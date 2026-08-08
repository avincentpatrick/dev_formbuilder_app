<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsAxis;
use App\Enums\AnalyticsFormSelection;
use App\Enums\AnalyticsGranularity;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Enums\UsageMetric;
use App\Models\Form;
use App\Models\SavedReportView;
use App\Models\ScopeNode;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Webhooks\WebhookEndpointPresenter;
use App\Support\Analytics\AnalyticsQuery;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * The `/analytics` page's prop bag (Increment H24b2).
 *
 * ── Why the mapping is INLINE and not an `Api\V1` Resource ──────────────────────────────────────────────
 * The same rule {@see WebhookEndpointPresenter} records: a web presenter must not
 * couple to an API-versioned class. Field names mirror the API's 1:1 where they overlap, but this bag adds
 * derivations the resources do not have — resolved breakdown labels, the option catalogues, the `can` map,
 * the reference checks on a saved view — and inline mapping keeps the two free to move apart.
 *
 * ── Closures, and which keys are NOT among them ─────────────────────────────────────────────────────────
 * The heavy groups are `Closure` props, so an Inertia partial reload naming `only: [...]` evaluates just the
 * ones it asks for. `filters` is deliberately excluded from every partial reload the page issues: the option
 * catalogues are TENANT-scoped, never range-scoped, so they cannot change when a filter changes — and that
 * exclusion is what makes shipping the full IANA timezone list affordable at all.
 *
 * ── What this class must never do ───────────────────────────────────────────────────────────────────────
 * Resolve a label inside {@see AnalyticsMetricsService::breakdown()}. That method's shape is the
 * `/api/v1/analytics/report` response, byte-diffed against the committed `openapi.json`; a label is a
 * presentation concern of one surface, which is exactly what `DashboardMetricsService::labelFormRows()`'s
 * docblock already establishes.
 */
