<?php

namespace App\Http\Middleware;

use App\Models\Form;
use App\Models\ScopeNode;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ] : null,
                // Ability gates the shell/pages need (e.g. the Members nav item + its row actions).
                // Computed FAIL-CLOSED: on tenant routes EstablishTenantDatabaseContext has set the
                // Spatie permissions team by the time this share() renders (and resets it in
                // terminate()), so can() resolves against the active tenant; off-tenant (central/guest)
                // no team is set, so every ability resolves to false — exactly the gating we want.
                'can' => [
                    'manageMembers' => (bool) $user?->can('tenant.members.invite'),
                    'transferOwnership' => (bool) $user?->can('tenant.ownership.transfer'),
                    // Gates the Forms nav item + the list page (viewAny composes forms.create/.edit.* — FormPolicy).
                    'manageForms' => (bool) $user?->can('viewAny', Form::class),
                    // Gates the Submissions inbox nav item + list page (F7). All five roles that hold
                    // submissions.view; the presenter then scopes rows (tenant-wide vs own-forms).
                    'viewSubmissions' => (bool) $user?->can('submissions.view'),
                    // Gates the Scopes nav item + the /scopes page + the form scope picker (G10b2).
                    // ScopeNodePolicy::viewAny is exactly `scopes.manage` — Owner/Admin only. Deliberately
                    // NOT reused for the grant surface inside the page: `forms.collaborators.manage` is a
                    // separate catalog entry, and the page gates that block on its own presenter flag.
                    'manageScopes' => (bool) $user?->can('viewAny', ScopeNode::class),
                ],
            ],
            // Drives the app shell's theme toggle (C2), the Settings → Appearance panel (G11) and the
            // <html> attribute emission in app.blade.php. Guests resolve to the product defaults, every
            // one of which emits NO attribute (so prefers-color-scheme decides the theme and the type
            // scale / body face stay at their base values).
            'ui' => [
                'theme' => $user?->uiTheme() ?? User::defaultUiTheme(),
            ],
            // The tenant's read-only entitlement model (H5a / ADR-0008): current plan tier, feature flags,
            // and per-metric quota-vs-usage. One shared read-model both the H5b gated UI and any future
            // Plan & Usage panel consume, so they cannot disagree — the same "one source" reasoning as
            // ui.theme above. FAIL-CLOSED off-tenant: EntitlementService::snapshot() returns null when
            // there is no active tenant context (guest/central routes), so no query runs and no plan leaks.
            'entitlements' => app(EntitlementService::class)->snapshot(),
            // One-shot flash → toast bridge (design-system §3.7). Controllers signal an outcome with
            // ->with('toast', ['type' => 'success'|'error'|'info', 'message' => '…']); the app shell's
            // ToastHost raises it once, then the session flash is gone.
            'flash' => [
                'toast' => $request->session()->get('toast'),
                // Non-fatal XLSForm-import warnings (Increment G7b) — the builder renders these as a
                // dismissible banner after a destructive import so the author reviews lossy coercions
                // (dynamic repeat_count, downgraded grids, sanitized keys) before publishing (§6).
                'xlsformWarnings' => $request->session()->get('xlsformWarnings'),
            ],
        ];
    }
}
