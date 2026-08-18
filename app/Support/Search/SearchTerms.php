<?php

declare(strict_types=1);

namespace App\Support\Search;

use App\Services\Tenancy\TenantMembershipService;
use App\Support\Submissions\SubmissionReference;

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
     * ⚠️ `MIN_UUID_PREFIX = 8` WAS DELETED IN J2e, AND ITS MEASUREMENT SURVIVES IN
     * {@see SubmissionReference} AS THE REASON THAT CLASS EXISTS.
     *
     * Short version, because the next reader will wonder why a ≥8-character id fragment stopped working:
     * `submissions.id` is a uuidv7, whose first 12 hex characters are a 48-bit millisecond timestamp — so
     * the first EIGHT are its top 32 bits and are IDENTICAL for every row created inside the same ~49-day
     * window. Pasting eight characters returned a time window, not a row. The constant could not simply be
     * raised, because the product PRINTED those eight characters and the arm's contract was that what is
     * shown can be pasted back in; real randomness does not begin until hex position 14. It needed a
     * different reference FORMAT, which is `submissions.reference`.
     *
     * What replaced it: {@see referenceCandidate()} (an exact reference) and {@see submissionId()} (an exact,
     * strictly canonical uuid). A FULL uuid pasted in was always an exact lookup and still is;
     * `ListKeywordFilterTest` pins both halves.
     *
     * @param  list<string>  $tokens  lowercased, alphanumeric-or-underscore only
     */
    private function __construct(
        private ?string $raw,
        private array $tokens,
        private ?string $submissionId,
        private ?string $referenceCandidate,
    ) {}

    public static function parse(?string $raw): self
    {
        if ($raw === null) {
            return new self(null, [], null, null);
        }

        $clamped = mb_substr($raw, 0, self::MAX_RAW_LENGTH);

        if (trim($clamped) === '') {
            // Whitespace-only is indistinguishable from empty for every purpose here, and collapsing them
            // means the presenter has ONE "no query" branch rather than two that can drift apart.
            return new self(null, [], null, null);
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

        return new self(
            trim($clamped),
            $tokens,
            self::detectSubmissionId($lowered),
            SubmissionReference::normalize($clamped),
        );
    }

    /**
     * A FULL canonical uuid, if the whole query is one.
     *
     * ⚠️ THE REGEX IS STRICTLY CANONICAL, AND THAT STRICTNESS IS LOAD-BEARING RATHER THAN TIDY. The consumer
     * binds this to `submissions.id = ?`, and PostgreSQL raises 22P02 (`invalid input syntax for type uuid`)
     * on anything that is not one — which arrives as a `QueryException` and therefore as a **500 on an
     * Inertia GET**, the exact availability failure this class's header exists to prevent. The predecessor
     * could afford a loose pattern because it compared `id::text LIKE`, where a bad value merely matched
     * nothing; an equality cannot.
     *
     * The version nibble is deliberately NOT constrained: a support ticket may quote any id this system has
     * ever issued, including a pre-uuidv7 one.
     *
     * Derived from the raw string rather than from `$tokens` because the tokeniser splits on the hyphens,
     * so a uuid would arrive as five separate tokens with its shape destroyed.
     */
    private static function detectSubmissionId(string $lowered): ?string
    {
        $candidate = trim($lowered);

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $candidate)) {
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

    /** A full canonical uuid, safe to bind to `submissions.id = ?`. Null for anything else. */
    public function submissionId(): ?string
    {
        return $this->submissionId;
    }

    /**
     * The query as a stored `submissions.reference`, or null if it is not one.
     *
     * ⚠️ THERE IS NO AMBIGUITY WITH {@see submissionId()}, AND THE REASON IS WORTH STATING BECAUSE IT LOOKS
     * LIKE THERE SHOULD BE. Hex is a strict SUBSET of Crockford Base32 (Crockford omits only I, L, O and U;
     * A–F and 0–9 are all present), so every 8-character hex string is also a valid reference and no
     * alphabet-based test could tell them apart. LENGTH does, exactly and totally: a normalized reference is
     * 8 characters, a canonical uuid is 36, and a de-hyphenated uuid is 32 — never 8. The two accessors are
     * provably disjoint.
     *
     * ⚠️ Derived from the raw string, mirroring its sibling — but unlike its sibling that choice is NOT
     * observable here, and saying so is better than implying a test pins it. The tokeniser splits
     * `7K4M-2QXB` into `['7k4m','2qxb']`, and concatenating those yields the same eight characters
     * {@see SubmissionReference::normalize()} produces from the raw string. It reads from raw for symmetry;
     * every input I could construct agrees either way.
     */
    public function referenceCandidate(): ?string
    {
        return $this->referenceCandidate;
    }

    /** The clamped, trimmed original — echoed back into `filters.applied` so the box re-renders what ran. */
    public function raw(): ?string
    {
        return $this->raw;
    }
}
