<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use App\Enums\PointRule;
use App\Models\Submission;
use App\Services\Gamification\GamificationBackfill;
use App\Services\Gamification\PointsRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Give a seeded workspace the award ledger its own submissions imply — Increment K1c.
 *
 * ── ⚠️ WHY THIS EXISTS AT ALL, WHICH IS A DECISION doc #28 §9 LEFT EXPLICITLY TO K1c ───────────────────
 * Both seeders drive `FormService::create()` and `PublishService::publish()` **for real**, so form-shaped
 * points and badges are genuinely earned during seeding. Neither drives `SubmissionPipeline`: they hand-roll
 * their submissions, for five documented reasons of which the first alone settles it (the pipeline
 * hard-codes `submitted_at => now()`, and a fixture needs ninety days of history). So no
 * `SubmissionCreated` is ever raised, no `('submission','created')` audit row is ever written, and a seeded
 * tenant's ledger is **form-shaped only**: publishing badges appear, collection and review badges do not.
 *
 * Three options were on the record and this is the third. **Widening the audit tail to one row per
 * submission is what ADR-0020 §D1 refuses** — it would make what gets audited a scoring decision, taken by
 * somebody not thinking about scoring, in a compliance ledger. **Leaving the demo form-shaped** leaves the
 * product owner testing a feature whose main surface is empty, which is the exact failure §D5's backfill
 * decision exists to prevent. So the seeder becomes an honest caller of the gamification writer for the
 * acts it fabricates — which is the posture it already has toward `FormService`.
 *
 * ⚠️ **THIS IS NOT THE "HAND-SEEDED BADGE" K1b REFUSED.** That refusal was about writing a `badge_awards`
 * row with no ledger behind it — a fixture asserting something the engine would never produce, made
 * permanent by `append_only`. Every row here goes through {@see PointsRecorder::award()}, and every badge
 * is then earned by the real evaluator crossing a real threshold.
 *
 * ⚠️ **`announceBadges: false`, WHICH DIVERGES FROM THE FORM BADGES IN THE SAME FIXTURE — DELIBERATELY.**
 * These are back-dated acts, months old, and the product's rule for back-dated acts is the one K1c's
 * backfill follows: earn them, do not announce them. Announcing here would also move the E2E fixture's
 * `unread_count`, which several Playwright specs assert on and which has nothing to do with gamification.
 */
trait SeedsGamificationLedger
{
    /**
     * Award collection and review for every finalized submission the current workspace already holds.
     *
     * Requires an active tenant context — `Submission` is strict-RLS, so with no GUC this reads nothing and
     * writes nothing, silently. Called from inside each seeder's own tenant block for that reason.
     *
     * Idempotent by construction: `point_awards` is keyed on (tenant, member, rule, subject), so a re-seed
     * finds every row already present. That is what lets it sit beside the seeders' other converge-on-rerun
     * writes without a guard of its own.
     */
    protected function seedGamificationLedger(): void
    {
        $recorder = app(PointsRecorder::class);

        // ⚠️ THE MEMBERSHIP RULES ARE MISSING FOR EXACTLY THE SAME REASON THE SUBMISSION ONES ARE, AND THE
        // FIX IS TO CALL THE REAL BACKFILL RATHER THAN TO WRITE A THIRD COPY OF THE KEYS. Both seeders
        // create `tenant_users` rows directly instead of going through `TenantMembershipService`, so no
        // `MemberJoined` is ever raised — and without this a seeded workspace holds no `welcome` badge at
        // all, which is the one badge whose stated purpose is to keep a new member's page from being blank.
        // `replayMemberships()` reads `joined_at` / `invited_by` / `invited_at` off rows the seeder DID
        // populate honestly, so nothing here is fabricated; measured on a real seed, the demo tenant's one
        // still-pending invitation is correctly left uncredited.
        app(GamificationBackfill::class)->replayMemberships((string) TenantContext::currentTenantId());

        // ⚠️ ORDERED BY `created_at`, NOT BY `id`. The submissions ARE uuidv7, so ordinarily the two agree —
        // but a seeder force-fills `created_at` into the past while the key is minted now, so here they are
        // uncorrelated. Chronological order is what puts a badge's `awarded_at` on the act that genuinely
        // crossed its threshold rather than on whichever row happened to be inserted first.
        $rows = Submission::query()
            ->countable()
            ->orderBy('created_at')
            ->get(['id', 'respondent_user_id', 'created_at', 'validated_by', 'validated_at']);

        foreach ($rows as $submission) {
            // A guest row credits nobody (ADR-0020 §D8) — and the demo fixture is roughly two thirds guest,
            // so this is also what makes the seeded workspace demonstrate §D8 rather than merely obey it.
            if ($submission->respondent_user_id !== null) {
                $recorder->award(
                    PointRule::SubmissionCollected,
                    (string) $submission->respondent_user_id,
                    'submission',
                    (string) $submission->getKey(),
                    self::ledgerInstant($submission->created_at),
                    announceBadges: false,
                );
            }
        }

        foreach ($rows as $submission) {
            // A second pass rather than one interleaved loop, so each RULE is replayed in its own
            // chronological order — which is all a badge needs, because every badge counts exactly one rule.
            if ($submission->validated_by !== null) {
                $recorder->award(
                    PointRule::SubmissionReviewed,
                    (string) $submission->validated_by,
                    'submission',
                    (string) $submission->getKey(),
                    self::ledgerInstant($submission->validated_at ?? $submission->created_at),
                    announceBadges: false,
                );
            }
        }
    }

    /** `awarded_at` is NOT NULL, so a fixture row with no timestamp falls back to the clock rather than failing. */
    private static function ledgerInstant(?Carbon $at): Carbon
    {
        return $at ?? Carbon::now();
    }
}
