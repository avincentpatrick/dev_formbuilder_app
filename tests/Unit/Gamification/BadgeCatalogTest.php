<?php

declare(strict_types=1);

use App\Enums\BadgeKey;
use App\Enums\NotificationType;
use App\Enums\PointRule;

/**
 * The `BadgeKey` catalog facts (Increment K1b) — the {@see NotificationType} unit-test analogue.
 *
 * Unit, so no container and no database: everything asserted here is meant to be answerable without either,
 * which is itself the test that the catalog has not quietly grown a dependency.
 *
 * ⚠️ **THE THRESHOLDS ARE PINNED LITERALLY, AND THAT IS THE WHOLE REASON THIS FILE EXISTS.** K1a's mutation
 * pass found that three of its assertions were worthless because they compared a stored value against **the
 * same enum arm the code had just read** — swap two arms and the code and its expectation move together,
 * green throughout. Every number below is written out, so changing a threshold has to be done twice,
 * deliberately.
 */
it('pins the case order and the catalog, because the DB CHECK is generated from it', function (): void {
    // `badge_awards_badge_check` is built from values() at migration time. A dropped or renamed case
    // silently changes that constraint on the next migrate:fresh, and — unlike NotificationType, whose
    // order is load-bearing for the same reason — a RENAME here would also orphan every row already earned
    // under the old key, which no migration in this increment could repair.
    expect(BadgeKey::values())->toBe([
        'welcome',
        'first_form',
        'first_publish',
        'publisher',
        'first_response',
        'collector',
        'field_veteran',
        'first_review',
        'reviewer',
        'recruiter',
    ]);
});

it('pins every threshold literally, so a re-threshold cannot be a one-line accident', function (): void {
    expect(BadgeKey::Welcome->threshold())->toBe(1)
        ->and(BadgeKey::FirstForm->threshold())->toBe(1)
        ->and(BadgeKey::FirstPublish->threshold())->toBe(1)
        ->and(BadgeKey::Publisher->threshold())->toBe(3)
        ->and(BadgeKey::FirstResponse->threshold())->toBe(1)
        ->and(BadgeKey::Collector->threshold())->toBe(25)
        ->and(BadgeKey::FieldVeteran->threshold())->toBe(250)
        ->and(BadgeKey::FirstReview->threshold())->toBe(1)
        ->and(BadgeKey::Reviewer->threshold())->toBe(50)
        ->and(BadgeKey::Recruiter->threshold())->toBe(5);
});

it('pins each badge to the act it counts', function (): void {
    // Also literal, and for the same reason: `rule()` is what the evaluator indexes the catalog by, so a
    // badge silently re-pointed at another rule would simply stop being earnable by the people it was for.
    expect(BadgeKey::Welcome->rule())->toBe(PointRule::MemberJoined)
        ->and(BadgeKey::FirstForm->rule())->toBe(PointRule::FormCreated)
        ->and(BadgeKey::FirstPublish->rule())->toBe(PointRule::FormPublished)
        ->and(BadgeKey::Publisher->rule())->toBe(PointRule::FormPublished)
        ->and(BadgeKey::FirstResponse->rule())->toBe(PointRule::SubmissionCollected)
        ->and(BadgeKey::Collector->rule())->toBe(PointRule::SubmissionCollected)
        ->and(BadgeKey::FieldVeteran->rule())->toBe(PointRule::SubmissionCollected)
        ->and(BadgeKey::FirstReview->rule())->toBe(PointRule::SubmissionReviewed)
        ->and(BadgeKey::Reviewer->rule())->toBe(PointRule::SubmissionReviewed)
        ->and(BadgeKey::Recruiter->rule())->toBe(PointRule::MemberInvited);
});

it('awards no badge for correcting a response, which is a decision and not a gap', function (): void {
    // ⚠️ THE ABSENCE IS THE ASSERTION. `submission.edited` is the only rule whose act MUTATES COLLECTED
    // EVIDENCE, and it is exactly-once per (editor, submission) — so a threshold of N would require editing
    // N DISTINCT submissions, and the cheapest way to reach it is to open N responses, touch a space and
    // save. A badge would contradict the weight of 1 that PointRule spends a paragraph justifying.
    //
    // Pinned because "we forgot" and "we decided" are indistinguishable in an enum, and the next person to
    // read the catalog will notice the gap before they notice the docblock.
    $counted = array_map(static fn (BadgeKey $badge): PointRule => $badge->rule(), BadgeKey::cases());

    expect($counted)->not->toContain(PointRule::SubmissionEdited);
});

it('announces every badge except the one nobody can fail to earn', function (): void {
    // An exact list rather than a count, the honorsEmailPreference() idiom: if a second silent badge
    // appears it is either a mistake or a decision that needs writing down.
    $silent = array_values(array_filter(
        BadgeKey::cases(),
        static fn (BadgeKey $badge): bool => ! $badge->announces(),
    ));

    expect($silent)->toBe([BadgeKey::Welcome]);
});

it('orders each rule’s tiers by strictly increasing threshold', function (): void {
    // A property rather than today's values, so it survives a re-threshold that the literal pin above would
    // simply be updated for. It matters because the evaluator awards in this order when one act crosses two
    // tiers at once, and both rows are stamped from the same triggering award — so the ORDER is the only
    // thing that says which was earned first.
    foreach (PointRule::cases() as $rule) {
        $thresholds = array_map(
            static fn (BadgeKey $badge): int => $badge->threshold(),
            BadgeKey::forRule($rule),
        );

        $ascending = $thresholds;
        sort($ascending);

        expect($thresholds)->toBe($ascending)
            ->and(count(array_unique($thresholds)))->toBe(count($thresholds));
    }
});

it('indexes the catalog by rule without losing or inventing a badge', function (): void {
    // forRule() is the evaluator's only entry into the catalog, so a filter that dropped a case would make
    // that badge permanently unearnable — silently, because nothing else reads it.
    $indexed = [];

    foreach (PointRule::cases() as $rule) {
        foreach (BadgeKey::forRule($rule) as $badge) {
            $indexed[] = $badge;
        }
    }

    expect(count($indexed))->toBe(count(BadgeKey::cases()));

    // `submission.edited` is the one rule that must map to nothing — the early return that keeps the
    // engine's highest-frequency act from paying for a count it can never use.
    expect(BadgeKey::forRule(PointRule::SubmissionEdited))->toBe([]);
});

it('gives every badge a name and a criterion in this codebase’s voice', function (BadgeKey $badge): void {
    expect($badge->label())->toBeString()->not->toBe('')
        ->and($badge->description())->toBeString()->not->toBe('')
        // A criterion is a sentence, matching PointRule::label()'s register — read as a record of something
        // that happened, never as an instruction, and never shouted.
        ->and($badge->description())->toEndWith('.')
        ->and($badge->label().' '.$badge->description())->not->toContain('!')
        ->and($badge->description())->not->toContain('  ')
        // A threshold of zero would be "earned" by a member who has done nothing, at whatever moment
        // somebody else's award happened to run the evaluator.
        ->and($badge->threshold())->toBeGreaterThanOrEqual(1);
})->with(BadgeKey::cases());

it('gives no two badges the same name, so a shelf of them is scannable', function (): void {
    // The tier ladders are the copy-paste risk: `collector` and `field_veteran` differ by one word in the
    // enum and by a factor of ten in meaning.
    $labels = array_map(static fn (BadgeKey $badge): string => $badge->label(), BadgeKey::cases());

    expect(count(array_unique($labels)))->toBe(count($labels));
});
