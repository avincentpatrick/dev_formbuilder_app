<?php

declare(strict_types=1);

namespace App\Support\Branding;

/**
 * sRGB ⇄ OKLCh conversion and WCAG 2.x contrast, for the tenant brand-ramp engine (ADR-0014, H23a1).
 *
 * This class is the PHP half of a DUAL-ENGINE pair — `packages/design-system/src/theme/brand-ramp.ts`
 * is the other half, and `tests/fixtures/brand-ramp.json` is the corpus that holds the two byte-identical.
 * That makes float-op choice a CORRECTNESS concern here, not a style one. Two rules follow, and neither is
 * cosmetic:
 *
 *  1. **The cube root is `pow($x, 1/3)`, never a dedicated cbrt.** JavaScript has `Math.cbrt`, which is
 *     MORE accurate than `Math.pow(x, 1/3)` — and that is exactly the problem: PHP has no cbrt, so the two
 *     engines would be computing the same quantity by two different routes and agreeing only to within a
 *     few ulp. The TS twin therefore also spells it `x ** (1/3)`. The inputs are always non-negative (the
 *     LMS matrix has all-positive coefficients and linear sRGB is non-negative), so the negative-argument
 *     hazard that usually motivates cbrt does not arise.
 *  2. **Rounding to 8 bits is the parity firewall.** Every value that crosses between the engines is a
 *     quantized `#RRGGBB`, never a float. A last-ulp disagreement inside the pipeline is invisible unless
 *     it straddles a rounding boundary, and the corpus is what stands over that residue. PHP's `round()`
 *     goes half-away-from-zero and JavaScript's `Math.round` goes half-up; the two agree on the
 *     non-negative domain this class clamps to, which is why the clamp precedes the round and not the
 *     other way round.
 *
 * The WCAG relative-luminance formula is reproduced here rather than imported because there is nothing to
 * import: no colour library exists in `composer.json` or `package.json`, and no `oklch()` appears anywhere
 * in the token set — every design-system value is a literal hex. {@see BrandRampGenerator} for why that is
 * a deliberate property and not an oversight.
 */
final class ColorSpace
{
    /**
     * WCAG 2.x sRGB→linear threshold. Note this is 0.04045 while the INVERSE transfer function's break is
     * at 0.0031308 — they are not the same number and swapping them is a silent ~1-byte error in the
     * darkest few steps, which is precisely where the light theme's fills live.
     */
    private const float SRGB_LINEAR_THRESHOLD = 0.04045;

    private const float LINEAR_SRGB_THRESHOLD = 0.0031308;

    /**
     * `#RRGGBB` → the three 8-bit channels.
     *
     * @return array{int, int, int}
     */
    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Three linear-light channels in [0,1] → a clamped, uppercase `#RRGGBB`.
     *
     * The clamp is what makes this total: the OKLab→sRGB matrix routinely produces out-of-range channels
     * for colours outside the sRGB gamut, and {@see self::oklchToHex()} reduces chroma to avoid that — but
     * floating-point slack can still leave a channel a hair outside, and a hair is enough to make
     * `dechex()` emit three characters.
     */
    public static function linearRgbToHex(float $r, float $g, float $b): string
    {
        $channel = static function (float $value): string {
            $encoded = $value <= self::LINEAR_SRGB_THRESHOLD
                ? $value * 12.92
                : 1.055 * $value ** (1 / 2.4) - 0.055;

            $byte = (int) round(max(0.0, min(1.0, $encoded)) * 255);

            return str_pad(strtoupper(dechex($byte)), 2, '0', STR_PAD_LEFT);
        };

        return '#'.$channel($r).$channel($g).$channel($b);
    }

    /** One 8-bit sRGB channel → linear light. */
    public static function channelToLinear(int $channel): float
    {
        $s = $channel / 255;

        return $s <= self::SRGB_LINEAR_THRESHOLD ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
    }

    /** WCAG 2.x relative luminance of a `#RRGGBB`. */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::hexToRgb($hex);

