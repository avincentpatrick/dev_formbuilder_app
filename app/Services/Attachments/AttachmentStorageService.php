<?php

declare(strict_types=1);

namespace App\Services\Attachments;

use App\Enums\AttachmentKind;
use App\Enums\ScanStatus;
use App\Exceptions\Attachments\AttachmentException;
use App\Jobs\ScanAttachmentJob;
use App\Models\Attachment;
use App\Models\FormField;
use App\Models\FormVersion;
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
