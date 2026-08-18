<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Enums\TenantUserStatus;
use App\Models\Form;
use App\Models\Submission;
use App\Models\TenantUser;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Workspace-wide totals — the other half of ADR-0020 §D8 (gamification-design.md §8) — Increment K1c.
 *
 * ── ⚠️ ONE READ OF THE AMBIENT TENANT, USED BY BOTH HALVES, AND THAT IS THE WHOLE STRUCTURE ────────────
 * This class mixes two query styles by necessity: the ledger totals are raw SQL (the `PointsRecorder` /
 * `BadgeAwarder` posture — those two tables are only ever addressed that way), while responses,
 * forms and members go through Eloquent so they reuse `Submission::scopeCountable()` and
 * `Form::scopePublished()` rather than inventing second answers to questions this codebase has already
 * settled once. Eloquent's `BelongsToTenant` scopes to the **ambient** tenant; raw SQL scopes to whatever
 * id it is handed. **Those two must not be able to disagree**, which is exactly the hazard K1b's
 * adversarial pass found in `BadgeAwarder::evaluate()` (an explicit `$tenantId` beside an ambient
 * notification write). So the id is read from {@see TenantContext} **once, here**, and passed down — rather
 * than accepted as a parameter that a caller could set to something the ORM half would ignore.
 *
 * ⚠️ **AND OFF-TENANT IS AN EXPLICIT GUARD RATHER THAN SIX QUIET ZEROES.** Every read below is
 * RLS-filtered, and an RLS-filtered read with no tenant GUC returns no rows rather than raising — the
 * read-side twin of the write-side refusal. Without the guard, "this workspace has done nothing" and "you
 * forgot to establish tenant context" would be the same output.
 */
final class TeamProgressService
{
    /**
     * The ledger half. One statement rather than two, because both aggregates read the same rows and the
     * second would repeat the scan for a number the first has already visited every row for.
     *
     * `count(DISTINCT user_id)` and not `count(*)`: "how many people have contributed" is the number a
     * workspace card means, and it is the one that does not grow simply because somebody is busy.
     */
    public const string LEDGER_SQL = <<<'SQL'
        SELECT COALESCE(SUM(points), 0) AS points, COUNT(DISTINCT user_id) AS contributors
        FROM point_awards
        WHERE tenant_id = ?
        SQL;

    /** Served by `badge_awards_tenant_user_badge_unique`'s leading column — no second index is needed. */
    public const string BADGES_SQL = 'SELECT COUNT(*) AS badges FROM badge_awards WHERE tenant_id = ?';

    public function forCurrentTenant(): TeamProgress
    {
        $tenantId = TenantContext::currentTenantId();

        if ($tenantId === null) {
            return TeamProgress::none();
        }

        /** @var object{points: int|string, contributors: int|string}|null $ledger */
        $ledger = DB::selectOne(self::LEDGER_SQL, [$tenantId]);

        /** @var object{badges: int|string}|null $badges */
        $badges = DB::selectOne(self::BADGES_SQL, [$tenantId]);

        return new TeamProgress(
            points: (int) ($ledger->points ?? 0),
            responses: $this->responses(),
            publishedForms: Form::query()->published()->count(),
            // Accepted members only. Deliberately NOT the billing `ActiveSeats` gauge, which also reserves
            // unaccepted invitations — `DashboardMetricsService::activeMembersCount()` is the sibling of
            // this line and records the same distinction. A single equality on a status enum, so it is
            // written out rather than extracted: there is no second answer here for a scope to protect.
            activeMembers: TenantUser::query()->where('status', TenantUserStatus::Active->value)->count(),
            badges: (int) ($badges->badges ?? 0),
            contributors: (int) ($ledger->contributors ?? 0),
        );
    }

    /**
     * Every finalized submission this workspace holds — guest responses included.
     *
     * ⚠️ **NO `visibleTo()`, AND THAT IS THE POINT OF THE NUMBER.** Team progress is what the WORKSPACE has
     * collected, not what the reader may open; scoping it to grants would give two members on one screen
     * two different totals for one workspace. Who may *see* the figure is K1d/K1e's gate, one layer up.
     *
     * {@see Submission::scopeCountable()} is ADR-0011 §D2's single definition of "a response" — shared with
     * the dashboard tile and with analytics, so those three can never drift into disagreeing about what
     * counts.
     */
    private function responses(): int
    {
        return Submission::query()->countable()->count();
    }
}
