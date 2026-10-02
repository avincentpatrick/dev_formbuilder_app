<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A note's author-composed content (Increment M125, `R-6dedc3a9`'s shape half): `config.content`, an ordered list of
 * typed, closed-shape blocks. No HTML string anywhere, no inline markup language, no sanitizer — every leaf is a plain
 * string in a named field, so each renderer escapes it like any other text.
 *
 *   heading   {type, level: 1|2, text}            level is RELATIVE: the guest SPA's section titles are h2, the preview's h3
 *   paragraph {type, spans}
 *   callout   {type, tone: info|success|warning|danger, spans}
 *   divider   {type}
 *   span      {text, bold?, italic?, code?, link?}
 *
 * ⛔ CLOSED BY REFUSAL, NEVER BY ENUMERATION. `UpdateFieldRequest::payload()` overlays the raw config back over
 * `validated()`, so a key a wildcard rule merely PRUNES — an `html` key on a span — is put straight back and persisted.
 * This is one rule over the whole list, and an unknown key at any depth refuses the whole value.
 *
 * ⛔ ONE CHECK, THREE WRITERS. The builder's PATCH runs it leniently through the FormRequest; {@see problem()} is also
 * called by `BlueprintValidator` (the template materializer and the question library both write config verbatim) and,
 * strictly, by `StructuralValidationGate` at publish. LENIENT accepts what an author mid-edit legitimately has — a blank
 * heading, an empty paragraph, a span whose text `ConvertEmptyStringsToNull` turned into null; STRICT refuses those,
 * because a respondent must never be shown them. Neither mode ever accepts a bad shape or an unsafe link.
 *
 * ⚠️ A LINK MUST NAME AN ALLOWLISTED SCHEME AND CARRY NO WHITESPACE OR CONTROL CHARACTER. Browsers strip tabs, newlines
 * and leading spaces from a URL before reading its scheme, so `java` + newline + `script:` is a relative link to this
 * check and a script to the browser unless such characters are refused outright. A relative link is refused too: there
 * is no page of ours a form's text needs to point at. The renderer re-checks at render; this is the write half.
 *
 * ⚠️ `image` is NOT in the closed set. Whose attachment it is, and who may read it, is `D58`.
 */
final class ContentBlocks implements ValidationRule
{
    public const MAX_BLOCKS = 50;

    public const MAX_SPANS = 50;

    public const MAX_HEADING_LENGTH = 300;

    public const MAX_TEXT_LENGTH = 2000;

    public const MAX_LINK_LENGTH = 2000;

    /** @var list<string> */
    public const LINK_SCHEMES = ['https', 'http', 'mailto', 'tel'];

    /** @var list<string> */
    public const TONES = ['info', 'success', 'warning', 'danger'];

    /** The keys each block type may carry, `type` included. */
    private const BLOCK_KEYS = [
        'heading' => ['type', 'level', 'text'],
        'paragraph' => ['type', 'spans'],
        'callout' => ['type', 'tone', 'spans'],
        'divider' => ['type'],
    ];

    private const SPAN_KEYS = ['text', 'bold', 'italic', 'code', 'link'];

    public function __construct(private readonly bool $strict = false) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = self::problem($value, $this->strict);

