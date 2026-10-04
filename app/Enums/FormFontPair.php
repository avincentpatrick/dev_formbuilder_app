<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The type a form theme preset sets (M131, `R-6017d6d8`, `D65` = A) — one of the two documented additions a
 * preset may make beyond the brand ramp's six colour properties (ADR-0014 §D7, amended 2026-10-04).
 *
 * ⛔ SYSTEM STACKS ONLY, AND THE REASON IS THE GUEST RUNTIME. No webfont loads on the guest page
 * (`fonts.css` says so, deliberately: the embeddable runtime makes no font request on a third-party page),
 * so a pair is two stacks of fonts a device may already have, each ending in a generic family so a device
 * with none of them still renders legibly. `FormThemePresetTest` holds every stack to that, and to a
 * character whitelist — the partial emits these UNESCAPED inside `<style>`, where an HTML entity would not
 * be decoded, so nothing that could close a declaration or a tag may ever appear in one.
 *
 * It sets `--mds-font-family-body-default`, never `--mds-font-family-body` itself: the dyslexia preference
 * overrides `-body`, and leaving that alias alone is what keeps a member's OpenDyslexic winning wherever a
 * preset is shown inside the admin app (the builder preview).
 */
enum FormFontPair: string
{
    /** The product's own stacks — sets nothing. */
    case System = 'system';

    /** A humanist sans for body text; headings unchanged. */
    case Humanist = 'humanist';

    /** Rounded headings; body unchanged. */
    case Rounded = 'rounded';

    /** Serif headings; body unchanged. */
    case Classic = 'classic';

    public function label(): string
    {
        return match ($this) {
            self::System => 'Standard type',
            self::Humanist => 'Humanist body',
            self::Rounded => 'Rounded headings',
            self::Classic => 'Serif headings',
        };
    }

    /**
     * The documented properties this pair sets, property ⇒ value.
     *
     * @return array<string, string>
     */
    public function declarations(): array
    {
        return match ($this) {
            self::System => [],
            self::Humanist => [
                '--mds-font-family-body-default' => 'Seravek, "Gill Sans Nova", Ubuntu, Calibri, "DejaVu Sans", source-sans-pro, sans-serif',
            ],
            self::Rounded => [
                '--mds-font-family-display' => 'ui-rounded, "Hiragino Maru Gothic ProN", Quicksand, Comfortaa, Manjari, "Arial Rounded MT", "Arial Rounded MT Bold", Calibri, source-sans-pro, sans-serif',
            ],
            self::Classic => [
                '--mds-font-family-display' => 'Charter, "Bitstream Charter", "Sitka Text", Cambria, serif',
            ],
        };
    }
}
