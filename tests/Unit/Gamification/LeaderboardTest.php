<?php

declare(strict_types=1);

use App\Services\Gamification\Leaderboard;
use App\Services\Gamification\LeaderboardEntry;
use App\Services\Gamification\LeaderboardService;
use App\Services\Gamification\MemberStanding;
use App\Services\Gamification\MemberStreak;

/**
 * The ranking RULE (Increment K1d) — the {@see MemberStreak} unit-test analogue.
 *
 * K1c split its streak the same way and for the same reason: {@see LeaderboardService} owns the SQL and
 * {@see Leaderboard} owns the rule, so the part with ties, skips, a denominator and an empty case in it can
 * be exercised exhaustively with no container and no database.
 *
 * ⚠️ **WHAT THESE CASES ARE SHAPED TO DISCRIMINATE, WHICH IS NOT THE SAME AS WHAT THEY ASSERT.** K1c's
 * mutation pass found two assertions that agreed with the code by accident, so every fixture below is
 * chosen for what it can TELL APART:
 *
 *   - A tie at the TOP cannot separate competition ranking from dense ranking (both open 1, 1, …), and
 *     neither can two members. It takes a tie in the MIDDLE with somebody below it — 1, 2, 2, **4** under
 *     the rule this product wants, 1, 2, 2, 3 under the one it does not.
 *   - A roster where everybody has scored cannot show that the denominator is the TEAM rather than the
 *     scoreboard. It takes members with nothing at all.
 *   - A points map containing only roster members cannot show that a departed member is dropped.
 */

/** A roster row, so the fixtures below read as people rather than as tuples. */
function rosterMember(string $id, string $name): array
{
    return ['id' => $id, 'name' => $name];
}

/**
 * @param  list<LeaderboardEntry>  $entries
 * @return list<int>
 */
function ranksOf(array $entries): array
{
    return array_map(static fn (LeaderboardEntry $entry): int => $entry->rank, $entries);
}

/**
 * @param  list<LeaderboardEntry>  $entries
 * @return list<string>
 */
function namesOf(array $entries): array
{
    return array_map(static fn (LeaderboardEntry $entry): string => $entry->name, $entries);
}

/*
|--------------------------------------------------------------------------
| Competition ranking — ties share a place and the NEXT one skips it
*/

it('skips the rank after a tie, which is what "4th of 12" has to mean', function (): void {
    // ⚠️ THE TIE IS IN THE MIDDLE ON PURPOSE. Dense ranking would give 1, 2, 2, 3 here and is
    // indistinguishable from this rule on any fixture whose tie sits at the top or has nothing below it.
    $board = Leaderboard::fromRoster(
        [rosterMember('a', 'Ada'), rosterMember('b', 'Bea'), rosterMember('c', 'Cal'), rosterMember('d', 'Dee')],
        ['a' => 50, 'b' => 30, 'c' => 30, 'd' => 10],
        [],
    );

    expect(ranksOf($board->entries))->toBe([1, 2, 2, 4])
        ->and(namesOf($board->entries))->toBe(['Ada', 'Bea', 'Cal', 'Dee']);
});

it('gives every member of an all-tied workspace first place', function (): void {
    $board = Leaderboard::fromRoster(
        [rosterMember('a', 'Ada'), rosterMember('b', 'Bea'), rosterMember('c', 'Cal')],
        ['a' => 7, 'b' => 7, 'c' => 7],
        [],
    );

    expect(ranksOf($board->entries))->toBe([1, 1, 1]);
});

it('orders a tie by name so two identical requests cannot disagree', function (): void {
    // Insertion order is deliberately the REVERSE of the expected order: a comparator that fell through to
    // "leave them where they were" would pass a fixture that arrived already sorted.
    $board = Leaderboard::fromRoster(
        [rosterMember('z', 'Zoe'), rosterMember('a', 'Ada')],
        ['z' => 5, 'a' => 5],
        [],
    );

    expect(namesOf($board->entries))->toBe(['Ada', 'Zoe']);
});

it('ranks on points and never on the order it was handed', function (): void {
    $board = Leaderboard::fromRoster(
        [rosterMember('a', 'Ada'), rosterMember('b', 'Bea')],
        ['a' => 1, 'b' => 99],
        [],
    );

    expect(namesOf($board->entries))->toBe(['Bea', 'Ada'])
        ->and(ranksOf($board->entries))->toBe([1, 2]);
});

