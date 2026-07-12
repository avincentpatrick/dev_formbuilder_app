<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use Illuminate\Support\Collection;

/**
 * Read model for the manual-encoding page (Increment F4b) — turns a form's published version into the flat,
 * render-ready schema the Encode.vue page walks. Fields are grouped into their sections (ungrouped fields
 * lead as a section-less block), in document order.
 *
 * "Render-supported, mark-the-rest" (confirmed scope decision): the ~14 Phase-1 scalar field types render a
 * real input; everything else (advanced geo/media/matrix/likert/cascading, duration, signature) is emitted
 * with `supported = false` so the page shows a read-only "not available for manual entry (Phase 2)" notice
 * rather than silently dropping it. Display-only `note` fields render as static prose. `page_break`/`hidden`/
 * `calculated` are structural/derived and are omitted from the encode surface entirely (they are never a
 * manually-entered answer).
 *
 * Repeat groups (Increment G2): a repeatable section is now emitted with its `min_instances`/`max_instances`
 * bounds; its member fields render exactly like any other (a scalar type is supported), and the page renders
 * an add/remove-instance loop over them (the pipeline persists the nested per-instance answer document, G1).
 */
final class EncodeFormPresenter
{
    /**
     * The scalar field types with a Phase-1 manual-encoding input. Any other answerable type is surfaced as
     * an explicit unsupported notice.
     *
     * @var list<FieldType>
     */
    private const SUPPORTED = [
        FieldType::ShortText, FieldType::LongText, FieldType::Email, FieldType::Phone, FieldType::Url,
        FieldType::Integer, FieldType::Decimal,
        FieldType::Date, FieldType::Time, FieldType::Datetime,
        FieldType::SingleSelect, FieldType::MultiSelect, FieldType::Dropdown, FieldType::YesNo,
        // Increment G4a: a single-choice rating scale + an N-level dependent select.
        FieldType::LikertScale, FieldType::CascadingSelect,
        // Increment G4b: the object-valued grids (matrix = per-cell single-select, likert_matrix = per-row scale).
        FieldType::Matrix, FieldType::LikertMatrix,
    ];

    /** Structural / server-derived types that never carry a manually-entered answer — omitted from the page. */
    private const OMITTED = [FieldType::PageBreak, FieldType::Hidden, FieldType::Calculated];

