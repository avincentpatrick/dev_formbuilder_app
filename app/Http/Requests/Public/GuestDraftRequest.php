<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Services\Submissions\SubmissionDraftService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The guest draft upsert request (Increment H9b) — the same coarse shape as {@see GuestSubmissionRequest}
 * (authorization is the share-token middleware, so authorize() is true; `answers` is a key⇒value map that
 * the {@see SubmissionDraftService} normalises), plus a `finish_later` flag. When true and the draft carries
 * a contact email, the upsert also emails the resume link (§5.2). A draft may be INCOMPLETE, so unlike the
 * submit request nothing here asserts completeness — only the outer envelope is validated.
 */
final class GuestDraftRequest extends FormRequest
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
            'guest_contact_email' => ['nullable', 'email', 'max:255'],
            'client_submission_uuid' => ['nullable', 'uuid'],
            'locale' => ['nullable', 'string', 'max:10'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:20'],
            // The resume cursor (H10) — the SPA's current step key, restored verbatim on resume. Null for
            // single-page forms. Loosely bounded; an unknown key just resumes at the first step client-side.
            'draft_current_step' => ['nullable', 'string', 'max:255'],
            // "Save and finish later" — email the resume link (if a contact email is present) rather than only
            // returning it in the response body.
            'finish_later' => ['nullable', 'boolean'],
            // Increment P3a — the lost-update token: the `answers_content_checksum` this device last saw for
            // this draft (from the resume response, or its own previous save). NULLABLE on purpose and in two
            // distinct senses that happen to share a representation: a FIRST save has nothing to base itself
            // on, and a draft written before the checksum column existed legitimately stores null. Both
            // compare equal to a null stored value, so neither is refused. 64 hex chars — the same SHA-256
            // width the column is declared at.
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

    public function clientSubmissionUuid(): ?string
    {
        $value = $this->input('client_submission_uuid');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function guestContactEmail(): ?string
    {
        $value = $this->input('guest_contact_email');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function localeInput(): ?string
    {
        $value = $this->input('locale');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function deviceId(): ?string
    {
        $value = $this->input('device_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function appVersion(): ?string
    {
        $value = $this->input('app_version');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function draftCurrentStep(): ?string
    {
        $value = $this->input('draft_current_step');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function finishLater(): bool
    {
        return $this->boolean('finish_later');
    }

    /**
     * The lost-update baseline (Increment P3a) — see the rule for what null means and why it is not refused.
     *
     * There is no companion "did the client send it?" accessor, and that is the deliberate posture: the
     * caller sets `checkBaseline: true` for EVERY request on this channel, so a client that omits the field
     * sends a null base and is refused against any draft that has a checksum stored. The guard fails CLOSED
     * for a stale client rather than silently degrading to the lost update it exists to stop.
     */
    public function baseContentChecksum(): ?string
    {
        $value = $this->input('base_content_checksum');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
