<?php

declare(strict_types=1);

namespace App\Services\Explorer;

use App\Enums\ExplorerAggregate;
use App\Enums\ExplorerBucket;
use App\Enums\ExplorerChartType;
use App\Enums\ExplorerFilterOperator;
use App\Enums\ExplorerSort;
use App\Enums\FormStatus;
use App\Exceptions\Explorer\InvalidExplorerQueryException;
use App\Models\ExplorerChart;
use App\Models\Form;
use App\Models\User;
use App\Services\Explorer\Sources\AgreementSource;
use App\Services\Explorer\Sources\ExplorerFormReach;
use App\Services\Explorer\Sources\ExplorerSource;
use App\Services\Explorer\Sources\GridScopedSource;
use App\Support\Explorer\ExplorerAgreement;
use App\Support\Explorer\ExplorerField;
use App\Support\Explorer\ExplorerQuery;
use DateTimeZone;

/**
 * The props of the data explorer's two pages, and the bodies of its three JSON reads (M236, ADR-0025).
 *
 * The wire shapes are documented once, in `resources/js/components/explore/types.ts`, as derived from the
 * `@return` annotations here.
 *
 * A refusal is DATA on this surface, not an exception: a chart whose form was deleted, or whose author
 * shared it with someone who may not read its source, comes back as `{result: null, refusal: {…}}` so a
 * board of twelve charts shows eleven and a sentence.
 */
final class ExplorerPresenter
{
    /** The most forms the answers source's picker lists. */
    private const int FORM_OPTIONS = 500;

    public function __construct(
        private readonly ExplorerCatalog $catalog,
        private readonly ExplorerQueryRunner $runner,
        private readonly ExplorerChartService $charts,
        private readonly ExplorerFormReach $reach,
        private readonly ExplorerLabels $labels,
    ) {}

    /**
     * The board: every chart this member may open.
     *
     * @return array{charts: list<array<string, mixed>>, can: array{create: bool}, limits: array{max_charts: int}}
     */
    public function index(User $user): array
    {
        $charts = $this->charts->boardFor($user);
        $names = User::query()
            ->whereIn('id', $charts->pluck('user_id')->unique()->all())
            ->pluck('name', 'id')
            ->all();

        return [
            'charts' => array_values($charts
                ->map(fn (ExplorerChart $chart): array => $this->chart($chart, $user, $names))
                ->all()),
            'can' => ['create' => $user->can('create', ExplorerChart::class)],
            'limits' => ['max_charts' => ExplorerChartService::MAX_CHARTS_PER_USER],
        ];
    }

    /**
     * The builder, for a new chart or a saved one.
     *
     * @return array{chart: array<string, mixed>|null, sources: list<array<string, mixed>>, forms: list<array{value: string, label: string}>, options: array<string, mixed>}
     */
    public function builder(User $user, ?ExplorerChart $chart): array
    {
        $names = $chart === null
            ? []
            : User::query()->whereKey($chart->user_id)->pluck('name', 'id')->all();

        return [
            'chart' => $chart === null ? null : $this->chart($chart, $user, $names),
            'sources' => array_map(
                fn (ExplorerSource $source): array => $this->source($source, $user),
                $this->catalog->sourcesFor($user),
            ),
            'forms' => $this->forms($user),
            'options' => [
                'charts' => array_map(static fn (ExplorerChartType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'min_dimensions' => $type->minDimensions(),
                    'max_dimensions' => $type->maxDimensions(),
                    'max_measures' => $type->maxMeasures(1),
                    'max_measures_split' => $type->maxMeasures(2),
                ], ExplorerChartType::cases()),
                'aggregates' => array_map(static fn (ExplorerAggregate $aggregate): array => [
                    'value' => $aggregate->value,
                    'label' => $aggregate->label(),
                    'needs_field' => $aggregate->needsField(),
                ], ExplorerAggregate::cases()),
                'buckets' => array_map(static fn (ExplorerBucket $bucket): array => [
                    'value' => $bucket->value,
                    'label' => $bucket->label(),
                ], ExplorerBucket::cases()),
                'operators' => array_map(static fn (ExplorerFilterOperator $operator): array => [
                    'value' => $operator->value,
                    'label' => $operator->label(),
                    'takes_values' => $operator->takesValues(),
                ], ExplorerFilterOperator::cases()),
                'sorts' => array_map(static fn (ExplorerSort $sort): array => [
                    'value' => $sort->value,
                    'label' => $sort->label(),
                ], ExplorerSort::cases()),
                'timezones' => DateTimeZone::listIdentifiers(),
                'limits' => [
                    'max_dimensions' => ExplorerQuery::MAX_DIMENSIONS,
                    'max_measures' => ExplorerQuery::MAX_MEASURES,
                    'max_filters' => ExplorerQuery::MAX_FILTERS,
                    'max_range_days' => ExplorerQuery::MAX_RANGE_DAYS,
                    'max_limit' => ExplorerQuery::MAX_LIMIT,
                    'default_limit' => ExplorerQuery::DEFAULT_LIMIT,
                ],
                // M250 (`D234`): an agreement list's bounds and the values a new one opens with.
                'agreement' => [
                    'max_picks' => ExplorerAgreement::MAX_PICKS,
                    'max_items' => ExplorerAgreement::MAX_ITEMS,
                    'max_marks' => ExplorerAgreement::MAX_MARKS,
                    'min_threshold' => ExplorerAgreement::MIN_THRESHOLD,
                    'max_threshold' => ExplorerAgreement::MAX_THRESHOLD,
                    'default_threshold' => ExplorerAgreement::DEFAULT_THRESHOLD,
                    'max_min_views' => ExplorerAgreement::MAX_MIN_VIEWS,
                    'default_min_views' => ExplorerAgreement::DEFAULT_MIN_VIEWS,
                ],
            ],
        ];
    }

