<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Exceptions\Forms\FormException;
use App\Exceptions\Forms\PublishValidationException;
use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\PublishService;
use App\Services\Forms\RestoreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Publishing a form's draft and restoring an old version (Increment D3). Authorization is the `can:`
 * route middleware (FormPolicy `publish` / `update`). The structural pre-publish gate's SPECIFIC
 * violation and lifecycle-rule violations are surfaced to the builder as an error toast, not swallowed.
 */
final class FormPublishController extends Controller
{
    public function store(Request $request, Form $form, PublishService $publisher): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        /** @var User $user */
        $user = $request->user();

        try {
            $version = $publisher->publish($form, $user, $validated['note'] ?? null);
        } catch (PublishValidationException|FormException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Published version {$version->version_number}.",
        ]);
    }

    public function restore(Request $request, Form $form, FormVersion $version, RestoreService $restorer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $restorer->restore($form, $version, $user);
        } catch (FormException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Restored version {$version->version_number} into the draft.",
        ]);
    }
}
