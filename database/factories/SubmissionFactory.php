<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Submission>
 *
 * Defaults to a manual, draft submission. tenant_id is auto-filled by BelongsToTenant under the active
 * context. The default form_id/form_version_id come from independent factories (schema-valid but not
 * necessarily the same form) — use forVersion() to bind both coherently to one published version.
 */
class SubmissionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'form_version_id' => FormVersion::factory(),
            'status' => SubmissionStatus::Draft,
            'source' => SubmissionSource::Manual,
        ];
    }

    /** Bind form_id + form_version_id coherently to one version. */
    public function forVersion(FormVersion $version): static
    {
        return $this->state(fn (): array => [
            'form_id' => $version->form_id,
            'form_version_id' => $version->id,
        ]);
    }

    /** A finalized guest submission with the guest-channel provenance columns populated. */
    public function guest(): static
    {
        return $this->state(fn (): array => [
            'source' => SubmissionSource::Guest,
            'status' => SubmissionStatus::Submitted,
            'guest_token' => fake()->sha256(),
            'guest_ip' => fake()->ipv4(),
            'guest_user_agent' => fake()->userAgent(),
            'submitted_at' => now(),
        ]);
    }
}
