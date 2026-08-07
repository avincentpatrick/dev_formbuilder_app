/**
 * The super-admin tenant-detail page's prop contract (Increment I7b), mirrored from
 * `App\Services\Admin\TenantDetailPresenter`.
 *
 * Lives beside the components rather than inside `Pages/admin/TenantDetail.vue` for the reason
 * `components/audit/types.ts` and `components/feedback/types.ts` do: the page TEST imports these to build
 * its prop factories, so a fixture that drifts from the real shape fails `npm run type-check` instead of
 * passing a test against a shape the server never sends.
 *
 * ⚠️ **`type` ALIASES, NOT `interface`, AND THE DIFFERENCE IS LOAD-BEARING.** `MdsDataTable` is generic
 * over `Row extends Record<string, unknown>`. TypeScript grants an *implicit index signature* to a type
 * alias but never to an interface, so converting any of these to an interface breaks the build, not the
 * style. That is also why `ConsoleDomainRow` below intersects with `Record<string, unknown>` explicitly
 * rather than relying on `Omit` alone — `DomainRow` IS an interface, and `Omit<Interface, K>` inherits its
 * lack of an index signature.
 */

import type { DomainRow } from '@/components/domains/types';

export type TenantOwner = { name: string; email: string };

export type TenantIdentity = {
    id: string;
    name: string;
    slug: string;
    /** The raw column. Uncast `?string` server-side, so the page never compares it to an enum. */
    status: string;
    status_label: string;
    is_active: boolean;
    created_at: string | null;
    default_locale: string;
    maintenance_mode: boolean;
    maintenance_message: string | null;
    app_host: string;
    public_host: string;
    /** Null when the workspace has no owner, or when the owner is no longer an ACTIVE member. */
    owner: TenantOwner | null;
};

export type PlanIntervalOption = { value: string; label: string };

export type PlanCatalogEntry = {
    id: string;
    code: string;
    name: string;
    description: string;
    /** Held-from-sale tiers (ADR-0008 §D6) are `false` and stay ASSIGNABLE — the page labels, never hides. */
    is_active: boolean;
    sort_order: number;
    feature_flags: Record<string, boolean>;
    interval_options: PlanIntervalOption[];
};

/**
 * The GOVERNING subscription — the row `EntitlementService` resolves from, not necessarily the row
 * `assignPlan()` writes. `subscription_name` is shipped so a divergence between the two is visible.
 */
export type CurrentPlan = {
    plan_id: string;
    code: string | null;
    name: string | null;
    interval: string;
    interval_label: string;
    /** Deliberately uncast free text; render as plain text, never through `statusVariant()`. */
    stripe_status: string;
    subscription_name: string;
    assigned_at: string | null;
};

export type TenantPlan = {
    current: CurrentPlan | null;
    /** What is actually in force — falls back to the seeded `free` plan when there is no subscription. */
    effective: { code: string; name: string } | null;
    catalog: PlanCatalogEntry[];
    intervals: PlanIntervalOption[];
};

/**
 * One usage row. `display` is formatted SERVER-side because only the server knows that `storage_bytes` is
 * bytes and the other seven metrics are counts. `used`/`limit` ship raw so tests assert numbers.
 *
 * ⚠️ `limit === 0` is a real hard limit (the Free tier seeds three of them); `unlimited` is the only
 * correct test for "no ceiling", never `!limit`.
 */
export type UsageRow = {
    metric: string;
    label: string;
    limit: number | null;
    used: number;
    unlimited: boolean;
    display: string;
    at_limit: boolean;
};

export type TenantUsage = {
    /** False only when no plan resolved at all — the card says so rather than rendering eight zeros. */
    available: boolean;
    gauges: UsageRow[];
    flows: UsageRow[];
};

/**
 * A capability, as the plan grants it beside what the workspace effectively has. `reason` explains a
 * mismatch: `tenant_disabled` = the workspace switched it off itself; `legacy_override` = grandfathered
 * on (ADR-0008 §D5); `unavailable` = granted but not effective for any other reason.
 */
export type FeatureRow = {
    key: string;
    label: string;
    plan_grants: boolean;
    effective: boolean;
    reason: 'tenant_disabled' | 'legacy_override' | 'unavailable' | null;
};

/**
 * A domain row minus `verification`: the DNS challenge is the tenant's own recovery affordance and the
 * console offers no copy-to-clipboard, so shipping it would be dead weight on an operator screen.
 * The `& Record<string, unknown>` is what makes this assignable to `MdsDataTable` — see the header.
 */
export type ConsoleDomainRow = Omit<DomainRow, 'verification'> & Record<string, unknown>;

export type TenantDomains = {
    rows: ConsoleDomainRow[];
    app_host: string;
    public_host: string;
};

export type TenantDetailPageProps = {
    tenant: TenantIdentity;
    plan: TenantPlan;
    usage: TenantUsage;
    features: FeatureRow[];
    domains: TenantDomains;
};
