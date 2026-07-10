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
}
