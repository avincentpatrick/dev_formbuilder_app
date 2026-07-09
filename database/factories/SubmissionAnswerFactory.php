<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionAnswer>
 *
 * The 1:1 answer document. submission_id is both PK and FK; tenant_id is auto-filled by BelongsToTenant.
 * Use forSubmission() to bind the row to an existing submission (matching its form_version_id).
 */
class SubmissionAnswerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'form_version_id' => FormVersion::factory(),
            'answers' => [],
            'attachment_refs' => [],
        ];
    }

    /** Bind to an existing submission, inheriting its version. */
    public function forSubmission(Submission $submission): static
    {
        return $this->state(fn (): array => [
            'submission_id' => $submission->id,
            'form_version_id' => $submission->form_version_id,
        ]);
    }
}
