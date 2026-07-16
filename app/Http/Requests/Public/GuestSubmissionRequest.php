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
            // Increment G8b — offline device provenance (data-dictionary §7); bounded to the column widths.
            'device_id' => ['nullable', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ];
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
