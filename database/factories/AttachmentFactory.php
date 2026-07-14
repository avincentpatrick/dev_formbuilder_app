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
