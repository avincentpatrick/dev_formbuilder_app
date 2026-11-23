<?php

declare(strict_types=1);

namespace App\Exceptions\Explorer;

use App\Support\Explorer\ExplorerQuery;
use InvalidArgumentException;

/**
 * A data-explorer declaration that cannot be run (ADR-0025 §D2).
 *
 * Thrown from two places and it matters that they share a type. {@see ExplorerQuery}'s constructor refuses
 * a declaration that breaks one of its own bounds, whatever data it names. The catalogue refuses one that
 * names a source, a field or a form this reader cannot use. Both reach the page as a sentence in place of
 * the chart, and both reach a saved chart the same way when what it names has since changed — a stated
 * refusal rather than a wrong number.
 */
final class InvalidExplorerQueryException extends InvalidArgumentException
{
    private function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function malformed(string $detail): self
    {
        return new self("This chart cannot be read: {$detail}.", 'malformed_definition');
    }

    public static function unknownSource(string $source): self
    {
        return new self("There is no data source called '{$source}'.", 'unknown_source');
    }

    public static function sourceForbidden(string $label): self
    {
        return new self("You do not have access to {$label}.", 'source_forbidden');
    }

    public static function formRequired(): self
    {
        return new self('Choose the form whose answers this chart reads.', 'form_required');
    }

    public static function formUnavailable(): self
    {
        return new self('The form this chart reads no longer exists, or you can no longer open it.', 'form_unavailable');
    }

    /** M247 (`D231`): a chart that counts a grid's rows must say which grid. */
    public static function gridRequired(): self
    {
        return new self('Choose the grid whose rows this chart counts.', 'grid_required');
    }

    /** M247: the grid is gone from the form, is marked personal or sensitive, or cannot be charted by row. */
    public static function gridUnavailable(): self
    {
        return new self('The grid this chart counts is no longer in the form, or cannot be charted by row.', 'grid_unavailable');
    }

    /** M247: only a source that counts a grid's rows reads a grid. */
    public static function gridNotAllowed(string $label): self
    {
        return new self("A chart of {$label} does not read a grid.", 'grid_not_allowed');
    }

    public static function unknownField(string $field): self
    {
        return new self("'{$field}' is not a field of this data source any more.", 'unknown_field');
    }

    public static function fieldNotGroupable(string $label): self
    {
        return new self("{$label} is a number, so a chart cannot be grouped by it. Use it as a value instead.", 'field_not_groupable');
    }

    public static function bucketRequired(string $label): self
    {
        return new self("Choose how to group {$label}: by day, week, month and so on.", 'bucket_required');
    }

    public static function bucketNotAllowed(string $label): self
    {
        return new self("{$label} cannot be grouped that way.", 'bucket_not_allowed');
    }

    public static function fieldNotAggregable(string $label, string $aggregate): self
    {
        return new self("{$aggregate} needs a number, and {$label} is not one.", 'field_not_aggregable');
    }

    public static function measureNeedsField(string $aggregate): self
    {
        return new self("Choose the field to take the {$aggregate} of.", 'measure_needs_field');
    }

    public static function operatorNotAllowed(string $label): self
    {
        return new self("{$label} cannot be filtered that way.", 'operator_not_allowed');
    }

    public static function filterNeedsValue(string $label): self
    {
        return new self("The filter on {$label} has no value to compare with.", 'filter_needs_value');
    }

    public static function duplicateDimension(): self
    {
        return new self('A chart cannot be grouped by the same field twice.', 'duplicate_dimension');
    }

    public static function chartShape(string $chart, string $detail): self
    {
        return new self("A {$chart} chart {$detail}.", 'chart_shape');
    }

    public static function tooMany(string $what, int $max): self
    {
        return new self("A chart takes at most {$max} {$what}.", 'too_many');
    }

    public static function rangeMissing(): self
    {
        return new self('A chart must state a date range.', 'range_missing');
    }

    public static function rangeInverted(): self
    {
        return new self('The end of the range falls before its start.', 'range_inverted');
    }

    public static function rangeTooLong(int $days, int $max): self
    {
        return new self("A range of {$days} days is longer than the {$max}-day maximum.", 'range_too_long');
    }

    public static function unknownTimezone(string $timezone): self
    {
        return new self("'{$timezone}' is not a known time zone.", 'unknown_timezone');
    }

    public static function limitOutOfRange(int $max): self
    {
        return new self("A chart shows between 1 and {$max} groups.", 'limit_out_of_range');
    }

    public static function tooManyPeriods(int $periods, int $max): self
    {
        return new self("That range has {$periods} periods, more than the {$max} a chart can show. Group by a longer period or shorten the range.", 'too_many_periods');
    }

    /** M250 (`D234`): a source offers only the chart types it can fill — the agreement source only its list. */
    public static function chartNotAllowed(string $chart, string $source): self
    {
        return new self("“{$chart}” is not offered for {$source}.", 'chart_not_allowed');
    }

    /** M250: an agreement list's own rules — what it names, its threshold, its minimum number of views. */
    public static function agreementShape(string $detail): self
    {
        return new self("An agreement list {$detail}.", 'chart_shape');
    }

    /** M250: a picked question is gone from the form, is marked personal or sensitive, or is not a choice any more. */
    public static function unknownItem(string $pick): self
    {
        return new self("'{$pick}' is not a question this agreement list can report on any more. Open the list and choose its questions again.", 'unknown_item');
    }

    /** M250: so many answers that reading only some would change which answer is the most common. */
    public static function tooManyAnswers(int $max): self
    {
        return new self('These questions hold more than '.number_format($max).' different answers between them. Report on fewer questions at a time.', 'too_many_answers');
    }

    /** Machine-readable, so the page can branch on it without parsing the sentence. */
    public function reason(): string
    {
        return $this->reason;
    }
}
