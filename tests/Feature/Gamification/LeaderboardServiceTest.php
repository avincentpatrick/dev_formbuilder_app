<?php

declare(strict_types=1);

use App\Enums\BadgeKey;
use App\Enums\PlanTier;
use App\Enums\PointRule;
use App\Enums\TenantUserStatus;
use App\Models\BadgeAward;
use App\Models\PointAward;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Gamification\Leaderboard;
use App\Services\Gamification\LeaderboardService;
use App\Services\Gamification\TeamProgressService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| K1d — the ladder's READ (ADR-0020 §D7, §D11). The ranking RULE is unit-tested without a database in
| tests/Unit/Gamification/LeaderboardTest.php; everything here needs one.
|
| WHAT CLASS OF BUG THIS FILE EXISTS TO CATCH, in the order the bugs would actually happen:
|
|  1. THE ROSTER COMING FROM THE LEDGER INSTEAD OF THE MEMBERSHIP. `point_awards` is append-only, so a
|     removed member's rows outlive them. Build the ladder by grouping the ledger and the workspace
|     scoreboard starts naming ex-colleagues — and the person who left keeps a rank forever.
|  2. THE JOIN FAN-OUT. Reading points and badges in ONE statement multiplies a member's award rows by
|     their badge rows and inflates SUM(points) silently. The fixture that catches it needs at least TWO
|     of each; one of each returns the same number under both shapes.
|  3. THE QUIET EMPTY LADDER. Every read here is RLS-filtered, so with no tenant GUC they return nothing
|     rather than failing — making "nobody has scored" and "you forgot to enter a tenant" the same output.
|  4. THE TWO HALVES DISAGREEING BY ACCIDENT RATHER THAN BY DESIGN. §D11's gap between team totals and the
|     ladder is deliberate and is asserted in BOTH directions, so neither can be "fixed" without a red test.
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

/** The reading under test. A distinct name from the sibling files' globals — they share one process. */
function ladder(): Leaderboard
{
    return app(LeaderboardService::class)->forCurrentTenant();
}

/** A member whose membership row exists in `$status` rather than active. */
function memberInStatus(TenantUserStatus $status, string $roleName = 'form_editor'): User
{
    $user = User::factory()->create();

    TenantUser::create([
        'user_id' => $user->id,
        'status' => $status,
        'joined_at' => now(),
        'invited_role_id' => catalogRole($roleName),
    ]);

    return $user;
}

/*
|--------------------------------------------------------------------------
| The off-tenant guard
*/

it('returns an explicit empty ladder off-tenant rather than a quiet one', function (): void {
    PointAward::factory()->create(['user_id' => $this->owner->id]);

    // ⚠️ applyLocal(null), NEVER forget() — the project rule. This is the state a console command or a
    // queue worker starts in.
    TenantContext::applyLocal(null);

    // ⚠️ THE ASSERTION IS ON THE QUERY COUNT, NOT ON THE EMPTINESS, AND THE K1c MUTATION PASS IS WHY.
    // Deleting the guard SURVIVES an assertion on the returned value: every read behind this service is
    // RLS-filtered, so off-tenant they all return nothing and the ladder is empty either way. The only
    // thing that can discriminate is whether the service asked the database at all.
    DB::flushQueryLog();
    DB::enableQueryLog();
    $board = ladder();
    $queries = count(DB::getRawQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0)
        ->and($board->entries)->toBe([])
        ->and($board->memberCount)->toBe(0);
});

