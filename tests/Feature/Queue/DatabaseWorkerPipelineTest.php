<?php

declare(strict_types=1);

use App\Enums\AttachmentKind;
use App\Enums\QueueName;
use App\Enums\ScanStatus;
use App\Jobs\ScanAttachmentJob;
use App\Models\Attachment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Jobs\ExplodingTenantJob;
use Tests\Support\Jobs\ProbeTenantJob;

/**
 * ADR-0007 `:112` — THE test the ADR calls the only one that can catch a regression in §D2/§D4/§D5.
 *
 * It drives a real `queue:work --once` on the `database` driver, exercising
 * serialize → enqueue → reserve → unserialize → context re-entry. Every other test in this suite runs
 * under QUEUE_CONNECTION=sync, where the job never touches the `jobs` table and never leaves the
 * caller's memory — so none of them exercise any of that.
 *
 * ANTI-VACUITY IS THE POINT OF THIS FILE. A test that merely observes "the side effect happened"
 * would pass identically under `sync`, proving nothing. So each case asserts the row really existed
 * in `jobs` beforehand and really left afterwards, and the context assertions are taken from INSIDE
 * handle() via ProbeTenantJob rather than inferred.
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

/**
 * A staged attachment row, created directly: AttachmentStorageService would also dispatch, and this
 * file wants to control exactly what is on the queue.
 */
function queuePipelineAttachment(): Attachment
{
    return Attachment::create([
        'tenant_id' => test()->tenant->id,
        'attachable_type' => 'form_field',
        'attachable_id' => Str::uuid7()->toString(),
        'kind' => AttachmentKind::SubmissionFile,
        'disk' => 'local',
        'path' => 'tenants/probe/submission_file/202607/probe.png',
        'virus_scan_status' => ScanStatus::Pending,
        'is_encrypted_at_rest' => false,
        'is_pii' => false,
    ]);
}

