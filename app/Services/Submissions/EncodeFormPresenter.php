<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\FieldType;
use App\Enums\PrefillSource;
use App\Enums\RequiredMode;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Services\Forms\StepGraphInspector;
use App\Services\Templates\TemplateRenderer;
use App\Services\Templates\TemplateSources;
use App\Services\Validation\SemanticValidator;
use App\Support\Forms\FormScheduleView;
use App\Support\Forms\StepDescriptor;
use App\Support\Forms\StepProjection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read model for the manual-encoding page (Increment F4b) — turns a form's published version into the flat,
 * render-ready schema the Encode.vue page walks. Fields are grouped into their sections (ungrouped fields
 * lead as a section-less block), in document order.
 *
 * "Render-supported, mark-the-rest" (confirmed scope decision): the ~14 Phase-1 scalar field types render a
 * real input; everything else (advanced geo/media/matrix/likert/cascading, duration, signature) is emitted
 * with `supported = false` so the page shows a read-only "not available for manual entry (Phase 2)" notice
 * rather than silently dropping it. Display-only `note` fields render as static prose. `page_break` and
 * `calculated` are structural/derived and are omitted from the encode surface entirely (they are never a
 * manually-entered answer).
 *
 * Hidden fields (Increment H7) are NOT omitted, and this channel is deliberately asymmetric with the guest
 * SPA on that point. A respondent must never see a hidden field — that is what the type means. A keyer is
 * the opposite case: they are staff transcribing a paper form, so a `url`-sourced hidden field has no URL to
 * come from and they are the only possible source (stamping a batch id on a linelist sheet). Each row
 * therefore carries `prefill`: `'url'` rows are `supported` and render a real text input, `'fixed'` rows are
 * server-set and render read-only.
 *
 * Repeat groups (Increment G2): a repeatable section is now emitted with its `min_instances`/`max_instances`
 * bounds; its member fields render exactly like any other (a scalar type is supported), and the page renders
 * an add/remove-instance loop over them (the pipeline persists the nested per-instance answer document, G1).
 *
 * THE STEP MODEL (Increment H21c, Doc #27 §7). Until this increment the payload had "no step key, no
 * `single_page_mode` read, and zero occurrences of `relevant_expression`" — the encode channel applied
 * relevance server-side at submit and nowhere else, so a keyer saw every branch and was told nothing when
 * half of what they typed was pruned. Three things are ADDED here, and `blocks` is deliberately unchanged:
 *
 *  - `version.schema` (the frozen `schema_snapshot`) + `version.checksum` + the `form.*` runtime flags, which
 *    together are exactly the {@see PublicFormPresenter} envelope the guest SPA's store consumes. `Encode.vue`
 *    mounts the SAME `createFormRuntime()` on it, so the two channels' step lists are equal BY CONSTRUCTION
 *    rather than by a hand-copied predicate list.
 *  - `version.now`, the session clock, stamped ONCE by the server (see {@see clock()}).
 *  - `steps`, this version's projection under an EMPTY answer document, via {@see StepProjection}. Doc #27 §9
 *    and the `StepProjection` docblock both name `EncodeFormPresenter` as the class that must consume it.
 *
 * `blocks` STAYS, and the duplication it carries is recorded rather than quietly tolerated: `geo()`,
 * `media()`, `cascade()`, `matrix()` and `options()` below are hand-mirrors of `schema-mapping.ts`'s
 * `buildGeo`/`buildMedia`/etc., and collapsing them onto the client's `buildRenderModel(snapshot)` is a real
 * simplification this increment deliberately declines (Doc #27 §10). §9's parity obligation is about the STEP
 * LIST, not the field render model, and the collapse would put every G4a/G4b/G5b2/G6/H7 encode test into the
 * blast radius of an increment that is already an L.
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
        // Increment G5b2: geospatial capture (coordinate/vertex control + progressive-enhancement Leaflet map).
        FieldType::Geopoint, FieldType::Geotrace, FieldType::Geoshape,
        // Increment G6: media capture (upload-on-selection + progressive-enhancement getUserMedia). `signature`
        // (a canvas-draw control) is deferred, so it stays unsupported for now.
        FieldType::FileUpload, FieldType::ImageCapture, FieldType::AudioCapture, FieldType::VideoCapture,
    ];

    /**
     * Structural / server-derived types that never carry a manually-entered answer — omitted from the page.
     * `hidden` LEFT this list in Increment H7 (see the class docblock); it is now presented, with its
     * keyability decided per-field by {@see PrefillSource}.
     */
    private const OMITTED = [FieldType::PageBreak, FieldType::Calculated];

    public function __construct(
        private readonly SchemaValueFormatter $formatter,
        private readonly TemplateRenderer $templates,
        // Increment H21c — the relevance masks the step projection consumes. The projection itself evaluates
        // nothing (it is pure and static); this is the only thing on this page that runs the engine.
        private readonly SemanticValidator $semantic,
        private readonly StructuralAnswerNormalizer $normalizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Form $form, FormVersion $version): array
    {
        $sections = $version->sections()->orderBy('sequence')->get();
        $allFields = $version->fields()->orderBy('sequence')->get();
        $fields = $allFields
            ->reject(fn (FormField $f): bool => in_array($f->field_type, self::OMITTED, true));

        // Increment H6a — piping (Doc #26 §3.4). This page serves a BLANK keying form: there is no
        // submission and therefore no answer document, so every hole renders as the empty string. That is
        // the contract, not a shortfall — a hole is never emitted as the raw `${key}` token, and §8 records
        // the same narrowing for a printed OCR form ("prose with a gap", not a substitution).
        //
        // Note that `OMITTED` still drops `calculated`, which IS a pipeable SOURCE per §3.1. That is fine:
        // a source need not have a row on this page for its consumer's label to be correct, and the source
        // map is built from EVERY field for exactly that reason — including the ones with no row.
        // (Increment H7 un-omitted `hidden`; `calculated` remains omitted.)
        $sources = TemplateSources::fromFields($allFields);
        $answers = [];

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
                'fields' => $ungrouped->map(fn (FormField $f): array => $this->field($form, $f, $sources, $answers))->values()->all(),
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
                'label' => $this->templates->render($section->label, $sources, $answers),
                'description' => $this->templates->renderOptional($section->description, $sources, $answers),
                'repeatable' => $section->is_repeatable,
                'min_instances' => $section->is_repeatable ? $section->min_instances : null,
                'max_instances' => $section->is_repeatable ? $section->max_instances : null,
                'fields' => $sectionFields
                    ->map(fn (FormField $f): array => $this->field($form, $f, $sources, $answers))
                    ->values()->all(),
            ];
        }

        $now = $this->clock();

        return [
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
                'description' => $form->description,
                // Increment H21c — the presentation mode this channel never read. `false` (the column
                // default) is the STEPPED flow; a one-section form still projects to exactly one step, so
                // the difference is invisible until a form actually has branches.
                'single_page_mode' => $form->single_page_mode,
                // Required by the runtime store's `SchemaResponse`. The encode channel is SINGLE-LOCALE — a
                // recorded narrowing, not an oversight: `blocks` above resolves no `*_translations` either
                // (a keyer transcribes the paper form in front of them, and the language switcher is a
                // respondent affordance). Emitting the default alone is what keeps that switcher absent.
                'default_locale' => $form->default_locale,
                'supported_locales' => [$form->default_locale],
                // Scheduled-form window + cap (Increment H12b) — the encode page pre-warns (banner + disabled
                // submit) when `acceptance` != 'open'. Enforcement stays authoritative in the pipeline
                // ({@see FormAcceptanceGuard}); this is advisory, the twin of PublicFormPresenter's block.
                'schedule' => $this->schedule($form),
            ],
            'version' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                // Increment H21c — the engine's input, verbatim. `checksum` travels with it for the same
                // reason it does on the guest channel: the snapshot is the frozen contract, and the pair
                // must be read together.
                'checksum' => $version->checksum,
                'schema' => $version->schema_snapshot,
                'now' => $now,
            ],
            'blocks' => $blocks,
            // Increment H21c — the step list under an EMPTY answer document: the first paint, and the value
            // the parity suite and the PDF cross-check compare.
            'steps' => $this->steps($version, $sections, $allFields, $now),
        ];
    }

    /**
     * The session clock, in Carbon's exact `toIso8601String()` shape — `2026-08-01T12:34:56+00:00`, never a
     * `Z`, never milliseconds (Doc #27 §3.4, amendment A1).
     *
     * STAMPED BY THE SERVER, and that is the one place this channel deliberately diverges from the guest
     * runtime, which stamps its own with `isoClock(new Date())` at `App.vue`. The encode page hands this
     * string straight to `RuntimeOptions.now`, so the `steps` computed below and the client's first
     * `visibleSteps` evaluate `today()`/`now()` against ONE clock. Let the client stamp its own and a keyer
     * working near midnight — or on a machine with a skewed clock — gets a step list that disagrees with the
     * one the server just rendered, on a page whose entire purpose is that the two agree.
     */
    private function clock(): string
    {
        return Carbon::now()->toIso8601String();
    }

    /**
     * This version's step projection under an empty answer document — the recipe
     * {@see StepGraphInspector::emptyAtOpen()} already uses, reused rather than
     * re-derived.
     *
     * `$allFields` rather than the OMITTED-filtered `$fields`, and the reason is a CONTRACT rather than an
     * observable difference — recorded that way because a mutation proved it. The projection's emptiness
     * predicate is `PdfFieldRole::Omitted`, the RESPONDENT's "renders nothing" set, which is deliberately not
     * `self::OMITTED` (H7 makes the two channels asymmetric on `hidden`). Passing the pre-filtered list is a
     * NO-OP today and reddens nothing: `self::OMITTED` = {`page_break`, `calculated`} is a strict SUBSET of
     * `PdfFieldRole::Omitted` = {`hidden`, `calculated`, `page_break`}, so the projection simply re-drops what
     * the filter already dropped.
     *
     * That subset relation is an accident of the current lists, it is enforced nowhere, and the two answer
     * DIFFERENT questions — `self::OMITTED` is "never a manually-entered answer", `PdfFieldRole::Omitted` is
     * "the respondent sees nothing". Add a type to `self::OMITTED` that the respondent does see (a future
     * `signature`, say, keyed from paper by nobody) and the pre-filtered list would start hiding a step the
     * respondent was shown, silently. `EncodeStepParityTest` pins the subset relation directly so that day is
     * a red test rather than a divergence, and passing the complete list keeps the respondent's rule the only
     * one this projection ever applies.
     *
     * The `errors` from the validate call are ignored on purpose. An empty encode form is full of
     * required-field errors and not one of them is being reported here — only the three masks are wanted.
     *
     * @param  Collection<int, FormSection>  $sections
     * @param  Collection<int, FormField>  $fields
     * @return list<array{key: string, section_key: ?string, field_keys: list<string>, is_repeat: bool}>
     */
    private function steps(FormVersion $version, Collection $sections, Collection $fields, string $now): array
    {
        $normalized = $this->normalizer->normalize($fields, $sections, []);
        $result = $this->semantic->validate($version, $normalized, null, $now);

        return array_map(
            static fn (StepDescriptor $step): array => [
                'key' => $step->key,
                'section_key' => $step->sectionKey,
                'field_keys' => $step->fieldKeys,
                'is_repeat' => $step->isRepeat,
            ],
            StepProjection::of(
                $sections,
                $fields,
                $result->sectionRelevance,
                $result->fieldRelevance,
                $result->repeatFieldRelevance,
            ),
        );
    }

    /**
     * The scheduled-form runtime block (Increment H12b) — the same wire shape the guest presenter emits, via
     * the shared {@see FormScheduleView}. The live finalized COUNT is read only when a cap exists.
     *
     * @return array{opens_at: ?string, closes_at: ?string, timezone: string, max_responses: ?int, acceptance: string, remaining: ?int}
     */
    private function schedule(Form $form): array
    {
        $cap = $form->max_responses;
        $finalizedCount = $cap === null ? null : $this->finalizedCount($form);

        return FormScheduleView::present($form, $finalizedCount);
    }

    /** The live count of finalized (non-draft) submissions for this form, RLS-scoped to the tenant. */
    private function finalizedCount(Form $form): int
    {
        return Submission::query()
            ->where('form_id', $form->id)
            ->where('status', '!=', SubmissionStatus::Draft->value)
            ->count();
    }

    /**
     * @param  array<string, array{type: FieldType, config: array<string, mixed>}>  $sources
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function field(Form $form, FormField $field, array $sources, array $answers): array
    {
        $type = $field->field_type;
        $prefill = PrefillSource::for($type, $field->config);

        return [
            'key' => $field->key,
            'field_type' => $type->value,
            'label' => $this->templates->render($field->label, $sources, $answers),
            'hint' => $this->templates->renderOptional($field->hint, $sources, $answers),
            'placeholder' => $this->templates->renderOptional($field->placeholder, $sources, $answers),
            'required' => $field->is_required === RequiredMode::Required,
            'options' => $this->options($field),
            // Cascading hierarchy (Increment G4a); null for every other type so the shared FieldInput ignores it.
            'cascade' => $this->cascade($field),
            // Composite grid config (Increment G4b: matrix / likert_matrix); null for every other type.
            'matrix' => $this->matrix($field),
            // Geo capture config (Increment G5b2: geopoint / geotrace / geoshape); null for every other type.
            'geo' => $this->geo($field),
            // Media capture config (Increment G6: file/image/audio/video); null for every other type.
            'media' => $this->media($field),
            // Where a media control POSTs a staged upload (Increment G6) — the authenticated form-scoped
            // endpoint. Only media fields carry it; the shared FieldInput ignores it for other types.
            'upload' => $type->isMedia() ? ['url' => route('forms.attachments.store', $form)] : null,
            // Where a hidden field's value comes from (Increment H7); null for every other type, which is
            // what makes this additive for every existing row. `'fixed'` renders read-only (the server
            // writes the authored literal regardless of what this page sends), `'url'` renders a real input.
            'prefill' => $type === FieldType::Hidden ? $prefill->value : null,
            // The already-server-set value shown beside a `'fixed'` row, so the keyer can see what will be
            // recorded rather than an unexplained blank. Never editable, never submitted.
            'prefill_value' => $prefill === PrefillSource::Fixed ? $field->default_value : null,
            // `note` is display-only (handled by the page), never an input; a repeatable section's fields are
            // supported and render inside the add/remove-instance loop (Increment G2). A hidden field is
            // keyable ONLY when it is externally sourced — a `fixed` or source-less one has no answer this
            // channel could legitimately contribute (Increment H7).
            'supported' => $type === FieldType::Hidden
                ? $prefill === PrefillSource::Url
                : in_array($type, self::SUPPORTED, true),
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
     * The geo capture config (Increment G5b2) — author options for the geopoint/geotrace/geoshape control,
     * normalised from the stored snake_case config into the camelCase shape the shared FieldInput geo control
     * consumes (mirrors schema-mapping.ts buildGeo). Null for every non-geo type. Labels-free (no translation).
     *
     * @return array{captureAltitude: bool, accuracyThreshold: float|null, defaultCenter: array{lat: float, lon: float}|null, defaultZoom: int|null}|null
     */
    private function geo(FormField $field): ?array
    {
        if (! $field->field_type->isGeo()) {
            return null;
        }

        $center = data_get($field->config, 'default_center');
        $defaultCenter = null;
        if (is_array($center)
            && isset($center['lat'], $center['lon'])
            && is_numeric($center['lat'])
            && is_numeric($center['lon'])
        ) {
            $defaultCenter = ['lat' => (float) $center['lat'], 'lon' => (float) $center['lon']];
        }

        $threshold = data_get($field->config, 'accuracy_threshold');
        $zoom = data_get($field->config, 'default_zoom');

        return [
            'captureAltitude' => data_get($field->config, 'capture_altitude') === true,
            'accuracyThreshold' => is_numeric($threshold) ? (float) $threshold : null,
            'defaultCenter' => $defaultCenter,
            'defaultZoom' => is_numeric($zoom) ? (int) $zoom : null,
        ];
    }

    /**
     * The media capture config (Increment G6) — author options for the file/image/audio/video control,
     * normalised from the stored snake_case config into the camelCase shape the shared FieldInput media
     * control consumes (mirrors schema-mapping.ts buildMedia). Null for every non-media type.
     *
     * @return array{acceptedTypes: list<string>, maxFileSizeBytes: int|null, maxCount: int|null, minCount: int|null, captureSource: string|null}|null
     */
    private function media(FormField $field): ?array
    {
        if (! $field->field_type->isMedia()) {
            return null;
        }

        $accepted = data_get($field->config, 'accepted_types');
        $maxSize = data_get($field->config, 'max_file_size_bytes');
        $maxCount = data_get($field->config, 'max_count');
        $minCount = data_get($field->config, 'min_count');
        $source = data_get($field->config, 'capture_source');

        return [
            'acceptedTypes' => is_array($accepted) ? array_values(array_filter($accepted, 'is_string')) : [],
            'maxFileSizeBytes' => is_numeric($maxSize) ? (int) $maxSize : null,
            'maxCount' => is_numeric($maxCount) ? (int) $maxCount : null,
            'minCount' => is_numeric($minCount) ? (int) $minCount : null,
            'captureSource' => is_string($source) ? $source : null,
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
