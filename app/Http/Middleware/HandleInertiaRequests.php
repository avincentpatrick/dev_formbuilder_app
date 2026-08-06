<?php

namespace App\Http\Middleware;

use App\Models\Audit;
use App\Models\Form;
use App\Models\SavedReportView;
use App\Models\ScopeNode;
use App\Models\User;
use App\Services\Branding\TenantBrandingService;
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
                    // Gates the Webhooks nav item + the /webhooks management pages (H14). Exactly
                    // WebhookEndpointPolicy::viewAny — the `webhooks.manage` permission, Owner/Admin only. The
                    // nav item ALSO combines this with the `webhooks` plan feature (Sidebar.vue) so a tier
                    // without the feature never sees a destination it would only bounce off.
                    'manageWebhooks' => (bool) $user?->can('webhooks.manage'),
                    // Gates the Integrations nav item + the /integrations pages (H15b). Exactly
                    // ConnectionPolicy::viewAny — the `integrations.manage` permission, Owner/Admin only. Like
                    // manageWebhooks the nav item ALSO requires the `native_connectors` plan feature
                    // (Sidebar.vue), so a tier without it never sees a destination it would only bounce off.
                    'manageIntegrations' => (bool) $user?->can('integrations.manage'),
                    // Gates the Analytics nav item, the /analytics page and the Dashboard's view-switcher
                    // (H24b2). The POLICY, not a bare permission string: SavedReportViewPolicy::viewAny is
                    // the `dashboard.org.view || dashboard.form.view` composition, and re-spelling that here
                    // would put a second definition of "may read analytics" in a second file. Like the two
                    // above, every consumer ALSO requires the `advanced_analytics` plan feature, so a tier
                    // without it never sees a destination it would only bounce off (ADR-0011 §D9 — hidden,
                    // never locked-with-upsell, because Business is held from sale).
                    'viewAnalytics' => (bool) $user?->can('viewAny', SavedReportView::class),
                    // Gates the Domains nav item + the /domains page (H22b). The BARE PERMISSION, not a
                    // policy: `domains` is RLS-exempt and has no model policy at all (ADR-0012 — there is no
                    // per-instance authorization question, and a policy over an unscoped table would invite
                    // one), so `tenant.settings.manage` is the whole authorization. It is the same
                    // permission the Drafts card on /settings uses; the two surfaces are both Owner/Admin
                    // tenant administration. The nav item ALSO requires the `custom_domain` plan feature
                    // (Sidebar.vue) — but the PAGE deliberately does not, because ADR-0012 §D9 keeps reads
                    // and deletes open so a tenant downgraded off Business can still remove a live host.
                    'manageDomains' => (bool) $user?->can('tenant.settings.manage'),
                    // Gates the Audit log nav item + the /audit-log page and its export (I2). The POLICY,
                    // not the bare `audit_log.view` string, for the reason `viewAnalytics` gives above:
                    // AuditPolicy already defines "may read the ledger", and re-spelling the permission
                    // here would put a second definition of it in a second file.
                    //
                    // THE ONLY GATED NAV ITEM WITH NO COMPANION PLAN FEATURE, and that is deliberate rather
                    // than an omission — PlanCatalog carries no audit key on any tier, because an audit
                    // trail is a baseline obligation for every tenant and not an enterprise upsell. So
                    // unlike its four neighbours above, this one turns on a permission alone.
                    'viewAuditLog' => (bool) $user?->can('viewAny', Audit::class),
                ],
            ],
            // Drives the app shell's theme toggle (C2), the Settings → Appearance panel (G11) and the
            // <html> attribute emission in app.blade.php. Guests resolve to the product defaults, every
            // one of which emits NO attribute (so prefers-color-scheme decides the theme and the type
            // scale / body face stay at their base values).
            'ui' => [
                'theme' => $user?->uiTheme() ?? User::defaultUiTheme(),
                // The tenant's brand ramp (H23a3, ADR-0014), or null. A SHARED prop rather than a
                // per-page one because it paints every page — unlike `customDomains`, which H22b kept
                // off the shared payload precisely because it served one card.
                //
                // Read through TenantBrandingService::isActive(), NEVER Tenant::hasBrandRamp(): stored
                // and active are different questions, and a surface that checks the wrong one ships a
                // Starter+ feature to Free tenants with nothing in the build to notice.
                'brand' => app(TenantBrandingService::class)->sharedRamp(),
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
                // Non-fatal branching notices raised after a PUBLISH (Increment H21a, Doc #27 §6): a
                // forward reference, a circular condition, or a form that shows nothing until something is
                // answered. Its own key rather than a reuse of `xlsformWarnings`, whose banner copy is
                // hard-coded to "Imported with N warning(s)" — the publish already succeeded and the
                // wording has to say so.
                'publishWarnings' => $request->session()->get('publishWarnings'),
                // The answers RELEVANCE dropped on the manual-encode channel (Increment H21c, Doc #27 §7):
                // the keyer typed them, Stage 3 masked them off, and the page used to say "Submission
                // recorded." over the loss. Its own key for the same reason `publishWarnings` is: this one is
                // an outcome report on a write that SUCCEEDED, so it must not borrow copy that says otherwise.
                'prunedAnswers' => $request->session()->get('prunedAnswers'),
                // Webhook one-shot flashes (H14). `newSecret` carries the plaintext signing secret exactly
                // once after a create/rotate so the page can reveal + copy it, then it is gone (never a durable
                // prop; AuditRedactor already strips it from the ledger). `testResult` carries the synchronous
                // test.ping outcome for the Show page's result modal — the tester never throws, so it is a plain
                // 200-shaped array. Both live for a single request via the session flash.
                'newSecret' => $request->session()->get('newSecret'),
                'testResult' => $request->session()->get('testResult'),
            ],
        ];
    }
}
