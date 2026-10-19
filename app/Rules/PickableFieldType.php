<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\FieldType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A field type an author may pick — refusing a retired one (M177, `R-0b7db001`, `R-22011569`, `D140`) on the two
 * routes that make a question a type: adding one, and changing one's type.
 *
 * ⛔ THE BUILDER'S UNDO GOES THROUGH THOSE SAME ROUTES, which is why `$restoring` exists. Undoing the delete of an
 * existing Yes/No re-creates it with `POST …/fields`, and undoing a conversion converts back with `POST …/convert`;
 * the store's history steps send `restore: true`, and nothing an author does sends it. It is a product rule, not a
 * permission: a hand-made request that claims to restore makes a question of a type the platform still fully supports.
 *
 * Runs after `Rule::enum()`, so an unknown value is that rule's to refuse.
 */
final class PickableFieldType implements ValidationRule
{
    public function __construct(private readonly bool $restoring) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $type = is_string($value) ? FieldType::tryFrom($value) : null;
        if ($type === null || ! $type->isRetired() || $this->restoring) {
            return;
        }

        // In the DSR §1.5 voice: what happened, and what to use instead.
        $fail(match ($type) {
            FieldType::YesNo => 'Yes / No questions are retired. Use Single choice with the choices Yes and No.',
            default => "{$type->label()} questions are retired: respondents can’t answer them. A duration will be worked out by a calculation.",
        });
    }
}
