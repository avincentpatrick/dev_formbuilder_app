<?php

declare(strict_types=1);

namespace App\Support\Branding;

use App\Exceptions\Branding\BrandRampException;

/**
 * Turns one tenant-chosen `#RRGGBB` into a twelve-token brand ramp that satisfies every
 * design-system-reference.md §4.1 pairing in both themes (ADR-0014, H23a1).
 *
 * ── WHY THIS IS A GENERATOR AND NOT A VALIDATOR ────────────────────────────────────────────────────
 * A brand hex is not a token. The Teal accent — the one hue the design system already lets a *user*
 * choose — needed SIX tokens per theme and carries SEVENTEEN measured pairings in §4.1. So "validate the
 * tenant's colour" is not a thing that can be done: there is nothing to validate until eleven other
 * colours exist, and those are what determine whether the answer is accessible. This class derives them.
 *
 * ── WHAT THE TENANT ACTUALLY CONTROLS ──────────────────────────────────────────────────────────────
 * Hue, and chroma up to a cap. **Lightness is discarded and re-derived per role**, because lightness IS
 * contrast — holding the tenant's L would be holding the one property that decides whether white button
 * text is legible. A consequence worth stating plainly, because the admin UI (H23a2) has to disclose it:
 * `#FF0000` and `#8B0000` differ only in lightness and chroma, so they produce near-identical ramps, and
 * every achromatic input — `#000000`, `#FFFFFF`, `#808080` — produces the SAME grey ramp. Three corpus
 * vectors pin that.
 *
 * ── WHY IT CAN ALWAYS SUCCEED (the "snap" in B-snap) ───────────────────────────────────────────────
 * Contrast against a fixed ground is monotone in lightness, and the search sweeps the entire lightness
 * range at a gamut-mapped chroma, so a compliant answer exists for every hue. Where the requested chroma
 * cannot survive at the required lightness, {@see ColorSpace::oklchToHex()} gives up chroma rather than
 * lightness — that concession is the snap. The product therefore never refuses a customer's brand colour;
 * it tells them what it did. {@see BrandRampException} explains why the deny-by-default check below is
 * nonetheless a guard worth having.
 *
 * ── WHY THE TARGETS ARE RATIOS AND NOT RAMP STEPS ─────────────────────────────────────────────────
 * The two ratified accents disagree about steps but agree about RATIOS. In dark mode Blueprint darkens on
 * hover (6.14 → 9.14 → 13.00) while Teal lightens then darkens (6.68 → 4.82 → 9.40, recorded as
 * exceptions-log #6 precisely because it is odd). Targeting ratios rather than steps gives one rule that
 * works for every hue, and it is monotone in both themes — hover and active always move toward MORE
 * contrast with the white text they carry. That deviates from Teal's hand-tuned dark hover, deliberately:
 * a generator cannot reproduce a per-hue aesthetic exception, and the safe direction is the one that
 * increases the ratio the text depends on.
 *
 * The targets below are the MIDPOINT of what the two ratified accents measure at each role, so a tenant
 * ramp lands in the same visual register as the product's own — not scraping the 4.5 floor. Verified: fed
 * `#1B5E5E` this engine re-derives `--mds-accent-teal-600` (`#1B5E5E`) and `--mds-accent-teal-50`
 * (`#E6F2F2`) byte-identically, and reproduces §4.1's 7.48 / 9.40 / 12.10 to two decimals.
 *
 * ── WHAT IS DELIBERATELY NOT A CONSTRAINT ─────────────────────────────────────────────────────────
 * Fill-versus-canvas. It looks like it belongs (a button ought to be distinguishable from the page), but
 * measuring the shipped system first showed both ratified accents FAIL it in dark mode — Blueprint 2.61,
 * Teal 2.40, against a 3:1 reading of WCAG 1.4.11. Adding it would have made the engine stricter than the
 * design system it serves and rejected the product's own colours. §4.1 does not list it; neither does this.
 *
 * @see BrandRamp for why the result is stored rather than re-derived on read.
 */
final class BrandRampGenerator
{
    /**
     * Bumped whenever a target, ground or role changes. Stored on every ramp
     * ({@see BrandRamp::$engineVersion}) so a future change is an explicit re-derivation rather than a
     * silent repaint of every live tenant.
     */
    public const int VERSION = 1;

    /** The text every primary fill carries. `--mds-color-text-on-primary` is `#FFFFFF` in BOTH themes. */
    private const string ON_PRIMARY = '#FFFFFF';

