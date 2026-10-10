<?php

declare(strict_types=1);

namespace App\Services\Ocr\Bakeoff;

use App\Enums\FieldType;
use App\Services\Ocr\PrintedFormMatcher;

/**
 * Scores what the reader read against the correct answers, at every confidence threshold (M136). Pure: no I/O.
 *
 * ── THE MEASURE IS PRD G9: FIELDS NEEDING MANUAL CORRECTION ──────────────────────────────────────────────
 * A reviewer is shown every value read, whatever its confidence (`D108`, M156): below the review threshold it is filled in
 * and marked "may be wrong" rather than withheld (`docs/ocr-pipeline-design.md` §3). So a field NEEDS CORRECTION exactly
 * when what was read is not the correct answer:
 *   - a wrong value needs correction, flagged or not;
 *   - a value read where the correct answer is blank needs correction — somebody clears it;
 *   - a question not found, unreadable or read as blank when it was answered needs correction.
 * Only scans that were read are scored. G9's bar is under 15% of fields. A review threshold no longer changes what is
 * shown, only how a value is marked, so it is not swept; the confidence histogram is where a threshold is chosen.
 *
 * ── THE SECOND MEASURE IS THE DANGEROUS ONE: A WRONG VALUE NOBODY IS ASKED TO CHECK ──────────────────────
 * At or above the auto threshold a value is filled with no flag. A wrong value there is a SILENT ERROR — the case §3's
 * flags exist to avoid. Raising the auto threshold trades silent errors for flagged fields (review fatigue); the auto
 * sweep shows both at the configured review threshold.
 *
 * The tier boundary is {@see PrintedFormMatcher::tier()}, the matcher's own rule, so the sweep and the review screen
 * cannot disagree about which side of a threshold a value falls.
 */
final class OcrBakeoffScorer
{
    /** PRD G9: under this share of fields may need manual correction. */
    public const float G9_BAR = 0.15;

    /** Why a field needs correction, in the order the report lists them. */
    public const array CAUSES = [
        'not_found' => 'question not found on the page',
        'unreadable' => 'something written, not readable as the answer',
        'skipped' => 'an area the reader does not read',
        'missed' => 'read as blank, but it was answered',
        'unexpected' => 'a value read where the answer is blank',
        'wrong_flagged' => 'wrong, and flagged for review',
        'wrong_unflagged' => 'wrong, and NOT flagged (a silent error)',
    ];

    /**
     * @param  list<OcrBakeoffScan>  $scans
     * @param  array{auto: int, review: int}  $configured
     * @return array{
     *     thresholds: array{auto: int, review: int},
     *     headline: array<string, mixed>,
     *     by_condition: array<string, array<string, mixed>>,
     *     auto_sweep: list<array<string, int>>,
     *     histogram: array<int, array{right: int, wrong: int}>,
     *     observations: list<array<string, mixed>>,
     *     scans: list<array<string, mixed>>
     * }
     */
    public function score(array $scans, array $configured): array
    {
        $observations = [];
        $scanRows = [];

        foreach ($scans as $scan) {
            $counts = [];
            $scored = 0;
            $corrections = 0;

            foreach ($scan->fields as $key => $field) {
                $state = is_string($field['state'] ?? null) ? $field['state'] : 'unknown';
                $counts[$state] = ($counts[$state] ?? 0) + 1;

                $type = FieldType::tryFrom(is_string($field['type'] ?? null) ? $field['type'] : '');
                $isScored = $scan->status === 'read' && $scan->expected !== null && array_key_exists($key, $scan->expected);
                $observation = [
                    'scan' => $scan->name,
                    'condition' => $scan->condition,
                    'key' => $key,
                    'type' => $type,
                    'state' => $state,
                    'value' => $field['value'] ?? null,
                    'text' => is_string($field['text'] ?? null) ? $field['text'] : null,
                    'confidence' => is_int($field['confidence'] ?? null) ? $field['confidence'] : null,
                    'anchored_by' => is_string($field['anchored_by'] ?? null) ? $field['anchored_by'] : null,
                    'scored' => $isScored,
                    'expected' => $isScored ? $scan->expected[$key] : null,
                ];
                $observation['cause'] = $isScored ? $this->cause($observation, $configured) : null;
                $observation['verdict'] = $isScored ? $this->verdict($observation, $configured) : 'not scored';

                if ($isScored) {
                    $scored++;
                    if ($observation['cause'] !== null) {
                        $corrections++;
                    }
                }
                $observations[] = $observation;
            }

            ksort($counts);
            $scanRows[] = [
                'name' => $scan->name,
                'status' => $scan->status,
                'message' => $scan->message,
                'condition' => $scan->condition,
                'version' => $scan->versionNumber,
                'matched_by' => $scan->matchedBy,
                'pages' => count($scan->files),
                'counts' => $counts,
                'has_answers' => $scan->expected !== null,
                'scored' => $scored,
                'needs_correction' => $corrections,
            ];
        }

        $scoredObservations = array_values(array_filter($observations, static fn (array $o): bool => $o['scored'] === true));

        $byCondition = [];
        foreach ($scoredObservations as $o) {
            $byCondition[is_string($o['condition']) ? $o['condition'] : '(none)'][] = $o;
        }
        ksort($byCondition);

        return [
            'thresholds' => $configured,
            'headline' => $this->headline($scoredObservations, $configured),
            'by_condition' => array_map(fn (array $group): array => $this->headline($group, $configured), $byCondition),
            'auto_sweep' => $this->autoSweep($scoredObservations, $configured),
            'histogram' => $this->histogram($scoredObservations),
            'observations' => $observations,
            'scans' => $scanRows,
        ];
    }

