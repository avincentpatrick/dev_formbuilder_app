<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\OcrFieldEligibility;
use App\Enums\PdfFieldRole;
use App\Enums\PrintAnswerArea;

// I12's printed-blank-form field classification, locked type by type. The fourth member of the
// OcrFieldEligibilityTest / PipingEligibilityTest / PdfFieldRoleTest family, with the same totality
// lock — plus the assertions that matter most here: this enum DISAGREES with both of its nearest
// siblings, on purpose, and a later refactor must not be able to fold them together.
//
// No database, no container (tests/Pest.php binds TestCase to Feature only).

/**
 * The verdict table, transcribed independently of the enum — the point is that the two are written
 * separately and must agree.
 *
 * @return array<string, list<string>>
 */
function printVerdictTable(): array
{
    return [
        // Bounded runs of characters a pen can print into separated boxes (10).
        'comb' => [
            'short_text', 'email', 'phone', 'url',
            'integer', 'decimal',
            'date', 'time', 'datetime', 'duration',
        ],
        // Free prose: nothing to comb (1).
        'ruled' => ['long_text'],
        // Author-defined option lists, each with a box to tick (6).
        'choices' => [
            'single_select', 'multi_select', 'dropdown', 'yes_no', 'cascading_select', 'likert_scale',
        ],
        // Grids. OCR-excluded and printed anyway — paper has handled grids for centuries (2).
        'grid' => ['matrix', 'likert_matrix'],
        // The one media type whose artefact IS the ink (1).
        'signature_line' => ['signature'],
        // Printed, answered by nobody (1).
        'prose' => ['note'],
        // Real questions a pen cannot answer: named, marked, given no box (7).
        'unavailable' => [
            'geopoint', 'geotrace', 'geoshape',
            'file_upload', 'image_capture', 'audio_capture', 'video_capture',
        ],
        // Pagination, which paper takes literally (1).
        'page_break' => ['page_break'],
        // Server-supplied; a pen never can (2).
        'omitted' => ['hidden', 'calculated'],
    ];
}

/** @return list<string> the backing values of every case with the given area, in catalog order */
function printTypesWithArea(PrintAnswerArea $area): array
{
    return array_values(array_map(
        static fn (FieldType $t): string => $t->value,
        array_filter(FieldType::cases(), static fn (FieldType $t): bool => PrintAnswerArea::for($t) === $area),
    ));
}

it('classifies every one of the 31 field types, and the partition is exactly the table', function (): void {
    // An EQUALITY over the whole catalog, not a spot check: a 32nd field type cannot satisfy this
    // file without somebody deciding what a pen does with it and writing that decision down twice.
    $table = printVerdictTable();

    expect(array_sum(array_map('count', $table)))->toBe(count(FieldType::cases()))
        ->and(count(FieldType::cases()))->toBe(31);

    foreach ($table as $area => $expected) {
        expect(printTypesWithArea(PrintAnswerArea::from($area)))
            ->toEqualCanonicalizing($expected, "the {$area} set drifted from the table");
    }
});

it('leaves no field type unclassified and no area empty', function (): void {
    // The `match` has no `default` arm, so an unclassified type is an UnhandledMatchError rather
    // than a wrong answer — this asserts the other direction, that every declared case is REACHED.
    // An area no type maps to is a case somebody added and never wired, which is dead layout code.
    foreach (PrintAnswerArea::cases() as $area) {
        expect(printTypesWithArea($area))->not->toBeEmpty("PrintAnswerArea::{$area->name} classifies no field type");
    }

    foreach (FieldType::cases() as $type) {
        expect(PrintAnswerArea::for($type))->toBeInstanceOf(PrintAnswerArea::class);
    }
});

it('disagrees with OcrFieldEligibility on grids, signature and page breaks — and MUST', function (): void {
    // ⚠️ THE ASSERTION THIS FILE EXISTS FOR. Both enums are total, `default`-less matches over the
    // same 31 cases, which makes "collapse them into one rule" a permanently tempting refactor. It
    // would be wrong: OcrFieldEligibility answers "can an extraction stage lift this off a scan?"
    // and this answers "is this a form a field team can carry?". A printed blank form is the
    // INSTRUMENT, not the extraction target.
    //
    // Decided with the user 2026-08-09 and recorded in docs/ocr-pipeline-design.md §2.5.
    foreach ([FieldType::Matrix, FieldType::LikertMatrix] as $grid) {
        expect(OcrFieldEligibility::for($grid))->toBe(OcrFieldEligibility::Excluded)
            ->and(PrintAnswerArea::for($grid))->toBe(PrintAnswerArea::Grid);
    }

    expect(OcrFieldEligibility::for(FieldType::Signature))->toBe(OcrFieldEligibility::Excluded)
        ->and(PrintAnswerArea::for(FieldType::Signature))->toBe(PrintAnswerArea::SignatureLine);

    expect(OcrFieldEligibility::for(FieldType::PageBreak))->toBe(OcrFieldEligibility::Neutral)
        ->and(PrintAnswerArea::for(FieldType::PageBreak))->toBe(PrintAnswerArea::PageBreak);
});

it('disagrees with PdfFieldRole on geo and media — and MUST', function (): void {
    // The other tempting reuse, and the more dangerous one. PdfFieldRole calls every geo envelope
    // and every media type `Answer`, correctly: a submission PDF records what the respondent SAW,
    // and they demonstrably saw those controls on a screen. Reusing it here would print a writable
    // box under "Photo of household" — inviting somebody to write into an area no scan will ever
    // produce an attachments row from.
    $penCannotSupply = [
        FieldType::Geopoint, FieldType::Geotrace, FieldType::Geoshape,
        FieldType::FileUpload, FieldType::ImageCapture, FieldType::AudioCapture, FieldType::VideoCapture,
    ];

    foreach ($penCannotSupply as $type) {
        expect(PdfFieldRole::for($type))->toBe(PdfFieldRole::Answer)
            ->and(PrintAnswerArea::for($type))->toBe(PrintAnswerArea::Unavailable);
    }

    // And the one place they diverge in the OTHER direction: PdfFieldRole omits `page_break` because
    // a respondent never "sees" one; paper takes it literally.
    expect(PdfFieldRole::for(FieldType::PageBreak))->toBe(PdfFieldRole::Omitted)
        ->and(PrintAnswerArea::for(FieldType::PageBreak))->toBe(PrintAnswerArea::PageBreak);
});

it('puts ink on the page for every area except Omitted', function (): void {
    // `isPrinted()` is what the presenter filters on, so a wrong answer here silently drops a whole
    // class of question off the paper.
    foreach (PrintAnswerArea::cases() as $area) {
        expect($area->isPrinted())->toBe($area !== PrintAnswerArea::Omitted, "isPrinted() is wrong for {$area->name}");
    }
});