        return 0.2126 * self::channelToLinear($r)
            + 0.7152 * self::channelToLinear($g)
            + 0.0722 * self::channelToLinear($b);
    }

    /**
     * WCAG 2.x contrast ratio between two `#RRGGBB`, in [1, 21]. Symmetric — the lighter of the pair is
     * always the numerator, so callers never have to know which argument is the text.
     */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return ($la > $lb ? $la + 0.05 : $lb + 0.05) / ($la > $lb ? $lb + 0.05 : $la + 0.05);
    }

    /**
     * `#RRGGBB` → OKLCh as `[L, C, h]` with L in [0,1], C ≥ 0 and h in [0,360).
     *
     * For an achromatic input C is 0 and h is meaningless (`atan2(0, 0)` is 0 by definition in both
     * languages, so the two engines still agree) — the ramp for grey, black and white is therefore one and
     * the same grey ramp, which is correct and is pinned by three corpus vectors.
     *
     * @return array{float, float, float}
     */
    public static function hexToOklch(string $hex): array
    {
        [$r8, $g8, $b8] = self::hexToRgb($hex);
        $r = self::channelToLinear($r8);
        $g = self::channelToLinear($g8);
        $b = self::channelToLinear($b8);

        // See the class docblock: `** (1/3)`, never a cbrt, so the TS twin can spell it the same way.
        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);

        $okL = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $okA = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $okB = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        $hue = rad2deg(atan2($okB, $okA));

        return [$okL, hypot($okA, $okB), fmod($hue + 360, 360)];
    }

    /**
     * OKLCh → an in-gamut sRGB `#RRGGBB`, reducing CHROMA (holding L and hue) until the colour fits.
     *
     * Holding lightness is the whole point: the generator has chosen this L precisely because it produces
     * the contrast ratio the role requires, so a gamut-mapping strategy that moved L — clipping in RGB
     * does exactly that — would silently invalidate the very property the search was solving for. Hue is
     * held because it is the only thing the tenant actually chose. Chroma is what is left, and giving it
     * up is what "snapping" means (ADR-0014 §D3).
     *
     * The bisection runs a FIXED 32 iterations rather than to a tolerance: a fixed trip count is
     * trivially identical in both engines, where a `while (hi - lo > eps)` loop can take a different
     * number of steps on the two platforms and land on different sides of a rounding boundary.
     */
    public static function oklchToHex(float $l, float $c, float $h): string
    {
        if (! self::inGamut($l, $c, $h)) {
            $lo = 0.0;
            $hi = $c;

            for ($i = 0; $i < 32; $i++) {
                $mid = ($lo + $hi) / 2;

                if (self::inGamut($l, $mid, $h)) {
                    $lo = $mid;
                } else {
                    $hi = $mid;
                }
            }

            $c = $lo;
        }

        [$r, $g, $b] = self::oklchToLinearRgb($l, $c, $h);

        return self::linearRgbToHex($r, $g, $b);
    }

    /**
     * OKLCh → raw linear sRGB, WITHOUT clamping — the un-clamped channels are the gamut test itself.
     *
     * @return array{float, float, float}
     */
    private static function oklchToLinearRgb(float $l, float $c, float $h): array
    {
        $rad = deg2rad($h);
        $okA = $c * cos($rad);
        $okB = $c * sin($rad);

        $lCube = ($l + 0.3963377774 * $okA + 0.2158037573 * $okB) ** 3;
        $mCube = ($l - 0.1055613458 * $okA - 0.0638541728 * $okB) ** 3;
        $sCube = ($l - 0.0894841775 * $okA - 1.2914855480 * $okB) ** 3;

        return [
            4.0767416621 * $lCube - 3.3077115913 * $mCube + 0.2309699292 * $sCube,
            -1.2684380046 * $lCube + 2.6097574011 * $mCube - 0.3413193965 * $sCube,
            -0.0041960863 * $lCube - 0.7034186147 * $mCube + 1.7076147010 * $sCube,
        ];
    }

    /** Whether this OKLCh triple lands inside sRGB, with a hair of slack for float error. */
    private static function inGamut(float $l, float $c, float $h): bool
    {
        foreach (self::oklchToLinearRgb($l, $c, $h) as $channel) {
            if ($channel < -0.0001 || $channel > 1.0001) {
                return false;
            }
        }

        return true;
    }
}