it('carries a job through the real database queue and re-establishes tenant context inside handle', function (): void {
    ProbeTenantJob::dispatch($this->tenant->id, 'pipeline');

    // ── anti-vacuity: it really is on the queue, unreserved ────────────────────────────────────
    $row = DB::table('jobs')->where('queue', QueueName::Submissions->value)->first();

    expect($row)->not->toBeNull()
        ->and($row->attempts)->toBe(0)
        ->and($row->reserved_at)->toBeNull();

    // Decoded rather than matched against the raw JSON, which escapes every namespace separator.
    $payload = json_decode($row->payload, true);

    // ── §D5: the payload carries scalar ids only, never a serialized Eloquent model ────────────
    // A model-typed property would appear as an Illuminate ModelIdentifier here, and would then be
    // restored by SerializesModels BEFORE handle() runs — under a NULL GUC, so RLS would find zero
    // rows and raise ModelNotFoundException as a PERMANENT failure.
    expect($payload['displayName'])->toBe(ProbeTenantJob::class)
        ->and($payload['data']['command'])->toContain($this->tenant->id)
        ->and($payload['data']['command'])->not->toContain('ModelIdentifier')
        ->and($payload['data']['command'])->not->toContain('App\Models');

    // ── §D7: the retry knobs really reached the payload ────────────────────────────────────────
    // maxTries null is the load-bearing one: it proves TenantAwareJob declares NO $tries, so a
    // fairness deferral (which consumes an attempt) cannot fail the job. retryUntil governs instead.
    // ⚠️ `maxExceptions` here is the LAST guard on TenantAwareJob's default, and it is not redundant with
    // the failure-path test below (I10b). That test's fixture overrides $maxExceptions to 1 to make the
    // failure deterministic, so it is blind to the base class's default moving. ProbeTenantJob does not
    // override it — this line is the only place the 3 is asserted. Do not delete it as duplication.
    expect($payload['maxTries'])->toBeNull()
        ->and($payload['maxExceptions'])->toBe(3)
        ->and($payload['timeout'])->toBe(60)
        ->and($payload['retryUntil'])->toBeInt()
        ->and($payload['deleteWhenMissingModels'])->toBeFalse();

    // The worker swallows exceptions, so an assertion that fails inside handle() would silently mark
    // the job failed. Exceptions::fake() is the only thing that surfaces it.
    Exceptions::fake();

    // Put the process in the state a real `queue:work` is in — no ambient tenant. The §D4 listener
    // saves and restores rather than flushing, so without this the ambient tenant set by beforeEach
    // would legitimately be restored and the "clean afterwards" assertion below would be a tautology.
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    workOneJob();

    Exceptions::assertNothingReported();

    // Reserved AND deleted. A released job would leave the row behind, so this distinguishes
    // "completed" from "deferred" — which the fairness tests rely on too.
    expect(DB::table('jobs')->count())->toBe(0);

    // ── §D2: the context observed INSIDE handle() ─────────────────────────────────────────────
    $seen = ProbeTenantJob::last();

    expect($seen)->not->toBeNull()
        ->and($seen['label'])->toBe('pipeline')
        // The PHP mirror and the database GUC agree — the pair whose divergence IS the §Context 6 defect.
        ->and($seen['static'])->toBe($this->tenant->id)
        ->and($seen['guc'])->toBe($this->tenant->id)
        // Spatie's team id, which the HTTP middleware sets and no job set before H2.
        ->and($seen['team'])->toBe($this->tenant->id)
        // Proves handleForTenant() ran inside DB::transaction — without it applyLocal's
        // transaction-scoped GUC would not have survived to the probe's own query.
        ->and($seen['txLevel'])->toBeGreaterThanOrEqual(1);

    // ── §D4: the worker is clean afterwards ───────────────────────────────────────────────────
    expect(TenantContext::currentTenantId())->toBeNull()
        ->and(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
});

it('makes tenant-scoped rows visible to the job through RLS', function (): void {
    queuePipelineAttachment();

    ProbeTenantJob::dispatch($this->tenant->id);
    workOneJob();

    // A non-zero count proves the GUC was live for a real policy-checked read, not merely that a
    // string was assigned to a static. Zero here is exactly how RLS fails closed.
    expect(ProbeTenantJob::last()['visible'])->toBe(1);
});

it('runs on the submissions queue, not default', function (): void {
    ProbeTenantJob::dispatch($this->tenant->id);

    // §D6: the #[Queue] attribute really routed it. Draining `default` must be a no-op...
    workOneJob(QueueName::FALLBACK);

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(ProbeTenantJob::$observations)->toBeEmpty();

    // ...and draining `submissions` must consume it. Together these make a mis-targeted worker
    // impossible to pass green, which a one-sided assertion would allow.
    workOneJob();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(ProbeTenantJob::$observations)->toHaveCount(1);
});

it('scans a real attachment end to end through the worker', function (): void {
    $attachment = queuePipelineAttachment();

    ScanAttachmentJob::dispatch($attachment->id, $this->tenant->id);
    workOneJob();

    // The worker cleared the statics on the way out, so re-enter before reading.
    enterTenant($this->tenant->id, $this->user->id);

    expect($attachment->refresh()->virus_scan_status)->toBe(ScanStatus::Skipped);
});

it('records a failed job in failed_jobs rather than losing it', function (): void {
    ExplodingTenantJob::dispatch($this->tenant->id);

    workOneJob();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);

    $failed = DB::table('failed_jobs')->first();

    expect($failed->queue)->toBe(QueueName::Submissions->value);

    // I10b: the named-regression check, and it goes FIRST deliberately. When the pre-flight retryUntil
    // check fires instead of handle(), this row carries the FRAMEWORK's exception — and a `expect()->and()`
    // chain throws on its first failure, so ordering the positive assertion ahead of this one would leave
    // this line unreachable in the only scenario it exists for, handing the next reader exactly the opaque
    // substring miss it is meant to replace. Separate statements rather than a chain, so the order is a
    // decision on the page rather than an accident of formatting.
    expect($failed->exception)->not->toContain('MaxAttemptsExceededException');
    expect($failed->exception)->toContain('Deliberate failure from ExplodingTenantJob');
});
