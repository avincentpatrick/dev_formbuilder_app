<?php

declare(strict_types=1);

namespace App\Services\Ocr;

/**
 * The few text comparisons the matcher makes (M128), kept in one place so "the same text" means one
 * thing everywhere. Pure functions over strings.
 *
 * The printed blank form is ASCII by construction (`docs/ocr-pipeline-design.md` §2.5.5 — dompdf's core
 * fonts are WinAnsi), so the authored text a scan is compared with is ASCII too, and a byte-level
 * comparison is a fair one. Handwriting is not, which is why everything is normalised before comparing.
 */
final class OcrText
{
    /**
     * What a reviewer would accept as "an X in the box". A tick reads back as many things depending on the
     * pen, and a box that is merely drawn round can read as a letter O — so O is deliberately NOT a mark.
     *
     * @var list<string>
     */
    public const array MARKS = ['x', '×', '✓', '✔', '✗', '✘', 'v', '/', '\\', '*', '+'];

    /** What a recognizer makes of a drawn box wall: a bar, a broken bar, a box-drawing line. */
    private const array BORDER_ARTEFACTS = ['|', '¦', '│'];

    /**
     * A field key compared the way a 7pt stamp survives a scan: lower-cased, with everything but letters
     * and digits removed. The underscore is the character most often lost, so it is not required.
     */
    public static function key(string $text): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $text));
    }

    /** Lower-cased words of letters and digits, single-spaced — the comparison form of a printed string. */
    public static function normal(string $text): string
    {
        $lower = mb_strtolower($text);
        $clean = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $lower);

        return trim($clean);
    }

    /** 1.0 for identical normal forms, falling to 0.0 as the edit distance approaches the longer length. */
    public static function similarity(string $a, string $b): float
    {
        $a = self::normal($a);
        $b = self::normal($b);

        if ($a === '' || $b === '') {
            return $a === $b ? 1.0 : 0.0;
        }

        $longest = max(strlen($a), strlen($b));

        return 1.0 - levenshtein($a, $b) / $longest;
    }

    /** Whether a word, on its own, is a mark in a box. */
    public static function isMark(string $text): bool
    {
        return in_array(mb_strtolower(trim($text)), self::MARKS, true);
    }

    /**
     * Whether a recognised character is a box border rather than ink. A comb cell's walls are thin vertical
     * rules, and a recognizer reads a rule as `|`. Only that one is dropped: an `I` or a `1` written INSIDE
     * a cell is an answer, and the geometry cannot tell them apart from here.
     */
    public static function isBorderArtefact(string $text): bool
    {
        return in_array($text, self::BORDER_ARTEFACTS, true);
    }

    /**
     * The text with every border artefact removed — an open box's wall read as a character against the
     * writing (layout 3, `D98`: a number or a phone written in an open box, up against its left edge).
     */
    public static function withoutBorderArtefacts(string $text): string
    {
        return str_replace(self::BORDER_ARTEFACTS, '', $text);
    }
}
