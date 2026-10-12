<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Enums\FieldType;
use App\Services\Expressions\Coercion;

/**
 * Reads a tick from the ink in its printed box (M159, `D112`) — for the ticks a text recognizer never returns. Cloud Vision
 * gave back no mark at all for 20 of Round 1's 22 tick questions read blank: it reads "Male" and "Yes" and leaves the box
 * before them empty, because a tick is not a letter.
 *
 * ── WHERE THE BOX IS ───────────────────────────────────────────────────────────────────────────────────────
 * The paper prints each option as a box, then its label. The label is found by the reader ({@see OcrAnswerReader}, from
 * Vision's words), so the box is the stretch just before the label's first letter, along the line. Everything is sized by
 * the PAGE's letter height — the median height of the option labels' letters — never by one label's own box: a tick that
 * touches a label makes Vision's box round that word grow, and a window sized from it misses the box (every miss of the
 * first prototype). Measured in the photo's stored frame, where the provider's boxes are.
 *
 * ── WHAT COUNTS AS INK ─────────────────────────────────────────────────────────────────────────────────────
 * Pixels darker than a share of the window's own paper grey (a high percentile of the window), so a shadow across the page
 * moves the bar with it. A phone photo blurs a thin printed wall to grey, so the walls are mostly not counted and a pen
 * stroke is; finding the walls themselves was tried and abandoned for that reason.
 *
 * ── WHEN A BOX IS TICKED ───────────────────────────────────────────────────────────────────────────────────
 * Against the page's own median box — most boxes on a page are empty, so that is what an empty box looks like in this
 * photo's light. A one-answer question takes its darkest box only when it clears the median and the next box by a margin,
 * and otherwise says nothing: a tick it cannot tell apart stays blank, for a reviewer, rather than guessed. A multi-select
 * takes every box clear of the median.
 *
 * Measured on Round 1's fifteen phone-photographed forms (`ocr-ink-ticks`, docs/ocr-pipeline-design.md §9) — the settings
 * in `config/ocr.php` were tuned on those same forms, so the first fresh scans are the real test. Every answer is offered
 * for review ({@see OcrAnswerReader::MARK_SEEN}), never accepted on the ink alone.
 */
final class OcrInkReader
{
    /** The reading direction of a page's text in its stored frame, by how many quarter turns bring it upright. */
    private const array DIRECTIONS = [0 => [1, 0], 1 => [0, -1], 2 => [-1, 0], 3 => [0, 1]];

    /**
     * @param  array{window_from: float, window_to: float, across: float, paper_quantile: float, dark_share: float, margin: float, single_floor: float, multi_floor: float}  $settings
     *                                                                                                                                                                                  letter heights and shares of grey; see `config/ocr.php`
     */
    public function __construct(private readonly array $settings) {}

    public static function fromConfig(): self
    {
        /** @var array{window_from: float, window_to: float, across: float, paper_quantile: float, dark_share: float, margin: float, single_floor: float, multi_floor: float} $settings */
        $settings = array_map('floatval', (array) config('ocr.ink'));

        return new self($settings);
    }

    /**
     * Every tick question on ONE page, read from the ink before its options' labels. A question absent from the answer
     * had no label this page could measure.
     *
     * @param  array<string, array{type: FieldType|null, options: list<array{value: string, label: string, word: OcrWord}>}>  $questions
     *                                                                                                                                    key => the question, each option's label word as the reader found it
     * @param  int  $quarterTurns  {@see OcrLineBuilder::quarterTurns()} of the page as the provider returned it
     * @return array<string, array{state: string, value: mixed, text: string|null, confidence: int|null, ink: array<string, float>}>
     */
    public function read(array $questions, OcrInkImage $image, int $quarterTurns): array
    {
        [$dx, $dy] = self::DIRECTIONS[(($quarterTurns % 4) + 4) % 4];
        $width = $image->width();
        $height = $image->height();

        // Each option's letters in pixels, and the page's letter height from all of them.
        $letters = [];
        $sizes = [];
        foreach ($questions as $key => $question) {
            foreach ($question['options'] as $o => $option) {
                $boxes = $this->letters($option['word'], $option['label'], $width, $height);
                if ($boxes === []) {
                    continue;
                }
                $letters[$key][$o] = $boxes;
                $sizes[] = $this->median(array_map(static fn (array $b): float => $dx !== 0 ? $b[3] - $b[1] : $b[2] - $b[0], $boxes));
            }
        }
        if ($sizes === []) {
            return [];
        }
        $size = $this->median($sizes);

        $ink = [];
        foreach ($letters as $key => $options) {
            foreach ($options as $o => $boxes) {
                $ink[$key][$o] = $this->inkBefore($image, $boxes, $dx, $dy, $size);
            }
        }
        $paper = $this->median(array_merge(...array_values(array_map('array_values', $ink))));

        $out = [];
        foreach ($ink as $key => $byOption) {
            $question = $questions[$key];
            $out[$key] = $this->decide($question['type'], $question['options'], $byOption, $paper);
        }

        return $out;
    }

