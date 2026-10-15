<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A section content edit from the config panel (Increment D4a). Validates shape only — `key` format (a key another
 * section holds is suffixed by the service since M172, never refused), and the repeat-instance sanity the DB CHECK also enforces (min ≤ max).
 * Authorization is `can:update,form`; the optimistic-concurrency token is checked in the service.
 */
final class UpdateSectionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // M172 (`R-733eff09`, `D132`): no uniqueness rule — a clash is suffixed by the service, as for a question
            // (the reason is on UpdateFieldRequest's key rule).
            'key' => ['required', 'string', 'max:150', 'regex:/^[a-z][a-z0-9_]*$/'],
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
