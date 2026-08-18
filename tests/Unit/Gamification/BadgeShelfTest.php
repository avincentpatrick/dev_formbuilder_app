<?php

declare(strict_types=1);

use App\Enums\BadgeKey;
use App\Enums\PointRule;
use App\Services\Gamification\BadgeShelf;
use App\Services\Gamification\BadgeStanding;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| K1e — the badge shelf's ASSEMBLY AND ORDERING RULE, with no database at all. The reads live in
| tests/Feature/Gamification/BadgeShelfServiceTest.php; everything here is a pure function, which is the
| whole reason the rule was put in a value object rather than in the service.
|
| WHAT CLASS OF BUG THIS FILE EXISTS TO CATCH, in the order the bugs would actually happen:
|
|  1. EARNED-NESS DERIVED FROM THE COUNT. `earned = progress >= threshold` is the obvious shape and it is
|     wrong: ADR-0020 §D9 keeps a badge's key and date so that RE-THRESHOLDING MOVES FUTURE EARNINGS ONLY.
|     Raise Collector from 25 to 40 and everyone who earned it at 25 still holds it, with progress now
|     BELOW threshold. Deriving the flag silently un-awards all of them while their ledger rows sit there.
|  2. THE CATALOG DRIVEN FROM THE ROWS. Walking the award rows instead of BadgeKey::cases() makes an
|     unearned badge ABSENT rather than at zero — and a surface that cannot name what you have not earned
|     yet has stopped being an achievements surface.
|  3. AN ORDERING THAT RESHUFFLES. Both comparators fall through to catalog position precisely so two
|     identical requests cannot return two different lists; a comparator that stops at the first key leaves
|     the tail to whatever order the caller happened to pass.
|  4. THE ORDER BEING WRONG FOR THE READER WHO MATTERS MOST — a brand-new member, for whom every remainder
|     equals its own threshold and the five one-act badges tie.
*/

/** A shelf built from raw maps, which is exactly what the service hands it. */
function shelf(array $earnedOn = [], array $countsByRule = []): BadgeShelf
{
    return BadgeShelf::assemble($earnedOn, $countsByRule);
}

function at(string $iso): CarbonImmutable
{
    return CarbonImmutable::parse($iso);
}

/** @return list<string> the badge keys of a list of standings, in order */
function keysOf(array $standings): array
{
    return array_map(static fn (BadgeStanding $s): string => $s->badge->value, $standings);
}

/*
|--------------------------------------------------------------------------
| The catalog is the catalog
*/

it('places every badge in the catalog on exactly one of the two lists', function (): void {
    $shelf = shelf([BadgeKey::Welcome->value => at('2026-08-01')], [PointRule::MemberJoined->value => 1]);

    // ⚠️ THE PROPERTY THAT MAKES THE PAGE's EMPTY STATE REACHABLE OR DEAD, and the reason it is asserted
    // here rather than assumed: the two lists ALWAYS sum to the catalog, so "both halves empty" is not a
    // state an on-tenant reader can be in. The page's first draft guarded on exactly that and shipped an
    // unreachable empty state; this case is what would have said so.
    expect(count($shelf->earned) + count($shelf->inProgress))->toBe(count(BadgeKey::cases()));

    $all = [...keysOf($shelf->earned), ...keysOf($shelf->inProgress)];
    sort($all);
    $catalog = BadgeKey::values();
    sort($catalog);

    expect($all)->toBe($catalog);
});

