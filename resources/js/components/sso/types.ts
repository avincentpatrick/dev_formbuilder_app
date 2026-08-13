/**
 * The wire contract for the SSO settings surface (P1a — ADR-0016).
 *
 * Mirrors `App\Services\Sso\SsoConnectionPresenter` field for field, so a fixture that type-checks here is
 * a fixture the server could actually have sent — the `components/domains/types.ts` posture.
 *
 * ⚠️ THERE IS DELIBERATELY NO FIELD THAT COULD CARRY A CERTIFICATE BODY. The presenter never emits
 * `idp_certificates`, and the absence of a place to put it is what makes vue-tsc a second guard on that:
 * a future prop spread that leaked the column would not type-check against this file.
 */

/** How a single signing certificate stands right now. Derived server-side so a skewed browser clock cannot disagree. */
export type CertificateState = 'valid' | 'expiring_soon' | 'expired' | 'not_yet_valid' | 'unreadable';

/** The one word the page leads with, across the whole certificate set. */
export type CertificateRollupState = 'ok' | 'expiring_soon' | 'expired' | 'not_yet_valid' | 'unreadable';

export type SsoStatus = 'draft' | 'active' | 'disabled';

export interface SsoCertificateRow {
    /** Subject CN. Null when the certificate is unreadable, or when a multi-valued RDN makes it ambiguous. */
    subject: string | null;
    issuer: string | null;
    serial: string | null;
    /** First 12 hex of this certificate's own SHA-256 — the value an IdP console labels "Thumbprint". */
    thumbprint_short: string | null;
    not_before: string | null;
    not_after: string | null;
    /** Signed: negative means it expired that many days ago. Server-computed. */
    expires_in_days: number | null;
    state: CertificateState;
}

export interface SsoConnectionRow {
    status: SsoStatus;
    status_label: string;
    protocol_label: string;
    /** Whether the protocol endpoints may serve — `status` AND a non-empty key set. */
    serves_protocol: boolean;
    idp_entity_id: string;
    idp_sso_url: string;
    name_id_format: string;
    /** False when an import stored a urn the four-option picker cannot represent; the control renders read-only. */
    name_id_format_known: boolean;
    /** This application resolves identities by email, so anything else will not match a user. */
    name_id_format_is_email: boolean;
    jit_provisioning_enabled: boolean;
    default_role_name: string;
    default_role_label: string;
    attribute_map: Record<string, string>;
    /** First 12 hex of the SHA-256 over the whole certificate SET. Not the same thing as a thumbprint. */
    fingerprint_short: string;
    certificate_count: number;
    certificates: SsoCertificateRow[];
    certificates_state: CertificateRollupState;
    /** Prose instruction, not a status. Null when there is nothing to do. */
    certificate_warning: string | null;
    metadata_imported_at: string | null;
    /** Null until P1b lands the login round trip. */
    last_login_at: string | null;
}

/** The values an admin pastes into their identity provider's console. */
export interface SsoServiceProvider {
    entity_id: string;
    acs_url: string;
    metadata_url: string;
}

export interface SsoOption {
    value: string;
    label: string;
}

export interface SsoPageProps {
    data: SsoConnectionRow | null;
    sp: SsoServiceProvider;
    roles: SsoOption[];
    nameIdFormats: SsoOption[];
    attributeKeys: string[];
    can: {
        /** Owner/Admin AND the plan — gates import and the policy controls. */
        configure: boolean;
        /** Owner/Admin alone. Disable and Remove stay available after a downgrade (ADR-0016 §D5). */
        manage: boolean;
    };
    entitled: boolean;
}

/** The `/settings` signpost prop. `visible` is `entitled OR configured` — wider than the domains card, on purpose. */
export interface SsoSettingsCard {
    visible: boolean;
    configured: boolean;
    entitled: boolean;
    status: SsoStatus | null;
    status_label: string | null;
}
