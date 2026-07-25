<?php

declare(strict_types=1);

use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Exceptions\Submissions\FormNotAcceptingSubmissionException;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\User;
use App\Services\Submissions\SubmissionDraftService;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H12a — scheduled-form acceptance enforcement (opens_at / closes_at).
|--------------------------------------------------------------------------
| Enforcement is LIVE at the write path: FormAcceptanceGuard blocks a fresh submission (or a NEW draft) outside
| the open window, but the draft-vs-close policy is a GRACE WINDOW — a draft STARTED before the form closed may
| still be promoted afterwards (bounded by its own draft_expires_at). The headline test proves exactly that.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->seed(RolePermissionSeeder::class);
    $this->tenant = inboxTenant('acme');
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->pipeline = app(SubmissionPipeline::class);
    $this->drafts = app(SubmissionDraftService::class);
});

function acceptPayload(FormVersion $version, ?string $uuid = null, string $name = 'Ada'): SubmissionPayload
{
    return new SubmissionPayload(
        version: $version,
        answers: ['full_name' => $name],
        source: SubmissionSource::Guest,
        clientSubmissionUuid: $uuid ?? Uuid::uuid7()->toString(),
    );
}

it('HEADLINE: a draft created BEFORE close can still be promoted AFTER close (grace window)', function (): void {
    $version = scheduledForm($this->tenant, $this->user); // open when the draft is saved
    $uuid = Uuid::uuid7()->toString();
    $draft = $this->drafts->saveDraft(acceptPayload($version, $uuid))->submission;

    // The draft was started; the form then closes at a time AFTER the draft's creation.
    Submission::query()->whereKey($draft->id)->update(['created_at' => now()->subDays(2)]);
    Form::query()->whereKey($version->form_id)->update(['closes_at' => now()->subDay()]);

    $result = $this->drafts->promote(Submission::findOrFail($draft->id));

    expect($result->created)->toBeTrue()
        ->and($result->submission->status)->toBe(SubmissionStatus::Submitted);
});

it('refuses a fresh submission before opens_at (form_not_open)', function (): void {
    $version = scheduledForm($this->tenant, $this->user, ['opens_at' => now()->addDay(), 'schedule_state' => 'scheduled']);

    expect(fn () => $this->pipeline->submit(acceptPayload($version)))
        ->toThrow(FormNotAcceptingSubmissionException::class, 'not open');
});

it('refuses a fresh submission after closes_at with a 403 form_closed envelope', function (): void {
    $version = scheduledForm($this->tenant, $this->user, ['closes_at' => now()->subDay(), 'schedule_state' => 'closed']);

    try {
        $this->pipeline->submit(acceptPayload($version));
        $this->fail('expected a scheduled-form refusal');
    } catch (FormNotAcceptingSubmissionException $e) {
        expect($e->code())->toBe('form_closed')
            ->and($e->status())->toBe(403)
            ->and($e->details())->toHaveKey('closes_at');
    }
});

it('refuses starting a NEW draft on a closed form', function (): void {
    $version = scheduledForm($this->tenant, $this->user, ['closes_at' => now()->subDay(), 'schedule_state' => 'closed']);

    expect(fn () => $this->drafts->saveDraft(acceptPayload($version)))
        ->toThrow(FormNotAcceptingSubmissionException::class, 'closed');
});

it('still lets an EXISTING draft keep autosaving after close (grace)', function (): void {
    $version = scheduledForm($this->tenant, $this->user); // open
    $uuid = Uuid::uuid7()->toString();
    $this->drafts->saveDraft(acceptPayload($version, $uuid, 'Ada'));

    Form::query()->whereKey($version->form_id)->update(['closes_at' => now()->subDay()]);

    // Same uuid → the update branch, which runs no start guard.
    $result = $this->drafts->saveDraft(acceptPayload($version, $uuid, 'Ada Lovelace'));

    expect($result->submission->status)->toBe(SubmissionStatus::Draft)
        ->and(Submission::query()->where('client_submission_uuid', $uuid)->count())->toBe(1);
});

it('refuses promoting a draft that was created AT/AFTER a retroactive close (defensive)', function (): void {
    $version = scheduledForm($this->tenant, $this->user); // open
    $draft = $this->drafts->saveDraft(acceptPayload($version, Uuid::uuid7()->toString()))->submission;

    // Retroactively close the form to BEFORE the draft's creation — the draft is not grace-eligible.
    Form::query()->whereKey($version->form_id)->update(['closes_at' => now()->subDay()]);

    expect(fn () => $this->drafts->promote(Submission::findOrFail($draft->id)))
        ->toThrow(FormNotAcceptingSubmissionException::class);
    expect(Submission::findOrFail($draft->id)->status)->toBe(SubmissionStatus::Draft);
});

it('is inert for an unscheduled form', function (): void {
    $version = scheduledForm($this->tenant, $this->user); // no opens_at/closes_at

    $result = $this->pipeline->submit(acceptPayload($version));

    expect($result->created)->toBeTrue()
        ->and($result->submission->status)->toBe(SubmissionStatus::Submitted);
});
