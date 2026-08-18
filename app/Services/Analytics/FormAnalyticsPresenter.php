<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsAxis;
use App\Enums\AnalyticsFormSelection;
use App\Models\Form;
use App\Models\User;
use App\Services\Dashboard\DashboardMetricsService;
use App\Support\Analytics\AnalyticsQuery;
use Carbon\CarbonImmutable;

/**
 * The `/forms/{form}/analytics` prop bag (Increment I10c) — `docs/PRD.md:198`'s Form Owner/Editor view:
 * "submissions over time for that form" plus a breakdown by submission channel.
 *
 * ── UNGATED, AND BUILT SO IT CANNOT DRIFT INTO A SECOND /analytics ──────────────────────────────────────
 * These are PHASE-1 acceptance criteria, so the page carries no `feature:advanced_analytics` — the same
 * unbundling {@see DashboardMetricsService::trendsForUser()} records, and the same one `ToggleableModules`
 * states in its own hint for that key (which this increment widened to name per-form
 * statistics alongside the dashboard). What the gate protects is the
 * genuinely Phase-3 surface: arbitrary axis selection, scope-subtree selection, saved views, answer-value
 * aggregation and the streamed export.
 *
 * FIVE STRUCTURAL MECHANISMS KEEP THAT LINE, none of which is discipline:
 *
 *  1. **The constructor is the blast radius.** One dependency. {@see AnswerValueAggregator} (answer-value
 *     aggregation), {@see SavedReportViewService} (saved views), `AnalyticsExporter` (the export) and
 *     {@see AnalyticsPresenter} itself are all absent, so every gated surface is unreachable from here BY
 *     CONSTRUCTION. Adding one is a visible constructor change in review rather than a quiet new key.
 *  2. **No FormRequest and no query string.** The controller reads nothing from the request; the axis, the
 *     granularity, the timezone, the top-N and the window are literals below. There is no input to widen.
 *     (Contrast `AnalyticsController::index(AnalyticsFilterRequest $request)`.)
 *  3. **`selection: Forms` with exactly one id.** {@see AnalyticsQuery}'s constructor throws on `Forms` with
 *     an empty `formIds`, so a one-form selection cannot silently degrade into `All` and become a tenant
 *     total wearing a form's title. `AnalyticsFormSelection::ScopeNode` — the gated subtree selection — is
 *     never constructible from this path.
 *  4. **One route, and deliberately no `/export` sibling.** Nothing on this page downloads.
 *  5. Pinned by `FormAnalyticsPageTest` and `FormAnalyticsGateTest`, including a case that GETs the route
 *     with widening query parameters and asserts the report is unchanged.
 *
 * ── VISIBILITY IS THREE LAYERS, AND NONE OF THEM IS REDUNDANT ───────────────────────────────────────────
 * RLS answers "is this form in your tenant"; `can:view,form` on the route answers "may you open this page"
 * (zeroed aggregates do not fix a leaked TITLE); and {@see AnalyticsFormSet::resolve()} answers "which rows
 * may be counted". The two authorization predicates are genuinely different — `forms.edit.*` versus
 * `dashboard.org.view` — which is exactly why neither substitutes for the other. `resolve()` INTERSECTS the
 * requested selection with the user's visible set rather than replacing it, so passing a form id here can
 * never widen what the caller may read.
 */
final class FormAnalyticsPresenter
{
    public function __construct(
        private readonly AnalyticsReportBuilder $reports,
    ) {}

    /**
     * @return array{form: array{id: string, title: string}, report: array<string, mixed>}
     */
    public function show(Form $form, User $user): array
    {
        $today = CarbonImmutable::now();

        $query = new AnalyticsQuery(
            // The SAME window the dashboard opens on — the constant lives on AnalyticsQuery precisely so two
            // surfaces cannot come to mean two different things by "the last 30 days".
            from: $today->subDays(AnalyticsQuery::DEFAULT_RANGE_DAYS),
            to: $today,
            selection: AnalyticsFormSelection::Forms,
            formIds: [(string) $form->id],
            // Channel, because on a page that is already scoped to one form, grouping BY form would be a
            // single bar restating the page title. PRD.md:198 asks for exactly this axis.
            axis: AnalyticsAxis::Source,
        );

        return [
            'form' => [
                'id' => (string) $form->id,
                'title' => $form->title,
            ],
            'report' => $this->reports->build($query, $user),
        ];
    }
}
