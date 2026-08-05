<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\User;
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
     * Resolve a column value to an enum case, treating NULL as Blueprint.
     *
     * ⚠️ **Since H23a3 this is a LOSSY read, and callers have to know it.** NULL means "no opinion",
     * which on a BRANDED tenant resolves to the organisation's brand rather than to Blueprint. This
     * helper answers the narrower question *"which of the two product accents does this row imply"* —
     * the right question for a fallback, the wrong one for deciding whether to apply a tenant ramp.
     * {@see User::uiTheme()} deliberately does NOT use it: it carries the null through so the
     * blade can tell the two states apart.
     */
    public static function fromColumn(?string $value): self
    {
        return $value === null ? self::Blueprint : self::from($value);
    }

    /**
     * The column value for this case — now always the backing value, INCLUDING Blueprint.
     *
     * ── AMENDED IN H23a3, and the amendment is the point rather than a tidy-up ──────────────────────
     * This used to return NULL for Blueprint, so that "no row / no opinion" and "explicitly chose the
     * product default" were deliberately the SAME state (data-dictionary §19). That was correct while
     * there was only one layer of accent: absence could safely mean "the base tokens", because nothing
     * else could occupy them.
     *
     * Tenant branding (ADR-0014) adds a second layer, and it makes absence AMBIGUOUS. On a branded tenant
     * the two readings diverge: "no opinion" should inherit the organisation's brand, while "explicitly
     * Blueprint" must not. Collapsing them left a member with **no way to ask for the product blue back**
     * — the escape defect recorded in design-system-reference.md §395.
     *
     * So the column's domain widens from `{NULL, 'teal'}` to `{NULL, 'blueprint', 'teal'}`, and NULL now
     * means exactly one thing: **no opinion** — inherit the tenant's brand if one is active, otherwise
     * fall back to the product default. Existing NULL rows keep rendering identically, because a tenant
     * with no brand has nothing to inherit.
     */
    public function toColumn(): string
    {
        return $this->value;
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
