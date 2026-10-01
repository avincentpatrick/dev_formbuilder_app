<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a type conversion removes a validation rule (Increment M121). Closed, with a transmitted sentence —
 * the same posture as {@see ConversionWarning}.
 */
enum ConversionDropReason: string
{
    /** The new type is `note` or `hidden`, which may carry no rule at all — not even one its shape allows. */
    case TargetTakesNoRules = 'target_takes_no_rules';

    /** The new type's value shape cannot be checked this way ({@see ValueShape::allows()}). */
    case NotAllowedForShape = 'not_allowed_for_shape';

    /** The rule is the old type's built-in format check, which does not apply to the new type. */
    case TypeDefault = 'type_default';

    public function message(): string
    {
        return match ($this) {
            self::TargetTakesNoRules => 'The new type takes no validation rules.',
            self::NotAllowedForShape => 'The new type cannot be checked this way.',
            self::TypeDefault => "This was the old type's built-in format check, and it does not apply to the new type.",
        };
    }
}
