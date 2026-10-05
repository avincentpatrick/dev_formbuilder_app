<?php

declare(strict_types=1);

namespace App\Services\Ocr\Bakeoff;

use App\Enums\FieldType;
use App\Services\Ocr\OcrText;
use DateInterval;
use DateTimeInterface;

/**
 * What a correct answer typed into the answer sheet means, and whether the reader's value equals it (M136).
 *
 * ── ONE SHAPE ON BOTH SIDES ────────────────────────────────────────────────────────────────────────────────
 * A typed answer is turned into the shape `OcrAnswerReader` emits for the field's type — a date as `YYYY-MM-DD`, a
 * choice as the option's value, several choices as a list of values, a yes/no as a boolean — and then both sides go
 * through {@see canonical()} before they are compared. So "the reader read it right" means exactly "a reviewer would not
 * have to change it", and the leniency is the same in both directions.
 *
 * ── THE LENIENCY, STATED ONCE (the report prints it) ───────────────────────────────────────────────────────
 * Text ignores letter case and repeated spaces (the paper asks for capitals). An email or web address ignores case and
 * spaces. A phone number is its digits, leading zeros aside — Excel drops a leading zero from a number typed into a
 * cell. Numbers compare as numbers. Several choices compare as a set. Everything else compares exactly.
 *
 * ⛔ A CELL THAT CANNOT BE UNDERSTOOD IS NEVER GUESSED AT. It comes back `ok: false` with the reason, the harness lists
 * it and refuses to score that field, and the run exits with a failure — a mistyped correct answer would otherwise
 * count against the reader and move the G9 figure.
 */
final class OcrBakeoffAnswers
{
    /** How the report describes the comparison. */
    public const string LENIENCY = 'Text ignores letter case and repeated spaces; an email or web address ignores case and spaces; a phone number is compared by its digits, leading zeros aside; numbers compare as numbers; several choices compare as a set; everything else exactly.';

