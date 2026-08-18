<?php

declare(strict_types=1);

use App\Enums\BadgeKey;
use App\Enums\PlanTier;
use App\Enums\PointRule;
use App\Models\BadgeAward;
use App\Models\PointAward;
use App\Models\User;
use App\Services\Gamification\BadgeShelf;
use App\Services\Gamification\BadgeShelfService;
use App\Services\Gamification\BadgeStanding;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| K1e — the badge shelf's READS. The assembly and ordering RULE is unit-tested without a database in
| tests/Unit/Gamification/BadgeShelfTest.php; everything here needs one.
|
| WHAT CLASS OF BUG THIS FILE EXISTS TO CATCH, in the order the bugs would actually happen:
|
|  1. THE JOIN FAN-OUT, IN ITS SECOND FORM. LeaderboardService records why joining `point_awards` to
|     `badge_awards` inflates a SUM. The same trap is here wearing more innocent clothes: one statement
|     returning "badge, date, and how many awards of its rule" multiplies each badge row by that rule's
|     award rows. The fixture needs at least TWO badges on ONE rule and at least TWO awards of it —
|     one of each returns the same number under both shapes and would let the bug through.
|  2. THE COUNT COMING BACK AS TEXT. PostgreSQL returns COUNT as a string through PDO, and an unconverted
|     total sorts as text — where '9' outranks '10' — which would reorder the nearest-first list silently.
|  3. THE SHELF CROSSING A TENANT OR A MEMBER. Both statements are raw SQL with explicit predicates sitting
|     underneath RLS; the predicates are what this file proves, since RLS would mask a missing one.
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
    assignPlanTier(PlanTier::Free);
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

function shelfFor(User $user): BadgeShelf
{
    return app(BadgeShelfService::class)->for((string) test()->tenant->id, (string) $user->id);
}

/** Find one badge's standing wherever it sits on the shelf. */
function standingOf(BadgeShelf $shelf, BadgeKey $badge): BadgeStanding
{
    return collect([...$shelf->earned, ...$shelf->inProgress])
        ->firstWhere(fn (BadgeStanding $s): bool => $s->badge === $badge);
}

function collectOnce(User $user, string $subject): void
{
    PointAward::factory()->create([
        'user_id' => $user->id,
        'rule' => PointRule::SubmissionCollected,
        'points' => PointRule::SubmissionCollected->points(),
        'subject_type' => 'submission',
        'subject_id' => $subject,
    ]);
}

/*
|--------------------------------------------------------------------------
| The fan-out that a "one statement would do" refactor would introduce
*/

it('reports the true award count for a rule carrying several badges', function (): void {
    // THREE badges all count `submission.collected` (FirstResponse, Collector, FieldVeteran). Give the
    // member TWO of them and FOUR awards of the rule. A join would return 2 × 4 = 8 rows and report the
    // progress as 8; two grouped reads keyed by different columns cannot fan out at all.
    foreach (['s1', 's2', 's3', 's4'] as $subject) {
        collectOnce($this->owner, $subject);
    }

    foreach ([BadgeKey::FirstResponse, BadgeKey::Collector] as $badge) {
        BadgeAward::factory()->create([
            'user_id' => $this->owner->id,
            'badge' => $badge,
            'awarded_at' => now(),
        ]);
    }

    $shelf = shelfFor($this->owner);

    // ⚠️ EVERY badge on that rule reads FOUR — including the one still unearned, which is the reading a
    // fan-out would inflate hardest, since the page renders it as "8 of 250".
    expect(standingOf($shelf, BadgeKey::FirstResponse)->progress)->toBe(4)
        ->and(standingOf($shelf, BadgeKey::Collector)->progress)->toBe(4)
        ->and(standingOf($shelf, BadgeKey::FieldVeteran)->progress)->toBe(4)
        ->and(count($shelf->earned))->toBe(2);
});

