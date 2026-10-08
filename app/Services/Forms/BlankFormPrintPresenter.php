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
use LogicException;

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

    /**
     * The revision of the printed LAYOUT, printed beside the version stamp and read back by the matcher
     * (`M143`, `D96`). The checksum stamp identifies the schema; this identifies the template it was typeset
     * with, so a sheet printed before a layout change cannot be read against the layout after it. Bump it
     * whenever the geometry the reader depends on changes — where an answer sits relative to its label.
     * Layout 3 (`M144`, `D98`): a phone and a number in one open box, the long-text box sized from its
     * `max_length`. Layout 4 (`M148`): the gap between a comb's groups carries its separator (`/`, `:`) and
     * no border, where layouts 2 and 3 drew it as one more box to write in.
     */
    public const int LAYOUT = 4;

    /**
     * The hard ceiling on a comb run, and it is derived from the page rather than chosen.
     *
     * A4 is 210mm; `@page` takes 16mm off each side, leaving 178mm = 504.6pt of content. Layout 2's cell
     * is 18pt square on a 20pt pitch (`border-spacing: 2pt`), and a group gap is a 10pt cell, so a run
     * of N cells in G+1 groups is `20N + 12G + 2` points wide — `border-spacing` lands at both table
     * edges as well as between cells. With three gaps (a datetime, a four-level cascade) that allows
     * 23 cells (500pt); 24 clips. The cell is not shrunk to fit more: 18pt is about 6.3mm, the square,
     * pen-sized box the ICR guidance asks for, and a comb narrower than about 5mm stops being
     * comfortable to hand-print in, which costs exactly the accuracy the comb exists to buy.
     *
     * Since layout 3 only the captioned combs remain (a date, a time, a duration, a cascade's levels),
     * and this budget is what a cascading select divides between its levels. dompdf CLIPS an over-wide
     * table rather than wrapping it, so a run past this width would silently lose its right-hand end
     * in the PDF while every model-level assertion stayed green.
     */
    private const MAX_COMB_CELLS = 23;

    /**
     * How many hand-printed block capitals fit on one line of an open box (layout 3, `M144`,
     * `R-d696ba9e`). The content width is 178mm (see {@see self::MAX_COMB_CELLS}); a capital written
     * freely with a pen, with its spacing, runs about 4mm — denser than a 6.3mm comb cell, far sparser
     * than type. The figure sizes the long-text box from its `max_length`; the bake-off's samples are
     * where it gets measured.
     */
    private const CAPITALS_PER_LINE = 45;

    /**
     * The long-text box in LINES — the template owns the points (20pt a line, so three lines is the 60pt
     * box layout 2 printed). Three at least, because a `long_text` with no `max_length` is the common
     * case and a paragraph needs room; ten at most, so one question cannot swallow a page — `.q` is
     * `page-break-inside: avoid`, and a box taller than the page could never be placed. The banner tells
     * the respondent what to do when a box runs out: continue on another sheet, with the question number.
     */
    private const RULED_LINES_MIN = 3;

    private const RULED_LINES_MAX = 10;

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
            // Whether the FORM accepts scans today (`forms.allow_ocr_single`), which with `ocr_compatible`
            // decides what the footer promises (`M143`, `R-6bbf9d73`). A form setting, not a version fact:
            // the same paper reads differently once scanning is switched on, and the footer says so.
            'accepts_scans' => $form->allow_ocr_single === true,
            'layout' => self::LAYOUT,
            'blocks' => $this->numbered($this->blocks($sections, $bySection, $locale)),
        ];
    }

    /**
     * Every printed block, ungrouped fields leading.
     *
     * A section with no printable field is dropped entirely, heading included — the same rule
     * `StepProjection`'s predicate 2 applies on screen, for the same reason: a heading over an empty
     * panel tells the person holding the paper that they missed something.
     *
     * Increment M124 (`R-8c517fb6`) — the same rule for a PAGE BREAK, which prints but is not a question.
     * A section's leading breaks print BEFORE its heading, in a block of their own (per instance for a
     * repeatable section, so "each member starts a new page" survives), so a heading is never stranded at
     * the foot of a page above its own questions — and a section holding nothing but breaks prints no
     * heading at all. {@see tidyPageBreaks()} then keeps only the breaks that separate printed questions.
     *
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, list<array<string, mixed>>>  $bySection
     * @return list<array<string, mixed>>
     */
    private function blocks(array $sections, array $bySection, ?string $locale): array
    {
        $blocks = [];
        $claimed = [''];

        $lead = $this->fieldRows($bySection[''] ?? [], $locale);
        if ($lead !== []) {
            $blocks[] = ['label' => null, 'description' => null, 'instance' => null, 'conditional' => false, 'fields' => $lead];
        }

        foreach ($sections as $section) {
            $key = is_string($section['key'] ?? null) ? $section['key'] : '';
            $claimed[] = $key;
            $rows = $this->fieldRows($bySection[$key] ?? [], $locale);

            if ($rows === []) {
                continue;
            }

            $label = LocaleVariant::resolve($section['label_translations'] ?? null, $this->stringOrNull($section['label'] ?? null), $locale, $key);
            $description = LocaleVariant::resolve($section['description_translations'] ?? null, $this->stringOrNull($section['description'] ?? null), $locale);

            $repeatable = $this->isRepeatable($section);
            $instances = $this->repeatInstances($section);
            [$leadingBreaks, $rows] = $this->splitLeadingBreaks($rows);

            // A non-repeatable section is one block with no instance number. A repeatable one prints
            // its blocks numbered exactly as `RepeatGroup.vue` numbers them on screen, so a keyer
            // transcribing the paper is looking at the same ordinals the UI will show them — and it
            // is numbered even at ONE instance, because "Household member 1" on the paper is what
            // tells the enumerator a second one is possible on another sheet.
            for ($i = 1; $i <= $instances; $i++) {
                if ($leadingBreaks !== []) {
                    $blocks[] = ['label' => null, 'description' => null, 'instance' => null, 'conditional' => false, 'fields' => $leadingBreaks];
                }

                if ($rows === []) {
                    continue;
                }

                $blocks[] = [
                    'label' => $label,
                    'description' => $description === '' ? null : $description,
                    'instance' => $repeatable ? $i : null,
                    // ⚠️ A CONDITIONAL SECTION HAS TO SAY SO ON ITS HEADING, and the per-field flag
                    // cannot cover it: a `relevant_expression` on the SECTION lives on the section
                    // row, and its member fields carry nothing. Without this, a whole block that may
                    // not apply to this respondent printed with no marker anywhere — the enumerator
                    // would have no way to know the branch existed, which is the same failure the
                    // per-field marker exists to prevent, one level up.
                    'conditional' => $this->stringOrNull($section['relevant_expression'] ?? null) !== null,
                    'fields' => $rows,
                ];
            }
        }

        // ⚠️ NOTHING MAY VANISH FROM THE PAPER. A field whose `section_key` matches no section in
        // the snapshot would otherwise be grouped into a bucket no loop above ever reads, and would
        // simply not be printed — a QUESTION SILENTLY MISSING FROM THE INSTRUMENT, which is the
        // worst failure this increment has and the one nobody would notice.
        //
        // The serializer cannot currently produce it (a field's `section_key` is looked up from its
        // own version's sections), so this is unreachable through the publish path today. It is here
        // because the cost of being wrong is a lost question and the cost of the guard is a loop:
        // the orphans print at the end, under no heading, rather than not at all.
        foreach ($bySection as $key => $orphans) {
            if (in_array($key, $claimed, true)) {
                continue;
            }

            $rows = $this->fieldRows($orphans, $locale);
            if ($rows !== []) {
                $blocks[] = ['label' => null, 'description' => null, 'instance' => null, 'conditional' => false, 'fields' => $rows];
            }
        }

        return $this->tidyPageBreaks($blocks);
    }

    /**
     * Increment M124 — a block's leading page breaks, split from the rows after them.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function splitLeadingBreaks(array $rows): array
    {
        $count = 0;
        foreach ($rows as $row) {
            if (($row['area'] ?? null) !== PrintAnswerArea::PageBreak->value) {
                break;
            }
            $count++;
        }

        return [array_slice($rows, 0, $count), array_slice($rows, $count)];
    }

    /**
     * Increment M124 — a page break prints only where it SEPARATES printed questions.
     *
     * One before the first question would open the sheet on a blank page, one after the last would close it
     * on one, and two with nothing between them print a blank page between their neighbours. So, walking
     * the whole document in order, a break survives only when a question has printed before it and another
     * prints after it before the next surviving break — the last of a run wins, the rest collapse into it —
     * and a block left with nothing to print is dropped. Heading blocks always keep a question, so only the
     * heading-less blocks of hoisted breaks can empty.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function tidyPageBreaks(array $blocks): array
    {
        $isBreak = static fn (mixed $row): bool => is_array($row) && ($row['area'] ?? null) === PrintAnswerArea::PageBreak->value;

        /** @var array<string, true> $kept "block:row" for every surviving break */
        $kept = [];
        $questionPrinted = false;
        $pending = null;
        foreach ($blocks as $b => $block) {
            /** @var list<array<string, mixed>> $fields */
            $fields = $block['fields'];
            foreach ($fields as $r => $row) {
                if ($isBreak($row)) {
                    $pending = $questionPrinted ? "{$b}:{$r}" : null;

                    continue;
                }

                if ($pending !== null) {
                    $kept[$pending] = true;
                    $pending = null;
                }
                $questionPrinted = true;
            }
        }

        $tidy = [];
        foreach ($blocks as $b => $block) {
            /** @var list<array<string, mixed>> $fields */
            $fields = $block['fields'];
            $rows = [];
            foreach ($fields as $r => $row) {
                if (! $isBreak($row) || isset($kept["{$b}:{$r}"])) {
                    $rows[] = $row;
                }
            }

            if ($rows !== []) {
                $block['fields'] = $rows;
                $tidy[] = $block;
            }
        }

        return $tidy;
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
                // Layout 3 (`R-d696ba9e`): the long-text box's height in lines, from the authored
                // `max_length`. Null for every other area — an open `line` box is one line whatever
                // its `max_length` says, because a short text, a phone or a number is a one-line answer
                // and a 255 cap on one is a sanity limit, not a length promise.
                'lines' => $area === PrintAnswerArea::Ruled ? $this->ruledLines($field) : null,
                'options' => $area === PrintAnswerArea::Choices ? $this->choiceOptions($type, $config, $locale) : [],
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
     * Since layout 3 (`D98`) ONLY captioned combs exist: a free run of digits — a number, a phone — is an
     * open box, because the reader parses a digit string from free text as well as from boxes and the
     * comb bought only the segmentation the recognizer does itself. {@see PrintAnswerArea::for()} is the
     * single place that decides which types reach this method, so the default arm is unreachable and
     * says so rather than printing an empty comb.
     *
     * `separator` is what the gap BEFORE a group prints (layout 4, `M148`): `/` inside a date, `:` inside a
     * time and a duration, and null for a first group, for the plain gap between a datetime's date and its
     * time, and for every cascade level, whose written words can hold a real hyphen or slash. The user's
     * comment on layout 3 found the gap printed as a tenth box nobody knew what to write in; a printed mark
     * with no border says what the gap is, and the reader drops it again (`OcrAnswerReader`).
     *
     * @param  array<string, mixed>  $field
     * @return list<array{cells: int, caption: ?string, separator: ?string}>
     */
    private function combGroups(FieldType $type, array $field): array
    {
        return match ($type) {
            FieldType::Date => [
                ['cells' => 2, 'caption' => 'DD', 'separator' => null],
                ['cells' => 2, 'caption' => 'MM', 'separator' => '/'],
                ['cells' => 4, 'caption' => 'YYYY', 'separator' => '/'],
            ],
            FieldType::Time => [
                ['cells' => 2, 'caption' => 'HH', 'separator' => null],
                ['cells' => 2, 'caption' => 'MM', 'separator' => ':'],
            ],
            FieldType::Datetime => [
                ['cells' => 2, 'caption' => 'DD', 'separator' => null],
                ['cells' => 2, 'caption' => 'MM', 'separator' => '/'],
                ['cells' => 4, 'caption' => 'YYYY', 'separator' => '/'],
                ['cells' => 2, 'caption' => 'HH', 'separator' => null],
                ['cells' => 2, 'caption' => 'MM', 'separator' => ':'],
            ],
            FieldType::Duration => [
                ['cells' => 3, 'caption' => 'HRS', 'separator' => null],
                ['cells' => 2, 'caption' => 'MIN', 'separator' => ':'],
            ],
            FieldType::CascadingSelect => $this->cascadingGroups($field),
            default => throw new LogicException(sprintf(
                'PrintAnswerArea::for() sends only the captioned types to a comb; %s reached combGroups()',
                $type->value,
            )),
        };
    }

    /**
     * One captioned comb run per declared level of a `cascading_select`.
     *
     * The screen control narrows each level by the one above it; paper can narrow nothing, so the
     * answer is WRITTEN per level rather than picked from the flat multi-level option pool (see
     * {@see PrintAnswerArea}'s note on why printing that pool would be wrong, not merely long).
     * A captioned run per level is what a paper address block has always looked like.
     *
     * Captions come from the level KEY rather than any authored label, for two reasons: the key is
     * the identifier H18 maps back to, and keys are constrained to a short ASCII-ish token where a
     * label is free text that could be neither (§2.5.5's WinAnsi constraint, and a 6.5pt caption
     * has about ten characters of room over its group).
     *
     * The per-level width divides the page budget rather than being chosen, so a four-level
     * hierarchy still fits one line. The floor of 4 keeps a deep hierarchy legible instead of
     * silently dropping its deepest levels — a form with enough levels to overflow at 4 cells each
     * has never existed in this product, and §2.5.8 records the bound rather than hiding it.
     *
     * @param  array<string, mixed>  $field
     * @return list<array{cells: int, caption: ?string, separator: null}>
     */
    private function cascadingGroups(array $field): array
    {
        /** @var array<string, mixed> $config */
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];
        $levels = [];

        foreach ($this->rawList($config, 'levels') as $level) {
            $key = is_array($level) ? ($level['key'] ?? null) : $level;
            if (is_string($key) && $key !== '') {
                $levels[] = $key;
            }
        }

        // A cascading select with no declared levels cannot publish (StructuralValidationGate
        // refuses it), so this is the hand-built-snapshot path: one plain run at the page's full
        // width, never zero groups, because zero groups renders a labelled question with nowhere to
        // answer it.
        if ($levels === []) {
            return [['cells' => self::MAX_COMB_CELLS, 'caption' => null, 'separator' => null]];
        }

        $cells = max(4, intdiv(self::MAX_COMB_CELLS, count($levels)));

        return array_map(
            // mb_* rather than the byte functions: `substr` can cut a UTF-8 sequence in half, and
            // Blade's `e()` is `htmlspecialchars(..., 'UTF-8')` with no ENT_SUBSTITUTE, which
            // returns the EMPTY STRING on invalid input — so a truncated multibyte key would not
            // error, it would silently print no caption at all.
            static fn (string $key): array => [
                'cells' => $cells,
                'caption' => mb_strtoupper(mb_substr($key, 0, 10)),
                'separator' => null,
            ],
            $levels,
        );
    }

    /**
     * Layout 3 (`R-d696ba9e`) — how many lines the long-text box gets: the authored `max_length` divided
     * into lines of {@see self::CAPITALS_PER_LINE}, never fewer than {@see self::RULED_LINES_MIN} nor more
     * than {@see self::RULED_LINES_MAX}; the minimum when nothing is authored. The template turns lines
     * into points, and the OCR test fixture mirrors the same count, so the reader's region grows with it.
     *
     * @param  array<string, mixed>  $field
     */
    private function ruledLines(array $field): int
    {
        $max = $this->authoredMaxLength($field);
        if ($max === null) {
            return self::RULED_LINES_MIN;
        }

        return max(self::RULED_LINES_MIN, min((int) ceil($max / self::CAPITALS_PER_LINE), self::RULED_LINES_MAX));
    }

    /**
     * The field's first authored `max_length` rule of one or more, or null. Rules travel in the snapshot as
     * `{rule_type, rule_value}` with the value a string, the serializer's shape.
     *
     * @param  array<string, mixed>  $field
     */
    private function authoredMaxLength(array $field): ?int
    {
        foreach ($this->listAt($field, 'validations') as $rule) {
            if (($rule['rule_type'] ?? null) !== ValidationRuleType::MaxLength->value) {
                continue;
            }

            $value = $rule['rule_value'] ?? null;
            if (is_numeric($value) && (int) $value >= 1) {
                return (int) $value;
            }
        }

        return null;
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
     * The named list inside a config, entries UNFILTERED — `config.levels` legitimately holds either
     * maps or bare strings, which {@see self::listAt()} would silently drop.
     *
     * @param  array<string, mixed>  $source
     * @return list<mixed>
     */
    private function rawList(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return is_array($value) && array_is_list($value) ? $value : [];
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

    /**
     * The tick boxes one `choices` field prints (Increment M128).
     *
     * ⚠️ A YES/NO QUESTION HAS NO STORED OPTIONS, AND IT USED TO PRINT AS A WRITE-IN BOX. `yes_no` is not a
     * `hasOptions()` type — its two answers are fixed and never stored (`ValueShape::Boolean`) — so
     * `optionList()` read an empty `config.options`, and the template's empty branch drew the ruled
     * "write it in" box meant for a malformed choice list. `PrintAnswerArea` has always classed `yes_no` as
     * `choices`, which is what `docs/ocr-pipeline-design.md` §2.5.2 says paper should show. So the two
     * answers are supplied here, in the same `{value, label}` shape as any option list. The values are the
     * literals `Coercion::yesNoAnswer()` reads as true and false, so an OCR mark on "Yes" lands on the same
     * canonical boolean a screen answer does. The labels are the ones `SchemaValueFormatter` prints for a
     * stored yes/no answer in the submission PDF, so the blank form and the filled one agree.
     *
     * This method is appended rather than placed beside `optionList()`, deliberately: this file is cited by
     * line from the backlog, and an insertion above those lines would move them.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{value: string, label: string}>
     */
    private function choiceOptions(FieldType $type, array $config, ?string $locale): array
    {
        if ($type === FieldType::YesNo) {
            return [
                ['value' => 'yes', 'label' => 'Yes'],
                ['value' => 'no', 'label' => 'No'],
            ];
        }

        return $this->optionList($config, 'options', $locale);
    }

    /**
     * Layout 2 (`M143`, `D96`) — every question carries its printed number, 1-based across the whole sheet.
     *
     * The number is a separate key, never folded into `label`: the label is what the matcher anchors a
     * scanned question on and what the bake-off's answer template names its columns by, and both compare
     * it with the authored text. Prose, page breaks and omitted rows are not questions
     * ({@see PrintAnswerArea::isQuestion()}), so they take no number and leave no gap. A repeatable section's
     * instances number on through, so "7." on the paper means one thing however many copies print.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function numbered(array $blocks): array
    {
        $number = 0;

        foreach ($blocks as $b => $block) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $block['fields'];
            foreach ($rows as $r => $row) {
                $area = PrintAnswerArea::tryFrom((string) ($row['area'] ?? ''));
                $blocks[$b]['fields'][$r]['number'] = $area !== null && $area->isQuestion() ? ++$number : null;
            }
        }

        return $blocks;
    }
}
