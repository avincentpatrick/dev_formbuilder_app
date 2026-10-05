<?php

declare(strict_types=1);

namespace App\Services\Ocr\Bakeoff;

use App\Enums\FieldType;
use App\Services\Ocr\PrintedFormMatcher;

/**
 * Scores what the reader read against the correct answers, at every confidence threshold (M136). Pure: no I/O.
 *
 * ── THE MEASURE IS PRD G9: FIELDS NEEDING MANUAL CORRECTION ──────────────────────────────────────────────
 * A reviewer sees a field's value only when it was read at or above the review threshold; below it the field is empty
 * and marked for manual entry (`docs/ocr-pipeline-design.md` §3). So at a review threshold r a field NEEDS CORRECTION
 * exactly when what the reviewer is shown is not the correct answer:
 *   - a right answer withheld below r needs correction — somebody types it in;
 *   - a value withheld where the correct answer is blank does not — the empty field IS the answer;
 *   - a wrong value shown needs correction, flagged or not;
 *   - a question not found, unreadable or read as blank when it was answered needs correction.
 * Only scans that were read are scored. G9's bar is under 15% of fields.
 *
 * ── THE SECOND MEASURE IS THE DANGEROUS ONE: A WRONG VALUE NOBODY IS ASKED TO CHECK ──────────────────────
 * At or above the auto threshold a value is filled with no flag. A wrong value there is a SILENT ERROR — the case §3
 * withholds values to avoid. Raising the auto threshold trades silent errors for flagged fields (review fatigue); the
 * auto sweep shows both at the configured review threshold.
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
        'withheld_right' => 'withheld below the review threshold, though it was right',
        'withheld_wrong' => 'withheld below the review threshold, and wrong',
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
     *     review_sweep: list<array<string, int|float|null>>,
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
            'review_sweep' => $this->reviewSweep($scoredObservations, $configured),
            'auto_sweep' => $this->autoSweep($scoredObservations, $configured),
            'histogram' => $this->histogram($scoredObservations),
            'observations' => $observations,
            'scans' => $scanRows,
        ];
    }

    /**
     * Whether a field needs correction when the reviewer is shown values at or above `$review`.
     *
     * @param  array<string, mixed>  $o
     */
    public function needsCorrection(array $o, int $review): bool
    {
        return ! OcrBakeoffAnswers::same($o['type'], $o['expected'], $this->shown($o, $review));
    }

    /**
     * What the reviewer is shown at a review threshold: the value when it was read at or above it, otherwise nothing.
     *
     * @param  array<string, mixed>  $o
     */
    private function shown(array $o, int $review): mixed
    {
        return $o['state'] === 'read' && $this->tierAt($o, ['auto' => $review, 'review' => $review]) !== 'manual' ? $o['value'] : null;
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
     * The cause code from {@see CAUSES}, or null when the field needs no correction at the configured thresholds.
     *
     * @param  array<string, mixed>  $o
     * @param  array{auto: int, review: int}  $configured
     */
    private function cause(array $o, array $configured): ?string
    {
        if (! $this->needsCorrection($o, $configured['review'])) {
            return null;
        }

        $answered = OcrBakeoffAnswers::canonical($o['type'], $o['expected']) !== null;

        return match ($o['state']) {
            'not_found' => 'not_found',
            'unreadable' => 'unreadable',
            'blank' => 'missed',
            'read' => match (true) {
                $this->tierAt($o, $configured) === 'manual' => $this->isRight($o) ? 'withheld_right' : 'withheld_wrong',
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
            $swapped = in_array($cause, ['wrong_flagged', 'wrong_unflagged', 'withheld_wrong'], true)
                && OcrBakeoffAnswers::daySwapped($o['type'], $o['expected'], $o['value']);

            return self::CAUSES[$cause].($swapped ? ' — day and month swapped? check the answer sheet' : '');
        }

        if (OcrBakeoffAnswers::canonical($o['type'], $o['expected']) === null) {
            return 'right (blank)';
        }

        return $this->tierAt($o, $configured) === 'review' ? 'right, flagged for review' : 'right';
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
        foreach ($observations as $o) {
            if (is_string($o['cause'])) {
                $causes[$o['cause']]++;
            }
            if ($o['state'] !== 'read') {
                continue;
            }
            // Counted exactly as the auto sweep counts it, so the headline is that sweep's configured row: any value
            // filled with no flag that is not the answer — a wrong one, or one read where the answer is blank.
            $tier = $this->tierAt($o, $configured);
            if ($tier === 'review') {
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
            'causes' => $causes,
        ];
    }

    /**
     * The review threshold swept from 0 to 100: how many fields need correction, and why.
     *
     * @param  list<array<string, mixed>>  $observations
     * @param  array{auto: int, review: int}  $configured
     * @return list<array<string, int|float|null>>
     */
    private function reviewSweep(array $observations, array $configured): array
    {
        $rows = [];
        foreach ($this->steps(0, $configured['review']) as $review) {
            $corrections = 0;
            $withheldRight = 0;
            $wrongShown = 0;
            foreach ($observations as $o) {
                if (! $this->needsCorrection($o, $review)) {
                    continue;
                }
                $corrections++;
                if ($o['state'] !== 'read') {
                    continue;
                }
                if ($this->shown($o, $review) === null) {
                    $withheldRight += $this->isRight($o) ? 1 : 0;
                } else {
                    $wrongShown++;
                }
            }
            $rows[] = [
                'review' => $review,
                'needs_correction' => $corrections,
                'rate' => $observations === [] ? null : fdiv($corrections, count($observations)),
                'withheld_right' => $withheldRight,
                'wrong_shown' => $wrongShown,
                'other' => $corrections - $withheldRight - $wrongShown,
            ];
        }

        return $rows;
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
                if ($tier === 'review') {
                    $flagged++;
                } elseif ($tier === 'auto' && $this->isRight($o)) {
                    $rightUnflagged++;
                } elseif ($tier === 'auto') {
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
