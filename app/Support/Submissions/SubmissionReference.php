<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Http\Controllers\Public\GuestDraftResumeController;

/**
 * The one place a `submissions.reference` is minted, formatted and parsed (Increment J2e).
 *
 * A reference is the short, quotable handle a respondent is shown and a support agent pastes back into a
 * search box: eight Crockford Base32 characters, stored uppercase and ungrouped, displayed `7K4M-2QXB`.
 *
 * ⚠️ WHY IT IS RANDOM RATHER THAN DERIVED FROM THE ID, WHICH IS THE WHOLE REASON THIS CLASS EXISTS.
 * The product used to DISPLAY `substr($submission->id, 0, 8)` and accept it back as a lookup. That is a
 * lookup for a random uuid and NOT one for a **uuidv7**, which is what `submissions.id` is: the first 12 hex
 * characters are a 48-bit millisecond timestamp, so the first EIGHT are its top 32 bits — identical for every
 * row created inside the same ~49-day window. Measured in J1e rather than reasoned: two submissions seeded
 * milliseconds apart share them.
 *
 * Raising the prefix length alone would have made things worse, because the product PRINTED those eight
 * characters and the contract was that what is shown can be pasted back in. Real randomness in a uuidv7 does
 * not begin until hex position 14, so a selective prefix would mean displaying ~16 characters — i.e. a
 * different reference format. This is that format: one change, not two.
 *
 * ⚠️ THIS IS A DISPLAY HANDLE, NEVER A CREDENTIAL. Forty bits is ample to keep a tenant's references distinct
 * and nowhere near enough to resist enumeration. No route may resolve a submission by reference alone —
 * `SubmissionReferenceDisclosureTest` asserts that structurally, because a prohibition that lives only in a
 * comment is not a prohibition. If a public "check my submission" page is ever built, it needs a signed token
 * exactly as {@see GuestDraftResumeController} already does.
 *
 * Uniqueness is enforced by the database, not here: `submissions_tenant_id_reference_unique` is
 * `(tenant_id, reference)` with NO `deleted_at` arm, so a soft-deleted submission keeps its code reserved
 * forever. That is deliberate — freeing it would let a respondent's written-down code resolve to a DIFFERENT
 * submission later, which is strictly worse than "not found". There is deliberately no collision probe in
 * this class; see {@see SubmissionReferenceIssuer} for why the retry lives at the transaction boundary.
 */
final class SubmissionReference
{
    /**
     * Crockford Base32: the digits plus the uppercase letters, minus I, L, O and U.
     *
     * I/L/O are excluded because they are visually confusable with 1 and 0 on a printed confirmation; U is
     * excluded by Crockford to avoid spelling accidental obscenities. That asymmetry matters at parse time —
     * see {@see normalize()}.
     */
    public const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Matches `submissions.reference`'s column width. 32^8 ≈ 1.1e12 codes per tenant. */
    public const int LENGTH = 8;

    /** Display grouping only. The stored value never contains a separator. */
    public const int GROUP = 4;

    /**
     * A fresh code. Uppercase, ungrouped, exactly {@see LENGTH} characters.
     *
     * ⚠️ `random_int`, NOT `mt_rand`, and not because the code is a secret — it is printed on a confirmation
     * screen and grants nothing. `mt_rand` is seeded per process, so a php-fpm fleet forked from one master
     * can emit correlated sequences, which would raise the EFFECTIVE collision rate above the arithmetic in a
     * way no test could ever catch. `random_int` draws from the OS CSPRNG and has no such failure mode.
     *
     * The rejected alternative, recorded because it looks tidier: `random_bytes()` plus `ord($b) % 32` is
     * unbiased ONLY because 256 % 32 == 0 — a correctness fact that breaks silently the day someone changes
     * the alphabet size. Eight draws cannot get modulo bias wrong, because there is no modulo.
     */
    public static function mint(): string
    {
        $out = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, 31)];
        }

        return $out;
    }

    /**
     * The stored value as it is shown to a human: `7K4M2QXB` becomes `7K4M-2QXB`.
     *
     * Every display surface calls this — the inbox, the submission detail, the PDF, the export, the search
     * result title and the guest confirmation. No caller spells the separator itself, which is what keeps a
     * re-grouping (4-4 to 3-5, say) a one-line change here rather than a data migration.
     */
    public static function format(string $stored): string
    {
        $groups = str_split($stored, self::GROUP);

        return implode('-', $groups);
    }

    /**
     * User-supplied text as a stored reference, or null when it is not one.
     *
     * Lenient in exactly four ways, and no more:
     *
     * 1. Strips hyphens, ASCII spaces and U+00A0 — a code pasted out of a PDF carries a non-breaking space,
     *    and the display form carries a hyphen by construction.
     * 2. Upper-cases, because the stored form is uppercase.
     * 3. Applies Crockford's DECODE leniency on input only, never on generation: I and L read as 1, O reads
     *    as 0. That is what makes a hand-copied code work.
     * 4. Then refuses anything that is not exactly {@see LENGTH} characters drawn from {@see ALPHABET}.
     *
     * ⚠️ U IS NOT MAPPED AND IS NOT ACCEPTED. Crockford excludes it to avoid accidental obscenity rather than
     * for visual confusion, so — unlike I, L and O — there is no digit it could mean. Accepting it would only
     * manufacture a lookup that matches nothing. In production a code containing U cannot exist, so this arm
     * is a fail-closed guard rather than a live rule: its mutation reddens the unit case and nothing else.
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $candidate = str_replace(['-', ' ', "\u{00A0}"], '', $input);
        $candidate = mb_strtoupper(trim($candidate));
        $candidate = strtr($candidate, ['I' => '1', 'L' => '1', 'O' => '0']);

        // Byte length AND byte span, deliberately not `mb_strlen`: every alphabet character is single-byte
        // ASCII, so `strlen === 8 && strspn === 8` proves the string is exactly eight alphabet characters.
        // Any multibyte input fails one or the other. Mixing a character count with a byte span would read
        // as correct and be true only by accident.
        if (strlen($candidate) !== self::LENGTH || strspn($candidate, self::ALPHABET) !== self::LENGTH) {
            return null;
        }

        return $candidate;
    }

    /** Is `$candidate` already exactly the stored form? One predicate, not two that can drift. */
    public static function isValid(string $candidate): bool
    {
        return self::normalize($candidate) === $candidate;
    }
}
