/**
 * Shared read-model types for the form surfaces — the hub, the share modal, and anything else that renders
 * a form outside the builder.
 *
 * `ShareProps` moved here from `@/components/builder/types` in J2b, and the move is the point: the payload
 * is no longer the builder's. `FormSharePresenter` (extracted from `BuilderPresenter` in the same increment)
 * now serves BOTH the builder and the form hub, and a type living under `builder/` while two unrelated pages
 * import it is the kind of drift that ends with someone declaring a second copy rather than reaching across
 * a directory that looks like it belongs to something else. `ShareModal.vue` itself has always lived in
 * `components/forms/`, so this puts the type beside its only component.
 *
 * ⚠️ NO RE-EXPORT SHIM WAS LEFT BEHIND in `builder/types.ts`. Two live import paths for one interface is how
 * the next author picks the wrong one and the pair silently diverge; there were exactly three referencing
 * files and all three were updated in the same commit.
 */

// The share surface's read model (Increment I1, PRD Feature #3). Every absolute URL here is composed by
// TenantUrl's PUBLIC arm on the server — a custom domain serves the guest runtime and only the guest runtime
// (ADR-0012 §D1), so this is deliberately NOT derivable from `window.location` in the browser.
export interface ShareProps {
    public_slug: string | null;
    allow_guest_submissions: boolean;
    // Spam protection (I8b, PRD Feature #3). `bot_challenge` is a proof-of-work check the respondent's
    // browser solves before submitting; `guest_rate_limit_per_minute` is a per-IP ceiling for this form,
    // null meaning "no per-form ceiling" (the deployment-wide limits still apply).
    bot_challenge: 'off' | 'proof_of_work';
    guest_rate_limit_per_minute: number | null;
    // A slug that is already free within the tenant, from the same FormSlug helper the XLSForm importer uses,
    // so the editor opens on a value that will save rather than one the author discovers is taken via a 422.
    suggested_slug: string;
    // `current_published_version_id !== null`. False means the public link 404s even with guest access on.
    is_published: boolean;
    public_host: string;
    // Null whenever `public_slug` is — the modal shows the "no link yet" state rather than a plausible URL
    // that leads nowhere.
    public_url: string | null;
}

/**
 * The fields the form-settings sections read and write (M129, `D63`): the same block in the builder's "Form
 * settings" modal and on the hub's Settings tab, from one server presenter, `FormSettingsPresenter`, so the two
 * entry points can never show a section different values.
 */
export interface FormSettingsForm {
    title: string;
    description: string | null;
    save_and_resume: boolean;
    single_page_mode: boolean;
    opens_at: string | null;
    closes_at: string | null;
    timezone: string;
    max_responses: number | null;
    confirmation_message: string | null;
    confirmation_message_translations: Record<string, string>;
    // M130 (`R-db169c29`, `D76`) — where a respondent goes after the thank-you screen, and where they could.
    redirect_kind: RedirectKind;
    redirect_form_id: string | null;
    redirect_url: string | null;
    redirect_targets: RedirectTargetOption[];
    // M131 (`R-6017d6d8`, `D65`, `D81`) — the form's preset theme (null = the workspace brand), and every preset.
    theme_preset: string | null;
    theme_presets: ThemePresetOption[];
    default_locale: string;
    supported_locales: string[];
}

/**
 * One preset theme, as `FormThemePreset::catalogue()` transmits it — the client keeps no copy of a colour. `tokens`
 * are the six colour roles per theme; `lines` the documented extra properties (font pair, radius scale).
 */
export interface ThemePresetOption {
    value: string;
    label: string;
    description: string;
    font: string;
    radius: string;
    tokens: Record<'light' | 'dark', { bg: string; bg_hover: string; bg_active: string; fg: string; tint: string; ring: string }>;
    lines: Record<string, string>;
}

/** Where a respondent goes after the thank-you screen (`D76`): nowhere, another form, or a web address. */
export type RedirectKind = 'none' | 'form' | 'url';

/** A form an author may send respondents to; `live` is whether its public link takes responses now. */
export interface RedirectTargetOption {
    id: string;
    title: string;
    live: boolean;
}

/**
 * The Scanning section (M129): whether this form accepts scans of its printed paper, and whether its current
 * published version is one paper can carry. The server sends null where the workspace cannot scan at all.
 */
export interface OcrScanningProps {
    enabled: boolean;
    eligible: boolean;
    /** Why the current version cannot be read, in the upload's own words; null when it can. */
    reason: string | null;
}

/** One node of the scope picker (G10b2). */
export interface ScopeOption {
    id: string;
    name: string;
    parent_id: string | null;
    is_active: boolean;
}

/** The hub-only Scope section (M129): sent only to a holder of `scopes.manage`, the route's second gate. */
export interface ScopeSectionProps {
    current_node_id: string | null;
    options: ScopeOption[];
}
