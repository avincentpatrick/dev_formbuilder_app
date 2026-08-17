<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\AnalyticsAxis;
use App\Enums\FormStatus;
use App\Enums\SubmissionSource;
use App\Enums\TenantUserStatus;
use App\Models\Form;
use App\Models\Submission;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Analytics\AnalyticsMetricsService;
use App\Services\Analytics\AnalyticsReportBuilder;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Entitlements\EntitlementService;
use App\Support\Analytics\AnalyticsQuery;
use App\Support\Forms\FormHubLink;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

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
     * ── `channels` (Increment I10c) ──────────────────────────────────────────────────────────────────────
     * `docs/PRD.md:198`'s last unbuilt Phase-1 clause: "a breakdown by submission channel (manual / guest /
     * OCR / API / offline-sync)". It ships here, ungated, for the same reason everything else in this method
     * does — it is a Phase-1 acceptance criterion, and `advanced_analytics` gates the Phase-3 surface
     * (arbitrary grouping, saved views, export), not this. `ToggleableModules`' own hint for that key says
     * the dashboard and per-form statistics are unaffected.
     *
     * Deliberately NOT zero-filled to the six `SubmissionSource` cases. A GROUP BY returns only the channels
     * that actually occurred; five permanent `0` bars would invent categories that do not exist — the exact
     * failure `breakdown-bars.ts` already names for the "Other" bucket — and would advertise OCR and API
     * import, which are unbuilt, as available and unused.
     *
     * @return array{range: array{from: string, to: string, timezone: string}, total: array{current: int, prior: int, change: float|null}, series: list<array{bucket: string, count: int}>, top_forms: array{rows: list<array{key: string|null, label: string, count: int, url: string|null}>, other: array{count: int, categories: int}|null, unassigned: int}, channels: array{rows: list<array{key: string|null, label: string, count: int, url: null}>, other: array{count: int, categories: int}|null, unassigned: int}, forms_accepting: int, drafts: array<string, mixed>}
     */
    public function trendsForUser(User $user): array
    {
        $today = CarbonImmutable::now();

        // H24b2 — the window constant moved to AnalyticsQuery so `/analytics` opens on the SAME range this
        // page shows. It was private here, which is exactly how two surfaces come to mean two different
        // things by "the last 30 days"; the constant's docblock records why the number is 29 and not 30.
        $from = $today->subDays(AnalyticsQuery::DEFAULT_RANGE_DAYS);

        $query = new AnalyticsQuery(from: $from, to: $today, axis: AnalyticsAxis::Form);

        $topForms = $this->analytics->breakdown($query, $user);

        // One more aggregate over the SAME bounded row set (ADR-0011 §D7: time-bounded, form-bounded,
        // fixed-cardinality). Built from the same `$from`/`$today` rather than derived, so the two
        // breakdowns cannot drift into meaning different periods.
        //
        // ⚠️ `topN` IS RAISED TO THE FULL CASE COUNT HERE, AND THAT IS NOT A TUNING CHOICE. `source` is a
        // CLOSED axis — six values, no more — and `breakdown()` discards the IDENTITIES of everything it
        // folds into `other`, keeping only a count and a category tally. So at the default top-5 a tenant
        // using all six channels would lose one channel's name permanently: the plot cannot show it and the
        // paired data table cannot either, because `breakdownTableRows()` renders what the server sent. On
        // an OPEN axis (forms) that trade is the point of a top-N; on a closed one it buys nothing and costs
        // a name. Asking for six guarantees `other` is always null on this axis, which is what makes
        // "nothing is hidden" true on the page rather than merely likely.
        $channelQuery = new AnalyticsQuery(
            from: $from,
            to: $today,
            axis: AnalyticsAxis::Source,
            topN: count(SubmissionSource::cases()),
        );

        $channels = $this->analytics->breakdown($channelQuery, $user);

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
            'top_forms' => [
                ...$topForms,
                'rows' => $this->labelFormRows($topForms['rows'], $user),
            ],
            // Shaped identically to `top_forms` on purpose. `Dashboard.vue` already documents why the three
            // client-side breakdown keys are named on the page rather than widened into the prop; a second,
            // richer shape on the same bag would make one page speak two dialects of the same thing.
            'channels' => [
                ...$channels,
                'rows' => $this->labelSourceRows($channels['rows']),
            ],
            'forms_accepting' => $this->analytics->acceptingFormsCount($user),
            'drafts' => $this->analytics->draftMetrics($query, $user),
        ];
    }

    /**
     * Attach a human title to each `axis=form` breakdown row.
     *
     * **Resolved HERE and not inside {@see AnalyticsMetricsService::breakdown()}** — that method's shape is
     * the `/api/v1/analytics/report` response, byte-diffed against the committed `openapi.json` by the
     * contract-tests job and asserted key-by-key by `AnalyticsApiTest`. A label is a presentation concern of
     * one surface; widening the API's aggregate row to carry it would make every API consumer pay for it and
     * would put a second definition of "the row's name" in the aggregator.
     *
     * `withTrashed()` is load-bearing, not defensive. {@see AnalyticsFormSet::visible()} roots an org-wide
     * reader's set on `Form::withTrashed()`, so a soft-deleted form legitimately appears in a breakdown whose
     * submissions are still countable — and a plain lookup would return no row for it, leaving a bar with a
     * number and no name. The fallback below is for a HARD-deleted form only, which is genuinely unnamed.
     *
     * ⚠️ AND THAT IS EXACTLY WHY `url` IS A SECOND QUESTION (Increment J2d). The `withTrashed()` lookup
     * above names a soft-deleted form on purpose; `/forms/{form}` binds through the DEFAULT scope and 404s
     * on it. {@see FormHubLink::pathsFor()} omits every id it will not vouch for — trashed, hard-deleted or
     * out-of-grant alike — so a deleted form keeps its bar and its name and simply is not a link.
     *
     * @param  list<array{key: string|null, count: int}>  $rows
     * @return list<array{key: string|null, label: string, count: int, url: string|null}>
     */
    private function labelFormRows(array $rows, User $user): array
    {
        $ids = array_values(array_filter(array_column($rows, 'key')));

        /** @var array<string, string> $titles */
        $titles = $ids === []
            ? []
            : Form::withTrashed()
                ->whereIn('id', $ids)
                ->pluck('title', 'id')
                ->all();

        $urls = FormHubLink::pathsFor($user, $ids);

        return array_map(
            static fn (array $row): array => [
                'key' => $row['key'],
                'label' => $row['key'] === null ? 'Unassigned' : ($titles[$row['key']] ?? 'Deleted form'),
                'count' => $row['count'],
                'url' => $row['key'] === null ? null : ($urls[$row['key']] ?? null),
            ],
            $rows,
        );
    }

    /**
     * Attach a human label to each `axis=source` breakdown row (Increment I10c).
     *
     * Here rather than inside {@see AnalyticsMetricsService::breakdown()} for the reason
     * {@see self::labelFormRows()} records at length: that method's shape is the `/api/v1/analytics/report`
     * response, byte-diffed against the committed `openapi.json`.
     *
     * ⚠️ `tryFrom`, NEVER `from`, AND THE FALLBACK IS THE RAW VALUE RATHER THAN A PLACEHOLDER. Three facts
     * make an unknown string genuinely reachable here rather than defensive: `submissions.source` is
     * `varchar(20) NOT NULL` with **no DB CHECK constraint**, so nothing at the database level rejects a bad
     * value; the aggregate reads it off a `selectRaw` alias, so the Eloquent `SubmissionSource` cast never
     * runs to catch one; and this is the page every role lands on after login, where `from()`'s uncaught
     * `ValueError` would be a 500 on the workspace's front door. A placeholder like "Unknown channel" would
     * be worse than the raw value in the other direction — it hides the string an operator needs in order to
     * find whatever wrote it.
     *
     * This is the same expression {@see AnalyticsReportBuilder::enumLabel()} uses
     * (moved there from `AnalyticsPresenter` by this same increment): a second CALL SITE of
     * `SubmissionSource::label()`, not a second definition of what a channel is called.
     *
     * The null arm is unreachable today (`breakdown()` diverts nulls into `unassigned`) and is kept for one
     * line rather than asserted away, matching `labelFormRows()`.
     *
     * ⚠️ `url` IS EMITTED AND ALWAYS NULL, WHICH IS NOT AN OVERSIGHT (Increment J2d). A channel is a VALUE,
     * not an entity — there is no `/sources/manual` to open — so no row here can carry a destination. The
     * key is still present so the two bags keep the identical shape this class argues for elsewhere, and so
     * the client's one bar builder reads both without branching. It also matters for accessibility: because
     * no datum is linked, this chart keeps `role="img"` and its sr-only data table, while the Top-forms
     * chart correctly loses both (`MdsBarChart` — a link inside `role="img"` would be unreachable).
     *
     * @param  list<array{key: string|null, count: int}>  $rows
     * @return list<array{key: string|null, label: string, count: int, url: null}>
     */
    private function labelSourceRows(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'key' => $row['key'],
                'label' => $row['key'] === null
                    ? 'Unassigned'
                    : (SubmissionSource::tryFrom($row['key'])?->label() ?? $row['key']),
                'count' => $row['count'],
                'url' => null,
            ],
            $rows,
        );
    }

    /**
     * Has this user got a PUBLISHED form? — the one getting-started fact the KPI tiles do not already
     * carry (Increment J5b).
     *
     * Deliberately the same base query as the Forms tile rather than a second idea of which forms count:
     * a checklist row that disagreed with the number directly above it on the same screen is ADR-0011
     * §D2's defect at one glance's distance. So an archived form does not satisfy this row even if it was
     * once published — it is not in the tile's numerator either.
     *
     * `current_published_version_id` and not `status`: {@see FormStatus} carries a `Published` case, but
     * the column is what `FormPublishController` actually writes and what the public runtime resolves
     * against, so it is the fact rather than a label beside it.
     */
    public function publishedFormsCount(User $user): int
    {
        return $this->visibleFormsQuery($user, $user->can('dashboard.org.view'))
            ->whereNotNull('current_published_version_id')
            ->count();
    }

    /** Active (non-archived) forms the user may reach — org-wide, or scoped to granted forms. */
    private function formsCount(User $user, bool $orgWide): int
    {
        return $this->visibleFormsQuery($user, $orgWide)->count();
    }

    /**
     * The one definition of "forms this user's dashboard counts" — extracted in J5b when the checklist
     * needed the identical set narrowed one step further. Copying the four lines would have put a second
     * answer to that question in the same class, which is the drift this codebase has an ADR-numbering
     * incident to show for.
     *
     * @return Builder<Form>
     */
    private function visibleFormsQuery(User $user, bool $orgWide): Builder
    {
        $query = Form::query()->where('status', '!=', FormStatus::Archived->value);

        if (! $orgWide) {
            // The same granted-form subquery Submission::scopeVisibleTo() uses, so the Forms and
            // Submissions numbers agree on which forms a scoped role can reach.
            $query->whereIn('id', $this->grants->grantedFormIdsQuery($user));
        }

        return $query;
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
