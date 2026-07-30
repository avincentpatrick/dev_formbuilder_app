<?php

declare(strict_types=1);

use App\Services\Forms\CapabilityFlags;

// The version-level §2 rule over hand-built canonical snapshots. Pure — no database, no container: since
// H8 the primitive takes the frozen snapshot rather than live Eloquent rows, which is what makes the whole
// matrix unit-testable. The publish-path wiring (that step 8 persists THIS function's output) is asserted
// in tests/Feature/Forms/FormLifecycleTest.php.

/**
 * A canonical snapshot fragment. Only the two keys the rule reads are populated; the serializer emits many
 * more per entry and the rule must not care.
 *
 * @param  list<string>  $fieldTypes
 * @param  list<bool>  $sectionRepeatable
 * @return array<string, mixed>
 */
function ocrSnapshot(array $fieldTypes, array $sectionRepeatable = []): array
{
    return [
        'fields' => array_map(
            static fn (string $type, int $i): array => ['key' => "f{$i}", 'field_type' => $type],
            $fieldTypes,
            array_keys($fieldTypes),
        ),
        'sections' => array_map(
            static fn (bool $repeatable, int $i): array => ['key' => "s{$i}", 'is_repeatable' => $repeatable],
            $sectionRepeatable,
            array_keys($sectionRepeatable),
        ),
    ];
}

function isOcrCompatibleSnapshot(array $fieldTypes, array $sectionRepeatable = []): bool
{
    return CapabilityFlags::forSnapshot(ocrSnapshot($fieldTypes, $sectionRepeatable))['ocr_compatible'];
}

it('marks an all-extractable version OCR compatible', function (): void {
    // The positive control. Without it the whole file would pass for a rule that always answers false.
    expect(isOcrCompatibleSnapshot(['short_text', 'integer', 'single_select', 'yes_no', 'date']))->toBeTrue();

    expect(CapabilityFlags::forSnapshot(ocrSnapshot(['short_text'])))->toBe([
        'has_geofields' => false,
        'has_media' => false,
        'ocr_compatible' => true,
    ]);
});

it('excludes a version containing either grid type', function (): void {
    // The charter's headline: both were `true` before H8, against §2's own words.
    expect(isOcrCompatibleSnapshot(['short_text', 'matrix']))->toBeFalse()
        ->and(isOcrCompatibleSnapshot(['short_text', 'likert_matrix']))->toBeFalse();
});

it('keeps a plain likert scale question OCR compatible', function (): void {
    // Guards against excluding the whole Likert category along with its two grid members.
    expect(isOcrCompatibleSnapshot(['likert_scale']))->toBeTrue();
});

it('excludes a repeatable section even when every field type is extractable', function (): void {
    expect(isOcrCompatibleSnapshot(['short_text', 'integer'], [true]))->toBeFalse();
});

it('excludes a repeatable section that holds no fields', function (): void {
    // §2's predicate is on the SECTION, not its contents. Pinned so nobody "optimizes" the rule into a
    // field->section join, which would be more code AND less conservative.
    expect(isOcrCompatibleSnapshot(['short_text'], [true]))->toBeFalse()
        ->and(isOcrCompatibleSnapshot([], [true]))->toBeFalse();
});

it('keeps a version with only non-repeatable sections OCR compatible', function (): void {
    // Guards against "any section disqualifies".
    expect(isOcrCompatibleSnapshot(['short_text'], [false, false]))->toBeTrue();
});

it('treats a section entry with no is_repeatable key as repeatable', function (): void {
    // Fail-safe: an unrecognized section shape must not read as "not a repeat group".
    $snapshot = ['fields' => [['field_type' => 'short_text']], 'sections' => [['key' => 's0']]];

    expect(CapabilityFlags::forSnapshot($snapshot)['ocr_compatible'])->toBeFalse();
});

