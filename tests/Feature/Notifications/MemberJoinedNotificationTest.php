<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Enums\PlanTier;
use App\Enums\UsageMetric;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Settings\PlatformSettings;
use App\Services\Settings\TenantSettingRegistry;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| "A member joined" — the Owner finally hears about the open front door (Increment J3a).
|
| Before this, a workspace could turn self-registration ON and have no way of learning that anyone had used
| it. The only trace was an audit row reading `via: self_registration` that nobody was watching, while its
| twin `member_invited` had been announcing the OTHER door since I3.
|
| ⚠️ THE INTERESTING HALF IS THE THREE STATES THAT MUST STAY SILENT. `joinOpenTenant()` returns early on a
| full seat quota and on an already-active member, and `JoinTenantOnRegistration` falls through entirely on
| the central host. None of those is "someone joined", and each is respected by WHERE the `event()` call sits
| rather than by a condition — so a refactor that hoists it breaks them all at once and only these cases
| would say so.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    DB::connection('pgsql_privileged')->table('settings')->whereNull('tenant_id')->delete();
    app(PlatformSettings::class)->forget();
    app(TenantSettingRegistry::class)->forget();

    $this->tenant = inboxTenant();

    // The people who should hear about it, and one who should not.
    enterTenant($this->tenant->id);
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    $this->viewer = User::factory()->create();
    enterTenant($this->tenant->id, $this->viewer->id);
    makeActiveMember($this->viewer, 'viewer');
});

afterEach(function (): void {
    DB::connection('pgsql_privileged')->table('settings')->whereNull('tenant_id')->delete();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** Rows of the given type currently visible in the ambient tenant. */
function joinedRows(): Collection
{
    return Notification::query()->where('type', NotificationType::MemberJoined)->get();
}

it('tells the Owner and the Admin when someone walks in the front door', function (): void {
    $newcomer = User::factory()->create(['name' => 'Nadia Newcomer']);

    TenantContext::flush();
    app(TenantMembershipService::class)->joinOpenTenant($this->tenant, $newcomer);

    enterTenant($this->tenant->id, $this->owner->id);
    $rows = joinedRows();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->owner->id, $this->admin->id])->sort()->values()->all());

    // The payload the copy renders from — a name and a role, deliberately no email address (the joiner is a
    // stranger the recipients never named).
    expect($rows->first()->data)->toMatchArray(['name' => 'Nadia Newcomer', 'role' => 'viewer'])
        ->and($rows->first()->data)->not->toHaveKey('email');
});

it('writes the row under the borrowed tenant context, so it is scoped to the right workspace', function (): void {
    // `joinOpenTenant()` is the one membership write in the codebase that runs with NO ambient tenant
    // context — it borrows one inside its own transaction. If the event were raised post-commit, the GUC
    // would already be gone and this strict-RLS INSERT would be REFUSED rather than mis-scoped.
    $newcomer = User::factory()->create();

    TenantContext::flush();
    app(TenantMembershipService::class)->joinOpenTenant($this->tenant, $newcomer);

    enterTenant($this->tenant->id, $this->owner->id);

    expect(joinedRows()->pluck('tenant_id')->unique()->all())->toBe([$this->tenant->id]);
});

it('tells nobody who cannot act on it', function (): void {
    $newcomer = User::factory()->create();

    TenantContext::flush();
    app(TenantMembershipService::class)->joinOpenTenant($this->tenant, $newcomer);

    enterTenant($this->tenant->id, $this->owner->id);

    // The Viewer holds no org-wide role; the joiner is excluded by `$exceptUserId` and by not holding one
    // either. Both are asserted, because "nobody else" is the claim.
    $told = joinedRows()->pluck('user_id')->all();

    expect($told)->not->toContain($this->viewer->id)
        ->and($told)->not->toContain($newcomer->id);
});

it('says nothing when the workspace is FULL, because nobody joined', function (): void {
    // The seat quota refuses, `joinOpenTenant()` returns null, and the person is left in exactly the state a
    // central-host registration produces. Announcing an arrival that did not happen would be worse than
    // silence — which is what the service's own docblock already calls the correct outcome.
    //
    // Capped at THREE, the number of seats the beforeEach already occupies (owner + admin + viewer), so the
    // very next join is the one that is refused. `SeatQuotaEnforcementTest::h5bCapSeats()` is the pattern.
    $plan = Plan::factory()->tier(PlanTier::Free)
        ->withQuotas([UsageMetric::ActiveSeats->value => 3])
        ->create();
    Subscription::factory()->forPlan($plan)->create();
    app(EntitlementService::class)->forget();

    $newcomer = User::factory()->create();

    TenantContext::flush();
    $result = app(TenantMembershipService::class)->joinOpenTenant($this->tenant, $newcomer);

    enterTenant($this->tenant->id, $this->owner->id);

    expect($result)->toBeNull()
        ->and(joinedRows())->toHaveCount(0);
});

it('says nothing when the person is ALREADY an active member', function (): void {
    // They registered twice. Nothing changed, so nothing is announced — the early return sits above the
    // emission point, which is the whole mechanism.
    TenantContext::flush();
    $result = app(TenantMembershipService::class)->joinOpenTenant($this->tenant, $this->viewer);

    enterTenant($this->tenant->id, $this->owner->id);

    expect($result?->getKey())->not->toBeNull()
        ->and(joinedRows())->toHaveCount(0);
});

it('says nothing for a CENTRAL-host registration, which joins no workspace at all', function (): void {
    // `JoinTenantOnRegistration` resolves the tenant from `request()->getHost()` and falls through when
    // there is none, so a central-host account belongs to nobody and there is no Owner to tell.
    // `registration.open_signup` DEFAULTS to true and the beforeEach clears every platform settings row, so
    // the central host is reachable without writing one.
    fakeHibp();

    $this->post('http://meridian.test/register', [
        'name' => 'Central Person',
        'email' => 'central@joinedtest.local',
        'password' => 'Correct-Horse-Battery-9',
        'password_confirmation' => 'Correct-Horse-Battery-9',
    ]);

    enterTenant($this->tenant->id, $this->owner->id);

    expect(joinedRows())->toHaveCount(0);
});

it('rolls the notification back TOGETHER WITH the membership when the transaction fails', function (): void {
    // What raising the event inside the transaction buys: no phantom bell row for a membership that was
    // never written. Driven by making the audit write — the statement immediately before the event — throw.
    //
    // ⚠️ BOTH HALVES ARE ASSERTED, AND A FIRST DRAFT ASSERTED ONLY THE FIRST. "No notification after an
    // exception" is true however the event is placed — if it were post-commit it would never fire at all —
    // so on its own it proved nothing about atomicity and nothing about placement. Asserting that the
    // MEMBERSHIP is equally absent is what makes this a rollback test rather than a restatement of "the
    // call threw". Placement itself is carried by the two cases above, which need the borrowed GUC to be
    // live for their rows to exist at all.
    $newcomer = User::factory()->create();

    TenantContext::flush();

    DB::listen(function ($query) {
        if (str_contains($query->sql, 'insert into "audits"')) {
            throw new RuntimeException('audit write refused');
        }
    });

    try {
        app(TenantMembershipService::class)->joinOpenTenant($this->tenant, $newcomer);
    } catch (RuntimeException) {
        // expected
    }

    enterTenant($this->tenant->id, $this->owner->id);

    expect(joinedRows())->toHaveCount(0)
        ->and(TenantUser::query()->where('user_id', $newcomer->id)->exists())->toBeFalse();
});
