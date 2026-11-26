<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The app icons — an "iC" monogram for iConnect Collection (M252, D236)
|--------------------------------------------------------------------------
|
| Writes public/icons/{icon-192,icon-512,icon-maskable-512,apple-touch-icon-180}.png and public/favicon.ico.
| The icons used to be an "M" for Meridian, made by hand with nothing in the repository able to make them again;
| this script is the source now, so the artwork can be rebuilt or recoloured by changing a constant here.
|
| ⚠️ DRAWN FROM SHAPES, NOT A FONT. A font file would make the output depend on the machine that ran it (GD needs
| a TTF, and the design system ships only woff2). The "i" is a stem and a round dot; the "C" is a ring with its
| right side cut away. Each image is drawn at four times its size and scaled down, which is GD's only way to
| smooth the edges of filled shapes.
|
| The colours are the old icon's, measured: the teal ground #1B5E5E and the off-white letters #F3F4F1.
| A maskable icon keeps its mark inside the centre 80% circle (the W3C safe zone), so it is drawn smaller.
|
| Usage: php scripts/generate-app-icons.php            (needs the GD extension; changes only those five files)
*/

const ICON_BACKGROUND = [0x1B, 0x5E, 0x5E];
const ICON_FOREGROUND = [0xF3, 0xF4, 0xF1];
const SUPERSAMPLE = 4;

if (! extension_loaded('gd')) {
    fwrite(STDERR, "generate-app-icons: the GD extension is not loaded.\n");
    exit(2);
}

$root = dirname(__DIR__);

/**
 * The monogram on a square of `$size` pixels, the mark occupying `$scale` of the side.
 */
function draw_icon(int $size, float $scale): GdImage
{
    $big = $size * SUPERSAMPLE;
    $canvas = imagecreatetruecolor($big, $big);
    $bg = imagecolorallocate($canvas, ...ICON_BACKGROUND);
    $fg = imagecolorallocate($canvas, ...ICON_FOREGROUND);
    imagefilledrectangle($canvas, 0, 0, $big - 1, $big - 1, $bg);

    // The mark's box: a square of $scale × the side, centred. Everything below is a fraction of it.
    $box = $big * $scale;
    $left = ($big - $box) / 2;
    $top = ($big - $box) / 2;
    $stroke = $box * 0.17;

    // The "i": a stem from 36% to 100% of the box's height, and a round dot above it.
    $stemLeft = $left + $box * 0.02;
    imagefilledrectangle(
        $canvas,
        (int) round($stemLeft),
        (int) round($top + $box * 0.36),
        (int) round($stemLeft + $stroke),
        (int) round($top + $box),
        $fg,
    );
    $dot = $stroke * 1.15;
    imagefilledellipse(
        $canvas,
        (int) round($stemLeft + $stroke / 2),
        (int) round($top + $box * 0.17),
        (int) round($dot),
        (int) round($dot),
        $fg,
    );

    // The "C": a ring as tall as the box, to the right of the "i", with its right side cut away.
    $ringDiameter = $box * 0.74;
    $ringCx = $left + $box - $ringDiameter / 2;
    $ringCy = $top + $box * 0.5;
    imagefilledellipse($canvas, (int) round($ringCx), (int) round($ringCy), (int) round($ringDiameter), (int) round($box), $fg);
    imagefilledellipse(
        $canvas,
        (int) round($ringCx),
        (int) round($ringCy),
        (int) round($ringDiameter - 2 * $stroke),
        (int) round($box - 2 * $stroke),
        $bg,
    );
    // The opening: a wedge from the ring's centre through its right side, ±40° about the horizontal.
    $reach = $box;
    $angle = deg2rad(40);
    imagefilledpolygon($canvas, [
        (int) round($ringCx), (int) round($ringCy),
        (int) round($ringCx + $reach * cos($angle)), (int) round($ringCy - $reach * sin($angle)),
        (int) round($ringCx + $reach * cos($angle)), (int) round($ringCy + $reach * sin($angle)),
    ], $bg);

    $out = imagecreatetruecolor($size, $size);
    imagecopyresampled($out, $canvas, 0, 0, 0, 0, $size, $size, $big, $big);
    imagedestroy($canvas);

    return $out;
}

function png_bytes(GdImage $image): string
{
    ob_start();
    imagepng($image, null, 9);

    return (string) ob_get_clean();
}

/**
 * A .ico holding PNG images (every current browser reads PNG-in-ICO): a 6-byte header, a 16-byte entry per image,
 * then the images.
 *
 * @param  array<int, string>  $pngs  size => PNG bytes
 */
function ico_bytes(array $pngs): string
{
    $header = pack('vvv', 0, 1, count($pngs));
    $entries = '';
    $data = '';
    $offset = 6 + 16 * count($pngs);

    foreach ($pngs as $size => $png) {
        $dimension = $size >= 256 ? 0 : $size;
        $entries .= pack('CCCCvvVV', $dimension, $dimension, 0, 0, 1, 32, strlen($png), $offset + strlen($data));
        $data .= $png;
    }

    return $header.$entries.$data;
}

$targets = [
    'public/icons/icon-192.png' => [192, 0.56],
    'public/icons/icon-512.png' => [512, 0.56],
    'public/icons/apple-touch-icon-180.png' => [180, 0.56],
    // Inside the safe zone: the mark's corners stay within the centre 80% circle.
    'public/icons/icon-maskable-512.png' => [512, 0.44],
];

foreach ($targets as $path => [$size, $scale]) {
    $image = draw_icon($size, $scale);
    file_put_contents($root.'/'.$path, png_bytes($image));
    imagedestroy($image);
    echo "wrote {$path} ({$size}x{$size})\n";
}

$favicons = [];
foreach ([16, 32, 48] as $size) {
    $image = draw_icon($size, 0.64);
    $favicons[$size] = png_bytes($image);
    imagedestroy($image);
}
file_put_contents($root.'/public/favicon.ico', ico_bytes($favicons));
echo "wrote public/favicon.ico (16, 32, 48)\n";