final class AnalyticsPresenter
{
    public function __construct(
        private readonly AnalyticsMetricsService $metrics,
        private readonly AnalyticsReportBuilder $reports,
        private readonly AnswerValueAggregator $aggregator,
        private readonly AnalyticsFormSet $formSet,
        private readonly SavedReportViewService $views,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * The whole page bag.
     *
     * `$refusal` is computed ONCE here rather than inside each closure — three of them consult it, and a
     * per-closure `exists()` would issue the same query three times on a full visit.
     *
     * @return array<string, mixed>
     */
    public function index(User $user, AnalyticsQuery $query, ?string $questionKey): array
    {
        $refusal = $this->selectionRefusal($query);

        return [
            // The declaration the server actually applied, verbatim from the VO — including `v`, so the page
            // and a stored `definition` speak one shape. The client re-seeds its controls from this on every
            // response, which is what keeps them honest when the server coerced something.
            'applied' => $query->toArray(),
            'refusal' => $refusal,
            'can' => [
                // Nothing else belongs here. `forUser()` returns only OWNED rows and the policy is
                // `viewAny && owns`, so every view the page can list is already updatable and deletable by
                // the person looking at it; a per-row map would be a second definition of ownership.
                'createView' => $user->can('create', SavedReportView::class),
            ],
            'filters' => fn (): array => $this->options($user),
            'summary' => fn (): array => [
                'exports' => [
                    'used' => $this->entitlements->usage(UsageMetric::ExportsCount),
                    'limit' => $this->entitlements->quota(UsageMetric::ExportsCount),
                ],
            ],
            'report' => fn (): ?array => $refusal !== null ? null : $this->report($user, $query),
            'questions' => fn (): array => $refusal !== null ? [] : $this->aggregator->questions($query, $user),
            'question' => fn (): ?array => $refusal !== null || $questionKey === null
                ? null
                : $this->questionAggregate($user, $query, $questionKey),
            'views' => fn (): array => $this->savedViews($user, $query),
        ];
    }

    /**
     * ADR-0011 §D8's read-time degradation for the one class of rot the substrate structurally cannot see.
     *
     * {@see SavedReportViewService::resolve()} re-parses a stored definition's SHAPE; `scope_node_id` is a
     * plain string there and its existence is never checked. Meanwhile {@see AnalyticsFormSet} resolves a
     * subtree selection through `findOrFail` — correct, because returning "all forms" for a missing node
     * would silently WIDEN a scoped report, which is worse than any error. So without this pre-emption a
     * saved view naming a deleted area 404s the whole page instead of stating why it cannot be produced.
     *
     * Public because `AnalyticsController::export()` must apply the same guard — an exporter has no page to
     * render a refusal into. (Named in prose rather than as a `{@see}`: Pint's `fully_qualified_strict_types`
     * would turn the reference into a real `use`, and a service importing a controller is an inversion.)
     *
     * A DEACTIVATED node is deliberately not a refusal: `subtreeFormIdsQuery()` inherits `nodeReach()`'s
     * semantics and excludes an inactive branch, so the report is an honest zero.
     *
     * @return array{reason: string, message: string}|null
     */
    public function selectionRefusal(AnalyticsQuery $query): ?array
    {
        if ($query->selection !== AnalyticsFormSelection::ScopeNode) {
            return null;
        }

        if (ScopeNode::query()->whereKey($query->scopeNodeId)->exists()) {
            return null;
        }

        return [
            'reason' => 'scope_node_missing',
            'message' => 'The area this report groups by has been deleted, so its figures cannot be produced.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function report(User $user, AnalyticsQuery $query): array
    {
        return [
            ...$this->reports->build($query, $user),
            // Workspace-wide, and deliberately NOT in the builder (Increment I10c). It answers "how many
            // forms across your whole visible set are accepting responses right now" — a question that takes
            // no range and no form selection, so on a surface scoped to one form it would be a confidently
            // wrong number beside three correct ones. Keeping it here means such a surface cannot inherit it.
            'forms_accepting' => $this->metrics->acceptingFormsCount($user),
        ];
    }

    /**
     * The one derivation the aggregate needs and does not carry: its own name.
     *
     * {@see AnswerValueAggregator::aggregate()} returns `key` only, so without this the chart title is a
     * snake_case field key. This is precisely the `form_title` case `WebhookEndpointPresenter` cites as a
     * reason for inline mapping.
     *
     * The label is read back out of `questions()` rather than looked up directly, which is a second query
     * and deliberate: that catalogue resolves a key's label LAST-VERSION-WINS across the published versions
     * in scope, and a direct `form_fields` lookup would pick a different row the moment a question was
     * relabelled — putting one name on the picker and a different one on the chart it opens.
     *
     * @return array<string, mixed>
     */
    private function questionAggregate(User $user, AnalyticsQuery $query, string $key): array
    {
        $label = null;

        foreach ($this->aggregator->questions($query, $user) as $question) {
            if ($question['key'] === $key) {
                $label = $question['label'];
                break;
            }
        }

        return [
            ...$this->aggregator->aggregate($query, $user, $key),
            'label' => $label ?? $key,
        ];
    }

    /**
     * The user's saved views, each resolved and each reference-checked.
     *
     * The existence checks are TWO queries for the whole list, never one per view: a user with twenty saved
     * views would otherwise pay forty round trips to render a sidebar.
     *
     * Missing FORM ids are a disclosure, not a refusal — a `forms` selection with dead ids narrows to an
     * honest zero, whereas a missing scope node cannot be resolved at all.
     *
     * @return list<array<string, mixed>>
     */
    private function savedViews(User $user, AnalyticsQuery $applied): array
    {
        $views = $this->views->forUser($user);

        $resolved = $views->map(fn (SavedReportView $view): array => [
            'view' => $view,
            ...$this->views->resolve($view),
        ])->all();

        $nodeIds = [];
        $formIds = [];

        foreach ($resolved as $entry) {
            $query = $entry['query'];

            if (! $query instanceof AnalyticsQuery) {
                continue;
            }

            if ($query->selection === AnalyticsFormSelection::ScopeNode && $query->scopeNodeId !== null) {
                $nodeIds[] = $query->scopeNodeId;
            }

            foreach ($query->formIds as $formId) {
                $formIds[] = $formId;
            }
        }

        /** @var array<int, string> $liveNodes */
        $liveNodes = $nodeIds === [] ? [] : ScopeNode::query()
            ->whereIn('id', array_unique($nodeIds))->pluck('id')->all();
        /** @var array<int, string> $liveForms */
        $liveForms = $formIds === [] ? [] : Form::withTrashed()
            ->whereIn('id', array_unique($formIds))->pluck('id')->all();

        return array_values(array_map(
            fn (array $entry): array => $this->savedViewRow($entry, $liveNodes, $liveForms, $applied),
            $resolved,
        ));
    }

    /**
     * @param  array{view: SavedReportView, query: AnalyticsQuery|null, refused: bool, reason: string|null, message: string|null}  $entry
     * @param  array<int, string>  $liveNodes
     * @param  array<int, string>  $liveForms
     * @return array<string, mixed>
     */
    private function savedViewRow(array $entry, array $liveNodes, array $liveForms, AnalyticsQuery $applied): array
    {
        $view = $entry['view'];
        $query = $entry['query'];
        $refused = $entry['refused'];
        $reason = $entry['reason'];
        $message = $entry['message'];
        $staleForms = [];

        if ($query instanceof AnalyticsQuery) {
            $staleForms = array_values(array_diff($query->formIds, $liveForms));

            if ($query->selection === AnalyticsFormSelection::ScopeNode
                && ! in_array($query->scopeNodeId, $liveNodes, true)) {
                $refused = true;
                $reason = 'scope_node_missing';
                $message = 'The area this report groups by has been deleted, so it can no longer be applied.';
            }
        }

        return [
            'id' => $view->id,
            'name' => $view->name,
            'definition' => $view->definition,
            'refused' => $refused,
            'reason' => $reason,
            'message' => $message,
            'stale_form_ids' => $staleForms,
            // Compared THROUGH the VO rather than against the raw definition, so a view written before a
            // default moved still recognises itself as the one currently applied.
            'is_applied' => ! $refused
                && $query instanceof AnalyticsQuery
                && $query->toArray() === $applied->toArray(),
            'created_at' => $this->iso($view->created_at),
            'updated_at' => $this->iso($view->updated_at),
        ];
    }

    /**
     * The filter catalogues. Tenant-scoped, never range-scoped — which is why the page excludes this key
     * from every partial reload.
     *
     * @return array<string, mixed>
     */
    private function options(User $user): array
    {
        return [
            'forms' => $this->formOptions($user),
            'scope_nodes' => $this->scopeNodeOptions(),
            'locales' => $this->localeOptions($user),
            'axes' => [
                ['value' => AnalyticsAxis::Form->value, 'label' => 'Form'],
                ['value' => AnalyticsAxis::Source->value, 'label' => 'Channel'],
                ['value' => AnalyticsAxis::Status->value, 'label' => 'Status'],
                ['value' => AnalyticsAxis::ScopeNode->value, 'label' => 'Area'],
                ['value' => AnalyticsAxis::Locale->value, 'label' => 'Language'],
            ],
            'granularities' => [
                ['value' => AnalyticsGranularity::Day->value, 'label' => 'Daily'],
                ['value' => AnalyticsGranularity::Week->value, 'label' => 'Weekly'],
                ['value' => AnalyticsGranularity::Month->value, 'label' => 'Monthly'],
            ],
            'selections' => [
                ['value' => AnalyticsFormSelection::All->value, 'label' => 'All forms I can see'],
                ['value' => AnalyticsFormSelection::Forms->value, 'label' => 'Only the forms I pick'],
                ['value' => AnalyticsFormSelection::ScopeNode->value, 'label' => 'One area and everything under it'],
            ],
            'statuses' => array_values(array_map(
                fn (SubmissionStatus $case): array => ['value' => $case->value, 'label' => $case->label()],
                // `draft` is never in a countable result, so offering it as a filter would offer a control
                // that can only ever return nothing.
                array_filter(SubmissionStatus::cases(), fn (SubmissionStatus $c): bool => $c !== SubmissionStatus::Draft),
            )),
            'sources' => array_map(
                fn (SubmissionSource $case): array => ['value' => $case->value, 'label' => $case->label()],
                SubmissionSource::cases(),
            ),
            // The full IANA list, which is exactly what the validator checks against — so the picker cannot
            // offer a zone the server will refuse. Affordable only because this whole key ships once per
            // full visit and never on a filter change.
            'timezones' => DateTimeZone::listIdentifiers(),
            'limits' => [
                'max_range_days' => AnalyticsQuery::MAX_RANGE_DAYS,
                'max_form_ids' => AnalyticsQuery::MAX_FORM_IDS,
                'max_top_n' => AnalyticsQuery::MAX_TOP_N,
                'default_top_n' => AnalyticsQuery::DEFAULT_TOP_N,
                'default_range_days' => AnalyticsQuery::DEFAULT_RANGE_DAYS,
            ],
        ];
    }

    /**
     * Exactly the forms the aggregates cover, so the picker cannot offer one whose selection returns zero
     * for a reason the user cannot see. A soft-deleted form is legitimately here (its submissions stay
     * countable for a grant-holder) and says so.
     *
     * @return list<array{value: string, label: string}>
     */
    private function formOptions(User $user): array
    {
        /** @var Collection<int, Form> $forms */
        $forms = Form::withTrashed()
            ->whereIn('forms.id', $this->formSet->resolveAllVisible($user))
            ->orderBy('title')
            ->get(['id', 'title', 'deleted_at']);

        return array_values($forms->map(fn (Form $form): array => [
            'value' => (string) $form->id,
            'label' => $form->trashed() ? $form->title.' (archived)' : $form->title,
        ])->all());
    }

    /**
     * @return list<array{value: string, label: string, depth: int, is_active: bool}>
     */
    private function scopeNodeOptions(): array
    {
        /** @var Collection<int, ScopeNode> $nodes */
        $nodes = ScopeNode::query()->orderBy('path')->get(['id', 'name', 'depth', 'is_active']);

        return array_values($nodes->map(fn (ScopeNode $node): array => [
            'value' => (string) $node->id,
            'label' => $node->name,
            'depth' => $node->depth,
            'is_active' => $node->is_active,
        ])->all());
    }

    /**
     * Derived from what the FORMS declare, not from a `DISTINCT` over `submissions` — that column is
     * unbounded and free-text on the ingest paths, so a distinct scan is both slow and capable of offering
     * junk as a filter.
     *
     * @return list<array{value: string, label: string}>
     */
    private function localeOptions(User $user): array
    {
        /** @var Collection<int, Form> $forms */
        $forms = Form::withTrashed()
            ->whereIn('forms.id', $this->formSet->resolveAllVisible($user))
            ->get(['id', 'default_locale', 'supported_locales']);

        $locales = [];

        foreach ($forms as $form) {
            $locales[] = $form->default_locale;

            foreach ($form->supported_locales as $locale) {
                $locales[] = $locale;
            }
        }

        $unique = array_values(array_unique(array_filter($locales)));
        sort($unique);

        return array_map(fn (string $locale): array => ['value' => $locale, 'label' => $locale], $unique);
    }

    private function iso(?CarbonInterface $value): ?string
    {
        return $value?->toIso8601String();
    }
}
