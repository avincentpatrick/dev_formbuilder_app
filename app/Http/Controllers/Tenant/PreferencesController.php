<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateAppearanceRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserUiPreference;
use App\Services\Submissions\SubmissionDraftService;
use App\Services\Tenancy\CustomDomainService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

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
    public function __construct(private readonly CustomDomainService $domains) {}

    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Tenant $tenant */
        $tenant = app(TenantContract::class);

        return Inertia::render('Settings/Index', [
            'twoFactor' => [
                'enabled' => $user->two_factor_secret !== null,
                'confirmed' => $user->two_factor_confirmed_at !== null,
            ],
            // Tenant-level draft settings (H10). Only Owner/Admin (tenant.settings.manage) may edit; the page
            // hides the card otherwise. The effective value falls back to the 30-day default when unset.
            'draftSettings' => [
                'draft_ttl_days' => $tenant->draft_ttl_days ?? SubmissionDraftService::DRAFT_TTL_DAYS,
                'is_default' => $tenant->draft_ttl_days === null,
                'can_manage' => $user->can('tenant.settings.manage'),
            ],
            // Custom domains (H22b) — a LINK, not a control. The /domains page owns the surface; this is the
            // escape hatch that makes ADR-0012 §D9 real: the nav item requires the `custom_domain` feature,
            // so a tenant downgraded off Business would otherwise have no path to a hostname that is still
            // resolving. The card renders only when `count > 0`, which is what keeps it from advertising an
            // unbuyable plan (Business is seeded is_active:false) to everyone else.
            //
            // One query, on THIS page only — deliberately not a shared prop, which would put a `domains`
            // read on every Inertia render in the application for one card.
            'customDomains' => [
                'count' => $user->can('tenant.settings.manage')
                    ? $this->domains->forTenant($tenant)->count()
                    : 0,
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
