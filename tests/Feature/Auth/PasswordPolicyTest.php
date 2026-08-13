<?php

declare(strict_types=1);

use App\Support\Auth\PasswordPolicy;
use Illuminate\Validation\Rules\Password;

/*
|--------------------------------------------------------------------------
| The published password policy (Increment J3a).
|
| ⚠️ THIS FILE EXISTS BECAUSE THE CHECKLIST AND THE VALIDATOR ARE TWO DIFFERENT ENGINES, IN TWO DIFFERENT
| LANGUAGES, ON TWO DIFFERENT MACHINES. `PasswordPolicy::requirements()` publishes a regular expression the
| BROWSER evaluates; `Password::defaults()` installs a rule the SERVER evaluates. Nothing about writing them
| in one file makes them agree — only an assertion that runs both against the same strings does.
|
| The failure this prevents is silent and lands on the person choosing the password: a checklist showing four
| green ticks over a form that then rejects them. There is no gate anywhere else in the repo that can see it —
| the Vue test can only prove the component renders what it is handed, and the Pest feature tests can only
| prove the server rejects what it should. This is the seam.
|
| ⚠️ A FEATURE TEST DESPITE TESTING A PURE CLASS, AND THE REASON IS WORTH KNOWING. `PasswordPolicy` itself
| needs nothing; the SERVER HALF of every assertion below does. `validator()` resolves
| `Illuminate\Contracts\Validation\Factory` out of the container, and `pest()->extend(TestCase::class)` is
| applied `->in('Feature')` only — so in `tests/Unit` this file dies with "Target [...\Validation\Factory] is
| not instantiable" rather than failing an assertion. The first draft was written in Unit, with a comment
| explaining why it belonged there. It did not.
|
| `Password::min(...)` is still constructed explicitly below rather than read from `Password::defaults()`:
| the point is to compare the published pattern against the framework rule it claims to mirror, one rule at a
| time. Reading the composed default would test all five at once and could not tell you WHICH one disagreed.
*/

/**
 * The server-side rule for a single requirement key, WITHOUT `uncompromised()` — no unit test may reach the
 * network. Mirrors `FortifyServiceProvider::boot()` one requirement at a time.
 */
function serverRuleFor(string $key): Password
{
    return match ($key) {
        'min_length' => Password::min(PasswordPolicy::MIN_LENGTH),
        'mixed_case' => Password::min(1)->mixedCase(),
        'numbers' => Password::min(1)->numbers(),
        'symbols' => Password::min(1)->symbols(),
        default => throw new InvalidArgumentException("no server rule mapped for [{$key}]"),
    };
}

/** Does the published client-side pattern accept `$candidate`? */
function clientPatternAccepts(string $pattern, string $candidate): bool
{
    // `u` matches the browser's flag, which is what makes `\p{Ll}` and friends mean the same thing on both
    // sides. Delimiters are `~` so a pattern containing `/` needs no escaping.
    return preg_match('~'.$pattern.'~u', $candidate) === 1;
}

it('publishes a requirement for every rule the server actually enforces', function (): void {
    $keys = array_column(PasswordPolicy::requirements(), 'key');

    // Exact list, exact order — the order is what the checklist renders, so a reorder is a visible product
    // change and should have to be written down here.
    expect($keys)->toBe(['min_length', 'mixed_case', 'numbers', 'symbols', 'uncompromised']);
});

it('gives every requirement a non-empty label', function (): void {
    foreach (PasswordPolicy::requirements() as $requirement) {
        expect($requirement['label'])->toBeString()->not->toBe('');
    }
});

it('names the minimum length in the label it renders, so the number cannot be typed twice', function (): void {
    $minLength = collect(PasswordPolicy::requirements())->firstWhere('key', 'min_length');

    expect($minLength['label'])->toContain((string) PasswordPolicy::MIN_LENGTH);
});

it('leaves uncompromised unevaluatable in the browser, on purpose', function (): void {
    $breach = collect(PasswordPolicy::requirements())->firstWhere('key', 'uncompromised');

    // ⚠️ IF THIS EVER FAILS, SOMEBODY GAVE THE BREACH ROW A PATTERN TO MAKE IT "WORK". Do not fix the test.
    // A local pattern cannot decide this — the check is a k-anonymity range query against a third party, and
    // running it in the browser would leak the SHA-1 prefix of a password being typed, per keystroke, from
    // the user's own IP. The row is a stated condition, not a tickable one.
    expect($breach['pattern'])->toBeNull();
});

