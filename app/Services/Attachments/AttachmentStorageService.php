<?php

declare(strict_types=1);

namespace App\Services\Attachments;

use App\Enums\AttachmentKind;
use App\Enums\ScanStatus;
use App\Enums\UsageMetric;
use App\Exceptions\Attachments\AttachmentException;
use App\Jobs\ScanAttachmentJob;
use App\Models\Attachment;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Services\Entitlements\QuotaGuard;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;

/**
 * The attachment write path (Increment G6) — the single place a respondent-uploaded media file becomes an
 * {@see Attachment} row + a stored object. Staged against the {@see FormField} it answers (its polymorphic
 * owner until {@see SubmissionPipeline} re-points it to the submission at persist).
 *
 * Security posture (threat-model §6): the MIME type is CONTENT-SNIFFED from the file's bytes
 * ({@see UploadedFile::getMimeType()}), never the client-declared header; the storage key is
 * server-generated `tenants/{tenant_id}/{kind}/{yyyymm}/{uuid}.{ext}` (the extension derives from the
 * sniffed type — `original_filename` is stored as display metadata only and never touches the key); and
 * the file is validated against the field's size cap + MIME allowlist before a single byte is stored.
 */
final class AttachmentStorageService
{
    public function __construct(private readonly QuotaGuard $quota) {}

    /**
     * Validate + store one uploaded file and return its persisted {@see Attachment}. `$uploadedBy` is the
     * acting user id, or null for a guest upload.
     *
     * @throws AttachmentException on an unknown/non-media field, a rejected MIME type, or an over-size file
     */
    public function store(UploadedFile $file, FormVersion $version, string $fieldKey, ?string $uploadedBy): Attachment
    {
        $field = $this->resolveMediaField($version, $fieldKey);

        $mime = $file->getMimeType() ?? 'application/octet-stream';
        $this->assertAccepted($field, $mime);
        $this->assertWithinFieldSize($field, (int) $file->getSize());

        // Hard-block the storage_bytes quota (H5b / ADR-0008 §D4) — but ONLY for a signed-in member's
        // upload. A guest respondent's upload ($uploadedBy === null) is data collection and is NEVER
        // rejected over the tenant's billing status (the same never-block principle that protects
        // submissions_count); it is bounded by the per-field size cap above + the guest rate limit, and
        // overage drives the tenant's upsell, not a data-collection gate.
        if ($uploadedBy !== null) {
            $this->quota->assertCanCreate(UsageMetric::StorageBytes, (int) $file->getSize());
        }

        $kind = AttachmentKind::forFieldType($field->field_type);
        $tenantId = (string) TenantContext::currentTenantId();
        $disk = (string) config('filesystems.default');

        $uuid = Uuid::uuid7()->toString();
        $extension = $file->extension() ?: 'bin';
        $directory = "tenants/{$tenantId}/{$kind->value}/".date('Ym');
        $storedPath = Storage::disk($disk)->putFileAs($directory, $file, "{$uuid}.{$extension}");

        if ($storedPath === false) {
            throw AttachmentException::storeFailed();
        }

        [$width, $height] = $this->imageDimensions($file, $mime);

        $attachment = Attachment::create([
            'id' => $uuid,
            'attachable_type' => 'form_field',
            'attachable_id' => $field->id,
            'kind' => $kind,
            'disk' => $disk,
            'path' => $storedPath,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => (int) $file->getSize(),
            'checksum_sha256' => hash_file('sha256', (string) $file->getRealPath()) ?: null,
            'width' => $width,
            'height' => $height,
            'duration_seconds' => null, // extraction deferred (needs ffprobe/getID3)
            'is_encrypted_at_rest' => (bool) $field->is_sensitive,
            'is_pii' => (bool) $field->is_pii,
            'virus_scan_status' => ScanStatus::Pending,
            'uploaded_by' => $uploadedBy,
        ]);

        ScanAttachmentJob::dispatch($attachment->id, $tenantId);

        return $attachment;
    }

