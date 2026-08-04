/**
 * The custom-domain admin surface's prop shapes (Increment H22b / ADR-0012).
 *
 * Mirrors `App\Services\Tenancy\DomainPresenter::row()` field for field, which in turn mirrors
 * `DomainResource` so the web and /api/v1 surfaces describe the same records in the same
 * vocabulary. `status` is the ADR's three-word derivation and nothing else may be added to it — the
 * database keeps three nullable timestamps precisely so the word is computed rather than stored.
 */

/** The DNS challenge, returned on EVERY state — a tenant that lost its record needs it back to recover. */
export interface DomainVerification {
    type: 'TXT';
    name: string;
    value: string;
}

export interface DomainRow {
    domain: string;
    status: 'pending' | 'verified' | 'live';
    verification: DomainVerification;
    verified_at: string | null;
    activated_at: string | null;
    last_checked_at: string | null;
    failure_reason: 'not_found' | 'mismatch' | 'lookup_failed' | null;
    /** Control is proven and the hostname still serves nothing — an operator step remains (ADR-0012 §D6). */
    awaiting_operator: boolean;
    is_primary: boolean;
    /** Whether respondent links are built on THIS host right now — read from TenantUrl, never re-derived. */
    is_public_host: boolean;
    can_be_primary: boolean;
    /** The last DNS outcome as an instruction. Null unless the last check failed. */
    failure_hint: string | null;
}
