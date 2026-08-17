<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\PointRule;
use App\Enums\SubmissionStatus;
use App\Enums\TenantStatus;
use App\Jobs\Gamification\ReplayTenantHistoryJob;
use App\Models\Audit;
use App\Models\PointAward;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| K1c — the operator command and the per-tenant job it fans out.
|
| WHAT CLASS OF BUG THIS FILE EXISTS TO CATCH:
|
|  1. THE CHAIN THAT STOPS AT ONE PAGE, OR NEVER STOPS. The job takes one chunk and re-dispatches itself,
|     which is what keeps a long-lived workspace from losing a whole transaction to the base class's 60s
|     timeout. Both failure modes are silent at the default chunk size, which is why the size is on the
|     payload at all.
|  2. THE DRY RUN THAT WROTE. It is the real replay inside a rolled-back transaction — deliberately not a
|     second counting query — so the one thing that must be proven is that nothing survives it.
|  3. THE SUSPENDED OR DISABLED WORKSPACE SILENTLY REPORTED AS DONE. Both are named in the output rather
|     than omitted from it: a workspace missing from the table reads as one the run never reached.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    assignPlanTier(PlanTier::Free);
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

/** A back-dated audit row in the workspace the test is currently inside. */
function commandAudit(string $type, string $event, string $actorId, Carbon $at): Audit
{
    /** @var Audit $audit */
    $audit = Audit::query()->forceCreate([
        'auditable_type' => $type,
        'auditable_id' => (string) Str::uuid7(),
        'event' => $event,
        'old_values' => null,
        'new_values' => null,
        'redacted_fields' => null,
        'user_id' => $actorId,
        'acting_as_user_id' => null,
        'is_system_action' => false,
        'ip_address' => null,
        'user_agent' => null,
        'created_at' => $at,
    ]);

    return $audit;
}

it('queues one job per active workspace and writes nothing itself', function (): void {
    $second = inboxTenant('northwind');
    TenantContext::applyLocal(null);

    Queue::fake();

    expect(Artisan::call('gamification:backfill'))->toBe(0);

    Queue::assertPushed(ReplayTenantHistoryJob::class, 2);
    // The command is a dispatcher, not a writer. If it ever replayed inline by default, a box with many
    // workspaces would do all of it in one request-less process with no chunking and no fairness limiter.
    expect(PointAward::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($second->slug)->toBe('northwind');
});

it('never fans out to a suspended workspace', function (): void {
    $this->tenant->update(['status' => TenantStatus::Suspended->value]);
    TenantContext::applyLocal(null);

    Queue::fake();
    Artisan::call('gamification:backfill');

    // Routed through Tenant::scopeActive(), so this and TenantAwareJob's own per-job lifecycle guard
    // cannot drift apart — the guard would defer the job anyway, but queueing it at all is noise.
    Queue::assertNotPushed(ReplayTenantHistoryJob::class);
});

it('targets one named workspace by slug, and refuses an unknown one', function (): void {
    inboxTenant('northwind');
    TenantContext::applyLocal(null);

    Queue::fake();

    expect(Artisan::call('gamification:backfill', ['--tenant' => 'northwind']))->toBe(0);
    Queue::assertPushed(ReplayTenantHistoryJob::class, 1);

    // A slug that is not a uuid must not reach `find()`, or Postgres raises 22P02 and the operator gets a
    // stack trace instead of a sentence. TenantLocator's guard is what prevents it.
    expect(Artisan::call('gamification:backfill', ['--tenant' => 'no-such-workspace']))->toBe(1);
    Queue::assertPushed(ReplayTenantHistoryJob::class, 1);
});

it('replays for real with --sync', function (): void {
    commandAudit('form', 'created', (string) $this->owner->id, Carbon::now()->subMonths(2));
    TenantContext::applyLocal(null);

    expect(Artisan::call('gamification:backfill', ['--sync' => true]))->toBe(0);

    enterTenant($this->tenant->id, $this->owner->id);

    expect(PointAward::query()->where('rule', PointRule::FormCreated->value)->count())->toBe(1);
});

it('writes absolutely nothing on a dry run, having replayed the whole thing', function (): void {
    commandAudit('form', 'created', (string) $this->owner->id, Carbon::now()->subMonths(2));
    TenantContext::applyLocal(null);

    expect(Artisan::call('gamification:backfill', ['--dry-run' => true]))->toBe(0);

    enterTenant($this->tenant->id, $this->owner->id);

    // ⚠️ THE MEMBERSHIP AWARD IS THE STRONGER HALF OF THIS ASSERTION. It needs no audit row at all, so it
    // is created on every run of every workspace — if the rollback leaked, this is the row that would be
    // left behind even on a tenant with an empty ledger.
    expect(PointAward::query()->count())->toBe(0);

    Artisan::call('gamification:backfill', ['--sync' => true]);

    // Re-enter: the command clears the context mirror on its way out of every workspace, so a read on the
    // next line would be unscoped and silently return nothing — the same re-entry `UsageRollupTest` does
    // after a worker, and a good demonstration that the cleanup is real.
    enterTenant($this->tenant->id, $this->owner->id);

    expect(PointAward::query()->where('rule', PointRule::MemberJoined->value)->count())->toBe(1);
});

it('names a workspace whose module is switched off instead of leaving it out', function (): void {
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.gamification' => false], $this->owner);
    app(TenantSettingRegistry::class)->forget();
    app(EntitlementService::class)->forget($this->tenant->id);
    TenantContext::applyLocal(null);

    Artisan::call('gamification:backfill', ['--sync' => true]);

    expect(Artisan::output())->toContain('module off');

    enterTenant($this->tenant->id, $this->owner->id);
    expect(PointAward::query()->count())->toBe(0);
});

it('chains its own cursor until the ledger is exhausted', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);

    foreach (range(1, 4) as $i) {
        $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => "P{$i}"]);
        Audit::query()->forceCreate([
            'auditable_type' => 'submission',
            'auditable_id' => (string) $submission->getKey(),
            'event' => 'created',
            'old_values' => null,
            'new_values' => null,
            'redacted_fields' => null,
            'user_id' => (string) $this->owner->id,
            'acting_as_user_id' => null,
            'is_system_action' => false,
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => Carbon::now()->subDays(10 - $i),
        ]);
    }

    TenantContext::applyLocal(null);

    // QUEUE_CONNECTION is `sync` here, so the re-dispatch runs inline and the whole chain completes in this
    // call — one nested savepoint per page. A chunk of one means six pages for six rows plus the short
    // final page: a cursor that failed to advance would hang, and one that never re-dispatched would leave
    // five of the six awards unwritten.
    ReplayTenantHistoryJob::dispatch((string) $this->tenant->id, null, 1);

    enterTenant($this->tenant->id, $this->owner->id);

    expect(PointAward::query()->where('rule', PointRule::SubmissionCollected->value)->count())->toBe(4)
        ->and(PointAward::query()->where('rule', PointRule::MemberJoined->value)->count())->toBe(1);
});

it('does the membership rules once, on the first chunk only', function (): void {
    // Resuming from a cursor must not re-walk `tenant_users` — harmless for the ledger, which is
    // ON CONFLICT DO NOTHING, but it would inflate every resumed chunk's report by the member count and
    // make the numbers an operator reads meaningless on a long tenant.
    TenantContext::applyLocal(null);

    ReplayTenantHistoryJob::dispatch((string) $this->tenant->id, '00000000-0000-7000-8000-000000000000');

    enterTenant($this->tenant->id, $this->owner->id);

    expect(PointAward::query()->where('rule', PointRule::MemberJoined->value)->count())->toBe(0);
});
