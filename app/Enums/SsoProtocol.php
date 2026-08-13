<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\SsoConnection;

/**
 * The federation protocol an {@see SsoConnection} speaks (Phase 4, P1a). The
 * `sso_connections_protocol_check` CHECK is generated from {@see values()} so enum and constraint cannot
 * drift — the {@see WebhookEndpointStatus} treatment.
 *
 *   - {@see Saml2} — SAML 2.0 Web Browser SSO, SP-initiated only (ADR-0016).
 *
 * ── WHY A ONE-CASE ENUM IS NOT OVER-ENGINEERING HERE ──────────────────────────────────────────────────
 * This is the protocol-neutral seam the user ratified on 2026-08-13: SAML now, OIDC later. The whole point
 * is that adding OIDC is a new CASE plus a new driver, never a table rename or a column migration — the
 * discriminator has to exist BEFORE the second protocol, or the first one's assumptions leak into column
 * names (`idp_sso_url` is already protocol-flavoured; `saml_entity_id` on the connections table would have
 * been worse) and every consumer learns to read `saml_*` columns it must later unlearn.
 *
 * It is deliberately NOT a boolean or a nullable column: a two-state string that only ever holds one value
 * still forces every read site to answer "which protocol is this?", which is the question that keeps the
 * driver lookup honest when the second case lands.
 */
enum SsoProtocol: string
{
    case Saml2 = 'saml2';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** Human-readable, for the settings UI and audit-log rendering. */
    public function label(): string
    {
        return match ($this) {
            self::Saml2 => 'SAML 2.0',
        };
    }
}