/**
 * ⚠️ THE ASSERTION THAT ACTUALLY MATTERS. For each requirement, a string that FAILS it must fail on both
 * sides, and a string that SATISFIES it must pass on both. The fixtures are chosen so that each pair differs
 * ONLY in the property under test — otherwise a pattern could agree with the validator by accident.
 */
dataset('policy agreement fixtures', [
    // key                     satisfies       violates
    'min_length' => ['min_length', 'abcdefghijkl', 'abcdefghijk'],
    'mixed_case' => ['mixed_case', 'aB', 'ab'],
    'numbers' => ['numbers', 'a1', 'ab'],
    'symbols' => ['symbols', 'a!', 'ab'],

    // ⚠️ THE NON-ASCII HALF IS WHAT MAKES THIS TEST ABLE TO FAIL AT ALL, AND THE FIRST DRAFT DID NOT HAVE IT.
    // Every ASCII fixture above is satisfied by an ASCII approximation such as `[0-9]` or `[!@#$%^&*]` — i.e.
    // by exactly the drift `PasswordPolicy`'s docblock claims to prevent. Laravel matches `\pN` for numbers,
    // `\p{Lu}`/`\p{Ll}` for case and `\p{Z}|\p{S}|\p{P}` for symbols, so a password made of Arabic-Indic
    // digits, accented capitals or a euro sign is accepted by the SERVER; a client pattern that narrowed to
    // ASCII would tick nothing while the form submitted fine. Mutation-verified: replacing the published
    // `numbers` pattern with `[0-9]` reddens the row below and none of the four above.
    'numbers (non-ASCII)' => ['numbers', "a\u{0663}", 'ab'],
    'mixed_case (non-ASCII)' => ['mixed_case', "a\u{00C9}", 'ab'],
    'symbols (non-ASCII)' => ['symbols', "a\u{20AC}", 'ab'],
]);

it('agrees with the server on both sides of every rule', function (string $key, string $satisfies, string $violates): void {
    $pattern = collect(PasswordPolicy::requirements())->firstWhere('key', $key)['pattern'];
    expect($pattern)->not->toBeNull("[{$key}] must publish a pattern");

    $rule = ['password' => serverRuleFor($key)];

    expect(validator(['password' => $satisfies], $rule)->fails())
        ->toBeFalse("server rejected [{$satisfies}], which the fixture says satisfies [{$key}]");
    expect(clientPatternAccepts($pattern, $satisfies))
        ->toBeTrue("client pattern rejected [{$satisfies}], which the server accepts for [{$key}]");

    expect(validator(['password' => $violates], $rule)->fails())
        ->toBeTrue("server accepted [{$violates}], which the fixture says violates [{$key}]");
    expect(clientPatternAccepts($pattern, $violates))
        ->toBeFalse("client pattern accepted [{$violates}], which the server rejects for [{$key}]");
})->with('policy agreement fixtures');

it('publishes patterns that are anchor-free, so the checklist can test a substring of a longer password', function (): void {
    foreach (PasswordPolicy::requirements() as $requirement) {
        if ($requirement['pattern'] === null) {
            continue;
        }

        // A leading `^` or trailing `$` would make every rule a whole-string match, so "contains a number"
        // would become "is a number". The lookahead form used by `mixed_case` is deliberately allowed.
        expect($requirement['pattern'])->not->toStartWith('^');
        expect($requirement['pattern'])->not->toEndWith('$');
    }
});

it('publishes patterns the browser can actually compile', function (): void {
    foreach (PasswordPolicy::requirements() as $requirement) {
        if ($requirement['pattern'] === null) {
            continue;
        }

        // PCRE with `u` is not JavaScript's engine, but every construct used here (`\p{...}`, `{n,}`,
        // alternation, lookahead) means the same thing in both. `password-policy.test.ts` is the other half
        // of this claim and compiles the same strings with `new RegExp(..., 'u')`.
        expect(@preg_match('~'.$requirement['pattern'].'~u', 'probe'))->not->toBeFalse(
            "[{$requirement['key']}] does not compile as a regular expression",
        );
    }
});
