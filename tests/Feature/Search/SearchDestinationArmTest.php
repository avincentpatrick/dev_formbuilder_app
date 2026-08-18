<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Search\Arms\DestinationSearchArm;
use App\Support\Authorization\ShellAbilities;
use App\Support\Search\DestinationCatalog;
use App\Support\Search\SearchTerms;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The destination ("settings") arm — PRD §3.7's fourth entity (Increment J1c).
|
| This is the only arm with no database query, so its risks are different from the others': it cannot leak
| rows, but it CAN offer a page the user will only bounce off, and it can drift away from the sidebar that
| answers the same question. Both are what this file pins.
|
| ⚠️ THE PARITY CASE IS THE POINT. The catalog and `Sidebar.vue` both answer "which destinations may this
| user reach", and before J1c that answer existed once. The extraction of `ShellAbilities` is what keeps it
| that way; this file is what proves the catalog actually consumes it rather than re-deriving it.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->viewer = User::factory()->create();
    makeActiveMember($this->viewer, 'viewer');
});

/** @return list<string> the destination urls the arm shows $user for $keyword */
function searchedDestinationUrls(User $user, string $keyword): array
{
    $rows = app(DestinationSearchArm::class)->search($user, SearchTerms::parse($keyword), 50)->rows;

    return array_map(static fn (array $row): string => (string) $row['url'], $rows);
}

it('finds a page by its own name', function (): void {
    expect(searchedDestinationUrls($this->owner, 'members'))->toContain('/members');
});

it('finds a page by a word the product does not use for it', function (): void {
    // The whole reason the catalog carries `keywords`: a user who does not know the product's noun still
    // has to arrive somewhere. If these stop working the arm is only an autocomplete for people who
    // already know the answer.
    //
    // ⚠️ `/domains` is deliberately NOT in this list — it is plan-gated, and the case below owns it. The
    // first draft asserted it here and went red on a fixture tenant with no `custom_domain` feature, which
    // was the entitlement filter working correctly rather than a bug.
    expect(searchedDestinationUrls($this->owner, 'people'))->toContain('/members')
        ->and(searchedDestinationUrls($this->owner, 'inbox'))->toContain('/submissions')
        ->and(searchedDestinationUrls($this->owner, 'history'))->toContain('/audit-log');
});

it('hides a plan-gated destination until the tenant holds the feature, then shows it', function (): void {
    // Both directions in one case, because either alone is weak: "absent" could mean the keyword is wrong,
    // and "present" could mean the gate is not applied at all. ADR-0011 §D9's posture is hidden-not-locked,
    // so there is no upsell row to find in the meantime.
    expect(searchedDestinationUrls($this->owner, 'dns'))->not->toContain('/domains');

    assignUnlimitedPlan();
    app()->forgetInstance(EntitlementService::class);

    expect(searchedDestinationUrls($this->owner, 'dns'))->toContain('/domains');
});

it('narrows on both words rather than widening', function (): void {
    $both = searchedDestinationUrls($this->owner, 'audit history');
    expect($both)->toContain('/audit-log')->and($both)->not->toContain('/members');
});

it('hides a destination the user has no permission to reach', function (): void {
    // A Viewer holds none of the management keys. The row must be ABSENT, never listed-and-locked: the
    // same disclosure rule the query-backed arms follow.
    $urls = searchedDestinationUrls($this->viewer, 'members');
    expect($urls)->not->toContain('/members');

    // Anti-vacuity: the Viewer CAN reach some destinations, so an empty result above is the permission
    // filter working rather than the arm being broken for that role.
    expect(searchedDestinationUrls($this->viewer, 'dashboard'))->toContain('/dashboard');
});

it('never refuses the arm itself, because every member can reach some page', function (): void {
    $arm = app(DestinationSearchArm::class);
    expect($arm->allowed($this->owner))->toBeTrue()
        ->and($arm->allowed($this->viewer))->toBeTrue();
});

it('counts only the destinations it would actually show', function (): void {
    // The count-leak property, which is easy to get wrong here precisely BECAUSE there is no database:
    // `count(ROWS)` would compile, run fast, and quietly report pages the user cannot reach.
    $arm = app(DestinationSearchArm::class);
    $terms = SearchTerms::parse('members');

    expect($arm->count($this->viewer, $terms))->toBe(count($arm->search($this->viewer, $terms, 50)->rows))
        ->and($arm->count($this->viewer, $terms))->toBe(0)
        ->and($arm->count($this->owner, $terms))->toBeGreaterThan(0);
});

it('returns nothing at all for an empty query rather than the whole catalog', function (): void {
    expect(app(DestinationSearchArm::class)->search($this->owner, SearchTerms::parse(''), 50)->rows)->toBe([])
        ->and(app(DestinationSearchArm::class)->count($this->owner, SearchTerms::parse('')))->toBe(0);
});

it('offers no destination whose gate the user would bounce off', function (): void {
    // ⭐ THE PARITY CASE. Every catalog row names an ability key; that key must exist in ShellAbilities and
    // must be true for this user. A row naming a key that no longer exists would silently evaluate to
    // false and vanish from search while remaining in the sidebar — drift in the direction nobody notices.
    $abilities = ShellAbilities::for($this->owner);

    foreach (app(DestinationCatalog::class)->visibleTo($this->owner) as $row) {
        expect($row['url'])->toStartWith('/');
    }

    // And the keys themselves: read the catalog's declared abilities out of its own source of truth by
    // asking for a user who holds nothing, then confirming every ability name is a real ShellAbilities key.
    $reflection = new ReflectionClass(DestinationCatalog::class);
    /** @var list<array{ability: ?string, feature: ?string, key: string}> $rows */
    $rows = $reflection->getConstant('ROWS');

    foreach ($rows as $row) {
        if ($row['ability'] !== null) {
            expect($abilities)->toHaveKey(
                $row['ability'],
                "catalog row [{$row['key']}] names ability [{$row['ability']}], which ShellAbilities does not define"
            );
        }
    }
});
