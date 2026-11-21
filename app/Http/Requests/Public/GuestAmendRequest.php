<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A respondent's changed answers to the response they sent (M248, `D232`), with the content checksum their page was
 * given — the lost-update baseline, always claimed, because the respondent may have the link open on two devices.
 */
final class GuestAmendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the amend token, verified by EstablishGuestAmendContext, is the whole authority
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // The respondent's answers, keyed by question; the same shape a guest submit sends.
            'answers' => ['present', 'array'],
            // The `content_checksum` the amend read returned. Null only for a response stored before checksums existed.
            'base_content_checksum' => ['present', 'nullable', 'string', 'max:128'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        /** @var array<string, mixed> $answers */
        $answers = $this->validated('answers');

        return $answers;
    }

    public function baseContentChecksum(): ?string
    {
        $value = $this->validated('base_content_checksum');

        return is_string($value) ? $value : null;
    }
}
