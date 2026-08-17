<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Enums\PointRule;
use App\Events\MemberInvited;
use App\Models\Concerns\BelongsToTenant;
use App\Models\PointAward;
use App\Services\Entitlements\EntitlementService;
use App\Services\Entitlements\UsageMeter;
use App\Support\Entitlements\ToggleableModules;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantExtractColumns;
use App\Support\Tenancy\TenantScopedTables;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only writer of {@see PointAward} (gamification-design.md §5, ADR-0020) — Increment K1a.
 *
 * Called from the synchronous listeners under `App\Listeners\Gamification`, and later from K1c's `audits`
 * backfill. Awarding is **never allowed to break the act it is scoring**: the whole body is a
 * swallow-and-log guard on the {@see UsageMeter} precedent, because a respondent's submission or a member's
 * form must persist even if the scoreboard throws.
 *
 * ── ⚠️ `ON CONFLICT DO NOTHING`, AND THE REASON IS NOT MERELY "IDEMPOTENCY IS NICE" ─────────────────────
 * The obvious shapes are `firstOrCreate` (a TOCTOU window between the SELECT and the INSERT) or a
 * `try/catch` on 23505. **The second one is actively dangerous here.** In PostgreSQL a constraint violation
 * aborts the *whole* transaction, so a caught 23505 leaves the enclosing transaction poisoned — every
 * subsequent statement fails with `current transaction is aborted`. That is not hypothetical for this
 * service: `FormService::create()` is itself called from `TemplateService` inside an outer transaction, so a
 * duplicate award raised there would take out a template instantiation that had nothing to do with
 * gamification. `ON CONFLICT DO NOTHING` never raises, so there is no exception to catch and nothing to
 * poison — the duplicate is refused by the index and the caller never learns of it.
 *
 * ── ⚠️ RAW SQL, WHICH BYPASSES {@see BelongsToTenant} ON PURPOSE ───────────────────
 * `tenant_id` is therefore passed EXPLICITLY, which is what satisfies the strict `WITH CHECK` policy — the
 * {@see UsageMeter::increment()} precedent, and the same reasoning: the trait's auto-fill and the raw path
 * are alternatives, and the failure mode of getting this wrong is the measured one in this project (an
 * INSERT that matches no policy). Note the asymmetry that makes it safe: an RLS **INSERT** refusal RAISES
 * (`42501`), it does not silently affect zero rows the way a filtered UPDATE does — so a mis-scoped write
 * here lands in the log below rather than disappearing. `tests/Feature/Gamification/PointAwardRlsTest.php`
 * pins both halves.
 *
 * ── WHAT "0 ROWS" MEANS, WHICH IS THE ONE THING A CALLER MUST NOT CONFLATE ─────────────────────────────
 * {@see self::award()} returns `false` for three different facts — the module is off, the act was already
 * awarded, and the write failed — and no caller currently distinguishes them, because no caller acts on the
 * answer. It is returned rather than voided so the K1c backfill can COUNT what it actually created, and so
 * badge evaluation runs only on a genuinely new award rather than on every replay.
 *
 * ⚠️ **AS OF K1b THAT SENTENCE IS LOAD-BEARING RATHER THAN ASPIRATIONAL, AND IT CONSTRAINS THIS FILE.**
 * {@see BadgeAwarder} is called below on the true path only, which is what stops a member's thousandth
 * response from re-offering every badge in the catalog. The call sits **outside** the `try`, after the
 * return value is settled — a badge failure must not be able to turn a successful award into `false`,
 * because the backfill counts that boolean and would then under-report against a ledger that is correct.
 */