    /**
     * @param  list<array{value: string, label: string, word: OcrWord}>  $options
     * @param  array<int, float>  $ink  option index => its ink share
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null, ink: array<string, float>}
     */
    private function decide(?FieldType $type, array $options, array $ink, float $paper): array
    {
        $measured = [];
        foreach ($ink as $o => $share) {
            $measured[$options[$o]['value']] = round($share, 4);
        }

        $over = array_map(static fn (float $share): float => $share - $paper, $ink);
        arsort($over);
        if ($over === []) {
            return ['state' => 'blank', 'value' => null, 'text' => null, 'confidence' => null, 'ink' => $measured];
        }
        $ticked = [];
        if ($type === FieldType::MultiSelect) {
            $ticked = array_keys(array_filter($over, fn (float $excess): bool => $excess >= $this->settings['multi_floor']));
            sort($ticked);
        } else {
            $ranked = array_values($over);
            $best = array_key_first($over);
            $clears = $ranked[0] >= $this->settings['single_floor'] && (count($ranked) < 2 || $this->settings['margin'] <= $ranked[0] - $ranked[1]);
            if ($clears) {
                $ticked = [$best];
            }
        }

        if ($ticked === []) {
            return ['state' => 'blank', 'value' => null, 'text' => null, 'confidence' => null, 'ink' => $measured];
        }

        $values = array_map(static fn (int $o): string => $options[$o]['value'], $ticked);
        $text = implode(', ', array_map(static fn (int $o): string => $options[$o]['label'], $ticked));
        $value = match (true) {
            $type === FieldType::MultiSelect => $values,
            $type === FieldType::YesNo => Coercion::yesNoAnswer($values[0]),
            default => $values[0],
        };

        return ['state' => 'read', 'value' => $value, 'text' => $text, 'confidence' => OcrAnswerReader::MARK_SEEN, 'ink' => $measured];
    }

    /**
     * The label's letters in pixels, in the stored frame, without a mark the recognizer ran into the word ("☑No", "XFemale").
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    private function letters(OcrWord $word, string $label, int $width, int $height): array
    {
        $source = $word->source ?? $word;
        $symbols = $source->symbols;
        while ($symbols !== [] && OcrText::isMark($symbols[0]->text)) {
            $rest = implode('', array_map(static fn (OcrSymbol $s): string => $s->text, array_slice($symbols, 1)));
            $whole = implode('', array_map(static fn (OcrSymbol $s): string => $s->text, $symbols));
            // A letter mark is dropped only when the word reads as the label better without it — "Very" keeps its V.
            if (! OcrText::isSymbolMark($symbols[0]->text) && OcrText::similarity($rest, $label) <= OcrText::similarity($whole, $label)) {
                break;
            }
            array_shift($symbols);
        }

        return array_map(static fn (OcrSymbol $s): array => [$s->x0 * $width, $s->y0 * $height, $s->x1 * $width, $s->y1 * $height], $symbols);
    }

    /**
     * The share of ink in the window before a label's first letter, along its line.
     *
     * @param  list<array{0: float, 1: float, 2: float, 3: float}>  $boxes  the label's letters, in reading order
     */
    private function inkBefore(OcrInkImage $image, array $boxes, int $dx, int $dy, float $size): float
    {
        $first = $boxes[0];
        $from = $this->settings['window_from'] * $size;
        $to = $this->settings['window_to'] * $size;
        $half = $this->settings['across'] * $size;

        if ($dx !== 0) {
            $across = $this->median(array_map(static fn (array $b): float => ($b[1] + $b[3]) / 2, $boxes));
            $start = $dx > 0 ? $first[0] : $first[2];
            [$a, $b] = [$start - $dx * $from, $start - $dx * $to];
            $window = [min($a, $b), $across - $half, max($a, $b), $across + $half];
        } else {
            $across = $this->median(array_map(static fn (array $b): float => ($b[0] + $b[2]) / 2, $boxes));
            $start = $dy > 0 ? $first[1] : $first[3];
            [$a, $b] = [$start - $dy * $from, $start - $dy * $to];
            $window = [$across - $half, min($a, $b), $across + $half, max($a, $b)];
        }

        $counts = $image->histogram((int) $window[0], (int) $window[1], (int) $window[2], (int) $window[3]);
        $total = array_sum($counts);
        if ($total === 0) {
            return 0.0;
        }

        // The paper's grey: the darkest grey with the given share of the window at or below it.
        $paper = 255;
        $running = 0;
        foreach ($counts as $grey => $count) {
            $running += $count;
            if ($running >= $this->settings['paper_quantile'] * $total) {
                $paper = $grey;
                break;
            }
        }

        return array_sum(array_slice($counts, 0, (int) ($paper * $this->settings['dark_share']))) / $total;
    }

    /** @param  list<float>  $values */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