    /**
     * The fields of one source — asked for on its own when the source needs a form, because a form's
     * questions are not known until the form is.
     *
     * M247 (`D231`): a source that reads one grid also lists the form's grids it may chart (`grids`), and once a grid is
     * named, its fields and notes are that grid's.
     *
     * M250 (`D234`): the agreement source also lists what an author may put on the list (`items`) — every choice
     * question, every choice column of a question table, every Likert grid — in the form's order.
     *
     * @return array{fields: list<array<string, mixed>>, notes: list<string>, grids?: list<array{value: string, label: string}>, items?: list<array<string, mixed>>}
     */
    public function fields(User $user, string $sourceKey, ?string $formId, ?string $grid = null): array
    {
        $source = $this->catalog->source($sourceKey);

        if ($source === null) {
            throw InvalidExplorerQueryException::unknownSource($sourceKey);
        }

        if (! $source->allows($user)) {
            throw InvalidExplorerQueryException::sourceForbidden(strtolower($source->label()));
        }

        $form = null;

        if ($source->requiresForm()) {
            $form = $formId === null ? null : $this->catalog->form($user, $formId);

            if ($form === null) {
                throw $formId === null
                    ? InvalidExplorerQueryException::formRequired()
                    : InvalidExplorerQueryException::formUnavailable();
            }
        }

        if ($source instanceof GridScopedSource && $form !== null) {
            $grids = [];
            foreach ($source->grids($user, $form) as $value => $label) {
                $grids[] = ['value' => (string) $value, 'label' => $label];
            }
            $bound = $grid === null ? null : $source->forGrid($user, $form, $grid);
            if ($grid !== null && $bound === null) {
                throw InvalidExplorerQueryException::gridUnavailable();
            }
            $read = $bound ?? $source;

            return [
                'fields' => $this->fieldRows($read->fields($user, $form), $user),
                'notes' => $read->notes($user, $form),
                'grids' => $grids,
            ];
        }

        if ($source instanceof AgreementSource && $form !== null) {
            return [
                'fields' => $this->fieldRows($source->fields($user, $form), $user),
                'notes' => $source->notes($user, $form),
                'items' => $source->picks($form),
            ];
        }

        return [
            'fields' => $this->fieldRows($source->fields($user, $form), $user),
            'notes' => $source->notes($user, $form),
        ];
    }

    /**
     * Run a declaration, or say why it cannot be run.
     *
     * @return array{result: array<string, mixed>|null, refusal: array{reason: string, message: string}|null}
     */
    public function run(User $user, ExplorerQuery $query): array
    {
        try {
            return ['result' => $this->runner->run($query, $user), 'refusal' => null];
        } catch (InvalidExplorerQueryException $e) {
            return ['result' => null, 'refusal' => ['reason' => $e->reason(), 'message' => $e->getMessage()]];
        }
    }

