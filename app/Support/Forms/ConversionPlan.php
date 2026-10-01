<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Enums\ConversionDropReason;
use App\Enums\ConversionWarning;
use App\Enums\FieldType;
use App\Enums\ValidationRuleType;
use App\Models\FormFieldValidation;
use App\Services\Forms\FormBuilderService;
use BackedEnum;

/**
 * What converting one field to one other type would do, computed BEFORE anything is written (Increment
 * M121). Produced only by {@see FieldTypeConversion::plan()}; applied only by
 * {@see FormBuilderService::convertField()}, which recomputes it inside the write and refuses if it
 * differs from the one the author confirmed.
 *
 * ⛔ THE FINGERPRINT EXISTS BECAUSE THE VERSION TOKEN CANNOT SEE WHAT A CONVERSION DEPENDS ON.
 * The builder's optimistic-concurrency token is the field's `updated_at` at SECOND precision, and an edit
 * that touches only validation rows never bumps it — a save whose field columns are unchanged issues no
 * UPDATE on `form_fields` at all ({@see FormBuilderService::rowVersion()}). So between the author reading
 * a plan and confirming it, a second tab can add a rule the conversion will drop and the token will not
 * move. {@see self::fingerprint()} hashes every CONTENT column of every rule row (never an id, so a
 * content-identical re-save does not refuse), the dropped config keys, the column changes and the
 * warnings. It deliberately does NOT hash config values or labels: an option relabelled in another tab
 * changes nothing the author was asked to confirm.
 *
 * ⛔ {@see self::toArray()} CARRIES NO IDS OF ANY KIND — no validation id, no related field id, no logic
 * group. The builder never sees a validation id (`BuilderPresenter::field()` sends none), so rows are
 * named by `sequence` + `rule_type` + `rule_value`, which it already holds.
 *
 * ⚠️ `lossless` means nothing the author wrote is removed or altered. ADDING a default rule does not make a
 * plan lossy, but it does make it need confirmation: the author has to have seen a new check appear.
 */
final class ConversionPlan
{
    /**
     * @param  array<string, mixed>  $config  the RESULT config (internal)
     * @param  list<string>  $configDropped  sorted top-level keys whose non-empty value is lost
     * @param  list<array{column: string, from: mixed, to: mixed}>  $changes  in a fixed column order
     * @param  list<FormFieldValidation>  $kept
     * @param  list<array{row: FormFieldValidation, reason: ConversionDropReason}>  $dropped
     * @param  list<array{sequence: int, rule_type: ValidationRuleType, rule_value: string, error_message: string}>  $added
     * @param  list<ConversionWarning>  $warnings  unique, in declaration order
     */
    public function __construct(
        public readonly FieldType $from,
        public readonly FieldType $to,
        public readonly array $config,
        public readonly array $configDropped,
        public readonly array $changes,
        public readonly array $kept,
        public readonly array $dropped,
        public readonly array $added,
        public readonly array $warnings,
    ) {}

    public function lossless(): bool
    {
        return $this->dropped === [] && $this->configDropped === [] && $this->changes === [];
    }

