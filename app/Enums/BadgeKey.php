<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\BadgeAward;
use App\Services\Gamification\BadgeAwarder;

/**
 * The badge catalog (gamification-design.md §7, ADR-0020) — Increment K1b.
 *
 * One case per *earnable achievement*. Every criterion is the same shape — **N awards of one
 * {@see PointRule}** — read by {@see BadgeAwarder} as `rule()` + `threshold()`. The
 * `badge_awards_badge_check` CHECK is generated from {@see values()} so the enum and the constraint cannot
 * drift, exactly as {@see PointRule} does for its own column.
 *
 * ── ONE CRITERION KIND, AND THE UNIFORMITY IS THE DESIGN ────────────────────────────────────────────────
 * A "total points across all rules" badge and a "streak of N days" badge were both available and both are
 * refused here. Streaks are K1c and do not exist yet; a points-total criterion would be a SECOND criterion
 * vocabulary, and {@see DomainEventType}'s docblock one level up makes the argument this inherits — a second
 * vocabulary for the same question is a thing two readers come to answer differently. With one kind, the
 * evaluator is a single `COUNT` against an index that already exists, and K1c's `audits` backfill earns
 * badges for free by replaying the awards that produce them rather than by re-deriving anything.
 *
 * ── PERSISTED, NEVER DERIVED, WHICH IS THE WHOLE REASON {@see BadgeAward} IS A TABLE ────────────────────
 * "Has this member collected 25 responses?" is derivable from `point_awards` at any moment. **"When did
 * they earn it?" is not** — the answer changes the instant a threshold below moves, and a lowered threshold
 * would retroactively invent an earned-on date that never happened. That is the J5 lesson stated as a
 * table (ADR-0020 Context §4): `GettingStartedChecklist` recomputes done-ness per request and can say a step
 * *is* done while being structurally unable to say *when* it became done. The award row is the only place
 * that fact can live.
 *
 * ── THE SHAPE: A FIRST-TIME BADGE PER ACT, A VOLUME TIER ONLY WHERE VOLUME MEANS SOMETHING ──────────────
 * Collection carries three because it is high-frequency and it IS the product ({@see PointRule}'s weighting
 * argument, PRD §1.1's KoboToolbox half); publishing and reviewing carry two; inviting and joining carry
 * one each. The thresholds are a product statement in the same sense the weights are, and they are pinned
 * LITERALLY by `BadgeCatalogTest` rather than against these methods — a test that asks the enum what the
 * enum says proves nothing.
 *
 * ⚠️ **{@see PointRule::SubmissionEdited} DELIBERATELY EARNS NO BADGE, AND IT IS THE ONE ABSENCE THAT WOULD
 * BE ACTIVELY HARMFUL TO FILL.** The rule is already exactly-once per (editor, submission), so a threshold
 * of N cannot be farmed against one row — it requires editing **N distinct submissions**, and the cheapest
 * way to do that is to open N responses, touch a space and save. That writes N audit rows and bumps
 * `updated_at` on N rows of real research data. It is **the only rule in the vocabulary whose act mutates
 * collected evidence**, and its weight of 1 is this product's recorded opinion of it ("editing is worth
 * least because it is correction rather than new evidence"). A badge would contradict the weight the
 * scoring enum spent a paragraph justifying. Stated here so the catalog does not look merely incomplete to
 * the next person, who would otherwise "finish" it — "we forgot" and "we decided" are indistinguishable in
 * an enum, which is why `BadgeCatalogTest` pins the absence too.
 *
 * ⚠️ **{@see self::Welcome} IS EARNED BY EVERY MEMBER ON DAY ONE, AND THAT IS WHY IT IS HERE.**
 * `member.joined` is the one bounded rule — one award per membership, ever — so this badge cannot be farmed
 * and cannot be missed. It exists so the achievements surface is non-empty the first time anybody opens it
 * (PRD §3.7's frictionless principle), and because after K1c it is the per-member row that demonstrates the
 * `audits` backfill actually ran rather than merely reporting that it did.
 */
enum BadgeKey: string
{
    case Welcome = 'welcome';
    case FirstForm = 'first_form';
    case FirstPublish = 'first_publish';
    case Publisher = 'publisher';
    case FirstResponse = 'first_response';
    case Collector = 'collector';
    case FieldVeteran = 'field_veteran';
    case FirstReview = 'first_review';
    case Reviewer = 'reviewer';
    case Recruiter = 'recruiter';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The act this badge counts.
     *
     * {@see BadgeAwarder} evaluates only the badges whose rule matches the award it was just handed, so this
     * is the index into the catalog rather than a description of it — a badge naming a rule nothing awards
     * would simply never be earned, silently.
     */
    public function rule(): PointRule
    {
        return match ($this) {
            self::Welcome => PointRule::MemberJoined,
            self::FirstForm => PointRule::FormCreated,
            self::FirstPublish, self::Publisher => PointRule::FormPublished,
            self::FirstResponse, self::Collector, self::FieldVeteran => PointRule::SubmissionCollected,
            self::FirstReview, self::Reviewer => PointRule::SubmissionReviewed,
            self::Recruiter => PointRule::MemberInvited,
        };
    }