    /**
     * Chroma ceiling, ≈1.3× the most saturated ratified accent (Blueprint measures 0.0832; Teal 0.0651).
     * Headroom for a punchier brand without letting a tenant out-saturate the design system it sits in.
     */
    private const float MAX_CHROMA = 0.11;

    /**
     * The LIGHT tint is a near-white wash, not an accent, and takes its own far tighter ceiling: both
     * ratified light tints measure C ≈ 0.010–0.013 (`primary-50` 0.0101, `accent-teal-50` 0.0127) against
     * their accents' 0.065–0.083. Generating the tint at full accent chroma produces a garish
     * `#BAFCFB` where the system ships `#E6F2F2`. The DARK tint keeps the ramp chroma — both shipped dark
     * tints sit at 81–95% of their accent's.
     */
    private const float MAX_TINT_CHROMA_LIGHT = 0.013;

    /** Every §4.1 pairing has one of these two floors: 4.5 for text (1.4.3), 3.0 for non-text (1.4.11). */
    private const float TEXT_MIN = 4.5;

    private const float NON_TEXT_MIN = 3.0;

    /**
     * The grounds each theme measures against — the REAL semantic tokens, not approximations.
     *
     * `surface` is `--mds-color-bg-surface` (dark re-points it to `neutral-100`), `canvas` is
     * `--mds-color-bg-canvas` (`neutral-50`, which the dark neutral flip carries to `#0C2337`), and `ink`
     * is `neutral-900` — near-black in light, PALE in dark, which is why the tint search finds a light
     * colour in one theme and a dark one in the other from the same rule.
     */
    private const array GROUNDS = [
        'light' => ['surface' => '#FFFFFF', 'canvas' => '#F3F4F1', 'ink' => '#0E1620'],
        'dark' => ['surface' => '#123350', 'canvas' => '#0C2337', 'ink' => '#EAF1F6'],
    ];

    /** Target ratios per theme and role — the midpoint of the two ratified accents. See the docblock. */
    private const array TARGETS = [
        'light' => ['bg' => 7.5, 'bg_hover' => 9.4, 'bg_active' => 12.1, 'tint' => 15.9],
        'dark' => ['bg' => 6.4, 'bg_hover' => 9.2, 'bg_active' => 11.5, 'fg' => 5.2, 'tint' => 9.4],
    ];

    /**
     * Derive the ramp, or throw if the result somehow fails §4.1.
     *
     * @throws BrandRampException
     */
    public function generate(string $input): BrandRamp
    {
        $input = strtoupper(trim($input));

        if (preg_match('/^#[0-9A-F]{6}$/', $input) !== 1) {
            throw BrandRampException::malformedInput($input);
        }

        [, $chroma, $hue] = ColorSpace::hexToOklch($input);
        $chroma = min($chroma, self::MAX_CHROMA);

        $tokens = [];

        foreach (BrandRamp::THEMES as $theme) {
            $tokens[$theme] = $this->themeTokens($theme, $chroma, $hue);
        }

        $measurements = $this->measure($tokens);

        $failures = array_map(
            static fn (array $m): string => sprintf(
                '%s / %s measured %.2f, needs %.1f',
                $m['theme'],
                $m['pairing'],
                $m['ratio'],
                $m['min'],
            ),
            array_values(array_filter($measurements, static fn (array $m): bool => $m['ratio'] < $m['min'])),
        );

        if ($failures !== []) {
            throw BrandRampException::pairingFailed($input, $failures);
        }

        return new BrandRamp($input, self::VERSION, $tokens, $measurements);
    }