    /**
     * Whether the author must see this plan before it is applied. A plan that needs none may be applied
     * with no fingerprint at all — which is what lets the Text/Number variant switch (`R-a367bf9e`) flip a
     * field in one request.
     */
    public function requiresConfirmation(): bool
    {
        return ! $this->lossless() || $this->added !== [] || $this->warnings !== [];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode(
            self::canonical($this->fingerprintPayload()),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /**
     * The ids of the rows the conversion deletes. Internal — never sent to a client.
     *
     * @return list<string>
     */
    public function droppedIds(): array
    {
        return array_map(static fn (array $drop): string => (string) $drop['row']->getKey(), $this->dropped);
    }

    /**
     * The column writes the conversion makes on the field row. Internal.
     *
     * @return array<string, mixed>
     */
    public function fieldAttributes(): array
    {
        $attributes = ['field_type' => $this->to, 'config' => $this->config];

        foreach ($this->changes as $change) {
            $attributes[$change['column']] = $change['to'];
        }

        return $attributes;
    }

    /**
     * The public shape a confirmation dialog renders. No ids — see the class docblock.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->value,
            'to' => $this->to->value,
            'lossless' => $this->lossless(),
            'requires_confirmation' => $this->requiresConfirmation(),
            'fingerprint' => $this->fingerprint(),
            'config_dropped' => $this->configDropped,
            'changes' => array_map(static fn (array $change): array => [
                'column' => $change['column'],
                'from' => self::scalar($change['from']),
                'to' => self::scalar($change['to']),
            ], $this->changes),
            'kept' => array_map(static fn (FormFieldValidation $row): array => self::publicRow($row), $this->kept),
            'dropped' => array_map(static fn (array $drop): array => [
                ...self::publicRow($drop['row']),
                'reason' => $drop['reason']->value,
                'reason_message' => $drop['reason']->message(),
            ], $this->dropped),
            'added' => array_map(static fn (array $add): array => [
                'sequence' => $add['sequence'],
                'rule_type' => $add['rule_type']->value,
                'rule_value' => $add['rule_value'],
                'error_message' => $add['error_message'],
            ], $this->added),
            'warnings' => array_map(static fn (ConversionWarning $warning): array => [
                'code' => $warning->value,
                'message' => $warning->message(),
            ], $this->warnings),
        ];
    }

    /** @return array<string, mixed> */
    private function fingerprintPayload(): array
    {
        return [
            'v' => 1,
            'from' => $this->from->value,
            'to' => $this->to->value,
            'config_dropped' => $this->configDropped,
            'changes' => array_map(static fn (array $change): array => [
                $change['column'], self::scalar($change['from']), self::scalar($change['to']),
            ], $this->changes),
            'kept' => self::rowTuples($this->kept),
            'dropped' => self::droppedTuples($this->dropped),
            'added' => array_map(static fn (array $add): array => [
                $add['sequence'], $add['rule_type']->value, $add['rule_value'], $add['error_message'],
            ], $this->added),
            'warnings' => array_map(static fn (ConversionWarning $warning): string => $warning->value, $this->warnings),
        ];
    }

    /**
     * @param  list<FormFieldValidation>  $rows
     * @return list<list<mixed>>
     */
    private static function rowTuples(array $rows): array
    {
        return self::sorted(array_map(static fn (FormFieldValidation $row): array => self::rowTuple($row), $rows));
    }

    /**
     * @param  list<array{row: FormFieldValidation, reason: ConversionDropReason}>  $drops
     * @return list<list<mixed>>
     */
    private static function droppedTuples(array $drops): array
    {
        return self::sorted(array_map(
            static fn (array $drop): array => [self::rowTuple($drop['row']), $drop['reason']->value],
            $drops,
        ));
    }

    /**
     * Every persisted CONTENT column of a rule row — everything except `id`, `tenant_id`,
     * `form_version_id`, `form_field_id` and the timestamps. `related_form_field_id` is an id but it is
     * content here: it changes only when the rule's target changes, and it is hashed opaquely.
     *
     * @return list<mixed>
     */
    private static function rowTuple(FormFieldValidation $row): array
    {
        return [
            $row->sequence,
            $row->rule_type?->value,
            $row->operator?->value,
            $row->rule_value,
            $row->expression,
            $row->related_form_field_id,
            $row->error_message,
            $row->error_message_translations,
            $row->logic_group,
            $row->logic_operator?->value,
        ];
    }

    /**
     * Rows in an order that never depends on their ids.
     *
     * @param  list<list<mixed>>  $tuples
     * @return list<list<mixed>>
     */
    private static function sorted(array $tuples): array
    {
        usort($tuples, static fn (array $a, array $b): int => strcmp(
            json_encode(self::canonical($a), JSON_THROW_ON_ERROR),
            json_encode(self::canonical($b), JSON_THROW_ON_ERROR),
        ));

        return $tuples;
    }

    /**
     * Object keys sorted at every depth. ⚠️ Load-bearing, not tidiness: `jsonb` normalises key order, so
     * a translation map read back from the database need not arrive in the order it was written.
     */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(static fn (mixed $item): mixed => self::canonical($item), $value);
    }

    /** @return array<string, mixed> */
    private static function publicRow(FormFieldValidation $row): array
    {
        return [
            'sequence' => $row->sequence,
            'rule_type' => $row->rule_type?->value,
            'operator' => $row->operator?->value,
            'rule_value' => $row->rule_value,
            'expression' => $row->expression,
            'error_message' => $row->error_message,
        ];
    }

    private static function scalar(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
