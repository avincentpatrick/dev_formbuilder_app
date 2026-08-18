<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsAxis;
use App\Enums\AnalyticsGranularity;
use App\Enums\FormStatus;
use App\Enums\SubmissionSource;
use App\Models\Form;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Submissions\SubmissionDraftService;
use App\Support\Analytics\AnalyticsQuery;
use App\Support\Forms\FormSchedule;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * Cross-form submission aggregates, computed live under RLS (ADR-0011 §D7, Increment H24a).
 *
 * The doctrine is `DashboardMetricsService`'s, ratified at cross-form scale: **live SQL under the tenant's
 * RLS GUC, never a maintained running aggregate.** `docs/non-functional-requirements.md:20` permits
 * precomputed aggregates, and §D7 reads that as what it is — *permission, not instruction*. Materialization
 * is a `MaintenanceJob` fan-out (ADR-0007 §D3) opened by a **measured** p95 breach of the 1.5s maximum on
 * real tenant data, never chosen up front; and the invariant governing any such revisit is that a rollup
 * must never be able to disagree with the inbox, which is why every count here goes through
 * {@see Submission::scopeCountable()} rather than spelling its own predicate.
 *
 * Bounded means three things, all supplied by {@see AnalyticsQuery}'s constructor rather than by convention
 * here: a mandatory date range with a maximum span, a form-bounded selection, and a fixed-cardinality
 * result.
 *
 * ── The bucketing rule, and why the obvious spelling is wrong ───────────────────────────────────────────
 * `date_trunc('day', submitted_at)` silently uses the session `TimeZone` GUC, and `config/database.php` sets
 * no `timezone` key on the `pgsql` connection — so it is whatever `postgresql.conf` says, UTC in the
 * container and unknown on ADR-0005's self-hosted box. The same data would bucket differently on different
 * hosts. Every bucket below therefore uses the THREE-argument form, `date_trunc(field, source, zone)`
 * (PG 16+), with the zone bound from the query.
 *
 * And the range predicate stays on the RAW column: `date_trunc` appears only in SELECT/GROUP BY, because
 * `submissions_analytics_series_idx` is `(tenant_id, submitted_at)` and filtering on the expression would be
 * a sequential scan. No expression index is added — it would be frozen to one timezone and useless the
 * moment a saved view named another.
 *
 * Bound `scoped()` alongside {@see DashboardMetricsService}, for the same reason:
 * it shares the request's one {@see ResourceGrantResolver} memo.
 */
final class AnalyticsMetricsService
{
    /** Aggregate alias. `count(*)` is not a usable property name on a PDO result object. */
    private const string AGG = 'aggregate';

    public function __construct(
        private readonly AnalyticsFormSet $formSet,
        private readonly ResourceGrantResolver $grants,
    ) {}

    /**
     * `docs/PRD.md:197`'s period comparison: the range's countable total beside the equal-length range
     * immediately before it.
     *
     * `change` is null rather than 0 or 100 when the prior period is empty — a percentage change from zero
     * is undefined, and rendering it as "+100%" is the same class of confident-wrong number §D5 refuses
     * elsewhere.
     *
     * @return array{current: int, prior: int, change: float|null}
     */
    public function total(AnalyticsQuery $query, User $user): array
    {
        $current = $this->base($query, $user)->count();
        $prior = $this->base($query->priorPeriod(), $user)->count();

        return [
            'current' => $current,
            'prior' => $prior,
            'change' => $prior === 0 ? null : round((($current - $prior) / $prior) * 100, 1),
        ];
    }

