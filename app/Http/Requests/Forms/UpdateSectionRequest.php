<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Models\Form;
use App\Models\FormSection;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A section content edit from the config panel (Increment D4a). Validates shape only — `key` format +
 * per-version uniqueness, and the repeat-instance sanity the DB CHECK also enforces (min ≤ max).
 * Authorization is `can:update,form`; the optimistic-concurrency token is checked in the service.
 */
final class UpdateSectionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Form $form */
        $form = $this->route('form');
        /** @var FormSection $section */
        $section = $this->route('section');

        return [
            'key' => [
                'required', 'string', 'max:150', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('form_sections', 'key')
                    ->where('form_version_id', $form->draft_version_id)
                    ->ignore($section->id),
            ],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_repeatable' => ['boolean'],
            'min_instances' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'max_instances' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'relevant_expression' => ['nullable', 'string', 'max:2000'],
            'version' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $min = $this->input('min_instances');
            $max = $this->input('max_instances');

            if ($min !== null && $max !== null && (int) $min > (int) $max) {
                $validator->errors()->add('max_instances', 'Maximum instances must be at least the minimum.');
            }
        });
    }
}
