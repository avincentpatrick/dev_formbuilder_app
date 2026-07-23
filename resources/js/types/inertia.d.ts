// Shared Inertia props exposed by HandleInertiaRequests::share() — typed globally so usePage()
// is strongly typed everywhere (auth.user for the account menu, ui.theme for the theme toggle).

export type ThemeMode = 'system' | 'light' | 'dark';

/**
 * The curated accent set (design-system-reference.md §2.9) — closed at two options by ratified
 * decision, never an arbitrary colour picker. `blueprint` is the product default and is stored as
 * NULL server-side, but always travels as a real value on the wire.
 */
export type AccentToken = 'blueprint' | 'teal';

/** Uniform text-size scale (§2.9). `standard` emits no attribute. */
export type FontSizeScale = 'standard' | 'large' | 'extra_large';

/**
 * Mirrors User::uiTheme() / User::defaultUiTheme(). camelCase over the wire (matching `auth.can.*`);
 * the PATCH request fields stay snake_case to match the column names.
 */
export interface UiTheme {
    mode: ThemeMode;
    accent: AccentToken;
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
}

export type FlashToast = { type: 'success' | 'error' | 'info'; message: string };

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
        ui: { theme: UiTheme };
        flash: { toast: FlashToast | null; xlsformWarnings?: string[] | null };
        entitlements: EntitlementSnapshot | null;
        errors: Record<string, string>;
    }
}
