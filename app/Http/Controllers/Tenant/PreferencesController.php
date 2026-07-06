<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserUiPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-user settings (Feature #9 appearance + profile/security). Runs inside the authenticated tenant
 * group, where EstablishTenantDatabaseContext has set app.current_user_id — the belongs-to-user RLS key
 * on user_ui_preferences — so the theme upsert is visible and writable. No permission gate: a user edits
 * only their own account (RLS-scoped). Profile/password/2FA are driven by Fortify's own endpoints; this
 * only renders the page and passes the current 2FA enrolment state. accent/font/dyslexia → Phase 2.
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

    public function updateTheme(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'theme_mode' => ['required', 'string', Rule::in(['system', 'light', 'dark'])],
        ]);

        /** @var User $user */
        $user = $request->user();

        UserUiPreference::updateOrCreate(
            ['user_id' => $user->id],
            ['theme_mode' => $validated['theme_mode']],
        );

        return back()->with('status', 'theme-updated');
    }
}
