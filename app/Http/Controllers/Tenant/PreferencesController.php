<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserUiPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Per-user appearance preferences (PRD Feature #9). Runs inside the authenticated tenant group, where
 * EstablishTenantDatabaseContext has set app.current_user_id — the belongs-to-user RLS key on
 * user_ui_preferences — so the upsert is visible and writable. No permission gate: a user edits only
 * their own row (RLS-scoped). Only theme_mode is persistable in Phase 1 (accent/font/dyslexia → Phase 2).
 */
final class PreferencesController extends Controller
{
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
