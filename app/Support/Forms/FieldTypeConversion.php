<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Enums\AnalyticsFieldEligibility;
use App\Enums\ConversionDropReason;
use App\Enums\ConversionFamily;
use App\Enums\ConversionWarning;
use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\PrefillSource;
use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
use App\Enums\ValueShape;
use App\Exceptions\Forms\FormException;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Services\Expressions\Coercion;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\StructuralValidationGate;
use LogicException;

/**
 * Changing a question's type without deleting it (Increment M121 — `R-495abf48`, the overhaul plan's
 * `B5a`, first half). Pure and static: no container, no config, no database — so the whole table can be
 * proved by Unit tests that never boot the app, and {@see FormBuilderService} does the reading and the
 * writing around it.
 *
 * ⛔ THE KEY NEVER CHANGES, AND THAT IS THE WHOLE POINT. The workaround this replaces — delete the field
 * and add a new one — mints a new key, so every `${key}` in a condition, a rule expression or a formula
 * dangles; and it CASCADE-DELETES every other field's rule that names the field by id (both foreign keys
 * on `form_field_validations` are `cascadeOnDelete`). A conversion is an in-place UPDATE of one row, so
 * every reference survives by construction rather than by a rewrite pass.
 *
 * ⛔ WHICH TYPES MAY BECOME WHICH IS A RECORDED USER DECISION, NOT A DESIGN CHOICE MADE HERE. `D64` answered
 * "refuse across value shapes", and the `D64` amendment of 2026-10-01 fixed where the line falls — see
 * {@see ConversionFamily}. The single enforcement point is {@see self::plan()}: a read only ever plans the
 * targets {@see self::targetsFor()} offers, and the write recomputes the plan, so the two cannot disagree.
 *
 * ⛔ "A RULE SURVIVES IFF THE NEW SHAPE ALLOWS IT" IS NOT THE WHOLE RULE, AND THE OVERHAUL PLAN SAID IT WAS.
 * Two cases break it, both measured:
 *   - `hidden` is `ValueShape::Text`, so the shape rule would KEEP a length or pattern rule on a field
 *     becoming hidden — which publish then refuses ({@see StructuralValidationGate}, the hidden arm). A
 *     field arriving at `note` or `hidden` keeps no rule at all.
 *   - An `email` field's shipped format check is a `pattern` row, and `pattern` is allowed on every text
 *     type — so the shape rule would leave an email check on a field that has just become a phone number.
 *     The built-in check is recognised by its pattern ({@see DefaultFieldRules}) and swapped for the new
 *     type's own; a pattern the author wrote is kept.
 *
 * ⚠️ WHAT THIS CANNOT SEE: ANY OTHER FIELD. A plan describes one field. What a conversion does to the
 * fields that REFER to it — a multiple choice's `contains` meaning a different thing once it is a single
 * choice, an ordered comparison against a scale that is now a choice, a `${key}` that a note can no longer
 * answer — is the cross-field census that the HTTP half (`M122`) owns.
 */
final class FieldTypeConversion
{
    /** The three types whose config offers a capture source (`MediaEditor.vue` shows it for no others). */
    private const CAPTURE_TYPES = [FieldType::ImageCapture, FieldType::AudioCapture, FieldType::VideoCapture];

    /** Types whose answers are plain strings, so a default written for one reads the same in another. */
    private const PLAIN_TEXT = [FieldType::ShortText, FieldType::LongText, FieldType::Email, FieldType::Phone, FieldType::Url, FieldType::Hidden];

    /** Types whose answer is ONE option value, so a default written for one reads the same in another. */
    private const SINGLE_OPTION = [FieldType::SingleSelect, FieldType::Dropdown, FieldType::LikertScale];

