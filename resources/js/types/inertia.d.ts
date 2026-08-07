// Shared Inertia props exposed by HandleInertiaRequests::share() — typed globally so usePage()
// is strongly typed everywhere (auth.user for the account menu, ui.theme for the theme toggle).

export type ThemeMode = 'system' | 'light' | 'dark';

/**
 * The curated accent set (design-system-reference.md §2.9) — closed at two options by ratified
 * decision, never an arbitrary colour picker.
 *
 * Since H23a3 the WIRE value is nullable, and null is a real state rather than a missing one: it means
 * "no opinion", which on a branded tenant resolves to the organisation's brand and everywhere else to
 * the product default. `blueprint` now means "the product default EXPLICITLY", which is the only way a
 * member of a branded tenant can opt back out of their organisation's colour.
 */
export type AccentToken = 'blueprint' | 'teal';

/** The stored accent, where `null` means "no opinion — use my organisation's brand if it has one". */
export type AccentPreference = AccentToken | null;

/** Uniform text-size scale (§2.9). `standard` emits no attribute. */
export type FontSizeScale = 'standard' | 'large' | 'extra_large';

/**
 * Mirrors User::uiTheme() / User::defaultUiTheme(). camelCase over the wire (matching `auth.can.*`);
 * the PATCH request fields stay snake_case to match the column names.
 */
export interface UiTheme {
    mode: ThemeMode;
    accent: AccentPreference;
    fontSize: FontSizeScale;
    dyslexiaFont: boolean;
}

export interface AppUser {
    id: string;
    name: string;
    email: string;
}

// Ability gates the shell/pages consume (computed fail-closed in HandleInertiaRequests::share()).
export interface AppAbilities {
    manageMembers: boolean;
    transferOwnership: boolean;
    manageForms: boolean;
    viewSubmissions: boolean;
    manageScopes: boolean;
    manageWebhooks: boolean;
    manageIntegrations: boolean;
    // H24b2 — SavedReportViewPolicy::viewAny (dashboard.org.view || dashboard.form.view). Every consumer
    // combines it with the `advanced_analytics` entitlement, which is what makes the surface HIDDEN rather
    // than locked for a tier that does not have it (ADR-0011 §D9).
    viewAnalytics: boolean;
    // H22b — the bare `tenant.settings.manage` permission (Owner/Admin). The NAV ITEM combines it with the
    // `custom_domain` entitlement; the PAGE deliberately does not, so a tenant downgraded off Business can
    // still see and remove a hostname that is still resolving (ADR-0012 §D9).
    manageDomains: boolean;
    // I2 — AuditPolicy::viewAny (the `audit_log.view` permission, Owner/Admin only; Viewer is excluded on
    // purpose). THE ONLY GATE HERE WITH NO COMPANION ENTITLEMENT, and the absence is deliberate rather than
    // an oversight: PlanCatalog carries no audit key on any tier, because an audit trail is a baseline
    // obligation for every tenant and not an upsell. Do not add a `feature:` to its nav item by symmetry
    // with its four neighbours.
    viewAuditLog: boolean;
    // `feedback.view` (I7a) — Owner/Admin. Like viewAuditLog it carries no companion plan feature: seeing
    // what your own people reported about the product is a baseline, not a tier.
    viewFeedback: boolean;
}

export type FlashToast = { type: 'success' | 'error' | 'info'; message: string };

/** One-shot plaintext signing secret, flashed once after a webhook create/rotate (H14). */
export type FlashNewSecret = { id: string; name: string; secret: string };

/** The synchronous test.ping outcome, flashed to the webhook Show page's result modal (H14). */
export type FlashTestResult = {
    delivered: boolean;
    response_status: number | null;
    response_time_ms: number | null;
    response_body_excerpt: string | null;
    error: string | null;
};

/**
 * The tenant's entitlement snapshot (H5c) — mirrors EntitlementService::snapshot(). `null` off-tenant
 * (guest/central), so client-side gating fail-closes (a falsy feature hides its affordance). `features` is
 * the plan flag map, override-aware (a grandfathered tenant reads `true`); `quotas` is every metric's
 * limit-vs-usage (`limit: null` = unlimited). Keys are the snake_case server keys.
 */
export interface EntitlementSnapshot {
    plan: { code: string; name: string };
    features: Record<string, boolean>;
    quotas: Record<string, { limit: number | null; used: number }>;
}

declare module '@inertiajs/core' {
    interface PageProps {
        auth: { user: AppUser | null; can: AppAbilities };
        ui: {
            theme: UiTheme;
            /** The tenant's active brand ramp (H23a3), or null. Theme => role => #RRGGBB. */
            brand: Record<'light' | 'dark', Record<string, string>> | null;
        };
        flash: {
            toast: FlashToast | null;
            xlsformWarnings?: string[] | null;
            /** Increment H21a — branching notices raised after a publish that SUCCEEDED (Doc #27 §6). */
            publishWarnings?: string[] | null;
            /**
             * Increment H21c — the answers relevance dropped on the manual-encode channel, labelled as the
             * keyer saw them (Doc #27 §7). Flashed only when the list is non-empty, and only after a
             * submission that was actually created.
             */
            prunedAnswers?: string[] | null;
            newSecret?: FlashNewSecret | null;
            testResult?: FlashTestResult | null;
        };
        entitlements: EntitlementSnapshot | null;
        errors: Record<string, string>;
    }
}
