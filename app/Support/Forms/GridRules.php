<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Enums\FieldType;

/**
 * M246 (`grid-question-table`) — where a grid holds expressions of its own, in one place. Every reader that walks a
 * question's expressions (the publish gate's type checks, the conversion census, section copy and key rename, the
 * step graph) reads the grid's rules through {@see sites()} and rewrites them through {@see mapExpressions()}, so a
 * grid rule is never the one expression a walk forgot. Mirrored for the builder by
 * `resources/js/components/builder/grid-rules.ts`.
 *
 * The rules, all in `form_fields.config`:
 *  - `rows[].relevant` — show this row only when… (Grid and Likert);
 *  - `columns[].relevant` — show this column only when… (Grid; reads other questions, never `${.col}`);
 *  - `columns[].needed_when` — in each row, this cell is needed only when… (Grid; may read `${.col}` to its left);
 *  - `columns[].required_when` — in each row, this cell is required when… (Grid; likewise).
 *
 * The non-expression settings beside them: `rows[].not_needed` (column values whose cell is "Not needed" in that
 * row) and `rows[].details` (content blocks shown under the row's label).
 */
final class GridRules
{
    public const string ROW_RELEVANT = 'relevant';

    public const string COLUMN_RELEVANT = 'relevant';

    public const string NEEDED_WHEN = 'needed_when';

    public const string REQUIRED_WHEN = 'required_when';

    public const string NOT_NEEDED = 'not_needed';

    public const string DETAILS = 'details';

    /** The column-level rule keys, in the order a row's cells are decided. */
    public const array COLUMN_RULES = [self::COLUMN_RELEVANT, self::NEEDED_WHEN, self::REQUIRED_WHEN];

    /**
     * Every non-blank rule expression a grid's config holds, in rows-then-columns order.
     *
     * `rule` says which setting it is: `row` (a row's show-when), `column` (a column's show-when), `needed` or
     * `required` (a column's per-row rules). `row_scope` is true where `${.col}` may be used — a column's per-row
     * rules only.
     *
     * @return list<array{expression: string, rule: 'row'|'column'|'needed'|'required', row: ?string, column: ?string, row_scope: bool}>
     */
    public static function sites(FieldType $type, mixed $config): array
    {
        if (! $type->isComposite() || ! is_array($config)) {
            return [];
        }

        $sites = [];

        foreach (self::entries($config['rows'] ?? null) as $row) {
            $expression = self::expression($row[self::ROW_RELEVANT] ?? null);
            if ($expression !== null) {
                $sites[] = ['expression' => $expression, 'rule' => 'row', 'row' => self::value($row), 'column' => null, 'row_scope' => false];
            }
        }

        if ($type !== FieldType::Matrix) {
            return $sites;
        }

        foreach (self::entries($config['columns'] ?? null) as $column) {
            foreach ([self::COLUMN_RELEVANT => 'column', self::NEEDED_WHEN => 'needed', self::REQUIRED_WHEN => 'required'] as $key => $rule) {
                $expression = self::expression($column[$key] ?? null);
                if ($expression !== null) {
                    $sites[] = ['expression' => $expression, 'rule' => $rule, 'row' => null, 'column' => self::value($column), 'row_scope' => $rule !== 'column'];
                }
            }
        }

        return $sites;
    }

    /**
     * The config with every grid rule expression passed through `$rewrite` (a blank or non-string rule is left
     * exactly as it is). Any other config, or any other field type, comes back unchanged.
     *
     * @param  callable(string): ?string  $rewrite
     */
    public static function mapExpressions(FieldType $type, mixed $config, callable $rewrite): mixed
    {
        if (! $type->isComposite() || ! is_array($config)) {
            return $config;
        }

        if (is_array($config['rows'] ?? null)) {
            $config['rows'] = self::mapEach($config['rows'], [self::ROW_RELEVANT], $rewrite);
        }

        if ($type === FieldType::Matrix && is_array($config['columns'] ?? null)) {
            $config['columns'] = self::mapEach($config['columns'], self::COLUMN_RULES, $rewrite);
        }

        return $config;
    }

    /** Whether a grid's config holds any rule at all — a row or column condition, or a "Not needed" cell. */
    public static function hasRules(FieldType $type, mixed $config): bool
    {
        if (self::sites($type, $config) !== []) {
            return true;
        }

        if ($type !== FieldType::Matrix || ! is_array($config)) {
            return false;
        }

        foreach (self::entries($config['rows'] ?? null) as $row) {
            if (is_array($row[self::NOT_NEEDED] ?? null) && $row[self::NOT_NEEDED] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @param  list<string>  $keys
     * @param  callable(string): ?string  $rewrite
     * @return array<array-key, mixed>
     */
    private static function mapEach(array $entries, array $keys, callable $rewrite): array
    {
        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach ($keys as $key) {
                if (self::expression($entry[$key] ?? null) !== null) {
                    /** @var string $text */
                    $text = $entry[$key];
                    $entry[$key] = $rewrite($text);
                }
            }

            $entries[$index] = $entry;
        }

        return $entries;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function entries(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, is_array(...)));
    }

    /** @param  array<array-key, mixed>  $entry */
    private static function value(array $entry): ?string
    {
        $value = $entry['value'] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    private static function expression(mixed $text): ?string
    {
        return is_string($text) && trim($text) !== '' ? $text : null;
    }
}