    /**
     * The six tokens for one theme.
     *
     * Two roles are ALIASES rather than searches, and both are drawn from the ratified accents:
     *  - **light `fg` = light `bg`** — Teal's `fg` is `teal-600`, which is its fill; Blueprint's is
     *    `primary-600`, which is its fill. Both, unanimously.
     *  - **dark `ring` = dark `fg`** — Teal rings with `teal-300`, its dark `fg`; Blueprint with
     *    `primary-300`, its dark `fg`. Again unanimous.
     *
     * The third, **light `ring` = light `bg_hover`**, is where the two accents DISAGREE (Teal rings with
     * its hover step, Blueprint with its fill). Taking the hover step is the safer half of the
     * disagreement — a ring needs only 3:1 and the hover step clears ~9.4 — and it is recorded as a
     * choice rather than a derivation.
     *
     * @return array<string, string>
     */
    private function themeTokens(string $theme, float $chroma, float $hue): array
    {
        $ground = self::GROUNDS[$theme];
        $target = self::TARGETS[$theme];

        $onPrimary = fn (string $hex): float => ColorSpace::contrast(self::ON_PRIMARY, $hex);

        $bg = $this->search($chroma, $hue, $target['bg'], self::TEXT_MIN, $onPrimary);
        $hover = $this->search($chroma, $hue, $target['bg_hover'], self::TEXT_MIN, $onPrimary);
        $active = $this->search($chroma, $hue, $target['bg_active'], self::TEXT_MIN, $onPrimary);

        // Light `fg` is the fill itself; dark `fg` is a PALE step and must be lighter than the ground it
        // sits on, or the search would happily return the equally-contrasty dark solution instead.
        //
        // Spelled `self::TARGETS['dark']['fg']` rather than `$target['fg']` because only the dark map has
        // an `fg` key — light `fg` is an alias, not a search — and PHPStan cannot narrow `$target` from
        // the `$theme` comparison. The TS twin spells it `TARGETS.dark.fg` for the same reason.
        $fg = $theme === 'light'
            ? $bg
            : $this->search(
                $chroma,
                $hue,
                self::TARGETS['dark']['fg'],
                self::TEXT_MIN,
                fn (string $hex): float => ColorSpace::contrast($hex, $ground['surface']),
                lighterThan: $ground['surface'],
            );

        // No direction filter on the tint: the target ratio against the theme's own ink already pins it
        // (near-black ink ⇒ a light wash; pale ink ⇒ a dark one), and contrast is monotone in lightness.
        $tint = $this->search(
            $theme === 'light' ? min($chroma, self::MAX_TINT_CHROMA_LIGHT) : $chroma,
            $hue,
            $target['tint'],
            self::TEXT_MIN,
            fn (string $hex): float => ColorSpace::contrast($ground['ink'], $hex),
        );

        return [
            'bg' => $bg,
            'bg_hover' => $hover,
            'bg_active' => $active,
            'fg' => $fg,
            'tint' => $tint,
            'ring' => $theme === 'light' ? $hover : $fg,
        ];
    }

    /**
     * Sweep lightness and return the candidate whose measured ratio lands closest to `$target` while
     * clearing `$floor`.
     *
     * **A FIXED 1001-POINT SCAN, NOT A BINARY SEARCH, AND THAT IS A PARITY DECISION.** This class has a
     * TypeScript twin (`packages/design-system/src/theme/brand-ramp.ts`) that must agree with it
     * byte-for-byte. A convergence loop can take a different number of iterations on two platforms and
     * settle on opposite sides of an 8-bit rounding boundary; a fixed integer grid cannot. The scan
     * evaluates the same 1001 lightness values in the same order in both languages, and every value that
     * leaves this method has already been quantized to `#RRGGBB`.
     *
     * @param  callable(string): float  $measure
     * @param  string|null  $lighterThan  when set, only candidates lighter than this ground are eligible
     */
    private function search(
        float $chroma,
        float $hue,
        float $target,
        float $floor,
        callable $measure,
        ?string $lighterThan = null,
    ): string {
        $groundLuminance = $lighterThan === null ? null : ColorSpace::luminance($lighterThan);

        $best = null;
        $bestDistance = INF;

        for ($step = 0; $step <= 1000; $step++) {
            $hex = ColorSpace::oklchToHex($step / 1000, $chroma, $hue);

            if ($groundLuminance !== null && ColorSpace::luminance($hex) <= $groundLuminance) {
                continue;
            }

            $ratio = $measure($hex);

            if ($ratio < $floor) {
                continue;
            }

            $distance = abs($ratio - $target);

            if ($distance < $bestDistance) {
                $best = $hex;
                $bestDistance = $distance;
            }
        }

        // Unreachable by the monotonicity argument in the class docblock — every role's floor is met at
        // one extreme of the lightness range for every hue. Kept because "unreachable" is a claim about
        // today's targets, and a future target edit that broke it would otherwise return null silently.
        if ($best === null) {
            throw BrandRampException::pairingFailed(
                sprintf('L-sweep at C=%.4f h=%.1f', $chroma, $hue),
                [sprintf('no lightness clears %.1f for this role', $floor)],
            );
        }

        return $best;
    }

