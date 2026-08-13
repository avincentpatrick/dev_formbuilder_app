<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Actions\Fortify\PasswordValidationRules;
use App\Providers\FortifyServiceProvider;
use App\Services\Settings\RegistrationGate;
use App\Support\Notifications\NotificationCopy;
use Illuminate\Validation\Rules\Password;

/**
 * The password policy, stated once (Increment J3a — the user's 2026-08-09 decision of record: a 12-character
 * minimum, the four character classes, a breached-password check, and a LIVE strength checklist).
 *
 * Pure — no container, no config, no database — so it unit-tests against a string, the {@see NotificationCopy}
 * posture.
 *
 * ── WHY THIS EXISTS AT ALL, WHEN `Password::defaults()` ALREADY HOLDS THE RULES ─────────────────────────
 * Because a live checklist has to run in the BROWSER, and the validator runs on the server. Without a
 * shared declaration the product would have two: `FortifyServiceProvider`'s builder chain, and a hand-typed
 * TypeScript list beside it. Those two agree until somebody edits one — and the failure is silent in the
 * worst direction, because the checklist is what a person is reading while they choose. A checklist showing
 * four green ticks over a form that then rejects the password is a bug that makes the product look broken
 * and the user feel stupid.
 *
 * This is the same argument I5 already made for `canRegister`: the login page's link and the reachability of
 * the route it points at are one answer from {@see RegistrationGate}, deliberately,
 * "so the link and the route it points at cannot disagree". A password rule and the description of that
 * password rule are the same shape of problem.
 *
 * ── THE PATTERNS SHIP TO THE CLIENT, AND THAT IS THE POINT ──────────────────────────────────────────────
 * Each requirement carries a JavaScript-compatible regular expression SOURCE, evaluated in the browser with
 * the `u` flag. Two consequences worth stating rather than discovering:
 *
 *   (a) A requirement added here appears in the checklist with no TypeScript edit at all. The alternative —
 *       shipping `{key, label}` and keeping a `key => test` map on the client — means a new requirement
 *       renders as a row the checklist cannot evaluate, which is a worse failure than not shipping it.
 *   (b) These strings are AUTHORED HERE, never derived from tenant data or user input, so `new RegExp()` on
 *       them is not an injection surface. `PasswordPolicyTest` pins that every pattern is anchor-free,
 *       bounded, and agrees with what `Password::default()` actually enforces.
 *
 * ── `uncompromised` IS IN THE LIST AND IS DELIBERATELY NOT LIVE ─────────────────────────────────────────
 * It is a rule, so hiding it would make the checklist lie by omission — a person who satisfies four green
 * ticks and is then rejected has been told the wrong thing. But it cannot be evaluated locally: the check is
 * a k-anonymity range query against api.pwnedpasswords.com, and a browser making it would leak the first
 * five characters of the SHA-1 of a password being typed, per keystroke, to a third party from the user's own
 * IP. It therefore carries a NULL pattern, and the component renders it as a stated condition rather than a
 * tickable one. **Never give it a pattern to make the row "work".**
 *
 * @see FortifyServiceProvider::boot() — the one place these rules are installed as `Password::defaults()`
 * @see PasswordValidationRules — the trait every password surface validates through
 */
final class PasswordPolicy
{
    /**
     * The minimum length, in characters.
     *
     * Consumed by {@see FortifyServiceProvider::boot()} as `Password::min()` and rendered by the checklist,
     * so the number a person reads and the number that rejects them are one number.
     */
    public const int MIN_LENGTH = 12;

    /**
     * The policy as the client renders it, in the order it is shown.
     *
     * `pattern` is a JavaScript regular-expression source evaluated with the `u` flag, or NULL for a rule
     * that can only be decided by the server (see the class docblock on `uncompromised`).
     *
     * Each pattern mirrors ONE `Illuminate\Validation\Rules\Password` check, using the same Unicode
     * properties the framework uses (`\p{Ll}`, `\p{Lu}`, `\p{N}`, and `\p{Z}|\p{S}|\p{P}` for symbols) rather
     * than an ASCII approximation — otherwise a password made of non-ASCII digits or punctuation would tick
     * on the server and not in the browser, which is the drift this class exists to prevent.
     *
     * @return list<array{key: string, label: string, pattern: string|null}>
     */
    public static function requirements(): array
    {
        return [
            [
                'key' => 'min_length',
                'label' => self::MIN_LENGTH.' characters or more',
                // `[\s\S]` rather than `.`, because `.` does not match a newline and a passphrase may
                // legitimately contain one. The server counts characters, not non-newlines.
                'pattern' => '[\s\S]{'.self::MIN_LENGTH.',}',
            ],
            [
                'key' => 'mixed_case',
                'label' => 'An upper and a lower case letter',
                // ONE row for `mixedCase()`, which is one rule and one error message on the server. Two rows
                // would report two failures where the validator reports one.
                'pattern' => '(?=[\s\S]*\p{Ll})(?=[\s\S]*\p{Lu})',
            ],
            [
                'key' => 'numbers',
                'label' => 'A number',
                'pattern' => '\p{N}',
            ],
            [
                'key' => 'symbols',
                'label' => 'A symbol',
                // The framework's own class set for `symbols()`. Note `\p{Z}` — a SPACE counts as a symbol to
                // Laravel, so a passphrase with spaces satisfies this. Surprising, and correct to mirror.
                'pattern' => '\p{Z}|\p{S}|\p{P}',
            ],
            [
                'key' => 'uncompromised',
                'label' => 'Not found in a known data breach',
                // ⚠️ NULL ON PURPOSE — checked when you submit, never in the browser. See the class docblock.
                'pattern' => null,
            ],
        ];
    }
}
