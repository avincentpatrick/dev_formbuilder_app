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

    /** M176 (`R-36e6f557`): in the DSR §1.5 voice — short sentences, everyday words, what to do next. */
    public function message(): string
    {
        return match ($this) {
            self::NeedsSetup => 'Add its choices (or its levels, rows or columns) before you publish the form.',
            self::CalculatedNeedsFormula => 'A calculated question needs a formula. Add one before you publish the form.',
            self::HiddenNeedsPrefillSource => 'A hidden question stays empty until you choose where its value comes from.',
            self::DefaultValueFormat => 'Its default value was made for the old type. Check that it still fits.',
            self::IndexingUnavailable => 'It is indexed for reporting, but answers of the new type can’t be indexed.',
            self::IndexedTypeMayNotSuit => 'It is indexed for reporting with a data type chosen for the old type. Check that it still fits.',
            self::ScaleValuesNotNumeric => 'A Likert scale scores each choice as a number, and at least one choice’s value isn’t a number.',
            self::RepeatableSectionRefusesType => 'This question is in a repeating section, where the new type can’t be used. Move it, or choose another type, before you publish.',
        };
    }
}
