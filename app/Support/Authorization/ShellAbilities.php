<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\Audit;
use App\Models\Form;
use App\Models\SavedReportView;
use App\Models\ScopeNode;
use App\Models\User;

/**
 * The app shell's ability map — "which destinations may this user reach" (extracted in Increment J1c).
 *
 * ⚠️ EXTRACTED, NOT INVENTED. Every entry below was `HandleInertiaRequests::share()`'s `auth.can` block,
 * moved verbatim with its reasoning intact. The middleware still produces the identical prop shape; it now
 * reads it from here. The extraction exists because J1c added a SECOND consumer — `DestinationCatalog`,
 * which answers the same question for global search — and a second copy of this map is how the sidebar and
 * the command palette start disagreeing about what a Viewer can reach. This repo has paid for that shape
 * before: form visibility was encoded twice, differently, and J1b's `Form::scopeVisibleTo()` extraction is
 * the same move for the same reason.
 *
 * ⚠️ THE KEYS ARE A PUBLIC CONTRACT. `resources/js/types/inertia.d.ts` declares them as `AppAbilities` and
 * `nav-model.ts` references them by name in its `gate` field, so renaming one silently hides a nav item
 * rather than failing loudly. `ShellAbilityParityTest` is what holds the two ends together: it reads
 * `AppAbilities` off disk and asserts the two key SETS are equal -- not their ORDER, which nothing
 * consumes -- and it refuses any nav `gate` or catalog `ability` naming a key this map does not define.
 * That last direction is a SUBSET check on purpose: `transferOwnership` and `assignRoles` are spent by a
 * page rather than by the nav, so an ability with no nav item is ordinary and equality would be wrong.
 *
 * Computed FAIL-CLOSED: on tenant routes `EstablishTenantDatabaseContext` has set the Spatie permissions
 * team by the time this is called (and resets it in `terminate()`), so `can()` resolves against the active
 * tenant; off-tenant (central/guest) no team is set, so every ability resolves to false — exactly the
 * gating we want, and the reason a null user is accepted rather than guarded against.
 */
final class ShellAbilities
{
    /**
     * @return array<string, bool>
     */
    public static function for(?User $user): array
    {
        return [
            'manageMembers' => (bool) $user?->can('tenant.members.invite'),
            'transferOwnership' => (bool) $user?->can('tenant.ownership.transfer'),
            // Gates the Members page's per-row role control (I8a). A SEPARATE key from
            // manageMembers because the catalog separates them: `tenant.roles.assign` is the
            // authority to change what someone may DO, `tenant.members.invite` only who is here.
            // Both land on Owner/Admin today, but collapsing them here would quietly decide that
            // they must always agree.
            'assignRoles' => (bool) $user?->can('tenant.roles.assign'),
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
            // Gates the Feedback nav item + the /feedback list (I7a). The BARE PERMISSION, not a
            // policy, for the reason `manageDomains` gives: `feedback_reports` has no model policy
            // — the page is a tenant-scoped list with no per-instance authorization question, and
            // RLS already answers "whose rows are these". No companion plan feature, on the audit
            // ledger's reasoning: seeing what your own people reported about the product is a
            // baseline, not a tier.
            'viewFeedback' => (bool) $user?->can('feedback.view'),
        ];
    }
}
