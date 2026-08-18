<?php

declare(strict_types=1);

namespace App\Services\Gamification;

/**
 * What a whole workspace has done, as {@see TeamProgressService} read it (ADR-0020 §D8) — Increment K1c.
 *
 * The inverse of the per-member ladder, and the two are defined AGAINST each other in §D8's single
 * sentence: the ladder answers *"who collected the most data"*, this answers *"what has this workspace
 * achieved"*. Everything here is a plain count, never a percentage — the "N of M" rule J5 established for
 * `MdsProgress` and which K1e renders these through.
 *
 * ── ⚠️ `responses` AND THE SUM OF MEMBERS' `submission.collected` AWARDS DO NOT MATCH, BY DESIGN ────────
 * This is the single most likely thing here to be reported as a bug, so it is stated in the type itself.
 * ADR-0020 §D8 awards `submission.collected` **only** where `submissions.respondent_user_id` is set — a
 * member who encoded, synced or scanned the response — because crediting the form's owner for a public link
 * would turn the ladder into a popularity contest decided by whoever published the busiest form. But a
 * guest response is still a response the workspace collected, so `responses` counts **every** submission.
 * **The difference between the two numbers is exactly the guest submissions**, and on a tenant whose
 * collection is mostly public links that difference is most of the total. Neither number is wrong; they
 * answer different questions, and a surface that puts them side by side has to say which is which.
 */
final readonly class TeamProgress
{
    public function __construct(
        /** Every point every member of this workspace has earned, at the weight it was earned at. */
        public int $points,
        /** Finalized submissions — INCLUDING guest ones. See the class docblock. */
        public int $responses,
        /** Live forms: published and not archived — `App\Models\Form::scopePublished()` is the definition. */
        public int $publishedForms,
        /** Accepted members. Distinct from the billing seat gauge, which also reserves unaccepted invites. */
        public int $activeMembers,
        /** Badges held across the whole workspace, all members and all catalog keys. */
        public int $badges,
        /** How many members have earned anything at all — never more than `activeMembers` + departures. */
        public int $contributors,
    ) {}

    /**
     * The answer off-tenant.
     *
     * Zeroes rather than null, and the reason is {@see TeamProgressService}'s: every read behind this is
     * RLS-filtered, and an RLS-filtered read with no tenant GUC returns no rows rather than failing. Making
     * the empty case an explicit named constructor means the one place that can produce it is a guard that
     * checked, instead of six queries that each quietly found nothing.
     */
    public static function none(): self
    {
        return new self(
            points: 0,
            responses: 0,
            publishedForms: 0,
            activeMembers: 0,
            badges: 0,
            contributors: 0,
        );
    }
}
