<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldType;
use App\Enums\PrintAnswerArea;
use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
use App\Models\Form;
use App\Models\FormVersion;
use App\Services\Submissions\SchemaValueFormatter;
use App\Services\Submissions\SubmissionPdfPresenter;
use App\Support\Forms\LocaleVariant;
use App\Support\Forms\StepProjection;

/**
 * The render model for one PUBLISHED version's printable BLANK form (Increment I12) — a pure array,
 * no HTML, no Blade, no dompdf. {@see BlankFormPrintRenderer} turns it into a document; this class
 * decides what the paper asks. The geometry each area is printed at is
 * `docs/ocr-pipeline-design.md` §2.5.
 *
 * ── THE INPUT IS THE FROZEN SNAPSHOT, NEVER LIVE ROWS ───────────────────────────────────────────
 * Every value below comes from `form_versions.schema_snapshot`. {@see PublicFormPresenter} is the
 * precedent and the reason: the guest runtime already renders a BLANK form from exactly this column,
 * and this class is that runtime's paper twin.
 *
 * It also matters downstream. {@see CapabilityFlags}'s docblock argues the point in full — "one input
 * shape ⇒ one extraction ⇒ nothing to drift" — and it binds here twice over: the bytes this paper is
 * printed from are the same bytes `ocr_compatible` was computed from at publish (PublishService step
 * 8) and the same bytes H18's extraction stage will map a scanned region back against. Reading live
 * `form_fields` rows instead would open a third door onto a question two others already answer.
 *
 * ── ⚠️ THE SNAPSHOT IS ORDERED BY `key`, NOT BY `sequence` ──────────────────────────────────────
 * {@see SchemaSnapshotSerializer::snapshot()} sorts both lists with `->sortBy('key', SORT_STRING)`,
 * and it must: the checksum is a drift-detection contract that has to be stable across row-id churn
 * and DB return order, so a total order over a stable column is the only option.
 *
 * That order is ALPHABETICAL, and it is not the order anybody authored or answered the form in.
 * Rendering the lists as they arrive produces a form whose questions are shuffled — which looks
 * entirely plausible on any single form, and is the specific defect
 * `BlankFormPrintPresenterTest`'s ordering case exists to catch (its fixture's `key` order is the
 * exact reverse of its `sequence` order, so a presence-only assertion cannot pass by accident).
 *
 * Sections sort by `sequence`; fields sort by `section_sequence` then `sequence` within their
 * section, matching what `FormBuilderService` maintains and what
 * {@see StepProjection} consumes. Ungrouped fields LEAD, which is that class's
 * `LEAD_STEP_KEY` convention carried onto paper.
 *
 * ── RELEVANCE IS NOT APPLIED, AND THAT IS THE WHOLE POINT ───────────────────────────────────────
 * {@see SubmissionPdfPresenter} replays the relevance masks because it
 * documents what ONE respondent was shown. A blank form is the opposite artefact: it is printed
 * before anybody has answered anything, so no `relevant_expression` has an input to evaluate
 * against. EVERY field prints, and a conditional one is marked as conditional rather than hidden —
 * an enumerator holding paper has to be able to see the branch in order to follow it.
 */
final class BlankFormPrintPresenter
{
    /**
     * How many blank instances a repeatable section prints, when it declares no `min_instances`.
     *
     * One, not zero: a repeat group printing zero instances is a heading over nothing, and the
     * respondent has no "Add" button to press on paper.
     */
    private const DEFAULT_REPEAT_INSTANCES = 1;

    /**
     * The ceiling on printed repeat instances (§2.5). `max_instances` is frequently null or large —
     * an unbounded roster would print until the paper ran out, and the enumerator's real recourse is
     * a second copy of the sheet. Recorded as a documented cap rather than left to the author.
     */
    private const MAX_REPEAT_INSTANCES = 5;

    /** Comb cells per answer when nothing narrows it. Fits one line at the §2.5 cell pitch. */
    private const DEFAULT_COMB_CELLS = 24;

