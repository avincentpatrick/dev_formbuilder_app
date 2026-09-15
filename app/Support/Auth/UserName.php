<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * The `users.name` contract, stated once (M96): one limit, a rule that refuses by it, and a fit that cuts to it.
 *
 * The users-table migration declares `name` as `varchar(150)`. A longer value is never a validation message on
 * its own: it fails the write with SQLSTATE 22001, which nothing maps, so the person sees a 500. Before this
 * class the number lived in three unconnected copies while registration and the profile form validated
 * `max:255` against it, and Google sign-in and the invitation placeholder wrote names nothing had measured.
 * `FortifyErrorBagTest` compares {@see self::MAX} with the live column, so a migration that moves it goes red.
 *
 * ── WHICH OF THE TWO A WRITER USES DEPENDS ON WHO CHOSE THE NAME ────────────────────────────────────────────
 *   · A person typed it → REFUSE with {@see rules()}. They can shorten it, and a message on the field is the
 *     honest answer: registration, the profile form, invitation accept, and the operator commands' typed names.
 *   · An identity provider or this product derived it → FIT with {@see fit()}, at every `users` INSERT that
 *     takes one. Refusing a verified Google or SAML sign-in over a cosmetic field would strand a real person who
 *     can correct the name on their profile afterwards, and nobody who could shorten a placeholder's name or a
 *     default owner name ever typed it.
 *
 * ⚠️ `fit()` COUNTS CODE POINTS, BECAUSE BOTH ENDS OF THE WRITE DO. Laravel's `max` rule measures `mb_strlen`,
 * and `varchar(150)` in this UTF-8 database holds 150 characters — measured with four-byte emoji and with
 * combining marks, not assumed. So:
 *   · ⛔ NEVER `Str::limit()`. It measures DISPLAY WIDTH, where a CJK character or an emoji is two columns, so a
 *     100-character Chinese name that fits is cut to 75. `SsoIdentityResolver` did exactly that until M96.
 *   · ⛔ NEVER `grapheme_substr()`. 150 grapheme clusters can be far more than 150 code points — a flag is two, a
 *     family emoji several — which reopens the 22001 this class exists to close.
 * The accepted cost: a cut can split a multi-code-point grapheme at the 150th character of a name that long.
 */
final class UserName
{
    /** `users.name` is `varchar(150)`; a longer value fails the write with SQLSTATE 22001, a 500. */
    public const int MAX = 150;

    /**
     * The validation rules for a name a person typed.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'max:'.self::MAX];
    }

    /**
     * A derived or identity-provider name, trimmed and cut to the column in code points.
     *
     * Trimmed first so padding never costs a character, and trimmed again after the cut so a name never ends in
     * the space the cut exposed. An all-whitespace value comes back as '', so a caller that needs a non-empty
     * name chooses its fallback BEFORE fitting.
     */
    public static function fit(string $value): string
    {
        return rtrim(mb_substr(trim($value), 0, self::MAX, 'UTF-8'));
    }
}
