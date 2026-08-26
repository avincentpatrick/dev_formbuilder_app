<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AttachmentKind;
use App\Enums\ScanStatus;
use App\Models\Attachment;
use App\Models\FormField;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 *
 * A stored file. `tenant_id` is auto-filled by BelongsToTenant. The default attachable is a `form_field`
 * alias with a random id (staged state; no DB FK, so it needn't reference a real row — cheap for RLS
 * fuzz). `forField()`/`forSubmission()` point it at a real owner; `pending()`/`clean()`/`infected()`
 * set the scan state.
 */
class AttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attachable_type' => 'form_field',
            'attachable_id' => (string) Str::uuid(),
            'kind' => AttachmentKind::FieldMediaSample,
            'disk' => 'local',
            'path' => 'tenants/'.Str::uuid().'/field_media_sample/'.date('Ym').'/'.Str::uuid().'.jpg',
            'original_filename' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(1_000, 5_000_000),
            'checksum_sha256' => hash('sha256', (string) Str::uuid()),
            'width' => 1_024,
            'height' => 768,
            'duration_seconds' => null,
            'is_encrypted_at_rest' => false,
            'is_pii' => false,
            'virus_scan_status' => ScanStatus::Pending,
            'ocr_confidence_avg' => null,
            'uploaded_by' => null,
        ];
    }

    public function forField(FormField $field): static
    {
        return $this->state(fn (): array => [
            'attachable_type' => 'form_field',
            'attachable_id' => $field->id,
        ]);
    }

    public function forSubmission(Submission $submission): static
    {
        return $this->state(fn (): array => [
            'attachable_type' => 'submission',
            'attachable_id' => $submission->id,
        ]);
    }

    /**
     * A tenant brand logo (H23a2). The owner is the TENANT itself, which is the third morph alias the
     * `attachments` table carries and the first one that is not form-scoped.
     *
     * Deliberately `clean()`: a logo is served to every respondent of every branded form, and
     * {@see ScanStatus::servable()} is the gate that decides whether it is served at all — so a fixture
     * left `pending()` would make every branding render test assert the absence of a logo for a reason
     * that has nothing to do with branding.
     */
    public function brandingLogo(string $tenantId): static
    {
        return $this->state(fn (): array => [
            'attachable_type' => 'tenant',
            'attachable_id' => $tenantId,
            'kind' => AttachmentKind::BrandingLogo,
            'mime_type' => 'image/png',
            'original_filename' => 'logo.png',
            'path' => "tenants/{$tenantId}/branding_logo/".date('Ym').'/'.Str::uuid().'.png',
            'virus_scan_status' => ScanStatus::Clean,
            'width' => 512,
            'height' => 128,
        ]);
    }

    /**
     * A feedback screenshot (I7a, ADR-0015 §D6) — the fourth morph alias, owned by a `feedback_report`.
     *
     * Deliberately `is_pii = true` and `clean()`, which is what the real write path produces once its
     * queued scan lands: ADR-0015 §D8 marks the row PII because the image is a photograph of whatever was
     * on the reporter's screen, and a fixture left `pending()` would make an authorization test assert a
     * 409 for a reason that has nothing to do with authorization. `clean()` rather than `skipped()` for
     * the same reason `brandingLogo()` chooses it — the strongest servable state, so the scan gate is
     * never the thing under test.
     */
    public function feedbackScreenshot(string $tenantId, string $reportId): static
    {
        return $this->state(fn (): array => [
            'attachable_type' => 'feedback_report',
            'attachable_id' => $reportId,
            'kind' => AttachmentKind::FeedbackScreenshot,
            'mime_type' => 'image/png',
            'original_filename' => 'capture.png',
            'path' => "tenants/{$tenantId}/feedback_screenshot/".date('Ym').'/'.Str::uuid().'.png',
            'virus_scan_status' => ScanStatus::Clean,
            'is_pii' => true,
            'width' => 1_600,
            'height' => 900,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['virus_scan_status' => ScanStatus::Pending]);
    }

    public function clean(): static
    {
        return $this->state(fn (): array => ['virus_scan_status' => ScanStatus::Clean]);
    }

    public function skipped(): static
    {
        return $this->state(fn (): array => ['virus_scan_status' => ScanStatus::Skipped]);
    }

    public function infected(): static
    {
        return $this->state(fn (): array => ['virus_scan_status' => ScanStatus::Infected]);
    }
}
