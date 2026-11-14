<?php

declare(strict_types=1);

namespace App\Support\Forms;

/**
 * M240 (`builder-copy-section`, `D225`) — the keys a copy takes. The number in a key counts up: `a01_use` becomes
 * `a02_use` and `section_3` becomes `section_4`, so a numbered block copied in order needs no renaming.
 *
 * The rule, for one copy (a question, ticked questions or a section's questions):
 * - Each key's FIRST run of digits is its number. One step `n` (1, 2, … up to 1000) is shared by the whole copy, and
 *   the smallest one wins for which EVERY numbered key's candidate is free. A candidate keeps the run's zero-padded
 *   width (`a09` → `a10`, `a99` → `a100`) and must be no longer than the key column.
 * - A key with no number, a run over 18 digits (it would overflow an integer), or a copy for which no step works,
 *   takes `{key}_copy`, then `{key}_copy_2`… — shortening the key, never the suffix, as `freeKey()` does.
 *
 * "Free" is the caller's `$taken` (every field and section key in the draft, since the two share one key space, and
 * every key a published version ever held, so old answers cannot attach to a copy) plus every key this plan has
 * already handed out. Pure: no database, so the rule is unit-tested on its own.
 */
final class CopyKeys
{
    public const MAX_LENGTH = 150;

    private const MAX_STEP = 1000;

    private const MAX_RUN_DIGITS = 18;

    /**
     * @param  list<string>  $keys  the copied keys, in source order
     * @param  array<string, true>  $taken  keys the copy may not take
     * @return array<string, string> source key ⇒ copy key, in `$keys` order
     */
    public static function plan(array $keys, array $taken, int $max = self::MAX_LENGTH): array
    {
        /** @var array<string, array{0: int, 1: string}> $numbered key ⇒ [offset, digit run] */
        $numbered = [];

        foreach ($keys as $key) {
            if (preg_match('/[0-9]+/', $key, $match, PREG_OFFSET_CAPTURE) === 1 && strlen($match[0][0]) <= self::MAX_RUN_DIGITS) {
                $numbered[$key] = [$match[0][1], $match[0][0]];
            }
        }

        /** @var array<string, string> $planned */
        $planned = $numbered === [] ? [] : self::countUp($numbered, $taken, $max);

        $assigned = $taken;
        foreach ($planned as $copy) {
            $assigned[$copy] = true;
        }

        $out = [];
        foreach ($keys as $key) {
            if (! isset($planned[$key])) {
                $planned[$key] = self::suffixed($key, $assigned, $max);
                $assigned[$planned[$key]] = true;
            }

            $out[$key] = $planned[$key];
        }

        return $out;
    }

    /**
     * The smallest shared step for which every numbered key's candidate is free, or [] when none up to the cap is.
     *
     * @param  array<string, array{0: int, 1: string}>  $numbered
     * @param  array<string, true>  $taken
     * @return array<string, string>
     */
    private static function countUp(array $numbered, array $taken, int $max): array
    {
        for ($step = 1; $step <= self::MAX_STEP; $step++) {
            $candidates = [];

            foreach ($numbered as $key => [$at, $run]) {
                $next = str_pad((string) ((int) $run + $step), strlen($run), '0', STR_PAD_LEFT);
                $candidate = substr($key, 0, $at).$next.substr($key, $at + strlen($run));

                if (strlen($candidate) > $max || isset($taken[$candidate]) || isset($candidates[$candidate])) {
                    continue 2;
                }

                $candidates[$candidate] = $key;
            }

            return array_flip($candidates);
        }

        return [];
    }

    /**
     * @param  array<string, true>  $assigned
     */
    private static function suffixed(string $key, array $assigned, int $max): string
    {
        for ($n = 1; ; $n++) {
            $suffix = $n === 1 ? '_copy' : "_copy_{$n}";
            $candidate = substr($key, 0, $max - strlen($suffix)).$suffix;

            if (! isset($assigned[$candidate])) {
                return $candidate;
            }
        }
    }
}
