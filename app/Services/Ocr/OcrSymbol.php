<?php

declare(strict_types=1);

namespace App\Services\Ocr;

/**
 * One recognized character (M128). Coordinates are the axis-aligned box of what the provider returned,
 * normalised to the page: 0–1 across and 0–1 down, whatever the provider's own units were.
 */
final readonly class OcrSymbol
{
    public function __construct(
        public string $text,
        public float $confidence,
        public float $x0,
        public float $y0,
        public float $x1,
        public float $y1,
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