    /**
     * Whether a field needs correction: whether what the reviewer is shown is not the correct answer.
     *
     * @param  array<string, mixed>  $o
     */
    public function needsCorrection(array $o): bool
    {
        return ! OcrBakeoffAnswers::same($o['type'], $o['expected'], $this->shown($o));
    }

    /**
     * What the reviewer is shown: the value whenever one was read, at every confidence (`D108`), otherwise nothing.
     *
     * @param  array<string, mixed>  $o
     */
    private function shown(array $o): mixed
    {
        return $o['state'] === 'read' ? $o['value'] : null;
    }

    /**
     * @param  array<string, mixed>  $o
     * @param  array{auto: int, review: int}  $thresholds
     */
    private function tierAt(array $o, array $thresholds): string
    {
        return PrintedFormMatcher::tier(is_int($o['confidence']) ? $o['confidence'] : 0, $thresholds);
    }

    /** @param  array<string, mixed>  $o */
    private function isRight(array $o): bool
    {
        return OcrBakeoffAnswers::same($o['type'], $o['expected'], $o['value']);
    }

    /**
     * The cause code from {@see CAUSES}, or null when the field needs no correction. The configured thresholds decide
     * only whether a wrong value was flagged.
     *
     * @param  array<string, mixed>  $o
     * @param  array{auto: int, review: int}  $configured
     */
    private function cause(array $o, array $configured): ?string
    {
        if (! $this->needsCorrection($o)) {
            return null;
        }

        $answered = OcrBakeoffAnswers::canonical($o['type'], $o['expected']) !== null;

        return match ($o['state']) {
            'not_found' => 'not_found',
            'unreadable' => 'unreadable',
            'blank' => 'missed',
            'read' => match (true) {
                ! $answered => 'unexpected',
                $this->tierAt($o, $configured) === 'auto' => 'wrong_unflagged',
                default => 'wrong_flagged',
            },
            default => 'skipped',
        };
    }

    /**
     * @param  array<string, mixed>  $o
     * @param  array{auto: int, review: int}  $configured
     */
    private function verdict(array $o, array $configured): string
    {
        $cause = $o['cause'];
        if (is_string($cause)) {
            return self::CAUSES[$cause].($this->isDaySwapped($o) ? ' — day and month swapped? check the paper and the answer sheet' : '');
        }

        if (OcrBakeoffAnswers::canonical($o['type'], $o['expected']) === null) {
            return 'right (blank)';
        }

        return match ($this->tierAt($o, $configured)) {
            'review' => 'right, flagged for review',
            'manual' => 'right, marked may be wrong',
            default => 'right',
        };
    }

    /**
     * A wrong date that would be right with its day and month swapped: the respondent wrote the month in the DD boxes,
     * or the answer sheet was typed month first. Either way the reader is not the suspect.
     *
     * @param  array<string, mixed>  $o
     */
    private function isDaySwapped(array $o): bool
    {
        return in_array($o['cause'], ['wrong_flagged', 'wrong_unflagged'], true)
            && OcrBakeoffAnswers::daySwapped($o['type'], $o['expected'], $o['value']);
    }

