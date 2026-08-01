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
use App\Services\Forms\StepGraphInspector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Publishing a form's draft and restoring an old version (Increment D3). Authorization is the `can:`
 * route middleware (FormPolicy `publish` / `update`). The structural pre-publish gate's SPECIFIC
 * violation and lifecycle-rule violations are surfaced to the builder as an error toast, not swallowed.
 */
final class FormPublishController extends Controller
{
    public function store(Request $request, Form $form, PublishService $publisher, StepGraphInspector $inspector): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        /** @var User $user */
        $user = $request->user();

        try {
            $version = $publisher->publish($form, $user, $validated['note'] ?? null);
        } catch (PublishValidationException|FormException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        // Increment H21a (Doc #27 §6) — branching NOTICES, never refusals. Computed HERE rather than inside
        // `PublishService` for two reasons: the service keeps its binary published-or-threw contract, and
        // `FormVersionApiController` (the other caller) is left untouched, so `openapi.json` stays
        // drift-free. The publish already happened and stands regardless of what this finds.
        $warnings = $inspector->warnings($version);

        if ($warnings === []) {
            return back()->with('toast', [
                'type' => 'success',
                'message' => "Published version {$version->version_number}.",
            ]);
        }

        // The count rides the toast as well as the banner because publish is reachable from TWO pages —
        // the builder and the forms list — and `back()` returns to whichever called it. Only the builder
        // renders the banner, so without this the list-page publish would drop the notices silently.
        $count = count($warnings);

        return back()
            ->with('toast', [
                'type' => 'info',
                'message' => "Published version {$version->version_number}. {$count} branching ".($count === 1 ? 'warning' : 'warnings').'.',
            ])
            ->with('publishWarnings', $warnings);
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
