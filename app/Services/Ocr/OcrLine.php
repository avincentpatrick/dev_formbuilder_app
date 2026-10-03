<?php

declare(strict_types=1);

namespace App\Services\Ocr;

/**
 * One visual line of a page (M128): words that sit at the same height once the page is deskewed, sorted
 * left to right. Built by {@see OcrLineBuilder} from geometry alone, because the printed form puts a
 * question's label at the left margin and its key stamp at the right one, and only geometry knows those
 * two belong together.
 */
final readonly class OcrLine
{
    /**
     * @param  list<OcrWord>  $words  deskewed, left to right
     */
    public function __construct(
        public int $page,
        public int $index,
        public array $words,
    ) {}

    public function text(): string
    {
        return implode(' ', array_map(static fn (OcrWord $w): string => $w->text, $this->words));
    }

    public function centerY(): float
    {
        $sum = 0.0;
        foreach ($this->words as $word) {
            $sum += $word->centerY();
        }

        return $this->words === [] ? 0.0 : $sum / count($this->words);
    }

    public function top(): float
    {
        return $this->words === [] ? 0.0 : min(array_map(static fn (OcrWord $w): float => $w->y0, $this->words));
    }

    /**
     * Whether this line comes before `$other` in reading order: an earlier page, or the same page and a
     * smaller line index.
     */
    public function before(self $other): bool
    {
        return $this->page < $other->page || ($this->page === $other->page && $this->index < $other->index);
    }
}
