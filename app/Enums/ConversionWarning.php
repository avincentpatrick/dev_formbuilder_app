<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Forms\ConversionPlan;

/**
 * What an author should know before a type conversion is applied (Increment M121). A warning never refuses
 * a conversion — it is carried on the {@see ConversionPlan} so the confirmation can say it before the write.
 *
 * ⛔ CLOSED, AND THE SENTENCE IS TRANSMITTED RATHER THAN MIRRORED. {@see self::message()} is a `match` with no
 * `default` arm, so a new case without a sentence is a PHPStan error, and the client renders what the
 * server sends — the posture `ValidationRuleType::label()` set for the validation editor.
 *
 * ⚠️ Declaration order is the order a plan lists them in.
 */
enum ConversionWarning: string
{
    /** The target needs choices, levels, rows, columns or cells before publish will accept it. */
    case NeedsSetup = 'needs_setup';

    /** A calculated field with no formula computes nothing, and publish refuses it until it has one (M122). */
    case CalculatedNeedsFormula = 'calculated_needs_formula';

    /** A hidden field holds nothing until it declares where its value comes from. */
    case HiddenNeedsPrefillSource = 'hidden_needs_prefill_source';

    /** A kept default value was written for the old type. */
    case DefaultValueFormat = 'default_value_format';

    /** The field is marked for reporting and answers of the new type cannot be indexed at all. */
    case IndexingUnavailable = 'indexing_unavailable';

    /** The field is marked for reporting and its declared index type was chosen for the old type. */
    case IndexedTypeMayNotSuit = 'indexed_type_may_not_suit';

    /** A Likert scale scores its choices as numbers, and at least one choice value is not one. */
    case ScaleValuesNotNumeric = 'scale_values_not_numeric';

    /** The field sits in a repeatable section, where the new type cannot be published. */
    case RepeatableSectionRefusesType = 'repeatable_section_refuses_type';

    public function message(): string
    {
        return match ($this) {
            self::NeedsSetup => 'This question needs its choices, levels, rows or columns set up before the form can be published.',
            self::CalculatedNeedsFormula => 'A calculated question computes nothing until it has a formula, and the form cannot be published without one.',
            self::HiddenNeedsPrefillSource => 'A hidden question holds nothing until you choose where its value comes from.',
            self::DefaultValueFormat => 'The default value was written for the old type and may not suit the new one.',
            self::IndexingUnavailable => 'This question is marked for reporting, but answers of the new type cannot be indexed.',
            self::IndexedTypeMayNotSuit => 'This question is marked for reporting, and the data type it is indexed as was chosen for the old type.',
            self::ScaleValuesNotNumeric => 'A Likert scale scores its choices as numbers, and at least one choice value is not a number.',
            self::RepeatableSectionRefusesType => 'This question sits in a repeatable section, where the new type cannot be published.',
        };
    }
}
