<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\UserUiPreference;

/**
 * Light/dark preference for the authenticated app shell ({@see UserUiPreference::$theme_mode},
 * data-dictionary §19, PRD Feature #9).
 *
 * Catalogued in the data dictionary's enum table since Increment C but never actually written — until
 * G11 the allowed values were duplicated as bare literals across the controller's `Rule::in`, the blade
 * root-attribute whitelist, and the TypeScript union. This enum is now the single source those share.
 *
 * There is no backing CHECK constraint (`theme_mode` is a plain varchar(10)); validation is
 * application-layer, exactly like {@see AccentToken}.
 */
enum ThemeMode: string
{
    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The `data-theme-mode` value to emit on <html>, or null to emit no attribute at all.
     *
     * "System" is the ABSENCE of the attribute rather than a value of its own, so
     * `prefers-color-scheme` decides and the whole personalization layer costs nothing for users who
     * never expressed a preference (design-system-reference.md §2.9). Every axis follows this
     * convention — see {@see FontSizeScale::attributeValue()} and {@see AccentToken::attributeValue()}.
     */
    public function attributeValue(): ?string
    {
        return $this === self::System ? null : $this->value;
    }
}
