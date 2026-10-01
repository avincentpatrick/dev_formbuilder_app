<?php

declare(strict_types=1);

use App\Services\Expressions\Coercion;
use App\Services\Expressions\Marker;

/*
|--------------------------------------------------------------------------
| Increment M124 (R-9f296f7e) — the two yes/no tables, pinned value by value.
|--------------------------------------------------------------------------
| `yesNoLiteral()` is STRICT: it decides what the other side of a comparison with a yes/no answer means, and
| anything an author did not write as yes or no means neither. `yesNoAnswer()` is PERMISSIVE: it is the table
| Stage 2 has always applied to an answer a client sent, moved verbatim so Stage 3 can apply it to the browser's
| string. The golden vectors pin both through the engines; these cases pin the edges the vectors cannot spell
| cheaply — PHP's own trim set, which the TypeScript twin must copy rather than borrow from JavaScript.
|
| Control characters are built with chr(), never with an escape sequence.
*/

dataset('strict yes/no literals', function (): iterable {
    $tab = chr(9);
    $nul = chr(0);
    $verticalTab = chr(11);
    $nbsp = chr(0xC2).chr(0xA0);

    yield 'yes' => ['yes', true];
    yield 'Yes' => ['Yes', true];
    yield 'padded YES' => [' YES ', true];
    yield 'true' => ['true', true];
    yield 'TRUE' => ['TRUE', true];
    yield 'string 1' => ['1', true];
    yield 'int 1' => [1, true];
    yield 'float 1' => [1.0, true];
    yield 'no' => ['no', false];
    yield 'No' => ['No', false];
    yield 'false' => ['false', false];
    yield 'string 0' => ['0', false];
    yield 'int 0' => [0, false];
    yield 'float 0' => [0.0, false];
    yield 'negative zero' => [-0.0, false];
    yield 'maybe' => ['maybe', null];
    yield 'y' => ['y', null];
    yield 'n' => ['n', null];
    yield 'empty string' => ['', null];
    yield 'a space' => [' ', null];
    yield 'string 1.0' => ['1.0', null];
    yield 'string 01' => ['01', null];
    yield 'int 2' => [2, null];
    yield 'NaN' => [NAN, null];
    yield 'a boolean true' => [true, null];
    yield 'a boolean false' => [false, null];
    yield 'null' => [null, null];
    yield 'absent' => [Marker::Absent, null];
    yield 'a list' => [['yes'], null];
    yield 'a tab is trimmed' => [$tab.'yes', true];
    yield 'NUL is trimmed' => [$nul.'no'.$nul, false];
    yield 'a vertical tab is trimmed' => [$verticalTab.'yes', true];
    yield 'NBSP is not trimmed' => [$nbsp.'yes', null];
});

it('reads the other side of a yes/no comparison strictly', function (mixed $value, ?bool $expected): void {
    expect(Coercion::yesNoLiteral($value))->toBe($expected);
})->with('strict yes/no literals');

dataset('permissive yes/no answers', function (): iterable {
    $nul = chr(0);
    $nbsp = chr(0xC2).chr(0xA0);

    yield 'yes' => ['yes', true];
    yield 'no' => ['no', false];
    yield 'padded NO' => [' NO ', false];
    yield 'false' => ['false', false];
    yield 'string 0' => ['0', false];
    yield 'n reads as yes' => ['n', true];
    yield 'off reads as yes' => ['off', true];
    yield 'maybe reads as yes' => ['maybe', true];
    yield 'NUL-led no is trimmed' => [$nul.'no', false];
    yield 'NBSP-led no is not' => [$nbsp.'no', true];
    yield 'a boolean true' => [true, true];
    yield 'a boolean false' => [false, false];
    yield 'int 1' => [1, true];
    yield 'int 0' => [0, false];
    yield 'null' => [null, false];
});

it('reads a yes/no answer by the table Stage 2 has always applied', function (mixed $value, bool $expected): void {
    expect(Coercion::yesNoAnswer($value))->toBe($expected);
})->with('permissive yes/no answers');

it('is not a superset of toBool, in either direction', function (): void {
    // The normalizer's old docblock said otherwise. '0.0' is numeric, so toBool reads it as zero — and the
    // yes/no table, which knows only four false spellings, reads it as yes. ' 0 ' is not numeric (the
    // pattern admits no whitespace), so toBool sees a non-empty string — and the yes/no table trims it first.
    expect(Coercion::toBool('0.0'))->toBeFalse()
        ->and(Coercion::yesNoAnswer('0.0'))->toBeTrue()
        ->and(Coercion::toBool(' 0 '))->toBeTrue()
        ->and(Coercion::yesNoAnswer(' 0 '))->toBeFalse();
});
