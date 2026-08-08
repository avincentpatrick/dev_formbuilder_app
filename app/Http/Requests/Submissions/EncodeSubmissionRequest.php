<?php

declare(strict_types=1);

namespace App\Http\Requests\Submissions;

use App\Services\Submissions\SubmissionPipeline;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The manual-encoding submit request (Increment F4b). Authorization is the `can:create,<Submission>,form`
 * route middleware; this request only asserts the coarse shape — `answers` is a key⇒value map. All real
 * validation (per-field type coercion, required/relevance, constraints) is the {@see SubmissionPipeline},
 * the single write path shared by every channel, so it is deliberately NOT duplicated here.
 */
final class EncodeSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answers' => ['present', 'array'],
            // NULLABLE here where the draft channel's is `required` (Increment I9b), and the asymmetry is
            // deliberate: this endpoint must keep working for a direct POST, an old cached page, or any
            // caller that never opened a draft. When present it does two things — it routes a resumed draft
            // to `promote()` instead of a second `submit()`, and it makes a double-clicked Submit resolve to
            // one submission via Stage 2b.
            'client_submission_uuid' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        $answers = $this->input('answers', []);

        return is_array($answers) ? $answers : [];
    }

    public function clientSubmissionUuid(): ?string
    {
        $uuid = $this->input('client_submission_uuid');

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }
}
