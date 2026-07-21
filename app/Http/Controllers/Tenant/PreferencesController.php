<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateAppearanceRequest;
use App\Models\User;
use App\Models\UserUiPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-user settings (Feature #9 appearance + profile/security). Runs inside the authenticated tenant
 * group, where EstablishTenantDatabaseContext has set app.current_user_id — the belongs-to-user RLS key
 * on user_ui_preferences — so the upsert is visible and writable. No permission gate: a user edits
 * only their own account (RLS-scoped). Profile/password/2FA are driven by Fortify's own endpoints; this
 * only renders the page and passes the current 2FA enrolment state.
 *
 * The current appearance is NOT passed as a page prop — it reaches the panel through the shared
 * `ui.theme` prop (HandleInertiaRequests), which the top-nav quick toggle reads too, so both surfaces
 * cannot disagree.
 */
final class PreferencesController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Settings/Index', [
            'twoFactor' => [
                'enabled' => $user->two_factor_secret !== null,
                'confirmed' => $user->two_factor_confirmed_at !== null,
            ],
        ]);
    }

    /**
     * Partial update of the four appearance axes (theme mode, accent, text size, dyslexia font).
     *
     * Every field on the request is `sometimes`, so this writes exactly the axes the caller sent — the
     * top-nav quick toggle sends only `theme_mode` and cannot disturb the rest. See
     * {@see UpdateAppearanceRequest} for why that guarantee lives in the request rather than here.
     */
    public function updateAppearance(UpdateAppearanceRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        UserUiPreference::updateOrCreate(['user_id' => $user->id], $request->toColumns());

        return back()->with('status', 'appearance-updated');
    }
}
