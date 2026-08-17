<?php

declare(strict_types=1);

namespace App\Http\Requests\Submissions;

use App\Http\Requests\Public\GuestDraftRequest;
use App\Services\Submissions\SubmissionDraftService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The authenticated encode channel's draft-autosave body (Increment I9b) — {@see GuestDraftRequest} minus
 * every field that only makes sense for an unauthenticated respondent (no contact email, no device id, no
 * app version, no guest locale override, no "finish later"). Authorization is the route's
 * `can:create,<Submission>,form` middleware, exactly as the sibling `EncodeSubmissionRequest`.
 *
 * ⚠️ `client_submission_uuid` IS `required` HERE WHERE THE GUEST'S IS `nullable`, AND THAT IS THE WHOLE
 * IDEMPOTENCY DESIGN RATHER THAN A STRICTER MOOD. {@see SubmissionDraftService::saveDraft()} consults
 * `findByClientUuid()` only when the uuid is non-null; with a null one it takes the `createDraft()` branch
 * every single time. So a nullable uuid on an AUTOSAVE channel does not mean "no idempotency key", it means
 * one new `status = draft` row per keystroke-debounce tick, each with its own 30-day TTL. Making it required
 * makes that unrepresentable instead of merely untested — the runtime store has always minted one
 * (`useFormRuntime.ts`), this channel simply never sent it.
 */
final class EncodeDraftRequest extends FormRequest
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
            'client_submission_uuid' => ['required', 'uuid'],
            // The resume cursor — the page's current step key, restored verbatim on resume. Null for
            // single-page forms. Loosely bounded, mirroring the guest rule: an unknown key just resumes at
            // the first step client-side rather than erroring.
            'draft_current_step' => ['nullable', 'string', 'max:255'],
            // Increment P3a — the lost-update token, mirroring the guest rule exactly. Nullable for a first
            // save and for a draft written before the checksum column existed; 64 hex chars otherwise.
            'base_content_checksum' => ['nullable', 'string', 'size:64'],
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

    public function clientSubmissionUuid(): string
    {
        return (string) $this->input('client_submission_uuid');
    }

    public function draftCurrentStep(): ?string
    {
        $step = $this->input('draft_current_step');

        return is_string($step) && $step !== '' ? $step : null;
    }

    /**
     * The lost-update baseline (Increment P3a) — the twin of
     * {@see \App\Http\Requests\Public\GuestDraftRequest::baseContentChecksum()}, including its posture: the
     * controller sets `checkBaseline: true` unconditionally, so omitting the field is refused rather than
     * silently unguarded.
     */
    public function baseContentChecksum(): ?string
    {
        $value = $this->input('base_content_checksum');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
