<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Enums\FieldType;
use App\Enums\ValidationRuleType;
use App\Enums\ValueShape;

/**
 * What a NEW field of each type should already validate (Increment M112).
 *
 * ⛔ WHY THIS IS A `pattern` ROW AND NOT A NEW `ValidationRuleType`. Choosing `email` used to change the
 * HTML input `type` and nothing else: the public runtime sets `novalidate`, so it degraded to a keyboard
 * hint; the server coerces to a string; and `SemanticValidator` has no email rule. Enforcing it looks
 * like it needs an `EmailFormat` rule type — which would mean a new DB CHECK value, an arm in each of the
 * two evaluators, new golden vectors and a new parity surface, for a check that already exists.
 * `pattern` is evaluated BYTE-EQUIVALENTLY by both engines today: `StructuredRuleEvaluator.php:73-74`
 * compiles via `compilePattern()` (`:135-140`) and `structured-rule-evaluator.ts:119-130` via its own
 * `compilePattern` (`:54-58`). Both wrap the body as an anchored full match with the `u` flag, both
 * escape only unescaped `/`, both fail closed on an empty pattern, and both PASS on an empty answer — so
 * a default row constrains the format without making the field required.
 *
 * ⛔ THE PATTERNS ARE DELIBERATELY PLAIN, AND THAT IS A PARITY REQUIREMENT RATHER THAN MODESTY.
 * They use only literals, `\s`, `\.`, `\-`, bounded quantifiers and simple classes — the subset PCRE and
 * JavaScript's `RegExp` in `u` mode agree on exactly. No lookarounds, no backreferences, no `\d` vs
 * `[0-9]` locale questions, no possessive quantifiers, no unicode property escapes. A pattern that
 * parses in one engine and not the other is a draft that saves cleanly and then refuses to publish —
 * and `tests/Feature/Forms/FieldDefaultValidationsTest.php` drives the shipped values through the PHP
 * evaluator rather than trusting this note.
 *
 * ⚠️ NO BACKFILL, BY DESIGN. Existing `email` fields stay unvalidated until an author opens the
 * Validation tab. Retro-applying a constraint to published forms would start rejecting answers that
 * were legal when the respondent gave them, which is a data question and not a builder one.
 *
 * ⚠️ AND THE THREE TYPES THAT GET A DEFAULT ARE THE ONLY THREE WITH A FORMAT WORTH ASSERTING. Every
 * other arm returns `[]` explicitly rather than falling through a `default`, for the reason the class
 * docblock of {@see ValueShape} gives: a thirty-second field type must be a PHPStan error
 * here too, not a silent "no defaults".
 */
final class DefaultFieldRules
{
    /**
     * An `email`: something, an `@`, a host with at least one dot and a two-character-or-longer last
     * label. Deliberately permissive — the job is to catch `bob` and `bob@`, not to adjudicate RFC 5322.
     */
    private const EMAIL = '[^@\s]+@[^@\s]+\.[^@\s]{2,}';

    /** A `url`: an explicit http/https scheme and a non-empty rest. A scheme-less host is refused. */
    private const URL = 'https?://[^\s]+';

    /**
     * A `phone`: an optional leading `+`, then a digit OR an opening bracket, then 5-19 more digits,
     * spaces, brackets or hyphens. No national format is assumed — this is a multi-tenant product and a Philippine mobile,
     * a US landline and an E.164 string all have to pass.
     */
    private const PHONE = '\+?[0-9(][0-9\s()\-]{5,19}';

    /**
     * The validation rows a newly-added field of this type should arrive with.
     *
     * @return list<array{rule_type: ValidationRuleType, rule_value: string, error_message: string}>
     */
    public static function for(FieldType $type): array
    {
        return match ($type) {
            FieldType::Email => [self::pattern(self::EMAIL, 'Enter a valid email address.')],
            FieldType::Url => [self::pattern(self::URL, 'Enter a valid web address, starting with http:// or https://.')],
            FieldType::Phone => [self::pattern(self::PHONE, 'Enter a valid phone number.')],

            FieldType::ShortText, FieldType::LongText,
            FieldType::Integer, FieldType::Decimal, FieldType::Calculated,
            FieldType::Date, FieldType::Time, FieldType::Datetime, FieldType::Duration,
            FieldType::SingleSelect, FieldType::MultiSelect, FieldType::Dropdown, FieldType::YesNo,
            FieldType::CascadingSelect, FieldType::LikertScale, FieldType::LikertMatrix,
            FieldType::Geopoint, FieldType::Geotrace, FieldType::Geoshape,
            FieldType::FileUpload, FieldType::ImageCapture, FieldType::AudioCapture,
            FieldType::VideoCapture, FieldType::Signature,
            FieldType::Note, FieldType::PageBreak, FieldType::Hidden, FieldType::Matrix => [],
        };
    }

    /**
     * @return array{rule_type: ValidationRuleType, rule_value: string, error_message: string}
     */
    private static function pattern(string $body, string $message): array
    {
        return [
            'rule_type' => ValidationRuleType::Pattern,
            'rule_value' => $body,
            'error_message' => $message,
        ];
    }
}
