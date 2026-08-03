<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\AnalyticsAxis;
use App\Enums\FormStatus;
use App\Enums\TenantUserStatus;
use App\Models\Form;
use App\Models\Submission;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Analytics\AnalyticsMetricsService;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Entitlements\EntitlementService;
use App\Support\Analytics\AnalyticsQuery;
use Carbon\CarbonImmutable;

/**
 * The tenant dashboard's KPI aggregator (H11) — the single place the landing page's headline counts are
 * computed. Live COUNT-under-RLS, never a maintained running aggregate: the same doctrine as
 * {@see EntitlementService::computeGauge()} — a synced counter drifts (a form is
 * archived, a member removed) whereas a COUNT under the tenant's RLS GUC is always correct. No new table,
 * no rollup job; if these counts ever grow too heavy, materialization is a MaintenanceJob fan-out away.
 *
 * ── Visibility ───────────────────────────────────────────────────────────────────────────────────────
 * Numbers are scoped to what the user may see, reusing the {@see Submission::scopeVisibleTo()} /
 * `dashboard.org.view` boundary so a KPI never exceeds the inbox: Owner/Admin/Viewer see tenant-wide
 * totals; a Form Editor/Reviewer sees only forms they hold a grant on. The org-level Members count is
 * withheld (null ⇒ the tile is not rendered) from users without `dashboard.org.view`.
 *
 * Bound `scoped()` (AppServiceProvider): it shares the request's one {@see ResourceGrantResolver} memo, the
 * same reason the entitlement services are scoped, and stays safe to add a per-tenant memo to later.
 */
final class DashboardMetricsService
{
    /** The default window for the trend tiles — a rolling month, wide enough to show shape, cheap to scan. */
    private const int TREND_DAYS = 29;

    public function __construct(
        private readonly ResourceGrantResolver $grants,
        private readonly AnalyticsMetricsService $analytics,
    ) {}

    /**
     * The landing-page KPIs for `$user`. `members` is null when the user lacks org-wide visibility, which
     * the page reads as "omit the Members tile".
     *
     * @return array{forms: int, submissions: int, members: int|null}
     */
    public function forUser(User $user): array
    {
        $orgWide = $user->can('dashboard.org.view');

        return [
            'forms' => $this->formsCount($user, $orgWide),
            'submissions' => $this->submissionsCount($user),
            'members' => $orgWide ? $this->activeMembersCount() : null,
        ];
    }

    /**
     * The four `docs/PRD.md:197` criteria H11 left "partially shipped", plus §D5's two draft metrics.
     *
     * **Ungated, for every tier.** These are Phase-*1* acceptance criteria, and the Phase-3 decomposition's
     * own "H11 vs H24" note unbundles the basic dashboard from the Business gate: *"Free/Starter/Pro tenants
     * get a working dashboard (H11) regardless of the `advanced_analytics` gate; H24 extends H11's service,
     * it does not replace it."* What `advanced_analytics` gates is the genuinely Phase-3 surface — arbitrary
     * cross-form grouping, scope-subtree selection, saved views, answer-value aggregation and the export.
     *
     * One shared query layer, two entry points: the numbers here come from the same
     * {@see AnalyticsMetricsService} the gated API uses, so the dashboard and the analytics surface cannot
     * disagree about a period total the way §D2 warns a dashboard and an inbox can.
     *
     * No new permission and no new gate — the existing `dashboard.org.view` split flows through
     * `AnalyticsFormSet`, so a Form Editor's trend covers exactly the forms their KPI tiles already count.
     *
     * @return array{range: array{from: string, to: string, timezone: string}, total: array{current: int, prior: int, change: float|null}, series: list<array{bucket: string, count: int}>, top_forms: array{rows: list<array{key: string|null, count: int}>, other: array{count: int, categories: int}|null, unassigned: int}, forms_accepting: int, drafts: array<string, mixed>}
     */
    public function trendsForUser(User $user): array
    {
        $today = CarbonImmutable::now();

        $query = new AnalyticsQuery(
            from: $today->subDays(self::TREND_DAYS),
            to: $today,
            axis: AnalyticsAxis::Form,
        );

        return [
            // Echoed so the page can label the tiles honestly — "last 30 days", not an unqualified total —
            // and so the UTC bucketing is visible rather than assumed.
            'range' => [
                'from' => $query->from->toDateString(),
                'to' => $query->to->toDateString(),
                'timezone' => $query->timezone,
            ],
            'total' => $this->analytics->total($query, $user),
            'series' => $this->analytics->series($query, $user),
            'top_forms' => $this->analytics->breakdown($query, $user),
            'forms_accepting' => $this->analytics->acceptingFormsCount($user),
            'drafts' => $this->analytics->draftMetrics($query, $user),
        ];
    }

    /** Active (non-archived) forms the user may reach — org-wide, or scoped to granted forms. */
    private function formsCount(User $user, bool $orgWide): int
    {
        $query = Form::query()->where('status', '!=', FormStatus::Archived->value);

        if (! $orgWide) {
            // The same granted-form subquery Submission::scopeVisibleTo() uses, so the Forms and
            // Submissions numbers agree on which forms a scoped role can reach.
            $query->whereIn('id', $this->grants->grantedFormIdsQuery($user));
        }

        return $query->count();
    }

    /**
     * Finalized submissions (drafts excluded, mirroring the inbox default) visible to `$user`.
     *
     * The predicate is {@see Submission::scopeCountable()} — ADR-0011 §D2 names it once so this tile and
     * H24a's analytics cannot drift into two definitions of "a response".
     */
    private function submissionsCount(User $user): int
    {
        return Submission::query()
            ->visibleTo($user)
            ->countable()
            ->count();
    }

    /**
     * Accepted (active) members of the tenant — the org-wide Members tile. Deliberately accepted members
     * only, distinct from the billing `ActiveSeats` gauge which also reserves `Invited` (unaccepted) seats.
     */
    private function activeMembersCount(): int
    {
        return TenantUser::query()
            ->where('status', TenantUserStatus::Active->value)
            ->count();
    }
}
