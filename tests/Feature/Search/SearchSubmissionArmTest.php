<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Enums\SearchEntity;
use App\Enums\SubmissionStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Search\SearchPresenter;
use App\Support\Search\SearchTerms;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The submissions arm (Increment J1b).
|
| ⚠️ THE FIRST CASE IS THE HIGHEST-VALUE TEST IN THIS INCREMENT and the reason this file exists.
|
| `Submission::scopeVisibleTo()` adds `(granted forms OR I am the respondent)`. Compose a keyword predicate
| onto that FLAT and you get `granted OR (respondent AND keyword)` — so a reviewer searching any word also
| receives every submission they ever authored, on any form, in any status. The search would silently return
| rows the inbox refuses.
|
| The scope's own docblock records that its defensive closure is REDUNDANT today — mutating it leaves the
| suite green — because `Builder::callScope()` already groups what a local scope adds. So mutating the
| wrapper proves nothing. What has to be falsifiable is the CALL SHAPE: that the arm invokes `visibleTo` as a
| scope and adds its keyword clause in its own group, rather than hoisting either into a bare chain.
|
| The fixture is built so that a flattened OR returns a row nothing else would: the reviewer authored a
| submission on a form they hold NO grant on, and that submission does not match the keyword.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenant = inboxTenant();

    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->reviewer = User::factory()->create();
    makeActiveMember($this->reviewer, 'reviewer');

    // ⚠️ THREE FORMS AND THREE ROWS, EACH ONE CATCHING A DIFFERENT MUTATION. This shape is the product of
    // two mutations that SURVIVED the first draft, and the corrections are worth stating because both were
    // counter-intuitive:
    //
    //   1. Flattening the visibility OR does NOT leak the reviewer's own unrelated rows. SQL binds AND
    //      tighter than OR, so `granted OR (respondent AND countable AND keyword)` leaves the FIRST disjunct
    //      unconstrained — the leak is every row on a GRANTED form, for any query at all.
    //   2. A non-matching row on `Clinic Intake` is still a correct match, because the arm deliberately
    //      matches the parent FORM's title. So the row that proves the keyword still binds has to sit on a
    //      granted form whose title does not match either.
    $this->granted = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    makeCollaborator($this->granted, $this->reviewer, ResourceCapacity::Reviewer);

    // Granted too, but its title does not match "clinic".
    $this->grantedOther = publishedInboxForm($this->tenant, $this->owner, 'Payroll Register');
    makeCollaborator($this->grantedOther, $this->reviewer, ResourceCapacity::Reviewer);

    // No grant at all.
    $this->foreign = publishedInboxForm($this->tenant, $this->owner, 'Supply Ledger');

    // Visible AND matching — the row that must always come back.
    $this->matching = seedInboxSubmission($this->granted, null, SubmissionStatus::Submitted, []);
    $this->matching->forceFill(['remarks' => 'chased the clinic for a callback'])->save();

    // Visible, NOT matching. Reddens the associativity mutation (a flat OR returns it).
    $this->visibleNoMatch = seedInboxSubmission($this->grantedOther, null, SubmissionStatus::Submitted, []);
    $this->visibleNoMatch->forceFill(['remarks' => 'annual reconciliation'])->save();

    // MATCHING but not visible. Reddens dropping `visibleTo` for a bare `Submission::query()` — and note it
    // is same-tenant, so RLS cannot be what excludes it.
    $this->hiddenMatching = seedInboxSubmission($this->foreign, null, SubmissionStatus::Submitted, []);
    $this->hiddenMatching->forceFill(['remarks' => 'clinic supplies reorder'])->save();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** @return list<string> submission ids global search returns $user for $keyword */
function searchedSubmissionIds(User $user, string $keyword): array
{
    $props = app(SearchPresenter::class)->index($user, SearchTerms::parse($keyword), null);

    foreach ($props['data'] as $group) {
        if ($group['entity'] === SearchEntity::Submission->value) {
            return array_map(static fn (array $row): string => (string) $row['id'], $group['items']);
        }
    }

    return [];
}

