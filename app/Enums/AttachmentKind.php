<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Attachment;

/**
 * What an {@see Attachment} row is (data-dictionary §10 `AttachmentKind`). The nine kinds
 * span every channel that produces a file — respondent submission media, OCR source scans, avatars,
 * branding logos, feedback screenshots, export artifacts, and archived webhook payloads — all stored in
 * the one shared polymorphic `attachments` table (replacing legacy's `image_path`/`file_path`/… sprawl).
 * The backing string doubles as the `{kind}` segment of the storage key `tenants/{tenant_id}/{kind}/...`
 * (deployment-infrastructure.md §7), so per-tenant storage accounting is a key-prefix query.
 *
 * Increment G6 writes `submission_file` (file uploads) and `field_media_sample` (photo/audio/video
 * capture); `signature_capture` is ready for the deferred signature control. H23a2 wired
 * `branding_logo`; I7a wired `feedback_screenshot`. The remaining kinds are wired by their own
 * features (OCR, avatars, exports, webhooks).
 */
enum AttachmentKind: string
{
    case SubmissionFile = 'submission_file';
    case OcrSourceScan = 'ocr_source_scan';
    case FieldMediaSample = 'field_media_sample';
    case SignatureCapture = 'signature_capture';
    case Avatar = 'avatar';
    case BrandingLogo = 'branding_logo';
    case FeedbackScreenshot = 'feedback_screenshot';
    case ExportArtifact = 'export_artifact';
    case WebhookPayloadArchive = 'webhook_payload_archive';

    /**
     * The kind a respondent-uploaded answer file gets from the media field it answers (Increment G6):
     * a drawn signature is `signature_capture`; a captured photo/audio/video sample is
     * `field_media_sample`; a plain attached document (`file_upload`) is `submission_file`.
     */
    public static function forFieldType(FieldType $type): self
    {
        return match ($type) {
            FieldType::Signature => self::SignatureCapture,
            FieldType::ImageCapture, FieldType::AudioCapture, FieldType::VideoCapture => self::FieldMediaSample,
            default => self::SubmissionFile,
        };
    }
}
