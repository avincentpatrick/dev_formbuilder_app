<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Setting;
use App\Models\Tenant;

/**
 * The settings vocabulary (data-dictionary §20, PRD Feature #10) — Increment I5.
 *
 * **This enum is the only place a default is written down, and that is the whole design.** The `settings`
 * table is SPARSE: a tenant that has never touched a toggle has no row for it, so "absent" has to mean
 * something, and it means {@see self::default()}. Every read path resolves through here and every surface
 * renders the RESOLVED answer — the UI never sees a raw sparse row, which is the same shape I4 gave
 * {@see NotificationType} and its preference resolver.
 *
 * ── Scope is a property of the KEY, not of the caller ───────────────────────────────────────────────────
 * {@see self::scope()} says whether a key belongs on a tenant row or on the platform (NULL-tenant) row.
 * Both live in one table with two partial unique indexes, and the readers filter on `tenant_id` — so a key
 * written to the wrong side would be invisible rather than wrong, which is exactly the kind of silence the
 * scope test exists to prevent.
 *
 * ── What is deliberately NOT here ──────────────────────────────────────────────────────────────────────
 * **Tenant maintenance mode.** It is `tenants.maintenance_mode` / `.maintenance_message`, two real columns,
 * because the guest runtime consults it on every public form render and the tenant model is already loaded
 * there (see 2026_08_06_000004's docblock). Only the PLATFORM maintenance pair lives in this enum.
 *
 * **Module toggles.** `modules.<plan_feature_key>` is an open namespace keyed by
 * {@see App\Support\Entitlements\ToggleableModules}, not a fixed case list — the set of modules follows the
 * plan catalog, and enumerating them twice is how the two lists come to disagree. Their default is "on"
 * (a tenant that has expressed no opinion gets whatever its plan grants), expressed in
 * {@see App\Services\Settings\TenantSettingRegistry::moduleEnabled()} rather than as a case here.
 */
enum SettingKey: string
{
    /** Tenant: may a person join this workspace by registering on its subdomain, or only by invitation? */
    case RegistrationInviteOnly = 'registration.invite_only';

    /** Tenant: must every member of this workspace have completed 2FA enrollment (I8a, PRD Feature #14)? */
    case SecurityRequireTwoFactor = 'security.require_two_factor';

    /** Platform: is new account signup open at all? */
    case RegistrationOpenSignup = 'registration.open_signup';

    /** Platform: is the whole product in a maintenance window? */
    case MaintenanceEnabled = 'maintenance.enabled';

    /** Platform: the notice shown while the window is open. */
    case MaintenanceMessage = 'maintenance.message';

    /**
     * The value a read resolves to when no row exists.
     *
     * Both booleans are the SAFE reading of "nobody has decided yet": a workspace nobody has configured is
     * invite-only (a stranger cannot join by guessing a subdomain), and the platform is NOT in maintenance
     * (a fresh install serves traffic). Note those two point in opposite directions on purpose — the
     * fail-safe answer for an access control is "closed", and for an availability switch it is "open".
     *
     * ⚠️ `SecurityRequireTwoFactor` DEFAULTS FALSE, AND THAT IS NOT THE FAIL-CLOSED READING. It looks like
     * an access control and by the paragraph above ought to default true; it must not. The table is
     * SPARSE, so "absent" is the state of every workspace that already exists — defaulting true would,
     * on the deploy that adds this key, redirect every member of every workspace to an enrollment
     * interstitial they never asked for, including Owners who would then have to enroll before they could
     * reach the switch to turn it off. An enforcement policy is a decision a workspace MAKES, not one it
     * inherits; the fail-safe reading of "nobody has decided" here is "nobody has decided".
     */
    public function default(): bool|string
    {
        return match ($this) {
            self::RegistrationInviteOnly => true,
            self::SecurityRequireTwoFactor => false,
            self::RegistrationOpenSignup => true,
            self::MaintenanceEnabled => false,
            self::MaintenanceMessage => '',
        };
    }

    /** Whether this key is stored on a tenant's row or on the platform (NULL-tenant) row. */
    public function scope(): SettingScope
    {
        return match ($this) {
            self::RegistrationInviteOnly,
            self::SecurityRequireTwoFactor => SettingScope::Tenant,
            self::RegistrationOpenSignup,
            self::MaintenanceEnabled,
            self::MaintenanceMessage => SettingScope::Platform,
        };
    }

    /**
     * Every key that belongs on a {@see Tenant}'s own {@see Setting} rows.
     *
     * @return list<self>
     */
    public static function tenantKeys(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $case): bool => $case->scope() === SettingScope::Tenant));
    }

    /**
     * Every key that belongs on the platform row.
     *
     * @return list<self>
     */
    public static function platformKeys(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $case): bool => $case->scope() === SettingScope::Platform));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
