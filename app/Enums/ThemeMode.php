<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\User;
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
     * The product default, and the ONE place it is written.
     *
     * ⛔ INCREMENT M86 — THIS EXISTS TO DELETE COPIES, SO ADDING IT WITHOUT MOVING THE CALLERS WOULD
     * MAKE THINGS WORSE. `'system'` was written as a bare literal in the creating migration and again
     * in {@see User::defaultUiTheme()}, alongside the live column default and
     * `docs/data-dictionary.md` §19 — four homes for one fact, all agreeing, none derived from another.
     * Both writable copies now call this. The two that remain are a database default and a document,
     * which are compared to each other by `tests/Feature/Migrations/DocumentedDefaultDriftTest.php`.
     *
     * ⚠️ Calling it from the migration is the in-house idiom rather than an invention — the plans,
     * audits and webhook tables already build CHECK constraints from `values()` — and it is safe on a
     * migration that has already run, because the emitted value is byte-identical to the literal it
     * replaces. The default this returns must therefore never be changed without a migration that
     * alters the column: this is the name of the existing default, not a lever for changing it.
     */
    public static function default(): self
    {
        return self::System;
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
