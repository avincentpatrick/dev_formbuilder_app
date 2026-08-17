<?php

declare(strict_types=1);

use App\Enums\BadgeKey;
use App\Enums\PlanTier;
use App\Enums\PointRule;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Enums\TenantUserStatus;
use App\Events\SubmissionCreated;
use App\Models\BadgeAward;
use App\Models\PointAward;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Gamification\TeamProgress;
use App\Services\Gamification\TeamProgressService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| K1c — workspace-wide team progress (ADR-0020 §D8's other half).
|
| WHAT CLASS OF BUG THIS FILE EXISTS TO CATCH, in the order the bugs would actually happen:
|
|  1. THE LADDER'S PREDICATE LEAKING UPWARDS. `submission.collected` credits nobody for a guest response
|     (§D8), and the obvious way to build "team responses" is to sum the ladder — which would silently
|     under-report every workspace that collects through public links, i.e. the product's main channel.
|     The case that pins this asserts the DIFFERENCE, not the total, because a total agrees by accident
|     on a tenant with no guest rows.
|  2. THE READER'S GRANTS LEAKING UPWARDS. Team progress is what the WORKSPACE did. Scope it to what the
|     reader may open and two members on one screen see two different totals for one workspace.
|  3. THE SIX QUIET ZEROES. Every read here is RLS-filtered, so with no tenant GUC they all return
|     nothing rather than failing — making "this workspace has done nothing" and "you forgot to enter a
|     tenant" the same output. The guard is asserted, not assumed.
|  4. ROWS COUNTED AS PEOPLE. `contributors` must not grow because one person was busy.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    assignPlanTier(PlanTier::Free);
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

/** The reading under test. A separate name from the sibling files' globals — they share one process. */
function teamProgress(): TeamProgress
{
    return app(TeamProgressService::class)->forCurrentTenant();
}

it('returns an explicit empty reading off-tenant rather than six quiet zeroes', function (): void {
    PointAward::factory()->create(['user_id' => $this->owner->id]);

    // ⚠️ applyLocal(null), NEVER forget() — the project rule. This is the state a queue worker or a console
    // command starts in, and every query behind this service would return no rows there without raising.
    TenantContext::applyLocal(null);

    $progress = teamProgress();

    expect($progress->points)->toBe(0)
        ->and($progress->responses)->toBe(0)
        ->and($progress->contributors)->toBe(0);
});

it('sums every members points, not just the readers', function (): void {
    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');

    PointAward::factory()->forRule(PointRule::FormPublished)->create(['user_id' => $this->owner->id]);
    PointAward::factory()->forRule(PointRule::SubmissionReviewed)->create(['user_id' => $editor->id]);

    // Pinned literally rather than against PointRule::points(), which would compare the code with itself.
    expect(teamProgress()->points)->toBe(28)
        ->and(teamProgress()->contributors)->toBe(2);
});

it('counts people once however busy they are', function (): void {
    PointAward::factory()->count(5)->create(['user_id' => $this->owner->id]);

    expect(teamProgress()->contributors)->toBe(1);
});

it('counts a guest response even though it credited nobody — and the gap is exactly the guests', function (): void {
    // ⚠️ THE §D8 CASE, AND IT ASSERTS THE DIFFERENCE RATHER THAN THE TOTAL. A workspace whose collection
    // is mostly public links would look empty if team progress were a sum over the ladder, and a test that
    // only checked `responses === 3` would pass against that bug on any tenant with no guest rows.
    $form = publishedInboxForm($this->tenant, $this->owner);

    $member = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);
    event(SubmissionCreated::for($member));

    foreach (['Bea', 'Cal'] as $name) {
        $guest = seedInboxSubmission(
            $form,
            null,
            SubmissionStatus::Submitted,
            ['full_name' => $name],
            SubmissionSource::Guest,
        );
        event(SubmissionCreated::for($guest));
    }

    $collected = PointAward::query()->where('rule', PointRule::SubmissionCollected->value)->count();

    expect(teamProgress()->responses)->toBe(3)
        ->and($collected)->toBe(1)
        ->and(teamProgress()->responses - $collected)->toBe(2);
});

it('excludes drafts from responses, on the one shared definition of a response', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);

    seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);
    seedInboxSubmission($form, $this->owner, SubmissionStatus::Draft, ['full_name' => 'Half']);

    expect(teamProgress()->responses)->toBe(1);
});

it('counts published forms and not drafts', function (): void {
    publishedInboxForm($this->tenant, $this->owner, 'Live');
    app(FormService::class)->create($this->tenant, $this->owner, 'Still drafting');

    expect(teamProgress()->publishedForms)->toBe(1);
});

it('stops counting a published form once it is archived', function (): void {
    // The archived form still carries `current_published_version_id`, so a predicate that looked only at
    // that column would report a workspace as more live than it is. Both halves of the scope matter.
    $form = publishedInboxForm($this->tenant, $this->owner, 'Live');
    app(FormService::class)->archive($form->refresh(), $this->owner);

    expect(teamProgress()->publishedForms)->toBe(0);
});

it('counts accepted members only, never an outstanding invitation', function (): void {
    $invitee = User::factory()->create();
    TenantUser::create([
        'user_id' => $invitee->id,
        'status' => TenantUserStatus::Invited,
        'invited_at' => now(),
        'invited_role_id' => catalogRole('viewer'),
    ]);

    expect(teamProgress()->activeMembers)->toBe(1);
});

it('counts badges across the whole workspace', function (): void {
    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');

    BadgeAward::factory()->forBadge(BadgeKey::FirstForm)->create(['user_id' => $this->owner->id]);
    BadgeAward::factory()->forBadge(BadgeKey::FirstPublish)->create(['user_id' => $this->owner->id]);
    BadgeAward::factory()->forBadge(BadgeKey::FirstResponse)->create(['user_id' => $editor->id]);

    expect(teamProgress()->badges)->toBe(3);
});

it('reads the workspace it is inside and never a neighbour', function (): void {
    PointAward::factory()->forRule(PointRule::FormPublished)->create(['user_id' => $this->owner->id]);

    $other = inboxTenant('northwind');
    enterTenant($other->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    PointAward::factory()->forRule(PointRule::FormCreated)->count(3)->create(['user_id' => $this->owner->id]);

    expect(teamProgress()->points)->toBe(30);

    enterTenant($this->tenant->id, $this->owner->id);

    // ⚠️ Both halves of the service are checked here, and they are scoped by DIFFERENT mechanisms: the
    // ledger totals by an explicit tenant predicate under RLS, the member count by Eloquent's own global
    // scope. If the id fed to the first ever diverged from the ambient one driving the second, this is
    // where it would show — which is the K1b hazard restated one class over.
    expect(teamProgress()->points)->toBe(25)
        ->and(teamProgress()->activeMembers)->toBe(1);
});