/*
|--------------------------------------------------------------------------
| The denominator is the TEAM, not the scoreboard
*/

it('keeps a member who has earned nothing on the ladder, ranked last rather than absent', function (): void {
    // "You are not on the board" is a worse answer than "you are fifth of five", and a denominator that
    // counted only scorers would make somebody's own position move on a day they did nothing.
    $board = Leaderboard::fromRoster(
        [
            rosterMember('a', 'Ada'), rosterMember('b', 'Bea'), rosterMember('c', 'Cal'),
            rosterMember('d', 'Dee'), rosterMember('e', 'Eve'),
        ],
        ['a' => 40, 'b' => 20],
        [],
    );

    expect($board->memberCount)->toBe(5)
        ->and($board->entries)->toHaveCount(5)
        ->and(ranksOf($board->entries))->toBe([1, 2, 3, 3, 3])
        ->and($board->standingFor('e')->of)->toBe(5)
        ->and($board->standingFor('e')->points)->toBe(0);
});

it('drops a points entry belonging to nobody on the roster — the departed member', function (): void {
    // `point_awards` is append-only, so a removed member's rows outlive their membership. The roster is
    // what narrows the ladder; the ledger cannot add to it.
    $board = Leaderboard::fromRoster(
        [rosterMember('a', 'Ada')],
        ['a' => 10, 'gone' => 999],
        ['gone' => 4],
    );

    expect($board->entries)->toHaveCount(1)
        ->and($board->memberCount)->toBe(1)
        ->and($board->entries[0]->userId)->toBe('a')
        // The high scorer is ABSENT rather than first, which is the direction that would be a disclosure.
        ->and($board->standingFor('gone'))->toEqual(MemberStanding::none());
});

/*
|--------------------------------------------------------------------------
| Badges ride along without touching the ranking
*/

it('carries badge counts per member without letting them influence the rank', function (): void {
    // ⚠️ The badge-heavy member is the LOWER scorer on purpose: if badges ever leaked into the ordering,
    // this fixture inverts and the test says so.
    $board = Leaderboard::fromRoster(
        [rosterMember('a', 'Ada'), rosterMember('b', 'Bea')],
        ['a' => 50, 'b' => 10],
        ['b' => 9],
    );

    expect(namesOf($board->entries))->toBe(['Ada', 'Bea'])
        ->and($board->entries[0]->badges)->toBe(0)
        ->and($board->entries[1]->badges)->toBe(9);
});

/*
|--------------------------------------------------------------------------
| Standing, and the empty cases
*/

it('reports a members own standing from the same ranking the list is built from', function (): void {
    $board = Leaderboard::fromRoster(
        [rosterMember('a', 'Ada'), rosterMember('b', 'Bea'), rosterMember('c', 'Cal')],
        ['a' => 30, 'b' => 20, 'c' => 10],
        ['b' => 2],
    );

    $standing = $board->standingFor('b');

    expect($standing->rank)->toBe(2)
        ->and($standing->of)->toBe(3)
        ->and($standing->points)->toBe(20)
        ->and($standing->badges)->toBe(2);
});

it('has no standing for someone who is not on the ladder, and says so with null rather than 0th', function (): void {
    $board = Leaderboard::fromRoster([rosterMember('a', 'Ada')], ['a' => 5], []);

    // Zero would render as a position. Null is the one value that cannot be mistaken for one.
    expect($board->standingFor('nobody')->rank)->toBeNull()
        ->and($board->standingFor('nobody')->of)->toBe(0);
});

it('is empty rather than null-ish for a workspace with no active members', function (): void {
    expect(Leaderboard::none()->entries)->toBe([])
        ->and(Leaderboard::none()->memberCount)->toBe(0)
        ->and(Leaderboard::none()->standingFor('a')->rank)->toBeNull();
});

it('ranks a lone member first of one, with no points map at all', function (): void {
    $board = Leaderboard::fromRoster([rosterMember('a', 'Ada')], [], []);

    expect($board->entries[0]->rank)->toBe(1)
        ->and($board->entries[0]->points)->toBe(0)
        ->and($board->standingFor('a')->of)->toBe(1);
});
