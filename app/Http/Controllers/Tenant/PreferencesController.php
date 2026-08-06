<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateAppearanceRequest;
use App\Http\Requests\Tenant\UpdateNotificationPreferenceRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserUiPreference;
use App\Services\Branding\BrandingPresenter;
use App\Services\Notifications\NotificationPreferenceResolver;
use App\Services\Notifications\NotificationPresenter;
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
    public function __construct(
        private readonly CustomDomainService $domains,
        private readonly BrandingPresenter $branding,
        private readonly NotificationPresenter $notifications,
        private readonly NotificationPreferenceResolver $notificationPreferences,
    ) {}

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
            // Tenant branding (H23a2, ADR-0014). Unlike customDomains above this is a full CONTROL, not a
            // link — branding is three inputs and a preview, which does not earn a page of its own the way
            // the per-row domain lifecycle did. `can_manage` hides the card for non-admins; `is_active`
            // vs `has_brand_color` is what lets it explain a downgraded tenant's stored-but-dormant brand.
            'branding' => $this->branding->forSettings($tenant, $user->can('tenant.settings.manage')),
            // Per-type notification preferences (I4, §23). RESOLVED, never read raw: the table is sparse —
            // a row exists only where someone diverged — so the card renders
            // NotificationPreferenceResolver's answer, which fills every gap from NotificationType's
            // defaults. `email_locked` is what lets the two site-owned types render their email control as
            // unavailable instead of as a switch wired to nothing.
            'notificationPreferences' => $this->notifications->preferences($user),
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

    /**
     * One notification type's channels for the acting user — ALWAYS BOTH BOOLEANS.
     *
     * Note the deliberate divergence from {@see self::updateAppearance()} directly above, whose every field
     * is `sometimes`: there the four axes are independent, here the pair is a single §23 row and a partial
     * write would lean on the columns' `default true`, silently opting the user into the one type the PRD
     * says to keep quiet. That guarantee lives in {@see UpdateNotificationPreferenceRequest} and in
     * {@see NotificationPreferenceResolver::set()}, not here.
     */
    public function updateNotifications(UpdateNotificationPreferenceRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $channels = $request->toChannels();

        $this->notificationPreferences->set($user, $request->type(), $channels['in_app'], $channels['email']);

        return back()->with('status', 'notifications-updated');
    }
}
