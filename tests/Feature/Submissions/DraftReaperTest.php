<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Jobs\Maintenance\ReapExpiredDraftsJob;
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

/** Run the reaper end to end: sweep (fan-out) then the single acme child. */
function runReaper(): void
{
    ReapExpiredDraftsJob::dispatch();
    workOneJob('scheduled-maintenance'); // the sweep enqueues one child (acme is the only active tenant)
    workOneJob('scheduled-maintenance'); // the child hard-deletes acme's expired drafts
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