    /**
     * ⛔ A `match` ON THE ENUM WITH NO `default` ARM: a thirty-second field type is a PHPStan level-8 error
     * here, never a silent member of some family.
     */
    public static function family(FieldType $type): ConversionFamily
    {
        return match ($type) {
            FieldType::ShortText, FieldType::LongText, FieldType::Email,
            FieldType::Phone, FieldType::Url => ConversionFamily::Text,

            FieldType::Integer, FieldType::Decimal => ConversionFamily::Number,

            FieldType::Date, FieldType::Time, FieldType::Datetime => ConversionFamily::Temporal,

            FieldType::SingleSelect, FieldType::MultiSelect, FieldType::Dropdown,
            FieldType::LikertScale => ConversionFamily::Choice,

            FieldType::Geopoint, FieldType::Geotrace, FieldType::Geoshape => ConversionFamily::Geo,

            FieldType::FileUpload, FieldType::ImageCapture, FieldType::AudioCapture,
            FieldType::VideoCapture, FieldType::Signature => ConversionFamily::Attachment,

            FieldType::Matrix, FieldType::LikertMatrix => ConversionFamily::Grid,

            FieldType::Calculated, FieldType::Duration, FieldType::YesNo,
            FieldType::CascadingSelect => ConversionFamily::Isolated,

            FieldType::Note, FieldType::Hidden => ConversionFamily::Parking,

            FieldType::PageBreak => ConversionFamily::Fixed,
        };
    }

    public static function compatible(FieldType $from, FieldType $to): bool
    {
        if ($from === $to) {
            return false;
        }

        $toFamily = self::family($to);

        return match (self::family($from)) {
            ConversionFamily::Fixed => false,
            ConversionFamily::Parking => $toFamily !== ConversionFamily::Fixed,
            ConversionFamily::Isolated => $toFamily === ConversionFamily::Parking,
            ConversionFamily::Text, ConversionFamily::Number, ConversionFamily::Temporal, ConversionFamily::Choice,
            ConversionFamily::Geo, ConversionFamily::Attachment, ConversionFamily::Grid => $toFamily === self::family($from) || $toFamily === ConversionFamily::Parking,
        };
    }

    /**
     * The types a field of this type may become, in the order a dialog lists them: its own family in
     * catalog order, then `note`, then `hidden`.
     *
     * @return list<FieldType>
     */
    public static function targetsFor(FieldType $from): array
    {
        $family = [];
        $parking = [];

        foreach (FieldType::cases() as $to) {
            if (! self::compatible($from, $to)) {
                continue;
            }

            if (self::family($to) === ConversionFamily::Parking) {
                $parking[] = $to;
            } else {
                $family[] = $to;
            }
        }

        return [...$family, ...$parking];
    }

    /**
     * What converting $field to $to would do.
     *
     * @param  list<FormFieldValidation>  $validations  the field's own rows, in (sequence, id) order
     * @param  array<string, mixed>  $targetDefaultConfig  the config a NEW field of $to starts with — supplied
     *                                                     by the service, whose private seed it is
     *
     * @throws FormException when the pair is not one {@see self::compatible()} admits
     */
    public static function plan(
        FormField $field,
        array $validations,
        FieldType $to,
        array $targetDefaultConfig,
        bool $inRepeatableSection,
    ): ConversionPlan {
        $from = $field->field_type;

        if (! self::compatible($from, $to)) {
            throw FormException::conversionRefused($from, $to);
        }

        $toParking = self::family($to) === ConversionFamily::Parking;
        $throughParking = $toParking || self::family($from) === ConversionFamily::Parking;

        // Read raw: an unsaved row (the Unit tests', and a field created without one) may carry no config.
        /** @var array<string, mixed> $source */
        $source = (array) $field->getAttribute('config');
        $config = $throughParking ? $targetDefaultConfig : self::inFamilyConfig($from, $to, $source, $targetDefaultConfig);

        [$kept, $dropped] = self::partitionRows($from, $to, $validations);
        $changes = self::changes($field, $to, $toParking);
        $after = self::columnsAfter($field, $changes);

        return new ConversionPlan(
            from: $from,
            to: $to,
            config: $config,
            configDropped: self::droppedKeys($source, $config),
            changes: $changes,
            kept: $kept,
            dropped: $dropped,
            added: self::addedDefaults($to, $kept),
            warnings: self::warnings($from, $to, $source, $config, $after, $inRepeatableSection),
        );
    }

