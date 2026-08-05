<?php

declare(strict_types=1);

namespace App\Support\Branding;

/**
 * A tenant's derived brand ramp — twelve literal hexes, their measured ratios, and the engine that made
 * them (ADR-0014, H23a1).
 *
 * **This is stored, not recomputed, and the reason is not performance.** Three of the five surfaces that
 * consume it cannot resolve a CSS custom property at all: mail clients strip `<style>` and ignore
 * `var()`, and dompdf (the H17 PDF renderer) supports neither custom properties reliably nor `oklch()`.
 * Those surfaces need literal hexes at render time, so the ramp has to exist as data somewhere regardless
 * — and once it does, deriving it a second time at render is pure duplication with a drift hazard
 * attached.
 *
 * {@see self::$engineVersion} is what keeps that honest. A later change to a target ratio or a ground
 * token would silently repaint every live tenant if the ramp were re-derived on read; because it is
 * stored with the version that produced it, such a change becomes an explicit, auditable re-derivation
 * instead of a surprise. Nothing in this class re-derives anything.
 *
 * The value object is deliberately dumb: {@see BrandRampGenerator} owns every decision, and this holds
 * the result. `$measurements` is carried rather than recomputed on demand because the admin UI's snap
 * disclosure (H23a2) shows the tenant the actual ratios their colour achieved, and those are the numbers
 * the engine measured — not a second implementation's opinion of them.
 */
final class BrandRamp
{
    /** The six roles a ramp defines, in each theme. Mirrors the six custom properties H23a3 emits. */
    public const array ROLES = ['bg', 'bg_hover', 'bg_active', 'fg', 'tint', 'ring'];

    public const array THEMES = ['light', 'dark'];

    /**
     * @param  string  $input  the `#RRGGBB` the tenant actually typed, kept so the picker can show it
     *                         beside the derived fill — the snap is disclosed, never hidden
     * @param  array<string, array<string, string>>  $tokens  theme => role => `#RRGGBB`
     * @param  list<array{theme: string, pairing: string, ratio: float, min: float}>  $measurements
     */
    public function __construct(
        public readonly string $input,
        public readonly int $engineVersion,
        public readonly array $tokens,
        public readonly array $measurements,
    ) {}

    /** One derived hex. */
    public function token(string $theme, string $role): string
    {
        return $this->tokens[$theme][$role];
    }

    /**
     * The jsonb payload for `tenants.brand_ramp`.
     *
     * Ratios are rounded to 2 decimals on the way out — the same precision design-system-reference.md
     * §4.1 prints, and the same precision the admin UI shows. Storing the full float would imply a
     * significance the WCAG formula does not have at the eighth decimal place.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'input' => $this->input,
            'engine_version' => $this->engineVersion,
            'tokens' => $this->tokens,
            'measurements' => array_map(
                static fn (array $m): array => [...$m, 'ratio' => round($m['ratio'], 2)],
                $this->measurements,
            ),
        ];
    }

    /**
     * Rehydrate from `tenants.brand_ramp`.
     *
     * Deliberately does NOT re-run the generator or re-validate: a stored ramp was validated when it was
     * written, by the engine version recorded alongside it. Re-validating here would re-open the question
     * this class exists to close, and would fail loudly on exactly the rows a version bump is supposed to
     * migrate deliberately.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var array<string, array<string, string>> $tokens */
        $tokens = $payload['tokens'];
        /** @var list<array{theme: string, pairing: string, ratio: float, min: float}> $measurements */
        $measurements = $payload['measurements'];

        return new self(
            (string) $payload['input'],
            (int) $payload['engine_version'],
            $tokens,
            $measurements,
        );
    }
}