    /**
     * The stored-shape value of one typed answer. A blank cell is a blank answer (`value` null).
     *
     * @return array{ok: true, value: mixed}|array{ok: false, reason: string}
     */
    public static function expected(OcrBakeoffQuestion $question, mixed $raw): array
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return ['ok' => true, 'value' => null];
        }

        return match ($question->type) {
            FieldType::Integer => self::integer($raw),
            FieldType::Decimal => self::decimal($raw),
            FieldType::Date => self::date($raw),
            FieldType::Time => self::time($raw),
            FieldType::Datetime => self::datetime($raw),
            FieldType::Duration => self::duration($raw),
            FieldType::YesNo => self::yesNo($raw),
            FieldType::SingleSelect, FieldType::Dropdown, FieldType::LikertScale => $question->options === []
                ? self::text($raw)  // a list from another form prints a write-in box, and the text written is the answer
                : self::choice($question, $raw),
            FieldType::MultiSelect => self::choices($question, $raw),
            FieldType::CascadingSelect => self::cascade($question, $raw),
            default => self::text($raw),
        };
    }

    /** How to type a correct answer for this question — the template's second row. Beside {@see expected()} on purpose. */
    public static function hint(OcrBakeoffQuestion $question): string
    {
        $labels = self::labels($question->options);

        return match ($question->type) {
            FieldType::Date => 'a date, typed 2026-10-08',
            FieldType::Time => 'a time, typed 14:05',
            FieldType::Datetime => 'a date and time, typed 2026-10-08 14:05',
            FieldType::Duration => 'hours:minutes, typed 1:30',
            FieldType::Integer => 'a whole number',
            FieldType::Decimal => 'a number',
            FieldType::YesNo => 'yes or no',
            FieldType::SingleSelect, FieldType::Dropdown, FieldType::LikertScale => $question->options === [] ? 'what was written' : "one of: {$labels}",
            FieldType::MultiSelect => "any of: {$labels} — separate several with ;",
            FieldType::CascadingSelect => 'each level, separated by >',
            default => 'what was written',
        };
    }

    /** Whether the reader's value is the correct answer, after both are put in {@see canonical()} form. */
    public static function same(?FieldType $type, mixed $expected, mixed $read): bool
    {
        return self::canonical($type, $expected) === self::canonical($type, $read);
    }

    /**
     * Whether a wrong date would be right with its day and month swapped — the mark of a correct answer typed into a
     * spreadsheet that reads dates month first.
     */
    public static function daySwapped(?FieldType $type, mixed $expected, mixed $read): bool
    {
        if (($type !== FieldType::Date && $type !== FieldType::Datetime) || ! is_string($expected) || ! is_string($read)) {
            return false;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(.*)$/', $expected, $m) !== 1 || $m[2] === $m[3]) {
            return false;
        }

        return checkdate((int) $m[3], (int) $m[2], (int) $m[1]) && "{$m[1]}-{$m[3]}-{$m[2]}{$m[4]}" === $read;
    }

    /** The comparison form of a value of `$type`, from either side. */
    public static function canonical(?FieldType $type, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($type) {
            FieldType::Integer, FieldType::Decimal => is_scalar($value) && is_numeric((string) $value) ? self::number((string) $value) : self::spaced(self::scalar($value)),
            FieldType::Email, FieldType::Url => strtolower(str_replace(' ', '', self::scalar($value))),
            FieldType::Phone => ltrim((string) preg_replace('/\D/', '', self::scalar($value)), '0'),
            FieldType::MultiSelect => self::sortedList($value),
            FieldType::CascadingSelect => is_array($value) ? array_values(array_map(self::scalar(...), $value)) : self::scalar($value),
            FieldType::YesNo, FieldType::Duration => $value,
            FieldType::SingleSelect, FieldType::Dropdown, FieldType::LikertScale, FieldType::Date, FieldType::Time, FieldType::Datetime => self::scalar($value),
            default => mb_strtoupper(self::spaced(self::scalar($value))),
        };
    }

    // ── one parser per answer shape ──────────────────────────────────────────────────────────────────────

    /** @return array{ok: true, value: mixed}|array{ok: false, reason: string} */
    private static function text(mixed $raw): array
    {
        if ($raw instanceof DateTimeInterface || $raw instanceof DateInterval) {
            return self::refuse('the spreadsheet turned this text into a date or time; type it again as text');
        }

        return ['ok' => true, 'value' => self::spaced(self::scalar($raw))];
    }

    /** @return array{ok: true, value: mixed}|array{ok: false, reason: string} */
    private static function integer(mixed $raw): array
    {
        $text = str_replace(' ', '', self::scalar($raw));

        return preg_match('/^-?\d+$/', $text) === 1
            ? ['ok' => true, 'value' => self::number($text)]
            : self::refuse('not a whole number');
    }

    /** @return array{ok: true, value: mixed}|array{ok: false, reason: string} */
    private static function decimal(mixed $raw): array
    {
        $text = str_replace([' ', ','], ['', '.'], self::scalar($raw));

        return preg_match('/^-?\d+(\.\d+)?$/', $text) === 1
            ? ['ok' => true, 'value' => self::number($text)]
            : self::refuse('not a number');
    }

    /** @return array{ok: true, value: mixed}|array{ok: false, reason: string} */
    private static function date(mixed $raw): array
    {
        if ($raw instanceof DateTimeInterface) {
            return ['ok' => true, 'value' => $raw->format('Y-m-d')];
        }

        $ymd = self::ymd(trim(self::scalar($raw)));

        return $ymd === null ? self::refuse('not a date; type it as 2026-10-08') : ['ok' => true, 'value' => $ymd];
    }

    /** @return array{ok: true, value: mixed}|array{ok: false, reason: string} */
    private static function time(mixed $raw): array
    {
        $hm = self::hm($raw);

        return $hm === null ? self::refuse('not a time; type it as 14:05') : ['ok' => true, 'value' => $hm];
    }

    /** @return array{ok: true, value: mixed}|array{ok: false, reason: string} */
    private static function datetime(mixed $raw): array
    {
        if ($raw instanceof DateTimeInterface) {
            return ['ok' => true, 'value' => $raw->format('Y-m-d\TH:i')];
        }

        $parts = preg_split('/[\sT]+/u', trim(self::scalar($raw)), 2);
        $ymd = self::ymd($parts[0] ?? '');
        $hm = self::hm($parts[1] ?? '');

        return $ymd === null || $hm === null
            ? self::refuse('not a date and time; type it as 2026-10-08 14:05')
            : ['ok' => true, 'value' => "{$ymd}T{$hm}"];
    }

    /** @return array{ok: true, value: mixed}|array{ok: false, reason: string} */
    private static function duration(mixed $raw): array
    {
        if ($raw instanceof DateInterval) {
            return ['ok' => true, 'value' => ($raw->d * 24 + $raw->h) * 3600 + $raw->i * 60];
        }
        if ($raw instanceof DateTimeInterface) {
            return ['ok' => true, 'value' => (int) $raw->format('G') * 3600 + (int) $raw->format('i') * 60];
        }

        if (preg_match('/^(\d+):([0-5]\d)$/', trim(self::scalar($raw)), $m) !== 1) {
            return self::refuse('not a duration; type hours and minutes as 1:30');
        }

        return ['ok' => true, 'value' => (int) $m[1] * 3600 + (int) $m[2] * 60];
    }

    /** @return array{ok: true, value: mixed}|array{ok: false, reason: string} */
    private static function yesNo(mixed $raw): array
    {
        if (is_bool($raw)) {
            return ['ok' => true, 'value' => $raw];
        }

        return match (OcrText::normal(self::scalar($raw))) {
            'yes', 'y', 'oo', 'true', '1' => ['ok' => true, 'value' => true],
            'no', 'n', 'hindi', 'false', '0' => ['ok' => true, 'value' => false],
            default => self::refuse('not yes or no'),
        };
    }

    /** @return array{ok: true, value: mixed}|array{ok: false, reason: string} */
    private static function choice(OcrBakeoffQuestion $question, mixed $raw): array
    {
        $value = self::option($question->options, self::scalar($raw));

        return $value === null ? self::refuse('not one of: '.self::labels($question->options)) : ['ok' => true, 'value' => $value];
    }

    /** @return array{ok: true, value: mixed}|array{ok: false, reason: string} */
    private static function choices(OcrBakeoffQuestion $question, mixed $raw): array
    {
        $values = [];
        foreach (preg_split('/\s*[;,\n]\s*/u', trim(self::scalar($raw))) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            $value = self::option($question->options, $part);
            if ($value === null) {
                return self::refuse("\"{$part}\" is not one of: ".self::labels($question->options).'; separate several with ;');
            }
            $values[] = $value;
        }

        return ['ok' => true, 'value' => $values === [] ? null : array_values(array_unique($values))];
    }

    /**
     * Each level matched to an option at that level under the one chosen above it — the rule `OcrAnswerReader` reads a
     * cascading answer by.
     *
     * @return array{ok: true, value: mixed}|array{ok: false, reason: string}
     */
    private static function cascade(OcrBakeoffQuestion $question, mixed $raw): array
    {
        $levels = [];
        foreach ((array) ($question->config['levels'] ?? []) as $level) {
            $key = is_array($level) ? ($level['key'] ?? null) : $level;
            if (is_string($key)) {
                $levels[] = $key;
            }
        }

        $parts = array_values(array_filter(preg_split('/\s*(?:>|;|\/|\n)\s*/u', trim(self::scalar($raw))) ?: [], static fn (string $p): bool => $p !== ''));
        if (count($parts) > count($levels)) {
            return self::refuse('more levels than the question has; separate the levels with >');
        }

        $chosen = [];
        $parent = null;
        foreach ($parts as $i => $part) {
            $match = null;
            foreach ((array) ($question->config['options'] ?? []) as $option) {
                if (! is_array($option) || ($option['level'] ?? null) !== $levels[$i] || ($parent !== null && ($option['parent'] ?? null) !== $parent)) {
                    continue;
                }
                $value = $option['value'] ?? null;
                $label = $option['label'] ?? null;
                if (is_string($value) && (OcrText::normal($part) === OcrText::normal($value) || (is_string($label) && OcrText::normal($part) === OcrText::normal($label)))) {
                    $match = $value;
                    break;
                }
            }
            if ($match === null) {
                return self::refuse("\"{$part}\" is not a choice at level {$levels[$i]}".($parent === null ? '' : " under {$parent}"));
            }
            $chosen[] = $match;
            $parent = $match;
        }

        return ['ok' => true, 'value' => $chosen === [] ? null : $chosen];
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────

    private static function ymd(string $text): ?string
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $text, $m) === 1) {
            // Day first, the order the paper prints: DD MM YYYY.
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    private static function hm(mixed $raw): ?string
    {
        if ($raw instanceof DateTimeInterface) {
            return $raw->format('H:i');
        }
        if ($raw instanceof DateInterval) {
            return sprintf('%02d:%02d', $raw->h, $raw->i);
        }

        if (preg_match('/^(\d{1,2}):(\d{2})\s*([ap]\.?m\.?)?$/iu', trim(self::scalar($raw)), $m) !== 1) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];
        $half = strtolower(str_replace('.', '', $m[3] ?? ''));
        if ($half !== '') {
            if ($hour < 1 || $hour > 12) {
                return null;
            }
            $hour = ($hour % 12) + ($half === 'pm' ? 12 : 0);
        }

        return $hour > 23 || $minute > 59 ? null : sprintf('%02d:%02d', $hour, $minute);
    }

    /** @param  list<array{value: string, label: string}>  $options */
    private static function option(array $options, string $typed): ?string
    {
        $typed = OcrText::normal($typed);
        foreach ($options as $option) {
            if ($typed === OcrText::normal($option['value']) || $typed === OcrText::normal($option['label'])) {
                return $option['value'];
            }
        }

        return null;
    }

    /** @param  list<array{value: string, label: string}>  $options */
    private static function labels(array $options): string
    {
        return implode(' / ', array_column($options, 'label'));
    }

    /** A number's comparison form: no leading zeros, no trailing fractional zeros, no "-0". */
    private static function number(string $text): string
    {
        $negative = str_starts_with($text, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($text, '-+'), 2), 2, '');
        $whole = ltrim($whole, '0');
        $fraction = rtrim($fraction, '0');
        $out = ($whole === '' ? '0' : $whole).($fraction === '' ? '' : '.'.$fraction);

        return $negative && $out !== '0' ? '-'.$out : $out;
    }

    /** @return list<string>|string */
    private static function sortedList(mixed $value): array|string
    {
        if (! is_array($value)) {
            return self::scalar($value);
        }

        $list = array_values(array_unique(array_map(self::scalar(...), $value)));
        sort($list, SORT_STRING);

        return $list;
    }

    private static function spaced(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => $value == floor($value) && abs($value) < 1e15 ? (string) (int) $value : (string) $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i'),
            default => '',
        };
    }

    /** @return array{ok: false, reason: string} */
    private static function refuse(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
