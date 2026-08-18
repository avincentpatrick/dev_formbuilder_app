<?php

declare(strict_types=1);

use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Exceptions\Submissions\FormNotAcceptingSubmissionException;
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
| Increment H12a — the max_responses cap (a transactional COUNT-under-RLS, not a running aggregate).
|--------------------------------------------------------------------------
| Enforced at EVERY finalize (submit + promote) inside the persist transaction, after the head row exists, so
| the live COUNT includes it (threshold count > max). A refusal rolls the whole transaction back. Drafts are
| NOT capacity-gated at create — the authoritative gate is at promote. The COUNT is form-scoped, so one form's
| volume never consumes another's cap.
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

function capPayload(FormVersion $version, ?string $uuid = null): SubmissionPayload
{
    return new SubmissionPayload(
        version: $version,
        answers: ['full_name' => 'Ada'],
        source: SubmissionSource::Guest,
        clientSubmissionUuid: $uuid ?? Uuid::uuid7()->toString(),
    );
}

/**
 * This file's own oracle, and it must be the SAME predicate the guard enforces — hence the scope rather than
 * a hand-spelled copy. It used to be a seventh duplicate of `status <> 'draft'`.
 *
 * To be precise about what the change did and did not buy, since the honest version is less flattering than
 * the obvious one: no case in THIS file seeds a `screened_out` row, so both spellings agree here today and
 * the repoint is behaviourally a no-op. Its value is structural — the oracle can no longer drift from the
 * guard the day someone adds such a case, which is the failure mode a hand-copied predicate in a test file
 * is built for. The cases that actually exercise the narrowed predicate live in `ScreenedOutTest`.
 */
function capacityCount(string $formId): int
{
    return Submission::query()
        ->where('form_id', $formId)
        ->consumesCapacity()
        ->count();
}

it('accepts up to the cap and refuses the next submission, rolling it back', function (): void {
    $version = scheduledForm($this->tenant, $this->user, ['max_responses' => 2]);

    $this->pipeline->submit(capPayload($version));
    $this->pipeline->submit(capPayload($version));

    try {
        $this->pipeline->submit(capPayload($version));
        $this->fail('expected a capacity refusal');
    } catch (FormNotAcceptingSubmissionException $e) {
        expect($e->code())->toBe('max_responses_reached')
            ->and($e->details())->toMatchArray(['max_responses' => 2, 'current_count' => 3]);
    }

    // The rejected submit rolled back — exactly the cap remains finalized.
    expect(capacityCount($version->form_id))->toBe(2);
});

it('refuses a promote that would exceed the cap, leaving the draft resumable', function (): void {
    $version = scheduledForm($this->tenant, $this->user, ['max_responses' => 1]);

    $this->pipeline->submit(capPayload($version)); // fills the single slot

    // A draft can still be SAVED (capacity is not gated at draft-create)...
    $uuid = Uuid::uuid7()->toString();
    $draft = $this->drafts->saveDraft(capPayload($version, $uuid))->submission;

    // ...but promoting it would be the 2nd finalized row, over the cap of 1.
    expect(fn () => $this->drafts->promote(Submission::findOrFail($draft->id)))
        ->toThrow(FormNotAcceptingSubmissionException::class, 'response limit');

    expect(Submission::findOrFail($draft->id)->status)->toBe(SubmissionStatus::Draft)
        ->and(capacityCount($version->form_id))->toBe(1);
});

it('never caps an uncapped form', function (): void {
    $version = scheduledForm($this->tenant, $this->user); // max_responses null

    foreach (range(1, 5) as $i) {
        $this->pipeline->submit(capPayload($version));
    }

    expect(capacityCount($version->form_id))->toBe(5);
});

it('scopes the cap COUNT to the form, not the tenant', function (): void {
    $capped = scheduledForm($this->tenant, $this->user, ['max_responses' => 1], 'Capped');
    $other = scheduledForm($this->tenant, $this->user, [], 'Uncapped');

    // Load the OTHER form heavily — none of it must count toward the capped form.
    foreach (range(1, 3) as $i) {
        $this->pipeline->submit(capPayload($other));
    }

    $this->pipeline->submit(capPayload($capped)); // the capped form's single slot — must succeed

    expect(fn () => $this->pipeline->submit(capPayload($capped))) // the 2nd — refused
        ->toThrow(FormNotAcceptingSubmissionException::class);
    expect(capacityCount($capped->form_id))->toBe(1);
});
