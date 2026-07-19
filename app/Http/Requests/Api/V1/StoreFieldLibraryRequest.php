<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\FieldType;
use App\Services\Forms\BlueprintValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /api/v1/field-library (Increment G9b) — author a reusable question. Authorization is
 * the route's `ability:write:forms`; the model omits BelongsToTenant so the controller sets `tenant_id` from
 * the token's tenant context. `default_config` / `default_validations` are stored verbatim (their shape is
 * re-validated when the item is inserted into a draft, {@see BlueprintValidator::validateField}).
 */
final class StoreFieldLibraryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'field_type' => ['required', Rule::enum(FieldType::class)],
            'default_label' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:60'],
            'default_hint' => ['nullable', 'string'],
            'default_config' => ['nullable', 'array'],
            'default_validations' => ['nullable', 'array'],
        ];
    }
}
