<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The corner radius a form theme preset sets (M131, `R-6017d6d8`, `D65` = A) — the second documented addition
 * a preset may make beyond the brand ramp's six colour properties (ADR-0014 §D7, amended 2026-10-04).
 *
 * ⛔ ONLY THE THREE TIERS ABOVE `sm`, NEVER `sm`, `none` OR `full`. design-system-reference §2.6 keeps
 * `--mds-radius-sm` at 6px on purpose: the checkbox is an 18px box, and a larger `sm` would round it toward the
 * radio's circle — the one non-colour cue separating the two controls. `full` is reserved for true circles and
 * pills. So a scale moves `md` (controls), `lg` (compact surfaces) and `xl` (cards and dialogs), and keeps the
 * proportion the direction depends on: controls visibly tighter than cards. `FormThemePresetTest` holds every
 * scale to `6 < md < lg <= xl` with at least 4px between `md` and `xl`.
 */
enum FormRadiusScale: string
{
    /** The product's own scale — sets nothing. */
    case Standard = 'standard';

    /** Tighter corners throughout. */
    case Crisp = 'crisp';

    /** Rounder corners throughout. */
    case Soft = 'soft';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard corners',
            self::Crisp => 'Crisp corners',
            self::Soft => 'Soft corners',
        };
    }

    /**
     * The documented properties this scale sets, property ⇒ value.
     *
     * @return array<string, string>
     */
    public function declarations(): array
    {
        return match ($this) {
            self::Standard => [],
            self::Crisp => ['--mds-radius-md' => '8px', '--mds-radius-lg' => '10px', '--mds-radius-xl' => '12px'],
            self::Soft => ['--mds-radius-md' => '14px', '--mds-radius-lg' => '18px', '--mds-radius-xl' => '24px'],
        };
    }
}