        if ($problem !== null) {
            $fail("The note's content is invalid: {$problem}.");
        }
    }

    /**
     * The first reason `$value` is not a content-block list, or null when it is one. Pure, so the publish gate and the
     * blueprint validator ask the same question the FormRequest does.
     */
    public static function problem(mixed $value, bool $strict): ?string
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return 'it must be a list of blocks';
        }
        if (count($value) > self::MAX_BLOCKS) {
            return 'a note may hold at most '.self::MAX_BLOCKS.' blocks';
        }

        foreach ($value as $index => $block) {
            $problem = self::blockProblem($block, $strict);
            if ($problem !== null) {
                return 'block '.($index + 1).' '.$problem;
            }
        }

        return null;
    }

    private static function blockProblem(mixed $block, bool $strict): ?string
    {
        if (! is_array($block) || ($block !== [] && array_is_list($block))) {
            return 'is not a block';
        }

        $type = $block['type'] ?? null;
        if (! is_string($type) || ! array_key_exists($type, self::BLOCK_KEYS)) {
            return 'has an unknown type';
        }

        $unknown = array_diff(array_map('strval', array_keys($block)), self::BLOCK_KEYS[$type]);
        if ($unknown !== []) {
            return 'has an unknown setting “'.reset($unknown).'”';
        }

        return match ($type) {
            'heading' => self::headingProblem($block, $strict),
            'paragraph' => self::spansProblem($block['spans'] ?? null, $strict),
            'callout' => in_array($block['tone'] ?? null, self::TONES, true)
                ? self::spansProblem($block['spans'] ?? null, $strict)
                : 'has an unknown tone',
            'divider' => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $block
     */
    private static function headingProblem(array $block, bool $strict): ?string
    {
        $level = $block['level'] ?? null;
        if ($level !== 1 && $level !== 2) {
            return 'is a heading whose level is not 1 or 2';
        }

        $text = $block['text'] ?? null;
        if ($text !== null && ! is_string($text)) {
            return 'is a heading whose text is not text';
        }
        if (is_string($text) && mb_strlen($text) > self::MAX_HEADING_LENGTH) {
            return 'is a heading longer than '.self::MAX_HEADING_LENGTH.' characters';
        }
        if ($strict && trim((string) $text) === '') {
            return 'is a heading with no text';
        }

        return null;
    }

    private static function spansProblem(mixed $spans, bool $strict): ?string
    {
        if (! is_array($spans) || ! array_is_list($spans)) {
            return 'has no list of text';
        }
        if (count($spans) > self::MAX_SPANS) {
            return 'has more than '.self::MAX_SPANS.' pieces of text';
        }

        $hasText = false;
        foreach ($spans as $span) {
            $problem = self::spanProblem($span, $strict);
            if ($problem !== null) {
                return $problem;
            }
            $hasText = $hasText || trim((string) ($span['text'] ?? '')) !== '';
        }

        if ($strict && ! $hasText) {
            return 'has no text';
        }

        return null;
    }

    private static function spanProblem(mixed $span, bool $strict): ?string
    {
        if (! is_array($span) || ($span !== [] && array_is_list($span))) {
            return 'holds a piece of text that is not one';
        }

        $unknown = array_diff(array_map('strval', array_keys($span)), self::SPAN_KEYS);
        if ($unknown !== []) {
            return 'has a piece of text with an unknown setting “'.reset($unknown).'”';
        }

        $text = $span['text'] ?? null;
        if ($text !== null && ! is_string($text)) {
            return 'has a piece of text that is not text';
        }
        if (is_string($text) && mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            return 'has a piece of text longer than '.self::MAX_TEXT_LENGTH.' characters';
        }

        foreach (['bold', 'italic', 'code'] as $flag) {
            if (array_key_exists($flag, $span) && ! is_bool($span[$flag])) {
                return "has a piece of text whose “{$flag}” is not on or off";
            }
        }

        if (! array_key_exists('link', $span)) {
            return null;
        }

        $link = $span['link'];
        if ($link === null || $link === '') {
            return $strict ? 'has a link with no address' : null;
        }

        return is_string($link) && self::linkIsSafe($link) ? null : 'has a link this form cannot show';
    }

    /** An absolute link with an allowlisted scheme and no whitespace or control character anywhere in it. */
    public static function linkIsSafe(string $link): bool
    {
        if (strlen($link) > self::MAX_LINK_LENGTH || preg_match('/[\x00-\x20\x7F]/', $link) === 1) {
            return false;
        }
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.-]*):/', $link, $match) !== 1) {
            return false;
        }

        $scheme = strtolower($match[1]);
        if (! in_array($scheme, self::LINK_SCHEMES, true)) {
            return false;
        }
        if ($scheme !== 'http' && $scheme !== 'https') {
            return true;
        }

        // ⚠️ `parse_url()` answers FALSE, not null, for a URL it cannot parse — `https://` among them — so a `?? ''`
        // here would wave a host-less link through. Measured: the unit matrix's "https with no host" case caught it.
        $host = parse_url($link, PHP_URL_HOST);

        return is_string($host) && $host !== '';
    }
}