    /**
     * Store a TENANT BRAND LOGO (H23a2) — the first attachment in this codebase whose owner is not
     * form-scoped.
     *
     * It is a sibling of {@see self::store()} rather than a parameter on it because almost nothing
     * transfers: there is no {@see FormField}, so no `accepted_types`, no per-field size cap, no
     * `is_sensitive`/`is_pii` to inherit, and no {@see SubmissionPipeline} that will later re-point the
     * owner. What DOES transfer is the discipline, and it is repeated here deliberately: the MIME is
     * content-sniffed from the bytes rather than trusted from the client header, the storage key is
     * server-generated and the extension derives from the sniffed type, and the object is queued for a
     * virus scan like any other upload.
     *
     * **SVG IS DELIBERATELY NOT AN ACCEPTED TYPE, and this is a security decision rather than a
     * limitation.** An SVG is an XML document that can carry `<script>` and event handlers; a brand logo
     * is served to every respondent of every branded form, same-origin, which is precisely the shape that
     * turns a stored file into stored XSS (threat-model §6). Raster only — the allowlist lives in
     * `config('attachments.branding_logo.accepted_types')` and is enforced below, not merely declared.
     *
     * **The `tenant` morph alias is stored but NOT registered in the global morph map**, following the
     * explicit warning in `AppServiceProvider` about `audits.auditable_type`: adding `tenant` there would
     * change how Sanctum's `tokenable_type` and Spatie's `model_type` serialize and split existing rows
     * between alias and FQCN. Nothing resolves `$attachment->attachable` anywhere in this codebase, and
     * the read path for a logo is `tenants.logo_attachment_id` — a real FK — in the other direction.
     * `BrandingMorphAliasTest` pins that this absence is deliberate.
     *
     * @throws AttachmentException on a rejected MIME type or an over-size file
     */
    public function storeBrandingLogo(UploadedFile $file, string $tenantId, ?string $uploadedBy): Attachment
    {
        $mime = $file->getMimeType() ?? 'application/octet-stream';

        /** @var list<string> $accepted */
        $accepted = config('attachments.branding_logo.accepted_types');

        if (! $this->mimeAllowed($mime, $accepted)) {
            throw AttachmentException::mimeRejected($mime);
        }

        $maxBytes = (int) config('attachments.branding_logo.max_bytes');

        if ((int) $file->getSize() > $maxBytes) {
            throw AttachmentException::tooLarge($maxBytes);
        }

        // A logo is uploaded by a signed-in Owner/Admin, never a guest, so the storage quota applies
        // exactly as it does to a member's media upload (H5b / ADR-0008 §D4).
        $this->quota->assertCanCreate(UsageMetric::StorageBytes, (int) $file->getSize());

        $kind = AttachmentKind::BrandingLogo;
        $disk = (string) config('filesystems.default');

        $uuid = Uuid::uuid7()->toString();
        $extension = $file->extension() ?: 'bin';
        $directory = "tenants/{$tenantId}/{$kind->value}/".date('Ym');
        $storedPath = Storage::disk($disk)->putFileAs($directory, $file, "{$uuid}.{$extension}");

        if ($storedPath === false) {
            throw AttachmentException::storeFailed();
        }

        [$width, $height] = $this->imageDimensions($file, $mime);

        $attachment = Attachment::create([
            'id' => $uuid,
            'attachable_type' => 'tenant',
            'attachable_id' => $tenantId,
            'kind' => $kind,
            'disk' => $disk,
            'path' => $storedPath,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => (int) $file->getSize(),
            'checksum_sha256' => hash_file('sha256', (string) $file->getRealPath()) ?: null,
            'width' => $width,
            'height' => $height,
            'duration_seconds' => null,
            // A brand logo is public-facing by definition — it is rendered to anonymous respondents on
            // every branded form. Encrypting it at rest would be theatre, and marking it PII would be
            // false: it is the tenant's own mark, deliberately published.
            'is_encrypted_at_rest' => false,
            'is_pii' => false,
            'virus_scan_status' => ScanStatus::Pending,
            'uploaded_by' => $uploadedBy,
        ]);

        ScanAttachmentJob::dispatch($attachment->id, $tenantId);

        return $attachment;
    }

    /**
     * @throws AttachmentException
     */
    private function resolveMediaField(FormVersion $version, string $fieldKey): FormField
    {
        $field = $version->fields()->where('key', $fieldKey)->first();

        if (! $field instanceof FormField || ! $field->field_type->isMedia()) {
            throw AttachmentException::fieldNotFound();
        }

        return $field;
    }

    /**
     * @throws AttachmentException
     */
    private function assertAccepted(FormField $field, string $mime): void
    {
        if (! $this->mimeAllowed($mime, $this->acceptedTypes($field))) {
            throw AttachmentException::mimeRejected($mime);
        }
    }

    /**
     * @throws AttachmentException
     */
    private function assertWithinFieldSize(FormField $field, int $sizeBytes): void
    {
        $max = $field->config['max_file_size_bytes'] ?? null;

        if (is_int($max) && $max > 0 && $sizeBytes > $max) {
            throw AttachmentException::tooLarge($max);
        }
    }

    /**
     * The field's own `accepted_types`, or the per-type default from config. An empty result = accept any.
     *
     * @return list<string>
     */
    private function acceptedTypes(FormField $field): array
    {
        $configured = $field->config['accepted_types'] ?? null;
        if (is_array($configured) && $configured !== []) {
            return array_values(array_filter($configured, 'is_string'));
        }

        $defaults = config('attachments.default_accepted_types.'.$field->field_type->value, []);

        return is_array($defaults) ? array_values(array_filter($defaults, 'is_string')) : [];
    }

    /**
     * @param  list<string>  $accepted
     */
    private function mimeAllowed(string $mime, array $accepted): bool
    {
        if ($accepted === []) {
            return true; // no allowlist ⇒ any type (subject only to the global size ceiling)
        }

        foreach ($accepted as $pattern) {
            if ($pattern === $mime) {
                return true;
            }
            if (str_ends_with($pattern, '/*') && str_starts_with($mime, substr($pattern, 0, -1))) {
                return true; // e.g. `image/*` matches `image/png`
            }
        }

        return false;
    }

    /**
     * Image pixel dimensions (core `getimagesize`, no extra dependency); `[null, null]` for non-images.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function imageDimensions(UploadedFile $file, string $mime): array
    {
        if (! str_starts_with($mime, 'image/')) {
            return [null, null];
        }

        $info = @getimagesize((string) $file->getRealPath());

        return $info === false ? [null, null] : [(int) $info[0], (int) $info[1]];
    }
}
