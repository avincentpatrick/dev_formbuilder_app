<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\OcrFieldEligibility;

// The §2 OCR classification, locked type by type (no database, no container — tests/Pest.php binds
// TestCase to Feature only). This file is the spec lock: `docs/ocr-pipeline-design.md` §2's verdict table
// and OcrFieldEligibility::for() must say the same thing, and the totality test below is what makes a
// 32nd FieldType case impossible to add without a decision.

/**
 * §2's verdict table, transcribed from the doc rather than derived from the enum — the point is that the
 * two are written independently and must agree.
 *
 * @return array{extractable: list<string>, neutral: list<string>, excluded: list<string>}
 */
function ocrVerdictTable(): array
{
    return [
        // Respondent-supplied and scalar-shaped (17).
        'extractable' => [
            'short_text', 'long_text', 'email', 'phone', 'url',
            'integer', 'decimal',
            'date', 'time', 'datetime', 'duration',
            'single_select', 'multi_select', 'dropdown', 'yes_no', 'cascading_select',
            'likert_scale',
        ],
        // Permitted but contributing nothing — they never satisfy the non-vacuity clause (3).
        'neutral' => ['note', 'page_break', 'hidden'],
        // Present anywhere ⇒ the whole version is ineligible (11).
        'excluded' => [
            'calculated',
            'matrix', 'likert_matrix',
            'geopoint', 'geotrace', 'geoshape',
            'file_upload', 'image_capture', 'audio_capture', 'video_capture', 'signature',
        ],
    ];
}

/** @return list<string> the backing values of every case with the given verdict, in catalog order */
function ocrTypesWithVerdict(OcrFieldEligibility $verdict): array
{
    return array_values(array_map(
        static fn (FieldType $t): string => $t->value,
        array_filter(FieldType::cases(), static fn (FieldType $t): bool => OcrFieldEligibility::for($t) === $verdict),
    ));
}

it('classifies every field type in the catalog exactly once', function (): void {
    // The forcing mechanism's second layer. for() has no `default` arm, so a new FieldType case throws
    // UnhandledMatchError right here (PHPStan L8 reports it first, in the merge-blocking static-analysis
    // job) — and if someone adds a `default` to silence that, these three set assertions still fail.
    $table = ocrVerdictTable();

    expect(FieldType::cases())->toHaveCount(31)
        ->and(ocrTypesWithVerdict(OcrFieldEligibility::Extractable))->toEqualCanonicalizing($table['extractable'])
        ->and(ocrTypesWithVerdict(OcrFieldEligibility::Neutral))->toEqualCanonicalizing($table['neutral'])
        ->and(ocrTypesWithVerdict(OcrFieldEligibility::Excluded))->toEqualCanonicalizing($table['excluded']);

    // 17 + 3 + 11 = 31. A case can only take one match arm, so this really guards the three literal lists
    // above against drifting into overlap or dropping a type.
    expect(count($table['extractable']) + count($table['neutral']) + count($table['excluded']))
        ->toBe(count(FieldType::cases()));
});

it('can only ever remove OCR eligibility relative to the pre-H8 rule', function (): void {
    // THE MONOTONICITY INVARIANT, and it is load-bearing rather than decorative: the pre-H8 rule excluded
    // exactly geo ∪ media ∪ {calculated}, so if no type H8 permits is in that set, then
    // `ocr_compatible_H8(V) ⇒ ocr_compatible_pre-H8(V)` for every version V. That is what licenses the
    // backfill to read only stored `true`s (a stored `false` is provably still correct) and to write a
    // constant `false` rather than a computed value. Widen the allowlist and this test is the alarm.
    $preH8Denylist = array_filter(
        FieldType::cases(),
        static fn (FieldType $t): bool => $t->isGeo() || $t->isMedia() || $t === FieldType::Calculated,
    );

    expect($preH8Denylist)->toHaveCount(9);

    foreach ($preH8Denylist as $type) {
        expect(OcrFieldEligibility::for($type))->toBe(
            OcrFieldEligibility::Excluded,
            "{$type->value} was ineligible before H8 and must not become eligible",
        );
    }
});

it('excludes both grid types while keeping a plain likert scale extractable', function (): void {
    // The charter's headline (matrix + likert_matrix), plus the line FieldType::isComposite() already
    // draws: `likert_scale` is one circled row of options, not a grid, so excluding the whole Likert
    // category would have been a bug.
    foreach (FieldType::cases() as $type) {
        if ($type->isComposite()) {
            expect(OcrFieldEligibility::for($type))->toBe(OcrFieldEligibility::Excluded);
        }
    }

    expect(OcrFieldEligibility::for(FieldType::LikertScale))->toBe(OcrFieldEligibility::Extractable)
        ->and(FieldType::LikertScale->isComposite())->toBeFalse();
});

it('treats the answer-free structural types as neutral rather than extractable', function (): void {
    // Neutral, not Extractable, is what makes the non-vacuity clause meaningful: a form of nothing but
    // notes and page breaks has nothing to scan, so it must not count as having an extractable field.
    foreach ([FieldType::Note, FieldType::PageBreak, FieldType::Hidden] as $type) {
        expect(OcrFieldEligibility::for($type))->toBe(OcrFieldEligibility::Neutral);
    }
});
