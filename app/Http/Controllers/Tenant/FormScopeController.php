<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\AssignFormScopeRequest;
use App\Models\Form;
use App\Models\User;
use App\Services\Forms\FormService;
use Illuminate\Http\RedirectResponse;

/**
 * Assign a form to a node in the scoping hierarchy (Increment G10b2).
 *
 * Its own route and controller rather than an extra field on FormController::update, because the gate differs:
 * renaming a form needs `can:update,form`, which a plain form editor holds on any form they created
 * (FormPolicy::canEdit composes `forms.edit.own` with the editor grant FormService::create mints them).
 * Re-scoping is a grant-equivalent act, so the route additionally requires `can:viewAny,ScopeNode` —
 * `scopes.manage`, the permission that authorizes authoring the hierarchy in the first place.
 *
 * @see FormService::assignScope() for why the write is an explicit forceFill.
 */
final class FormScopeController extends Controller
{
    public function __construct(private readonly FormService $forms) {}

    public function update(AssignFormScopeRequest $request, Form $form): RedirectResponse
    {
        $nodeId = $request->validated('scope_node_id');

        /** @var User $user */
        $user = $request->user();

        $this->forms->assignScope($form, $nodeId === null ? null : (string) $nodeId, $user);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $nodeId === null ? 'Form un-scoped.' : 'Form scope updated.',
        ]);
    }
}
