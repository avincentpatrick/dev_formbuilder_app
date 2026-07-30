<?php

declare(strict_types=1);

namespace App\Services\Templates;

use App\Enums\PipingEligibility;
use App\Services\Forms\ExpressionValidationGate;

/**
 * The publish-time predicate for ONE template hole (`docs/piping-output-encoding-design.md` §3.1/§3.3).
 * Pure and DB-free — positions and source metadata in, a violation slug or null out — so every rule is
 * testable without a database and the gate stays a thin walk.
 *
 * ── Why this cannot simply call ExpressionParser::assertReferencesResolve() ─────────────────────────
 * §3.3 says so, and the code confirms it: that method's `$knownKeys` is built by
 * {@see ExpressionValidationGate} (`:47-61`) as a FLAT `array<string, bool>` union of
 * every field key AND every section key, carrying no scope information at all. Passed a template's
 * references it would accept `${roster}` in a label (rule 4 — a section key is a legal `count()` operand
 * and an illegal hole) and accept a cross-repeat reference (rule 3), because neither is expressible in
 * that set. H6a reuses the WALK shape and the exception envelope; the predicate is template-specific.
 *
 * ── The ordering tuple, as amended by H6a ───────────────────────────────────────────────────────────
 * §3.3 rule 1 originally named `(section_sequence, sequence)`. That is not implementable as written:
 * `form_fields.section_sequence` is a NULLABLE "order within its section" column that is null for nearly
 * every row (`FieldLibrary`, `SchemaBlueprintMaterializer` and the test fixtures all write null; only the
 * reorder endpoint sets it), and a section's own position lives on `form_sections.sequence`, so the
 * literal tuple inverts the hierarchy and sorts mostly-nulls. The implementable form of the same INTENT —
 * "a hole may reference only a field that precedes its own host in document order" — is
 * `(owning section's sequence, field's own sequence)`, both `integer default 0` NOT NULL, which is the
 * order every existing consumer already renders in (`EncodeFormPresenter`, `SubmissionInboxPresenter` and
 * `SubmissionExporter` all group by section and sort by `sequence`). Doc #26 §3.3 is amended to match.
 *
 * Positions are `[sectionOrder, fieldOrder]` pairs compared strictly and lexicographically:
 *   - a section-less field  → `[-1, sequence]`   (ungrouped fields lead, as all three presenters render)
 *   - a sectioned field     → `[section.sequence, sequence]`
 *   - a section's own label/description → `[section.sequence, -1]`  (a heading precedes its members)
 *
 * A TIE is a rejection: two fields at the same position have no defined render order, so a reference
 * between them is not provably backward. Real forms are unaffected — `FormBuilderService` mints
 * `max(sequence) + 1`, the reorder endpoint writes a dense index, and XLSForm import uses row order — but
 * a test fixture that leaves `sequence` at its `0` default will trip it.
 *
 * @phpstan-type PipingSource array{type: PipingEligibility, position: array{int, int}, repeat: ?string}
 */
final class TemplateScopeResolver
{
    /**
     * The §3.3 violation for one hole, or null when it resolves.
     *
     * Slugs are snake_case and stable — they travel into `PublishValidationException`'s message as the
     * `$detail`, so tests match slugs and never wording.
     *
     * @param  array{int, int}  $hostPosition  the position of the text carrying this hole
     * @param  ?string  $hostRepeat  the repeatable section key the host sits in, or null at flat scope
     * @param  array<string, PipingSource>  $sources  every field key in the version
     * @param  array<string, true>  $sectionKeys  every section key in the version
     */
    public function violationFor(
        string $holeKey,
        array $hostPosition,
        ?string $hostRepeat,
        array $sources,
        array $sectionKeys,
    ): ?string {
        // Rule 4 — a section key is never a template hole. `${roster}` resolves to a list<instanceMap>
        // for count(); a template has no text rendering for that, and displayValue() would json_encode
        // each instance map into the middle of a question. Checked BEFORE the unknown-key case so the
        // author gets the specific diagnosis rather than "unknown field".
        if (array_key_exists($holeKey, $sectionKeys)) {
            return 'template_references_section';
        }

        $source = $sources[$holeKey] ?? null;

        if ($source === null) {
            return 'unknown_template_reference';
        }

        // §3.1 — split by verdict so the message can say WHICH mistake this is.
        $violation = match ($source['type']) {
            PipingEligibility::Pipeable => null,
            PipingEligibility::NoAnswer => 'template_source_has_no_answer',
            PipingEligibility::Excluded => 'template_source_not_pipeable',
        };

        if ($violation !== null) {
            return $violation;
        }

        // Rules 2+3 — repeat scope. A hole inside repeat S may name a field in S (resolved against the
        // CURRENT instance) or a field outside every repeat (one value). Everything else names either no
        // instance (cross-repeat) or N values (repeat-to-flat).
        if ($source['repeat'] !== $hostRepeat && $source['repeat'] !== null) {
            return $hostRepeat === null
                ? 'template_repeat_to_flat_reference'
                : 'template_cross_repeat_reference';
        }

        // Rule 1 — backward reference only. A forward reference is permanently empty, which is an
        // authoring bug the gate can see. Applied uniformly, including to `hidden` and `calculated`:
        // both belong ahead of their consumers anyway (a prefill field at the top, a running total after
        // its operands).
        if (! $this->precedes($source['position'], $hostPosition)) {
            return 'template_forward_reference';
        }

        return null;
    }

    /**
     * The position of a field, for {@see violationFor()}'s `$sources` map.
     *
     * @return array{int, int}
     */
    public static function fieldPosition(?int $sectionSequence, int $sequence): array
    {
        return [$sectionSequence ?? -1, $sequence];
    }

    /**
     * The position of a section's own label/description — ahead of every field it contains.
     *
     * @return array{int, int}
     */
    public static function sectionPosition(int $sectionSequence): array
    {
        return [$sectionSequence, -1];
    }

    /**
     * Strict lexicographic precedence; a tie is NOT precedence (see the class docblock).
     *
     * @param  array{int, int}  $source
     * @param  array{int, int}  $host
     */
    private function precedes(array $source, array $host): bool
    {
        if ($source[0] !== $host[0]) {
            return $source[0] < $host[0];
        }

        return $source[1] < $host[1];
    }
}
