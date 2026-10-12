<?php

declare(strict_types=1);

namespace App\Services\Ocr;

/**
 * A scanned page's pixels as {@see OcrInkReader} needs them (M159): its size, and how many pixels of each grey there are
 * in a rectangle. The page is in its STORED frame — the image file as written, EXIF orientation not applied — which is the
 * frame the provider's word boxes are in.
 */
interface OcrInkImage
{
    public function width(): int;

    public function height(): int;

    /**
     * How many pixels of each grey, 0 (black) to 255 (white), lie in the rectangle from (`$x0`, `$y0`) up to but not
     * including (`$x1`, `$y1`), clipped to the page. Grey is the luma a JPEG viewer shows (ITU-R 601).
     *
     * @return array<int, int> a count for every grey, keyed by the grey, 0 to 255 in order
     */
    public function histogram(int $x0, int $y0, int $x1, int $y1): array;
}
