<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Insert a field-library item into the builder's current draft (Increment G9b). Only the item + optional
 * target section are chosen — the server materializes the stored field shape (minting a unique key).
 * `exists:field_library,id` runs under the tenant RLS context, so a cross-tenant private id fails validation
 * rather than leaking existence. Authorization is the route's `can:update,form`.
 */
final class StoreFieldFromLibraryRequest extends FormRequest
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
            'library_item_id' => ['required', 'uuid', 'exists:field_library,id'],
            'section_id' => ['nullable', 'uuid'],
        ];
    }
}
