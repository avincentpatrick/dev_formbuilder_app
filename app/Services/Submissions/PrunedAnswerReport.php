<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\FieldType;
use App\Enums\PrefillSource;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Services\Templates\TemplateRenderer;
use App\Services\Templates\TemplateSources;
use App\Services\Validation\SemanticResult;
use App\Support\Forms\StepProjection;
use Illuminate\Support\Collection;

/**
 * What the pipeline threw away, named back at the person who typed it — Increment H21c, the cheap half of
 * Doc #27 §7's defect (b).
 *
 * THE DEFECT, VERBATIM FROM §7: "A staffer keying a paper form sees every section, including branches the
 * respondent's own answers exclude. At submit, Stage 1 accepts the keys (they are known), Stage 3 masks the
 * irrelevant ones off, `effectiveAnswers()` drops them, and `passed()` is true — an irrelevant field is never
 * required-checked. Stage 4 persists the pruned document. The controller then discards the `SubmissionResult`
 * entirely and redirects with a flat 'Submission recorded.' The keyer is never told that half of what they
 * typed was dropped."
 *
 * §7 also fixes the landing order: the `semantic` result is ALREADY on {@see SubmissionResult}, so this is a
 * controller change and not an engine one, and it "should not wait for the expensive half".
 *
 * WHY IT SURVIVES THE EXPENSIVE HALF. Once the encode page mounts the client engine (the rest of H21c), it
 * hides what the server would prune, so this report normally finds nothing. It is kept as the DIVERGENCE
 * ALARM for the three cases the client cannot cover: the store's engine-degrade path (a malformed published
 * expression makes every field relevant client-side while the server still prunes), a page held open across
 * a republish, and a direct POST that never rendered the page at all. A silent report is the success case,
 * not dead code.
 *
 * RELEVANCE PRUNES ONLY, AND ONLY OF THINGS THE KEYER COULD HAVE TYPED. A Stage-1 drop is a different animal
 * and is not reported here: an unknown or misplaced key already throws, and an empty answer was never an
 * answer. Neither is a `fixed`-prefill hidden field, whose value is server-authored on every channel and
 * whose encode row is read-only — see {@see keyerContributed()}, which exists because the first cut of this
 * class asserted that such a value "never reaches the payload" and H21c made that false. The only thing this
 * names is what RELEVANCE removed from what a person entered, which is the only silent loss.
 *
 * NOT pure and static like {@see DraftCompleteness} / {@see StepProjection}, and the
 * departure is deliberate: the report must name each answer the way the KEYER saw it on screen, which means
 * resolving the label through {@see TemplateRenderer} against an empty answer document — H6a's contract for
 * this page ("a blank keying form: every hole renders as the empty string"). Naming a piped label by its raw
 * `${key}` token, or by a bare field key the keyer has never seen, would describe a row they cannot find.
 * {@see SubmissionPdfPresenter} is the precedent for a read model that renders labels.
 */
final class PrunedAnswerReport
{
    public function __construct(
        private readonly TemplateRenderer $templates,
        private readonly StructuralAnswerNormalizer $normalizer,
    ) {}

    /**
     * The dropped answers, labelled, in document order. Empty when nothing was pruned — the caller flashes
     * nothing rather than an empty banner.
     *
     * IT RE-RUNS STAGE 1, AND THAT IS NOT AN OPTIMISATION IT SKIPPED — IT IS THE ONLY WAY THE MASKS LINE UP.
     * The first cut of this class walked the RAW request map, on the reasoning that the normalizer renames no
     * keys. True for flat keys, and WRONG for repeat instances: `normalizeRepeatSection()` DROPS an empty
     * instance and re-indexes with `$instances[] =`, while `processRepeats()` appends one mask per SURVIVING
     * instance. So every dropped instance shifts every later mask by one, and the report then reads a
     * neighbour's mask — naming an answer that was persisted while missing the one that was actually pruned.
     * That is reachable on the ordinary encode flow, not in theory: `addInstance()` pushes a literal `{}`,
     * H21c seeds `min_instances` starter rows, and clearing a row's last value deletes the key, so a blank
     * row among filled ones is a normal state rather than an edge case.
     *
     * Re-normalising costs one pure pass over an array the pipeline has already accepted — it cannot throw
     * here, because a structural fault would have aborted the submit long before this is reached — and it
     * buys exact index alignment plus the emptiness rule for free, instead of this class keeping a second
     * copy of "what counts as unanswered" that could drift from the one Stage 1 actually applies.
     *
     * @param  array<string, mixed>  $submitted  the raw `answers` map as posted
     * @return list<string>
     */
    public function of(FormVersion $version, array $submitted, SemanticResult $semantic): array
    {
        /** @var Collection<int, FormField> $fields */
        $fields = $version->fields()->orderBy('sequence')->get();
        /** @var Collection<int, FormSection> $sections */
        $sections = $version->sections()->orderBy('sequence')->get();

        // The document the masks were computed against, byte for byte.
        $normalized = $this->normalizer->normalize($fields, $sections, $submitted);

        // Built from EVERY field, the way both encode-channel presenters do it: a source need not have a row
        // of its own for a consumer's label to be correct (Doc #26 §3.1).
        $sources = TemplateSources::fromFields($fields);

        /** @var array<string, FormField> $fieldByKey */
        $fieldByKey = [];
        foreach ($fields as $field) {
            $fieldByKey[$field->key] = $field;
        }

        /** @var array<string, FormSection> $repeatSectionByKey */
        $repeatSectionByKey = [];
        foreach ($sections as $section) {
            if ($section->is_repeatable === true) {
                $repeatSectionByKey[$section->key] = $section;
            }
        }

        $dropped = [];

        foreach ($normalized as $key => $value) {
            $key = (string) $key;

            $repeatSection = $repeatSectionByKey[$key] ?? null;
            if ($repeatSection !== null) {
                foreach ($this->repeatDrops($repeatSection, $value, $semantic, $fieldByKey, $sources) as $label) {
                    $dropped[] = $label;
                }

                continue;
            }

            $field = $fieldByKey[$key] ?? null;
            if ($field === null) {
                continue; // an unknown key never reaches here — Stage 1 throws on it
            }

            // Stage 1 already dropped every empty answer, so a key present here was typed. No second
            // emptiness rule lives in this class.
            if (! $this->keyerContributed($field)) {
                continue;
            }

            if (($semantic->fieldRelevance[$key] ?? true) !== true) {
                $dropped[] = $this->label($field, $sources);
            }
        }

        return $dropped;
    }

