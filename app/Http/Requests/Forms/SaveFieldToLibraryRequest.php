<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Models\FieldLibrary;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Save a draft field into the tenant's question library (Increment G9b). All metadata is OPTIONAL — the
 * one-click builder affordance sends an empty body and the service defaults the item name to the field's
 * label ({@see FieldLibrary::fromField}). Authorization is the route's `can:update,form`.
 */
final class SaveFieldToLibraryRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:60'],
        ];
    }
}
