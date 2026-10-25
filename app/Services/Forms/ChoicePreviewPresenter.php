<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;

/**
 * What the builder's Preview — and an old version opened read-only — shows of the choices a form takes from elsewhere
 * (M184, `R-d8c2ce84`): another form's answers, a cascade's list files, a question's list file, a Short text's
 * suggestions. Until now the preview said they "appear on the live form, not here", so an author checked a PSGC
 * cascade or a linked list only after publishing it.
 *
 * ── THE SAME LISTS THE LIVE FORM GETS, CUT TO WHAT A PREVIEW NEEDS ─────────────────────────────────────────────────
 * A DRAFT's snapshot is empty until it is published, so its lists are built from its rows the way publishing would build
 * them ({@see ChoiceListMaterializer::forDraft()}) and its links read through {@see LinkedChoiceService::listsForFields()}
 * — the guest page's own reader. A published version's come from its snapshot, as the guest page's do. Each list is cut
 * to {@see SHOWN} choices — per level for a cascade, keeping children of the choices kept above, so the first region
 * still opens onto its provinces — and carries its full size, so the preview can say "the first 200 of 42,010".
 *
 * Shaped like the guest endpoints' payloads, so the runtime's own merge functions take them unchanged.
 *
 * M185 (`R-bb79aec0`, `D161`): a draft's grids too — their rows, cells and choice columns copied from a list or a form, as
 * publishing will copy them ({@see ChoiceListMaterializer::gridsForDraft()}). A published version's are in its snapshot.
 */
final class ChoicePreviewPresenter
{
    /** The most choices one list shows in a preview, per level of a cascade. */
    public const int SHOWN = 200;

    public function __construct(
        private readonly LinkedChoiceService $links,
        private readonly ChoiceListMaterializer $lists,
    ) {}

    /** @return array<string, mixed> */
    public function forDraft(Form $form, FormVersion $draft): array
    {
        $fields = FormField::query()->where('form_version_id', $draft->id)->orderBy('id')->get()
            ->map(static fn (FormField $field): array => ['key' => $field->key, 'field_type' => $field->field_type->value, 'config' => $field->config])
            ->all();
        $built = $this->lists->forDraft($draft);

        return [
            ...$this->payload($this->links->listsForFields($form, $fields), $built['cascades'], $built['flat'], $built['suggestions'], $this->lists->gridsForDraft($draft)),
            // M186 (`R-ed7f3127`): the sets that narrow its choices, as the guest page is served them beside the schema.
            'sets' => $this->links->setsForFields($form, $fields),
        ];
    }

    /**
     * A published version keeps a flat list's choices and a Short text's suggestions in its snapshot, which the view
     * already draws; only a cascade's lists and the live links are served beside it.
     *
     * @return array<string, mixed>
     */
    public function forVersion(Form $form, FormVersion $version): array
    {
        return [
            ...$this->payload($this->links->listsFor($form, $version), ChoiceListMaterializer::listsForBrowser($version), [], [], []),
            'sets' => $this->links->setsFor($form, $version),
        ];
    }

    /**
     * @param  array<string, array{stamp: string, available: bool, truncated: bool, options: list<array{value: string, label: string}>}>  $linked
     * @param  array<string, array{levels: list<string>, options: list<list<int|string|null>>}>  $cascades
     * @param  array<string, list<array{value: string, label: string}>>  $flat
     * @param  array<string, list<string>>  $suggestions
     * @param  array<string, array{rows?: list<array{value: string, label: string}>, cells?: list<array{value: string, label: string}>, columns?: array<string, list<array{value: string, label: string}>>}>  $grids
     * @return array<string, mixed>
     */
    private function payload(array $linked, array $cascades, array $flat, array $suggestions, array $grids): array
    {
        $cut = static fn (array $options): array => ['options' => array_slice($options, 0, self::SHOWN), 'total' => count($options)];

        return [
            // A link's list is already capped at LinkedChoiceService::MAX_CHOICES, and says when it was cut.
            'linked' => (object) $linked,
            'cascades' => (object) array_map(self::cutCascade(...), $cascades),
            'flat' => (object) array_map(static fn (array $options): array => ['options' => array_slice($options, 0, self::SHOWN), 'total' => count($options)], $flat),
            'suggestions' => (object) array_map(static fn (array $items): array => ['suggestions' => array_slice($items, 0, self::SHOWN), 'total' => count($items)], $suggestions),
            // M185: a grid's copied lists, each cut as a flat list is; a column's keyed by the column's value.
            'grids' => (object) array_map(static fn (array $grid): array => array_filter([
                'rows' => isset($grid['rows']) ? $cut($grid['rows']) : null,
                'cells' => isset($grid['cells']) ? $cut($grid['cells']) : null,
                'columns' => isset($grid['columns']) ? (object) array_map($cut, $grid['columns']) : null,
            ], static fn (mixed $part): bool => $part !== null), $grids),
        ];
    }

    /**
     * At most {@see SHOWN} choices a level, each below the first a child of a choice kept above it.
     *
     * @param  array{levels: list<string>, options: list<list<int|string|null>>}  $list
     * @return array{levels: list<string>, options: list<list<int|string|null>>, total: int}
     */
    public static function cutCascade(array $list): array
    {
        $kept = [];
        $counts = [];
        $options = [];

        foreach ($list['options'] as $option) {
            [$level, $value, , $parent] = $option + [0, '', '', null];
            $level = (int) $level;
            if (($counts[$level] ?? 0) >= self::SHOWN || ($level > 0 && ! isset($kept[$level - 1][(string) $parent]))) {
                continue;
            }

            $kept[$level][(string) $value] = true;
            $counts[$level] = ($counts[$level] ?? 0) + 1;
            $options[] = $option;
        }

        return ['levels' => $list['levels'], 'options' => $options, 'total' => count($list['options'])];
    }
}
