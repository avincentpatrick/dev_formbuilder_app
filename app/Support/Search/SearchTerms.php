<?php

declare(strict_types=1);

namespace App\Support\Search;

use App\Services\Search\Arms\SubmissionSearchArm;
use App\Services\Tenancy\TenantMembershipService;

/**
 * A parsed, sanitised search query (Increment J1b).
 *
 * ⚠️ THE SANITISER IS LOAD-BEARING, NOT DEFENSIVE, AND THIS IS THE WHOLE REASON THE CLASS EXISTS.
 * The value is always BOUND, so SQL injection is not the concern. Availability is: `to_tsquery('simple',
 * 'foo &')` RAISES a syntax error, which arrives as a `QueryException` and therefore as a **500 on an Inertia
 * GET** — triggered by a user typing a single `&` into the search box. Every character outside unicode
 * letters, digits and underscore is stripped before anything reaches Postgres, so no operator, quote or
 * parenthesis can ever get there.
 *
 * ── WHY `to_tsquery` AND NOT `websearch_to_tsquery` ──────────────────────────────────────────────────────
 * `websearch_to_tsquery` is the obvious choice — it accepts arbitrary user text without raising, which is
 * exactly what the paragraph above is worried about. It is rejected because it **cannot prefix-match**, and a
 * command palette is type-ahead by definition: a user who has typed "clin" must see "Clinic Intake" before
 * they finish the word. `plainto_tsquery` has the same limitation. So the query is assembled here instead,
 * from tokens that are safe by construction, with `:*` appended to the LAST token only.
 *
 * The trade recorded honestly: we lose `websearch`'s quoted-phrase and `-negation` syntax. Nobody has asked
 * for either, and both can be added later on top of the same token list. Prefix matching cannot be.
 *
 * ── THE CAPS ─────────────────────────────────────────────────────────────────────────────────────────────
 * 200 characters, 6 tokens, 64 characters per token. Not arbitrary: a 10,000-character paste would otherwise
 * become a 10,000-character tsquery, and every extra `&` term is another GIN lookup intersected at run time.
 * Truncation is silent by design — the alternative is a validation error on a GET, and
 * `AuditLogFilterRequest`'s rule ("every rule is `sometimes`, never `required` — a 422 on an Inertia GET
 * redirects 'back', which on a cold visit is nowhere") applies with equal force to a search box.
 */