    public function __construct(private readonly SchemaValueFormatter $formatter) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Form $form, FormVersion $version): array
    {
        $sections = $version->sections()->orderBy('sequence')->get();
        $fields = $version->fields()->orderBy('sequence')->get()
            ->reject(fn (FormField $f): bool => in_array($f->field_type, self::OMITTED, true));

        /** @var Collection<string, Collection<int, FormField>> $bySection */
        $bySection = $fields->groupBy(fn (FormField $f): string => $f->form_section_id ?? '');

        $blocks = [];

        // Ungrouped (section-less) fields lead, matching how the builder renders top-level fields.
        $ungrouped = $bySection->get('');
        if ($ungrouped !== null && $ungrouped->isNotEmpty()) {
            $blocks[] = [
                'id' => null,
                'key' => null,
                'label' => null,
                'description' => null,
                'repeatable' => false,
                'min_instances' => null,
                'max_instances' => null,
                'fields' => $ungrouped->map(fn (FormField $f): array => $this->field($f))->values()->all(),
            ];
        }

        foreach ($sections as $section) {
            $sectionFields = $bySection->get($section->id);
            if ($sectionFields === null || $sectionFields->isEmpty()) {
                continue;
            }

            $blocks[] = [
                'id' => $section->id,
                // The stable section KEY — a repeatable section's nested instance answers are keyed on it (G2),
                // matching the StructuralAnswerNormalizer / schema_snapshot contract.
                'key' => $section->key,
                'label' => $section->label,
                'description' => $section->description,
                'repeatable' => $section->is_repeatable,
                'min_instances' => $section->is_repeatable ? $section->min_instances : null,
                'max_instances' => $section->is_repeatable ? $section->max_instances : null,
                'fields' => $sectionFields
                    ->map(fn (FormField $f): array => $this->field($f))
                    ->values()->all(),
            ];
        }

        return [
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
                'description' => $form->description,
            ],
            'version' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
            ],
            'blocks' => $blocks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function field(FormField $field): array
    {
        $type = $field->field_type;

        return [
            'key' => $field->key,
            'field_type' => $type->value,
            'label' => $field->label,
            'hint' => $field->hint,
            'placeholder' => $field->placeholder,
            'required' => $field->is_required === RequiredMode::Required,
            'options' => $this->options($field),
            // Cascading hierarchy (Increment G4a); null for every other type so the shared FieldInput ignores it.
            'cascade' => $this->cascade($field),
            // Composite grid config (Increment G4b: matrix / likert_matrix); null for every other type.
            'matrix' => $this->matrix($field),
            // `note` is display-only (handled by the page), never an input; a repeatable section's fields are
            // supported and render inside the add/remove-instance loop (Increment G2).
            'supported' => in_array($type, self::SUPPORTED, true),
        ];
    }

    /**
     * The cascading-select hierarchy (Increment G4a) — levels + parented options, normalised to the flat shape
     * the shared FieldInput cascading control consumes. Null for every non-cascading type. The encode channel is
     * single-locale, so labels are emitted as authored (no translation resolution — same as {@see options()}).
     *
     * @return array{levels: list<array{key: string, label: string}>, options: list<array{value: string, label: string, level: string, parent: string|null}>}|null
     */
    private function cascade(FormField $field): ?array
    {
        if ($field->field_type !== FieldType::CascadingSelect) {
            return null;
        }

        $levels = [];
        foreach ((array) data_get($field->config, 'levels', []) as $level) {
            if (! is_array($level) || ! isset($level['key'])) {
                continue;
            }
            $key = (string) $level['key'];
            $levels[] = ['key' => $key, 'label' => (string) ($level['label'] ?? $key)];
        }

        $options = [];
        foreach ((array) data_get($field->config, 'options', []) as $option) {
            if (! is_array($option) || ! isset($option['value'], $option['level'])) {
                continue;
            }
            $value = (string) $option['value'];
            $options[] = [
                'value' => $value,
                'label' => (string) ($option['label'] ?? $value),
                'level' => (string) $option['level'],
                'parent' => array_key_exists('parent', $option) && $option['parent'] !== null ? (string) $option['parent'] : null,
            ];
        }

        return ['levels' => $levels, 'options' => $options];
    }

    /**
     * The composite grid config (Increment G4b) — `rows`/`columns` for both grid types plus `cells` (the
     * shared per-cell choice pool) for `matrix`. Normalised to `{value,label}` pairs the shared FieldInput
     * grid controls consume; null for every non-grid type. Single-locale (labels as authored), like
     * {@see cascade()}/{@see options()}.
     *
     * @return array{rows: list<array{value: string, label: string}>, columns: list<array{value: string, label: string}>, cells: list<array{value: string, label: string}>}|null
     */
    private function matrix(FormField $field): ?array
    {
        $type = $field->field_type;
        if ($type !== FieldType::Matrix && $type !== FieldType::LikertMatrix) {
            return null;
        }

        return [
            'rows' => $this->optionPairs($field, 'rows'),
            'columns' => $this->optionPairs($field, 'columns'),
            'cells' => $type === FieldType::Matrix ? $this->optionPairs($field, 'cells') : [],
        ];
    }

    /**
     * A `config.<key>` option list ({value,label}[]) normalised to `{value,label}` pairs, skipping entries
     * with no value and defaulting a blank label to the value.
     *
     * @return list<array{value: string, label: string}>
     */
    private function optionPairs(FormField $field, string $configKey): array
    {
        $pairs = [];
        foreach ((array) data_get($field->config, $configKey, []) as $option) {
            if (! is_array($option) || ! isset($option['value']) || $option['value'] === '') {
                continue;
            }
            $value = (string) $option['value'];
            $pairs[] = ['value' => $value, 'label' => (string) ($option['label'] ?? $value)];
        }

        return $pairs;
    }

    /**
     * The author-defined option list for choice fields, normalised to `{value,label}` pairs (shared with the
     * F7 read side via {@see SchemaValueFormatter}). Empty for non-choice types.
     *
     * @return list<array{value: string, label: string}>
     */
    private function options(FormField $field): array
    {
        if (! $field->field_type->hasOptions()) {
            return [];
        }

        return $this->formatter->options($field->config ?? []);
    }
}
