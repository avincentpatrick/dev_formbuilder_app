<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldType;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\FormVersionChoiceList;
use Illuminate\Support\Collection;

/**
 * Turns a cascade's CSV choice lists into its options, in the version being published (M141, `R-f69aab42`, `D95`).
 *
 * ── WHY AT PUBLISH, AND INTO THE PUBLISHED FIELD'S OWN CONFIG ─────────────────────────────────────────────────
 * Every server-side reader of a published cascade reads `form_fields.config.options`: `SemanticValidator` for every
 * submission channel, `SchemaValueFormatter` for every export and connector, OCR's answer reader, the encode page.
 * Writing the list there makes all of them right with no change, and a published version is frozen, so an answer is
 * always checked against the list it was chosen from. The DRAFT keeps no options — the builder never carries a
 * 42,000-row list — and `SchemaTreeCloner` strips them again from the next draft ({@see self::forNextDraft()}).
 * The public schema leaves a cascade's out too; the runtime fetches them beside it.
 *
 * ── A SINGLE CHOICE, MULTIPLE CHOICE OR DROPDOWN FROM A LIST FILE (M184, `R-3e7c81bb`) ──────────────────────────
 * `config.options_list` names one list; publishing writes its rows as the question's options, exactly as for a cascade,
 * so every reader above stays plain. Unlike a cascade's, those options STAY in the public schema: a browser running the
 * app cached before the deploy merges only cascades' lists, and the offline list cache can be evicted apart from the
 * schema — so the list is capped at {@see self::FLAT_LIST_MAX} rows instead (a longer one is a cascade's, or a later row's).
 *
 * ── A GRID'S ROWS, CELLS AND COLUMNS' CHOICES (M185, `R-bb79aec0`, `D161`) ────────────────────────────────────
 * From a list file OR another form's answers, both copied in here ({@see GridSources}): every reader of a grid reads its
 * rows frozen, so a live row set would break them all. Publishing again picks up new answers; a link that is unfinished or
 * refused is left empty for `LinkedChoiceGate` to name.
 *
 * ── A CASCADE ON THE BUILT-IN PHILIPPINE PLACES (M186, `R-70c7673a`, `D131`) ─────────────────────────────────────
 * Levels named `psgc:region` … `psgc:barangay` take their rows from the platform's `psgc_places`, the newest quarter
 * loaded ({@see PsgcLists}), instead of the version's own files; everything after — the options written here, the strip,
 * the serve-beside — is the same, so a published form keeps its quarter.
 *
 * ── THE MAPPING, KOBO'S `select_one_from_file` WITH A `choice_filter` ────────────────────────────────────────
 * Level `i` takes its choices from the list it names: each row's `name` is the value and `label` the label (Kobo's
 * `label::…` when there is no plain `label`). Below the first level, the parent is the row's value in the column
 * named after the level above — `provinces.csv`'s `region` column for a level under `region`. A root option's parent
 * is null, which is what the runtime shows at the first level.
 *
 * Refused here, by name: a level naming a list the form does not hold, a list missing its parent column, and a
 * cascade that names lists on some levels only. Since M184 (`R-1059e9bd`) a row whose parent the list above does not
 * hold is refused here too, naming the file and the row and the likeliest cause — Excel turning a code like 01 into 1 —
 * where the gate that runs next (`StructuralValidationGate::assertCascadingResolves()`) could name only the option; it
 * still checks every typed cascade. A repeated value cannot occur, because the parser refuses a repeated name in a file.
 */
final class ChoiceListMaterializer
{
    /**
     * M184 (`R-3e7c81bb`): the most rows a Single choice, Multiple choice or Dropdown takes from one list. Its options are
     * written into the published version and the schema every browser downloads (not served beside it, as a cascade's
     * are: an app cached before a deploy merges cascades only), so the list is bounded; a longer list is a cascade's.
     */
    public const int FLAT_LIST_MAX = 5_000;

