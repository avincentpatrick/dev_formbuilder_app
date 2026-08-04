import type { IconName } from '@meridian/design-system';
import type { AppAbilities } from '@/types/inertia';

export interface NavItem {
    key: string;
    label: string;
    icon: IconName;
    href?: string;
    enabled: boolean;
    // When set, the item is only shown if the current user holds this ability (auth.can) — the nav is
    // permission-aware (e.g. Members is an Owner/Admin management surface).
    gate?: keyof AppAbilities;
    // When set, the item is ALSO hidden unless the tenant's plan includes this entitlement feature (H14) —
    // so a plan-gated destination (Webhooks = Starter+) never appears for a tier that would only bounce off
    // its `feature:` route guard. Distinct from `gate` (a permission); both must pass when both are set.
    feature?: string;
}

// Primary sidebar sections (DSR §3.4 order). Forms + Submissions are Phase-1 destinations that
// don't exist yet — shown as disabled "Soon" items so the eventual nav shape is visible now.
export const navItems: NavItem[] = [
    { key: 'forms', label: 'Forms', icon: 'forms', href: '/forms', enabled: true, gate: 'manageForms' },
    { key: 'submissions', label: 'Submissions', icon: 'submissions', href: '/submissions', enabled: true, gate: 'viewSubmissions' },
    { key: 'dashboard', label: 'Dashboard', icon: 'dashboard', href: '/dashboard', enabled: true },
    // Cross-form analytics (H24b2) — a permission (gate) AND a Business+ plan feature (feature). ADR-0011
    // §D9 makes the hiding load-bearing rather than tidy: Business is seeded is_active:false, so a locked
    // item with an upgrade CTA would point at a plan that cannot be bought. Its own glyph, not `activity`
    // — below 1024px the sidebar is icons only, and there the mark is the only thing distinguishing it
    // from Webhooks.
    { key: 'analytics', label: 'Analytics', icon: 'chart-bar', href: '/analytics', enabled: true, gate: 'viewAnalytics', feature: 'advanced_analytics' },
    { key: 'members', label: 'Members', icon: 'users', href: '/members', enabled: true, gate: 'manageMembers' },
    // Scoping hierarchy (G10b2) — sits beside Members because both are Owner/Admin administration of WHO
    // can reach what, rather than authoring surfaces.
    { key: 'scopes', label: 'Scopes', icon: 'building', href: '/scopes', enabled: true, gate: 'manageScopes' },
    // Webhook management + delivery log (H14) — Owner/Admin (gate) AND a Starter+ plan feature (feature),
    // so a tier without `webhooks` never sees the item (a direct visit still bounces off `feature:webhooks`).
    { key: 'webhooks', label: 'Webhooks', icon: 'activity', href: '/webhooks', enabled: true, gate: 'manageWebhooks', feature: 'webhooks' },
    { key: 'integrations', label: 'Integrations', icon: 'plug', href: '/integrations', enabled: true, gate: 'manageIntegrations', feature: 'native_connectors' },
    // Custom domains (H22b) — Owner/Admin (gate) AND a Business+ plan feature (feature). Hidden rather than
    // locked, the ADR-0011 §D9 posture: Business is seeded is_active:false, so an upgrade CTA would point at
    // a plan nobody can buy.
    //
    // ⚠️ THE FEATURE GATE HERE IS NARROWER THAN THE ROUTE'S, deliberately. /domains itself carries NO
    // `feature:` middleware on its read and delete (ADR-0012 §D9 — a tenant downgraded off Business keeps a
    // live, resolving hostname and must be able to take it down). Losing the nav item on downgrade is
    // therefore not the end of the path: Settings/Index.vue links the page once the tenant holds a domain.
    { key: 'domains', label: 'Domains', icon: 'globe', href: '/domains', enabled: true, gate: 'manageDomains', feature: 'custom_domain' },
    { key: 'settings', label: 'Settings', icon: 'settings', href: '/settings', enabled: true },
];
