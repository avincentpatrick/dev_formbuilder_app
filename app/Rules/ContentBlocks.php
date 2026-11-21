<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
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
 *   image     {type, attachment_id, alt}          attachment_id is one of the form's own uploads (M129, `D58` = B)
 *   list      {type, style: bulleted|numbered, items: [{spans}]}                       M245, one level
 *   details   {type, summary, blocks}            collapsed for respondents, printed open; its blocks are any type
 *                                                but another details (M245, `R-93e22765`)
 *   span      {text, bold?, italic?, code?, link?, term?}
 *
 * ── A GLOSSARY WORD NAMES ITS TERM BY TEXT, NOT BY AN ENTRY'S ID (M245, `R-d0bbb7bb`) ────────────────────────
 * `term` is the glossary term the span's text opens, and it must BE that text. Content is copied verbatim by publish's
 * clone, a draft copy, the question library, a template and a restore, while a glossary entry's row id changes on every
 * one of them; a word marked by text survives them all and is matched against whichever glossary its form has
 * (`App\Support\Forms\Glossary::key()`). A word whose term the form's glossary lacks is refused at publish,
 * never at save, so an author may mark a word before defining it. A `|` is refused in a term so `[[shown|term]]` can
 * be added later without changing what any stored mark means.
 *
 * ⚠️ EVERY READER RECURSES INTO A DETAILS BLOCK. {@see problem()} counts nested blocks toward MAX_BLOCKS, and
 * {@see imageIds()} and {@see termMarks()} name a nested block by its dotted path ("3.2"). A reader that walks only
 * the top level would leave a picture inside a details block unserved to guests and unchecked at publish.
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
 * ⚠️ THE BUILDER ASKS THE SAME QUESTION BEFORE IT SAVES. `linkLooksSafe()` in `content-markup.ts` is this method's
 * twin, so the editor keeps a link it knows will be refused as plain text instead of losing the whole save to it. Both
 * halves read `tests/fixtures/content-block-links.json` and each suite fails on a vector its half disagrees with, so a
 * change here that the fixture does not carry is a change the editor has not heard about.
 *
 * ── AN IMAGE IS A POINTER, AND WHOSE IT IS IS ASKED AT PUBLISH (M129, `R-f0c5b682`) ──────────────────────────
 * `D58` = B: a note's image belongs to the FORM (kind `form_content_image`, owner alias `form`). This rule checks the
 * SHAPE in both modes — the id must be a uuid — and in strict mode a description, because an image a screen-reader
 * user is told nothing about is not content. Whether the id names THIS form's own image is a database question a rule
 * cannot ask: `StructuralValidationGate` asks it at publish, over {@see imageIds()}.
 */
final class ContentBlocks implements ValidationRule
{
    public const MAX_BLOCKS = 50;

    public const MAX_SPANS = 50;

    public const MAX_HEADING_LENGTH = 300;

    public const MAX_TEXT_LENGTH = 2000;

    public const MAX_LINK_LENGTH = 2000;

    public const MAX_ALT_LENGTH = 300;

    public const MAX_LIST_ITEMS = 50;

    public const MAX_TERM_LENGTH = 100;

    /** @var list<string> */
    public const LINK_SCHEMES = ['https', 'http', 'mailto', 'tel'];

    /** @var list<string> */
    public const TONES = ['info', 'success', 'warning', 'danger'];

    /** @var list<string> */
    public const LIST_STYLES = ['bulleted', 'numbered'];

    /** The keys each block type may carry, `type` included. */
    private const BLOCK_KEYS = [
        'heading' => ['type', 'level', 'text'],
        'paragraph' => ['type', 'spans'],
        'callout' => ['type', 'tone', 'spans'],
        'divider' => ['type'],
        'image' => ['type', 'attachment_id', 'alt'],
        'list' => ['type', 'style', 'items'],
        'details' => ['type', 'summary', 'blocks'],
    ];

    private const ITEM_KEYS = ['spans'];

    private const SPAN_KEYS = ['text', 'bold', 'italic', 'code', 'link', 'term'];

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
        if (self::countBlocks($value) > self::MAX_BLOCKS) {
            return 'a note may hold at most '.self::MAX_BLOCKS.' blocks, counting those inside a details block';
        }

