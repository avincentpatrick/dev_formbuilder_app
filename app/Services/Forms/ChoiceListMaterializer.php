<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldType;
use App\Exceptions\Forms\PublishValidationException;
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
 * 42,000-row list — and `SchemaTreeCloner` strips them again from the next draft ({@see self::withoutListOptions()}).
 * The public schema leaves them out too; the runtime fetches them beside it.
 *
 * ── THE MAPPING, KOBO'S `select_one_from_file` WITH A `choice_filter` ────────────────────────────────────────
 * Level `i` takes its choices from the list it names: each row's `name` is the value and `label` the label (Kobo's
 * `label::…` when there is no plain `label`). Below the first level, the parent is the row's value in the column
 * named after the level above — `provinces.csv`'s `region` column for a level under `region`. A root option's parent
 * is null, which is what the runtime shows at the first level.
 *
 * Refused here, by name: a level naming a list the form does not hold, a list missing its parent column, and a
 * cascade that names lists on some levels only. An unknown parent value is refused by the gate that runs next
 * (`StructuralValidationGate::assertCascadingResolves()`), which checks every option's parent; a repeated value
 * cannot occur, because the parser refuses a repeated name in a file.
 */
final class ChoiceListMaterializer
{
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

            $lists ??= FormVersionChoiceList::query()->where('form_version_id', $draft->id)->get()->keyBy('name');
            $options = $this->optionsFor($field->key, $levels, $lists);

            if ($options instanceof PublishValidationException) {
                $violations[] = $options;

                continue;
            }

            $config['options'] = $options;
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

        foreach ($levels as $index => $level) {
            $key = (string) ($level['key'] ?? '');
            $name = (string) $level['list'];
            $list = $lists->get($name);

            if ($list === null) {
                return PublishValidationException::cascadingConfigInvalid($fieldKey, "level “{$key}” takes its choices from “{$name}”, which this form does not hold — upload {$name}.csv");
            }

            $header = $list->header();
            $nameAt = array_search('name', $header, true);
            $labelAt = self::labelColumn($header);
            $parentAt = null;

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
                $parent = $parentAt === null ? null : ($row[$parentAt] ?? '');

                $options[] = [
                    'level' => $key,
                    'value' => $row[$nameAt] ?? '',
                    'label' => $row[$labelAt] ?? '',
                    'parent' => $parent === '' ? null : $parent,
                ];
            }
        }

        return $options;
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
