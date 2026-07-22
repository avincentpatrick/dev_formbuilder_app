<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Jobs\ProbeTenantJob;

/**
 * ADR-0007 §D8 — `after_commit => true` on the `database` connection.
 *
 * THE BUG IT FIXES. AttachmentStorageService creates the attachment row and then dispatches
 * ScanAttachmentJob, with no enclosing transaction at that level. Under `after_commit => false` a
 * worker could reserve the job before the row was visible, take the `$attachment === null` early
 * return, and leave `virus_scan_status` stuck at `pending` FOREVER. It has never surfaced because it
 * is structurally untestable under QUEUE_CONNECTION=sync — which was every CI job before H2.
 *
 * WHAT THIS FILE DOES AND DOES NOT PROVE. It proves the ORDERING CONTRACT: a job dispatched inside a
 * transaction is not visible on the queue until that transaction commits, and is never enqueued at
 * all if it rolls back. It does NOT reproduce the two-process race itself — in-process the worker
 * shares the test's PDO handle, so there is no second connection to lose the race against. That gap
 * is stated rather than papered over; reproducing it would need a committing test, of which this
 * suite has no precedent.
 *
 * A NESTED transaction is used deliberately. Pest's RefreshDatabase already holds an outer
 * transaction, and Laravel's testing DatabaseTransactionsManager deliberately ignores that wrapping
 * level — so a dispatch at "top level" inside a test fires IMMEDIATELY and would prove nothing.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
    ProbeTenantJob::reset();

    config()->set('queue.default', 'database');

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->user = User::factory()->create();

    enterTenant($this->tenant->id, $this->user->id);
});

it('holds a dispatch until the enclosing transaction commits', function (): void {
    DB::transaction(function (): void {
        ProbeTenantJob::dispatch($this->tenant->id);

        // MUTANT KILLED: reverting config/queue.php's after_commit to false — the row would already
        // be here, reservable by a worker before the transaction's own writes are visible.
        expect(DB::table('jobs')->count())->toBe(0);
    });

    expect(DB::table('jobs')->count())->toBe(1);
});

it('never enqueues a dispatch from a rolled-back transaction', function (): void {
    try {
        DB::transaction(function (): void {
            ProbeTenantJob::dispatch($this->tenant->id);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    // The work was never committed, so the job describing it must not exist. Under after_commit=false
    // this row would survive its own cause and the job would act on state that was undone.
    expect(DB::table('jobs')->count())->toBe(0);
});

it('dispatches immediately when there is no enclosing transaction', function (): void {
    ProbeTenantJob::dispatch($this->tenant->id);

    // after_commit must not mean "always deferred" — with no transaction to wait for, the job is
    // enqueued at once. (RefreshDatabase's own wrapping transaction is ignored by the testing
    // transactions manager, which is exactly why the cases above need an explicit nested one.)
    expect(DB::table('jobs')->count())->toBe(1);
});

it('still scans an attachment created in the same transaction as its dispatch', function (): void {
    // The end-to-end shape of the §D8 bug: row and dispatch in one transaction. After the commit the
    // job must find the row — i.e. NOT take ScanAttachmentJob's `$attachment === null` early return.
    DB::transaction(function (): void {
        ProbeTenantJob::dispatch($this->tenant->id, 'after-commit');
    });

    workOneJob();

    expect(ProbeTenantJob::last())->not->toBeNull()
        ->and(ProbeTenantJob::last()['label'])->toBe('after-commit');
});