    /**
     * `docs/PRD.md:197`'s submission-volume trend: one point per bucket across the whole range, **including
     * buckets with no submissions**.
     *
     * Gaps are filled here rather than with `generate_series`, and that is a decision rather than a
     * convenience. §D7 caps the series at 366 points so the PHP loop is trivial; a `generate_series` LEFT
     * JOIN would wrap the aggregate in a subquery and push the planner off the clean index scan; month
     * stepping over a DST boundary is a known `generate_series` footgun; and the labels have to be formatted
     * in PHP regardless. The cost is that PHP and SQL must agree on where a bucket starts, which is what
     * {@see AnalyticsGranularity::floor()} exists to guarantee.
     *
     * A series that returned only non-empty buckets would render as a plausible line rather than as a floor,
     * which is why the zero-filling is pinned by its own test.
     *
     * @return list<array{bucket: string, count: int}>
     */
    public function series(AnalyticsQuery $query, User $user): array
    {
        $counts = $this->base($query, $user)
            ->selectRaw(
                '(date_trunc(?, submitted_at, ?) AT TIME ZONE ?)::date as bucket, count(*) as '.self::AGG,
                [$query->granularity->value, $query->timezone, $query->timezone]
            )
            ->groupBy('bucket')
            ->pluck(self::AGG, 'bucket');

        $series = [];
        $cursor = $query->granularity->floor($query->from->setTimezone($query->timezone));
        $last = $query->to->setTimezone($query->timezone)->startOfDay();

        while ($cursor->lessThanOrEqualTo($last)) {
            $label = $cursor->toDateString();
            $series[] = ['bucket' => $label, 'count' => (int) ($counts[$label] ?? 0)];
            $cursor = $query->granularity->next($cursor);
        }

        return $series;
    }

    /**
     * A grouped count on the query's axis, collapsed to the top N plus one aggregated remainder (§D7's
     * fixed-cardinality rule; §D11's "Other (N)" bucket).
     *
     * Two properties this owes and a test pins:
     *
     *   - **`sum(rows) + other + unassigned == total`**, always. A breakdown that quietly dropped a
     *     population would be a visible self-disagreement on one screen — the failure §D2 exists to prevent
     *     — and there are two populations that get dropped by accident: forms with no scope node, and forms
     *     on a node the authorization layer treats as gone.
     *   - The remainder carries its **category count**, not just its sum, because §D11 requires "Other (N)"
     *     to name how many categories it stands for. Painting it neutral is H24b's job; supplying N is this
     *     one's.
     *
     * @return array{rows: list<array{key: string|null, count: int}>, other: array{count: int, categories: int}|null, unassigned: int}
     */
    public function breakdown(AnalyticsQuery $query, User $user): array
    {
        $axis = $query->axis;

        $rows = $this->grouped($query, $user, $axis)
            ->orderByDesc(self::AGG)
            ->orderBy('bucket') // deterministic ties, so paging and export agree run to run
            ->get();

        $unassigned = 0;
        $keyed = [];

        foreach ($rows as $row) {
            /** @var object{bucket: string|null, aggregate: int|string} $row */
            $count = (int) $row->aggregate;

            if ($row->bucket === null) {
                $unassigned += $count;

                continue;
            }

            $keyed[] = ['key' => (string) $row->bucket, 'count' => $count];
        }

        $top = array_slice($keyed, 0, $query->topN);
        $rest = array_slice($keyed, $query->topN);

        return [
            'rows' => $top,
            'other' => $rest === [] ? null : [
                'count' => array_sum(array_column($rest, 'count')),
                'categories' => count($rest),
            ],
            // Always present, never folded into `rows` — §D6 requires an EXPLICIT bucket, because
            // scope_node_id is NULL for every form until assigned and a silently short total is the failure.
            'unassigned' => $unassigned,
        ];
    }