    /**
     * Run a saved chart as the member opening it.
     *
     * @return array{result: array<string, mixed>|null, refusal: array{reason: string, message: string}|null}
     */
    public function result(User $user, ExplorerChart $chart): array
    {
        try {
            return $this->run($user, ExplorerQuery::fromArray($chart->definition));
        } catch (InvalidExplorerQueryException $e) {
            return ['result' => null, 'refusal' => ['reason' => $e->reason(), 'message' => $e->getMessage()]];
        }
    }

    /**
     * M250 (`D234`): why a saved list cannot be downloaded right now, or null when it can — asked before the stream
     * starts, through the same checks a save runs, so a list whose question has gone is a toast and not a broken file.
     */
    public function exportRefusal(User $user, ExplorerChart $chart): ?string
    {
        try {
            $this->runner->assertRunnable(ExplorerQuery::fromArray($chart->definition), $user);

            return null;
        } catch (InvalidExplorerQueryException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param  array<array-key, mixed>  $names  user id → name, for the readers `users` RLS lets this member see
     * @return array<string, mixed>
     */
    private function chart(ExplorerChart $chart, User $user, array $names): array
    {
        $mine = $chart->user_id === $user->getKey();
        $source = $this->catalog->source((string) ($chart->definition['source'] ?? ''));

        return [
            'id' => (string) $chart->getKey(),
            'name' => $chart->name,
            'definition' => $chart->definition,
            'is_shared' => $chart->is_shared,
            'is_mine' => $mine,
            'owner' => $mine ? null : (string) ($names[$chart->user_id] ?? 'A former member'),
            'source_label' => $source?->label(),
            'can' => [
                'update' => $user->can('update', $chart),
                'delete' => $user->can('delete', $chart),
                // M250 (`D234`): only an agreement list downloads, and only for a reader who may download the form's responses.
                'export' => $user->can('export', $chart),
            ],
            'updated_at' => $chart->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function source(ExplorerSource $source, User $user): array
    {
        return [
            'key' => $source->key(),
            'label' => $source->label(),
            'description' => $source->description(),
            'row_noun' => $source->rowNoun(),
            'requires_form' => $source->requiresForm(),
            // M247 (`D231`): a chart on this source names one of the form's grids too.
            'requires_grid' => $source instanceof GridScopedSource,
            // M250 (`D234`): the chart types this source can fill; the agreement source fills only its list.
            'charts' => array_map(static fn (ExplorerChartType $type): string => $type->value, $source->charts()),
            'default_range_days' => $source->defaultRangeDays(),
            'range_field' => $source->rangeField(),
            // A source that needs a form ships the fields every form has; the questions follow by fetch.
            'fields' => $this->fieldRows($source->fields($user, null), $user),
        ];
    }

    /**
     * A field that holds an id — a form, a member — is sent with the names of its values, so a filter on it
     * offers names to pick. One lookup per directory per request, however many fields share it.
     *
     * @param  list<ExplorerField>  $fields
     * @return list<array<string, mixed>>
     */
    private function fieldRows(array $fields, User $user): array
    {
        $directories = [];

        return array_map(function (ExplorerField $field) use (&$directories, $user): array {
            if ($field->lookup === null) {
                return $field->toArray();
            }

            $directories[$field->lookup] ??= $this->labels->directory($field->lookup, $user);

            return $field->toArray($directories[$field->lookup]);
        }, $fields);
    }

    /**
     * The forms whose answers this member may chart: reachable, not deleted, and published at least once —
     * a form that was never published has collected nothing.
     *
     * @return list<array{value: string, label: string}>
     */
    private function forms(User $user): array
    {
        if ($this->catalog->source('answers')?->allows($user) !== true) {
            return [];
        }

        $forms = Form::query()
            ->whereIn('forms.id', $this->reach->formIds($user))
            ->where('forms.status', '!=', FormStatus::Draft->value)
            ->orderBy('forms.title')
            ->limit(self::FORM_OPTIONS)
            ->get(['forms.id', 'forms.title']);

        $options = [];

        foreach ($forms as $form) {
            $options[] = ['value' => (string) $form->getKey(), 'label' => (string) $form->title];
        }

        return $options;
    }
}
