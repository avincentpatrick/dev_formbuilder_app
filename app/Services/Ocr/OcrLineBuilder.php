<?php

declare(strict_types=1);

namespace App\Services\Ocr;

/**
 * Deskews a page and groups its words into visual lines (M128).
 *
 * ── DESKEW, BECAUSE A PHONE PHOTO IS NEVER LEVEL ────────────────────────────────────────────────────
 * The provider reports each word's baseline angle. The page's tilt is the MEDIAN angle of the words long
 * enough to have a reliable one (three characters or more — a single character's box is square and says
 * nothing about direction). Every word and character is then rotated back by that angle about the page's
 * centre, in the page's REAL proportions, so "the same line" means the same height again.
 *
 * ⚠️ A TILT BEYOND {@see MAX_DESKEW} IS NOT CORRECTED. Past that the page is sideways or upside down, a
 * case the provider's own orientation handling covers for its text but which no amount of rotating boxes
 * here makes into the printed layout; the matcher then finds no anchors and says so per field.
 *
 * ── LINES ARE CLUSTERS BY HEIGHT ────────────────────────────────────────────────────────────────────
 * Words are taken top to bottom; a word joins the current line when its centre is within half a median
 * word height of the line's running centre, and otherwise starts a new one. That keeps a comb row and the
 * DD/MM/YYYY captions printed under it as two lines, which the date reader relies on.
 */
final class OcrLineBuilder
{
    /** About 17°. A camera held badly; not a page turned on its side. */
    public const float MAX_DESKEW = 0.3;

    /**
     * @return list<OcrLine>
     */
    public function lines(OcrPage $page, int $pageIndex): array
    {
        $aspect = $page->aspect();
        $angle = $this->tilt($page);
        $words = array_map(fn (OcrWord $w): OcrWord => $this->rotate($w, -$angle, $aspect), $page->words);

        usort($words, static fn (OcrWord $a, OcrWord $b): int => $a->centerY() <=> $b->centerY());

        $heights = array_map(static fn (OcrWord $w): float => $w->y1 - $w->y0, $words);
        $tolerance = 0.5 * max($this->median($heights), 0.002);

        $lines = [];
        $current = [];
        $currentY = 0.0;
        foreach ($words as $word) {
            if ($current !== [] && abs($word->centerY() - $currentY) > $tolerance) {
                $lines[] = $current;
                $current = [];
            }
            $current[] = $word;
            $currentY = array_sum(array_map(static fn (OcrWord $w): float => $w->centerY(), $current)) / count($current);
        }
        if ($current !== []) {
            $lines[] = $current;
        }

        $out = [];
        foreach ($lines as $index => $line) {
            usort($line, static fn (OcrWord $a, OcrWord $b): int => $a->x0 <=> $b->x0);
            $out[] = new OcrLine($pageIndex, $index, $line);
        }

        return $out;
    }

    /** The page's tilt in radians: the median baseline angle of its multi-character words, or 0. */
    public function tilt(OcrPage $page): float
    {
        $angles = [];
        foreach ($page->words as $word) {
            if (mb_strlen($word->text) >= 3) {
                $angles[] = $word->angle;
            }
        }

        $angle = $this->median($angles);

        return abs($angle) <= self::MAX_DESKEW ? $angle : 0.0;
    }

    /**
     * Rotate a word and its characters about the page centre, in real proportions (x scaled by the aspect
     * ratio, so a unit across equals a unit down), keeping each box's size.
     */
    private function rotate(OcrWord $word, float $angle, float $aspect): OcrWord
    {
        if ($angle === 0.0) {
            return $word;
        }

        [$x0, $y0, $x1, $y1] = $this->rotateBox($word->x0, $word->y0, $word->x1, $word->y1, $angle, $aspect);
        $symbols = array_map(function (OcrSymbol $s) use ($angle, $aspect): OcrSymbol {
            [$a, $b, $c, $d] = $this->rotateBox($s->x0, $s->y0, $s->x1, $s->y1, $angle, $aspect);

            return new OcrSymbol($s->text, $s->confidence, $a, $b, $c, $d);
        }, $word->symbols);

        // `$angle` arrives already negated (the caller passes minus the tilt), so it is ADDED here.
        return new OcrWord($word->text, $word->confidence, $x0, $y0, $x1, $y1, $word->angle + $angle, $symbols);
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function rotateBox(float $x0, float $y0, float $x1, float $y1, float $angle, float $aspect): array
    {
        $cx = (($x0 + $x1) / 2 - 0.5) * $aspect;
        $cy = ($y0 + $y1) / 2 - 0.5;

        $rx = $cx * cos($angle) - $cy * sin($angle);
        $ry = $cx * sin($angle) + $cy * cos($angle);

        $nx = $rx / $aspect + 0.5;
        $ny = $ry + 0.5;
        $hw = ($x1 - $x0) / 2;
        $hh = ($y1 - $y0) / 2;

        return [$nx - $hw, $ny - $hh, $nx + $hw, $ny + $hh];
    }

    /** @param  list<float>  $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
