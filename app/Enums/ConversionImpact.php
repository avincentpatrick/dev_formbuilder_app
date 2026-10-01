<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Forms\ConversionCensus;

/**
 * What a type conversion does to ANOTHER part of the form that names the converted field (Increment M122).
 * The field's own id and key survive a conversion, so every reference survives syntactically; these are the
 * references whose MEANING changes. Reported by {@see ConversionCensus} beside each plan, and never a refusal
 * — publish stays the gate, and only {@see self::TemplateHoleUnanswerable} is one it already enforces.
 *
 * ⛔ CLOSED, AND THE SENTENCE IS TRANSMITTED RATHER THAN MIRRORED — the {@see ConversionWarning} posture.
 * {@see self::message()} is a `match` with no `default` arm.
 *
 * ⚠️ Declaration order is the order a census lists them in.
 */
enum ConversionImpact: string
{
    /** A condition, rule, formula or constraint names a question that will hold no answer at all. */
    case ReferenceHasNoAnswer = 'reference_has_no_answer';

    /** The question is compared or calculated with as a number, and the new type is not a number. */
    case NumericUseOnNonNumber = 'numeric_use_on_non_number';

    /** A `contains`, `count()` or equality reads a list differently from one value, and the conversion crosses that line. */
    case ListMeaningChanges = 'list_meaning_changes';

    /** A `${key}` in text shows this question's answer, and the new type has none it can show. */
    case TemplateHoleUnanswerable = 'template_hole_unanswerable';

    public function message(): string
    {
        return match ($this) {
            self::ReferenceHasNoAnswer => 'This uses the question, which will no longer have an answer: "is empty" will always hold and every other comparison will fail.',
            self::NumericUseOnNonNumber => 'This compares or calculates with the question as a number. The new type is not a number, so the comparison is no longer offered and holds only while an answer happens to be numeric.',
            self::ListMeaningChanges => 'This checks whether the question contains or equals a choice. The new type changes whether the answer is one choice or a list, so the check will mean something different.',
            self::TemplateHoleUnanswerable => 'This text shows the question\'s answer, and the new type has no answer to show. Publishing will refuse until the reference is removed.',
        };
    }
}
