<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Jobs\ProbeTenantJob;

/**
 * ADR-0007 §D13 — tenant lifecycle inside jobs, driven through a real worker.
 *
 * The first test in this file is a MUTATION GUARD for the sharpest trap in the ADR. §D13 is worded as
 * `status !== TenantStatus::Active`, but `tenants.status` is a stancl virtual-column attribute that is
 * explicitly NOT cast (App\Models\Tenant, App\Enums\TenantStatus) — it is a plain ?string. Comparing
 * it to an enum OBJECT is therefore ALWAYS true, which would release-then-delete every job for every
 * tenant on the platform: a silent, total queue outage that no other test in this suite would notice.
 *
 * Tenant::isActive() exists so that comparison has exactly one definition, shared with
 * Tenant::scopeActive() which §D3's fan-out uses.
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

it('runs normally for an ACTIVE tenant', function (): void {
    // refresh(): `status` comes from a DATABASE default, which Eloquent does not back-fill into the
    // in-memory model after create(). The persisted row is 'active'; the unrefreshed instance is null.
    expect($this->tenant->refresh()->status)->toBe(TenantStatus::Active->value)
        ->and($this->tenant->isActive())->toBeTrue();

    ProbeTenantJob::dispatch($this->tenant->id);
    workOneJob();

    // MUTANT KILLED: writing `$tenant->status !== TenantStatus::Active` (enum, not ->value) in
    // TenantAwareJob's lifecycle guard. Under that bug this job is released instead of run, so
    // `observations` is empty and the jobs row survives — while every other test in this file, which
    // exercises the missing/suspended branches, still passes.
    expect(ProbeTenantJob::$observations)->toHaveCount(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('discards a job for a tenant that no longer exists, without failing it', function (): void {
    $ghost = Tenant::create(['name' => 'Ghost', 'slug' => 'ghost']);
    $ghostId = $ghost->id;

    ProbeTenantJob::dispatch($ghostId);

    // Hard-delete AFTER dispatch — the job is already serialized with the id.
    DB::table('domains')->where('tenant_id', $ghostId)->delete();
    DB::table('tenants')->where('id', $ghostId)->delete();

    workOneJob();

    // delete(), NOT fail(): a job for a hard-deleted tenant is correctly a no-op, and failing it would
    // fill the RLS-free failed_jobs table with noise. Asserting BOTH tables distinguishes the two.
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(ProbeTenantJob::$observations)->toBeEmpty();
});

it('releases rather than runs a job for a SUSPENDED tenant', function (): void {
    DB::table('tenants')->where('id', $this->tenant->id)->update(['status' => TenantStatus::Suspended->value]);

    ProbeTenantJob::dispatch($this->tenant->id);
    workOneJob();

    // Released back with a delay: the row survives, having consumed one attempt, and is not runnable
    // yet. A suspension is usually temporary, so it must not fail — but §D13 also bounds it, which is
    // what the next test covers.
    $row = DB::table('jobs')->first();

    expect($row)->not->toBeNull()
        ->and($row->attempts)->toBe(1)
        ->and($row->available_at)->toBeGreaterThan(now()->getTimestamp())
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(ProbeTenantJob::$observations)->toBeEmpty();
});

it('discards a suspended tenant job once the retry window is exhausted', function (): void {
    DB::table('tenants')->where('id', $this->tenant->id)->update(['status' => TenantStatus::Suspended->value]);

    ProbeTenantJob::dispatch($this->tenant->id);

    // Age the payload past 75% of the retry window. `createdAt` is stamped at dispatch and preserved
    // verbatim across releases, which is exactly why §D13 measures the window from it rather than
    // from an attempt count — attempts are also consumed by fairness deferrals (§D9).
    $row = DB::table('jobs')->first();
    $payload = json_decode($row->payload, true);
    $payload['createdAt'] = now()->subHours(24)->getTimestamp();
    DB::table('jobs')->where('id', $row->id)->update(['payload' => json_encode($payload)]);

    workOneJob();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(ProbeTenantJob::$observations)->toBeEmpty();
});

it('excludes a suspended tenant from the MaintenanceJob fan-out enumeration', function (): void {
    $suspended = Tenant::create(['name' => 'Halted', 'slug' => 'halted']);
    DB::table('tenants')->where('id', $suspended->id)->update(['status' => TenantStatus::Suspended->value]);

    $active = Tenant::query()->active()->pluck('id')->all();

    expect($active)->toContain($this->tenant->id)
        ->and($active)->not->toContain($suspended->id);
});