    /**
     * `docs/PRD.md:197`'s "count of forms currently accepting responses".
     *
     * Not derivable from a column. `forms.schedule_state` is explicitly a LAGGING denormalisation whose only
     * job is to make `form.opened`/`form.closed` fire once — `FormAcceptanceGuard`'s docblock says acceptance
     * is "never from the persisted `schedule_state`". So this mirrors {@see FormSchedule}
     * in SQL, and a test asserts the two agree row by row across all four acceptance labels. That parity test
     * is the point: this is the one place H24a creates a second implementation of an existing rule, and an
     * unpinned second implementation is how a dashboard tile comes to contradict the public form's own
     * closed banner.
     *
     * `forms.timezone` is deliberately not consulted, matching `FormSchedule`: every comparison is against
     * absolute `timestamptz` instants.
     */
    public function acceptingFormsCount(User $user): int
    {
        $now = CarbonImmutable::now();

        return Form::query()
            ->whereIn('forms.id', $this->formSet->resolveAllVisible($user))
            ->where('forms.status', FormStatus::Published->value)
            ->where(fn (Builder $q) => $q->whereNull('forms.opens_at')->orWhere('forms.opens_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('forms.closes_at')->orWhere('forms.closes_at', '>', $now))
            // The capacity arm, restricted to capped forms so uncapped ones never pay for the correlated
            // count. `acceptance()` reads `>= max_responses` as full, so accepting means strictly fewer.
            //
            // The predicate is `Submission::scopeConsumesCapacity()` spelled out by hand — this is a
            // correlated subquery on a `Form` builder, so the scope cannot be applied — and it is a capacity
            // question, NOT `scopeCountable()`. Since I9a that means excluding `screened_out` too: a form
            // whose only remaining "responses" were screened out is still ACCEPTING. `AnalyticsMetricsServiceTest`
            // pins this against the scope so the hand-spelled copy cannot drift from the enforcement COUNT.
            ->where(function (Builder $q): void {
                $q->whereNull('forms.max_responses')
                    ->orWhereRaw(
                        '(select count(*) from submissions where submissions.form_id = forms.id '
                        ."and submissions.status <> 'draft' and submissions.status <> 'screened_out' "
                        .'and submissions.deleted_at is null) < forms.max_responses'
                    );
            })
            ->count();
    }

    /**
     * §D5's two amended metrics — the substitutes for `docs/PRD.md:198`'s uncomputable "completion rate" and
     * "average completion time".
     *
     * Everything about this method is shaped by one fact: **a respondent who abandons a form leaves no server
     * row at all.** A `submissions` row appears only on submit, or when the respondent explicitly clicks
     * "Save and finish later" — and that self-selected population is hard-deleted nightly by
     * `ReapTenantDraftsJob`. A naive funnel over `count(status='draft')` therefore returns 100% on a
     * default-configured form and RISES every night as the reaper deletes its own denominator.
     *
     * Four consequences, each load-bearing:
     *
     *  - **The marker is `last_saved_at IS NOT NULL`**, which survives promotion because
     *    `SubmissionDraftService::promote()` rewrites only `status`, `submitted_at` and `draft_expires_at`,
     *    and the fresh-submit path never writes it. A direct submit is therefore excluded — which is the
     *    whole point, since `submitted_at - created_at` spans a single server request for one of those.
     *
     *  - **First save is `created_at`**: the row is created BY the first explicit save, and `promote()`
     *    leaves it alone.
     *
     *  - **The denominator is restricted to respondent channels.** `SubmissionDraftService` is exposed as a
     *    service seam, not only an HTTP route, precisely so a server-side caller can stage a draft — and
     *    every `createDraft()`/`updateDraft()` path stamps `last_saved_at` unconditionally. The moment
     *    H18/H19 land, every OCR-staged draft would join this denominator and the median would silently
     *    start measuring ENCODER REVIEW LATENCY rather than respondent fill time, with no visible change.
     *    Nothing distinguishes them but `submissions.source`, so the allowed set is named here and the
     *    excluded set — `ocr_single`, `ocr_linelist`, `offline_sync`, `api_import` — is named in this comment
     *    so a later increment cannot widen it by accident.
     *
     *  - **Suppressed, never estimated.** Past the tenant's draft-retention window the denominator has been
     *    deleted, so both numbers are withheld with a reason rather than computed from survivors.
     *
     * The response distinguishes THREE states, because `percentile_cont` returns NULL on an empty set and a
     * careless shape would conflate it with suppression: `suppressed` (retention), `unavailable` (nobody used
     * save-and-resume in range), and a number. A tile rendering "0%" for a suppressed metric is §D5's exact
     * failure wearing a different hat.
     *
     * @return array{suppressed: bool, reason: string|null, denominator: int, converted: int|null, conversion_rate: float|null, median_seconds: int|null}
     */
    public function draftMetrics(AnalyticsQuery $query, User $user): array
    {
        $retentionDays = $this->draftRetentionDays();
        $horizon = CarbonImmutable::now()->subDays($retentionDays);

        if ($query->fromUtc()->lessThan($horizon)) {
            return [
                'suppressed' => true,
                'reason' => 'beyond_draft_retention',
                'denominator' => 0,
                'converted' => null,
                'conversion_rate' => null,
                'median_seconds' => null,
            ];
        }

        $respondentChannels = [SubmissionSource::Guest->value, SubmissionSource::Manual->value];

        // Deliberately NOT ->countable(): the denominator must include drafts that have not converted, which
        // is the one place in this class that steps outside D2's predicate — and it is why the numerator
        // spells the countable half explicitly rather than inheriting it.
        $denominatorQuery = Submission::query()
            ->whereIn('form_id', $this->formSet->resolve($query, $user))
            ->whereNotNull('last_saved_at')
            ->whereIn('source', $respondentChannels)
            ->where('created_at', '>=', $query->fromUtc())
            ->where('created_at', '<', $query->toUtcExclusive());

        $denominator = (clone $denominatorQuery)->count();

        if ($denominator === 0) {
            return [
                'suppressed' => false,
                'reason' => 'no_saved_drafts',
                'denominator' => 0,
                'converted' => null,
                'conversion_rate' => null,
                'median_seconds' => null,
            ];
        }

        $converted = (clone $denominatorQuery)->countable()->count();

        // EXTRACT(EPOCH ...) server-side is not cosmetic: percentile_cont over a bare `interval` returns an
        // interval, which PDO hands back as the string "2 days 03:14:00" for PHP to re-parse.
        $median = (clone $denominatorQuery)
            ->countable()
            ->selectRaw(
                'percentile_cont(0.5) within group (order by extract(epoch from (submitted_at - created_at))) as '.self::AGG
            )
            ->value(self::AGG);

        return [
            'suppressed' => false,
            'reason' => null,
            'denominator' => $denominator,
            'converted' => $converted,
            'conversion_rate' => round(($converted / $denominator) * 100, 1),
            'median_seconds' => $median === null ? null : (int) round((float) $median),
        ];
    }

    /**
     * Every countable submission this query may see, before any grouping.
     *
     * Visibility is folded into the form-set subquery rather than applied separately, so the whole
     * authorization boundary is one `whereIn` — see {@see AnalyticsFormSet}.
     *
     * @return Builder<Submission>
     */
    private function base(AnalyticsQuery $query, User $user): Builder
    {
        return Submission::query()
            ->countable()
            ->whereIn('submissions.form_id', $this->formSet->resolve($query, $user))
            // On the RAW column, half-open. This is what submissions_analytics_series_idx serves; a
            // `BETWEEN … 23:59:59` bound would also drop sub-second rows in the final second.
            ->where('submissions.submitted_at', '>=', $query->fromUtc())
            ->where('submissions.submitted_at', '<', $query->toUtcExclusive())
            ->when($query->statuses !== [], fn (Builder $q) => $q->whereIn('submissions.status', $query->statuses))
            ->when($query->sources !== [], fn (Builder $q) => $q->whereIn('submissions.source', $query->sources))
            ->when($query->locales !== [], fn (Builder $q) => $q->whereIn('submissions.locale', $query->locales));
    }

    /**
     * The grouped aggregate for one axis.
     *
     * The scope-node arm is the interesting one. §D6 requires forms on a deactivated node — or under a
     * deactivated branch — to be excluded from their own bucket, and forms with no node to appear in an
     * explicit *Unassigned* one. Both fall out of a single LEFT JOIN whose condition includes reachability:
     * an unreachable node simply fails to match, the joined id is NULL, and the row lands in *Unassigned*
     * exactly like an unassigned form. Expressing it as a join condition rather than a CASE means the
     * reachability rule is still {@see ResourceGrantResolver}'s, called rather than copied.
     *
     * @return Builder<Submission>
     */
    private function grouped(AnalyticsQuery $query, User $user, AnalyticsAxis $axis): Builder
    {
        $base = $this->base($query, $user);

        if ($axis->groupsThroughForm()) {
            $reachable = $this->grants->reachableNodeIdsQuery();

            $base->join('forms', 'forms.id', '=', 'submissions.form_id')
                ->leftJoin('scope_nodes', function (JoinClause $join) use ($reachable): void {
                    $join->on('scope_nodes.id', '=', 'forms.scope_node_id')
                        ->whereIn('scope_nodes.id', $reachable);
                });

            return $base
                ->selectRaw('scope_nodes.id as bucket, count(*) as '.self::AGG)
                ->groupBy('scope_nodes.id');
        }

        return $base
            ->selectRaw($axis->column().' as bucket, count(*) as '.self::AGG)
            ->groupBy($axis->column());
    }

    /**
     * The tenant's configured draft window, or the system default.
     *
     * `tenants.draft_ttl_days` is nullable and null means "use the default" — the same resolution
     * `GuestDraftController` performs, kept identical so the suppression horizon matches the window the
     * reaper actually enforces.
     */
    private function draftRetentionDays(): int
    {
        $configured = Tenant::query()
            ->whereKey(TenantContext::currentTenantId())
            ->value('draft_ttl_days');

        return $configured === null ? SubmissionDraftService::DRAFT_TTL_DAYS : (int) $configured;
    }
}
