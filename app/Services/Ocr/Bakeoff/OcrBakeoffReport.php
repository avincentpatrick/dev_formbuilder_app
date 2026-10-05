<?php

declare(strict_types=1);

namespace App\Services\Ocr\Bakeoff;

use App\Enums\FieldType;
use App\Support\Export\SpreadsheetCell;
use RuntimeException;

/**
 * Writes the bake-off's result for people (M136): `report.md` (the G9 figure, both threshold sweeps, the confidence
 * picture and every scan) and `fields.csv` (one row per scan and question, to sort and filter in a spreadsheet).
 *
 * The report says what it did NOT score as plainly as what it did — skipped files, unmatched columns, questions with
 * no column, answers it could not understand — because a figure measured over fewer fields than it looks is the
 * failure a harness like this exists to prevent.
 */
final class OcrBakeoffReport
{
    /**
     * @param  array<string, mixed>  $result  {@see OcrBakeoffScorer::score()}
     * @param  array<string, mixed>  $meta
     */
    public static function markdown(array $result, array $meta): string
    {
        /** @var array{auto: int, review: int} $thresholds */
        $thresholds = $result['thresholds'];
        /** @var array<string, mixed> $headline */
        $headline = $result['headline'];
        $out = [];

        $out[] = '# OCR bake-off — '.self::cell(self::str($meta['title'] ?? ''));
        $out[] = '';
        $out[] = 'Measured '.self::str($meta['generated_at'] ?? '').' · provider `'.self::str($meta['provider'] ?? '').'` · '
            .count(self::list($result['scans'])).' scan(s) in `'.self::str($meta['folder'] ?? '').'` · layout versions '
            .implode(', ', array_map(self::str(...), self::list($meta['versions'] ?? []))).' · thresholds auto '.$thresholds['auto'].', review '.$thresholds['review']
            .' · answers '.(is_string($meta['answers'] ?? null) ? '`'.$meta['answers'].'`' : 'none').(($meta['offline'] ?? false) === true ? ' · **offline** (cached answers only)' : '');
        $out[] = '';

        $out[] = '## G9 — fields needing manual correction';
        $out[] = '';
        if (($headline['fields'] ?? 0) === 0) {
            $out[] = '**Nothing was scored, so G9 is not measured.** '.(is_string($meta['answers'] ?? null)
                ? 'No answer row matched a scan that was read — see *What was not scored*.'
                : 'Pass `--answers=` with a sheet of correct answers to score the reading.');
        } else {
            $out[] = '**'.self::int($headline['needs_correction']).' of '.self::int($headline['fields']).' scored fields ('.self::pct($headline['rate']).') across '
                .self::int($headline['scans']).' scan(s) needed manual correction — '.(($headline['g9_pass'] ?? false) === true ? 'PASS' : 'FAIL')
                .' against G9\'s bar of under '.self::pct(OcrBakeoffScorer::G9_BAR).'.** G9 is stated for a clear, well-lit single-page scan; read the `condition` rows for that subset.';
            $out[] = '';
            $out[] = 'At auto '.$thresholds['auto'].' / review '.$thresholds['review'].': **'.self::int($headline['silent']).' silent error(s)** (a wrong value filled with no flag) and '
                .self::int($headline['flagged']).' field(s) flagged for review.';
            $out[] = '';
            $out[] = '| Condition | Scans | Fields | Need correction | Rate | G9 | Silent errors | Flagged |';
            $out[] = '|---|---:|---:|---:|---:|---|---:|---:|';
            foreach (array_merge(self::map($result['by_condition']), ['**all**' => $headline]) as $condition => $row) {
                $row = is_array($row) ? $row : [];
                $out[] = '| '.self::cell((string) $condition).' | '.self::int($row['scans'] ?? 0).' | '.self::int($row['fields'] ?? 0).' | '.self::int($row['needs_correction'] ?? 0)
                    .' | '.self::pct($row['rate'] ?? null).' | '.(($row['g9_pass'] ?? null) === null ? '—' : (($row['g9_pass'] === true) ? 'pass' : 'FAIL'))
                    .' | '.self::int($row['silent'] ?? 0).' | '.self::int($row['flagged'] ?? 0).' |';
            }
            $out[] = '';
            $out[] = '### Why fields needed correction';
            $out[] = '';
            $out[] = '| Cause | Fields |';
            $out[] = '|---|---:|';
            foreach (OcrBakeoffScorer::CAUSES as $code => $label) {
                $out[] = '| '.$label.' | '.self::int(self::map($headline['causes'] ?? [])[$code] ?? 0).' |';
            }
            $out[] = '';
            $out[] = '*Not found* and *unreadable* point at the layout and the matcher; *wrong* and *withheld* point at the recognizer and the thresholds.';
            $out[] = '';

            $out[] = '## The review threshold';
            $out[] = '';
            $out[] = 'Below it a value is withheld and the field is left for manual entry. Lower shows more right answers and more wrong ones.';
            $out[] = '';
            $out[] = '| Review at | Need correction | Rate | Right answer withheld | Wrong answer shown | Other |';
            $out[] = '|---:|---:|---:|---:|---:|---:|';
            foreach (self::list($result['review_sweep']) as $row) {
                $row = is_array($row) ? $row : [];
                $mark = ($row['review'] ?? null) === $thresholds['review'] ? ' ← configured' : '';
                $out[] = '| '.self::int($row['review'] ?? 0).$mark.' | '.self::int($row['needs_correction'] ?? 0).' | '.self::pct($row['rate'] ?? null)
                    .' | '.self::int($row['withheld_right'] ?? 0).' | '.self::int($row['wrong_shown'] ?? 0).' | '.self::int($row['other'] ?? 0).' |';
            }
            $out[] = '';

            $out[] = '## The auto threshold (review held at '.$thresholds['review'].')';
            $out[] = '';
            $out[] = 'At or above it a value is filled with no flag. A wrong value there is a silent error; below it the value is flagged for a look.';
            $out[] = '';
            $out[] = '| Auto at | Silent errors | Flagged | Right, not flagged |';
            $out[] = '|---:|---:|---:|---:|';
            foreach (self::list($result['auto_sweep']) as $row) {
                $row = is_array($row) ? $row : [];
                $mark = ($row['auto'] ?? null) === $thresholds['auto'] ? ' ← configured' : '';
                $out[] = '| '.self::int($row['auto'] ?? 0).$mark.' | '.self::int($row['silent'] ?? 0).' | '.self::int($row['flagged'] ?? 0).' | '.self::int($row['right_unflagged'] ?? 0).' |';
            }
            $out[] = '';

            $out[] = '## Confidence of right and wrong values';
            $out[] = '';
            $out[] = 'Every scored field that was read, by its confidence. A threshold belongs where the wrong ones stop.';
            $out[] = '';
            $out[] = '| Confidence | Right | Wrong |';
            $out[] = '|---|---:|---:|';
            foreach (self::map($result['histogram']) as $bucket => $row) {
                $row = is_array($row) ? $row : [];
                $out[] = '| '.$bucket.'–'.($bucket === 90 ? 100 : (int) $bucket + 9).' | '.self::int($row['right'] ?? 0).' | '.self::int($row['wrong'] ?? 0).' |';
            }
            $out[] = '';
        }

        $out[] = '## Per scan';
        $out[] = '';
        $out[] = '| Scan | Pages | Condition | Status | Version (found by) | Read | Blank | Unreadable | Not found | Scored | Need correction |';
        $out[] = '|---|---:|---|---|---|---:|---:|---:|---:|---:|---:|';
        $notes = [];
        foreach (self::list($result['scans']) as $scan) {
            $scan = is_array($scan) ? $scan : [];
            $counts = self::map($scan['counts'] ?? []);
            $version = is_int($scan['version'] ?? null) ? 'v'.$scan['version'].' ('.self::str($scan['matched_by'] ?? '').')' : '—';
            $out[] = '| '.self::cell(self::str($scan['name'] ?? '')).' | '.self::int($scan['pages'] ?? 0).' | '.self::cell(self::str($scan['condition'] ?? '')).' | '.self::str($scan['status'] ?? '')
                .' | '.$version.' | '.self::int($counts['read'] ?? 0).' | '.self::int($counts['blank'] ?? 0).' | '.self::int($counts['unreadable'] ?? 0).' | '.self::int($counts['not_found'] ?? 0)
                .' | '.(($scan['has_answers'] ?? false) === true ? self::int($scan['scored'] ?? 0) : '—').' | '.(($scan['has_answers'] ?? false) === true ? self::int($scan['needs_correction'] ?? 0) : '—').' |';
            if (is_string($scan['message'] ?? null)) {
                $notes[] = '- `'.self::str($scan['name'] ?? '').'` — '.$scan['message'];
            }
        }
        $out[] = '';
        if ($notes !== []) {
            array_push($out, ...$notes);
            $out[] = '';
        }
        $out[] = 'A version found by `unconfirmed` means the checksum stamp in the running head was not read and the current version was assumed.';
        $out[] = '';

        $out[] = '## What was not scored';
        $out[] = '';
        $sections = [
            'Answer cells not understood (fix the sheet and run again — these fields are not scored)' => $meta['problems'] ?? [],
            'Answer rows naming no scan in the folder' => $meta['orphan_rows'] ?? [],
            'Files that are not scans the app would take' => $meta['skipped'] ?? [],
            'Answer columns that match no question' => $meta['unknown_headers'] ?? [],
            'Questions with no answer column' => $meta['unscored_questions'] ?? [],
            'Scans with no answer row (read, not scored)' => $meta['scans_without_answers'] ?? [],
        ];
        foreach ($sections as $title => $items) {
            $items = self::list($items);
            $out[] = '- **'.$title.':** '.($items === [] ? 'none.' : count($items));
            foreach ($items as $item) {
                $out[] = '  - '.self::str($item);
            }
        }
        $out[] = '';

        $out[] = '## How a field is compared';
        $out[] = '';
        $out[] = 'A typed answer is turned into the value the reader stores for the question\'s type, and both sides are compared in one form. '.OcrBakeoffAnswers::LENIENCY
            .' A date is typed as `2026-10-08` (or day first, `08/10/2026`, the order the paper prints). '
            .'Each scan is read once with nothing withheld, and every threshold above is applied to that one reading with the review screen\'s own tier rule.';
        $out[] = '';

        return implode("\n", $out);
    }

