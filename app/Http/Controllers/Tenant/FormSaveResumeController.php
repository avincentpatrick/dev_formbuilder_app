<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\UpdateSaveResumeRequest;
use App\Models\Form;
use App\Models\User;
use App\Services\Forms\FormService;
use Illuminate\Http\RedirectResponse;

/**
 * Toggle a form's per-form save-and-resume opt-in (Increment H10, UX §5.2).
 *
 * Its own route and controller rather than a field on FormController::update, mirroring
 * {@see FormScopeController}: the write is a guarded {@see FormService::setSaveAndResume} and the route stacks
 * `feature:save_and_resume` on top of `can:update,form`, so enabling the toggle requires both editing rights
 * and a tenant plan that includes the feature.
 */
final class FormSaveResumeController extends Controller
{
    public function __construct(private readonly FormService $forms) {}

    public function update(UpdateSaveResumeRequest $request, Form $form): RedirectResponse
    {
        $enabled = $request->boolean('save_and_resume');

        /** @var User $user */
        $user = $request->user();

        $this->forms->setSaveAndResume($form, $enabled, $user);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $enabled ? 'Save and resume enabled for this form.' : 'Save and resume disabled for this form.',
        ]);
    }
}
