<?php

declare(strict_types=1);

namespace Tests\Support\Nhete;

/**
 * One complete, valid NHETE response for a named scenario (`M237`). The scenario is spelled out here, branch by branch,
 * rather than worked out by evaluating the form's own conditions — a test that asks the engine which questions apply
 * and then answers exactly those proves only that the engine agrees with itself.
 *
 * The default scenario: an office in NCR that keeps A01 and B03, keeps something close to A07, and disagrees with one
 * Terms of Reference part — so every kind of branch is taken at least once: an "Other" box, a choice's own box, a
 * per-row comment, a required-when comment for each of its three reasons, a parts block with every part answer, and the
 * regional coverage question with its piped region.
 */
final class NheteAnswers
{
    /**
     * @param  array<string, string>  $kept  indicator code → 'yes' | 'close'; every other indicator is 'no'
     * @param  array<string, string>  $cutChange  indicator codes whose cut-off answer is 'change'
     * @return array<string, mixed>
     */
    public static function scenario(
        NhetePlan $plan,
        array $kept = ['A01' => 'yes', 'A07' => 'close', 'B03' => 'yes'],
        string $region = 'ncr',
        array $cutChange = ['C14'],
        array $worryOther = ['D12'],
    ): array {
        $answers = [
            'q0_0' => ['yes'], 'q0_1' => 'other', 'q0_1_other' => self::words(12), 'q0_2' => 'technical', 'q0_3' => 'own', 'q0_4' => $region,
            'q1_1' => self::words(60), 'q1_2' => 'need', 'q1_3' => 'depends', 'q1_3_text' => self::words(25), 'q1_4' => 'b', 'q1_5' => 'preventable',
            'q2_1' => 'heard',
            'q3_1' => ['access', 'results'], 'q3_2' => ['barangays', 'ip'], 'q3_3' => 'worst_first', 'q3_4' => 'agree_changes', 'q3_5' => self::words(30),
            'qs01' => 'a07', 'qs03' => 'b02_fallback', 'qs04' => ['b15', 'b19'], 'qs07' => 'd07',
            'qm02' => 'agree', 'qm04' => 'agree_changes', 'qm05' => '50_50', 'qm07a' => 'agree', 'qm07b' => 'noview', 'qm07c' => 'agree', 'qm08' => 'agree',
        ];
        if ($region === 'other') {
            $answers['q0_4_other'] = self::words(8);
        }

        foreach ($plan->elements() as $e) {
            $key = (string) $e['key'];
            if (in_array($e['type'], ['note', 'calculated'], true) || array_key_exists($key, $answers)) {
                continue;
            }
            switch ($e['role']) {
                case 'grid_row':
                    $answers[$key] = $key === 'tor_t06' ? 'disagree' : 'agree';
                    if ($key === 'tor_t06') {
                        $answers["{$key}_why"] = self::words(40);
                    }
                    break;
                case 'ind_use':
                    $answers[$key] = 'include';
                    break;
                case 'ind_cut':
                    $answers[$key] = in_array($e['indicator'], $cutChange, true) ? 'change' : 'use';
                    break;
                case 'ind_worry':
                    $answers[$key] = in_array($e['indicator'], $worryOther, true) ? 'other' : 'none';
                    break;
                case 'ind_keep':
                    $answers[$key] = $kept[$e['indicator']] ?? 'no';
                    break;
                case 'ind_comment':
                    $code = (string) $e['indicator'];
                    if (in_array($code, $cutChange, true) || in_array($code, $worryOther, true) || ($kept[$code] ?? null) === 'close') {
                        $answers[$key] = self::words(20);
                    }
                    break;
                case 'part_keep':
                    if (isset($kept[$e['indicator']])) {
                        $answers[$key] = self::partKeep($key);
                    }
                    break;
                case 'part_close':
                case 'part_area':
                case 'part_cov_r':
                case 'part_cov_n':
                    self::partAnswer($answers, $e, $kept, $region);
                    break;
                case 'question':
                    if ($e['required'] === 'required' && $e['relevant'] === null) {
                        $answers[$key] = $e['type'] === 'multi_select' ? [$e['options'][0][0]] : ($e['options'][0][0] ?? self::words(10));
                    }
                    break;
            }
        }
        $answers['q9_1'] = self::words(15);

        return $answers;
    }

    /** Part 1 of a kept indicator is kept, part 2 is "something close", part 3 is not kept, part 4 is unknown. */
    private static function partKeep(string $key): string
    {
        preg_match('/_p(\d+)_keep$/', $key, $m);

        return ['1' => 'yes', '2' => 'close', '3' => 'no', '4' => 'unknown'][$m[1] ?? '1'];
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $e
     * @param  array<string, string>  $kept
     */
    private static function partAnswer(array &$answers, array $e, array $kept, string $region): void
    {
        if (! isset($kept[$e['indicator']])) {
            return;
        }
        $part = (string) preg_replace('/_(close|area|cov_r|cov_n)$/', '', (string) $e['key']);
        $partKeep = $answers["{$part}_keep"] ?? null;
        $area = str_ends_with($part, '_p1') ? 'barangay' : 'city';
        switch ($e['role']) {
            case 'part_close':
                if ($partKeep === 'close') {
                    $answers[$e['key']] = self::words(18);
                }
                break;
            case 'part_area':
                if (in_array($partKeep, ['yes', 'close'], true)) {
                    $answers[$e['key']] = $area;
                }
                break;
            case 'part_cov_r':
                if (in_array($partKeep, ['yes', 'close'], true) && in_array($region, NhetePlan::FOUR_REGIONS, true)) {
                    $answers[$e['key']] = 'most';
                }
                break;
            case 'part_cov_n':
                if (in_array($partKeep, ['yes', 'close'], true) && $region === 'whole') {
                    $answers[$e['key']] = 'all';
                }
                break;
        }
    }

    /** `$n` plain words, deterministic. */
    public static function words(int $n): string
    {
        $pool = ['data', 'barangay', 'record', 'office', 'region', 'count', 'health', 'access', 'survey', 'facility'];

        return implode(' ', array_map(static fn (int $i): string => $pool[$i % count($pool)], range(0, $n - 1)));
    }
}
