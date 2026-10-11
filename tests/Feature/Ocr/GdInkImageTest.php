<?php

declare(strict_types=1);

use App\Services\Ocr\GdInkImage;

/*
|--------------------------------------------------------------------------
| M160 — the page image the ink reader measures, decoded with GD.
|--------------------------------------------------------------------------
| ⛔ THIS FILE NEEDS GD, AND DELIBERATELY DOES NOT SKIP WITHOUT IT. The reading job opens every photographed page through
| this class once `gd` is on the server (`D113`); a skip would let a runner without GD report this file green while it
| measured nothing. The app image and CI install `gd` for it.
|
| ⚠️ Helpers are prefixed `ocrGd*`: Pest loads every test file into one process.
*/

/** A 40×30 PNG: white, with a black 10×10 square at (5, 5) and a mid-grey 4×5 one at (30, 20). */
function ocrGdPng(): string
{
    $image = imagecreatetruecolor(40, 30);
    imagefilledrectangle($image, 0, 0, 39, 29, (int) imagecolorallocate($image, 255, 255, 255));
    imagefilledrectangle($image, 5, 5, 14, 14, (int) imagecolorallocate($image, 0, 0, 0));
    imagefilledrectangle($image, 30, 20, 33, 24, (int) imagecolorallocate($image, 128, 128, 128));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('counts the greys in a rectangle of the page, clipped to the page', function (): void {
    $page = GdInkImage::fromBytes(ocrGdPng());

    expect($page)->not->toBeNull()
        ->and($page->width())->toBe(40)
        ->and($page->height())->toBe(30);

    $whole = $page->histogram(0, 0, 40, 30);
    expect(array_sum($whole))->toBe(1200)
        ->and($whole[0])->toBe(100)
        ->and($whole[128])->toBe(20)
        ->and($whole[255])->toBe(1080)
        // Clipped: half of the black square, and nothing outside the page.
        ->and($page->histogram(-10, -10, 10, 40)[0])->toBe(50)
        ->and(array_sum($page->histogram(-10, -10, 10, 40)))->toBe(300);
});

it('decodes nothing it cannot, and refuses a page too large to decode safely before it tries', function (): void {
    // A PNG header declaring 8000 × 6000 pixels — 48 million, over the cap — with no image after it: refused from the
    // header alone, never handed to the decoder.
    $header = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 8000, 6000)."\x08\x02\x00\x00\x00".pack('N', 0);

    expect(GdInkImage::fromBytes(''))->toBeNull()
        ->and(GdInkImage::fromBytes('not an image'))->toBeNull()
        ->and(GdInkImage::fromBytes($header))->toBeNull()
        ->and(8000 * 6000)->toBeGreaterThan(GdInkImage::MAX_PIXELS);
});
