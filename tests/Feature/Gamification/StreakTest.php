<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\PointRule;
use App\Models\PointAward;
use App\Models\User;
use App\Services\Gamification\MemberStreak;
use App\Services\Gamification\StreakCalculator;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Carbon\CarbonImmutable;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| K1c — the derived activity streak.
|
| WHAT CLASS OF BUG THIS FILE EXISTS TO CATCH, in the order the bugs would actually happen:
|
|  1. THE COLUMN COLLAPSE. A streak read off `created_at` instead of `awarded_at` is INVISIBLE in every
|     ordinary test, because the two are the same instant for every live award. It shows up only on a
|     back-dated row — which is every row the K1c backfill writes — and the symptom is every historical
|     workspace showing one enormous single-day streak dated install day. Two cases here carry the
|     `created_at` half of the assertion for that reason.
|  2. THE MIDNIGHT ZERO. Walking back from "today" alone breaks every streak in the product between
|     midnight and whenever that member next acts. Someone on a fourteen-day run is told at breakfast
|     they have none.
|  3. THE INHERITED BOUNDARY. Reading the zone from `config('app.timezone')` passes every test written
|     on a UTC box and silently re-cuts history the day an operator changes an unrelated setting.
|  4. CURRENT READ AS LONGEST. They are different questions and a decayed `current` must not drag the
|     high-water mark down with it.
|
| No plan is needed here — nothing in this file writes through PointsRecorder, so the entitlement gate is
| not on the path. A plan IS seeded anyway, so that a future case which does award through the recorder
| cannot pass vacuously on a tenant with no catalog at all.
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

/**
 * Write awards on the given days-ago offsets, counted in the boundary zone.
 *
 * Named `streakDays` rather than anything shorter because Pest loads every file in this directory into one
 * process and a helper here is a GLOBAL — `badgesOf`, `awardTimes`, `badgeNotifications` and `awardsFor`
 * are already declared by the two sibling files, and a redeclaration is a fatal, not a shadow.
 *
 * @param  list<int>  $daysAgo
 */
function streakDays(User $user, array $daysAgo, PointRule $rule = PointRule::SubmissionCollected): void
{
    foreach ($daysAgo as $offset) {
        PointAward::factory()->forRule($rule)->create([
            'user_id' => $user->id,
            // Mid-afternoon UTC, so a case is never accidentally testing a boundary it did not mean to.
            'awarded_at' => CarbonImmutable::now(StreakCalculator::DAY_BOUNDARY)
                ->startOfDay()
                ->subDays($offset)
                ->addHours(14),
        ]);
    }
}

/** Always the workspace the test is currently INSIDE, so a case that switches tenants needs no bookkeeping. */
function streakOf(User $user, ?string $zone = null): MemberStreak
{
    return app(StreakCalculator::class)->for(
        (string) TenantContext::currentTenantId(),
        (string) $user->id,
        $zone,
    );
}

it('reports nothing at all for a member who has earned nothing', function (): void {
    $streak = streakOf($this->owner);

    expect($streak->current)->toBe(0)
        ->and($streak->longest)->toBe(0)
        ->and($streak->lastActiveOn)->toBeNull();
});

it('counts consecutive days ending today', function (): void {
    streakDays($this->owner, [0, 1, 2, 3]);

    $streak = streakOf($this->owner);

    expect($streak->current)->toBe(4)
        ->and($streak->longest)->toBe(4)
        ->and($streak->lastActiveOn?->toDateString())
        ->toBe(CarbonImmutable::now(StreakCalculator::DAY_BOUNDARY)->toDateString());
});

it('keeps a streak alive when nothing has been earned YET today', function (): void {
    // ⚠️ THE DECISION, NOT AN OFF-BY-ONE. Requiring today's date would zero every member in the product
    // between midnight and whenever they next act, so the number somebody sees at breakfast would not be
    // the number they saw the night before. It breaks after a FULL missed day, which the next case pins.
    streakDays($this->owner, [1, 2, 3]);

    expect(streakOf($this->owner)->current)->toBe(3);
});

it('breaks a streak once a whole day has passed with nothing in it', function (): void {
    streakDays($this->owner, [2, 3, 4]);

    $streak = streakOf($this->owner);

    // The run itself is still the member's best; only the LIVE count decays. Asserting both is what stops
    // a fix for one from silently being a regression in the other.
    expect($streak->current)->toBe(0)
        ->and($streak->longest)->toBe(3);
});

it('treats several awards on one day as one day', function (): void {
    streakDays($this->owner, [0, 0, 0, 1, 1]);

    expect(streakOf($this->owner)->current)->toBe(2);
});

