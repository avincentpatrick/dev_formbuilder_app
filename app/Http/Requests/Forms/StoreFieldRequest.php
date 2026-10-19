<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Enums\FieldType;
use App\Rules\PickableFieldType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding a field to the builder (Increment D4a). Only the type + optional target section are chosen here —
 * the server mints a unique key + default label/config. Authorization is the route's `can:update,form`.
 */
final class StoreFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // M177 (`D140`): never a retired type — unless the builder's undo is putting an existing question back.
            'field_type' => ['required', Rule::enum(FieldType::class), new PickableFieldType($this->boolean('restore'))],
            'section_id' => ['nullable', 'uuid'],
            'restore' => ['sometimes', 'boolean'],
        ];
    }
}