it('excludes a version with nothing to extract', function (): void {
    // The non-vacuity clause. A form of nothing but notes, page breaks and hidden fields has no answer a
    // scan could carry, and neither does a fieldless one — both were `true` before H8.
    expect(isOcrCompatibleSnapshot([]))->toBeFalse()
        ->and(isOcrCompatibleSnapshot(['note', 'page_break', 'hidden']))->toBeFalse();
});

it('permits the answer-free structural types alongside an extractable field', function (): void {
    // The other half of the non-vacuity clause: neutral types must not BLOCK. A literal reading of §2's
    // original 12-item list would have failed this — one page break would have disqualified the form.
    expect(isOcrCompatibleSnapshot(['short_text', 'note', 'page_break', 'hidden']))->toBeTrue();
});

it('keeps email, phone, url and duration OCR compatible', function (): void {
    // The four §2 omissions that are plainly printable. Each alone must satisfy the rule, so a regression
    // here cannot hide behind a sibling extractable field.
    foreach (['email', 'phone', 'url', 'duration'] as $type) {
        expect(isOcrCompatibleSnapshot([$type]))->toBeTrue("{$type} must stay OCR compatible");
    }
});

it('treats an unknown field_type as not extractable rather than throwing', function (): void {
    // A snapshot written by a NEWER schema than the reading code must fail safe, not 500 — which is why
    // the primitive resolves with tryFrom() and never from().
    expect(isOcrCompatibleSnapshot(['short_text', 'quantum_entangled_select']))->toBeFalse()
        ->and(isOcrCompatibleSnapshot(['quantum_entangled_select']))->toBeFalse();
});

it('fails closed on a snapshot with no fields or sections list', function (): void {
    // A DRAFT version's schema_snapshot is literally `[]` — unevaluable, NOT "a form with no fields". If
    // the missing key read as an empty list, every flag would answer vacuously.
    expect(CapabilityFlags::forSnapshot([]))->toBe([
        'has_geofields' => false,
        'has_media' => false,
        'ocr_compatible' => false,
    ]);

    // Present but the wrong shape, and a list whose entries are not arrays.
    expect(CapabilityFlags::forSnapshot(['fields' => 'nope', 'sections' => []])['ocr_compatible'])->toBeFalse()
        ->and(CapabilityFlags::forSnapshot(['fields' => ['x'], 'sections' => []])['ocr_compatible'])->toBeFalse()
        ->and(CapabilityFlags::forSnapshot(['fields' => ['a' => []], 'sections' => []])['ocr_compatible'])->toBeFalse();
});

it('reports has_geofields and has_media exactly as the pre-H8 rule did', function (): void {
    // H8 deleted this class's duplicate GEO_TYPES/MEDIA_TYPES consts in favour of FieldType::isGeo()/
    // isMedia(), whose membership is byte-identical. This is the regression guard for that swap — it fails
    // if the two predicates are transposed or either loses a member.
    foreach (['geopoint', 'geotrace', 'geoshape'] as $type) {
        expect(CapabilityFlags::forSnapshot(ocrSnapshot(['short_text', $type])))->toBe([
            'has_geofields' => true,
            'has_media' => false,
            'ocr_compatible' => false,
        ], "{$type} must report as a geofield and never as media");
    }

    foreach (['file_upload', 'image_capture', 'audio_capture', 'video_capture', 'signature'] as $type) {
        expect(CapabilityFlags::forSnapshot(ocrSnapshot(['short_text', $type])))->toBe([
            'has_geofields' => false,
            'has_media' => true,
            'ocr_compatible' => false,
        ], "{$type} must report as media and never as a geofield");
    }

    // `calculated` is the pre-H8 rule's one non-geo/non-media exclusion, carried forward unchanged.
    expect(CapabilityFlags::forSnapshot(ocrSnapshot(['short_text', 'calculated'])))->toBe([
        'has_geofields' => false,
        'has_media' => false,
        'ocr_compatible' => false,
    ]);
});
