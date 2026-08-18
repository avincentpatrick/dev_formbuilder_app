<?php

declare(strict_types=1);

use App\Enums\SettingKey;
use App\Enums\SettingScope;
use App\Support\Entitlements\ToggleableModules;
use Database\Seeders\Data\PlanCatalog;

/*
|--------------------------------------------------------------------------
| The I5 settings vocabulary — the two hand-maintained lists that have to agree with something else.
|
| PURE by construction: Relation::morphMap() is empty and the container is unbooted in Unit tests, so
| anything that touched an Eloquent cast would die on "connection resolver". SettingKey and
| ToggleableModules are constants and `match`es for exactly that reason.
*/

it('gives every settings key a default — the sparse table depends on it', function (SettingKey $key): void {
    // `default()` is exhaustive with no `default:` arm, so a new case is a FATAL here rather than a silent
    // null reaching a switch that renders as "off". This test is what makes that fatal visible in CI.
    expect($key->default())->toBeIn([true, false, '']);
})->with(SettingKey::cases());

it('assigns every key to exactly one scope, and both halves are populated', function (): void {
    foreach (SettingKey::cases() as $key) {
        expect($key->scope())->toBeInstanceOf(SettingScope::class);
    }

    // Both partial unique indexes exist because both kinds of row exist. If one of these lists were ever
    // empty, half the schema would be dead weight and the two-index decision would be wrong.
    expect(SettingKey::tenantKeys())->not->toBeEmpty();
    expect(SettingKey::platformKeys())->not->toBeEmpty();
    expect(count(SettingKey::tenantKeys()) + count(SettingKey::platformKeys()))
        ->toBe(count(SettingKey::cases()));
});

it('keeps maintenance.* on the PLATFORM side only', function (): void {
    // The tenant half of maintenance mode is `tenants.maintenance_mode` / `.maintenance_message`, two real
    // columns, so the guest runtime reads it off the already-resolved tenant with no extra query. A
    // `SettingKey` case scoped Tenant for `maintenance.*` would mean somebody re-introduced the row and
    // silently paid that query on every public form render.
    expect(SettingKey::MaintenanceEnabled->scope())->toBe(SettingScope::Platform);
    expect(SettingKey::MaintenanceMessage->scope())->toBe(SettingScope::Platform);
});

it('offers only modules the plan catalog actually gates', function (string $module): void {
    // Two hand-maintained lists that must agree. A key here that the catalog does not know is a switch
    // wired to nothing: EntitlementService::feature() would never be asked about it, so switching it off
    // would change nothing anywhere and nobody would find out.
    expect(PlanCatalog::FEATURE_KEYS)->toContain($module);
})->with(ToggleableModules::KEYS);

it('excludes the capabilities whose "off" would strand live state', function (string $module): void {
    // ADR-0012 §D9: a tenant that loses a paid feature must keep a path to UNDO what the paid tier let it
    // create. Switching `custom_domain` off here would hide /domains while a hostname is still resolving
    // to the platform — precisely the state that escape hatch exists to prevent. `branding` is the same
    // shape one step down. The other four are provisioning arrangements, not Tuesday-afternoon toggles.
    expect(ToggleableModules::KEYS)->not->toContain($module);
})->with(['branding', 'custom_domain', 'sso_saml', 'dedicated_db', 'data_residency', 'embedded_payments']);

it('labels and explains every module it offers', function (string $module): void {
    // A module row with no hint is a switch whose consequence the admin has to guess, and they differ
    // sharply (one hides a surface, another stops respondents mid-form).
    expect(ToggleableModules::label($module))->not->toBe($module);
    expect(ToggleableModules::hint($module))->not->toBe('');
})->with(ToggleableModules::KEYS);

it('recognises only its own keys as toggleable', function (): void {
    expect(ToggleableModules::isToggleable('webhooks'))->toBeTrue();
    expect(ToggleableModules::isToggleable('custom_domain'))->toBeFalse();
    expect(ToggleableModules::isToggleable('not_a_module'))->toBeFalse();
    expect(ToggleableModules::settingKey('webhooks'))->toBe('modules.webhooks');
});
