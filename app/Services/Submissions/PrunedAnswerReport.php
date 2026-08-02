<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\FieldType;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Services\Expressions\Coercion;
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
 * RELEVANCE PRUNES ONLY, deliberately. A Stage-1 drop is a different animal and is not reported here: an
 * unknown or misplaced key already throws, an empty answer was never an answer, and a `fixed`-prefill hidden
 * field's client-sent value is dropped by `coerce()` on purpose (H7 — the server writes the authored literal
 * on every channel, and the encode page renders those rows read-only and never submits them). The only thing
 * this names is what RELEVANCE removed, which is the only silent loss.
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
    public function __construct(private readonly TemplateRenderer $templates) {}

    /**
     * The dropped answers, labelled, in document order. Empty when nothing was pruned — the caller flashes
     * nothing rather than an empty banner.
     *
     * `$submitted` is the RAW request map, not the Stage-1 output: the normalizer renames no keys (it coerces
     * values and nests repeat instances under the same section key), so the raw map shares its key space with
     * the masks, and taking it raw means the report sees exactly what the keyer sent — including a value
     * Stage 1 would have coerced away.
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

        foreach ($submitted as $key => $value) {
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

            if (Coercion::isEmpty($value)) {
                continue; // never typed, so nothing was taken away
            }

            if (($semantic->fieldRelevance[$key] ?? true) !== true) {
                $dropped[] = $this->label($field, $sources);
            }
        }

        return $dropped;
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

        $holdsAnything = false;
        foreach ($instances as $instance) {
            foreach ($instance as $memberValue) {
                if (! Coercion::isEmpty($memberValue)) {
                    $holdsAnything = true;
                    break 2;
                }
            }
        }

        if (! $holdsAnything) {
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
                if ($member === null || Coercion::isEmpty($memberValue)) {
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