it('reports the longest run anywhere in the ledger, not the current one', function (): void {
    // A five-day run months ago, then a two-day run ending today. `current` must not drag `longest` down.
    streakDays($this->owner, [60, 61, 62, 63, 64]);
    streakDays($this->owner, [0, 1]);

    $streak = streakOf($this->owner);

    expect($streak->current)->toBe(2)
        ->and($streak->longest)->toBe(5);
});

it('reads awarded_at and not created_at, so a replayed act lands on the day it happened', function (): void {
    // ⚠️ THE INVARIANT THE WHOLE BACKFILL RESTS ON, AND THE ONLY CASE THAT CAN SEE IT. Every other row in
    // this file is written today, where the two columns are indistinguishable — so a calculator reading
    // `created_at` would pass all of them and then report every historical workspace as one enormous
    // one-day streak dated install day.
    streakDays($this->owner, [40, 41, 42]);

    $streak = streakOf($this->owner);

    expect($streak->longest)->toBe(3)
        ->and($streak->current)->toBe(0)
        // Both halves, or the assertion passes with the two columns collapsed into one.
        ->and(PointAward::query()->get()->every(fn (PointAward $a): bool => $a->created_at?->isToday() === true))
        ->toBeTrue();
});

it('states its day boundary rather than inheriting it from the app timezone', function (): void {
    // ⚠️ PINNED LITERALLY, THE K1a RULE: an assertion that compared the constant against
    // `config('app.timezone')` would compare the code with itself and pass on any value either could take.
    // The two agree today, which is exactly what makes reading the config the tempting mistake.
    expect(StreakCalculator::DAY_BOUNDARY)->toBe('UTC');
});

it('cuts the day at the stated boundary rather than at the callers local midnight', function (): void {
    // 23:30 UTC and 00:30 UTC the next morning are one hour apart and are TWO days. A calculator that
    // bucketed by anything other than the stated zone would fold them into one and report a 1-day streak.
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));

    foreach (['2026-03-09 23:30:00', '2026-03-10 00:30:00'] as $at) {
        PointAward::factory()->create([
            'user_id' => $this->owner->id,
            'awarded_at' => CarbonImmutable::parse($at, 'UTC'),
        ]);
    }

    expect(streakOf($this->owner)->current)->toBe(2);
});

it('honours a caller-supplied zone, so a tenant timezone can arrive without touching this class', function (): void {
    // ⚠️ THE TWO ROWS ARE CHOSEN SO THE COUNTS DIFFER, NOT MERELY THE DATES. Asia/Manila is +08:00, so
    // 17:00 UTC on the 10th is already the 11th there while 03:00 UTC on the 11th is still the 11th — two
    // UTC days collapse into one Manila day. A calculator that ignored the argument would return the same
    // number twice and this case would prove nothing, which is the trap a same-zone pair falls into.
    $this->travelTo(CarbonImmutable::parse('2026-03-11 12:00:00', 'UTC'));

    foreach (['2026-03-10 17:00:00', '2026-03-11 03:00:00'] as $at) {
        PointAward::factory()->create([
            'user_id' => $this->owner->id,
            'awarded_at' => CarbonImmutable::parse($at, 'UTC'),
        ]);
    }

    $utc = streakOf($this->owner);
    $manila = streakOf($this->owner, 'Asia/Manila');

    expect($utc->current)->toBe(2)
        ->and($manila->current)->toBe(1)
        ->and($utc->lastActiveOn?->toDateString())->toBe('2026-03-11')
        ->and($manila->lastActiveOn?->toDateString())->toBe('2026-03-11');
});

it('counts one member at a time', function (): void {
    $other = User::factory()->create();
    makeActiveMember($other, 'form_editor');

    streakDays($this->owner, [0, 1, 2]);
    streakDays($other, [0]);

    expect(streakOf($this->owner)->current)->toBe(3)
        ->and(streakOf($other)->current)->toBe(1);
});

it('never counts another workspace, at either layer', function (): void {
    streakDays($this->owner, [0, 1]);

    $otherTenant = inboxTenant('northwind');
    enterTenant($otherTenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    streakDays($this->owner, [0, 1, 2, 3, 4]);

    // Asked for the SECOND workspace from inside it: five. The ORM is not involved at all here — the
    // calculator is raw SQL — so this is the tenant predicate and RLS agreeing, which is the only state
    // in which the number can be trusted.
    expect(streakOf($this->owner)->current)->toBe(5);

    enterTenant($this->tenant->id, $this->owner->id);

    expect(streakOf($this->owner)->current)->toBe(2);
});

it('survives a member whose only award is old, without inventing a run of one', function (): void {
    streakDays($this->owner, [200]);

    $streak = streakOf($this->owner);

    expect($streak->current)->toBe(0)
        ->and($streak->longest)->toBe(1)
        ->and($streak->lastActiveOn?->toDateString())
        ->toBe(Carbon::now(StreakCalculator::DAY_BOUNDARY)->subDays(200)->toDateString());
});
