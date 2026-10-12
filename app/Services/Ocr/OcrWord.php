<?php

declare(strict_types=1);

namespace App\Services\Ocr;

/**
 * One recognized word (M128): its characters, the axis-aligned box around them normalised to the page
 * (0–1 both ways), and the angle of its baseline in radians, measured in the page's real proportions.
 * The angle is what deskews a photographed page; the box is what places the word on the printed layout.
 *
 * `source` is the word as the provider returned it, before {@see OcrLineBuilder} turned the page upright and
 * deskewed it (M159): its box is in the photo's own stored frame, which is where the ink in a printed box is
 * measured. Null on a word that was never moved — it is its own source.
 */
final readonly class OcrWord
{
    /**
     * @param  list<OcrSymbol>  $symbols
     */
    public function __construct(
        public string $text,
        public float $confidence,
        public float $x0,
        public float $y0,
        public float $x1,
        public float $y1,
        public float $angle,
        public array $symbols,
        public ?OcrWord $source = null,
    ) {}

    public function centerX(): float
    {
        return ($this->x0 + $this->x1) / 2;
    }

    public function centerY(): float
    {
        return ($this->y0 + $this->y1) / 2;
    }
}
