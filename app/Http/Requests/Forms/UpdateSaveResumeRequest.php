<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Services\Forms\FormService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Toggle a form's per-form save-and-resume opt-in (Increment H10, UX §5.2).
 *
 * Its own request — deliberately NOT folded into {@see FormMetadataRequest} — mirroring the
 * {@see AssignFormScopeRequest} precedent: the write is a dedicated {@see FormService::setSaveAndResume}
 * (never mass-assignment), and the route stacks `feature:save_and_resume` on top of `can:update,form` so a
 * form owner can only enable a feature the tenant plan actually includes.
 */
final class UpdateSaveResumeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route middleware (can:update,form + feature:save_and_resume) owns authorization
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'save_and_resume' => ['required', 'boolean'],
        ];
    }
}
