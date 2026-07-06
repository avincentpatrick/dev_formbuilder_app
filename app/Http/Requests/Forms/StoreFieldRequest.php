<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Enums\FieldType;
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
            'field_type' => ['required', Rule::enum(FieldType::class)],
            'section_id' => ['nullable', 'uuid'],
        ];
    }
}