    /**
     * Measure every §4.1 pairing over a generated ramp.
     *
     * @param  array<string, array<string, string>>  $tokens
     * @return list<array{theme: string, pairing: string, ratio: float, min: float}>
     */
    private function measure(array $tokens): array
    {
        $measurements = [];

        foreach (self::pairings() as $pairing) {
            $ground = self::GROUNDS[$pairing['theme']];
            $token = $tokens[$pairing['theme']][$pairing['role']];

            $against = match ($pairing['against']) {
                'on_primary' => self::ON_PRIMARY,
                'ink' => $ground['ink'],
                default => $ground[$pairing['against']],
            };

            $measurements[] = [
                'theme' => $pairing['theme'],
                'pairing' => $pairing['label'],
                'ratio' => ColorSpace::contrast($token, $against),
                'min' => $pairing['min'],
            ];
        }

        return $measurements;
    }

    /**
     * The seventeen pairings of design-system-reference.md §4.1, generalized off Teal to any hue.
     *
     * The count is 17 and is asserted by a unit test, because §4.1's Teal table is 17 rows and this list
     * is that table with the hue removed. Light row 6 ("UI boundary vs canvas", 3:1) duplicates row 5's
     * colour pair at a lower floor and is kept rather than folded in — dropping it would make this list
     * 16 and quietly break its correspondence with the document it encodes.
     *
     * @return list<array{theme: string, role: string, against: string, min: float, label: string}>
     */
    public static function pairings(): array
    {
        return [
            // ── Light (§4.1 rows 1–9) ────────────────────────────────────────────────────────────
            ['theme' => 'light', 'role' => 'bg', 'against' => 'on_primary', 'min' => self::TEXT_MIN, 'label' => 'white text on fill'],
            ['theme' => 'light', 'role' => 'bg_hover', 'against' => 'on_primary', 'min' => self::TEXT_MIN, 'label' => 'white text on hover fill'],
            ['theme' => 'light', 'role' => 'bg_active', 'against' => 'on_primary', 'min' => self::TEXT_MIN, 'label' => 'white text on active fill'],
            ['theme' => 'light', 'role' => 'fg', 'against' => 'surface', 'min' => self::TEXT_MIN, 'label' => 'fg text on surface'],
            ['theme' => 'light', 'role' => 'fg', 'against' => 'canvas', 'min' => self::TEXT_MIN, 'label' => 'fg text on canvas'],
            ['theme' => 'light', 'role' => 'fg', 'against' => 'canvas', 'min' => self::NON_TEXT_MIN, 'label' => 'fg UI boundary vs canvas'],
            ['theme' => 'light', 'role' => 'ring', 'against' => 'surface', 'min' => self::NON_TEXT_MIN, 'label' => 'focus ring vs surface'],
            ['theme' => 'light', 'role' => 'ring', 'against' => 'canvas', 'min' => self::NON_TEXT_MIN, 'label' => 'focus ring vs canvas'],
            ['theme' => 'light', 'role' => 'tint', 'against' => 'ink', 'min' => self::TEXT_MIN, 'label' => 'ink on tint'],

            // ── Dark (§4.1 rows 10–17) ───────────────────────────────────────────────────────────
            ['theme' => 'dark', 'role' => 'bg', 'against' => 'on_primary', 'min' => self::TEXT_MIN, 'label' => 'white text on fill'],
            ['theme' => 'dark', 'role' => 'bg_hover', 'against' => 'on_primary', 'min' => self::TEXT_MIN, 'label' => 'white text on hover fill'],
            ['theme' => 'dark', 'role' => 'bg_active', 'against' => 'on_primary', 'min' => self::TEXT_MIN, 'label' => 'white text on active fill'],
            ['theme' => 'dark', 'role' => 'fg', 'against' => 'surface', 'min' => self::TEXT_MIN, 'label' => 'fg text on surface'],
            ['theme' => 'dark', 'role' => 'fg', 'against' => 'canvas', 'min' => self::TEXT_MIN, 'label' => 'fg text on canvas'],
            ['theme' => 'dark', 'role' => 'ring', 'against' => 'surface', 'min' => self::NON_TEXT_MIN, 'label' => 'focus ring vs surface'],
            ['theme' => 'dark', 'role' => 'ring', 'against' => 'canvas', 'min' => self::NON_TEXT_MIN, 'label' => 'focus ring vs canvas'],
            ['theme' => 'dark', 'role' => 'tint', 'against' => 'ink', 'min' => self::TEXT_MIN, 'label' => 'ink on tint'],
        ];
    }
}
