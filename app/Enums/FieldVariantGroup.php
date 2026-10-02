<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Field types the builder palette shows as ONE entry, the difference being a setting rather than a kind
 * (Increment M125, `R-a367bf9e`): short and long text are "Text", whole and decimal numbers are "Number".
 * Returned by {@see FieldType::variantGroup()}.
 *
 * ⛔ A PALETTE-LEVEL MERGE, NOT A SECOND TYPE SYSTEM. The 31-case enum, the database and the XLSForm
 * round-trip are untouched: every field still stores its own member, the palette adds the group's
 * PRIMARY member, and the Basics tab switches members through the type-conversion routes. That is safe
 * only because every pair converts losslessly with no confirmation — `FieldVariantGroupTest` asserts it
 * over this enum's members, so a member that does not belong here turns that test red.
 */
enum FieldVariantGroup: string
{
    case Text = 'text';
    case Number = 'number';

    /** The palette entry's label, and the label a new field of the group is seeded with. */
    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Number => 'Number',
        };
    }

    /** The member the palette entry adds. */
    public function primary(): FieldType
    {
        return match ($this) {
            self::Text => FieldType::ShortText,
            self::Number => FieldType::Integer,
        };
    }

    /**
     * Every member, in catalog order — derived from {@see FieldType::variantGroup()}, never listed twice.
     *
     * @return list<FieldType>
     */
    public function members(): array
    {
        return array_values(array_filter(
            FieldType::cases(),
            fn (FieldType $type): bool => $type->variantGroup() === $this,
        ));
    }

    /**
     * What the builder palette transmits for one member of this group.
     *
     * @return array{group: string, label: string, primary: bool}
     */
    public function paletteVariant(FieldType $member): array
    {
        return [
            'group' => $this->value,
            'label' => $this->label(),
            'primary' => $member === $this->primary(),
        ];
    }
}
