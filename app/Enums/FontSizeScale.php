<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\UserUiPreference;

/**
 * User-chosen text-size scale ({@see UserUiPreference::$font_size_scale}, data-dictionary §19,
 * PRD Feature #9, Increment G11).
 *
 * These three values are the whole vocabulary; the px table they map to lives in the design system
 * (`packages/design-system/src/theme/theme-overrides.css`, design-system-reference.md §2.9), NOT here.
 * The scale applies uniformly to every type role — never per-page or per-component — by re-pointing the
 * `--mds-type-*` custom properties under a `[data-font-size]` attribute selector.
 */
enum FontSizeScale: string
{
    case Standard = 'standard';
    case Large = 'large';
    case ExtraLarge = 'extra_large';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The `data-font-size` value to emit on <html>, or null to emit no attribute.
     *
     * "Standard" is the ABSENCE of the attribute, mirroring {@see ThemeMode::System}.
     */
    public function attributeValue(): ?string
    {
        return $this === self::Standard ? null : $this->value;
    }
}
