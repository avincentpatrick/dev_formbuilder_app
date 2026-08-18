<?php

declare(strict_types=1);

namespace App\Enums;

use App\Events\FormCreated;
use App\Events\MemberJoined;
use App\Models\PointAward;
use App\Services\Gamification\PointsRecorder;

/**
 * The gamification scoring vocabulary (gamification-design.md §3, ADR-0020) — Increment K1a.
 *
 * One case per *earnable act*, valued in {@see points()}. The `point_awards_rule_check` CHECK is generated
 * from {@see values()} so the enum and the constraint cannot drift — the {@see UsageMetric} precedent, and
 * the reason a rule that exists in PHP can never be written to the database unrecognised.
 *
 * ── WHY THIS IS NOT A `DomainEventType` CASE, AND THAT IS THE WHOLE ARCHITECTURAL POINT ─────────────────
 * {@see DomainEventType} doubles as the webhook (`webhook_endpoints.event_types`) and Slack-connector
 * SUBSCRIPTION vocabulary, so a case there is a four-file act **and a published contract forever** — a
 * tenant could subscribe an endpoint to `points.awarded` and we could never withdraw it. Gamification is an
 * internal read-model over acts that ALREADY emit signals, so it consumes those signals and mints nothing
 * subscribable. Where an act has no domain event at all (form creation), the {@see FormCreated}
 * precedent is {@see MemberJoined}: a plain `Dispatchable`, deliberately not a `DomainEvent`.
 *
 * ── THE WEIGHTS ARE A PRODUCT STATEMENT, NOT A CONSTANT TABLE ──────────────────────────────────────────
 * They say what this product is for. Collection out-earns everything over any real period because it is
 * high-frequency and it IS the product (PRD §1.1 — the KoboToolbox half); publishing out-earns creating
 * because a draft nobody can answer has produced nothing yet; editing is worth least because it is
 * correction rather than new evidence. **Re-weighting is retroactive by construction** — `point_awards`
 * stores the points as they were awarded, so changing a weight here moves future awards only, and the
 * ledger keeps saying what it said. That is deliberate: a leaderboard that silently rewrites its own
 * history the day someone edits a constant is not a record of anything.
 *
 * ⚠️ **`SubmissionCollected` IS NOT "A SUBMISSION ARRIVED".** It is awarded only where
 * `submissions.respondent_user_id` is set — a member who encoded, synced or scanned it. A `guest`
 * submission through a public link awards NOBODY, because crediting the form's owner would turn the
 * per-member ladder into a popularity contest decided by whoever published the busiest link. Workspace
 * totals ("team progress") count every submission; the ladder counts collection. This is exactly the
 * *enumerator collection streak* the 2026-08-09 decision of record names.
 */
enum PointRule: string
{
    case FormCreated = 'form.created';
    case FormPublished = 'form.published';
    case SubmissionCollected = 'submission.collected';
    case SubmissionReviewed = 'submission.reviewed';
    case SubmissionEdited = 'submission.edited';
    case MemberInvited = 'member.invited';
    case MemberJoined = 'member.joined';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * What one occurrence of this act is worth.
     *
     * Read once, at award time, and copied into {@see PointAward::$points} by {@see PointsRecorder} — never
     * joined against at read time. See the class docblock for why that is the design and not an oversight.
     */
    public function points(): int
    {
        return match ($this) {
            self::FormPublished => 25,
            self::MemberInvited => 15,
            self::FormCreated => 10,
            self::MemberJoined => 10,
            self::SubmissionCollected => 5,
            self::SubmissionReviewed => 3,
            self::SubmissionEdited => 1,
        };
    }

    /**
     * The human label for an activity row.
     *
     * On the enum for the reason {@see UsageMetric::label()} is: the achievements page, the leaderboard
     * tooltip and any future digest email all name the same act, and a second consumer that invented its
     * own wording is how two screens come to disagree about what a point was for.
     */
    public function label(): string
    {
        return match ($this) {
            self::FormCreated => 'Created a form',
            self::FormPublished => 'Published a form',
            self::SubmissionCollected => 'Collected a response',
            self::SubmissionReviewed => 'Reviewed a response',
            self::SubmissionEdited => 'Corrected a response',
            self::MemberInvited => 'Invited a teammate',
            self::MemberJoined => 'Joined the workspace',
        };
    }

    /*
     * ⚠️ THERE IS DELIBERATELY NO `isRepeatable()` HERE, THOUGH THE FACT IT WOULD STATE IS REAL.
     * Every rule but {@see self::MemberJoined} can be earned repeatedly, and that one is bounded to once
     * per membership. But nothing would CONSULT such a method: repeatability is not a decision any writer
     * makes, it is a consequence of which subject the caller keys on — `member.joined` keys on the member,
     * so the unique index bounds it at one and no PHP branch is involved. A predicate with no consumer is
     * the shape {@see DomainEventType}'s own docblock warns about one level up ("a case with NO emission
     * site is worse than a missing one"): it reads as enforcement, enforces nothing, and invites a future
     * caller to trust it INSTEAD of the index. The property is documented in gamification-design.md §3 and
     * enforced by `PointAwardRlsTest`.
     */
}
