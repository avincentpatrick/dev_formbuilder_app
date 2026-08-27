<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\PointRule;
use App\Enums\SubmissionStatus;
use App\Enums\TenantStatus;
use App\Jobs\Gamification\ReplayTenantHistoryJob;
use App\Models\Audit;
use App\Models\PointAward;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Gamification\GamificationBackfill;
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

it('names each workspace on its own job, and starts every one at the beginning of its own ledger', function (): void {
    $second = inboxTenant('northwind');
    TenantContext::applyLocal(null);

    Queue::fake();

    expect(Artisan::call('gamification:backfill'))->toBe(0);

    // ⚠️ THE COUNT ASSERTION IN THE TEST ABOVE CANNOT SEE THE DEFECT THIS ONE EXISTS FOR, AND THAT WAS
    // MEASURED RATHER THAN REASONED. Hoisting the loop variable in `fanOut()` so every child is dispatched
    // with `$targets[0]`'s id leaves this whole file at 8 passed / 19 assertions, exit 0 — while every
    // workspace but the alphabetically-first is left permanently unbackfilled and the operator is told
    // "2 workspace(s) queued". The backfill is a one-shot operator action nobody re-runs, so there is no
    // later pass that repairs it.
    //
    // ⛔ AND `Queue::assertPushed($class, $closure)` IS NOT THE FIX, THOUGH IT READS LIKE ONE — it is what
    // the backlog row prescribed. `QueueFake::assertPushed()` asserts `pushed($job, $callback)->count() > 0`
    // (Laravel 13.18.1, `QueueFake.php:130-134`): AT LEAST ONE MATCH. One closure asking "is this job's
    // tenantId one of the two?" is satisfied by the first of two identical jobs and stays green under
    // precisely the mutation it would have been added to catch. `Queue::pushed()` returns the job objects
    // themselves (`:364-375`, `->pluck('job')`), so the whole set is comparable at once — which pins the
    // multiset in BOTH directions: nothing missing, nothing duplicated, nothing extra, no ordering assumed.
    $expected = collect([$this->tenant, $second])
        ->map(fn (Tenant $tenant): array => [(string) $tenant->getKey(), null, GamificationBackfill::CHUNK])
        ->sortBy(fn (array $payload): string => $payload[0])
        ->values()
        ->all();

    // The middle field is the one a count is furthest from seeing. A non-null `afterAuditId` on a FIRST
    // dispatch skips the membership rules outright — `ReplayTenantHistoryJob` keys them on the cursor being
    // absent — so every workspace on the box would silently lose every `member.joined` award while every
    // count in sight stayed right. The third field pins that the fan-out takes the default chunk rather
    // than naming one, and does it against the constant, so tuning `CHUNK` does not redden this.
    expect(Queue::pushed(ReplayTenantHistoryJob::class)
        ->map(fn (ReplayTenantHistoryJob $job): array => [$job->tenantId, $job->afterAuditId, $job->limit])
        ->sortBy(fn (array $payload): string => $payload[0])
        ->values()
        ->all())
        ->toBe($expected);
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

it('queues the workspace the operator named, and not merely one workspace', function (): void {
    $northwind = inboxTenant('northwind');
    TenantContext::applyLocal(null);

    Queue::fake();

    expect(Artisan::call('gamification:backfill', ['--tenant' => 'northwind']))->toBe(0);

    // ⚠️ A COUNT OF ONE IS SATISFIED BY THE WRONG WORKSPACE, AND THIS ARM HAS A LIVE RESOLVER BEHIND IT
    // RATHER THAN A LOOP — which makes it the likelier mutation of the two, not the lesser.
    // `TenantLocator::find()` accepts an id, a slug OR a domain, so "resolved something" and "resolved what
    // the operator typed" are genuinely different claims and only one of them is the operator's. MEASURED:
    // replacing the resolve with `Tenant::query()->active()->orderBy('slug')->first()` — which returns
    // `acme` here, never `northwind` — leaves this file green at 8 passed.
    //
    // Asserting the whole pushed list rather than one member also keeps the count in this assertion: a
    // second, unnamed workspace queued alongside the right one is a cross-tenant write the operator did
    // not ask for, and it would satisfy any `assertPushed` closure.
    expect(Queue::pushed(ReplayTenantHistoryJob::class)
        ->map(fn (ReplayTenantHistoryJob $job): string => $job->tenantId)
        ->all())
        ->toBe([(string) $northwind->getKey()]);
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