    /** The question types a list file may feed directly — the ones that may link to another form's answers too. */
    private const FLAT_TYPES = [FieldType::SingleSelect, FieldType::MultiSelect, FieldType::Dropdown];

    /**
     * M185 (`D161`): a grid copies another form's answers in at publish, read through the same consent a live list is.
     * M186 (`R-70c7673a`): a cascade bound to the built-in Philippine places reads them from the platform's table.
     */
    public function __construct(private readonly LinkedChoiceService $links, private readonly PsgcLists $psgc) {}

    /** Is every level of this cascade a list? Then its options come from the lists, and the draft holds none. */
    public static function isListBacked(mixed $config): bool
    {
        $levels = is_array($config) ? ($config['levels'] ?? null) : null;

        if (! is_array($levels) || $levels === []) {
            return false;
        }

        foreach ($levels as $level) {
            if (! is_array($level) || ! is_string($level['list'] ?? null) || $level['list'] === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * The config with a list-backed cascade's options emptied — for the draft cloned after a publish, and for every
     * payload that must not carry the list.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function withoutListOptions(array $config): array
    {
        if (self::isListBacked($config)) {
            $config['options'] = [];
        }

        return $config;
    }

    /**
     * The config a draft cloned from a version carries — after a publish, or a restore ({@see SchemaTreeCloner}): every
     * option a list wrote is taken out again, so the draft names its lists and holds none of their rows. A list-backed
     * cascade's, as {@see withoutListOptions()} does, since M184 a choice question bound to a list file, and since M185 a
     * grid's rows, cells and columns' choices copied in from a list or a form ({@see GridSources::withoutCopies()}).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function forNextDraft(array $config): array
    {
        if (is_string($config['options_list'] ?? null) && $config['options_list'] !== '') {
            $config['options'] = [];
        }
        // A Short text's suggestions (M184, `R-596d6afd`) are written at publish or served live, never kept in a draft.
        if (is_array($config['suggestions_source'] ?? null)) {
            unset($config['suggestions']);
        }

        // M185 (`D161`): a grid's rows, cells and columns' choices copied in from a list or a form — the copy goes, the source stays.
        return self::withoutListOptions(GridSources::withoutCopies($config));
    }

    /**
     * A published version's CSV-backed cascades, as the guest runtime fetches them beside the schema — keyed by the
     * question's key, each option a tuple to halve the bytes of a 42,000-row list: `[level index, value, label,
     * parent]`, the parent null at the first level. Read from the frozen snapshot, so it is the list the version was
     * published with.
     *
     * @return array<string, array{levels: list<string>, options: list<list<int|string|null>>}>
     */
    public static function listsForBrowser(FormVersion $version): array
    {
        $snapshot = $version->getAttribute('schema_snapshot');
        $fields = is_array($snapshot) && is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];
        $lists = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ($field['field_type'] ?? null) !== FieldType::CascadingSelect->value || ! self::isListBacked($field['config'] ?? null)) {
                continue;
            }

            $config = (array) $field['config'];
            $levels = array_values(array_map(static fn (mixed $level): string => is_array($level) ? (string) ($level['key'] ?? '') : '', (array) $config['levels']));
            $index = array_flip($levels);
            $options = [];

            foreach ((array) ($config['options'] ?? []) as $option) {
                if (! is_array($option) || ! isset($index[(string) ($option['level'] ?? '')])) {
                    continue;
                }

                $parent = $option['parent'] ?? null;
                $options[] = [
                    $index[(string) $option['level']],
                    (string) ($option['value'] ?? ''),
                    (string) ($option['label'] ?? ''),
                    $parent === null ? null : (string) $parent,
                ];
            }

            $lists[(string) ($field['key'] ?? '')] = ['levels' => $levels, 'options' => $options];
        }

        return $lists;
    }