it('has no standing for anyone off-tenant, without asking the database', function (): void {
    TenantContext::applyLocal(null);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $standing = app(LeaderboardService::class)->standingFor((string) $this->owner->id);
    $queries = count(DB::getRawQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0)
        ->and($standing->rank)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The roster is the MEMBERSHIP, not the ledger
*/

it('ranks every active member, including one who has earned nothing', function (): void {
    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');

    PointAward::factory()->forRule(PointRule::FormPublished)->create(['user_id' => $this->owner->id]);

    $board = ladder();

    expect($board->memberCount)->toBe(2)
        ->and($board->entries[0]->userId)->toBe((string) $this->owner->id)
        ->and($board->entries[0]->points)->toBe(25)
        ->and($board->entries[1]->userId)->toBe((string) $editor->id)
        ->and($board->entries[1]->points)->toBe(0)
        ->and($board->entries[1]->rank)->toBe(2);
});

it('leaves an invited member off the ladder until they accept', function (): void {
    memberInStatus(TenantUserStatus::Invited);

    // Also the `users_visibility` fact one layer down: RLS admits only ACTIVE co-tenants, so a
    // membership-first join would drop this row anyway. The explicit predicate is what makes it findable.
    expect(ladder()->memberCount)->toBe(1);
});

it('leaves the CALLER off the ladder when their own membership is not active', function (): void {
    // ⚠️ THIS IS WHY THE `whereExists` IS NOT REDUNDANT WITH RLS, AND I ONLY FOUND IT BY ASKING WHAT COULD
    // KILL A MUTATION THAT DELETED IT. `users_visibility` is `id = app.current_user_id OR EXISTS(active
    // co-tenant membership)` -- and the FIRST arm is unconditional. So a caller whose own membership has
    // been removed or suspended still sees their own `users` row, and a roster that leaned on RLS alone
    // would seat them on the ladder as a ghost: ranked, named, and no longer a member of the workspace.
    // Every OTHER member is filtered identically by both mechanisms, which is exactly what makes this the
    // only case that can tell them apart.
    $ghost = memberInStatus(TenantUserStatus::Removed);

    PointAward::factory()->forRule(PointRule::FormPublished)->create(['user_id' => $this->owner->id]);

    // The ghost is now the acting user, which is the state that arms the `id = current_user_id` arm.
    enterTenant($this->tenant->id, $ghost->id);

    $board = ladder();

    expect($board->memberCount)->toBe(1)
        ->and($board->entries[0]->userId)->toBe((string) $this->owner->id)
        ->and(collect($board->entries)->firstWhere('userId', (string) $ghost->id))->toBeNull()
        // And their own standing is the honest empty one rather than a rank on a board they left.
        ->and(app(LeaderboardService::class)->standingFor((string) $ghost->id)->rank)->toBeNull();
});

it('drops a departed members awards from the ladder while the team total keeps them — both directions', function (): void {
    // ⚠️ THE §D11 CASE. `point_awards` has no DELETE policy, so a removed member's history survives them.
    // Asserting only that the ladder omits them would pass against a service that had lost the awards
    // entirely; asserting only the team total would pass against one that still named the ex-member. The
    // gap is the assertion.
    $leaver = memberInStatus(TenantUserStatus::Removed);

    PointAward::factory()->forRule(PointRule::FormPublished)->create(['user_id' => $this->owner->id]);
    PointAward::factory()->forRule(PointRule::FormPublished)->create(['user_id' => $leaver->id]);

    $board = ladder();
    $team = app(TeamProgressService::class)->forCurrentTenant();

    $ladderTotal = array_sum(array_map(fn ($entry) => $entry->points, $board->entries));

    expect($board->memberCount)->toBe(1)
        ->and($board->entries[0]->userId)->toBe((string) $this->owner->id)
        ->and($ladderTotal)->toBe(25)
        // The leaver's 25 is still in the workspace's own history, and the difference is exactly them.
        ->and($team->points)->toBe(50)
        ->and($team->points - $ladderTotal)->toBe(25)
        // `contributors` counts them too, which is the same gap in its other form.
        ->and($team->contributors)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Points and badges cannot contaminate each other
*/

it('totals points correctly for a member holding several badges, which a single joined read would not', function (): void {
    // ⚠️ TWO OF EACH IS THE MINIMUM THAT DISCRIMINATES. With one award and one badge a fan-out join and two
    // grouped reads both return the award's value; with 2 awards and 3 badges the join returns 6 rows and
    // triples the total. The numbers are pinned literally rather than against PointRule::points(), which
    // would compare the code with itself.
    PointAward::factory()->forRule(PointRule::FormPublished)->create(['user_id' => $this->owner->id]);
    PointAward::factory()->forRule(PointRule::FormCreated)->create(['user_id' => $this->owner->id]);

    foreach ([BadgeKey::Welcome, BadgeKey::FirstForm, BadgeKey::FirstPublish] as $badge) {
        BadgeAward::factory()->forBadge($badge)->create(['user_id' => $this->owner->id]);
    }

    $entry = ladder()->entries[0];

    expect($entry->points)->toBe(35)
        ->and($entry->badges)->toBe(3);
});

it('reports zero badges for a member who has earned points and none', function (): void {
    PointAward::factory()->forRule(PointRule::SubmissionEdited)->create(['user_id' => $this->owner->id]);

    expect(ladder()->entries[0]->badges)->toBe(0)
        ->and(ladder()->entries[0]->points)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Tenancy
*/

it('reads the workspace it is inside and never a neighbour', function (): void {
    PointAward::factory()->forRule(PointRule::FormPublished)->create(['user_id' => $this->owner->id]);

    $other = inboxTenant('northwind');
    enterTenant($other->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    PointAward::factory()->forRule(PointRule::FormCreated)->count(3)->create(['user_id' => $this->owner->id]);

    expect(ladder()->entries[0]->points)->toBe(30);

    enterTenant($this->tenant->id, $this->owner->id);

    // Both halves are checked, and they are scoped by DIFFERENT mechanisms: the ledger totals by an
    // explicit tenant predicate under RLS, the roster by Eloquent plus the `users` join-shape policy. If
    // the id fed to the first ever diverged from the ambient one driving the second, this is where it shows.
    expect(ladder()->entries[0]->points)->toBe(25)
        ->and(ladder()->memberCount)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Standing is the ladder, asked
*/

it('gives a member the same numbers on their own card as beside their name on the list', function (): void {
    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');

    PointAward::factory()->forRule(PointRule::FormPublished)->create(['user_id' => $this->owner->id]);
    PointAward::factory()->forRule(PointRule::SubmissionReviewed)->create(['user_id' => $editor->id]);
    BadgeAward::factory()->forBadge(BadgeKey::FirstReview)->create(['user_id' => $editor->id]);

    $standing = app(LeaderboardService::class)->standingFor((string) $editor->id);
    $entry = collect(ladder()->entries)->firstWhere('userId', (string) $editor->id);

    expect($standing->rank)->toBe(2)
        ->and($standing->of)->toBe(2)
        ->and($standing->points)->toBe($entry->points)
        ->and($standing->badges)->toBe($entry->badges)
        ->and($standing->rank)->toBe($entry->rank);
});
