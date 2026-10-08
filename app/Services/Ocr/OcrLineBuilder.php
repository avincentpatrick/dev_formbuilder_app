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
 * ── FIRST, WHOLE QUARTER TURNS (M149) ───────────────────────────────────────────────────────────────
 * A phone keeps a portrait photo on its side and records the turn only in EXIF, which the provider's answer
 * does not carry, so the boxes arrive in the stored frame: all thirty of the bake-off's pages read with their
 * words at about −90°, and the matcher found no question on any of them. {@see upright()} reads the turn off
 * the words themselves and turns the page by whole quarters before anything else — a sideways or upside-down
 * page is a different frame, not a large tilt, so it is mapped exactly rather than rotated about the centre.
 *
 * ⚠️ WHAT IS LEFT AFTER THE TURN BEYOND {@see MAX_DESKEW} IS NOT CORRECTED. A page photographed at 40° is
 * neither level nor a quarter turn; the matcher then finds no anchors and says so per field.
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
        $page = $this->upright($page);
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

    /**
     * The page turned by whole quarter turns until its text runs left to right (M149): every word and character box
     * mapped into the turned frame, each baseline turned with it, and the page's sides swapped on an odd turn.
     */
    public function upright(OcrPage $page): OcrPage
    {
        $turns = $this->quarterTurns($page);
        if ($turns === 0) {
            return $page;
        }

        $map = match ($turns) {
            1 => static fn (float $x, float $y): array => [1.0 - $y, $x],
            2 => static fn (float $x, float $y): array => [1.0 - $x, 1.0 - $y],
            default => static fn (float $x, float $y): array => [$y, 1.0 - $x],
        };
        $turn = $turns * M_PI / 2;

        $words = array_map(function (OcrWord $w) use ($map, $turn): OcrWord {
            [$x0, $y0, $x1, $y1] = $this->turnBox($map, $w->x0, $w->y0, $w->x1, $w->y1);
            $symbols = array_map(function (OcrSymbol $s) use ($map): OcrSymbol {
                [$a, $b, $c, $d] = $this->turnBox($map, $s->x0, $s->y0, $s->x1, $s->y1);

                return new OcrSymbol($s->text, $s->confidence, $a, $b, $c, $d);
            }, $w->symbols);

            return new OcrWord($w->text, $w->confidence, $x0, $y0, $x1, $y1, $this->wrap($w->angle + $turn), $symbols);
        }, $page->words);

        return $turns % 2 === 1
            ? new OcrPage($page->height, $page->width, $words)
            : new OcrPage($page->width, $page->height, $words);
    }

    /**
     * How many quarter turns clockwise bring the page's text level, 0 to 3. Each multi-character word votes for the
     * quarter nearest its baseline and the commonest vote wins, a level page on a tie. A vote rather than a median:
     * an upside-down page's angles straddle +π and −π, and their median says nothing.
     */
    public function quarterTurns(OcrPage $page): int
    {
        $votes = [0, 0, 0, 0];
        foreach ($page->words as $word) {
            if (mb_strlen($word->text) >= 3) {
                $votes[(((int) round(-$word->angle / (M_PI / 2))) % 4 + 4) % 4]++;
            }
        }

        $best = 0;
        foreach ($votes as $turns => $count) {
            if ($count > $votes[$best]) {
                $best = $turns;
            }
        }

        return $best;
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

    /**
     * An axis-aligned box through a quarter-turn map: both corners mapped, then ordered again.
     *
     * @param  callable(float, float): array{0: float, 1: float}  $map
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function turnBox(callable $map, float $x0, float $y0, float $x1, float $y1): array
    {
        [$ax, $ay] = $map($x0, $y0);
        [$bx, $by] = $map($x1, $y1);

        return [min($ax, $bx), min($ay, $by), max($ax, $bx), max($ay, $by)];
    }

    /** An angle brought into (−π, π]. */
    private function wrap(float $angle): float
    {
        $angle = fmod($angle + M_PI, 2 * M_PI);

        return ($angle <= 0.0 ? $angle + 2 * M_PI : $angle) - M_PI;
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
