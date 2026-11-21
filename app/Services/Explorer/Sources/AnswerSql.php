<?php

declare(strict_types=1);

namespace App\Services\Explorer\Sources;

/**
 * How a stored answer is read in the explorer's SQL, shared by {@see AnswersSource} (one value per question) and
 * {@see GridRowsSource} (one value per grid cell) — M247 moved these here from `AnswersSource` unchanged.
 *
 * ── A value that does not parse is no value ─────────────────────────────────────────────────────────────
 * Answers are stored as submitted and a question can change type between versions. Every numeric and date
 * expression is therefore guarded: text that is not a number contributes nothing to a sum, and is never a cast
 * error that takes the whole chart down.
 */
final class AnswerSql
{
    /**
     * An answer as a number, or NULL. A jsonb number is taken as it is; a string only if it is digits with
     * an optional sign and decimal part. The pattern is written without `?` because a raw `?` in a
     * statement is a placeholder to the driver.
     *
     * @param  literal-string  $text
     * @param  literal-string  $json
     * @return literal-string
     */
    public static function number(string $text, string $json): string
    {
        return "(case when jsonb_typeof({$json}) = 'number' then {$text}::numeric"
            ." when jsonb_typeof({$json}) = 'string' and {$text} ~ '^-{0,1}[0-9]{1,30}([.][0-9]{1,30}){0,1}$' then {$text}::numeric end)";
    }

    /**
     * An answer as a date, or NULL — for a date, and for the date part of a date-and-time.
     *
     * Two guards, nested so the second is only evaluated once the first has held: the text opens with a
     * well-formed `YYYY-MM-DD`, and the day exists in that month. Without the second, `2026-02-30` passes
     * the pattern and `make_date` raises, which would fail the whole query for one bad row. (PostgreSQL 16
     * has `pg_input_is_valid` for this; the testing server runs 15.)
     *
     * @param  literal-string  $text
     * @return literal-string
     */
    public static function date(string $text): string
    {
        $year = "substr({$text}, 1, 4)::int";
        $month = "substr({$text}, 6, 2)::int";
        $day = "substr({$text}, 9, 2)::int";

        return "(case when {$text} ~ '^[1-9][0-9]{3}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])' then"
            ." case when {$day} <= extract(day from (make_date({$year}, {$month}, 1) + interval '1 month' - interval '1 day'))::int"
            ." then make_date({$year}, {$month}, {$day}) end end)";
    }

    /**
     * The choice labels an author wrote, so a chart reads "Female" where the answer stores `female`: value ⇒ label,
     * or null when there are none (a choice list from elsewhere, whose stored text is its own label).
     *
     * @return array<string, string>|null
     */
    public static function labels(mixed $options): ?array
    {
        if (! is_array($options) || $options === []) {
            return null;
        }

        $out = [];

        foreach ($options as $option) {
            if (is_array($option) && isset($option['value']) && is_scalar($option['value'])) {
                $label = $option['label'] ?? null;
                $out[(string) $option['value']] = is_string($label) && $label !== '' ? $label : (string) $option['value'];
            }
        }

        return $out === [] ? null : $out;
    }
}
