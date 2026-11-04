<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // M185 (`R-0a7275ce`, `D129`): a repeatable section as cards (null) or a table; whether its questions can be columns
            // is not refused here — a section that cannot be a table is drawn as cards, and the builder says why.
            'repeat_layout' => ['nullable', 'string', Rule::in(['cards', 'table'])],
            'relevant_expression' => ['nullable', 'string', 'max:2000'],
            // M196 (`R-036f3226`, `D189`): required — a save without the token it last read is refused, not taken as an overwrite.
            'version' => ['required', 'string'],
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
