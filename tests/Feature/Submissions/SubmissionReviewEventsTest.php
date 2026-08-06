<?php

declare(strict_types=1);

use App\Enums\SubmissionStatus;
use App\Events\SubmissionApproved;
use App\Events\SubmissionReturned;
use App\Exceptions\Submissions\SubmissionReviewException;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Submissions\SubmissionReviewService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * I3 made two of the four review verbs ANNOUNCE. What that has to mean, and what it must not:
 *
 *  - `approve` and `return` raise their event, `start reviewing` and `archive` raise nothing — the two
 *    silences are decisions (claiming a submission is internal queue mechanics; archival is retention), so
 *    they are pinned as assertions rather than left as the absence of a test;
 *  - the event fires AFTER the transaction commits. `assertDispatched` alone cannot tell the difference,
 *    which is why the third test reads `DB::transactionLevel()` from inside the listener.
 */
beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->reviewer = User::factory()->create();
    enterTenant($this->tenant->id, $this->reviewer->id);
    makeActiveMember($this->reviewer, 'admin');

    $this->respondent = User::factory()->create();

    $this->submission = Submission::factory()->create([
        'status' => SubmissionStatus::Submitted,
        'respondent_user_id' => $this->respondent->id,
    ]);

    $this->service = app(SubmissionReviewService::class);
});

it('announces an approval with the reviewer and the respondent', function (): void {
    Event::fake([SubmissionApproved::class]);

    $this->service->approve($this->submission, $this->reviewer);

    Event::assertDispatched(
        SubmissionApproved::class,
        fn (SubmissionApproved $e): bool => $e->submissionId === $this->submission->id
            && $e->validatedByUserId === $this->reviewer->id
            && $e->respondentUserId === $this->respondent->id,
    );
});

it('announces a return, carrying the state it came from', function (): void {
    Event::fake([SubmissionReturned::class]);

    $this->service->markUnderReview($this->submission, $this->reviewer);
    $this->service->returnToRespondent($this->submission, $this->reviewer, 'The ID does not match.');

    Event::assertDispatched(
        SubmissionReturned::class,
        fn (SubmissionReturned $e): bool => $e->previousStatus === SubmissionStatus::UnderReview->value,
    );
});

it('raises the event only after the transaction has committed', function (): void {
    $levelInsideListener = null;

    Event::listen(SubmissionApproved::class, function () use (&$levelInsideListener): void {
        $levelInsideListener = DB::transactionLevel();
    });

    $outerLevel = DB::transactionLevel();

    $this->service->approve($this->submission, $this->reviewer);

    // Under RefreshDatabase the suite already holds one uncommitted transaction, so "post-commit" here means
    // the listener sees the SAME level it started at — not one deeper. This is the assertion that fails if
    // anyone moves `event()` back inside `apply()`'s closure, which `assertDispatched` would happily pass.
    expect($levelInsideListener)->toBe($outerLevel);
});

it('announces nothing for the two verbs that have no audience', function (): void {
    Event::fake([SubmissionApproved::class, SubmissionReturned::class]);

    $this->service->markUnderReview($this->submission, $this->reviewer);
    $this->service->archive($this->submission, $this->reviewer);

    Event::assertNotDispatched(SubmissionApproved::class);
    Event::assertNotDispatched(SubmissionReturned::class);
});

it('announces nothing when the transition is refused', function (): void {
    Event::fake([SubmissionApproved::class]);

    $this->service->approve($this->submission, $this->reviewer);

    // Already approved — the guard throws, the transaction rolls back, and nothing may be announced.
    expect(fn () => $this->service->markUnderReview($this->submission->refresh(), $this->reviewer))
        ->toThrow(SubmissionReviewException::class);

    Event::assertDispatchedTimes(SubmissionApproved::class, 1);
});

it('still writes exactly one audit row per verb after the restructure', function (): void {
    // apply() was rearranged to hang the announcement off the returned row. The audit write is the thing
    // that must NOT have moved — it belongs inside the transaction, atomic with the change it records.
    $this->service->approve($this->submission, $this->reviewer);

    enterTenant($this->tenant->id, $this->reviewer->id);

    expect(DB::table('audits')
        ->where('auditable_type', 'submission')
        ->where('auditable_id', $this->submission->id)
        ->count())->toBe(1);
});
