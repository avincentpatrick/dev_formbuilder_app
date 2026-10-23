<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Enums\FieldType;

/**
 * A matrix column's own answer rule (M183, `R-5033e87e`, `D129`) — the ONE reading of `config.columns[]` that the save
 * request, the publish gate, the type conversion, Stage 1, the encode page, the exports and the paper share.
 *
 * A column may carry a `type` ({@see self::TYPES}), its own `options` and `required`. No `type` is a single choice. A
 * choice column with no options of its own draws from the grid's `config.cells`, so every grid saved before M183 —
 * untyped columns over one shared list — reads exactly as it did. `D140` keeps `yes_no` out: a yes/no column is a
 * single choice with the Yes / No options.
 *
 * ⛔ THE SEMANTIC ENGINES DO NOT CALL THIS. `SemanticValidator::columnSpecs()` and its TypeScript twin read the same
 * keys on their own, because the two engines must stay mirror images of each other (the golden vectors pin them).
 */
final class MatrixColumns
{
    /** The types a matrix column may take, in the order the builder offers them. */
    public const array TYPES = [
        FieldType::SingleSelect,
        FieldType::MultiSelect,
        FieldType::ShortText,
        FieldType::Integer,
        FieldType::Decimal,
        FieldType::Date,
    ];

    /**
     * The column's type. Absent, blank or not one a grid holds reads as a single choice — the request refuses an
     * unknown type and the publish gate refuses a stored one ({@see self::unknownType()}), so this only ever meets one.
     *
     * @param  array<string, mixed>  $column
     */
    public static function type(array $column): FieldType
    {
        $type = FieldType::tryFrom(is_string($column['type'] ?? null) ? $column['type'] : '');

        return $type !== null && in_array($type, self::TYPES, true) ? $type : FieldType::SingleSelect;
    }

    /**
     * A stored `type` a grid cannot hold (anything outside {@see self::TYPES}), or null when the column's type is fine.
     *
     * @param  array<string, mixed>  $column
     */
    public static function unknownType(array $column): ?string
    {
        $raw = $column['type'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        $type = is_string($raw) ? FieldType::tryFrom($raw) : null;

        return $type !== null && in_array($type, self::TYPES, true) ? null : (is_scalar($raw) ? (string) $raw : 'a list');
    }

    public static function isChoice(FieldType $type): bool
    {
        return $type === FieldType::SingleSelect || $type === FieldType::MultiSelect;
    }

    /**
     * The column's choices as stored option objects: its own when it has any, else the grid's shared `cells`. Empty
     * for a column that takes no choices — and for one whose own choices come from a list or a form and are not copied
     * in yet (M185): a draft's sourced column never shows the shared cells as if they were its own.
     *
     * @param  array<string, mixed>  $column
     * @param  array<string, mixed>  $config  the whole grid config (for `cells`)
     * @return list<array<string, mixed>>
     */
    public static function choices(array $column, array $config): array
    {
        if (! self::isChoice(self::type($column))) {
            return [];
        }

        $own = self::optionObjects($column['options'] ?? null);

        return $own !== [] || self::sourcesOwn($column) ? $own : self::optionObjects($config['cells'] ?? null);
    }

    /**
     * Whether some choice column draws from the shared `cells` — the only case in which a matrix needs them. A column
     * whose choices come from a list or a form (M185) has its own.
     *
     * @param  array<string, mixed>  $config
     */
    public static function needsSharedCells(array $config): bool
    {
        foreach (self::columns($config) as $column) {
            if (self::isChoice(self::type($column)) && self::optionObjects($column['options'] ?? null) === [] && ! self::sourcesOwn($column)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a column declares where its own choices come from — `options_list` or `options_source` present and not null,
     * picked or still being picked, as `App\Services\Forms\ChoiceSource` reads a choice question's.
     *
     * @param  array<string, mixed>  $column
     */
    public static function sourcesOwn(array $column): bool
    {
        return ($column['options_list'] ?? null) !== null || ($column['options_source'] ?? null) !== null;
    }

    /**
     * The column list a Likert matrix keeps when a matrix becomes one: its scale is the columns themselves, so a
     * column's own type, choices, their source (M185) and required flag have nothing to mean there.
     *
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    public static function asScale(array $config): array
    {
        return array_map(
            static fn (array $column): array => array_diff_key($column, ['type' => true, 'options' => true, 'options_list' => true, 'options_source' => true, 'required' => true]),
            self::columns($config),
        );
    }

    /**
     * The stored column objects, skipping anything that is not one.
     *
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    public static function columns(array $config): array
    {
        return self::optionObjects($config['columns'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function optionObjects(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $option) {
            if (is_array($option)) {
                /** @var array<string, mixed> $option */
                $out[] = $option;
            }
        }

        return $out;
    }
}