    /**
     * @param  list<array<string, mixed>>  $observations
     * @param  array{auto: int, review: int}  $configured
     * @return array<string, mixed>
     */
    private function headline(array $observations, array $configured): array
    {
        $causes = array_fill_keys(array_keys(self::CAUSES), 0);
        $flagged = 0;
        $silent = 0;
        $swapped = 0;
        foreach ($observations as $o) {
            if (is_string($o['cause'])) {
                $causes[$o['cause']]++;
                $swapped += $this->isDaySwapped($o) ? 1 : 0;
            }
            if ($o['state'] !== 'read') {
                continue;
            }
            // Counted exactly as the auto sweep counts it, so the headline is that sweep's configured row: any value
            // filled with no flag that is not the answer — a wrong one, or one read where the answer is blank. Flagged is
            // every value below auto: "check this" for review, "may be wrong" below it.
            $tier = $this->tierAt($o, $configured);
            if ($tier !== 'auto') {
                $flagged++;
            } elseif ($tier === 'auto' && ! $this->isRight($o)) {
                $silent++;
            }
        }

        $fields = count($observations);
        $corrections = array_sum($causes);
        $rate = $fields === 0 ? null : fdiv($corrections, $fields);

        return [
            'fields' => $fields,
            'scans' => count(array_unique(array_column($observations, 'scan'))),
            'needs_correction' => $corrections,
            'rate' => $rate,
            'g9_pass' => $rate === null ? null : $rate < self::G9_BAR,
            'silent' => $silent,
            'flagged' => $flagged,
            'day_swapped' => $swapped,
            'causes' => $causes,
        ];
    }

    /**
     * The auto threshold swept from 50 to 100 at the configured review threshold: silent errors against flagged fields.
     *
     * @param  list<array<string, mixed>>  $observations
     * @param  array{auto: int, review: int}  $configured
     * @return list<array<string, int>>
     */
    private function autoSweep(array $observations, array $configured): array
    {
        $rows = [];
        foreach ($this->steps(50, $configured['auto']) as $auto) {
            $thresholds = ['auto' => max($auto, $configured['review']), 'review' => $configured['review']];
            $silent = 0;
            $flagged = 0;
            $rightUnflagged = 0;
            foreach ($observations as $o) {
                if ($o['state'] !== 'read') {
                    continue;
                }
                $tier = $this->tierAt($o, $thresholds);
                if ($tier !== 'auto') {
                    $flagged++;
                } elseif ($this->isRight($o)) {
                    $rightUnflagged++;
                } else {
                    $silent++;
                }
            }
            $rows[] = ['auto' => $auto, 'silent' => $silent, 'flagged' => $flagged, 'right_unflagged' => $rightUnflagged];
        }

        return $rows;
    }

    /**
     * Right and wrong values by confidence, in tens — the picture a threshold is chosen from.
     *
     * @param  list<array<string, mixed>>  $observations
     * @return array<int, array{right: int, wrong: int}>
     */
    private function histogram(array $observations): array
    {
        $right = array_fill_keys(range(0, 90, 10), 0);
        $wrong = $right;

        foreach ($observations as $o) {
            if ($o['state'] !== 'read' || ! is_int($o['confidence'])) {
                continue;
            }
            $bucket = min(90, intdiv(max(0, $o['confidence']), 10) * 10);
            if ($this->isRight($o)) {
                $right[$bucket]++;
            } else {
                $wrong[$bucket]++;
            }
        }

        $buckets = [];
        foreach ($right as $bucket => $count) {
            $buckets[$bucket] = ['right' => $count, 'wrong' => $wrong[$bucket]];
        }

        return $buckets;
    }

    /**
     * Every multiple of five from `$from` to 100, with the configured value added if it is not one.
     *
     * @return list<int>
     */
    private function steps(int $from, int $configured): array
    {
        $steps = range($from, 100, 5);
        if ($configured >= $from && $configured <= 100 && ! in_array($configured, $steps, true)) {
            $steps[] = $configured;
            sort($steps);
        }

        return $steps;
    }
}