final class PointsRecorder
{
    /** The plan/module key gating the whole engine. Granted on every tier; see {@see ToggleableModules}. */
    public const string FEATURE = 'gamification';

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly BadgeAwarder $badges,
    ) {}

    /**
     * Award `$rule` to `$userId` for `$subjectType`/`$subjectId`, exactly once.
     *
     * Returns true only when a row was genuinely created. A no-op off-tenant, with no user (a `guest`
     * submission credits nobody — see {@see PointRule}), or with the module switched off.
     *
     * `$awardedAt` defaults to now and is passed explicitly by the backfill, which is replaying acts that
     * happened months ago; a streak computed from `created_at` would read every historical workspace as one
     * enormous one-day streak on install day.
     *
     * `$announceBadges` is forwarded to {@see BadgeAwarder} untouched — see that class for whose decision it
     * is. It does not affect points, which are never notified at all.
     */
    public function award(
        PointRule $rule,
        ?string $userId,
        string $subjectType,
        string $subjectId,
        ?Carbon $awardedAt = null,
        bool $announceBadges = true,
    ): bool {
        $tenantId = TenantContext::currentTenantId();

        if ($tenantId === null || $userId === null || $userId === '') {
            return false;
        }

        if (! $this->entitlements->feature(self::FEATURE)) {
            return false;
        }

        $at = $awardedAt ?? Carbon::now();

        try {
            $affected = DB::affectingStatement(
                'INSERT INTO point_awards '
                .'(tenant_id, user_id, rule, points, subject_type, subject_id, awarded_at, created_at, updated_at) '
                .'VALUES (?, ?, ?, ?, ?, ?, ?, now(), now()) '
                .'ON CONFLICT (tenant_id, user_id, rule, subject_type, subject_id) DO NOTHING',
                [
                    $tenantId,
                    $userId,
                    $rule->value,
                    $rule->points(),
                    $subjectType,
                    $subjectId,
                    $at->toIso8601String(),
                ],
            );
        } catch (Throwable $e) {
            // Scoring must never surface into the caller. Logged rather than rethrown — and logged at
            // WARNING rather than DEBUG precisely because the RLS refusal described above arrives here.
            Log::warning('Point award failed.', [
                'rule' => $rule->value,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'subject_type' => $subjectType,
                'exception_class' => $e::class,
            ]);

            return false;
        }

        if ($affected !== 1) {
            return false;
        }

        // ⚠️ DELIBERATELY OUTSIDE THE `try` ABOVE, AND THE PLACEMENT IS THE POINT (K1b). The answer this
        // method returns is already settled here, so nothing badges do can change it — which matters because
        // that answer is COUNTED by K1c's backfill, and a badge failure folding into the recorder's
        // swallow-and-log would make a correct ledger report itself as incomplete. BadgeAwarder is total by
        // contract and holds its own SAVEPOINT, so this can neither throw nor poison the caller.
        //
        // ⚠️ AND IT MUST STAY HERE RATHER THAN MOVING POST-COMMIT OR ONTO A QUEUE. TenantContext is a PHP
        // static mirror, not the GUC — `applyLocal()`'s SET LOCAL dies at commit while the mirror survives —
        // and badge evaluation opens with an RLS-FILTERED READ, which returns zero rows silently when the
        // GUC is unset. A successful INSERT under the strict WITH CHECK policy above is machine-proof that
        // the GUC still equals $tenantId; that proof expires the moment this call moves off this connection.
        $this->badges->evaluate($tenantId, $userId, $rule, $at, $announceBadges);

        return true;
    }

    /**
     * The idempotency key for an act identified by an email address rather than a row id.
     *
     * {@see MemberInvited} carries an email and no user id — an invitee need not have a `users`
     * row the listener can see — so `member.invited` keys on a digest of the address. Hashed rather than
     * stored, because an email address has no business sitting in a scoreboard table, and normalized first
     * so that re-inviting `Sam@Example.com` cannot farm a second award over `sam@example.com`.
     *
     * ── ⚠️ THE TENANT ID IS PART OF THE DIGEST, AND IT IS NOT THERE FOR UNIQUENESS ─────────────────────
     * The unique index already carries `tenant_id`, so scoping the hash buys nothing on that front. It is
     * there because **an unsalted digest of an email is a globally stable join key**, and `point_awards` is
     * an extracted table ({@see TenantScopedTables}). Two tenants each holding an
     * extract could join on the bare digest and prove they invited the same person — which is verbatim the
     * argument {@see TenantExtractColumns} makes for withholding `users.google_id`,
     * and it calls that "the one cross-tenant fact this architecture exists to withhold". Salting with the
     * tenant makes the column meaningless outside the workspace that produced it, which is what lets the
     * whole row be extracted rather than censored.
     *
     * ⚠️ The tenant is an EXPLICIT ARGUMENT rather than read from `TenantContext` inside. A context read
     * would make the function silently produce a different digest depending on when it was called, and the
     * symptom would be duplicate awards rather than an error — the K1c backfill runs per-tenant inside a
     * job, and that is exactly where an ambient read goes wrong. Every caller has `$event->tenantId` or its
     * own `$tenantId` to hand, so the argument costs nothing.
     *
     * Lives here rather than in the listener because K1c's backfill must produce the identical key from the
     * `audits` ledger — two copies of a hash rule is two chances to disagree.
     */
    public static function emailSubject(string $tenantId, string $email): string
    {
        return hash('sha256', $tenantId.':'.mb_strtolower(trim($email)));
    }
}
