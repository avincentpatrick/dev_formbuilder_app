<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Services\Submissions\SubmissionPipeline;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The guest submit request (Increment F5). Authorization is the share-token middleware, not a policy, so
 * authorize() is true. Like the manual-encode request this asserts only the coarse shape — `answers` is a
 * key⇒value map; all per-field validation is the {@see SubmissionPipeline}, the single shared write path.
 * The guest-only optional inputs (contact email, an idempotency uuid, a locale) are shallow-validated here.
 */
final class GuestSubmissionRequest extends FormRequest
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
}