    /**
     * The config of a conversion that stays inside one family. Anything reaching or leaving `note`/`hidden`
     * takes the target's default config instead, and never gets here.
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $targetDefault
     * @return array<string, mixed>
     */
    private static function inFamilyConfig(FieldType $from, FieldType $to, array $source, array $targetDefault): array
    {
        return match (self::family($from)) {
            // The choice family shares one config arm (`UpdateFieldRequest`'s `choices`), so the options —
            // with every option's `label_translations` and any key no rule enumerates — carry verbatim.
            ConversionFamily::Text, ConversionFamily::Number, ConversionFamily::Temporal,
            ConversionFamily::Choice, ConversionFamily::Geo => array_replace($targetDefault, $source),
            ConversionFamily::Attachment => self::mediaConfig($to, $source),
            ConversionFamily::Grid => self::gridConfig($to, $source, $targetDefault),
            ConversionFamily::Isolated, ConversionFamily::Parking, ConversionFamily::Fixed => throw new LogicException("No in-family conversion exists from {$from->value}."),
        };
    }

    /**
     * ⛔ A NON-EMPTY `accepted_types` REPLACES THE KIND'S DEFAULT ALLOWLIST RATHER THAN NARROWING IT, so an
     * image list carried onto an audio field would reject every audio upload. It resets on every
     * conversion between media kinds. A capture source survives only onto a type that can show one.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private static function mediaConfig(FieldType $to, array $source): array
    {
        $config = $source;

        if (is_array($config['accepted_types'] ?? null) && $config['accepted_types'] !== []) {
            unset($config['accepted_types']);
        }

        if (($config['capture_source'] ?? null) !== null && ! in_array($to, self::CAPTURE_TYPES, true)) {
            unset($config['capture_source']);
        }

        return $config;
    }

    /**
     * `cells` belongs to `matrix` alone. Leaving it, they are dropped; arriving, they start empty and the
     * plan warns — cells are never invented.
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $targetDefault
     * @return array<string, mixed>
     */
    private static function gridConfig(FieldType $to, array $source, array $targetDefault): array
    {
        $config = array_replace($targetDefault, $source);

        if ($to === FieldType::LikertMatrix) {
            unset($config['cells']);
        }

        return $config;
    }

    /**
     * @param  list<FormFieldValidation>  $validations
     * @return array{0: list<FormFieldValidation>, 1: list<array{row: FormFieldValidation, reason: ConversionDropReason}>}
     */
    private static function partitionRows(FieldType $from, FieldType $to, array $validations): array
    {
        $dropsAllRules = self::family($to) === ConversionFamily::Parking;
        $toShape = ValueShape::for($to);
        $kept = [];
        $dropped = [];

        foreach ($validations as $row) {
            $reason = match (true) {
                // Hidden may carry no rule the respondent could fail, and a note answers nothing.
                $dropsAllRules => ConversionDropReason::TargetTakesNoRules,
                // An expression row: the expression gate owns what it may say about the new type.
                $row->rule_type === null => null,
                ! $toShape->allows($row->rule_type) => ConversionDropReason::NotAllowedForShape,
                $row->rule_type === ValidationRuleType::Pattern
                    && self::isDefaultRow($from, $row) && ! self::isDefaultRow($to, $row) => ConversionDropReason::TypeDefault,
                default => null,
            };

            if ($reason === null) {
                $kept[] = $row;
            } else {
                $dropped[] = ['row' => $row, 'reason' => $reason];
            }
        }

        return [$kept, $dropped];
    }

