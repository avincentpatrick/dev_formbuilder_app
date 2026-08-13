<?php

declare(strict_types=1);

use App\Exceptions\Entitlements\FeatureGateException;
use App\Support\Authorization\AssignableRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The assignable-role catalog (P1a).
|
| In tests/Feature rather than tests/Unit because the case that matters needs a DATABASE: it compares the
| class against the CHECK constraint compiled into the schema, which is the only comparison that can
| actually fail. Everything else here is a unit assertion riding along.
|--------------------------------------------------------------------------
*/

it('offers exactly the seeded catalog minus owner', function (): void {
    expect(AssignableRoles::values())
        ->toBe(array_values(array_diff(RolePermissionSeeder::ROLES, ['owner'])))
        ->not->toContain('owner');
});

it('cannot drift from the database CHECK constraint', function (): void {
    // THE POINT OF THE CLASS. The picker, the two `Rule::in()` validators and the CHECK were four separate
    // derivations of the same four roles before P1a. A picker that drifts from the constraint does not fail
    // at validation — it fails as a SQLSTATE 23514 five hundred, which no static gate catches. So the two
    // are compared against each other here rather than merely written to match.
    $definition = DB::selectOne(
        'SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?',
        ['sso_connections_default_role_check'],
    );

    expect($definition)->not->toBeNull();

    preg_match_all("/'([a-z_]+)'/", (string) $definition->def, $matches);

    expect(array_values(array_unique($matches[1])))
        ->toEqualCanonicalizing(AssignableRoles::values());
});

it('labels every assignable role, with no raw snake_case reaching a picker', function (): void {
    foreach (AssignableRoles::options() as $option) {
        expect($option['label'])->not->toContain('_')
            ->and($option['label'])->not->toBe($option['value']);
    }

    expect(AssignableRoles::options())->toHaveCount(4)
        // Order is the seeder's, and it is load-bearing: the members roster has shipped `admin` first since
        // B2b, so reordering here would silently move which option a keyboard user lands on.
        ->and(AssignableRoles::options()[0]['value'])->toBe('admin');
});

it('fails open on a role the label map has not caught up with', function (): void {
    // The AuditableTypes::label() posture. A role added to the seeder and not here renders un-prettified
    // rather than vanishing from a picker while the CHECK still accepts it — the failure that would be silent.
    expect(AssignableRoles::label('data_steward'))->toBe('Data Steward');
});

it('names single sign-on in a plan refusal instead of the raw entitlement key', function (): void {
    // `default => $key` would render "Your plan doesn't include sso_saml." — the exact defect the
    // advanced_analytics arm was added to prevent. Free to assert, and it fails the moment the arm is
    // dropped in a merge.
    $message = FeatureGateException::forKey('sso_saml')->getMessage();

    expect($message)->toContain('single sign-on')->and($message)->not->toContain('sso_saml');
});
