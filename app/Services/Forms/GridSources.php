<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldType;
use App\Support\Forms\MatrixColumns;

/**
 * Where a grid's lists come from (M185, `R-bb79aec0`, `D161`): its rows, its shared cell choices, and each choice column's
 * own choices — typed here, a list file of its version, or another form's answers.
 *
 * ── ONE VOCABULARY, THE FLAT QUESTION'S ──────────────────────────────────────────────────────────────────────
 * Each place a grid holds a list is a SLOT with three keys, named as T3 named a choice question's (M184):
 *
 *     rows        rows_list     rows_source      Grid and Likert matrix
 *     cells       cells_list    cells_source     Grid — the choices a choice column without its own draws on
 *     options     options_list  options_source   inside one choice column of a Grid
 *
 * {@see view()} renames a slot's keys to `options` / `options_list` / `options_source`, so {@see ChoiceSource::of()},
 * {@see LinkedChoiceService::linkOf()} and the builder's chooser read it unchanged.
 *
 * ── COPIED IN AT PUBLISH (`D161`) ───────────────────────────────────────────────────────────────────────────────
 * A sourced slot's items are written into the version being published ({@see ChoiceListMaterializer}) — a form's
 * answers as well as a list's rows — because every reader of a grid reads its rows frozen: Stage 1 refuses an answered
 * row the config does not hold, both engines loop over the config's rows, and the exports, the paper form and the
 * encode page draw them. The next draft keeps the source and none of the copy ({@see withoutCopies()}).
 */
final class GridSources
{
    /** The most rows a grid takes from a list or a form — a grid of more is not one anybody answers. */
    public const int ROWS_MAX = 200;

    /**
     * The slots a grid of this type holds, in the order the builder shows them. A Likert matrix's scale is its columns,
     * so only its rows may come from elsewhere; a Grid's cells and each of its choice columns may too.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{part: 'rows'|'cells'|'column', at: int|string|null, items: string, list: string, source: string, max: int}>
     */
    public static function slots(FieldType $type, array $config): array
    {
        if ($type !== FieldType::Matrix && $type !== FieldType::LikertMatrix) {
            return [];
        }

        $slots = [['part' => 'rows', 'at' => null, 'items' => 'rows', 'list' => 'rows_list', 'source' => 'rows_source', 'max' => self::ROWS_MAX]];
        if ($type === FieldType::LikertMatrix) {
            return $slots;
        }

        $slots[] = ['part' => 'cells', 'at' => null, 'items' => 'cells', 'list' => 'cells_list', 'source' => 'cells_source', 'max' => ChoiceListMaterializer::FLAT_LIST_MAX];
        foreach ((array) ($config['columns'] ?? []) as $at => $column) {
            if (is_array($column) && MatrixColumns::isChoice(MatrixColumns::type($column))) {
                $slots[] = ['part' => 'column', 'at' => $at, 'items' => 'options', 'list' => 'options_list', 'source' => 'options_source', 'max' => ChoiceListMaterializer::FLAT_LIST_MAX];
            }
        }

        return $slots;
    }

    /**
     * The slot's keys under a flat choice question's names, each present only where the config holds it — a key's
     * presence is what makes a source declared while the author is still picking ({@see ChoiceSource}).
     *
     * @param  array<string, mixed>  $config
     * @param  array{part: string, at: int|string|null, items: string, list: string, source: string, max: int}  $slot
     * @return array<string, mixed>
     */
    public static function view(array $config, array $slot): array
    {
        $holder = self::holder($config, $slot);
        $view = [];
        foreach (['options' => $slot['items'], 'options_list' => $slot['list'], 'options_source' => $slot['source']] as $as => $key) {
            if (array_key_exists($key, $holder)) {
                $view[$as] = $holder[$key];
            }
        }

        return $view;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{part: string, at: int|string|null, items: string, list: string, source: string, max: int}  $slot
     */
    public static function sourceOf(array $config, array $slot): ChoiceSource
    {
        return ChoiceSource::of(self::view($config, $slot));
    }

    /**
     * The config with the slot's items replaced — what publishing writes.
     *
     * @param  array<string, mixed>  $config
     * @param  array{part: string, at: int|string|null, items: string, list: string, source: string, max: int}  $slot
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public static function withItems(array $config, array $slot, array $items): array
    {
        if ($slot['part'] === 'column') {
            $config['columns'][$slot['at']][$slot['items']] = $items;
        } else {
            $config[$slot['items']] = $items;
        }

        return $config;
    }

    /**
     * Whether any slot of the grid takes its list from elsewhere.
     *
     * @param  array<string, mixed>  $config
     */
    public static function declaresAny(FieldType $type, array $config): bool
    {
        foreach (self::slots($type, $config) as $slot) {
            if (! self::sourceOf($config, $slot)->isTyped()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The config a draft cloned from a version carries: every sourced slot's copy taken out again, its source kept. Read
     * by the keys alone — they occur on grids only — so the cloner needs no type; a typed slot is never touched.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function withoutCopies(array $config): array
    {
        foreach (self::slots(FieldType::Matrix, $config) as $slot) {
            if (self::sourceOf($config, $slot)->isTyped()) {
                continue;
            }
            if ($slot['part'] === 'column') {
                unset($config['columns'][$slot['at']][$slot['items']]);
            } else {
                $config[$slot['items']] = [];
            }
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{part: string, at: int|string|null, items: string, list: string, source: string, max: int}  $slot
     * @return array<string, mixed>
     */
    private static function holder(array $config, array $slot): array
    {
        if ($slot['part'] !== 'column') {
            return $config;
        }

        $column = $config['columns'][$slot['at']] ?? null;

        return is_array($column) ? $column : [];
    }
}