    /** Whether $row is one of $type's shipped format checks — recognised by its pattern, not its wording. */
    private static function isDefaultRow(FieldType $type, FormFieldValidation $row): bool
    {
        foreach (DefaultFieldRules::for($type) as $default) {
            if ($row->rule_type === $default['rule_type'] && $row->rule_value === $default['rule_value']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The new type's shipped checks, after the rows that stay — unless one is already there.
     *
     * @param  list<FormFieldValidation>  $kept
     * @return list<array{sequence: int, rule_type: ValidationRuleType, rule_value: string, error_message: string}>
     */
    private static function addedDefaults(FieldType $to, array $kept): array
    {
        $next = $kept === [] ? 0 : max(array_map(static fn (FormFieldValidation $row): int => $row->sequence, $kept)) + 1;
        $added = [];

        foreach (DefaultFieldRules::for($to) as $default) {
            if (self::alreadyKept($kept, $default)) {
                continue;
            }

            $added[] = [
                'sequence' => $next++,
                'rule_type' => $default['rule_type'],
                'rule_value' => $default['rule_value'],
                'error_message' => $default['error_message'],
            ];
        }

        return $added;
    }

    /**
     * @param  list<FormFieldValidation>  $kept
     * @param  array{rule_type: ValidationRuleType, rule_value: string, error_message: string}  $default
     */
    private static function alreadyKept(array $kept, array $default): bool
    {
        foreach ($kept as $row) {
            if ($row->rule_type === $default['rule_type'] && $row->rule_value === $default['rule_value']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The field columns a conversion rewrites, in a fixed order.
     *
     * ⚠️ THERE IS DELIBERATELY NO "CONDITIONAL WITH NO GOVERNING RULE BECOMES OPTIONAL" STEP FOR A
     * CONVERSION WITHIN A FAMILY. `required_if`/`required_with` are allowed on every shape that answers, and
     * the default swap touches only `pattern`, so a governing rule is never dropped there — the only field
     * such a step could fire on is one already Conditional with no rule, which publish already refuses and
     * which this would silently rewrite. A test pins that a governing rule always survives.
     *
     * @return list<array{column: string, from: mixed, to: mixed}>
     */
    private static function changes(FormField $field, FieldType $to, bool $toParking): array
    {
        $required = $field->is_required;
        $changes = [];

        $forcesOptional = $toParking && $required !== RequiredMode::Optional;
        if ($forcesOptional) {
            $changes[] = ['column' => 'is_required', 'from' => $required, 'to' => RequiredMode::Optional];
        }

        // A note has no answer: nothing to default, nothing to index. Hidden keeps both — its default is its
        // fixed prefill, and it is indexable.
        if ($to === FieldType::Note) {
            $defaultValue = self::defaultValue($field);
            if ($defaultValue !== null) {
                $changes[] = ['column' => 'default_value', 'from' => $defaultValue, 'to' => null];
            }
            if (self::defaultIsExpression($field)) {
                $changes[] = ['column' => 'default_value_is_expression', 'from' => true, 'to' => false];
            }
            if ((bool) $field->is_queryable) {
                $changes[] = ['column' => 'is_queryable', 'from' => true, 'to' => false];
            }
            if ($field->indexed_data_type !== null) {
                $changes[] = ['column' => 'indexed_data_type', 'from' => $field->indexed_data_type, 'to' => null];
            }
        }

        return $changes;
    }

    /**
     * The columns the warnings read, as they will be AFTER the conversion.
     *
     * @param  list<array{column: string, from: mixed, to: mixed}>  $changes
     * @return array{default_value: ?string, default_value_is_expression: bool, is_queryable: bool, indexed_data_type: ?IndexedDataType}
     */
    private static function columnsAfter(FormField $field, array $changes): array
    {
        $after = [
            'default_value' => self::defaultValue($field),
            'default_value_is_expression' => self::defaultIsExpression($field),
            'is_queryable' => (bool) $field->is_queryable,
            'indexed_data_type' => $field->indexed_data_type,
        ];

        foreach ($changes as $change) {
            if (array_key_exists($change['column'], $after)) {
                $after[$change['column']] = $change['to'];
            }
        }

        /** @var array{default_value: ?string, default_value_is_expression: bool, is_queryable: bool, indexed_data_type: ?IndexedDataType} $after */
        return $after;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $config
     * @param  array{default_value: ?string, default_value_is_expression: bool, is_queryable: bool, indexed_data_type: ?IndexedDataType}  $after
     * @return list<ConversionWarning>
     */
    private static function warnings(FieldType $from, FieldType $to, array $source, array $config, array $after, bool $inRepeatableSection): array
    {
        $raised = [];

        foreach (ConversionWarning::cases() as $warning) {
            $fires = match ($warning) {
                // Only a gap the conversion OPENS: a field that was already unfinished is not news.
                ConversionWarning::NeedsSetup => self::incomplete($to, $config) && ! self::incomplete($from, $source),
                ConversionWarning::CalculatedNeedsFormula => $to === FieldType::Calculated
                    && ! self::filled($config['calculated_formula'] ?? null),
                ConversionWarning::HiddenNeedsPrefillSource => $to === FieldType::Hidden
                    && PrefillSource::for($to, $config) === PrefillSource::None,
                // An expression default on a hidden field would be stored verbatim as the answer.
                ConversionWarning::DefaultValueFormat => (self::filled($after['default_value']) && ! self::sameAnswerFormat($from, $to))
                    || ($to === FieldType::Hidden && $after['default_value_is_expression']),
                ConversionWarning::IndexingUnavailable => $after['is_queryable'] && self::indexable($from) && ! self::indexable($to),
                ConversionWarning::IndexedTypeMayNotSuit => $after['is_queryable'] && $after['indexed_data_type'] !== null
                    && self::indexable($to) && self::indexKindChanges($from, $to),
                ConversionWarning::ScaleValuesNotNumeric => $to === FieldType::LikertScale && self::hasNonNumericOption($config),
                // Only a refusal the conversion CREATES: a geo field already in a repeat was already refused.
                ConversionWarning::RepeatableSectionRefusesType => $inRepeatableSection
                    && self::refusedInRepeat($to) && ! self::refusedInRepeat($from),
            };

            if ($fires) {
                $raised[] = $warning;
            }
        }

        return $raised;
    }

    /**
     * The config the publish gate would refuse as unfinished — its own dispatch, restated as a warning
     * rather than called, because the gate reads rows and this reads a plan. The service test proves the
     * two agree for every target a note can become.
     *
     * @param  array<string, mixed>  $config
     */
    private static function incomplete(FieldType $type, array $config): bool
    {
        return match (true) {
            ValueShape::for($type)->carriesOptionList() => self::emptyList($config['options'] ?? null),
            $type === FieldType::CascadingSelect => self::emptyList($config['levels'] ?? null) || self::emptyList($config['options'] ?? null),
            $type === FieldType::Matrix => self::emptyList($config['rows'] ?? null) || self::emptyList($config['columns'] ?? null)
                || self::emptyList($config['cells'] ?? null),
            $type === FieldType::LikertMatrix => self::emptyList($config['rows'] ?? null) || self::emptyList($config['columns'] ?? null),
            default => false,
        };
    }

    /** Whether a default written for $from reads as the same answer in $to. */
    private static function sameAnswerFormat(FieldType $from, FieldType $to): bool
    {
        return match (true) {
            in_array($from, self::PLAIN_TEXT, true) && in_array($to, self::PLAIN_TEXT, true) => true,
            $from === FieldType::Integer && $to === FieldType::Decimal => true,
            in_array($from, self::SINGLE_OPTION, true) && in_array($to, self::SINGLE_OPTION, true) => true,
            default => false,
        };
    }

    private static function indexable(FieldType $type): bool
    {
        return AnalyticsFieldEligibility::for($type)->isIndexable();
    }

    /** Whether the index type an author chose for $from was chosen for a different kind of value. */
    private static function indexKindChanges(FieldType $from, FieldType $to): bool
    {
        return ValueShape::for($from) !== ValueShape::for($to) || self::family($from) === ConversionFamily::Temporal;
    }

    /** The four types the publish gate refuses inside a repeatable section. */
    private static function refusedInRepeat(FieldType $type): bool
    {
        return $type->isComposite() || $type->isGeo() || $type->isMedia() || $type === FieldType::Hidden;
    }

    /** @param  array<string, mixed>  $config */
    private static function hasNonNumericOption(array $config): bool
    {
        $options = $config['options'] ?? null;

        if (! is_array($options)) {
            return false;
        }

        foreach ($options as $option) {
            if (is_array($option) && ! Coercion::isNumericLike($option['value'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The sorted top-level keys whose non-empty value the conversion loses or replaces.
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function droppedKeys(array $source, array $config): array
    {
        $dropped = [];

        foreach ($source as $key => $value) {
            if (self::filled($value) && (! array_key_exists($key, $config) || $config[$key] !== $value)) {
                $dropped[] = (string) $key;
            }
        }

        sort($dropped, SORT_STRING);

        return $dropped;
    }

    private static function defaultValue(FormField $field): ?string
    {
        $value = $field->getAttribute('default_value');

        return is_string($value) ? $value : null;
    }

    private static function defaultIsExpression(FormField $field): bool
    {
        return (bool) $field->getAttribute('default_value_is_expression');
    }

    private static function filled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    private static function emptyList(mixed $value): bool
    {
        return ! is_array($value) || $value === [];
    }
}
