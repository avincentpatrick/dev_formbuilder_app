<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\FieldType;
use App\Enums\PdfFieldRole;
use App\Enums\SubmissionSource;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\User;
use App\Services\Templates\TemplateRenderer;
use App\Services\Templates\TemplateSources;
use App\Services\Validation\SemanticResult;
use App\Services\Validation\SemanticValidator;
use App\Support\Forms\LocaleVariant;
use Illuminate\Support\Collection;

/**
 * The render model for one submission's PDF (Increment H17) — a pure array, no HTML, no Blade, no
 * dompdf. {@see SubmissionPdfRenderer} turns it into a document; this class decides what the
 * document says.
 *
 * ── Why this is a FORK of SubmissionInboxPresenter, not a reuse ─────────────────────────────────
 * The two surfaces answer different questions. An inbox answers "what did we collect?"; a PDF
 * answers "what did this person see and say?". Four concrete consequences, each of which would be
 * a defect if the inbox's version were reused unchanged:
 *
 *   1. FIELD SET. The inbox filters on `SchemaValueFormatter::isDataField()`, which excludes only
 *      `note` and `page_break` — so it renders rows for `hidden` and `calculated`, the two types
 *      the respondent provably never saw. This uses {@see PdfFieldRole}, whose Omitted set is
 *      pinned identical to the guest runtime's `RENDERS_NOTHING`.
 *   2. RELEVANCE. The inbox applies none, so an irrelevant field renders as an em-dash row
 *      indistinguishable from an answered-blank one. See "the replay" below.
 *   3. FALLBACK COPY. The inbox's "No entries." and em-dash live in `Show.vue`, not in the
 *      presenter — it emits `fields: []` and `value: ''`. A PDF has no Vue, so both are emitted
 *      here as {@see self::EMPTY_REPEAT} and {@see self::BLANK}, which also makes them assertable
 *      without rendering a document.
 *   4. LOCALE. The inbox is HALF-localized: choice VALUES resolve their `label_translations`
 *      through `displayValue($…, $locale)`, but field and section LABELS are always the base
 *      `label` column. Doc #26 §4 makes "resolve the locale, THEN render" normative and only
 *      `SubmissionExporter::header()` implements the first half. This does too, via
 *      {@see LocaleVariant}.
 *
 * What IS reused verbatim, because it is correct and hard-won: the repeat walk (H6b amendment A9),
 * `TemplateSources::fromFields()` over ALL fields including the omitted ones (a `hidden` field is
 * not rendered but is still a legal piping source — Doc #26 §3.1), `TemplateRenderer::render()`
 * and `SchemaValueFormatter::displayValue()`.
 *
 * ── The replay, and why it is the load-bearing part ─────────────────────────────────────────────
 * Relevance is persisted NOWHERE. `SemanticResult`'s masks are computed at submit and discarded;
 * neither `submissions` nor `submission_answers` has a column, key or snapshot for them. The only
 * trace is negative and lossy — `SemanticValidator::effectiveAnswers()` prunes irrelevant keys,
 * and the deliberate `unset()`s for empty media/geo/composite drop answered-blank keys too, so
 * "key absent" cannot distinguish "never shown" from "shown and skipped".
 *
 * So the mask is re-derived, with the clock PINNED to the submission's own finalisation time
 * (H17's fourth argument to `validate()`). Without the pin, a `relevant_expression` reading
 * `today()` would be evaluated against the day someone clicked "Generate PDF" and could produce a
 * different mask than the one that actually pruned the document.
 *
 * The replay runs over the already-pruned stored document rather than the raw input the original
 * run saw, so it is a FIXED POINT rather than a literal replay: a pruned upstream key is absent,
 * and absent reads as empty, which is what the original run's own fixed-point semantics produced.
 * `SubmissionPdfPresenterTest` pins that property directly rather than leaving it as an argument.
 *
 * VALUES still come from the STORED document, never from the replay's `effectiveAnswers`. The
 * stored document is `effectiveAnswers + computed` merged at `SubmissionPipeline.php:147` and it
 * is the persisted truth; the replay's own `effectiveAnswers` would drop every `calculated` value
 * (they live on a separate `computed` map) and silently blank any piped `${total}` hole. The
 * replay is consulted for MASKS ONLY.
 */
final class SubmissionPdfPresenter
{
    /** What an answered-blank field shows. The inbox's `Show.vue:167` em-dash, moved server-side. */
    public const BLANK = '—';

    /** What a repeatable section with no stored instances shows. `Show.vue:163`, moved server-side. */
    public const EMPTY_REPEAT = 'No entries.';

    public function __construct(
        private readonly SchemaValueFormatter $formatter,
        private readonly TemplateRenderer $templates,
        private readonly SemanticValidator $semantic,
    ) {}

