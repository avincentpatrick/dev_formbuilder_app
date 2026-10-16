<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of report a piece of feedback is (M175, `R-2ce2f274`, `D127`; data-dictionary §21) — chosen by
 * the reporter on the panel, shown as a badge and a filter on both feedback lists, and the first thing the
 * dev session's triage reads (`D139`: a bug is filed `early-testing`, a suggestion `during-testing`, a
 * question answered in a draft the user approves).
 *
 * `feedback_reports.type` carries `feedback_reports_type_check`, generated from {@see self::values()}. The
 * column is nullable only so the reports sent before this enum existed keep their place; a new report must
 * name one (`StoreFeedbackRequest`).
 */
enum FeedbackType: string
{
    case Bug = 'bug';
    case Suggestion = 'suggestion';
    case Question = 'question';

    /** Human label for the panel, the badge, the filter and the pull digest (single source). */
    public function label(): string
    {
        return match ($this) {
            self::Bug => 'Bug',
            self::Suggestion => 'Suggestion',
            self::Question => 'Question',
        };
    }

    /**
     * Every stored value, in declaration order — the source of the CHECK constraint.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The filter catalog for the console and the tenant list.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
