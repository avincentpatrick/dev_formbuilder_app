<?php

declare(strict_types=1);

namespace App\Services\Templates;

use App\Enums\FieldType;
use App\Services\Expressions\EvaluationContext;
use App\Services\Expressions\GridCells;
use App\Services\Expressions\Marker;
use App\Services\Submissions\EncodeFormPresenter;
use App\Services\Submissions\SchemaValueFormatter;
use App\Support\Forms\MatrixColumns;

/**
 * Fills a template's holes from an answer document (`docs/piping-output-encoding-design.md` §3.2/§3.4).
 * The PHP half of piping's render side; the guest SPA's reactive twin is H6b's.
 *
 * ── Two contracts that are easy to get wrong ────────────────────────────────────────────────────────
 * 1. **The rendered value of a hole is exactly `SchemaValueFormatter::displayValue()`.** Reused, never
 *    reimplemented — so a choice code renders as its author-defined label, `yes_no` as `Yes`/`No`, and a
 *    multi-select as its `'; '`-joined labels, identically in a piped label and in an export cell.
 *    `Coercion::toStr()` is explicitly BARRED as the primitive (§3.2): its non-integral float→string
 *    parity is documented as unpinned at `Coercion.php`, so a piped `decimal` rendered through it could
 *    disagree with the TS engine. This class never references it.
 * 2. **Rendering never fails.** A hole whose source has no answer renders as the EMPTY STRING — never the
 *    raw `${key}` token, never `undefined`, never a placeholder glyph. That keeps the two failure modes
 *    cleanly separated: publish is where an unresolvable REFERENCE is refused, render is where an
 *    unanswered VALUE is tolerated. A malformed template that somehow reached a snapshot (a hand-edited
 *    row, a snapshot from a newer TEMPLATE_VERSION) is parsed leniently rather than thrown on — a
 *    question with a gap in it is recoverable, a 500 on a respondent's form is not.
 *
 * ── On empty answer maps ────────────────────────────────────────────────────────────────────────────
 * Two of the three H6a call sites pass NO answers on purpose. {@see EncodeFormPresenter}
 * serves a blank keying form (there is no submission yet) and `SubmissionExporter::header()` writes one
 * header row that serves N submissions, so a hole there is structurally unfillable. Both render the gap
 * rather than the token, which is the same narrowing §8 records for a printed OCR form.
 *
 * @phpstan-type RenderSource array{type: FieldType, config: array<string, mixed>}
 */
final class TemplateRenderer
{
    public function __construct(
        private readonly TemplateParser $parser,
        private readonly SchemaValueFormatter $formatter,
    ) {}

    /**
     * Fill `$template`'s holes from `$answers`, formatting each through the field's own type and config.
     *
     * `$answers` is a FLAT key ⇒ value map — the same shape
     * {@see EvaluationContext} takes, and for the same reason: repeat scope is
     * the caller's job. `SemanticValidator` builds a per-instance context as
     * `array_merge($baseAnswers, $instanceAnswers)`, and a repeat-scoped renderer must do the same, so
     * §3.3 rule 2's "current instance" needs no addressable path (the stored instances carry no stable
     * id anyway).
     *
     * `$locale` is threaded to {@see SchemaValueFormatter::displayValue()} so a piped choice answer
     * resolves its option's `label_translations` variant (Increment H6b, Doc #26 amendment A8). §4's
     * order still holds and is still the CALLER's job: resolve `$template`'s own locale variant first,
     * then pass the same locale here so the value dropped into it agrees with the sentence around it.
     *
     * @param  array<string, RenderSource>  $sources  field key ⇒ its type + config
     * @param  array<string, mixed>  $answers  key ⇒ stored answer, already scoped to one repeat instance
     */
    public function render(string $template, array $sources, array $answers, ?string $locale = null): string
    {
        if ($template === '') {
            return '';
        }

        $out = '';

        foreach ($this->parser->parseLenient($template) as $segment) {
            if ($segment['type'] === 'literal') {
                $out .= $segment['value'];

                continue;
            }

            $source = $sources[$segment['value']] ?? null;
            $answer = $answers[$segment['value']] ?? null;

            // M246 (`R-ffd10da2`): `${grid.row.col}` / `${grid.row}` — one cell of a grid, shown as its column's type.
            if ($source === null && str_contains($segment['value'], '.')) {
                [$source, $answer] = $this->cell($segment['value'], $sources, $answers);
            }

            // An unknown key renders empty rather than throwing: the publish gate already refused every
            // resolvable-at-publish case, so reaching here means the snapshot and the answer document
            // disagree — recoverable, per §3.4.
            if ($source === null) {
                continue;
            }

            $out .= $this->formatter->displayValue(
                $source['type'],
                $answer,
                $source['config'],
                $locale,
            );
        }

        return $out;
    }

    /**
     * M246: a cell hole's source and answer — the grid's cell as {@see MatrixColumns::cellSource()} reads it, and the
     * cell's value as an expression reads it ({@see GridCells}); an unknown grid, row or column is no source.
     *
     * @param  array<string, RenderSource>  $sources
     * @param  array<string, mixed>  $answers
     * @return array{0: RenderSource|null, 1: mixed}
     */
    private function cell(string $hole, array $sources, array $answers): array
    {
        $parts = TemplateParser::holeParts($hole);
        $grid = $sources[$parts['key']] ?? null;
        $source = $grid === null || $parts['row'] === null
            ? null
            : MatrixColumns::cellSource($grid['type'], $grid['config'], $parts['row'], $parts['column']);

        if ($source === null) {
            return [null, null];
        }

        $value = GridCells::resolve($answers[$parts['key']] ?? null, $parts['row'], $parts['column']);

        return [$source, $value === Marker::Absent ? null : $value];
    }

    /**
     * {@see render()} for a nullable column (`hint`, `placeholder`, a section `description`), preserving
     * null rather than collapsing it to `''` — a presenter's "no hint" and "a hint that rendered empty"
     * are different states downstream. Mirrors the `resolveText`/`resolveOptional` pair the runtime's
     * `schema-mapping.ts` already draws for locale resolution.
     *
     * @param  array<string, RenderSource>  $sources
     * @param  array<string, mixed>  $answers
     */
    public function renderOptional(?string $template, array $sources, array $answers, ?string $locale = null): ?string
    {
        return $template === null ? null : $this->render($template, $sources, $answers, $locale);
    }
}
