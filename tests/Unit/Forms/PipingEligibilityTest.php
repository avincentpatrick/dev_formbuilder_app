<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\OcrFieldEligibility;
use App\Enums\PipingEligibility;

// The §3.1 piping-source classification, locked type by type (no database, no container — tests/Pest.php
// binds TestCase to Feature only). The twin of OcrFieldEligibilityTest, and the same spec lock:
// `docs/piping-output-encoding-design.md` §3.1's verdict table and PipingEligibility::for() must say the
// same thing, and the totality test below is what makes a 32nd FieldType case impossible to add without a
// decision.

/**
 * §3.1's verdict table, transcribed from the doc rather than derived from the enum — the point is that the
 * two are written independently and must agree.
 *
 * @return array{pipeable: list<string>, no_answer: list<string>, excluded: list<string>}
 */
function pipingVerdictTable(): array
{
    return [
        // Respondent-supplied scalars (17), plus the two deliberate divergences from OCR (19 total).
        'pipeable' => [
            'short_text', 'long_text', 'email', 'phone', 'url',
            'integer', 'decimal',
            'date', 'time', 'datetime', 'duration',
            'single_select', 'multi_select', 'dropdown', 'yes_no', 'cascading_select',
            'likert_scale',
            'calculated', 'hidden',
        ],
        // Hold no answer at all — exactly SchemaValueFormatter's NON_DATA set (2).
        'no_answer' => ['note', 'page_break'],
        // Object-valued grids, geo envelopes and attachment references (10).
        'excluded' => [
            'matrix', 'likert_matrix',
            'geopoint', 'geotrace', 'geoshape',
            'file_upload', 'image_capture', 'audio_capture', 'video_capture', 'signature',
        ],
    ];
}

/** @return list<string> the backing values of every case with the given verdict, in catalog order */
function pipingTypesWithVerdict(PipingEligibility $verdict): array
{
    return array_values(array_map(
        static fn (FieldType $t): string => $t->value,
        array_filter(FieldType::cases(), static fn (FieldType $t): bool => PipingEligibility::for($t) === $verdict),
    ));
}

it('classifies every field type in the catalog exactly once for piping', function (): void {
    // The forcing mechanism's second layer. for() has no `default` arm, so a new FieldType case throws
    // UnhandledMatchError right here (PHPStan L8 reports it first, in the merge-blocking static-analysis
    // job) — and if someone adds a `default` to silence that, these three set assertions still fail.
    //
    // Note this is the SECOND assertion of the number 31 in the suite (OcrFieldEligibilityTest has the
    // other), so a 32nd field type means editing two files. That is deliberate: the two classifications
    // are independent policies and each owes its own decision.
    $table = pipingVerdictTable();

    expect(FieldType::cases())->toHaveCount(31)
        ->and(pipingTypesWithVerdict(PipingEligibility::Pipeable))->toEqualCanonicalizing($table['pipeable'])
        ->and(pipingTypesWithVerdict(PipingEligibility::NoAnswer))->toEqualCanonicalizing($table['no_answer'])
        ->and(pipingTypesWithVerdict(PipingEligibility::Excluded))->toEqualCanonicalizing($table['excluded']);

    // 19 + 2 + 10 = 31. A case can only take one match arm, so this really guards the three literal lists
    // above against drifting into overlap or dropping a type — and it survives someone adding a `default`
    // arm to silence PHPStan.
    expect(count($table['pipeable']) + count($table['no_answer']) + count($table['excluded']))
        ->toBe(count(FieldType::cases()));
});

it('pipes a calculated field even though OCR excludes it', function (): void {
    // §3.1's first deliberate divergence. OCR excludes `calculated` because a scanned value could only
    // contradict the formula; piping is the opposite case — a running total is one of the most valuable
    // things to pipe, and it is a scalar the engine has already computed.
    expect(PipingEligibility::for(FieldType::Calculated))->toBe(PipingEligibility::Pipeable)
        ->and(OcrFieldEligibility::for(FieldType::Calculated))->toBe(OcrFieldEligibility::Excluded);
});

it('pipes a hidden field even though OCR treats it as neutral', function (): void {
    // §3.1's second deliberate divergence. OCR calls `hidden` Neutral because paper never carries it;
    // piping is its primary consumer — a URL-prefilled first name (H7) exists BEFORE the first label
    // renders, which is exactly what makes it a good source.
    expect(PipingEligibility::for(FieldType::Hidden))->toBe(PipingEligibility::Pipeable)
        ->and(OcrFieldEligibility::for(FieldType::Hidden))->toBe(OcrFieldEligibility::Neutral);
});

it('splits the answer-free structural types away from hidden', function (): void {
    // OcrFieldEligibilityTest asserts [note, page_break, hidden] are ALL Neutral. Piping SPLITS that set,
    // so copy-pasting that test into this file would encode the OCR verdict and contradict §3.1. Only the
    // two genuinely answer-free types land in NoAnswer here.
    foreach ([FieldType::Note, FieldType::PageBreak] as $type) {
        expect(PipingEligibility::for($type))->toBe(PipingEligibility::NoAnswer);
    }

    expect(PipingEligibility::for(FieldType::Hidden))->not->toBe(PipingEligibility::NoAnswer);
});

it('excludes every object-valued and attachment-valued type', function (): void {
    // Cross-checked against FieldType's own predicates rather than composed FROM them: `isComposite()` and
    // `isGeo()` are `===` chains and `isMedia()` has a `default =>` arm, so all three widen silently for a
    // 32nd type. They are safe to ASSERT with and unsafe to BUILD the rule with — the H8 lesson.
    foreach (FieldType::cases() as $type) {
        if ($type->isComposite() || $type->isGeo() || $type->isMedia()) {
            expect(PipingEligibility::for($type))->toBe(
                PipingEligibility::Excluded,
                "{$type->value} is object- or attachment-valued and cannot render inside a sentence",
            );
        }
    }

    // `matrix` is declared under FieldType's `// Structural` comment block and category() maps it to
    // FieldCategory::Structural — a classification grouped by the catalog's comments would misfile it as
    // answer-free. It is excluded, not no-answer.
    expect(PipingEligibility::for(FieldType::Matrix))->toBe(PipingEligibility::Excluded);
});

it('does not inherit H8s monotonicity invariant', function (): void {
    // Recorded as a deliberate NON-property. H8's invariant (its permitted set is disjoint from the pre-H8
    // denylist, so eligibility can only shrink) is what licenses its backfill to write a constant false.
    // Piping deliberately WIDENS relative to OCR, which is exactly the change class that invariant exists
    // to alarm on — so importing it here would be false. This test pins the widening so a later reader
    // does not "restore" the invariant and quietly drop `calculated`/`hidden` as sources.
    $widenedRelativeToOcr = array_values(array_filter(
        FieldType::cases(),
        static fn (FieldType $t): bool => PipingEligibility::for($t) === PipingEligibility::Pipeable
            && OcrFieldEligibility::for($t) !== OcrFieldEligibility::Extractable,
    ));

    expect(array_map(static fn (FieldType $t): string => $t->value, $widenedRelativeToOcr))
        ->toEqualCanonicalizing(['calculated', 'hidden']);
});
