<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Enums\FieldType;
use App\Enums\ValueShape;

/**
 * "Other, please specify" (M177, `R-71509975`): a choice question's `other` choice and the Short text shown only when it
 * is picked, linked by the choice question's `config.other_specify_key`. The one place that says which types offer it,
 * what the pair is called and the condition that joins them — the builder service, the XLSForm import and the builder
 * payload all read it here, so the condition the import writes is the one the switch writes and the condition editor
 * reads back as a structured row (`condition-model.ts` prints `selected(${key}, 'v')`).
 *
 * XLSForm's `or_other` is this pattern written out; ODK advises the explicit form because `or_other` breaks translations,
 * so the export writes the two real questions rather than the modifier.
 */
final class OtherSpecify
{
    public const OPTION_VALUE = 'other';

    public const OPTION_LABEL = 'Other';

    public const CHILD_LABEL = 'Please specify';

    public const CONFIG_KEY = 'other_specify_key';

    /**
     * The types that offer the switch: one or several choices from a typed list — single choice, multiple choice and
     * dropdown. Not a Likert scale (a score, not a category), and not a Yes/No (retired, `D140`).
     *
     * @return list<FieldType>
     */
    public static function types(): array
    {
        return array_values(array_filter(
            FieldType::cases(),
            static fn (FieldType $type): bool => $type->hasOptions()
                && in_array(ValueShape::for($type), [ValueShape::Choice, ValueShape::MultipleChoice], true),
        ));
    }

    public static function offers(FieldType $type): bool
    {
        return in_array($type, self::types(), true);
    }

    /** The condition the Please specify question is shown under — exactly the text the condition editor prints. */
    public static function condition(string $choiceKey): string
    {
        return 'selected(${'.$choiceKey."}, '".self::OPTION_VALUE."')";
    }

    /**
     * The choices with an `other` one at the end — or as they are, when one is already valued `other` (the library's
     * "Sex" item has one): a second would be a duplicate value, which publish refuses.
     *
     * @param  list<array<string, mixed>>  $options
     * @return list<array<string, mixed>>
     */
    public static function withOther(array $options): array
    {
        foreach ($options as $option) {
            if (($option['value'] ?? null) === self::OPTION_VALUE) {
                return $options;
            }
        }

        return [...$options, ['value' => self::OPTION_VALUE, 'label' => self::OPTION_LABEL]];
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return list<array<string, mixed>>
     */
    public static function withoutOther(array $options): array
    {
        return array_values(array_filter($options, static fn (array $option): bool => ($option['value'] ?? null) !== self::OPTION_VALUE));
    }
}
