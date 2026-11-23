<?php

declare(strict_types=1);

namespace Tests\Support\Nhete;

/**
 * M250 (`agreement-report`, `D234`): NHETE's agreement list, counted a second way.
 *
 * `variants()` turns the TABLE layout's one scenario into eight different respondents, so the list has something to
 * disagree about: one Terms-of-Reference row everyone agrees with, one nearly everyone has no view on, one split three
 * ways; an indicator half the respondents would rather compare, one nobody has a view on; a working description most
 * now agree with; a "choose up to two" question some have no view on.
 *
 * `tally()` counts the stored answer documents in plain PHP — no SQL, no shaper — so `NheteTableRouteTest` can hold the
 * explorer's list against it item by item. It reads the same rules as ADR-0025 §D10 states them, written out again on
 * purpose: a second reading that agreed only because it shared the code would prove nothing.
 */
final class NheteAgreementTally
{
    /**
     * Eight respondents built from one scenario.
     *
     * @param  array<string, mixed>  $base  `NheteAnswers::scenario()` for the TABLE layout
     * @return list<array<string, mixed>>
     */
    public static function variants(array $base): array
    {
        $a = NhetePlan::indicatorGrid('a');
        $out = [];

        for ($i = 0; $i < 8; $i++) {
            $answers = $base;

            // tor.t01 stays "agree" in every response. tor.t02: seven of eight have no view.
            $answers['tor']['t02']['agree'] = $i === 0 ? 'agree' : 'noview';
            // tor.t03: split between "disagree" and "agree with changes", each with its reason.
            $answers['tor']['t03']['agree'] = $i % 2 === 0 ? 'disagree' : 'agree_changes';
            $answers['tor']['t03']['why'] = 'Said in words: '.str_repeat('reason ', 6);

            // a01: six would rather compare it, two would drop it. a07: nobody has a view.
            $answers[$a]['a01']['use'] = $i < 6 ? 'compare' : 'drop';
            $answers[$a]['a07']['use'] = 'noview';

            // The working description: six now agree, two have no view; the reason box goes with "agree with changes".
            $answers['q3_4'] = $i < 6 ? 'agree' : 'noview';
            unset($answers['q3_5']);

            // "Choose up to two": three have no view, five choose one answer.
            $answers['q3_1'] = $i < 3 ? ['noview'] : ['access'];

            $out[] = $answers;
        }

        return $out;
    }

    /**
     * Each line of a list counted from the stored documents: responses, views, the number counted as agreeing, and
     * the status. The lines' own keys and answer order are read from the list; every number is counted here.
     *
     * @param  list<array<string, mixed>>  $documents  every countable response's `submission_answers.answers`
     * @param  list<array<string, mixed>>  $lines  the list's items
     * @param  list<string>  $leaveOut
     * @param  list<string>  $agreeing
     * @return array<string, array{responses: int, views: int, agreeing: int, status: string}>
     */
    public static function tally(array $documents, array $lines, array $leaveOut, array $agreeing, int $threshold, int $minViews): array
    {
        $out = [];

        foreach ($lines as $line) {
            $path = explode('.', (string) $line['key']);
            $order = array_column($line['answers'], 'value');
            $responses = 0;
            $views = 0;
            $marked = 0;
            $given = [];

            foreach ($documents as $document) {
                $cell = $document;
                foreach ($path as $step) {
                    $cell = is_array($cell) ? ($cell[$step] ?? null) : null;
                }

                $values = [];
                foreach (is_array($cell) ? $cell : [$cell] as $value) {
                    if (is_bool($value)) {
                        $values[] = $value ? 'true' : 'false';
                    } elseif (is_string($value) || is_int($value) || is_float($value)) {
                        if ((string) $value !== '') {
                            $values[] = (string) $value;
                        }
                    }
                }
                $values = array_values(array_unique($values));

                if ($values === []) {
                    continue;
                }

                $responses++;
                $views += array_diff($values, $leaveOut) !== [] ? 1 : 0;
                $marked += array_intersect($values, $agreeing) !== [] ? 1 : 0;

                foreach ($values as $value) {
                    $given[$value] = ($given[$value] ?? 0) + 1;
                }
            }

            if (array_intersect($order, $agreeing) !== []) {
                $agree = $marked;
            } else {
                $agree = 0;
                foreach ($order as $value) {
                    if (! in_array($value, $leaveOut, true) && ($given[$value] ?? 0) > $agree) {
                        $agree = $given[$value];
                    }
                }
            }

            $out[(string) $line['key']] = [
                'responses' => $responses,
                'views' => $views,
                'agreeing' => $agree,
                'status' => $views < $minViews ? 'too_few' : ($agree * 100 >= $threshold * $views ? 'short_list' : 'discuss'),
            ];
        }

        return $out;
    }
}
