<?php

declare(strict_types=1);

namespace App\Services\Ocr;

/**
 * One page as the provider read it (M128), provider-neutral: the words on it, and the page's size in the
 * provider's own units. Pixels for a photo, points for a PDF; only the RATIO is used, to measure angles
 * and distances in the page's real proportions rather than in a unit square.
 */
final readonly class OcrPage
{
    /**
     * @param  list<OcrWord>  $words
     */
    public function __construct(
        public float $width,
        public float $height,
        public array $words,
    ) {}

    /** Width over height, guarded so a malformed page cannot divide by zero downstream. */
    public function aspect(): float
    {
        return $this->height > 0.0 && $this->width > 0.0 ? $this->width / $this->height : 1.0;
    }
}
