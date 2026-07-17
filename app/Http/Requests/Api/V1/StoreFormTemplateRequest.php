<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/v1/form-templates (Increment G9a) — "save a version as a template". Authorization
 * is the route's `ability:write:forms` plus an in-controller `can:view` on the named version's parent form
 * (the version isn't a bound model). `form_version_id` is RLS-scoped by `exists`, so a cross-tenant id fails
 * validation rather than leaking existence.
 */
final class StoreFormTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'form_version_id' => ['required', 'uuid', 'exists:form_versions,id'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:60'],
        ];
    }
}
