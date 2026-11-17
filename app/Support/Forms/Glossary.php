<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Rules\ContentBlocks;

/**
 * A form's glossary (M245, `R-d0bbb7bb`): terms and their definitions, kept per form version like choice lists
 * (`form_version_glossary_entries`), frozen into the published snapshot as `glossary`, and opened from a note's marked
 * words ({@see ContentBlocks}' span `term`) and from a Glossary button on every page.
 *
 * ── ONE MATCHING KEY, TWO ENGINES ──────────────────────────────────────────────────────────────────────────
 * A marked word finds its entry by {@see key()}: spacing runs collapsed to one space, the ends trimmed, and the whole
 * lower-cased, so "TWG", "twg" and "T W G"-with-a-non-breaking-space are one term while "TWGs" is not. The respondent
 * app matches with `glossaryKey()` in `resources/js/components/builder/glossary.ts`; both read
 * `tests/fixtures/glossary-term-keys.json` and each suite fails on a vector its half disagrees with. Unicode
 * normalisation is deliberately NOT applied: a composed and a decomposed é are two keys in both halves, which the
 * fixture records rather than leaves to chance.
 *
 * Definitions are plain text, one paragraph, in the form's default language only — as note content is (`D70`) — and
 * never piped (`D69`).
 */
final class Glossary
{
    public const MAX_ENTRIES = 200;

    public const MAX_DEFINITION_LENGTH = 1000;

    /** The matching key: spacing collapsed, ends trimmed, lower-cased. */
    public static function key(string $term): string
    {
        return mb_strtolower(self::collapse($term));
    }

    /** A term as it is stored: spacing collapsed and the ends trimmed, its letters' case kept. */
    public static function cleanTerm(string $term): string
    {
        return self::collapse($term);
    }

    /** A definition as it is stored: one paragraph, so every line break and spacing run becomes one space. */
    public static function cleanDefinition(string $definition): string
    {
        return self::collapse($definition);
    }

    /**
     * Every spacing run as one space, the ends trimmed. The set is spelled out rather than written `\s` or `\v`:
     * PCRE's `\v` is seven characters and JavaScript's is the vertical tab alone, so `glossary.ts` lists the same set.
     */
    private static function collapse(string $text): string
    {
        return trim((string) preg_replace('/[\p{Zs}\t\n\x{0B}\f\r\x{85}\x{2028}\x{2029}]+/u', ' ', $text));
    }

    /**
     * The first reason a term (already cleaned) cannot be a glossary term, or null. The same bounds as a marked word's
     * `term` ({@see ContentBlocks::MAX_TERM_LENGTH}), so every term an author can define can also be marked.
     */
    public static function termProblem(string $term): ?string
    {
        if ($term === '') {
            return 'is empty';
        }
        if (mb_strlen($term) > ContentBlocks::MAX_TERM_LENGTH) {
            return 'is longer than '.ContentBlocks::MAX_TERM_LENGTH.' characters';
        }
        if (preg_match('/[\x{0}-\x{1F}\x{7F}|]/u', $term) === 1) {
            return 'holds a “|” or a control character';
        }

        return null;
    }

    /**
     * The first reason `$value` is not a glossary as a snapshot or a template carries it — a list of
     * `{term, definition}` with distinct keys — or null. Pure, for the blueprint validator.
     */
    public static function problem(mixed $value): ?string
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return 'it must be a list of terms';
        }
        if (count($value) > self::MAX_ENTRIES) {
            return 'a form may hold at most '.self::MAX_ENTRIES.' glossary terms';
        }

        $seen = [];
        foreach ($value as $index => $entry) {
            $name = 'term '.($index + 1);
            if (! is_array($entry) || array_is_list($entry) || array_keys($entry) !== ['term', 'definition']) {
                return "{$name} is not a term and its definition";
            }
            if (! is_string($entry['term']) || ! is_string($entry['definition'])) {
                return "{$name} is not text";
            }
            $termProblem = $entry['term'] !== self::cleanTerm($entry['term'])
                ? 'has extra spacing'
                : self::termProblem($entry['term']);
            if ($termProblem !== null) {
                return "{$name} {$termProblem}";
            }
            if (trim($entry['definition']) === '' || mb_strlen($entry['definition']) > self::MAX_DEFINITION_LENGTH) {
                return "{$name}’s definition is empty or longer than ".self::MAX_DEFINITION_LENGTH.' characters';
            }
            $key = self::key($entry['term']);
            if (isset($seen[$key])) {
                return "{$name} repeats “{$entry['term']}”";
            }
            $seen[$key] = true;
        }

        return null;
    }

    /**
     * The glossary a snapshot carries, read defensively: entries that are not a term and a definition are skipped,
     * never thrown on. An older snapshot has no `glossary` key and reads as empty.
     *
     * @param  array<array-key, mixed>|null  $snapshot
     * @return list<array{term: string, definition: string}>
     */
    public static function fromSnapshot(?array $snapshot): array
    {
        $raw = $snapshot['glossary'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $entries = [];
        foreach ($raw as $entry) {
            if (is_array($entry) && is_string($entry['term'] ?? null) && is_string($entry['definition'] ?? null)) {
                $entries[] = ['term' => $entry['term'], 'definition' => $entry['definition']];
            }
        }

        return $entries;
    }

    /**
     * Entries in the one order every surface shows them: by key, then by the term itself, byte-wise, so the snapshot's
     * checksum never depends on insertion order. `strcmp`, never `<=>`: the spaceship compares two numeric strings as
     * numbers, so "10" would sort after "9". The respondent app keeps this order as it arrives and never re-sorts.
     *
     * @template T of array{term: string}
     *
     * @param  list<T>  $entries
     * @return list<T>
     */
    public static function sorted(array $entries): array
    {
        usort($entries, static fn (array $a, array $b): int => strcmp(self::key($a['term']), self::key($b['term'])) ?: strcmp($a['term'], $b['term']));

        return $entries;
    }
}