it('lists a badge nobody has earned at zero rather than omitting it', function (): void {
    $shelf = shelf();

    expect($shelf->earned)->toBe([])
        ->and(keysOf($shelf->inProgress))->toContain(BadgeKey::FieldVeteran->value);

    $veteran = collect($shelf->inProgress)->firstWhere(fn (BadgeStanding $s) => $s->badge === BadgeKey::FieldVeteran);

    expect($veteran->progress)->toBe(0)
        ->and($veteran->threshold)->toBe(250)
        ->and($veteran->isEarned())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Earned-ness comes from the ledger, never from the count
*/

it('keeps a badge earned when a RAISED threshold now exceeds the progress that earned it', function (): void {
    // Collector's threshold is 25. This member holds the badge and has 12 collections on record — the
    // state ADR-0020 §D9 describes after a re-threshold, and the state a derived flag would misread.
    $shelf = shelf(
        [BadgeKey::Collector->value => at('2026-07-01')],
        [PointRule::SubmissionCollected->value => 12],
    );

    $collector = collect($shelf->earned)->firstWhere(fn (BadgeStanding $s) => $s->badge === BadgeKey::Collector);

    expect($collector)->not->toBeNull()
        ->and($collector->isEarned())->toBeTrue()
        ->and($collector->progress)->toBe(12)
        ->and($collector->threshold)->toBe(25)
        ->and(keysOf($shelf->inProgress))->not->toContain(BadgeKey::Collector->value);
});

it('leaves a badge unearned when the count already qualifies but no award row exists', function (): void {
    // The inverse, and it is not hypothetical: this is every member of every workspace before K1c's
    // backfill ran — qualifying acts on the ledger, no badge row yet.
    $shelf = shelf([], [PointRule::SubmissionCollected->value => 40]);

    $collector = collect($shelf->inProgress)->firstWhere(fn (BadgeStanding $s) => $s->badge === BadgeKey::Collector);

    expect($shelf->earned)->toBe([])
        ->and($collector->isEarned())->toBeFalse()
        ->and($collector->progress)->toBe(40)
        ->and($collector->remaining())->toBe(0);
});

it('never reports a negative remainder', function (): void {
    // `remaining()` floors at zero, because "-15 to go" is a sentence with no true reading. The case above
    // proves the floor engages; this one pins that it does not simply return the difference.
    $standing = BadgeStanding::unearned(BadgeKey::FirstResponse, 16);

    expect($standing->remaining())->toBe(0)
        ->and($standing->progress)->toBe(16);
});

it('does not clamp progress to the threshold, because the raw count is a fact about the member', function (): void {
    $shelf = shelf([], [PointRule::SubmissionCollected->value => 40]);

    $first = collect($shelf->inProgress)->firstWhere(fn (BadgeStanding $s) => $s->badge === BadgeKey::FirstResponse);

    // FirstResponse's threshold is 1; the member has forty. Clamping here would destroy the number
    // FieldVeteran (250) still needs from the same rule.
    expect($first->progress)->toBe(40)
        ->and($first->threshold)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Ordering — earned
*/

it('lists earned badges most recently earned first', function (): void {
    $shelf = shelf([
        BadgeKey::Welcome->value => at('2026-01-01'),
        BadgeKey::FirstForm->value => at('2026-06-15'),
        BadgeKey::FirstPublish->value => at('2026-03-02'),
    ], []);

    expect(keysOf($shelf->earned))->toBe([
        BadgeKey::FirstForm->value,
        BadgeKey::FirstPublish->value,
        BadgeKey::Welcome->value,
    ]);
});

it('breaks a same-instant tie by catalog order, which is the order they were awarded in', function (): void {
    // ⚠️ NOT A CONTRIVED TIE. BadgeAwarder stamps every badge it creates in one call with the TRIGGERING
    // AWARD's timestamp, so one collection crossing two tiers writes two rows sharing an instant to the
    // microsecond. BadgeKey::forRule() awards them cheapest-first, and falling through to catalog position
    // is what reproduces that here instead of leaving the pair to whatever order the rows came back in.
    $sameInstant = at('2026-08-18T09:30:00Z');

    $shelf = shelf([
        BadgeKey::Collector->value => $sameInstant,
        BadgeKey::FirstResponse->value => $sameInstant,
    ], []);

    expect(keysOf($shelf->earned))->toBe([
        BadgeKey::FirstResponse->value,
        BadgeKey::Collector->value,
    ]);
});

/*
|--------------------------------------------------------------------------
| Ordering — in progress
*/

it('lists unearned badges by what is LEFT rather than by threshold', function (): void {
    // ⚠️ THE PAIR IS CHOSEN SO THE TWO RULES DISAGREE, WHICH THE FIRST DRAFT OF THIS CASE DID NOT DO.
    // Collector needs 25 and this member has 22, so it is THREE away. Recruiter needs only 5 and this
    // member has invited nobody, so it is FIVE away. Ordering by threshold would put the cheaper Recruiter
    // first; ordering by what is left puts Collector first, which is the rule and is also what a person
    // means by "closest". A pair where the nearer badge is also the cheaper one would pass under either
    // rule and prove nothing.
    $shelf = shelf([], [PointRule::SubmissionCollected->value => 22]);

    $keys = keysOf($shelf->inProgress);

    expect(array_search(BadgeKey::Collector->value, $keys, true))
        ->toBeLessThan(array_search(BadgeKey::Recruiter->value, $keys, true));
});

it('opens on the one-act badges for a brand-new member, not on the 250-response one', function (): void {
    // ⚠️ THE CASE THE THRESHOLD TIE-BREAK EXISTS FOR, and it is every member on day one. With nothing done,
    // each remainder equals its own threshold, so the five threshold-1 badges all tie at 1 — and without
    // the threshold comparison the shelf could open on FieldVeteran's 250.
    $shelf = shelf();

    $firstFive = array_slice(keysOf($shelf->inProgress), 0, 5);
    sort($firstFive);

    $oneAct = array_values(array_map(
        static fn (BadgeKey $b): string => $b->value,
        array_filter(BadgeKey::cases(), static fn (BadgeKey $b): bool => $b->threshold() === 1),
    ));
    sort($oneAct);

    expect($firstFive)->toBe($oneAct)
        ->and(array_slice(keysOf($shelf->inProgress), -1))->toBe([BadgeKey::FieldVeteran->value]);
});

it('orders two equally distant badges by the cheaper threshold, against catalog order', function (): void {
    // ⚠️ THE PAIR IS CHOSEN SO THE THRESHOLD RULE AND THE CATALOG-POSITION FALLBACK DISAGREE — the first
    // draft picked Publisher vs Recruiter, where the cheaper badge is ALSO the earlier-declared one, so it
    // would have stayed green with the threshold comparison deleted. That is an assertion passing for the
    // wrong reason, which is the thing a tie-break test exists to rule out.
    //
    // With two publishes: Publisher (threshold 3) is one away, and FirstResponse (threshold 1, no
    // collections) is also one away. They tie on remainder. FirstResponse is CHEAPER and is declared
    // LATER — `BadgeKey::cases()` runs …FirstPublish, Publisher, FirstResponse… — so the threshold rule
    // and the fallback pull in opposite directions and only one of them can be producing this order.
    $shelf = shelf([], [PointRule::FormPublished->value => 2]);

    $keys = keysOf($shelf->inProgress);

    expect(array_search(BadgeKey::FirstResponse->value, $keys, true))
        ->toBeLessThan(array_search(BadgeKey::Publisher->value, $keys, true));
});

/*
|--------------------------------------------------------------------------
| The empty shelf
*/

it('has an explicit empty shelf that is not the same thing as a member who has earned nothing', function (): void {
    // `none()` is the OFF-TENANT answer and holds no badges at all. A member who has earned nothing holds
    // the whole catalog at zero. Collapsing the two would make "you have done nothing" and "nobody
    // established tenant context" the same output — the read-side twin of the silent RLS write refusal.
    expect(BadgeShelf::none()->earned)->toBe([])
        ->and(BadgeShelf::none()->inProgress)->toBe([])
        ->and(shelf()->inProgress)->toHaveCount(count(BadgeKey::cases()));
});
