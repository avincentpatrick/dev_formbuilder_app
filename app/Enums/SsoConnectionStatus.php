<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\SsoConnection;

/**
 * The lifecycle state of an {@see SsoConnection} (Phase 4, P1a). The `sso_connections_status_check` CHECK is
 * generated from {@see values()} so enum and constraint cannot drift.
 *
 *   - {@see Draft}    — a row exists but the trust anchor is incomplete or untested. The protocol endpoints
 *     answer 404; only the settings screen can see it. This is the state a half-finished metadata import
 *     leaves behind, and it exists so that state is REPRESENTABLE rather than being a partially-populated
 *     `Active` row that logs people in against an IdP nobody has verified.
 *   - {@see Active}   — the tenant's SP endpoints serve, and `/sso/saml/login` will redirect to the IdP.
 *   - {@see Disabled} — turned off by a tenant admin. Retained rather than deleted so the trust anchor and
 *     its audit history survive a temporary suspension; re-enabling is one field, not a re-import.
 *
 * Only {@see Active} connections may mint an AuthnRequest or consume an assertion — `SsoGate` is the single
 * place that decides, and it 404s for every other state (ADR-0016: an unentitled or unconfigured tenant must
 * not be able to tell the difference between "off" and "never existed").
 */
enum SsoConnectionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Disabled = 'disabled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** Human-readable, for the settings UI and audit-log rendering. */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Disabled => 'Disabled',
        };
    }

    /** Whether the protocol endpoints (`/sso/saml/login`, `/sso/saml/acs`) may serve in this state. */
    public function servesProtocol(): bool
    {
        return $this === self::Active;
    }
}