    /**
     * How many awards of {@see self::rule()} earn it.
     *
     * ⚠️ Every value is `>= 1`. A zero threshold would be earned by a member who has done nothing, at the
     * moment somebody else's award happened to run the evaluator — which is not "achieved" in any sense, and
     * `BadgeCatalogTest` pins the floor rather than trusting this comment.
     */
    public function threshold(): int
    {
        return match ($this) {
            self::Welcome, self::FirstForm, self::FirstPublish, self::FirstResponse, self::FirstReview => 1,
            // ⚠️ THREE, NOT TEN, AND THE FIRST NUMBER WRITTEN HERE WAS TEN. A workspace is a handful of
            // forms, not hundreds — the seeded demo tenant has SEVEN in total — so a publishing tier of ten
            // is unreachable by any member of a workspace of this product's actual shape, which makes it not
            // a tier but a dead rung. Three says "this workspace is running", which is the fact worth
            // marking. Corrected against the fixture rather than left as a round number that felt right.
            self::Publisher => 3,
            self::Recruiter => 5,
            self::Collector => 25,
            self::Reviewer => 50,
            self::FieldVeteran => 250,
        };
    }

    /**
     * The badge's name, as the earner sees it.
     *
     * On the enum for the reason {@see PointRule::label()} is: the achievements surface, the notification
     * row and its email all name the same badge, and a second consumer that invented its own wording is how
     * two screens come to disagree about what somebody earned.
     */
    public function label(): string
    {
        return match ($this) {
            self::Welcome => 'Welcome',
            self::FirstForm => 'First form',
            self::FirstPublish => 'First publish',
            self::Publisher => 'Publisher',
            self::FirstResponse => 'First response',
            self::Collector => 'Collector',
            self::FieldVeteran => 'Field veteran',
            self::FirstReview => 'First review',
            self::Reviewer => 'Reviewer',
            self::Recruiter => 'Recruiter',
        };
    }

    /**
     * What the earner did to get it, as a complete sentence.
     *
     * Past tense and terminated, matching {@see PointRule::label()}'s voice, because both are read as a
     * record of something that happened rather than as an instruction. Consumed today by the notification
     * email's body; K1e's achievements surface is its second reader.
     *
     * ⚠️ The numbers are spelled out rather than interpolated from {@see self::threshold()}. Interpolating
     * would make the sentence and the constant impossible to disagree — which sounds like a virtue and is
     * the exact property that made three of K1a's assertions worthless: they compared a stored value against
     * the same enum arm the code had just read. Spelled out, changing a threshold forces the copy to be
     * changed deliberately, and `BadgeCatalogTest` pins both halves separately.
     */
    public function description(): string
    {
        return match ($this) {
            self::Welcome => 'Joined the workspace.',
            self::FirstForm => 'Created a form for the first time.',
            self::FirstPublish => 'Published a form so it could start collecting.',
            self::Publisher => 'Published three forms.',
            self::FirstResponse => 'Collected a response.',
            self::Collector => 'Collected twenty-five responses.',
            self::FieldVeteran => 'Collected two hundred and fifty responses.',
            self::FirstReview => 'Reviewed a response.',
            self::Reviewer => 'Reviewed fifty responses.',
            self::Recruiter => 'Invited five teammates.',
        };
    }

    /**
     * Whether earning this badge raises a {@see NotificationType::BadgeEarned} row.
     *
     * False for exactly one case, and the reason is that {@see self::Welcome}'s criterion **cannot be
     * failed**: `member.joined` is bounded at one per membership, so this badge lands the instant somebody
     * arrives — in the noisiest minute of a new member's life, telling a person who just joined that they
     * have joined. It is still **awarded**, because it carries an earned-on date nothing else in the schema
     * exposes to the member (`tenant_users.joined_at` has no reader) and because it is what keeps a
     * brand-new achievements surface from being blank.
     *
     * ⚠️ **THIS IS NOT THE `PointRule::isRepeatable()` SHAPE, AND THE DIFFERENCE IS THE WHOLE TEST.** That
     * predicate was refused because **nothing would ever consult it** — it read as enforcement while
     * enforcing nothing, and invited a caller to trust it instead of the index. This one is consulted by
     * {@see BadgeAwarder} and changes behaviour: flip it and a notification appears. A predicate with a
     * consumer is a branch; a predicate without one is a decoration.
     */
    public function announces(): bool
    {
        return $this !== self::Welcome;
    }

    /**
     * Every badge earned by `$rule`, cheapest-first.
     *
     * Ordered by threshold so that a single award crossing two tiers at once awards them in the order they
     * were earned — which matters because {@see BadgeAward::$awarded_at} is written from the triggering
     * award and two rows would otherwise share an instant with no tie-break for a reader to trust.
     *
     * @return list<self>
     */
    public static function forRule(PointRule $rule): array
    {
        $matching = array_values(array_filter(
            self::cases(),
            static fn (self $badge): bool => $badge->rule() === $rule,
        ));

        usort($matching, static fn (self $a, self $b): int => $a->threshold() <=> $b->threshold());

        return $matching;
    }
}