    /**
     * M184 (`R-d8c2ce84`): a DRAFT's list-bound questions as publishing would write them, for the builder's preview —
     * which otherwise has nothing to show, the draft holding only the lists' names. A cascade comes in the guest
     * payload's tuple shape ({@see listsForBrowser()}). A question whose list cannot be used is left out: the builder says
     * why where the list is chosen, and publish refuses it in words.
     *
     * @return array{cascades: array<string, array{levels: list<string>, options: list<list<int|string|null>>}>, flat: array<string, list<array{value: string, label: string}>>, suggestions: array<string, list<string>>}
     */
    public function forDraft(FormVersion $draft): array
    {
        $built = ['cascades' => [], 'flat' => [], 'suggestions' => []];
        // No early return on a draft without uploaded lists: a cascade on the built-in Philippine places needs none (M186).
        $lists = FormVersionChoiceList::query()->where('form_version_id', $draft->id)->get()->keyBy('name');

        foreach (FormField::query()->where('form_version_id', $draft->id)->orderBy('id')->get() as $field) {
            $config = (array) $field->config;

            if ($field->field_type === FieldType::CascadingSelect && self::isListBacked($config)) {
                $levels = array_values(array_filter((array) $config['levels'], is_array(...)));
                $options = PsgcLists::declaresAny($levels) ? $this->psgc->options($field->key, $levels) : $this->optionsFor($field->key, $levels, $lists);
                if (is_array($options)) {
                    $keys = array_map(static fn (array $level): string => (string) ($level['key'] ?? ''), $levels);
                    $index = array_flip($keys);
                    $built['cascades'][$field->key] = [
                        'levels' => $keys,
                        'options' => array_map(static fn (array $o): array => [$index[$o['level']] ?? 0, $o['value'], $o['label'], $o['parent']], $options),
                    ];
                }
            } elseif (($config['options_list'] ?? null) !== null) {
                $options = $this->flatOptionsFor($field, $config, $lists);
                if (is_array($options)) {
                    $built['flat'][$field->key] = $options;
                }
            } elseif ($field->field_type === FieldType::ShortText && (is_array($config['suggestions_source'] ?? null) && ($config['suggestions_source']['kind'] ?? null) === 'list')) {
                $suggestions = $this->suggestionsFor($field->key, $config, $lists);
                if (is_array($suggestions)) {
                    $built['suggestions'][$field->key] = $suggestions;
                }
            }
        }

        return $built;
    }

    /**
     * M185 (`R-bb79aec0`, `D161`): a DRAFT's sourced grids as publishing would copy them in, for the builder's preview — each
     * grid's rows, cells and choice columns (by the column's value) that can be built today. A slot that cannot is left out;
     * the chooser says why, and publish refuses it in words.
     *
     * @return array<string, array{rows?: list<array{value: string, label: string}>, cells?: list<array{value: string, label: string}>, columns?: array<string, list<array{value: string, label: string}>>}>
     */
    public function gridsForDraft(FormVersion $draft): array
    {
        $grids = [];
        $lists = null;
        $destination = null;

        $fields = FormField::query()
            ->where('form_version_id', $draft->id)
            ->whereIn('field_type', [FieldType::Matrix->value, FieldType::LikertMatrix->value])
            ->orderBy('id')
            ->get();

        foreach ($fields as $field) {
            $config = (array) $field->config;
            if (! GridSources::declaresAny($field->field_type, $config)) {
                continue;
            }

            $lists ??= FormVersionChoiceList::query()->where('form_version_id', $draft->id)->get()->keyBy('name');
            $destination ??= Form::query()->whereKey($draft->form_id)->first();
            $built = [];

            foreach (GridSources::slots($field->field_type, $config) as $slot) {
                $items = $this->slotItems($field->key, $config, $slot, $lists, $destination);
                if (! is_array($items)) {
                    continue;
                }
                if ($slot['part'] === 'column') {
                    $built['columns'][(string) ($config['columns'][$slot['at']]['value'] ?? '')] = $items;
                } else {
                    $built[$slot['part']] = $items;
                }
            }

            if ($built !== []) {
                $grids[$field->key] = $built;
            }
        }

        return $grids;
    }

