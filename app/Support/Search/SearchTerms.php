<?php

declare(strict_types=1);

namespace App\Support\Search;

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
     * is both what a support ticket quotes and short enough to type. Below that the `LIKE` would match a
     * meaningful fraction of any tenant's submissions and stop being a lookup.
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
