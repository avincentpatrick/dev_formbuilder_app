<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\SsoAuthRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sso\SsoAuthRequestService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Sso\FakeIdp;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The AuthnRequest store's bounds (P1d, ADR-0016 §D8).
|
| ⚠️ THIS TABLE WAS UNBOUNDED FOR THREE INCREMENTS WHILE TWO DOCUMENTS SAID IT WAS NOT. Its own migration
| promised "a scheduled prune drops rows well past `expires_at`" and no command, job or schedule entry was
| ever written — while `routes/console.php` records that nothing runs the scheduler on the production box,
| so even a written one would have been a bound existing in the repository and not on the machine. The
| endpoint that fills it, `GET /sso/saml/login`, is UNAUTHENTICATED and writes one row per hit.
|
| ⚠️⚠️ THE CASE THIS FILE EXISTS FOR IS `it never evicts a live request`, AND IT IS NOT A CORNER CASE — IT IS
| THE DEFECT J3c2 SHIPPED AND HAD TO FIX. A rank-only cap ported onto a table holding LIVE rows is
| unauthenticated denial of *authentication*: anyone can mint enough rows to push a member's pending sign-in
| past the cap while they sit on their identity provider's login screen, after which their sign-in fails and
| the log blames a replay. So liveness is the OUTER `AND` here, and the case below is built in exactly that
| shape — the victim's row is deliberately NOT among the newest N, which is the only arrangement in which
| the missing predicate is visible.
|
| The honest consequence, asserted rather than assumed: this table is capped at N *dead* rows plus whatever
| the rate limiter admits inside one TTL. A pure grinder's rows are all live for ten minutes and none of
| them are eligible. That is the trade, and it is the right way round — deleting somebody's in-flight
| sign-in is not an acceptable answer to a capacity question.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    assignPlanTier(PlanTier::Enterprise);

    enterTenant($this->tenant->id, $this->admin->id);
    $this->connection = FakeIdp::connection();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** Mint through the service the ACS and the login controller both use. */
function mintRequest(): SsoAuthRequest
{
    return app(SsoAuthRequestService::class)->mint(test()->connection);
}

/** Spend a row so it becomes eligible. `consumed_at` is not fillable, by design. */
function spend(SsoAuthRequest $request): void
{
    SsoAuthRequest::query()->whereKey($request->getKey())->update(['consumed_at' => Carbon::now()]);
}

function requestRows(Tenant $tenant, User $actor)
{
    enterTenant($tenant->id, $actor->id);

    return SsoAuthRequest::query()->get();
}

it('holds at most the configured number of SPENT rows however many arrive', function (): void {
    config(['saml.authn_request_max_rows' => 3]);

    for ($i = 0; $i < 8; $i++) {
        spend(mintRequest());
    }

    // One more mint to run the trim after the eighth row was spent.
    $live = mintRequest();

    enterTenant($this->tenant->id, $this->admin->id);

    // ⚠️ "NOT AMONG THE NEWEST N", NEVER "OLDER THAN THE Nth". All of these land inside one second, so a
    // timestamp comparison would keep every tied row and the bound would fail at exactly the volume it
    // exists for — the `SsoAuthFailureRecorder` lesson, which is where this shape comes from.
    expect(SsoAuthRequest::query()->whereNotNull('consumed_at')->count())->toBe(3)
        // The live row is not counted against the cap and is still here.
        ->and(SsoAuthRequest::query()->whereKey($live->getKey())->exists())->toBeTrue();
});

it('never evicts a live request, however many rows a stranger piles on top of it', function (): void {
    config(['saml.authn_request_max_rows' => 2]);

    // The victim starts a sign-in and goes off to authenticate at their identity provider.
    $victim = mintRequest();

    // A moment passes — enough for later rows to sort above theirs, nowhere near the 600s TTL.
    $this->travel(30)->seconds();

    // The grinder. Every one of these is SPENT, so every one is eligible; the victim's is not, and it is
    // deliberately the OLDEST row in the table, so a cap that ranked without asking about liveness would
    // delete it first. That is precisely the J3c2 defect.
    for ($i = 0; $i < 10; $i++) {
        spend(mintRequest());
    }

    mintRequest();

    enterTenant($this->tenant->id, $this->admin->id);
    $survivor = SsoAuthRequest::query()->whereKey($victim->getKey())->first();

    expect($survivor)->not->toBeNull()
        ->and($survivor->consumed_at)->toBeNull()
        ->and($survivor->isLive())->toBeTrue()
        // ...and the bound still held for everything that was eligible.
        ->and(SsoAuthRequest::query()->whereNotNull('consumed_at')->count())->toBe(2);
});

it('drops spent rows past the retention window even when the cap is nowhere near', function (): void {
    config(['saml.authn_request_max_rows' => 500]);

    spend(mintRequest());

    expect(requestRows($this->tenant, $this->admin))->toHaveCount(1);

    // Data minimisation rather than housekeeping: these rows carry an IP address. Age alone removes them,
    // and the next write is what applies it — there is no scheduler to rely on.
    $this->travel((int) config('saml.authn_request_retention_days') + 1)->days();

    $fresh = mintRequest();

    enterTenant($this->tenant->id, $this->admin->id);

    expect(SsoAuthRequest::query()->pluck('id')->all())->toBe([(string) $fresh->getKey()]);
});

it('fails closed when a pruned request is presented again', function (): void {
    config(['saml.authn_request_max_rows' => 1]);

    $spent = mintRequest();
    spend($spent);

    // Push it out of the cap.
    spend(mintRequest());
    mintRequest();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(SsoAuthRequest::query()->whereKey($spent->getKey())->exists())->toBeFalse();

    // Pruning cannot re-open a replay: `findLive()` requires the row to EXIST and be unconsumed and
    // unexpired, so an absent row is refused exactly as a consumed one is. What is lost is resolution in
    // the log, where this now reads as "never minted" rather than "already used" — a documented cost.
    expect(app(SsoAuthRequestService::class)->findLive($spent->request_id))->toBeNull();
});

it('cannot reach across a tenant boundary', function (): void {
    config(['saml.authn_request_max_rows' => 1]);

    $neighbour = Tenant::create(['name' => 'Globex', 'slug' => 'globex', 'default_locale' => 'en']);
    $neighbour->domains()->create(['domain' => 'globex']);

    $neighbourAdmin = User::factory()->create();
    enterTenant($neighbour->id, $neighbourAdmin->id);
    makeActiveMember($neighbourAdmin, 'admin');
    assignPlanTier(PlanTier::Enterprise);

    enterTenant($neighbour->id, $neighbourAdmin->id);
    $neighbourConnection = FakeIdp::connection();
    $theirs = app(SsoAuthRequestService::class)->mint($neighbourConnection);
    spend($theirs);

    // Acme grinds. RLS is the tenant scoping — the DELETE carries no `tenant_id` predicate precisely
    // because under strict isolation there cannot be a cross-tenant row for it to match.
    enterTenant($this->tenant->id, $this->admin->id);
    for ($i = 0; $i < 5; $i++) {
        spend(mintRequest());
    }
    mintRequest();

    enterTenant($neighbour->id, $neighbourAdmin->id);
    expect(SsoAuthRequest::query()->whereKey($theirs->getKey())->exists())->toBeTrue();
});
