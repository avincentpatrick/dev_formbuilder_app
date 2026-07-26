<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Enums\AttachmentKind;
use App\Enums\ScanStatus;
use App\Models\Attachment;
use App\Models\FormField;
use App\Models\WebhookDelivery;
use App\Services\Attachments\AttachmentStorageService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;

/**
 * Off-loads an oversized webhook delivery envelope to attachment storage (H13b —
 * webhook-integration-design.md §5, data-dictionary §10 `AttachmentKind::WebhookPayloadArchive`), keeping the
 * hot `webhook_deliveries` table lean. Triggered at delivery creation by {@see WebhookEventDispatcher} when the
 * JSON envelope exceeds `webhooks.payload_archive_threshold_bytes`; the inline `payload` column is then trimmed
 * to a marker and {@see WebhookDelivery::$payload_attachment_id} points here. {@see DeliverWebhookJob} reads the
 * full envelope back via {@see read()} before signing, so the signed body is always the real payload.
 *
 * It does NOT reuse {@see AttachmentStorageService::store()} — that path is coupled to
 * an `UploadedFile` answering a media {@see FormField} — but mirrors its server-generated key
 * convention `tenants/{tenant_id}/{kind}/{yyyymm}/{uuid}.json` and writes a system-owned row (`uploaded_by`
 * null, so no staff storage-quota gate, the same never-block posture as a guest upload). Runs inside the
 * tenant's adopted context in both call sites, so the `attachments` strict-RLS WITH CHECK passes and
 * `BelongsToTenant` fills `tenant_id`.
 */
final class WebhookPayloadArchive
{
    /**
     * Write the envelope to storage as a `WebhookPayloadArchive` attachment owned by the delivery and return
     * the new attachment id (to stamp on `payload_attachment_id`).
     *
     * @param  array<string, mixed>  $payload
     */
    public function archive(WebhookDelivery $delivery, array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $tenantId = (string) TenantContext::currentTenantId();
        $disk = (string) config('filesystems.default');

        $uuid = Uuid::uuid7()->toString();
        $path = "tenants/{$tenantId}/".AttachmentKind::WebhookPayloadArchive->value.'/'.date('Ym')."/{$uuid}.json";

        Storage::disk($disk)->put($path, $json);

        $attachment = Attachment::create([
            'id' => $uuid,
            'attachable_type' => 'webhook_delivery',
            'attachable_id' => $delivery->getKey(),
            'kind' => AttachmentKind::WebhookPayloadArchive,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => 'payload.json',
            'mime_type' => 'application/json',
            'size_bytes' => strlen($json),
            'checksum_sha256' => hash('sha256', $json),
            'width' => null,
            'height' => null,
            'duration_seconds' => null,
            'is_encrypted_at_rest' => false,
            'is_pii' => false,
            // System-generated JSON, never scanned and never served to a browser — servable, "not scanned".
            'virus_scan_status' => ScanStatus::Skipped,
            'uploaded_by' => null,
        ]);

        return (string) $attachment->getKey();
    }

    /**
     * The delivery's full envelope — the archived copy when one exists, else the inline `payload`. Falls back
     * to the inline column if the archive row/object has gone missing, so a delivery never becomes unsendable.
     *
     * @return array<string, mixed>
     */
    public function read(WebhookDelivery $delivery): array
    {
        if ($delivery->payload_attachment_id === null) {
            return $delivery->payload;
        }

        $attachment = Attachment::query()->find($delivery->payload_attachment_id);

        if ($attachment === null) {
            return $delivery->payload;
        }

        $json = Storage::disk($attachment->disk)->get($attachment->path);
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : $delivery->payload;
    }
}
