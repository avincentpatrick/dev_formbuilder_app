<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Services\Xlsform\XlsformTypeMap;

/*
|--------------------------------------------------------------------------
| Increment G7a — the FieldType ⇄ XLSForm type map (xlsform-interop-spec §3). Forward drives export, reverse
| drives import; the conservative import rules (§3) are pinned here.
|--------------------------------------------------------------------------
*/

function typeMap(): XlsformTypeMap
{
    return new XlsformTypeMap;
}

it('maps every FieldType forward', function (): void {
    foreach (FieldType::cases() as $type) {
        expect(typeMap()->toXlsform($type)->type)->not->toBe('');
    }
});

it('flags exactly the documented lossy types as export-only', function (): void {
    $exportOnly = array_filter(
        FieldType::cases(),
        fn (FieldType $t): bool => typeMap()->toXlsform($t)->exportOnly,
    );

    expect(array_map(fn (FieldType $t): string => $t->value, array_values($exportOnly)))
        ->toEqualCanonicalizing(['duration', 'likert_matrix', 'matrix', 'page_break']);
});

it('maps the representative forward tokens + appearances', function (): void {
    $map = typeMap();

    expect($map->toXlsform(FieldType::ShortText)->type)->toBe('text')
        ->and($map->toXlsform(FieldType::LongText)->appearance)->toBe('multiline')
        ->and($map->toXlsform(FieldType::Dropdown)->appearance)->toBe('minimal')
        ->and($map->toXlsform(FieldType::Signature))->toMatchObject(['type' => 'image', 'appearance' => 'signature'])
        ->and($map->toXlsform(FieldType::Datetime)->type)->toBe('dateTime')
        ->and($map->toXlsform(FieldType::Duration)->type)->toBe('decimal')
        ->and($map->toXlsform(FieldType::Geoshape)->type)->toBe('geoshape');
});

it('resolves reverse tokens with appearance disambiguation', function (): void {
    $map = typeMap();

    expect($map->toFieldType('text'))->toBe(FieldType::ShortText)
        ->and($map->toFieldType('text', 'multiline'))->toBe(FieldType::LongText)
        ->and($map->toFieldType('integer'))->toBe(FieldType::Integer)
        ->and($map->toFieldType('select_multiple things'))->toBe(FieldType::MultiSelect)
        ->and($map->toFieldType('select_one', 'minimal'))->toBe(FieldType::Dropdown)
        ->and($map->toFieldType('image', 'signature'))->toBe(FieldType::Signature)
        ->and($map->toFieldType('geopoint'))->toBe(FieldType::Geopoint);
});

it('never auto-infers yes_no from a two-option select_one (§3)', function (): void {
    // A select_one is always SingleSelect on import — a two-option list is not assumed to be yes/no.
    expect(typeMap()->toFieldType('select_one yn'))->toBe(FieldType::SingleSelect);
});

it('returns null for an unmapped type token', function (): void {
    expect(typeMap()->toFieldType('rank'))->toBeNull()
        ->and(typeMap()->toFieldType('barcode'))->toBeNull();
});

it('restores page_break from its own-export marker but not the composites', function (): void {
    $map = typeMap();

    expect($map->toFieldType('note', null, XlsformTypeMap::MARKER_PAGE_BREAK))->toBe(FieldType::PageBreak)
        // matrix/likert_matrix markers are NOT reconstructed — they resolve by their base token instead.
        ->and($map->toFieldType('select_one x', null, XlsformTypeMap::MARKER_MATRIX))->toBe(FieldType::SingleSelect);
});

it('reports the two grids as not importable', function (): void {
    $map = typeMap();

    expect($map->isImportable(FieldType::Matrix))->toBeFalse()
        ->and($map->isImportable(FieldType::LikertMatrix))->toBeFalse()
        ->and($map->isImportable(FieldType::SingleSelect))->toBeTrue()
        ->and($map->isImportable(FieldType::Geopoint))->toBeTrue();
});
