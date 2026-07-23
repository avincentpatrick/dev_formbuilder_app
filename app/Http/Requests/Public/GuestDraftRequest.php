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
            // "Save and finish later" — email the resume link (if a contact email is present) rather than only
            // returning it in the response body.
            'finish_later' => ['nullable', 'boolean'],
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

    public function finishLater(): bool
    {
        return $this->boolean('finish_later');
    }
}
