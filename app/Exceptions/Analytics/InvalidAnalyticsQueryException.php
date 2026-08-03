<?php

declare(strict_types=1);

namespace App\Exceptions\Analytics;

use App\Support\Analytics\AnalyticsQuery;
use InvalidArgumentException;

/**
 * A declaration that violates one of ADR-0011 §D7's three bounds, or is otherwise unrepresentable.
 *
 * Thrown from {@see AnalyticsQuery}'s constructor, which makes "bounded" a property of the TYPE rather than
 * a convention every call site has to remember. Two callers reach it by different routes and both matter:
 *
 *  - a request, where the FormRequest has already validated and this is defence-in-depth;
 *  - a **saved report view**, where the stored `definition` was written under an older shape or names
 *    something that has since changed. §D8 requires that case to degrade to a stated refusal rather than to
 *    a wrong number, and this exception is how it does.
 */
final class InvalidAnalyticsQueryException extends InvalidArgumentException
{
    private function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function rangeMissing(): self
    {
        return new self('An analytics query must state a date range.', 'range_missing');
    }

    public static function rangeInverted(): self
    {
        return new self('The end of the range falls before its start.', 'range_inverted');
    }

    public static function rangeTooLong(int $days, int $max): self
    {
        return new self("A range of {$days} days exceeds the {$max}-day maximum.", 'range_too_long');
    }

    public static function unknownTimezone(string $timezone): self
    {
        return new self("'{$timezone}' is not a known IANA time zone.", 'unknown_timezone');
    }

    public static function tooManyForms(int $count, int $max): self
    {
        return new self("A form selection of {$count} exceeds the maximum of {$max}.", 'too_many_forms');
    }

    public static function emptySelection(string $mode): self
    {
        return new self("A '{$mode}' selection names nothing to aggregate.", 'empty_selection');
    }

    public static function topNOutOfRange(int $max): self
    {
        return new self("A breakdown may return between 1 and {$max} categories.", 'top_n_out_of_range');
    }

    /** Unrecognised shape in a stored view's `definition`. */
    public static function malformed(string $detail): self
    {
        return new self("This saved view cannot be read: {$detail}.", 'malformed_definition');
    }

    /** Machine-readable, so an API error body and H24b can both branch on it without parsing prose. */
    public function reason(): string
    {
        return $this->reason;
    }
}
