<?php

declare(strict_types=1);

use App\Exceptions\Analytics\InvalidAnalyticsQueryException;
use App\Models\SavedReportView;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Analytics\SavedReportViewService;
use App\Support\Analytics\AnalyticsQuery;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| ADR-0011 §D8 -- saved report views.
|
| This table has an unusual shape and each of its two isolation halves is enforced somewhere different, so
| both need their own test: RLS keeps a view inside its TENANT, and an application predicate keeps it
| private to its USER. §D8 forbids the `belongs_to_user` RLS variant precisely because that variant carries
| NO tenant predicate -- a consultant in two tenants would leak. So the tenant half must be proven to come
| from the database, and the user half must be proven to come from the code.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->service = app(SavedReportViewService::class);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** @return array<string, mixed> */
function definition(): array
{
    return (new AnalyticsQuery(
        from: CarbonImmutable::parse('2026-03-01', 'UTC'),
        to: CarbonImmutable::parse('2026-03-07', 'UTC'),
    ))->toArray();
}

it('saves a declaration and resolves it back into a usable query', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'owner');

    $view = $this->service->create($user, 'Q1 intake', definition());

    // A DECLARATION, not a materialized result -- which is what lets §D8's read-time degradation work.
    expect($view->definition['from'])->toBe('2026-03-01')
        ->and($view->definition['v'])->toBe(AnalyticsQuery::SCHEMA_VERSION);

    $resolved = $this->service->resolve($view->refresh());

    expect($resolved['refused'])->toBeFalse()
        ->and($resolved['query'])->toBeInstanceOf(AnalyticsQuery::class)
        ->and($resolved['query']->from->toDateString())->toBe('2026-03-01');
});

it('refuses a definition that could never resolve, at write time', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'owner');

    // Storing a row that is broken from birth would push the failure to every future read.
    $bad = definition();
    $bad['to'] = '2029-01-01'; // blows the 366-day span

    expect(fn () => $this->service->create($user, 'Too wide', $bad))
        ->toThrow(InvalidAnalyticsQueryException::class);

    expect(SavedReportView::query()->count())->toBe(0);
});

it('degrades a stale definition to a stated refusal rather than throwing', function (): void {
    // §D8's core promise. It returns rather than throws so a LIST of views can render the broken one beside
    // the working ones -- an exception would take the page down for one stale row.
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'owner');

    $view = $this->service->create($user, 'Legacy', definition());

    // Simulate a definition written by a build that knew a setting this one does not.
    $stale = $view->definition;
    $stale['axis'] = 'campaign';
    $view->forceFill(['definition' => $stale])->save();

    $resolved = $this->service->resolve($view->refresh());

    expect($resolved['refused'])->toBeTrue()
        ->and($resolved['query'])->toBeNull()
        ->and($resolved['reason'])->toBe('malformed_definition')
        ->and($resolved['message'])->toContain('campaign');
});

it('turns a duplicate name into a 422 rather than a 500', function (): void {
    // A real race (two tabs, same name), so a check-then-write would still surface a raw QueryException some
    // of the time. The unique constraint is the authority; SQLSTATE 23505 is mapped.
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'owner');

    $this->service->create($user, 'Weekly', definition());

    expect(fn () => $this->service->create($user, 'Weekly', definition()))
        ->toThrow(ValidationException::class);
});

it('lets two DIFFERENT users hold a view of the same name', function (): void {
    // The unique key is (tenant, user, name), not (tenant, name) -- "Weekly" is the obvious name and two
    // colleagues will both pick it.
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    enterTenant($tenant->id, $alice->id);
    makeActiveMember($alice, 'owner');
    makeActiveMember($bob, 'owner');

    $this->service->create($alice, 'Weekly', definition());
    $this->service->create($bob, 'Weekly', definition());

    expect(SavedReportView::query()->count())->toBe(2);
});

it('hides one members view from another in the SAME tenant, via the application predicate', function (): void {
    // Strict RLS scopes to the TENANT, so without scopeOwnedBy() an Owner would list every member's views.
    // This is the half the database does NOT enforce, which is exactly why it needs its own test.
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    enterTenant($tenant->id, $alice->id);
    makeActiveMember($alice, 'owner');
    makeActiveMember($bob, 'owner');

    $this->service->create($alice, 'Alice private', definition());
    $this->service->create($bob, 'Bob private', definition());

    expect($this->service->forUser($alice)->pluck('name')->all())->toBe(['Alice private'])
        ->and($this->service->forUser($bob)->pluck('name')->all())->toBe(['Bob private']);

    // ...and the raw query proves the database itself does NOT scope by user -- so the predicate above is
    // load-bearing rather than belt-and-braces.
    expect(SavedReportView::query()->count())->toBe(2);
});

it('hides a view from another TENANT, via RLS', function (): void {
    // The half §D8 chose `strict` for. Under `belongs_to_user` -- whose policies key on user_id with NO
    // tenant predicate -- a consultant acting for Globex would read their Acme definitions.
    $acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $globex = Tenant::create(['name' => 'Globex', 'slug' => 'globex', 'default_locale' => 'en']);
    $consultant = User::factory()->create();

    enterTenant($acme->id, $consultant->id);
    makeActiveMember($consultant, 'owner');
    $this->service->create($consultant, 'Acme numbers', definition());

    // Same person, same user id, different tenant context.
    enterTenant($globex->id, $consultant->id);
    makeActiveMember($consultant, 'owner');

    expect($this->service->forUser($consultant)->all())->toBe([])
        ->and(SavedReportView::query()->count())->toBe(0);
});

it('gates the policy on the analytics permissions and on ownership', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    enterTenant($tenant->id, $alice->id);
    makeActiveMember($alice, 'owner');
    makeActiveMember($bob, 'owner');

    $mine = $this->service->create($alice, 'Mine', definition());

    // viewAny is also the gate on the analytics report/question routes -- see the policy docblock.
    expect($alice->can('viewAny', SavedReportView::class))->toBeTrue()
        ->and($alice->can('view', $mine))->toBeTrue()
        // Private to its author, INCLUDING from another Owner. An admin override would need its own
        // decision and its own permission, and neither exists.
        ->and($bob->can('view', $mine))->toBeFalse()
        ->and($bob->can('update', $mine))->toBeFalse()
        ->and($bob->can('delete', $mine))->toBeFalse();
});