final readonly class SearchTerms
{
    public const int MAX_RAW_LENGTH = 200;

    public const int MAX_TOKENS = 6;

    public const int MAX_TOKEN_LENGTH = 64;

    /**
     * The shortest hex run treated as a submission reference. Eight is the first canonical uuid group, which
     * is both what a support ticket quotes and short enough to type.
     *
     * ⚠️ THE ORIGINAL JUSTIFICATION FOR 8 WAS WRONG, AND THE CORRECTION IS KEPT BECAUSE IT CHANGES WHAT THIS
     * FEATURE PROMISES. It read: "Below that the `LIKE` would match a meaningful fraction of any tenant's
     * submissions and stop being a lookup." That is true of a RANDOM uuid and false of a **uuidv7**, which
     * is what `submissions.id` is: the first 12 hex characters are a 48-bit millisecond timestamp, so the
     * first EIGHT are its top 32 bits — identical for every row created inside the same ~49-day window.
     * Measured in J1e, not reasoned: two submissions seeded milliseconds apart share them.
     *
     * So an 8-character reference already narrows to a time window rather than to a row. It is not a
     * disclosure (every caller is bounded by its own visibility scope), it is the lookup not being one.
     *
     * **Raising this constant alone would make things worse, which is why J1e did not.** The inbox and the
     * command palette both DISPLAY `substr($id, 0, 8)`, and {@see SubmissionSearchArm}'s
     * contract is that what is shown can be pasted back in — a longer minimum would refuse the very string
     * the product prints. Real randomness in a uuidv7 does not begin until hex position 14, so a selective
     * prefix means displaying ~16 characters, i.e. a different reference format. That is the "real short
     * handle" the arm already files for J2, and it is one change, not two.
     *
     * A FULL uuid pasted in is an exact lookup and always has been; `ListKeywordFilterTest` pins both halves.
     */
    public const int MIN_UUID_PREFIX = 8;

    /**
     * @param  list<string>  $tokens  lowercased, alphanumeric-or-underscore only
     */
    private function __construct(
        private ?string $raw,
        private array $tokens,
        private ?string $uuidPrefix,
    ) {}

    public static function parse(?string $raw): self
    {
        if ($raw === null) {
            return new self(null, [], null);
        }

        $clamped = mb_substr($raw, 0, self::MAX_RAW_LENGTH);

        if (trim($clamped) === '') {
            // Whitespace-only is indistinguishable from empty for every purpose here, and collapsing them
            // means the presenter has ONE "no query" branch rather than two that can drift apart.
            return new self(null, [], null);
        }

        $lowered = mb_strtolower($clamped);

        // Split on anything that is not a unicode letter, digit or underscore. `\p{L}` rather than `a-z`
        // because the corpus is multilingual by construction (tenant locales, PSGC scope names) and an ASCII
        // class would silently shred every non-Latin query into nothing.
        $split = preg_split('/[^\p{L}\p{N}_]+/u', $lowered, -1, PREG_SPLIT_NO_EMPTY);

        $tokens = [];
        foreach ($split === false ? [] : $split as $token) {
            $tokens[] = mb_substr($token, 0, self::MAX_TOKEN_LENGTH);
            if (count($tokens) === self::MAX_TOKENS) {
                break;
            }
        }

        return new self(trim($clamped), $tokens, self::detectUuidPrefix($lowered));
    }

    /**
     * A canonical-uuid PREFIX, if the whole query looks like one.
     *
     * Deliberately derived from the raw string rather than from `$tokens`: the tokeniser splits on the
     * hyphens, so `0198a3c1-2b3c` would arrive as two tokens and the hyphen position — which is what makes a
     * longer prefix match `id::text` at all — would be lost.
     */
    private static function detectUuidPrefix(string $lowered): ?string
    {
        $candidate = trim($lowered);

        if (! preg_match('/^[0-9a-f]{8}[0-9a-f-]*$/', $candidate)) {
            return null;
        }

        if (mb_strlen($candidate) < self::MIN_UUID_PREFIX || mb_strlen($candidate) > 36) {
            return null;
        }

        return $candidate;
    }

    public function isEmpty(): bool
    {
        return $this->tokens === [];
    }

    /**
     * The `to_tsquery` expression. Bind this as a parameter; never interpolate it.
     *
     * Only the LAST token is a prefix. Making every token a prefix would match far too widely on a
     * multi-word query ("cl in" matching everything), and making none a prefix breaks type-ahead entirely.
     */
    public function tsQuery(): string
    {
        if ($this->tokens === []) {
            return '';
        }

        $terms = $this->tokens;
        $last = array_key_last($terms);
        $terms[$last] .= ':*';

        return implode(' & ', $terms);
    }

    /**
     * One `%token%` pattern per token, for the arms that match with `ILIKE` instead of a tsvector
     * (Increment J1c). Bind these; never interpolate them.
     *
     * ── ⚠️ WHY THE MEMBERS ARM IS NOT FULL-TEXT, WHICH IS A DECISION AND NOT A SHORTCUT ─────────────────
     * The obvious symmetry — give `users` a `search_vector` like `forms` and `submissions` — cannot work,
     * and the reason is in `parse()` directly above. PostgreSQL's default parser emits `ana@acme.org` as a
     * SINGLE `email` lexeme, while `parse()` splits on everything outside `\p{L}\p{N}_` and yields
     * `ana & acme & org:*`. Those can never match, so an admin searching a domain to find everyone on it
     * would get nothing. Fixing that needs a second tokeniser, which is the one thing this feature refuses.
     *
     * Two further reasons, both measured in J1b rather than assumed: an index would be unreachable anyway
     * (`~~` and `~~*` are `proleakproof = f`, the same wall that removed the two GIN indexes — `pg_trgm` is
     * no escape hatch either), and `users` has no `tenant_id`, so any index on it is a cross-tenant object
     * indexing PII on a table with no erasure path. The candidate set after the `tenant_users` join is one
     * tenant's roster — tens to low hundreds — so a scan over it is the honest shape.
     *
     * ⚠️ `_` AND `%` ARE ESCAPED EVEN THOUGH `parse()` STRIPS `%`. Underscore SURVIVES tokenisation (it is
     * in the keep-class), so an unescaped `a_c` would match "abc" — a real wrong-result bug, not a
     * theoretical one. `%` is escaped anyway so this method does not silently depend on the tokeniser's
     * class staying narrow. The escape character is `!` rather than `\`: a backslash would have to survive
     * PHP string escaping AND `standard_conforming_strings`, and getting that wrong fails silently.
     *
     * @return list<string>
     */
    public function likePatterns(): array
    {
        return array_map(
            static fn (string $token): string => '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $token).'%',
            $this->tokens
        );
    }

    /**
     * The IN-PHP twin of {@see KeywordFilter::applyLike()}: every token must appear in at least one of
     * `$values` — AND across tokens, OR across values (Increment J1e).
     *
     * ⚠️ IT LIVES HERE, BESIDE `likePatterns()`, BECAUSE THE TWO MUST NOT DRIFT. The members roster is the
     * one list in the product whose keyword filter runs in PHP rather than in SQL, and it does so for a
     * security reason rather than a convenience one — see {@see TenantMembershipService::listMembers()}
     * and `MemberSearchArm`'s docblock. Two encodings of "what counts as a match" would let the roster and
     * the search arm disagree about the same two people; one method with two renderings cannot.
     *
     * ⚠️ NO ESCAPING IS NEEDED HERE, AND THAT ASYMMETRY IS THE POINT RATHER THAN AN OVERSIGHT. `%` and `_`
     * are wildcards to `ILIKE` and ordinary characters to `str_contains`, so the `ESCAPE '!'` dance
     * {@see likePatterns()} documents has no counterpart on this path. A future reader comparing the two
     * methods should not "fix" the difference.
     *
     * Tokens are already lowercased by {@see parse()}; the values are lowered here so the comparison is
     * case-insensitive in the same way `ILIKE` is. `mb_strtolower`, not `strtolower`: the corpus is
     * multilingual by construction, which is the reason `parse()` splits on `\p{L}` in the first place.
     */
    public function matchesAny(string ...$values): bool
    {
        if ($this->tokens === []) {
            return true;
        }

        $haystacks = array_map(static fn (string $v): string => mb_strtolower($v), $values);

        foreach ($this->tokens as $token) {
            $hit = false;
            foreach ($haystacks as $haystack) {
                if (str_contains($haystack, $token)) {
                    $hit = true;
                    break;
                }
            }

            if (! $hit) {
                return false;
            }
        }

        return true;
    }

    public function uuidPrefix(): ?string
    {
        return $this->uuidPrefix;
    }

    /** The clamped, trimmed original — echoed back into `filters.applied` so the box re-renders what ran. */
    public function raw(): ?string
    {
        return $this->raw;
    }
}
