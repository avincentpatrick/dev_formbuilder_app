<?php

declare(strict_types=1);

namespace App\Services\Ocr;

/**
 * One recognized word (M128): its characters, the axis-aligned box around them normalised to the page
 * (0–1 both ways), and the angle of its baseline in radians, measured in the page's real proportions.
 * The angle is what deskews a photographed page; the box is what places the word on the printed layout.
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
