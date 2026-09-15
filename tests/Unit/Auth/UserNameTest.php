<?php

declare(strict_types=1);

use App\Support\Auth\UserName;

/*
|--------------------------------------------------------------------------
| The `users.name` contract (M96): one limit, a rule that refuses by it, and a fit that cuts to it.
|--------------------------------------------------------------------------
| Pure: no container and no database. The limit's agreement with the schema is asserted in
| `FortifyErrorBagTest`, which has a database; this file pins what `fit()` counts.
|
| ⚠️ THE CJK AND EMOJI CASES ARE THE ONES THAT MATTER. An ASCII name cannot tell a code-point cut from a
| display-width cut (`Str::limit()`), because every ASCII character is one column wide. Both ends of the
| write count code points — Laravel's `max` rule measures `mb_strlen`, and PostgreSQL's `varchar(150)` holds
| 150 characters of a UTF-8 database — so a width cut halves a wide name that already fits.
*/

/** One character from its code point, so no editor or file encoding can change what a case sends. */
function userNameChar(int $codePoint): string
{
    return mb_chr($codePoint, 'UTF-8');
}

it('cuts a name one character past the column down to the column', function (): void {
    expect(UserName::fit(str_repeat('a', 151)))->toBe(str_repeat('a', 150));
});

it('leaves a name that fits exactly as it is', function (): void {
    expect(UserName::fit(str_repeat('a', 150)))->toBe(str_repeat('a', 150));
});

it('keeps 100 CJK characters whole, which a width-counting cut would halve to 75', function (): void {
    $name = str_repeat(userNameChar(0x4E2D), 100);

    expect(UserName::fit($name))->toBe($name);
});

it('counts a four-byte emoji as one character, so 150 fit and 151 are cut to 150', function (): void {
    $emoji = userNameChar(0x1F600);

    expect(UserName::fit(str_repeat($emoji, 150)))->toBe(str_repeat($emoji, 150))
        ->and(UserName::fit(str_repeat($emoji, 151)))->toBe(str_repeat($emoji, 150));
});

it('trims before it cuts, and again after, so no name ends in a space the cut exposed', function (): void {
    expect(UserName::fit('  x  '))->toBe('x')
        // 149 letters, two spaces, then a letter: 152 characters, and the 150th is a space.
        ->and(UserName::fit(str_repeat('a', 149).'  b'))->toBe(str_repeat('a', 149));
});

it('refuses by the same number it fits to', function (): void {
    expect(UserName::MAX)->toBe(150)
        ->and(UserName::rules())->toBe(['required', 'string', 'max:150']);
});
