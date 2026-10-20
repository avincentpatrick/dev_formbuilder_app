<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a feedback report came from (M179, `R-db9dec62`, `D150`; data-dictionary §21).
 *
 * - `button` — sent with the Feedback button: every report written before M179, and every one since. The
 *   column defaults to it, so no existing row was rewritten (`D137`). Seen by its reporter and by Owner/Admin.
 * - `relayed` — a comment that reached the team another way (in chat, in a PDF) and was loaded through the
 *   platform support API (`POST /api/platform/v1/feedback/relayed`, ADR-0023 §D7). Seen by every member of its
 *   workspace, under the **Testing-round comments** tab, so the testers can follow it and check the fix.
 *
 * `feedback_reports.source` carries `feedback_reports_source_check`, generated from {@see self::values()}.
 */
enum FeedbackSource: string
{
    case Button = 'button';
    case Relayed = 'relayed';

    /** Human label for the source filter and the source line (single source). */
    public function label(): string
    {
        return match ($this) {
            self::Button => 'Feedback button',
            self::Relayed => 'Testing-round comment',
        };
    }

    /**
     * Every stored value, in declaration order — the source of `feedback_reports_source_check`.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The filter catalog for the Owner/Admin list and the console.
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
