<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsAxis;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\ScopeNode;
use App\Models\User;
use App\Services\Dashboard\DashboardMetricsService;
use App\Support\Analytics\AnalyticsQuery;

/**
 * The labelled analytics REPORT — range, prior range, total, series, breakdown, drafts (Increment I10c).
 *
 * Extracted verbatim from {@see AnalyticsPresenter::report()}, which now delegates here, so that a second
 * surface can render the same numbers without inheriting the first one's dependencies. The Vue side takes
 * this shape as authoritative: `resources/js/components/analytics/types.ts` records that its shapes are
 * derived literally from PHP `@return` annotations, and a second PHP author of the same shape is exactly what
 * that sentence exists to prevent — which is why this was extracted rather than copied. Note the TS twin of
 * THIS method is `FormReport`, not `Report`: `Report` additionally carries `forms_accepting`, which `build()`
 * deliberately never emits (below).
 *
 * ── WHY `forms_accepting` IS NOT HERE ────────────────────────────────────────────────────────────────────
 * It stays on {@see AnalyticsPresenter}, and the omission is the point rather than an oversight. It answers
 * "how many forms across your whole visible set are accepting responses right now" — a workspace-wide
 * question that takes no range and no form selection. On `/analytics` that is meaningful; on a page scoped to
 * ONE form it would be a confidently wrong number sitting beside three correct ones. Keeping it out of the
 * builder means a form-scoped caller cannot pick it up by accident.
 *
 * ── WHAT THIS CLASS MUST NEVER DO ───────────────────────────────────────────────────────────────────────
 * Push label resolution down into {@see AnalyticsMetricsService::breakdown()}. That method's shape IS the
 * `/api/v1/analytics/report` response, byte-diffed against the committed `openapi.json` by the contract-tests
 * job; a label is a presentation concern of one surface. {@see DashboardMetricsService::labelFormRows()}'s
 * docblock establishes the rule and this class inherits it.
 *
 * Also: **no analytics query may call `withTrashed()` on `submissions`** — the analytics-index migration
 * (2026_08_03_000001) makes that a hard rule for this namespace, because it renders `deleted_at IS NULL`
 * unprovable and silently loses `submissions_analytics_series_idx`. The `Form::withTrashed()` below is on
 * `forms`, which is a different table and a different question.
 */
final class AnalyticsReportBuilder
{
    public function __construct(
        private readonly AnalyticsMetricsService $metrics,
    ) {}

    /**
     * @return array{range: array{from: string, to: string, timezone: string, granularity: string}, prior_range: array{from: string, to: string}, total: array{current: int, prior: int, change: float|null}, series: list<array{bucket: string, count: int}>, breakdown: array<string, mixed>, drafts: array<string, mixed>, week_starts_on: string}
     */
    public function build(AnalyticsQuery $query, User $user): array
    {
        $breakdown = $this->metrics->breakdown($query, $user);
        $prior = $query->priorPeriod();

        return [
            'range' => [
                'from' => $query->from->toDateString(),
                'to' => $query->to->toDateString(),
                'timezone' => $query->timezone,
                'granularity' => $query->granularity->value,
            ],
            // The comparison window, named rather than assumed. The dashboard can hard-code "vs. previous 30
            // days" because its range is fixed; here the range can vary, so the tile must say which window it
            // is comparing against or the delta is a number with no referent.
            'prior_range' => [
                'from' => $prior->from->toDateString(),
                'to' => $prior->to->toDateString(),
            ],
            'total' => $this->metrics->total($query, $user),
            'series' => $this->metrics->series($query, $user),
            'breakdown' => [
                ...$breakdown,
                'axis' => $query->axis->value,
                'rows' => $this->labelRows($query->axis, $breakdown['rows']),
                'unassigned_label' => $this->unassignedLabel($query->axis),
                'has_unassigned_bucket' => $query->axis->hasUnassignedBucket(),
            ],
            'drafts' => $this->metrics->draftMetrics($query, $user),
            // Stated on every response, matching the API's `meta`: ISO weeks are Monday-start and not
            // configurable, and a chart labelled "Week of …" is otherwise making a claim nobody declared.
            'week_starts_on' => 'monday',
        ];
    }

    /**
     * Attach a human name to each breakdown row.
     *
     * `Form::withTrashed()` is load-bearing for the same reason it is in `DashboardMetricsService`: an
     * org-wide reader's visible set is rooted on it, so a soft-deleted form legitimately appears in a
     * breakdown whose submissions are still countable, and a plain lookup would leave a bar with a number
     * and no name. `ScopeNode` takes no `withTrashed()` — it has no `SoftDeletes` at all.
     *
     * The `key === null` arm is unreachable today ({@see AnalyticsMetricsService::breakdown()} diverts nulls
     * into `unassigned`) and is kept anyway rather than asserted away: it costs one line and its absence
     * would be a fatal on a shape change rather than a wrong label.
     *
     * @param  list<array{key: string|null, count: int}>  $rows
     * @return list<array{key: string|null, label: string, count: int}>
     */
    private function labelRows(AnalyticsAxis $axis, array $rows): array
    {
        /** @var list<string> $ids */
        $ids = array_values(array_filter(array_column($rows, 'key'), 'is_string'));

        /** @var array<string, string> $names */
        $names = match ($axis) {
            AnalyticsAxis::Form => $ids === [] ? [] : Form::withTrashed()
                ->whereIn('id', $ids)->pluck('title', 'id')->all(),
            AnalyticsAxis::ScopeNode => $ids === [] ? [] : ScopeNode::query()
                ->whereIn('id', $ids)->pluck('name', 'id')->all(),
            AnalyticsAxis::Source, AnalyticsAxis::Status, AnalyticsAxis::Locale => [],
        };

        $missing = match ($axis) {
            AnalyticsAxis::Form => 'Deleted form',
            AnalyticsAxis::ScopeNode => 'Deleted area',
            AnalyticsAxis::Source, AnalyticsAxis::Status, AnalyticsAxis::Locale => '',
        };

        return array_map(fn (array $row): array => [
            ...$row,
            'label' => $row['key'] === null
                ? $this->unassignedLabel($axis)
                : ($names[$row['key']] ?? $this->enumLabel($axis, $row['key']) ?? $missing),
        ], $rows);
    }

    /**
     * The display name for a raw enum-valued axis key.
     *
     * `locale` deliberately returns the raw BCP-47 tag rather than a localised display name: the same label
     * appears in the CSV export, and a viewer-localised name would make the chart and the file disagree
     * about what the same bucket is called.
     */
    private function enumLabel(AnalyticsAxis $axis, string $key): ?string
    {
        return match ($axis) {
            AnalyticsAxis::Source => SubmissionSource::tryFrom($key)?->label() ?? $key,
            AnalyticsAxis::Status => SubmissionStatus::tryFrom($key)?->label() ?? $key,
            AnalyticsAxis::Locale => $key,
            AnalyticsAxis::Form, AnalyticsAxis::ScopeNode => null,
        };
    }

    /** Axis-specific copy for the bucket §D6 requires to be explicit rather than silently missing. */
    private function unassignedLabel(AnalyticsAxis $axis): string
    {
        return match ($axis) {
            AnalyticsAxis::Locale => 'Not recorded',
            AnalyticsAxis::Form, AnalyticsAxis::Source, AnalyticsAxis::Status, AnalyticsAxis::ScopeNode => 'Unassigned',
        };
    }
}
