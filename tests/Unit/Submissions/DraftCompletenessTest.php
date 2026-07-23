<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Services\Submissions\DraftCompleteness;

/*
|--------------------------------------------------------------------------
| Increment H9a — the draft progress indicator. Pure + relevance-unaware: percent = answered / answerable
| over the Stage-1 normalized answers, where display-only / calculated / hidden fields are excluded and each
| repeatable section counts as one unit. No container / DB (unsaved models; casts apply — see Pest.php).
|--------------------------------------------------------------------------
*/

it('is 100 when every answerable field is answered', function (): void {
    $fields = collect([
        makeSchemaField(['key' => 'a', 'field_type' => FieldType::ShortText]),
        makeSchemaField(['key' => 'b', 'field_type' => FieldType::Integer]),
    ]);

    expect(DraftCompleteness::of($fields, collect(), ['a' => 'x', 'b' => '3']))->toBe(100);
});

it('is 50 when half the answerable fields are answered', function (): void {
    $fields = collect([
        makeSchemaField(['key' => 'a', 'field_type' => FieldType::ShortText]),
        makeSchemaField(['key' => 'b', 'field_type' => FieldType::ShortText]),
    ]);

    expect(DraftCompleteness::of($fields, collect(), ['a' => 'x']))->toBe(50);
});

it('rounds 1 of 3 to 33', function (): void {
    $fields = collect([
        makeSchemaField(['key' => 'a', 'field_type' => FieldType::ShortText]),
        makeSchemaField(['key' => 'b', 'field_type' => FieldType::ShortText]),
        makeSchemaField(['key' => 'c', 'field_type' => FieldType::ShortText]),
    ]);

    expect(DraftCompleteness::of($fields, collect(), ['b' => 'x']))->toBe(33);
});

it('excludes note, page break, calculated, and hidden fields from the denominator', function (): void {
    $fields = collect([
        makeSchemaField(['key' => 'a', 'field_type' => FieldType::ShortText]),
        makeSchemaField(['key' => 'intro', 'field_type' => FieldType::Note]),
        makeSchemaField(['key' => 'brk', 'field_type' => FieldType::PageBreak]),
        makeSchemaField(['key' => 'total', 'field_type' => FieldType::Calculated]),
        makeSchemaField(['key' => 'ref', 'field_type' => FieldType::Hidden]),
    ]);

    // Only `a` is answerable → answering it is 100%, even though the hidden `ref` also has a stored value.
    expect(DraftCompleteness::of($fields, collect(), ['a' => 'x', 'ref' => 'server-set']))->toBe(100)
        ->and(DraftCompleteness::of($fields, collect(), ['ref' => 'server-set']))->toBe(0);
});

it('counts a repeatable section as one unit, folding in its member fields', function (): void {
    $section = makeSchemaSection(['id' => 'sec1', 'key' => 'hh', 'is_repeatable' => true]);
    $fields = collect([
        makeSchemaField(['key' => 'a', 'field_type' => FieldType::ShortText]),
        makeSchemaField(['key' => 'm1', 'field_type' => FieldType::ShortText, 'form_section_id' => 'sec1']),
        makeSchemaField(['key' => 'm2', 'field_type' => FieldType::ShortText, 'form_section_id' => 'sec1']),
    ]);
    $sections = collect([$section]);

    // Denominator is 2 (the top-level `a` + the one section unit), NOT 3 — members do not count individually.
    expect(DraftCompleteness::of($fields, $sections, ['a' => 'x', 'hh' => [['m1' => 'v']]]))->toBe(100)
        ->and(DraftCompleteness::of($fields, $sections, ['a' => 'x']))->toBe(50)
        ->and(DraftCompleteness::of($fields, $sections, ['hh' => [['m1' => 'v']]]))->toBe(50);
});

it('returns 0 rather than dividing by zero when there are no answerable units', function (): void {
    $fields = collect([
        makeSchemaField(['key' => 'intro', 'field_type' => FieldType::Note]),
        makeSchemaField(['key' => 'brk', 'field_type' => FieldType::PageBreak]),
    ]);

    expect(DraftCompleteness::of($fields, collect(), []))->toBe(0);
});
