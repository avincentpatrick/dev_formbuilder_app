import type { IconName } from '@meridian/design-system';
import type { AppAbilities } from '@/types/inertia';

export interface NavItem {
    key: string;
    label: string;
    icon: IconName;
    href: string;
    // When set, the item is only shown if the current user holds this ability (auth.can) — the nav is
    // permission-aware (e.g. Members is an Owner/Admin management surface).
    gate?: keyof AppAbilities;
    // When set, the item is ALSO hidden unless the tenant's plan includes this entitlement feature (H14) —
    // so a plan-gated destination (Webhooks = Starter+) never appears for a tier that would only bounce off
    // its `feature:` route guard. Distinct from `gate` (a permission); both must pass when both are set.
    feature?: string;
}

export interface NavGroup {
    key: string;
    /**
     * `null` renders the run with NO label element and NO `aria-labelledby` — never an empty heading, and
     * never a dangling idref. The lead run and the Settings tail are both null deliberately: the floor is
     * two items off-tenant (Dashboard and Settings, the only two that are ungated), and a category name
     * over a single item is noise rather than structure.
     */
    label: string | null;
    items: NavItem[];
}

/**
 * Primary sidebar sections, grouped (J4b).
 *
 * ⚠️ THE GROUPING IS A PARTITION OF THE ORDER THAT WAS ALREADY HERE — NOT ONE ITEM MOVES. Until J4b this
 * file was a flat array whose grouping existed only as prose in three comments, each saying the same thing
 * about the same cluster: Scopes "sits beside Members because both are Owner/Admin administration of WHO
 * can reach what, rather than authoring surfaces"; the audit ledger is "the RECORD of that same Owner/Admin
 * administration"; Feedback "sits next to the ledger because it is the other read-only record of things
 * that happened". Three authors independently described one group and had nowhere to put it. The runs below
 * are contiguous in the pre-J4b order, which is what makes this presentational rather than a re-ordering.
 *
 * ⚠️ TWO LABELS, NOT FOUR, AND THE TWO NULLS ARE THE INTERESTING HALF. Heading the lead run would put a
 * category name over the four destinations DSR §3.4 names as the primary ones — they need no explaining.
 * Settings is its own tail run rather than the end of `connections` because all three connection items are
 * plan-gated: an Owner on Free would otherwise read "Connections → Settings", a heading over the one item
 * it does not describe.
 *
 * ⚠️ AND `app/Support/Search/DestinationCatalog.php` IS A PARALLEL COPY OF THIS LIST, DELIBERATELY LEFT
 * UNGROUPED. It answers "what may this user reach", not "how is it arranged": the command palette groups
 * results by entity and caps each group, and the search page ranks flat across entities on purpose. Adding
 * a group key there would mirror a spatial arrangement into two surfaces that are not spatial. The gates
 * are what the two files must agree on, and those are untouched here.
 */