        foreach ($value as $index => $block) {
            $problem = self::blockProblem($block, $strict, (string) ($index + 1), false);
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * How many blocks `$value` holds, each details block's own blocks included. Not a shape check: a malformed entry
     * counts as one block, and {@see problem()} is the authority on whether it is one.
     */
    public static function countBlocks(mixed $value): int
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return 0;
        }

        $count = 0;
        foreach ($value as $block) {
            $count++;
            if (is_array($block) && ($block['type'] ?? null) === 'details' && is_array($block['blocks'] ?? null)) {
                $count += count($block['blocks']);
            }
        }

        return $count;
    }

    /** The first reason `$block` is not a block, as a whole phrase that names it by `$path`, or null. */
    private static function blockProblem(mixed $block, bool $strict, string $path, bool $nested): ?string
    {
        $reason = self::blockReason($block, $strict, $path, $nested);

        return $reason === null || str_starts_with($reason, 'block ') ? $reason : "block {$path} {$reason}";
    }

    private static function blockReason(mixed $block, bool $strict, string $path, bool $nested): ?string
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
            'image' => self::imageProblem($block, $strict),
            'list' => self::listProblem($block, $strict, $path),
            'details' => $nested
                ? 'is a details block inside another details block'
                : self::detailsProblem($block, $strict, $path),
        };
    }

    /**
     * @param  array<array-key, mixed>  $block
     */
    private static function listProblem(array $block, bool $strict, string $path): ?string
    {
        if (! in_array($block['style'] ?? null, self::LIST_STYLES, true)) {
            return 'is a list that is neither bulleted nor numbered';
        }

        $items = $block['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            return 'is a list with no list of items';
        }
        if (count($items) > self::MAX_LIST_ITEMS) {
            return 'is a list of more than '.self::MAX_LIST_ITEMS.' items';
        }
        if ($strict && $items === []) {
            return 'is a list with no items';
        }

        foreach ($items as $position => $item) {
            $name = "block {$path} item ".($position + 1);
            if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                return "{$name} is not a list item";
            }
            $unknown = array_diff(array_map('strval', array_keys($item)), self::ITEM_KEYS);
            if ($unknown !== []) {
                return "{$name} has an unknown setting “".reset($unknown).'”';
            }
            $problem = self::spansProblem($item['spans'] ?? null, $strict);
            if ($problem !== null) {
                return "{$name} {$problem}";
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $block
     */
    private static function detailsProblem(array $block, bool $strict, string $path): ?string
    {
        $summary = $block['summary'] ?? null;
        if ($summary !== null && ! is_string($summary)) {
            return 'is a details block whose summary is not text';
        }
        if (is_string($summary) && mb_strlen($summary) > self::MAX_HEADING_LENGTH) {
            return 'is a details block whose summary is longer than '.self::MAX_HEADING_LENGTH.' characters';
        }
        if ($strict && trim((string) $summary) === '') {
            return 'is a details block with no summary';
        }

        $inner = $block['blocks'] ?? null;
        if (! is_array($inner) || ! array_is_list($inner)) {
            return 'is a details block with no list of blocks';
        }
        if ($strict && $inner === []) {
            return 'is a details block with nothing inside it';
        }

        foreach ($inner as $index => $child) {
            $problem = self::blockProblem($child, $strict, $path.'.'.($index + 1), true);
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * Every block of `$value` with its dotted path ("3", or "3.2" inside a details block), in reading order. A
     * malformed entry is skipped, never thrown on: {@see problem()} is the authority on shape.
     *
     * @return list<array{path: string, block: array<array-key, mixed>}>
     */
    public static function walk(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $index => $block) {
            if (! is_array($block)) {
                continue;
            }
            $path = (string) ($index + 1);
            $out[] = ['path' => $path, 'block' => $block];
            $inner = $block['blocks'] ?? null;
            if (($block['type'] ?? null) === 'details' && is_array($inner) && array_is_list($inner)) {
                foreach ($inner as $position => $child) {
                    if (is_array($child)) {
                        $out[] = ['path' => $path.'.'.($position + 1), 'block' => $child];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The attachment id of every image block, nested ones included, each with the dotted path of its block, for the
     * publish gate's ownership check. Lowercased, because Postgres answers a uuid in lowercase whatever case it was
     * written in. A malformed block is skipped, never thrown on: {@see problem()} is the authority on shape, and the
     * gate asks it first.
     *
     * @return list<array{block: string, id: string}>
     */
    public static function imageIds(mixed $value): array
    {
        $ids = [];
        foreach (self::walk($value) as ['path' => $path, 'block' => $block]) {
            if (($block['type'] ?? null) === 'image' && is_string($block['attachment_id'] ?? null)) {
                $ids[] = ['block' => $path, 'id' => strtolower($block['attachment_id'])];
            }
        }

        return $ids;
    }

    /**
     * Every glossary word in `$value` (a span's `term`), in paragraphs, callouts and list items at any depth, each with
     * the dotted path of its block, for the publish gate's "is it in this form's glossary" check.
     *
     * @return list<array{block: string, term: string}>
     */
    public static function termMarks(mixed $value): array
    {
        $marks = [];
        foreach (self::walk($value) as ['path' => $path, 'block' => $block]) {
            foreach (self::spanLists($block) as $spans) {
                foreach ($spans as $span) {
                    if (is_array($span) && is_string($span['term'] ?? null)) {
                        $marks[] = ['block' => $path, 'term' => $span['term']];
                    }
                }
            }
        }

        return $marks;
    }

    /**
     * Each run of spans a block holds: a paragraph's or callout's one, a list's one per item, nothing for the rest.
     *
     * @param  array<array-key, mixed>  $block
     * @return list<array<array-key, mixed>>
     */
    public static function spanLists(array $block): array
    {
        $type = $block['type'] ?? null;
        if ($type === 'paragraph' || $type === 'callout') {
            return is_array($block['spans'] ?? null) ? [$block['spans']] : [];
        }
        if ($type !== 'list' || ! is_array($block['items'] ?? null)) {
            return [];
        }

        $lists = [];
        foreach ($block['items'] as $item) {
            if (is_array($item) && is_array($item['spans'] ?? null)) {
                $lists[] = $item['spans'];
            }
        }

        return $lists;
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

    /**
     * @param  array<array-key, mixed>  $block
     */
    private static function imageProblem(array $block, bool $strict): ?string
    {
        $id = $block['attachment_id'] ?? null;
        if (! is_string($id) || ! Str::isUuid($id)) {
            return 'is an image that names no uploaded file';
        }

        $alt = $block['alt'] ?? null;
        if ($alt !== null && ! is_string($alt)) {
            return 'is an image whose description is not text';
        }
        if (is_string($alt) && mb_strlen($alt) > self::MAX_ALT_LENGTH) {
            return 'is an image whose description is longer than '.self::MAX_ALT_LENGTH.' characters';
        }
        if ($strict && trim((string) $alt) === '') {
            return 'is an image with no description';
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

        if (array_key_exists('term', $span)) {
            $problem = self::termProblem($span);
            if ($problem !== null) {
                return $problem;
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

    /**
     * A glossary word's own checks, the same in both modes: a term of 1 to MAX_TERM_LENGTH characters with no edge
     * space, line break, control character or `|`, equal to the span's text, and neither a link nor code.
     *
     * @param  array<array-key, mixed>  $span
     */
    private static function termProblem(array $span): ?string
    {
        $term = $span['term'];
        if (! is_string($term) || $term === '' || $term !== trim($term) || mb_strlen($term) > self::MAX_TERM_LENGTH
            || preg_match('/[\x{0}-\x{1F}\x{7F}|]/u', $term) === 1) {
            return 'has a glossary word that is not a term of up to '.self::MAX_TERM_LENGTH.' characters';
        }
        if (($span['text'] ?? null) !== $term) {
            return 'has a glossary word whose text is not its term';
        }
        if (array_key_exists('link', $span) || ($span['code'] ?? false) === true) {
            return 'has a glossary word that is also a link or code';
        }

        return null;
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

        // An http(s) link must name its host after `//` (M129). Without this, `parse_url()` reads `https:443` as the
        // host `https` on port 443 — a "safe" link to nowhere, and a reading the editor's twin could only copy by
        // re-implementing PHP's port-guessing branch.
        if (! str_starts_with(substr($link, strlen($match[0])), '//')) {
            return false;
        }

        // ⚠️ `parse_url()` answers FALSE, not null, for a URL it cannot parse — `https://` among them — so a `?? ''`
        // here would wave a host-less link through. Measured: the unit matrix's "https with no host" case caught it.
        $host = parse_url($link, PHP_URL_HOST);

        return is_string($host) && $host !== '';
    }
}
