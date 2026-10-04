<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rename one of a form's reference files (M132, `R-bf49e4c1`) — the name respondents see in place of the file's.
 * Authorization is the route's `can:update,form`.
 */
final class UpdateFormReferenceFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.required' => 'Give the file a name respondents will recognise.',
            'label.max' => 'A file name must be 120 characters or fewer.',
        ];
    }

    public function referenceLabel(): string
    {
        return trim((string) $this->validated('label'));
    }
}