export const navGroups: NavGroup[] = [
    {
        key: 'workspace',
        label: null,
        items: [
            { key: 'forms', label: 'Forms', icon: 'forms', href: '/forms', gate: 'manageForms' },
            { key: 'submissions', label: 'Submissions', icon: 'submissions', href: '/submissions', gate: 'viewSubmissions' },
            { key: 'dashboard', label: 'Dashboard', icon: 'dashboard', href: '/dashboard' },
            // Achievements (K1e) — beside Dashboard because it answers the other half of "how is this
            // workspace doing": the dashboard counts the work, this counts the people doing it.
            //
            // ⚠️ NO `gate`, AND THE ABSENCE IS ADR-0020 §D7 RATHER THAN AN OVERSIGHT: every member may see
            // their OWN points, badges, streak and standing with no permission at all, so there is no
            // ability to name and none was minted. The named ladder inside the page IS gated, on the
            // existing `dashboard.org.view`, and the controller resolves that into a null prop — gating the
            // destination instead would hide a member's own achievements from them.
            //
            // ⚠️ `feature: 'gamification'` LOOKS LIKE A PLAN GATE AND IS ACTUALLY THE MODULE TOGGLE, which
            // is the only reason it is safe to reuse this field. ADR-0020 §D6 grants the key on EVERY tier
            // including Free, so `EntitlementService::feature()`'s plan half can never be what refuses it
            // and what remains is `modules.gamification` — the same thing the route's `module:gamification`
            // middleware reads. A second `module` axis was considered and refused: it would be a parallel
            // gating vocabulary for a field that already computes the right answer here.
            { key: 'achievements', label: 'Achievements', icon: 'award', href: '/achievements', feature: 'gamification' },
            // Cross-form analytics (H24b2) — a permission (gate) AND a Business+ plan feature (feature). ADR-0011
            // §D9 makes the hiding load-bearing rather than tidy: Business is seeded is_active:false, so a locked
            // item with an upgrade CTA would point at a plan that cannot be bought. Its own glyph, not `activity`
            // — below 1024px the sidebar is icons only, and there the mark is the only thing distinguishing it
            // from Webhooks.
            { key: 'analytics', label: 'Analytics', icon: 'chart-bar', href: '/analytics', gate: 'viewAnalytics', feature: 'advanced_analytics' },
        ],
    },
    {
        key: 'administration',
        label: 'Administration',
        items: [
            { key: 'members', label: 'Members', icon: 'users', href: '/members', gate: 'manageMembers' },
            // Scoping hierarchy (G10b2) — beside Members because both are Owner/Admin administration of WHO
            // can reach what, rather than authoring surfaces.
            { key: 'scopes', label: 'Scopes', icon: 'building', href: '/scopes', gate: 'manageScopes' },
            // The audit ledger (I2, PRD Feature #12) — the RECORD of that same administration.
            //
            // NO `feature:`, and it is the first gated item without one. PlanCatalog defines no audit key on any
            // tier: an audit trail is a baseline obligation for every tenant, not an enterprise upsell, so
            // visibility turns on the permission alone.
            //
            // `shield` rather than `clock`: below 1024px the sidebar is icons-only and the glyph is the SOLE
            // signifier (the rule that minted `chart-bar` and `globe`). `clock` already means version history on
            // /forms, so a user who learned that mark would misread this destination; `shield` reads
            // accountability, is used by no other nav item, and its two in-page uses (Transfer ownership, Grant
            // access) are the same semantic family.
            { key: 'audit', label: 'Audit log', icon: 'shield', href: '/audit-log', gate: 'viewAuditLog' },
            // The workspace's own feedback (I7a, PRD Feature #11) — Owner/Admin, and no `feature:` for the same
            // baseline reason the audit ledger has none. The other read-only record of things that happened,
            // rather than an authoring surface. The `feedback` glyph is already in the icon set (the shell's Send
            // Feedback trigger uses it), so the icons-only sidebar shows the same mark for reading feedback and
            // for sending it — which is the association we want.
            { key: 'feedback', label: 'Feedback', icon: 'feedback', href: '/feedback', gate: 'viewFeedback' },
        ],
    },
    {
        key: 'connections',
        label: 'Connections',
        items: [
            // Webhook management + delivery log (H14) — Owner/Admin (gate) AND a Starter+ plan feature (feature),
            // so a tier without `webhooks` never sees the item (a direct visit still bounces off `feature:webhooks`).
            { key: 'webhooks', label: 'Webhooks', icon: 'activity', href: '/webhooks', gate: 'manageWebhooks', feature: 'webhooks' },
            { key: 'integrations', label: 'Integrations', icon: 'plug', href: '/integrations', gate: 'manageIntegrations', feature: 'native_connectors' },
            // Custom domains (H22b) — Owner/Admin (gate) AND a Business+ plan feature (feature). Hidden rather than
            // locked, the ADR-0011 §D9 posture: Business is seeded is_active:false, so an upgrade CTA would point at
            // a plan nobody can buy.
            //
            // ⚠️ THE FEATURE GATE HERE IS NARROWER THAN THE ROUTE'S, deliberately. /domains itself carries NO
            // `feature:` middleware on its read and delete (ADR-0012 §D9 — a tenant downgraded off Business keeps a
            // live, resolving hostname and must be able to take it down). Losing the nav item on downgrade is
            // therefore not the end of the path: Settings/Index.vue links the page once the tenant holds a domain.
            { key: 'domains', label: 'Domains', icon: 'globe', href: '/domains', gate: 'manageDomains', feature: 'custom_domain' },
        ],
    },
    {
        key: 'workspace-settings',
        label: null,
        items: [
            { key: 'settings', label: 'Settings', icon: 'settings', href: '/settings' },
        ],
    },
];

/**
 * The flat order, DERIVED rather than re-authored — byte-identical to the pre-J4b array.
 *
 * Kept exported so grouping cannot silently re-order the nav, and so the parity case in `Sidebar.test.ts`
 * can assert that property against this value directly rather than against a second hand-written list that
 * would need maintaining alongside it.
 */
export const navItems: NavItem[] = navGroups.flatMap((group) => group.items);
