<?php

declare(strict_types=1);

use App\Support\Submissions\SubmissionReference;
use Tests\TestCase;

/**
 * Increment J2e — the short submission handle.
 *
 * ⚠️ THE ALPHABET IS ASSERTED STRUCTURALLY, NOT BY SAMPLING, AND THE DIFFERENCE IS THE POINT.
 * "Mint a thousand codes and check no I appears" reddens only PROBABILISTICALLY — an alphabet that gained an
 * `I` would pass that case roughly (31/32)^8000 of the time on a good day and fail it on a bad one, which is
 * the worst possible property for a gate. The constant's shape (32 characters, no duplicates, none of I/L/O/U)
 * is a deterministic fact about the source, so it is asserted as one. The sampling cases below exist for a
 * different job: proving `mint()` actually draws from it and is not constant.
 *
 * No database and no container — {@see SubmissionReference} touches neither, and `tests/Pest.php` binds
 * `TestCase` only to `Feature`.
 */
uses(TestCase::class);

it('has a 32-character Crockford alphabet with no duplicates and none of I, L, O or U', function (): void {
    $alphabet = SubmissionReference::ALPHABET;

    expect(strlen($alphabet))->toBe(32)
        ->and(count(array_unique(str_split($alphabet))))->toBe(32)
        ->and($alphabet)->not->toContain('I')
        ->and($alphabet)->not->toContain('L')
        ->and($alphabet)->not->toContain('O')
        ->and($alphabet)->not->toContain('U');
});

it('mints eight characters drawn only from the alphabet', function (): void {
    for ($i = 0; $i < 500; $i++) {
        $code = SubmissionReference::mint();

        expect(strlen($code))->toBe(SubmissionReference::LENGTH)
            ->and(strspn($code, SubmissionReference::ALPHABET))->toBe(SubmissionReference::LENGTH);
    }
});

it('does not mint a constant', function (): void {
    // Anti-vacuity: a `mint()` returning 'AAAAAAAA' would satisfy every other case in this file.
    $seen = [];
    for ($i = 0; $i < 500; $i++) {
        $seen[SubmissionReference::mint()] = true;
    }

    expect(count($seen))->toBeGreaterThan(400);
});

it('formats the stored value into two groups of four', function (): void {
    expect(SubmissionReference::format('7K4M2QXB'))->toBe('7K4M-2QXB');
});

it('round-trips the displayed form back to the stored form', function (): void {
    $stored = SubmissionReference::mint();

    expect(SubmissionReference::normalize(SubmissionReference::format($stored)))->toBe($stored);
});

it('accepts the displayed form, lowercase, spaced and non-breaking-spaced', function (string $input): void {
    expect(SubmissionReference::normalize($input))->toBe('7K4M2QXB');
})->with([
    'stored form' => '7K4M2QXB',
    'displayed form' => '7K4M-2QXB',
    'lowercase' => '7k4m-2qxb',
    'ascii spaces' => '7K4M 2QXB',
    // A code pasted out of a PDF carries U+00A0 rather than a plain space.
    'non-breaking space' => "7K4M\u{00A0}2QXB",
    'surrounding whitespace' => '  7K4M-2QXB  ',
]);

it('applies Crockford decode leniency for I, L and O on input', function (string $input, string $expected): void {
    expect(SubmissionReference::normalize($input))->toBe($expected);
})->with([
    'I reads as 1' => ['7K4MIQXB', '7K4M1QXB'],
    'lowercase l reads as 1' => ['7K4MlQXB', '7K4M1QXB'],
    'O reads as 0' => ['7K4MOQXB', '7K4M0QXB'],
]);

it('refuses a candidate containing U', function (): void {
    // ⚠️ A FAIL-CLOSED GUARD, NOT A LIVE RULE. Crockford excludes U to avoid accidental obscenity rather than
    // for visual confusion, so — unlike I, L and O — there is no digit it could mean. In production a code
    // containing U cannot exist, so this case is the ONLY place the arm is observable. Its mutation (mapping
    // U to something, or admitting it) reddens here and nowhere else.
    expect(SubmissionReference::normalize('7K4MUQXB'))->toBeNull();
});

it('refuses anything that is not exactly eight alphabet characters', function (?string $input): void {
    expect(SubmissionReference::normalize($input))->toBeNull();
})->with([
    'null' => null,
    'empty' => '',
    'seven characters' => '7K4M2QX',
    'nine characters' => '7K4M2QXBC',
    // A de-hyphenated canonical uuid is 32 characters — never 8 — which is what makes the reference and the
    // submission-id parsers provably disjoint despite hex being a strict SUBSET of Crockford Base32.
    'a de-hyphenated uuid' => '019fec5691cb7d5e8f901a2b3c4d5e6f',
    'punctuation' => '7K4M_2QX',
    'multibyte' => 'アイウエオカキク',
    'mixed multibyte and ascii' => '7K4M2QX日',
]);

it('treats isValid as exactly the identity of normalize', function (): void {
    expect(SubmissionReference::isValid('7K4M2QXB'))->toBeTrue()
        // The displayed form is NOT valid as a stored value — that asymmetry is what keeps the column
        // canonical and stops a hyphen reaching an equality lookup.
        ->and(SubmissionReference::isValid('7K4M-2QXB'))->toBeFalse()
        ->and(SubmissionReference::isValid('7k4m2qxb'))->toBeFalse()
        ->and(SubmissionReference::isValid('7K4M2QX'))->toBeFalse();
});