    /**
     * Write each list-backed cascade's options into the draft being published. Its fields are already locked.
     *
     * @throws PublishValidationException naming every cascade whose lists cannot be read
     */
    public function materialize(FormVersion $draft): void
    {
        /** @var Collection<string, FormVersionChoiceList>|null $lists */
        $lists = null;
        $violations = [];

        $fields = FormField::query()
            ->where('form_version_id', $draft->id)
            ->where('field_type', FieldType::CascadingSelect->value)
            ->orderBy('id')
            ->get();

        foreach ($fields as $field) {
            $config = (array) $field->config;
            $levels = array_values(array_filter((array) ($config['levels'] ?? []), is_array(...)));
            $named = array_filter($levels, static fn (array $level): bool => is_string($level['list'] ?? null) && $level['list'] !== '');

            if ($named === []) {
                continue;
            }

            if (! self::isListBacked($config)) {
                $violations[] = PublishValidationException::cascadingConfigInvalid($field->key, 'every level must take its choices from a list, or none may');

                continue;
            }

            if (PsgcLists::declaresAny($levels)) {
                $options = $this->psgc->options($field->key, $levels);
            } else {
                $lists ??= FormVersionChoiceList::query()->where('form_version_id', $draft->id)->get()->keyBy('name');
                $options = $this->optionsFor($field->key, $levels, $lists);
            }

            if ($options instanceof PublishValidationException) {
                $violations[] = $options;

                continue;
            }

            $config['options'] = $options;
            $field->forceFill(['config' => $config])->save();
        }

        // M184 (`R-3e7c81bb`): a choice question bound to a list file — its options written the same way, over any typed
        // ones a template, a library item or a restore carried beside the list's name.
        $bound = FormField::query()
            ->where('form_version_id', $draft->id)
            ->whereIn('field_type', [FieldType::SingleSelect->value, FieldType::MultiSelect->value, FieldType::Dropdown->value, FieldType::LikertScale->value])
            ->whereNotNull('config->options_list')
            ->orderBy('id')
            ->get();

        foreach ($bound as $field) {
            $config = (array) $field->config;
            $lists ??= FormVersionChoiceList::query()->where('form_version_id', $draft->id)->get()->keyBy('name');
            $options = $this->flatOptionsFor($field, $config, $lists);

            if ($options instanceof PublishValidationException) {
                $violations[] = $options;

                continue;
            }

            $config['options'] = $options;
            $field->forceFill(['config' => $config])->save();
        }

        // M184 (`R-596d6afd`): a Short text whose suggestions come from a list file — its labels written the same way, as
        // `config.suggestions`. Suggestions only: the answer stays free text, and no engine reads them.
        $suggesting = FormField::query()
            ->where('form_version_id', $draft->id)
            ->where('field_type', FieldType::ShortText->value)
            ->where('config->suggestions_source->kind', 'list')
            ->orderBy('id')
            ->get();

        foreach ($suggesting as $field) {
            $config = (array) $field->config;
            $lists ??= FormVersionChoiceList::query()->where('form_version_id', $draft->id)->get()->keyBy('name');
            $suggestions = $this->suggestionsFor($field->key, $config, $lists);

            if ($suggestions instanceof PublishValidationException) {
                $violations[] = $suggestions;

                continue;
            }

            $config['suggestions'] = $suggestions;
            $field->forceFill(['config' => $config])->save();
        }

        // M185 (`R-bb79aec0`, `D161`): a grid's rows, its shared cells and a choice column's choices from a list file or another
        // form — copied in, so every reader of the published grid reads plain rows and choices, frozen with the version. A link
        // that is unfinished or refused is left for `LinkedChoiceGate` to name, so one fault is said once.
        $grids = FormField::query()
            ->where('form_version_id', $draft->id)
            ->whereIn('field_type', [FieldType::Matrix->value, FieldType::LikertMatrix->value])
            ->orderBy('id')
            ->get();
        $destination = null;

        foreach ($grids as $field) {
            $config = (array) $field->config;
            if (! GridSources::declaresAny($field->field_type, $config)) {
                continue;
            }

            $lists ??= FormVersionChoiceList::query()->where('form_version_id', $draft->id)->get()->keyBy('name');
            $destination ??= Form::query()->whereKey($draft->form_id)->first();
            $faults = [];

            foreach (GridSources::slots($field->field_type, $config) as $slot) {
                $items = $this->slotItems($field->key, $config, $slot, $lists, $destination);
                if ($items instanceof PublishValidationException) {
                    $faults[] = $items;
                } elseif (is_array($items)) {
                    $config = GridSources::withItems($config, $slot, $items);
                }
            }

            if ($faults !== []) {
                array_push($violations, ...$faults);

                continue;
            }

            $field->forceFill(['config' => $config])->save();
        }

        if ($violations !== []) {
            throw count($violations) === 1 ? $violations[0] : PublishValidationException::several($violations);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $levels
     * @param  Collection<string, FormVersionChoiceList>  $lists
     * @return list<array{level: string, value: string, label: string, parent: ?string}>|PublishValidationException
     */
    private function optionsFor(string $fieldKey, array $levels, Collection $lists): array|PublishValidationException
    {
        $options = [];
        $known = []; // each level's values, so a row's parent is checked here, where its file can be named

        foreach ($levels as $index => $level) {
            $key = (string) ($level['key'] ?? '');
            $name = (string) $level['list'];
            $list = $lists->get($name);

            if ($list === null) {
                return PublishValidationException::cascadingConfigInvalid($fieldKey, "level “{$key}” takes its choices from “{$name}”, which this form does not hold — upload {$name}.csv or {$name}.xlsx");
            }

            $header = $list->header();
            $nameAt = array_search('name', $header, true);
            $labelAt = self::labelColumn($header);
            $parentAt = null;
            $parentColumn = '';

            if ($index > 0) {
                $parentColumn = mb_strtolower((string) ($levels[$index - 1]['key'] ?? ''));
                $parentAt = array_search($parentColumn, $header, true);

                if ($parentAt === false) {
                    return PublishValidationException::cascadingConfigInvalid($fieldKey, "“{$name}” has no column named “{$parentColumn}”, the level above “{$key}”");
                }
            }

            if ($nameAt === false || $labelAt === null) {
                return PublishValidationException::cascadingConfigInvalid($fieldKey, "“{$name}” has no name or label column");
            }

            foreach ($list->dataRows() as $row) {
                $value = $row[$nameAt] ?? '';
                $parent = $parentAt === null ? null : ($row[$parentAt] ?? '');

                if ($parent !== null && ! isset($known[$index - 1][$parent])) {
                    return PublishValidationException::cascadingConfigInvalid($fieldKey, self::parentProblem($list, $value, $parent, $parentColumn));
                }

                $known[$index][$value] = true;
                $options[] = [
                    'level' => $key,
                    'value' => $value,
                    'label' => $row[$labelAt] ?? '',
                    'parent' => $parent === '' ? null : $parent,
                ];
            }
        }

        return $options;
    }

    /**
     * A flat question's options from its list: each row's `name` the value, its `label` the label; any other column, a
     * cascade's parent column among them, is ignored.
     *
     * @param  array<string, mixed>  $config
     * @param  Collection<string, FormVersionChoiceList>  $lists
     * @return list<array{value: string, label: string}>|PublishValidationException
     */
    private function flatOptionsFor(FormField $field, array $config, Collection $lists): array|PublishValidationException
    {
        $key = $field->key;
        $name = is_string($config['options_list'] ?? null) ? $config['options_list'] : '';

        if (! in_array($field->field_type, self::FLAT_TYPES, true)) {
            return PublishValidationException::choiceOptionsInvalid($key, 'a Likert scale keeps its own points and cannot take them from a list file');
        }
        if (LinkedChoiceService::declaresLink($config)) {
            return PublishValidationException::choiceOptionsInvalid($key, 'it takes its choices from a list file and from another form — choose one');
        }
        if ($name === '') {
            return PublishValidationException::choiceOptionsInvalid($key, 'no list file is chosen for its choices');
        }

        $list = $lists->get($name);
        if ($list === null) {
            return PublishValidationException::choiceOptionsInvalid($key, "it takes its choices from “{$name}”, which this form does not hold — upload {$name}.csv or {$name}.xlsx");
        }

        $pairs = self::pairsOf($list);
        if ($pairs === null) {
            return PublishValidationException::choiceOptionsInvalid($key, "“{$name}” has no name or label column");
        }

        if (count($pairs) > self::FLAT_LIST_MAX) {
            return PublishValidationException::choiceOptionsInvalid($key, '“'.$name.'” holds '.number_format(count($pairs)).' choices, and one question takes at most '.number_format(self::FLAT_LIST_MAX).' — use a cascading select for a longer list');
        }

        return $pairs;
    }

    /**
     * M185 (`R-bb79aec0`, `D161`): one grid slot's items as publishing copies them in — a list file's rows, or another
     * form's answers (`D85`: an answer's text is its value) — or null when the slot is typed here, or takes another form's
     * answers through a link that is unfinished or refused, which `LinkedChoiceGate` names.
     *
     * @param  array<string, mixed>  $config
     * @param  array{part: string, at: int|string|null, items: string, list: string, source: string, max: int}  $slot
     * @param  Collection<string, FormVersionChoiceList>  $lists
     * @return list<array{value: string, label: string}>|PublishValidationException|null
     */
    private function slotItems(string $key, array $config, array $slot, Collection $lists, ?Form $destination): array|PublishValidationException|null
    {
        $view = GridSources::view($config, $slot);
        $source = ChoiceSource::of($view);
        if ($source->isTyped()) {
            return null;
        }

        $what = GridSources::name($config, $slot);
        $unit = $slot['part'] === 'rows' ? 'rows' : 'choices';

        if ($source === ChoiceSource::Form) {
            if (($view['options_list'] ?? null) !== null) {
                return PublishValidationException::gridSourceInvalid($key, $what, 'they come from a list file and from another form — choose one');
            }
            $link = LinkedChoiceService::linkOf($view);
            $list = $link === null || $destination === null ? null : $this->links->currentList($destination, $link);
            if ($list === null || ! $list['available']) {
                return null;
            }
            if ($list['options'] === []) {
                return PublishValidationException::gridSourceInvalid($key, $what, 'the question they come from has no answers yet');
            }
            // Rows past their cap are refused — a grid of a thousand rows is answered by nobody; a column's choices keep the
            // first 1,000, as the same list does live on a Dropdown.
            if ($slot['part'] === 'rows' && ($list['truncated'] || count($list['options']) > $slot['max'])) {
                $count = $list['truncated'] ? 'more than '.number_format(LinkedChoiceService::MAX_CHOICES) : number_format(count($list['options']));

                return PublishValidationException::gridSourceInvalid($key, $what, "the question they come from has {$count} different answers, and a grid takes at most ".number_format($slot['max']).' rows');
            }

            return $list['options'];
        }

        $name = is_string($view['options_list'] ?? null) ? $view['options_list'] : '';
        if ($name === '') {
            return PublishValidationException::gridSourceInvalid($key, $what, 'no list file is chosen for them');
        }
        $file = $lists->get($name);
        if ($file === null) {
            return PublishValidationException::gridSourceInvalid($key, $what, "they come from “{$name}”, which this form does not hold — upload {$name}.csv or {$name}.xlsx");
        }
        $pairs = self::pairsOf($file);
        if ($pairs === null) {
            return PublishValidationException::gridSourceInvalid($key, $what, "“{$name}” has no name or label column");
        }
        if ($pairs === []) {
            return PublishValidationException::gridSourceInvalid($key, $what, "“{$name}” holds no rows");
        }
        if (count($pairs) > $slot['max']) {
            return PublishValidationException::gridSourceInvalid($key, $what, '“'.$name.'” holds '.number_format(count($pairs)).' rows, and a grid takes at most '.number_format($slot['max'])." {$unit} from one list");
        }

        return $pairs;
    }

    /**
     * A list's rows as choices — each row's `name` the value, its `label` the label; any other column, a cascade's parent
     * column among them, is ignored — or null when the list has no name or no label column.
     *
     * @return list<array{value: string, label: string}>|null
     */
    private static function pairsOf(FormVersionChoiceList $list): ?array
    {
        $header = $list->header();
        $nameAt = array_search('name', $header, true);
        $labelAt = self::labelColumn($header);
        if ($nameAt === false || $labelAt === null) {
            return null;
        }

        return array_map(static fn (array $row): array => ['value' => $row[$nameAt] ?? '', 'label' => $row[$labelAt] ?? ''], $list->dataRows());
    }

    /**
     * A Short text's suggestions from its list: each row's label, each once, in the file's order.
     *
     * @param  array<string, mixed>  $config
     * @param  Collection<string, FormVersionChoiceList>  $lists
     * @return list<string>|PublishValidationException
     */
    private function suggestionsFor(string $key, array $config, Collection $lists): array|PublishValidationException
    {
        $source = is_array($config['suggestions_source'] ?? null) ? $config['suggestions_source'] : [];
        $name = is_string($source['name'] ?? null) ? $source['name'] : '';

        if ($name === '') {
            return PublishValidationException::suggestionsInvalid($key, 'no list file is chosen for its suggestions');
        }

        $list = $lists->get($name);
        if ($list === null) {
            return PublishValidationException::suggestionsInvalid($key, "it suggests answers from “{$name}”, which this form does not hold — upload {$name}.csv or {$name}.xlsx");
        }

        $labelAt = self::labelColumn($list->header());
        if ($labelAt === null) {
            return PublishValidationException::suggestionsInvalid($key, "“{$name}” has no label column");
        }

        $rows = $list->dataRows();
        if (count($rows) > self::FLAT_LIST_MAX) {
            return PublishValidationException::suggestionsInvalid($key, '“'.$name.'” holds '.number_format(count($rows)).' rows, and one question suggests at most '.number_format(self::FLAT_LIST_MAX));
        }

        $labels = [];
        foreach ($rows as $row) {
            $label = $row[$labelAt] ?? '';
            if ($label !== '') {
                $labels[$label] = true;
            }
        }

        return array_map('strval', array_keys($labels));
    }

    /**
     * M184 (`R-1059e9bd`): a row whose parent the list above does not hold, with its file and its name — and the likeliest
     * cause, because Excel reads a code such as `01` as the number 1 and saves it so, after which the child's parent column
     * names no row. (A typo reads the same way; the sentence says "may".)
     */
    private static function parentProblem(FormVersionChoiceList $list, string $value, string $parent, string $column): string
    {
        $what = $parent === ''
            ? "leaves its “{$column}” column empty"
            : "names “{$parent}” as its {$column}, which the list above does not hold";

        return "“{$value}” in {$list->file_name} {$what} — if the file was saved in Excel, a code like 01 may have become 1: keep codes as text, or use letters";
    }

    /** @param  list<string>  $header */
    private static function labelColumn(array $header): ?int
    {
        $exact = array_search('label', $header, true);

        if ($exact !== false) {
            return $exact;
        }

        foreach ($header as $index => $column) {
            if (str_starts_with($column, 'label')) {
                return $index;
            }
        }

        return null;
    }
}
