<?php

declare(strict_types=1);

use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\Search\Arms\SubmissionSearchArm;
use App\Support\Search\SearchTerms;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M146 (`R-cf423290`) — a promoted draft sits where it was SENT, not where it was started.
|--------------------------------------------------------------------------
| `SubmissionDraftService::promote()` finalizes the same row, whose uuidv7 id was minted at the first draft save —
| so an order by id put a response resumed from a week-old draft a week down the list while its Submitted column
| said today. Every list that says "newest first" now orders through `Submission::scopeOrderBySubmitted()`:
| `submitted_at`, then `id` as the tie-break for the same second (`submitted_at` is stored to the second).
| No new index: the two partial indexes on `(…, submitted_at) WHERE status <> 'draft'` already serve it.
| The export (ascending by id) is filed, not changed here: its file belongs to another row of this batch (`D13`).
|
| ⚠️ Helpers are prefixed `promotedOrder*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    $this->form = publishedInboxForm($this->tenant, $this->owner, 'Promoted intake');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * Two finished responses. A was sent in one step at 10:00. B was STARTED as a draft an hour earlier — its row, and
 * so its id, is older than A's — and promoted a minute after A. The promotion is the write
 * `SubmissionDraftService::promote()` makes on the same row (status, `submitted_at`, the expiry cleared); the real
 * service wants a saved answer document and a payload, which an ordering question does not need.
 *
 * @return array{0: Submission, 1: Submission} [A, B]
 */
function promotedOrderPair(Form $form): array
{
    $t0 = CarbonImmutable::parse('2026-08-01 10:00:00');
    $b = seedCountableAt($form, $t0->subHour(), SubmissionStatus::Draft);
    $a = seedCountableAt($form, $t0);
    $b->forceFill(['status' => SubmissionStatus::Submitted, 'submitted_at' => $t0->addMinute(), 'draft_expires_at' => null])->save();

    // Anti-vacuity: the defect only shows when the promoted row's id sorts BEFORE the one-step row's.
    expect(strcmp((string) $b->id, (string) $a->id) < 0)->toBeTrue();

    return [$a, $b->refresh()];
}

it('lists the promoted draft first in the inbox, by when it was sent', function (): void {
    [$a, $b] = promotedOrderPair($this->form);

    $this->withoutVite()->actingAs($this->owner)
        ->get('http://acme.meridian.test/submissions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('data', 2)
            ->where('data.0.id', (string) $b->id)
            ->where('data.1.id', (string) $a->id)
            ->etc());
});

it('lists it first in the form hub’s recent panel, which says it uses the inbox’s order', function (): void {
    [$a, $b] = promotedOrderPair($this->form);

    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/forms/{$this->form->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('recent', 2)
            ->where('recent.0.id', (string) $b->id)
            ->where('recent.1.id', (string) $a->id)
            ->etc());
});

it('lists it first in global search, which orders by recency as the inbox does', function (): void {
    [$a, $b] = promotedOrderPair($this->form);

    $rows = app(SubmissionSearchArm::class)->search($this->owner, SearchTerms::parse('promoted'), 50)->rows;

    expect(array_column($rows, 'id'))->toBe([(string) $b->id, (string) $a->id]);
});

it('breaks a same-second tie by id, so offset paging stays stable', function (): void {
    $t0 = CarbonImmutable::parse('2026-08-01 10:00:00');
    $first = seedCountableAt($this->form, $t0);
    $second = seedCountableAt($this->form, $t0);

    $this->withoutVite()->actingAs($this->owner)
        ->get('http://acme.meridian.test/submissions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('data', 2)
            ->where('data.0.id', (string) $second->id)
            ->where('data.1.id', (string) $first->id)
            ->etc());
});