    /**
     * The whole document model. Takes no {@see User}: the inbox needs one only for its
     * `can.review` flag (`SubmissionInboxPresenter.php:143`), row visibility is route middleware,
     * and a queue worker has no user to give. Every value below is UNTRUSTED and must be escaped
     * by the view — Doc #26 decision (3) binds the whole surface, not only the piped parts.
     *
     * @return array<string, mixed>
     */
    public function present(Submission $submission): array
    {
        $submission->loadMissing(['form:id,title', 'formVersion:id,version_number', 'respondent:id,name,email', 'answers']);

        $version = $submission->formVersion;
        $answers = $this->answersOf($submission);
        $locale = $submission->locale;

        return [
            'form_title' => (string) (data_get($submission, 'form.title') ?? ''),
            'version_number' => $version?->version_number,
            'submission' => [
                'id' => $submission->id,
                'status_label' => $submission->status->label(),
                'source_label' => $submission->source->label(),
                'respondent' => $this->respondentLabel($submission),
                'locale' => $locale,
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
                'finalized_at' => $submission->finalized_at?->toIso8601String(),
            ],
            'blocks' => $version === null ? [] : $this->answerBlocks($version, $answers, $locale, $this->replay($version, $answers, $submission, $locale)),
        ];
    }

    /**
     * Re-derive the relevance masks with the clock pinned to when the respondent actually finished.
     *
     * The fallback chain matters: `finalized_at` is the moment the answers became final, which is
     * the clock the original Stage-3 pass ran under. `submitted_at` covers a submission finalised
     * by a later review step, and `created_at` is the last resort so the pin is never silently
     * `Carbon::now()` — a null fourth argument would restore exactly the drift this exists to stop.
     *
     * @param  array<string, mixed>  $answers
     */
    private function replay(FormVersion $version, array $answers, Submission $submission, ?string $locale): SemanticResult
    {
        $at = $submission->finalized_at ?? $submission->submitted_at ?? $submission->created_at;

        return $this->semantic->validate($version, $answers, $locale, $at?->toIso8601String());
    }

    /**
     * Group the version's respondent-visible fields into their sections, ungrouped fields leading,
     * dropping everything the respondent did not see.
     *
     * @param  array<string, mixed>  $answers
     * @return list<array<string, mixed>>
     */
    private function answerBlocks(FormVersion $version, array $answers, ?string $locale, SemanticResult $relevance): array
    {
        $sections = $version->sections()->orderBy('sequence')->get();
        $allFields = $version->fields()->orderBy('sequence')->get();

        // Piping sources come from EVERY field, including the ones with no row of their own:
        // `hidden` and `calculated` are Pipeable per Doc #26 §3.1 precisely because their values
        // exist without the respondent acting. This is how a computed total still reaches the PDF
        // — inside the label the respondent read it in.
        $sources = TemplateSources::fromFields($allFields);

        $visible = $allFields->filter(
            fn (FormField $f): bool => PdfFieldRole::for($f->field_type) !== PdfFieldRole::Omitted,
        );

        /** @var Collection<string, Collection<int, FormField>> $bySection */
        $bySection = $visible->groupBy(fn (FormField $f): string => $f->form_section_id ?? '');

        $blocks = [];

        $ungrouped = $bySection->get('');
        if ($ungrouped !== null && $ungrouped->isNotEmpty()) {
            $rows = $this->fieldRows($ungrouped, $answers, $sources, $locale, $relevance->fieldRelevance);
            if ($rows !== []) {
                $blocks[] = ['id' => null, 'label' => null, 'fields' => $rows, 'notice' => null];
            }
        }

        foreach ($sections as $section) {
            $sectionFields = $bySection->get($section->id);
            if ($sectionFields === null || $sectionFields->isEmpty()) {
                continue;
            }

            // A section the respondent never reached takes its whole block with it — heading
            // included. Emitting the heading with no rows would claim they saw the section and
            // answered nothing in it, which is the specific lie this increment exists to avoid.
            if (($relevance->sectionRelevance[$section->key] ?? true) !== true) {
                continue;
            }

            if ($section->is_repeatable !== true) {
                $rows = $this->fieldRows($sectionFields, $answers, $sources, $locale, $relevance->fieldRelevance);
                if ($rows === []) {
                    continue;
                }

                $blocks[] = [
                    'id' => $section->id,
                    'label' => $this->sectionLabel($section, $sources, $answers, $locale),
                    'fields' => $rows,
                    'notice' => null,
                ];

                continue;
            }

            $instances = $this->instancesOf($answers, $section->key);

            // An added-nothing repeat keeps its heading and says so. Emitting nothing would hide
            // that the section existed; em-dash rows would claim it was answered blank. It was
            // neither — it is empty, and the document says which. (H6b's A9 rule, verbatim.)
            if ($instances === []) {
                $blocks[] = [
                    'id' => $section->id,
                    'label' => $this->sectionLabel($section, $sources, $answers, $locale),
                    'fields' => [],
                    'notice' => self::EMPTY_REPEAT,
                ];

                continue;
            }

            foreach ($instances as $index => $instance) {
                // The instance SHADOWS the flat document — the exact context SemanticValidator
                // builds for a repeat instance, so a member's label resolves against its own
                // siblings (Doc #26 §3.3 rule 2).
                $scoped = array_merge($answers, $instance);

                $blocks[] = [
                    'id' => $section->id.'#'.$index,
                    // Numbered exactly as `RepeatGroup.vue` numbered it for the respondent.
                    'label' => $this->sectionLabel($section, $sources, $scoped, $locale).' '.($index + 1),
                    'fields' => $this->fieldRows(
                        $sectionFields,
                        $scoped,
                        $sources,
                        $locale,
                        $relevance->repeatFieldRelevance[$section->key][$index] ?? [],
                    ),
                    'notice' => null,
                ];
            }
        }

        return $blocks;
    }

