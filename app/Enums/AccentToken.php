<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\UserUiPreference;

/**
 * The curated accent-colour whitelist ({@see UserUiPreference::$accent_token}, data-dictionary §19,
 * design-system-reference.md §2.2/§2.9, PRD Feature #9, Increment G11).
 *
 * Unlike the other enums in this namespace there is deliberately NO backing CHECK constraint: §19 rules
 * that enumerating design-system tokens in the schema would force a migration every time §2.2 adds an
 * accent option. This enum IS that application-layer whitelist — it is the only thing standing between
 * the request body and the column, which is why the accent axis is validated with `Rule::enum()` rather
 * than a loose string rule.
 *
 * The set is closed at TWO options by ratified design decision (§2.9): never an arbitrary colour picker,
 * never Brass (reserved for annotation) and never Moss/Success — reusing a semantic hue as a personal
 * accent would let a user recolour every primary button the same green as "success", collapsing the
 * accent/semantic separation the token architecture exists to enforce. Every option is pre-verified
 * against §4.1's contrast minimums in BOTH themes, so no reachable combination can be inaccessible.
 */
enum AccentToken: string
{
    /** The product default. Stored as NULL and emits no attribute — see {@see self::attributeValue()}. */
    case Blueprint = 'blueprint';

    case Teal = 'teal';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Resolve the column value to an enum case. NULL means "product default" (data-dictionary §19),
     * which is Blueprint — the wire and the UI always carry a real, non-null value.
     */
    public static function fromColumn(?string $value): self
    {
        return $value === null ? self::Blueprint : self::from($value);
    }

    /**
     * The column value for this case: Blueprint is stored as NULL so "no row / no opinion" and
     * "explicitly chose the default" are the same state in the database.
     */
    public function toColumn(): ?string
    {
        return $this === self::Blueprint ? null : $this->value;
    }

    /**
     * The `data-accent` value to emit on <html>, or null to emit no attribute.
     *
     * Blueprint is the ABSENCE of the attribute, mirroring {@see ThemeMode::System}.
     */
    public function attributeValue(): ?string
    {
        return $this === self::Blueprint ? null : $this->value;
    }
}
