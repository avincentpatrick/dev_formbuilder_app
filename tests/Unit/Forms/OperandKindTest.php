<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\OperandKind;

/*
|--------------------------------------------------------------------------
| M134 — OperandKind, the one server source for what an expression may compare a question with.
|--------------------------------------------------------------------------
| `numberNeverHeld()` must reproduce M126's `ExpressionValidationGate::numberNeverHeldBy()` exactly — that method
| moved here and the gate's refusals depend on it — so its table is TRANSCRIBED below, type by type, rather than
| derived from the enum it checks.
*/

it('reproduces M126\'s never-a-number classification for every field type', function (FieldType $type): void {
    $m126 = match ($type) {
        FieldType::Date, FieldType::Time, FieldType::Datetime => 'date',
        FieldType::Note, FieldType::PageBreak, FieldType::YesNo,
        FieldType::MultiSelect, FieldType::CascadingSelect,
        FieldType::FileUpload, FieldType::ImageCapture, FieldType::AudioCapture,
        FieldType::VideoCapture, FieldType::Signature => 'non_number',
        FieldType::ShortText, FieldType::LongText, FieldType::Email, FieldType::Phone, FieldType::Url,
        FieldType::Hidden, FieldType::Integer, FieldType::Decimal, FieldType::Calculated, FieldType::Duration,
        FieldType::SingleSelect, FieldType::Dropdown, FieldType::LikertScale,
        FieldType::Geopoint, FieldType::Geotrace, FieldType::Geoshape,
        FieldType::Matrix, FieldType::LikertMatrix => null,
    };

    expect(OperandKind::for($type)->numberNeverHeld())->toBe($m126);
})->with(FieldType::cases());

it('puts every field type in the kind an expression reads it as', function (): void {
    $byKind = [];

    foreach (FieldType::cases() as $type) {
        $byKind[OperandKind::for($type)->value][] = $type->value;
    }

    expect($byKind)->toEqualCanonicalizing([
        'number' => ['integer', 'decimal', 'duration', 'likert_scale'],
        'computed' => ['calculated'],
        'date' => ['date'],
        'time' => ['time'],
        'datetime' => ['datetime'],
        'value' => ['short_text', 'long_text', 'email', 'phone', 'url', 'hidden', 'single_select', 'dropdown'],
        'boolean' => ['yes_no'],
        'list' => ['multi_select', 'cascading_select'],
        'attachment' => ['file_upload', 'image_capture', 'audio_capture', 'video_capture', 'signature'],
        'object' => ['matrix', 'likert_matrix', 'geopoint', 'geotrace', 'geoshape'],
        'none' => ['note', 'page_break'],
    ]);
});

it('orders a date with a date or a date-time, and a time only with a time', function (): void {
    $pairs = [];

    foreach (OperandKind::cases() as $a) {
        foreach (OperandKind::cases() as $b) {
            if ($a->ordersTemporallyWith($b)) {
                $pairs[] = "{$a->value}~{$b->value}";
            }
        }
    }

    expect($pairs)->toEqualCanonicalizing(['date~date', 'date~datetime', 'datetime~date', 'datetime~datetime', 'time~time']);
});
