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
            // Increment P3a — the lost-update baseline, for the same reason the guest submit carries one:
            // the draft branch SAVES before it promotes, so a stale tab would overwrite another tab's answers
            // and finalize them in one request. ⚠️ Without it the encode page contradicts itself — P3a stops
            // the autosave loop with "saving has stopped to avoid overwriting it", and then Submit would
            // overwrite it anyway.
            'base_content_checksum' => ['nullable', 'string', 'size:64'],
        ];
    }

    /**
     * The lost-update baseline, and whether one was CLAIMED — the same present-only posture as
     * {@see \App\Http\Requests\Public\GuestSubmissionRequest::claimsBaseline()}, and for the same reason as
     * that class's `client_submission_uuid` note directly above: this endpoint must keep working for a direct
     * POST or an old cached page, so an ABSENT claim submits exactly as it did before P3a. A present-but-stale
     * claim is refused.
     */
    public function baseContentChecksum(): ?string
    {
        $value = $this->input('base_content_checksum');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function claimsBaseline(): bool
    {
        return $this->baseContentChecksum() !== null;
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
