<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * File a form into a folder, or unfile it (M131, `R-9e634897`).
 *
 * `present` rather than `required`, because null is meaningful: it unfiles the form. `exists` runs under the
 * tenant's RLS, so another workspace's folder is refused here as a field error rather than reaching the
 * composite foreign key; `FormService::assignFolder()` resolves it again under the same scope as a backstop.
 */
final class AssignFormFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:update,form` on the route owns authorization
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'folder_id' => ['present', 'nullable', 'uuid', 'exists:form_folders,id'],
        ];
    }
}