    /**
     * One row per scan and question, with a byte-order mark so a spreadsheet opens it as UTF-8.
     *
     * @param  list<array<string, mixed>>  $observations
     */
    public static function writeFieldsCsv(string $path, array $observations): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot write {$path}.");
        }

        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, ['scan', 'condition', 'question', 'type', 'state', 'anchored_by', 'confidence', 'value_read', 'text_read', 'correct_answer', 'verdict'], ',', '"', '');
        foreach ($observations as $o) {
            $type = $o['type'] instanceof FieldType ? $o['type']->value : '';
            // Text read off a scan is untrusted, so every cell is disarmed the way an export's is.
            fputcsv($handle, array_map(
                static function (string $v): string {
                    $safe = SpreadsheetCell::safe($v);

                    return is_string($safe) ? $safe : $v;
                },
                [
                    self::str($o['scan'] ?? ''), self::str($o['condition'] ?? ''), self::str($o['key'] ?? ''), $type, self::str($o['state'] ?? ''),
                    self::str($o['anchored_by'] ?? ''), is_int($o['confidence'] ?? null) ? (string) $o['confidence'] : '',
                    self::value($o['value'] ?? null), self::str($o['text'] ?? ''), ($o['scored'] ?? false) === true ? self::value($o['expected'] ?? null) : '',
                    self::str($o['verdict'] ?? ''),
                ],
            ), ',', '"', '');
        }
        fclose($handle);
    }

    /** A stored-shape value as a person reads it. */
    public static function value(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => implode('; ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value)),
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    private static function pct(mixed $rate): string
    {
        return is_float($rate) || is_int($rate) ? number_format(100 * $rate, 1).'%' : '—';
    }

    private static function int(mixed $value): string
    {
        return is_int($value) ? (string) $value : '0';
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** A markdown table cell: a pipe would end it. */
    private static function cell(string $text): string
    {
        return str_replace('|', '\\|', $text);
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /** @return array<array-key, mixed> */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
