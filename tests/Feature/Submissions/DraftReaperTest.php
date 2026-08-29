<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Jobs\Maintenance\ReapExpiredDraftsJob;
use App\Jobs\Submissions\ReapTenantDraftsJob;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionDraftService;
use App\Services\Submissions\SubmissionPayload;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H9b — the draft-expiry reaper (a cross-tenant MaintenanceJob fan-out).
|--------------------------------------------------------------------------
| ReapExpiredDraftsJob fans out one ReapTenantDraftsJob per active tenant, each HARD-deleting the drafts whose
| draft_expires_at has passed (forceDelete, so the partial-unique index frees the re-used uuid — a soft-delete
| tombstone would keep it reserved). Committing test: the fan-out enqueues on the `database` driver and is
| drained with workOneJob('scheduled-maintenance'), the only path RefreshDatabase's shared PDO lets a worker see.
*/

beforeEach(function (): void {
    TenantContext::flush();
    config()->set('queue.default', 'database');
    $this->seed(RolePermissionSeeder::class);
    $this->tenant = inboxTenant('acme');
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->drafts = app(SubmissionDraftService::class);
});

function reaperForm(Tenant $tenant, User $user): FormVersion
{
    $form = app(FormService::class)->create($tenant, $user, 'Resumable');
    addFormField($form->draftVersion, $user, 'full_name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
    app(PublishService::class)->publish($form->refresh(), $user);

    return FormVersion::findOrFail($form->refresh()->current_published_version_id);
}

/** Save a draft with the given uuid + answers against the version. */
function saveReaperDraft(SubmissionDraftService $drafts, FormVersion $version, string $uuid, array $answers = ['full_name' => 'Ada']): Submission
{
    return $drafts->saveDraft(new SubmissionPayload(
        version: $version,
        answers: $answers,
        source: SubmissionSource::Guest,
        clientSubmissionUuid: $uuid,
    ))->submission;
}

/**
 * Run the reaper end to end: the fan-out, then ONE CHILD PER ACTIVE TENANT.
 *
 * ⚠️ THIS WAS TWO HARD-CODED `workOneJob()` CALLS UNTIL M44, AND THAT IS WHY NOTHING HERE COULD SEE A WRONG
 * TENANT ID. With a second tenant the parent enqueues two children and the second was simply never worked, so
 * every downstream assertion read identically — a new green test that still cannot see the defect. The loop is
 * what makes a two-tenant case possible at all.
 *
 * ⚠️ `$activeTenants` IS ASSERTED, NEVER INFERRED — the {@see runRefreshSweep()} rationale (M6). Draining
 * "however many jobs happen to be queued" keeps passing when the sweep dispatches NONE, and that is not
 * hypothetical here: M44 measured a deliberately dead `sweep()` against this suite's webhook sibling and TWO of
 * its three cases stayed green. The count line is what turns that into a red.
 *
 * The bound is the asserted count, so this cannot hang CI even if a child re-queues itself.
 *
 * ⚠️ AND `failed_jobs` IS CHECKED BECAUSE THE WORKER SWALLOWS EXCEPTIONS ({@see workOneJob()}'s docblock). A
 * child that died under the SECOND tenant leaves no trace and reads as "the sweep did nothing", hundreds of
 * lines from the cause.
 */
function runReaper(int $activeTenants = 1): void
{
    ReapExpiredDraftsJob::dispatch();
    workOneJob('scheduled-maintenance'); // the fan-out enqueues one child per active tenant

    expect(DB::table('jobs')->where('queue', 'scheduled-maintenance')->count())->toBe($activeTenants);

    for ($i = 0; $i < $activeTenants; $i++) {
        workOneJob('scheduled-maintenance'); // each child hard-deletes ITS OWN tenant's expired drafts
    }

    expect(DB::table('failed_jobs')->count())->toBe(0);
}

it('hard-deletes an expired draft, leaving fresh drafts and finalized submissions untouched', function (): void {
    $version = reaperForm($this->tenant, $this->user);

    $expired = saveReaperDraft($this->drafts, $version, Uuid::uuid7()->toString());
    Submission::query()->whereKey($expired->id)->update(['draft_expires_at' => now()->subDay()]); // back-date past expiry

    $fresh = saveReaperDraft($this->drafts, $version, Uuid::uuid7()->toString()); // stamped now()+30d
    $promoted = saveReaperDraft($this->drafts, $version, Uuid::uuid7()->toString());
    $this->drafts->promote($promoted); // → submitted, draft_expires_at nulled

    runReaper();

    enterTenant($this->tenant->id, $this->user->id); // context cleared by the worker; re-enter to read

    // The expired draft is HARD-gone (not merely soft-deleted) and its answer doc cascaded away.
    expect(Submission::withTrashed()->whereKey($expired->id)->exists())->toBeFalse()
        ->and(SubmissionAnswer::query()->where('submission_id', $expired->id)->exists())->toBeFalse();

    // The fresh draft and the finalized submission survive.
    expect(Submission::query()->whereKey($fresh->id)->value('status'))->toBe(SubmissionStatus::Draft)
        ->and(Submission::query()->whereKey($promoted->id)->value('status'))->toBe(SubmissionStatus::Submitted);
});

it('frees the reaped draft\'s uuid for re-use (proves hard-delete, not soft-delete)', function (): void {
    $version = reaperForm($this->tenant, $this->user);
    $uuid = Uuid::uuid7()->toString();

    $expired = saveReaperDraft($this->drafts, $version, $uuid);
    Submission::query()->whereKey($expired->id)->update(['draft_expires_at' => now()->subDay()]);

    runReaper();

    enterTenant($this->tenant->id, $this->user->id);

    // A soft-delete tombstone would keep the partial-unique index slot for this uuid → this save would 23505.
    // A hard delete freed it, so the same uuid creates a brand-new draft (created: true, not an update fold).
    $result = $this->drafts->saveDraft(new SubmissionPayload(
        version: $version,
        answers: ['full_name' => 'Ada'],
        source: SubmissionSource::Guest,
        clientSubmissionUuid: $uuid,
    ));
    expect($result->created)->toBeTrue()
        ->and(Submission::query()->where('client_submission_uuid', $uuid)->count())->toBe(1);
});

// ── M44 — the fan-out's IDENTITY, which a one-tenant fixture structurally cannot assert ───────────────────
//
// The production loop is correct at all four sweep sites; what was missing was any fixture wide enough to
// notice if it stopped being. Both cases below go red on a hoisted loop variable, and they fail differently
// on purpose: the first names the child class (which appeared NOWHERE under tests/ before M44) and asserts
// the dispatched multiset; the second drives the real queue end to end and asserts the per-tenant effect.

it('fans out one child per active tenant and holds no tenant context itself', function (): void {
    Bus::fake([ReapTenantDraftsJob::class]);

    $other = inboxTenant('other');

    TenantContext::flush();
    (new ReapExpiredDraftsJob)->handle();

    Bus::assertDispatchedTimes(ReapTenantDraftsJob::class, 2);

    // ⚠️ Compared as a whole SET, never through Bus::assertDispatched($class, $closure): that is an
    // AT-LEAST-ONE-MATCH predicate, satisfied by the first of two identical jobs, so it cannot tell
    // "one child per tenant" from "the first tenant's id, twice" — which is exactly the defect.
    // Bus::dispatched() returns the commands themselves, so the multiset pins both directions at once:
    // nothing missing, nothing duplicated, nothing extra, no ordering assumed.
    $expected = collect([$this->tenant, $other])
        ->map(fn (Tenant $tenant): string => (string) $tenant->getKey())
        ->sort()
        ->values()
        ->all();

    expect(Bus::dispatched(ReapTenantDraftsJob::class)
        ->map(fn (ReapTenantDraftsJob $job): string => $job->tenantId)
        ->sort()
        ->values()
        ->all())
        ->toBe($expected);

    // A sweep is cross-tenant by definition, so a context left behind here would be inherited by whatever
    // ran next on the worker. Never asserted in this file before M44.
    expect(TenantContext::currentTenantId())->toBeNull();
});

it('reaps each active tenant\'s own expired draft, not the first tenant\'s twice', function (): void {
    $acmeVersion = reaperForm($this->tenant, $this->user);
    $acmeExpired = saveReaperDraft($this->drafts, $acmeVersion, Uuid::uuid7()->toString());
    Submission::query()->whereKey($acmeExpired->id)->update(['draft_expires_at' => now()->subDay()]);
    $acmeFresh = saveReaperDraft($this->drafts, $acmeVersion, Uuid::uuid7()->toString());

    // ⚠️ THE SECOND TENANT IS CREATED INSIDE THIS CASE, NOT IN beforeEach, AND THAT IS LOAD-BEARING.
    // Widening the shared fixture would leave every existing single-tenant case draining only the first of
    // two children — and, because lazyById() enumerates by UUIDv7 (creation order), always acme's. They
    // would all stay GREEN while quietly measuring nothing: coverage deleted rather than extended.
    $other = inboxTenant('other');
    $otherUser = User::factory()->create();
    enterTenant($other->id, $otherUser->id);
    $otherVersion = reaperForm($other, $otherUser);
    $otherExpired = saveReaperDraft($this->drafts, $otherVersion, Uuid::uuid7()->toString());
    Submission::query()->whereKey($otherExpired->id)->update(['draft_expires_at' => now()->subDay()]);

    enterTenant($this->tenant->id, $this->user->id);

    runReaper(activeTenants: 2);

    enterTenant($this->tenant->id, $this->user->id); // context cleared by the worker; re-enter to read
    expect(Submission::withTrashed()->whereKey($acmeExpired->id)->exists())->toBeFalse()
        ->and(Submission::query()->whereKey($acmeFresh->id)->exists())->toBeTrue();

    // ⛔ THE ASSERTION A ONE-TENANT FIXTURE CANNOT MAKE. Hoist the loop variable and both children carry
    // acme's id: every acme expectation above still passes, acme is simply reaped twice, and THIS row
    // survives. In production that is every tenant but the first, hourly, with no failed job to trace.
    enterTenant($other->id, $otherUser->id);
    expect(Submission::withTrashed()->whereKey($otherExpired->id)->exists())->toBeFalse();
});