    /**
     * Whether a value under this field could have come from the KEYER at all — the question this report is
     * actually asking, and the one the first cut got wrong by asking "was it in the payload".
     *
     * A `fixed`-prefill hidden field fails it. Its value is the authored literal, written by
     * `StructuralAnswerNormalizer::applyFixedPrefills()` on EVERY channel whether the client sent it or not,
     * and `EncodeFormPresenter` renders its row read-only precisely because nobody keys it. Naming one in
     * this banner tells a keyer that something they never saw, never typed and cannot act on "was not saved"
     * — noise on the one alarm that has to stay believable.
     *
     * H21c is what made this reachable, so it is fixed here rather than left as a latent oddity: before it,
     * `Encode.vue` seeded an answer slot only for `supported` rows and a `fixed` hidden row is not one, so
     * the value never entered the payload. Now `createFormRuntime()` seeds it through `buildPrefill()` (which
     * is CORRECT — a label piping `${batch_id}` has to resolve on the first paint) and the page posts the
     * full map. The server was always going to write its own copy; this only stops the report from claiming
     * the keyer lost something.
     *
     * A `url`-prefill hidden field is the opposite case and is deliberately NOT excluded: on this channel the
     * keyer IS its source (H7 — they render in the "Reference fields" block), so a relevance prune there is
     * exactly the loss worth naming.
     */
    private function keyerContributed(FormField $field): bool
    {
        return PrefillSource::for($field->field_type, $field->config) !== PrefillSource::Fixed;
    }

    /**
     * A repeatable section drops in two distinct shapes, and conflating them would misreport one of them.
     *
     * WHOLE-GROUP: an irrelevant section is skipped by `processRepeats()` before any instance is settled, so
     * `repeatFieldRelevance` carries no mask for it at all and every instance went with it. Report the group
     * ONCE by its own label — listing each member of each instance would name a dozen rows for one condition.
     *
     * PER-MEMBER: the section survived, so each instance has its own mask and a member can be gated off
     * inside one instance and not another. Report those individually, numbered exactly as the page numbered
     * them for the keyer (the `label N` shape {@see SubmissionPdfPresenter} uses).
     *
     * `$value` is the NORMALIZED instance list, which is what makes `$masks[$index]` the right mask rather
     * than a neighbour's — see the note on {@see of()}. It also means the numbering here is the PERSISTED
     * instance number: a blank row the keyer left among filled ones is gone from both lists, so "Household
     * members 2" names the second row that survived, not the second row on screen. That is the honest label
     * for a message about what was stored, and the alternative — numbering by screen position — would need
     * this class to reconstruct which rows Stage 1 dropped, which is the duplicated-rule trap `of()` avoids.
     *
     * @param  array<string, FormField>  $fieldByKey
     * @param  array<string, array{type: FieldType, config: array<string, mixed>}>  $sources
     * @return list<string>
     */
    private function repeatDrops(
        FormSection $section,
        mixed $value,
        SemanticResult $semantic,
        array $fieldByKey,
        array $sources,
    ): array {
        if (! is_array($value) || $value === []) {
            return [];
        }

        $instances = array_values(array_filter($value, static fn (mixed $instance): bool => is_array($instance)));
        if ($instances === []) {
            return [];
        }

        $sectionLabel = $this->templates->render($section->label, $sources, []);

        if (($semantic->sectionRelevance[$section->key] ?? true) !== true) {
            return [$sectionLabel === '' ? $section->key : $sectionLabel];
        }

        $masks = $semantic->repeatFieldRelevance[$section->key] ?? [];
        $dropped = [];

        foreach ($instances as $index => $instance) {
            $mask = $masks[$index] ?? [];
            foreach ($instance as $memberKey => $memberValue) {
                $memberKey = (string) $memberKey;
                $member = $fieldByKey[$memberKey] ?? null;
                if ($member === null || ! $this->keyerContributed($member)) {
                    continue;
                }
                if (($mask[$memberKey] ?? true) !== true) {
                    $prefix = ($sectionLabel === '' ? $section->key : $sectionLabel).' '.($index + 1);
                    $dropped[] = $prefix.' · '.$this->label($member, $sources);
                }
            }
        }

        return $dropped;
    }

    /**
     * The label as the keyer read it: piped against an EMPTY answer document, matching what
     * {@see EncodeFormPresenter::field()} rendered into the row. A field with no label falls back to its key,
     * which is still better than an empty quotation.
     *
     * @param  array<string, array{type: FieldType, config: array<string, mixed>}>  $sources
     */
    private function label(FormField $field, array $sources): string
    {
        $rendered = $this->templates->render($field->label, $sources, []);

        return $rendered === '' ? $field->key : $rendered;
    }
}
