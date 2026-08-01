<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\AttachmentKind;
use App\Enums\UsageMetric;
use App\Exceptions\Attachments\AttachmentException;
use App\Exceptions\Entitlements\QuotaExceededException;
use App\Models\Attachment;
use App\Models\Submission;
use App\Services\Attachments\AttachmentScanner;
use App\Services\Entitlements\QuotaGuard;
use App\Services\Webhooks\WebhookPayloadArchive;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

/**
 * Stores one submission's rendered PDF (Increment H17): scan, quota, write, row.
 *
 * ── Why this forks AttachmentStorageService rather than calling it ──────────────────────────────
 * That service's signature is `(UploadedFile, FormVersion, string $fieldKey, ?string $uploadedBy)`
 * and `resolveMediaField()` throws unless the named field's type `isMedia()` — it exists to store
 * a respondent's answer to a media question. It also derives `kind` from the field type via
 * `AttachmentKind::forFieldType()`, which can never yield `ExportArtifact`. The fork is the one
 * {@see WebhookPayloadArchive} already blessed in its own docblock.
 *
 * ── ONE ROW PER SUBMISSION, OVERWRITTEN IN PLACE ────────────────────────────────────────────────
 * The key is deterministic — `tenants/{tenant}/export_artifact/submissions/{submission}.pdf` —
 * and the row is matched on `(attachable, kind)`. Regenerating overwrites the object and updates
 * the row; it never accumulates.
 *
 * That is a supersede WITHOUT a delete, and the absence of the delete is the point. `storage_bytes`
 * is a HARD-BLOCK gauge (`EntitlementService::computeGauge()` sums `size_bytes` over non-trashed
 * rows) and NOTHING in this codebase reclaims storage — there is no attachment reaper, no
 * retention sweep and not one `Storage::delete` call. So an accumulate-and-delete design would
 * have to introduce disk reclaim, and there is no safe place to sequence it: `TenantAwareJob`
 * wraps `handleForTenant()` in a transaction, owns no post-commit hook, and
 * `SweepTenantScheduledFormsJob` records — verified — that `DB::afterCommit` never fires under the
 * suite's uncommitted outer transaction. Delete-then-write and write-then-delete both leave a
 * window where a rollback restores a row whose object is gone: a dangling row that still charges
 * the tenant's quota and 404s on download.
 *
 * Overwrite-in-place has no such window. On rollback the object on disk is a valid PDF of the same
 * submission and only the row's metadata is stale, which the next run heals.
 *
 * The key convention still satisfies `AttachmentKind`'s own contract that per-tenant accounting is
 * a `tenants/{tenant_id}/{kind}/…` prefix query; only the leaf is deterministic instead of a uuid.
 */
final class SubmissionPdfStorage
{
    public function __construct(
        private readonly QuotaGuard $quota,
        private readonly AttachmentScanner $scanner,
    ) {}

    /**
     * Scan, quota-check and persist. Returns the stored attachment.
     *
     * ORDER IS LOAD-BEARING and runs cheapest-refusal-first:
     *   1. SCAN, before anything is written. An infected PDF never reaches disk — strictly
     *      stronger than the upload path, which stores first and quarantines at read time with a
     *      409. It also may not take `WebhookPayloadArchive`'s `Skipped` shortcut, because that
     *      shortcut is justified by "never served to a browser" and this is.
     *   2. QUOTA, on the DELTA. The row being replaced already counts toward the gauge, so
     *      charging the full size would bill a regeneration twice and could refuse a tenant who is
     *      merely re-rendering something they already own. Rendering to memory first is what makes
     *      the exact byte count knowable before any write — an `UploadedFile` knows its size up
     *      front, a generated document does not.
     *   3. WRITE the object, then upsert the row.
     *
     * @throws AttachmentException when the scanner refuses the bytes
     * @throws QuotaExceededException when the delta will not fit
     */
    public function store(Submission $submission, string $bytes): Attachment
    {
        $filename = $this->filenameFor($submission);

        $verdict = $this->scanner->scanBytes($bytes, $filename);
        if (! $verdict->servable()) {
            throw AttachmentException::infected((string) $submission->getKey());
        }

        $existing = $this->existingFor($submission);
        // `->` not `?->`: `??` already has isset() semantics, so it absorbs a null $existing.
        $delta = strlen($bytes) - (int) ($existing->size_bytes ?? 0);

        // A regeneration that SHRINKS the document must not be refused, and `assertCanCreate()`
        // would compare `used + negative` — correct, but only by accident. Skipping the call when
        // nothing new is being consumed states the intent.
        if ($delta > 0) {
            $this->quota->assertCanCreate(UsageMetric::StorageBytes, $delta);
        }

        $disk = (string) config('filesystems.default');
        $path = $this->pathFor($submission);

        Storage::disk($disk)->put($path, $bytes);

        return Attachment::updateOrCreate(
            [
                'attachable_type' => 'submission',
                'attachable_id' => $submission->getKey(),
                'kind' => AttachmentKind::ExportArtifact,
            ],
            [
                'disk' => $disk,
                'path' => $path,
                'original_filename' => $filename,
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($bytes),
                'checksum_sha256' => hash('sha256', $bytes),
                'is_encrypted_at_rest' => false,
                'is_pii' => true,
                // The verdict the bytes actually got, never a hardcoded Skipped. With no scanner
                // wired (Phase-1) that IS Skipped — but it is Skipped because the scanner said so,
                // so wiring ClamAV changes behaviour here with no edit to this line.
                'virus_scan_status' => $verdict,
                // System-generated: no human uploaded it. Unlike WebhookPayloadArchive, a null
                // uploader does NOT mean "skip the quota" here — the quota is charged above,
                // because a staff user asked for this file and the tenant is keeping it.
                'uploaded_by' => null,
            ],
        );
    }

    /** The current PDF for a submission, or null. */
    public function existingFor(Submission $submission): ?Attachment
    {
        return Attachment::query()
            ->where('attachable_type', 'submission')
            ->where('attachable_id', $submission->getKey())
            ->where('kind', AttachmentKind::ExportArtifact)
            ->first();
    }

    private function pathFor(Submission $submission): string
    {
        $tenantId = (string) TenantContext::currentTenantId();

        return "tenants/{$tenantId}/".AttachmentKind::ExportArtifact->value."/submissions/{$submission->getKey()}.pdf";
    }

    /** What the browser will call it. Not the storage key — that stays opaque and tenant-prefixed. */
    private function filenameFor(Submission $submission): string
    {
        return 'submission-'.$submission->getKey().'.pdf';
    }
}