    /**
     * The hard ceiling on a comb run, and it is derived from the page rather than chosen.
     *
     * A4 is 210mm; `@page` takes 16mm off each side, leaving 178mm of content. §2.5's cell pitch is
     * 15.5pt (a 14pt cell plus 1.5pt of separation) which is about 5.47mm, so 30 cells occupy
     * ~164mm and 31 begin to crowd the margin. The cell is not shrunk to fit more: a comb narrower
     * than about 5mm stops being comfortable to hand-print in, which would cost exactly the ICR
     * accuracy the comb exists to buy.
     *
     * Without the clamp an authored `max_length` of 255 emits 255 boxes, and dompdf CLIPS an
     * over-wide table rather than wrapping it — so the row would silently lose its right-hand end in
     * the PDF while every model-level assertion stayed green.
     */
    private const MAX_COMB_CELLS = 30;

    public function __construct(private readonly SchemaValueFormatter $formatter) {}

    /**
     * The whole document model.
     *
     * The locale is the FORM's default, not a respondent's choice — nobody has chosen anything yet.
     * A "print this form in Tagalog" affordance is a real gap for multi-locale forms and is recorded
     * as such in `docs/ocr-pipeline-design.md` §2.5 rather than guessed at here.
     *
     * @return array<string, mixed>
     */
    public function present(Form $form, FormVersion $version): array
    {
        $locale = $form->default_locale;
        $snapshot = $version->schema_snapshot;

        $fields = $this->orderedFields($this->listAt($snapshot, 'fields'));
        $sections = $this->orderedSections($this->listAt($snapshot, 'sections'));

        /** @var array<string, list<array<string, mixed>>> $bySection */
        $bySection = [];
        foreach ($fields as $field) {
            $key = is_string($field['section_key'] ?? null) ? $field['section_key'] : '';
            $bySection[$key][] = $field;
        }

        return [
            'form_title' => $form->title,
            'form_description' => $form->description,
            'locale' => $locale,
            'version_number' => $version->version_number,
            'published_at' => $version->published_at?->toFormattedDateString(),
            // The first 8 chars of the version checksum, printed as a text stamp so a scanned sheet
            // can be tied back to the exact schema it was printed from. It is TEXT and not a barcode
            // because `ext-gd` is absent from the app container and from every CI job, so any raster
            // would render on a developer's machine and throw in the pipeline (the H23a4 finding).
            'schema_stamp' => $version->checksum === null ? null : substr($version->checksum, 0, 8),
            // Printed in the footer so whoever runs off a stack of these knows up front whether the
            // scans can be auto-extracted, rather than discovering it after collection. Re-derived
            // from this version's own bytes, never read off `forms.capability_flags` — that column
            // describes the CURRENTLY published version and is stale for a superseded one.
            'ocr_compatible' => CapabilityFlags::isOcrCompatible($version),
            'blocks' => $this->blocks($sections, $bySection, $locale),
        ];
    }

    /**
     * Every printed block, ungrouped fields leading.
     *
     * A section with no printable field is dropped entirely, heading included — the same rule
     * `StepProjection`'s predicate 2 applies on screen, for the same reason: a heading over an empty
     * panel tells the person holding the paper that they missed something.
     *
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, list<array<string, mixed>>>  $bySection
     * @return list<array<string, mixed>>
     */
    private function blocks(array $sections, array $bySection, ?string $locale): array
    {
        $blocks = [];

        $lead = $this->fieldRows($bySection[''] ?? [], $locale);
        if ($lead !== []) {
            $blocks[] = ['label' => null, 'description' => null, 'instance' => null, 'fields' => $lead];
        }

        foreach ($sections as $section) {
            $key = is_string($section['key'] ?? null) ? $section['key'] : '';
            $rows = $this->fieldRows($bySection[$key] ?? [], $locale);

            if ($rows === []) {
                continue;
            }

            $label = LocaleVariant::resolve($section['label_translations'] ?? null, $this->stringOrNull($section['label'] ?? null), $locale, $key);
            $description = LocaleVariant::resolve($section['description_translations'] ?? null, $this->stringOrNull($section['description'] ?? null), $locale);

            $repeatable = $this->isRepeatable($section);
            $instances = $this->repeatInstances($section);

            // A non-repeatable section is one block with no instance number. A repeatable one prints
            // its blocks numbered exactly as `RepeatGroup.vue` numbers them on screen, so a keyer
            // transcribing the paper is looking at the same ordinals the UI will show them — and it
            // is numbered even at ONE instance, because "Household member 1" on the paper is what
            // tells the enumerator a second one is possible on another sheet.
            for ($i = 1; $i <= $instances; $i++) {
                $blocks[] = [
                    'label' => $label,
                    'description' => $description === '' ? null : $description,
                    'instance' => $repeatable ? $i : null,
                    'fields' => $rows,
                ];
            }
        }

        return $blocks;
    }

