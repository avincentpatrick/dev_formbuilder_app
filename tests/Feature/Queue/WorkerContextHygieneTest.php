<?php

declare(strict_types=1);

use App\Enums\AttachmentKind;
use App\Enums\ScanStatus;
use App\Models\Attachment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Jobs\ExplodingTenantJob;
use Tests\Support\Jobs\ProbeMaintenanceJob;
use Tests\Support\Jobs\ProbeTenantJob;

/**
 * ADR-0007 §D4 — the worker-edge context listener, which closes the live defect §Context 6 documents:
 * TenantContext::$tenantId is a process-lifetime static that applyLocal() never rolls back, so on a
 * long-lived worker one job's tenant leaked into the next.
 *
 * EVERY case here is written to kill a specific mutant. Delete the after-edge listener, or bind it to
 * JobProcessed instead of JobAttempted, or replace save/restore with a blind flush, and a named test
 * below fails. That is the requirement ADR-0007's risk table sets for this mechanism ("the listener
 * is registered but silently stops firing" is mitigated only if a test asserts context re-entry
 * rather than mere job completion).
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
    ProbeTenantJob::reset();
    ProbeMaintenanceJob::reset();

    config()->set('queue.default', 'database');

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->other = Tenant::create(['name' => 'Globex', 'slug' => 'globex']);
    $this->user = User::factory()->create();

    enterTenant($this->tenant->id, $this->user->id);
});

/** A tenant-scoped row, so "can this code see tenant data" is an observable question. */
function makeHygieneAttachment(): Attachment
{
    return Attachment::create([
        'tenant_id' => test()->tenant->id,
        'attachable_type' => 'form_field',
        'attachable_id' => Str::uuid7()->toString(),
        'kind' => AttachmentKind::SubmissionFile,
        'disk' => 'local',
        'path' => 'tenants/acme/submission_file/202607/x.bin',
        'virus_scan_status' => ScanStatus::Pending,
        'is_encrypted_at_rest' => false,
        'is_pii' => false,
    ]);
}

/**
 * Put the process into the state a REAL `queue:work` is in: no ambient tenant context at all.
 *
 * This matters because the listener SAVES AND RESTORES rather than flushing (so that an inline sync
 * dispatch cannot corrupt its caller — see the sync test below). In a test, `beforeEach` has
 * established an ambient tenant, so "restore" correctly puts that tenant back and the static is
 * non-null afterwards. On a worker the saved value is null, so restore IS a flush.
 *
 * Clearing first is therefore not a workaround — it is what makes "the job's own context did not
 * survive the job" an observable claim rather than a tautology.
 */
function simulateBareWorker(): void
{
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
}

it('leaves the worker clean after a successful job', function (): void {
    ProbeTenantJob::dispatch($this->tenant->id);
    simulateBareWorker();

    workOneJob();

    // MUTANT KILLED: removing the JobAttempted listener — the job's own tenant would survive here.
    expect(TenantContext::currentTenantId())->toBeNull()
        ->and(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
});

it('leaves the worker clean after a job that THREW', function (): void {
    ExplodingTenantJob::dispatch($this->tenant->id);
    simulateBareWorker();

    workOneJob();

    // MUTANT KILLED: binding the after-edge to JobProcessed — which is what ADR-0007 §D4 literally
    // prescribes. Worker::process raises JobProcessed only on the success path and diverts to
    // handleJobException on a throw, so under that implementation this job's tenant static survives
    // into the next job. JobAttempted fires from the `finally` and does not.
    expect(TenantContext::currentTenantId())->toBeNull()
        ->and(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
});

it('does not let a job free-ride on a predecessor context', function (): void {
    // Contaminate from the TEST BODY, between jobs. This is the only shape that isolates the
    // BEFORE-edge: if the listener only cleaned up afterwards, the previous job would have left
    // things clean and the test would pass for the wrong reason.
    enterTenant($this->other->id, $this->user->id);

    ProbeMaintenanceJob::dispatch();
    workOneJob('scheduled-maintenance');

    $seen = ProbeMaintenanceJob::last();

    // MUTANT KILLED: removing the JobProcessing listener.
    expect($seen)->not->toBeNull()
        ->and($seen['static'])->toBeNull()
        ->and($seen['team'])->toBeNull();
});

it('gives a cross-tenant MaintenanceJob no tenant rows and the full active-tenant list', function (): void {
    makeHygieneAttachment();

    ProbeMaintenanceJob::dispatch();
    workOneJob('scheduled-maintenance');

    $seen = ProbeMaintenanceJob::last();

    // Zero tenant-scoped rows: with no GUC, BelongsToTenant substitutes its NO_TENANT_SENTINEL and
    // RLS fails closed. A non-zero count here means a stale static is being injected — the §Context 6
    // defect, observed from the far side.
    expect($seen['visible'])->toBe(0)
        // ...while `tenants` IS readable, because it is RLS-exempt (§D3).
        ->and($seen['activeTenants'])->toContain($this->tenant->id)
        ->and($seen['activeTenants'])->toContain($this->other->id);
});

it('preserves the caller context across an inline sync dispatch', function (): void {
    // THE SYNC CASE — the one the ADR does not address and that a blind flush would break.
    //
    // Under QUEUE_CONNECTION=sync (phpunit.xml — every other test in this suite) SyncQueue raises
    // JobProcessing/JobAttempted INLINE, inside the caller's own stack, mid-request. So a listener
    // that flushed instead of restoring would wipe the ambient request context and break everything
    // the dispatching code does afterwards — e.g. AttachmentStorageService, which creates the row and
    // then dispatches, with the request continuing after that.
    //
    // Deliberately NOT Queue::fake(): a fake never raises the events, which would make this vacuous.
    config()->set('queue.default', 'sync');

    makeHygieneAttachment();

    $before = TenantContext::currentTenantId();

    ProbeTenantJob::dispatch($this->tenant->id, 'inline');

    // It really did run inline, so the events really did fire in this stack.
    expect(ProbeTenantJob::$observations)->toHaveCount(1);

    // MUTANT KILLED: replacing save/restore with a blind flush. Both the mirror AND a real
    // policy-checked read must still work for the rest of the caller's work. Under a blind flush the
    // mirror is null and the count is 0 (RLS failing closed).
    expect(TenantContext::currentTenantId())->toBe($before)
        ->and(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($this->tenant->id)
        ->and(Attachment::query()->count())->toBe(1);
});

it('balances the context stack across nested sync dispatches', function (): void {
    config()->set('queue.default', 'sync');

    ProbeTenantJob::dispatch($this->tenant->id, 'outer');
    ProbeTenantJob::dispatch($this->tenant->id, 'inner');

    // MUTANT KILLED: a non-static stack (Dispatcher::createClassCallable resolves a FRESH listener
    // per dispatch, so instance state would never survive entering() → leaving(), silently degrading
    // the restore into a flush).
    expect(ProbeTenantJob::$observations)->toHaveCount(2)
        ->and(TenantContext::currentTenantId())->toBe($this->tenant->id);
});
