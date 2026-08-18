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