    /**
     * One block's printable rows.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function fieldRows(array $fields, ?string $locale): array
    {
        $rows = [];

        foreach ($fields as $field) {
            $type = FieldType::tryFrom((string) ($field['field_type'] ?? ''));

            // A field type this catalog does not know is a snapshot written by a newer schema than
            // the reading code. It is SKIPPED rather than thrown on, matching `CapabilityFlags`'s
            // `tryFrom` reasoning: a version merely from the future should not 500 a print request.
            if ($type === null) {
                continue;
            }

            $area = PrintAnswerArea::for($type);
            if (! $area->isPrinted()) {
                continue;
            }

            $key = (string) ($field['key'] ?? '');
            /** @var array<string, mixed> $config */
            $config = is_array($field['config'] ?? null) ? $field['config'] : [];

            $rows[] = [
                'key' => $key,
                'area' => $area->value,
                'label' => LocaleVariant::resolve($field['label_translations'] ?? null, $this->stringOrNull($field['label'] ?? null), $locale, $key),
                'hint' => $this->blankToNull(LocaleVariant::resolve($field['hint_translations'] ?? null, $this->stringOrNull($field['hint'] ?? null), $locale)),
                'required' => ($field['is_required'] ?? null) === RequiredMode::Required->value,
                // Printed as a marker rather than resolved: a conditional field's expression has no
                // answers to evaluate against on a blank sheet, and the enumerator needs to see that
                // the branch exists in order to follow it.
                'conditional' => ($field['is_required'] ?? null) === RequiredMode::Conditional->value
                    || $this->stringOrNull($field['relevant_expression'] ?? null) !== null,
                'comb' => $area === PrintAnswerArea::Comb ? $this->combGroups($type, $field) : null,
                'options' => $area === PrintAnswerArea::Choices ? $this->optionList($config, 'options', $locale) : [],
                'grid' => $area === PrintAnswerArea::Grid ? [
                    'rows' => $this->optionList($config, 'rows', $locale),
                    'columns' => $this->optionList($config, 'columns', $locale),
                ] : null,
            ];
        }

        return $rows;
    }

    /**
     * The comb layout for one field: a list of `{cells, caption}` groups printed side by side.
     *
     * ⚠️ THE DATE AND TIME TYPES COMB INTO FIXED, CAPTIONED GROUPS, AND THAT IS THE ENTIRE REASON A
     * HANDWRITTEN DATE IS MACHINE-READABLE. `[DD] [MM] [YYYY]` under printed captions removes the
     * ambiguity a free run of eight boxes leaves behind — 03/04 is the 3rd of April or the 4th of
     * March depending on who filled it in, and no recognizer can resolve that from the ink. The
     * captions are ASCII on purpose (see the renderer's WinAnsi note).
     *
     * Everything else takes one run, narrowed by an authored `max_length` where there is one.
     *
     * @param  array<string, mixed>  $field
     * @return list<array{cells: int, caption: ?string}>
     */
    private function combGroups(FieldType $type, array $field): array
    {
        return match ($type) {
            FieldType::Date => [
                ['cells' => 2, 'caption' => 'DD'],
                ['cells' => 2, 'caption' => 'MM'],
                ['cells' => 4, 'caption' => 'YYYY'],
            ],
            FieldType::Time => [
                ['cells' => 2, 'caption' => 'HH'],
                ['cells' => 2, 'caption' => 'MM'],
            ],
            FieldType::Datetime => [
                ['cells' => 2, 'caption' => 'DD'],
                ['cells' => 2, 'caption' => 'MM'],
                ['cells' => 4, 'caption' => 'YYYY'],
                ['cells' => 2, 'caption' => 'HH'],
                ['cells' => 2, 'caption' => 'MM'],
            ],
            FieldType::Duration => [
                ['cells' => 3, 'caption' => 'HRS'],
                ['cells' => 2, 'caption' => 'MIN'],
            ],
            FieldType::Integer, FieldType::Decimal => [
                ['cells' => $this->combCells($field, 10), 'caption' => null],
            ],
            default => [
                ['cells' => $this->combCells($field, self::DEFAULT_COMB_CELLS), 'caption' => null],
            ],
        };
    }

    /**
     * How many cells one free comb run gets: the field's authored `max_length` where it has one,
     * else `$default`, and never more than {@see self::MAX_COMB_CELLS}.
     *
     * The clamp is not decoration. dompdf CLIPS an over-wide table rather than wrapping it, so an
     * authored `max_length` of 255 would silently truncate the printed row at the page edge — a
     * defect that shows up only in the rendered PDF, never in a test that reads the model.
     *
     * @param  array<string, mixed>  $field
     */
    private function combCells(array $field, int $default): int
    {
        $cells = $default;

        foreach ($this->listAt($field, 'validations') as $rule) {
            if (($rule['rule_type'] ?? null) !== ValidationRuleType::MaxLength->value) {
                continue;
            }

            $value = $rule['rule_value'] ?? null;
            if (is_numeric($value) && (int) $value >= 1) {
                $cells = (int) $value;
                break;
            }
        }

        return max(1, min($cells, self::MAX_COMB_CELLS));
    }

    /**
     * A `{value,label}` option list off one config key, locale-resolved.
     *
     * Routed through {@see SchemaValueFormatter::options()} rather than re-walked here: that method
     * already normalises the shape, already applies BOTH of H6b's locale fallbacks (the variant
     * falls back when missing, non-string or blank; the base label falls back to the value on null
     * only), and already fails soft on a malformed list. It reads `config.options` by name, so the
     * grid's `rows`/`columns` are handed to it under that key — the shapes are identical, which
     * `StructuralValidationGate::assertOptionListResolves()` enforces at publish for all three.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{value: string, label: string}>
     */
    private function optionList(array $config, string $key, ?string $locale): array
    {
        return $this->formatter->options(['options' => $config[$key] ?? []], $locale);
    }

    /**
     * How many blank copies of a repeatable section to print.
     *
     * @param  array<string, mixed>  $section
     */
    private function repeatInstances(array $section): int
    {
        if (! $this->isRepeatable($section)) {
            return 1;
        }

        $min = $section['min_instances'] ?? null;
        $instances = is_numeric($min) && (int) $min >= 1 ? (int) $min : self::DEFAULT_REPEAT_INSTANCES;

        return min($instances, self::MAX_REPEAT_INSTANCES);
    }

    /**
     * Fail-SAFE in the printing direction, and deliberately the opposite of
     * {@see CapabilityFlags::anyRepeatableSection()}'s `?? true`.
     *
     * There, an ambiguous section must count as repeatable so an unrecognised shape cannot buy OCR
     * eligibility. Here, treating an ambiguous section as repeatable would print extra numbered
     * copies of it — so the safe reading is the other one. Same ambiguity, opposite cost.
     *
     * @param  array<string, mixed>  $section
     */
    private function isRepeatable(array $section): bool
    {
        return ($section['is_repeatable'] ?? false) === true;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function orderedSections(array $sections): array
    {
        usort($sections, fn (array $a, array $b): int => $this->intAt($a, 'sequence') <=> $this->intAt($b, 'sequence')
            ?: strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? '')));

        return $sections;
    }

    /**
     * Fields in authored order: `section_sequence` (position within the section) then `sequence`
     * (position within the version), with `key` as a total-order tiebreak so the output is
     * deterministic even for a hand-built snapshot whose sequences collide.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function orderedFields(array $fields): array
    {
        usort($fields, fn (array $a, array $b): int => $this->intAt($a, 'section_sequence') <=> $this->intAt($b, 'section_sequence')
            ?: $this->intAt($a, 'sequence') <=> $this->intAt($b, 'sequence')
            ?: strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? '')));

        return $fields;
    }

    /**
     * A missing or non-numeric sequence sorts LAST rather than first: an unsequenced field is one
     * the authoring path never positioned, and appending it is less wrong than opening the form with
     * it.
     *
     * @param  array<string, mixed>  $entry
     */
    private function intAt(array $entry, string $key): int
    {
        $value = $entry[$key] ?? null;

        return is_numeric($value) ? (int) $value : PHP_INT_MAX;
    }

    /**
     * The named list of arrays inside a snapshot (or a field's `validations`), or `[]`.
     *
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     */
    private function listAt(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $entries = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function blankToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