    /**
     * One block's rows, relevance-filtered.
     *
     * The `?? true` default is deliberate and fail-OPEN: an unmasked key means the validator did
     * not classify the field, which is normal for the answer-free types and would otherwise make
     * the document silently omit something. Showing one row too many is a cosmetic fault;
     * omitting a question the respondent answered is a false record.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $answers  already scoped to one repeat instance where applicable
     * @param  array<string, array{type: FieldType, config: array<string, mixed>}>  $sources
     * @param  array<string, bool>  $mask
     * @return list<array{key: string, label: string, value: string, role: string}>
     */
    private function fieldRows(Collection $fields, array $answers, array $sources, ?string $locale, array $mask): array
    {
        $rows = [];

        foreach ($fields as $field) {
            if (($mask[$field->key] ?? true) !== true) {
                continue;
            }

            $role = PdfFieldRole::for($field->field_type);
            $label = LocaleVariant::resolve($field->label_translations, $field->label, $locale, $field->key);

            $rows[] = [
                'key' => $field->key,
                'label' => $this->templates->render($label, $sources, $answers, $locale),
                // Prose carries no answer at all, so it never reaches displayValue() — a `note` is
                // the text itself, not a question with a response.
                'value' => $role === PdfFieldRole::Prose
                    ? ''
                    : $this->blankable($this->formatter->displayValue($field->field_type, $answers[$field->key] ?? null, $field->config ?? [], $locale)),
                'role' => $role->value,
            ];
        }

        return $rows;
    }

    /**
     * The section heading, locale-resolved then hole-rendered — §4's normative order.
     *
     * @param  array<string, array{type: FieldType, config: array<string, mixed>}>  $sources
     * @param  array<string, mixed>  $answers
     */
    private function sectionLabel(FormSection $section, array $sources, array $answers, ?string $locale): string
    {
        return $this->templates->render(
            LocaleVariant::resolve($section->label_translations, $section->label, $locale, $section->key),
            $sources,
            $answers,
            $locale,
        );
    }

    /** An unanswered field reads as an em-dash, never as an empty cell with no explanation. */
    private function blankable(string $value): string
    {
        return $value === '' ? self::BLANK : $value;
    }

    /**
     * A repeatable section's stored instances. Fail-closed, mirroring the inbox: anything that is
     * not a list of maps is no instances at all.
     *
     * @param  array<string, mixed>  $answers
     * @return list<array<string, mixed>>
     */
    private function instancesOf(array $answers, string $sectionKey): array
    {
        $instances = $answers[$sectionKey] ?? null;

        if (! is_array($instances)) {
            return [];
        }

        return array_values(array_filter($instances, is_array(...)));
    }

    /**
     * The same rule as the inbox (`SubmissionInboxPresenter.php:289-296`) — one submission, one name.
     *
     * Reached through `data_get` rather than `$submission->respondent->name`, which is how the
     * inbox writes it: that form is the single reason this file would carry a PHPStan
     * `property.notFound` (Larastan does not resolve `User::$name` in this project, and there are
     * seven such reports across already-shipped files). The null-safe traversal returns `mixed`
     * and `is_string()` narrows it, so the same behaviour costs no static-analysis debt — and
     * `formTitle()`'s docblock in the inbox already blesses the idiom for exactly this reason.
     */
    private function respondentLabel(Submission $submission): string
    {
        $name = data_get($submission, 'respondent.name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return $submission->source === SubmissionSource::Guest ? 'Guest' : self::BLANK;
    }

    /**
     * The persisted answer document — `effectiveAnswers + computed`, merged at write time.
     *
     * @return array<string, mixed>
     */
    private function answersOf(Submission $submission): array
    {
        $answers = data_get($submission, 'answers.answers');

        return is_array($answers) ? $answers : [];
    }
}
