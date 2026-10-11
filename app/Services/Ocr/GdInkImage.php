<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use GdImage;

/**
 * A page image decoded with GD (M159), for {@see OcrInkReader}. GD does not apply a photo's EXIF orientation, which is
 * what is wanted: the provider's word boxes are in the stored frame too.
 *
 * ⚠️ GD IS ON THE MACHINE THAT RUNS `ocr:bakeoff`, NOT IN THE APP IMAGE. {@see open()} answers null without it, and the
 * harness says so; putting the ink on the reading path adds GD to the image first (`ocr-ink-live`).
 */
class GdInkImage implements OcrInkImage
{
    /**
     * The most pixels a page may have to be decoded (M160): GD holds four bytes a pixel, so a 16-megapixel phone photo peaks
     * at 72 MB (measured on Round 1's) and this cap at about 160 MB — inside the reading job's raised limit, never the
     * worker's 128M. A larger page is not measured, and its blank ticks stay for the reviewer.
     */
    public const int MAX_PIXELS = 40_000_000;

    private function __construct(private readonly GdImage $image) {}

    /** The image in the file at `$path`, or null when GD is missing or cannot decode it (a PDF page has no file of its own). */
    public static function open(string $path): ?self
    {
        $bytes = @file_get_contents($path);

        return $bytes === false ? null : self::fromBytes($bytes);
    }

    /** The image these bytes encode, or null when GD is missing, cannot decode them, or the page exceeds {@see MAX_PIXELS}. */
    public static function fromBytes(string $bytes): ?self
    {
        if ($bytes === '' || ! function_exists('imagecreatefromstring')) {
            return null;
        }
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);

        return $image instanceof GdImage ? new self($image) : null;
    }

    public function width(): int
    {
        return imagesx($this->image);
    }

    public function height(): int
    {
        return imagesy($this->image);
    }

    public function histogram(int $x0, int $y0, int $x1, int $y1): array
    {
        $counts = array_fill(0, 256, 0);
        $x0 = max(0, $x0);
        $y0 = max(0, $y0);
        $x1 = min($this->width(), $x1);
        $y1 = min($this->height(), $y1);
        $palette = ! imageistruecolor($this->image);

        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                $pixel = imagecolorat($this->image, $x, $y);
                if ($pixel === false) {
                    continue;
                }
                if ($palette) {
                    $rgb = imagecolorsforindex($this->image, $pixel);
                    [$r, $g, $b] = [$rgb['red'], $rgb['green'], $rgb['blue']];
                } else {
                    [$r, $g, $b] = [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF];
                }
                // ITU-R 601 luma in fixed point, rounded — the grey every image tool shows for a colour pixel.
                $counts[($r * 19595 + $g * 38470 + $b * 7471 + 0x8000) >> 16]++;
            }
        }

        return $counts;
    }
}