it('returns only rows that are BOTH visible and matching', function (): void {
    // Asserts the OUTCOME both predicates exist to produce, on a fixture where each row can only be excluded
    // by one of them: `visibleNoMatch` is visible and does not match, `hiddenMatching` matches and is not
    // visible, both in the same tenant so RLS cannot be what excludes either.
    //
    // ⚠️ BE PRECISE ABOUT WHICH MUTATIONS THIS CATCHES, because one obvious one it does NOT.
    //   • Removing `->visibleTo($user)` → REDDENS (`hiddenMatching` comes back). Verified.
    //   • Removing the keyword group → REDDENS (`visibleNoMatch` comes back). Verified.
    //   • Hoisting `visibleTo`'s body into a bare `->whereIn(...)->orWhere(...)` chain → **SURVIVES**, and
    //     the reason is worth knowing rather than filing as a gap. Laravel's `addNewWheresWithinGroup()`
    //     groups BOTH slices of the where list, not just the scope's own — so the very next local scope
    //     (`->countable()`) retroactively wraps whatever came before it. The flat OR is therefore
    //     un-observable here for as long as any local scope follows the visibility clause.
    //
    // That is a real protection and it is also an accident of call order, which is exactly why the arm does
    // not lean on it: `visibleTo` is invoked AS A SCOPE and the match clause carries its own closure. Two
    // deliberate mechanisms, plus one incidental one that must never become the only one — `Submission.php`
    // makes the same argument about its own wrapper being redundant today.
    $ids = searchedSubmissionIds($this->reviewer, 'clinic');

    expect($ids)->toContain($this->matching->id)
        ->and($ids)->not->toContain($this->visibleNoMatch->id)
        ->and($ids)->not->toContain($this->hiddenMatching->id);
});

it('matches a submission by its remarks', function (): void {
    expect(searchedSubmissionIds($this->owner, 'callback'))->toContain($this->matching->id);
});

it('matches a submission by its FORM’s title, the only label the inbox shows', function (): void {
    // Deliberately not scoped by form visibility: a reviewer holds no `forms.*` key, so applying it would
    // make their own work unfindable by the one word they know it by. The rows are still bounded by
    // `visibleTo`, which the first case proves.
    expect(searchedSubmissionIds($this->reviewer, 'intake'))->toContain($this->matching->id);
});

it('finds a submission by a reference prefix', function (): void {
    $prefix = mb_substr($this->matching->id, 0, 8);

    expect(searchedSubmissionIds($this->owner, $prefix))->toContain($this->matching->id);
});

it('ignores a reference prefix shorter than eight characters', function (): void {
    // Below eight the LIKE stops being a lookup and starts matching a meaningful slice of the tenant.
    $short = mb_substr($this->matching->id, 0, 4);

    expect(SearchTerms::parse($short)->uuidPrefix())->toBeNull();
});

it('does not surface a draft, matching the inbox default', function (): void {
    $draft = seedInboxSubmission($this->granted, null, SubmissionStatus::Draft, []);
    $draft->forceFill(['remarks' => 'clinic draft note'])->save();

    expect(searchedSubmissionIds($this->owner, 'clinic'))->not->toContain($draft->id);
});

it('never matches ANSWER text — the decision recorded in the migration', function (): void {
    // Asserts an ABSENCE, which is the only way to pin a deliberate exclusion. A user decision of
    // 2026-08-09; the four supporting arguments are in the submissions migration's docblock.
    $withAnswer = seedInboxSubmission($this->granted, null, SubmissionStatus::Submitted, [
        'q_notes' => 'watermelon',
    ]);

    expect(searchedSubmissionIds($this->owner, 'watermelon'))->not->toContain($withAnswer->id);
});

it('never matches a guest contact email, which is on the PII list', function (): void {
    $guest = seedInboxSubmission($this->granted, null, SubmissionStatus::Submitted, []);
    $guest->forceFill(['guest_contact_email' => 'zebra@example.test'])->save();

    expect(searchedSubmissionIds($this->owner, 'zebra'))->not->toContain($guest->id);
});
