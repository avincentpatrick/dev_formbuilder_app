<?php

declare(strict_types=1);

namespace App\Support\Mapping;

/**
 * A stable, order-sensitive digest of a tabular artifact's header row (H16a).
 *
 * The whole drift mechanism reduces to one question — *is this the same table I was mapped against?* — and
 * this class is the only place that answers it. Two consumers ask it about two different artifacts: a Google
 * Sheets destination's first row (H16a) and a scanned linelist's detected column headers
 * ({@see docs/ocr-pipeline-design.md} §4, H19a).
 *
 * ORDER IS PART OF THE IDENTITY, not an incidental. Both consumers address columns POSITIONALLY — a Sheets
 * append writes cell 1, cell 2, cell 3, and an OCR row is read the same way — so two header rows carrying the
 * same labels in a different order are two different tables, and a set-based digest would call them equal and
 * then write every answer into the wrong column. That is the exact failure `ocr-pipeline-design.md:84` names.
 *
 * NORMALIZATION IS DELIBERATELY NARROW: trim, collapse internal whitespace runs, casefold. It absorbs the
 * differences that are invisible on screen — a trailing space a human cannot see, `Village  Name` with two
 * spaces, a capitalisation edit — and nothing else. It does NOT strip punctuation, unify dashes or transliterate
 * accents, because those are visible edits an author made on purpose and re-confirming a mapping is cheap.
 * Widening this is how a fingerprint starts calling genuinely different tables identical.
 *
 * TRAILING EMPTY HEADERS ARE DROPPED; INTERIOR ONES ARE KEPT. A spreadsheet's header row is padded to the
 * sheet's column count, so `['Name','Age','','','']` and `['Name','Age']` are the same table read twice — but
 * `['Name','','Age']` has a deliberate spacer column, and dropping it would shift every column after it by one.
 */
final readonly class ColumnFingerprint
{
    /**
     * @param  list<string>  $headers  the normalized header labels, in order, trailing blanks removed
     */
    private function __construct(
        public array $headers,
        public string $digest,
    ) {}

    /**
     * Fingerprint a raw header row as read from the artifact.
     *
     * @param  list<string|null>  $headers  the raw labels, in column order; null is treated as an empty cell
     */
    public static function forHeaders(array $headers): self
    {
        $normalized = self::normalizeAll($headers);

        return new self($normalized, self::digestOf($normalized));
    }

    /**
     * Rehydrate a fingerprint stored alongside a mapping, without re-deriving it from headers we no longer hold.
     *
     * @param  list<string|null>  $headers
     */
    public static function fromDigest(string $digest, array $headers = []): self
    {
        return new self(self::normalizeAll($headers), $digest);
    }

    public function matches(self $other): bool
    {
        // Constant-time comparison is deliberately NOT used: this is a change detector, not an authenticator.
        // Nothing is gated on secrecy here and a timing signal would reveal only how similar two column
        // headings are, which the tenant authored and can already see.
        return $this->digest === $other->digest;
    }

    public function isEmpty(): bool
    {
        return $this->headers === [];
    }

    /**
     * The canonical form of one header label.
     *
     * `mb_strtolower` rather than `strtolower`: a header may be any language the tenant writes in, and the
     * byte-wise version would leave `Ä` unfolded while folding `A`, making casefolding silently locale-dependent.
     */
    public static function normalize(?string $header): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', (string) $header);

        return mb_strtolower(trim($collapsed ?? ''));
    }

    /**
     * @param  list<string|null>  $headers
     * @return list<string>
     */
    private static function normalizeAll(array $headers): array
    {
        $normalized = array_map(self::normalize(...), array_values($headers));

        // Drop the trailing padding only. array_pop walks backwards, so an interior blank is never reached.
        while ($normalized !== [] && end($normalized) === '') {
            array_pop($normalized);
        }

        return array_values($normalized);
    }

    /**
     * @param  list<string>  $headers
     */
    private static function digestOf(array $headers): string
    {
        // The index is hashed alongside the label so a reorder changes the digest, and "\x1F" (unit separator)
        // joins them because it cannot occur in a spreadsheet cell — a plain "|" would make ['a|b'] and
        // ['a','b'] collide, which is the classic delimiter-injection bug in a hashing costume.
        $canonical = '';

        foreach ($headers as $index => $header) {
            $canonical .= $index."\x1F".$header."\x1E";
        }

        return hash('sha256', $canonical);
    }
}
