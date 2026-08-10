<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Enums\SubmissionStatus;
use App\Models\User;
use App\Services\Search\Arms\FormSearchArm;
use App\Services\Search\Arms\SubmissionSearchArm;
use App\Support\Search\SearchTerms;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A count must never disclose more than the rows do (Increment J1b).
|
| `SearchArm`'s interface docblock has named this file as its pin since the interface was written:
| "IMPLEMENTATIONS MUST DERIVE THIS FROM THE SAME BUILDER search() USES ... every implementation routes both
| through one private builder() and SearchCountLeakTest mutates it." Until now that sentence was untrue —
| the only coverage was a single forms-arm case inside `SearchVisibilityTest`, and the submissions arm's
| count had none at all.
|
| ⚠️ WHY A COUNT IS A DISCLOSURE AND THE ROWS ARE NOT. `SearchPresenter` renders `counts` beside every group
| heading. If `count()` were ever built from a builder missing the visibility predicate, the badge would
| read "3" over a list of 1 — which tells the user that two rows exist that they may not see, on a surface
| whose whole disclosure rule (DSR §3.4.1) is that an invisible result must be indistinguishable from a
| non-existent one. That is a leak with no row ever leaving the database, which is exactly why it needs its
| own file rather than an assertion tacked onto a rows test.
|
| ── THE FIXTURE IS THE TEST ──────────────────────────────────────────────────────────────────────────────
| Every row below exists to make one specific mutation fail. In particular each subject can see SOME but not
| ALL of the matching rows: if the fixture let a subject see everything that matches, deleting the
| visibility predicate from count() would change nothing and this file would be decorative.
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

    $this->editor = User::factory()->create();
    makeActiveMember($this->editor, 'form_editor');

    $this->reviewer = User::factory()->create();
    makeActiveMember($this->reviewer, 'reviewer');

    // Two matching forms. The editor holds an Editor grant on exactly one of them, so "matching" and
    // "visible" are deliberately different sets.
    $this->granted = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    $this->foreign = publishedInboxForm($this->tenant, $this->owner, 'Clinic Referral');
    makeCollaborator($this->granted, $this->editor, ResourceCapacity::Editor);
    makeCollaborator($this->granted, $this->reviewer, ResourceCapacity::Reviewer);

    // Two matching submissions the reviewer may see, one they may not — same tenant throughout, so RLS is
    // never what excludes the hidden row and "the policy worked" cannot be confused with "RLS worked".
    seedInboxSubmission($this->granted, null, SubmissionStatus::Submitted, [])
        ->forceFill(['remarks' => 'chased the clinic for a callback'])->save();
    seedInboxSubmission($this->granted, null, SubmissionStatus::Submitted, [])
        ->forceFill(['remarks' => 'second clinic follow-up'])->save();
    seedInboxSubmission($this->foreign, null, SubmissionStatus::Submitted, [])
        ->forceFill(['remarks' => 'clinic supplies reorder'])->save();
});

it('counts a form arm exactly as many rows as it returns, never as many as exist', function (): void {
    $arm = app(FormSearchArm::class);
    $terms = SearchTerms::parse('clinic');

    $rows = $arm->search($this->editor, $terms, 50)->rows;
    $count = $arm->count($this->editor, $terms);

    // Anti-vacuity: if the editor could see everything, or nothing, this case could not distinguish a
    // shared builder from a divergent one.
    expect($rows)->toHaveCount(1)
        ->and($count)->toBe(1);

    // And the owner, who CAN see both, must count both — otherwise "1" would be right by accident.
    expect($arm->count($this->owner, $terms))->toBe(2);
});

it('counts a submission arm exactly as many rows as it returns, never as many as exist', function (): void {
    $arm = app(SubmissionSearchArm::class);
    $terms = SearchTerms::parse('clinic');

    $rows = $arm->search($this->reviewer, $terms, 50)->rows;
    $count = $arm->count($this->reviewer, $terms);

    expect($rows)->toHaveCount(2)
        ->and($count)->toBe(2);

    expect($arm->count($this->owner, $terms))->toBe(3);
});

it('keeps the count and the rows in step when the keyword matches nothing', function (): void {
    // The zero case is where a divergent builder is likeliest to survive review: both numbers are small and
    // "0 vs 0" looks right whatever the predicates are. Asserted for both arms so a future arm cannot copy
    // a half-covered precedent.
    $terms = SearchTerms::parse('zzzznotathing');

    foreach ([app(FormSearchArm::class), app(SubmissionSearchArm::class)] as $arm) {
        expect($arm->search($this->owner, $terms, 50)->rows)->toBe([])
            ->and($arm->count($this->owner, $terms))->toBe(0);
    }
});

it('counts past the display limit rather than stopping at it', function (): void {
    // `search()` applies `limit($limit + 1)` and `count()` applies none — the one place the two builders
    // are legitimately different. If a refactor ever pushed the limit down into the shared builder, the
    // badge would silently cap at the preview size and under-report.
    $arm = app(FormSearchArm::class);
    $terms = SearchTerms::parse('clinic');

    expect($arm->search($this->owner, $terms, 1)->rows)->toHaveCount(1)
        ->and($arm->search($this->owner, $terms, 1)->hasMore)->toBeTrue()
        ->and($arm->count($this->owner, $terms))->toBe(2);
});