it('returns the progress as an integer, not the string PDO hands back', function (): void {
    collectOnce($this->owner, 's1');

    // '9' > '10' as text, which would reorder the nearest-first list without changing a single number a
    // reader can see. `toBe` is identity, so a string would fail this.
    expect(standingOf(shelfFor($this->owner), BadgeKey::Collector)->progress)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Scoping
*/

it('counts only this members awards, not the workspaces', function (): void {
    $colleague = User::factory()->create();
    enterTenant($this->tenant->id, $colleague->id);
    makeActiveMember($colleague, 'form_editor');

    // Put the tenant context back on the owner before writing theirs.
    enterTenant($this->tenant->id, $this->owner->id);
    collectOnce($this->owner, 'mine');

    enterTenant($this->tenant->id, $colleague->id);
    collectOnce($colleague, 'theirs-1');
    collectOnce($colleague, 'theirs-2');

    enterTenant($this->tenant->id, $this->owner->id);

    expect(standingOf(shelfFor($this->owner), BadgeKey::Collector)->progress)->toBe(1)
        ->and(standingOf(shelfFor($colleague), BadgeKey::Collector)->progress)->toBe(2);
});

it('reads the badge rows through the same tenant predicate the awards use', function (): void {
    BadgeAward::factory()->create([
        'user_id' => $this->owner->id,
        'badge' => BadgeKey::Welcome,
        'awarded_at' => now()->subDay(),
    ]);

    $shelf = shelfFor($this->owner);

    expect(array_map(fn (BadgeStanding $s): string => $s->badge->value, $shelf->earned))
        ->toBe([BadgeKey::Welcome->value])
        ->and($shelf->earned[0]->earnedOn)->not->toBeNull();
});

it('asks the database exactly twice, which is what keeps the two reads from becoming one', function (): void {
    collectOnce($this->owner, 's1');
    BadgeAward::factory()->create([
        'user_id' => $this->owner->id,
        'badge' => BadgeKey::FirstResponse,
        'awarded_at' => now(),
    ]);

    // ⚠️ AN EXACT COUNT, NOT A CEILING. Two is the whole design: one grouped read per table, keyed on
    // different columns. A third would mean somebody added a per-badge lookup inside the catalog walk
    // (ten queries the moment the catalog grows), and ONE would mean the join this file's header warns
    // about. Both regressions are invisible in the returned value on a small fixture.
    DB::flushQueryLog();
    DB::enableQueryLog();
    shelfFor($this->owner);
    $queries = count(DB::getRawQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| A row the catalog no longer knows about
*/

it('drops a badge row whose key has left the catalog rather than failing the page', function (): void {
    BadgeAward::factory()->create([
        'user_id' => $this->owner->id,
        'badge' => BadgeKey::Welcome,
        'awarded_at' => now(),
    ]);

    // ⚠️ WRITTEN PAST THE ENUM AND PAST THE CHECK CONSTRAINT ON PURPOSE. `badge_awards_badge_check` is
    // generated from BadgeKey, so this state is unreachable today — and the single edit that would make it
    // reachable is REMOVING a case, which leaves every historical row behind it. The right failure is
    // losing a retired badge from the shelf; a 500 on the achievements page is not. Dropping the
    // constraint for one statement is what lets the guard be exercised at all.
    DB::statement('ALTER TABLE badge_awards DROP CONSTRAINT badge_awards_badge_check');
    DB::insert(
        'INSERT INTO badge_awards (tenant_id, user_id, badge, awarded_at, created_at, updated_at) '
        .'VALUES (?, ?, ?, now(), now(), now())',
        [(string) $this->tenant->id, (string) $this->owner->id, 'a_badge_that_was_retired'],
    );

    $shelf = shelfFor($this->owner);

    expect(array_map(fn (BadgeStanding $s): string => $s->badge->value, $shelf->earned))
        ->toBe([BadgeKey::Welcome->value])
        ->and(count($shelf->earned) + count($shelf->inProgress))->toBe(count(BadgeKey::cases()));
});
