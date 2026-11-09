<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Increment M232 — ONE READER FOR A WORKFLOW'S JOBS AND STEPS, because an assertion over the whole file goes
 * blind the day a second job carries the same token.
 *
 * ⛔ THAT IS NOT HYPOTHETICAL; IT HAPPENED TO TWO TESTS AT ONCE. `SecretScanDepthTest` asserted that `ci.yml`
 * contains the secret-scanning job's history key, and `NpmAuditJudgeTest` asserted the npm-audit steps' fence
 * the same way. `M192` added the `bhw-connect` job, which carries a copy of both npm-audit steps and its own
 * full-history checkout, and `M214` added `scope`, a third. Measured with `scripts/mutate.php` on 2026-11-09:
 * the scan's own job bounded to a depth of 2, Meridian's fetch step without `continue-on-error`, and Meridian's
 * judge without `set +e` each left its test green (`R-473558cf`, `npm-audit-arm-blind`).
 *
 * Parsed by indentation rather than with a YAML parser, because no YAML extension is loaded in the app container.
 * The shape it reads is this repository's own: a job is a two-space key under `jobs:`, its steps follow its
 * four-space `steps:` key, and each step is a `- ` item at six spaces. A step's text runs to the next step, so a
 * comment that introduces the next step is carried by the one before it; `executable()` drops it either way.
 *
 * ⛔ EVERY LOOKUP THROWS WHEN IT FINDS NOTHING, OR MORE THAN ONE WHERE ONE IS MEANT. An arm that asserts over an
 * empty string, or over the first of two matches, is the defect this file exists to end, in a new place.
 */
final class WorkflowText
{
    /** A workflow's bytes, by path from the repository root. */
    public static function read(string $relativePath): string
    {
        $text = file_get_contents(base_path($relativePath));

        if ($text === false || $text === '') {
            throw new RuntimeException("cannot read {$relativePath}, so every arm built on it would scan nothing");
        }

        return str_replace("\r\n", "\n", $text);
    }

    /**
     * Every job id, in file order.
     *
     * @return list<string>
     */
    public static function jobIds(string $yaml): array
    {
        $ids = [];

        foreach (self::jobLines($yaml) as $line) {
            if (preg_match('/^  ([A-Za-z0-9_-]+):\s*$/', $line, $match) === 1) {
                $ids[] = $match[1];
            }
        }

        if ($ids === []) {
            throw new RuntimeException('no job found under `jobs:` — the workflow moved and this reader is blind');
        }

        return $ids;
    }

    /** One job's text, from the line after its key to the next job's key. */
    public static function job(string $yaml, string $id): string
    {
        $inside = false;
        $body = [];

        foreach (self::jobLines($yaml) as $line) {
            if (preg_match('/^  ([A-Za-z0-9_-]+):\s*$/', $line, $match) === 1) {
                if ($inside) {
                    break;
                }

                $inside = $match[1] === $id;

                continue;
            }

            if ($inside) {
                $body[] = $line;
            }
        }

        if ($body === []) {
            throw new RuntimeException("no job `{$id}` in the workflow");
        }

        return implode("\n", $body);
    }

    /**
     * The id of the ONE job that runs a step whose name starts with `$namePrefix`. An arm finds its job by what
     * the job runs, so renaming the job cannot blind it.
     */
    public static function jobRunning(string $yaml, string $namePrefix): string
    {
        $hits = array_values(array_filter(
            self::jobIds($yaml),
            static fn (string $id): bool => self::stepsNamed(self::job($yaml, $id), $namePrefix) !== []
        ));

        if (count($hits) !== 1) {
            throw new RuntimeException(sprintf(
                'expected exactly one job running a step named `%s…`, found %d (%s)',
                $namePrefix,
                count($hits),
                implode(', ', $hits)
            ));
        }

        return $hits[0];
    }

    /**
     * Each step of a job, as text, from its `- ` line to the next one.
     *
     * @return list<string>
     */
    public static function steps(string $jobText): array
    {
        $steps = self::collectSteps($jobText);

        if ($steps === []) {
            throw new RuntimeException('no steps found in this job');
        }

        return $steps;
    }

    /**
     * The steps, or none. Kept apart from `steps()` so a scan across every job can pass over one that has no
     * steps (a reusable-workflow call) instead of failing on it.
     *
     * @return list<string>
     */
    private static function collectSteps(string $jobText): array
    {
        $steps = [];
        $current = null;
        $inSteps = false;

        foreach (explode("\n", $jobText) as $line) {
            if (preg_match('/^    steps:\s*$/', $line) === 1) {
                $inSteps = true;

                continue;
            }

            if (! $inSteps) {
                continue;
            }

            // A four-space key after `steps:` ends them.
            if (preg_match('/^    [A-Za-z0-9_-]+:/', $line) === 1) {
                break;
            }

            if (str_starts_with($line, '      - ')) {
                if ($current !== null) {
                    $steps[] = implode("\n", $current);
                }

                $current = [$line];

                continue;
            }

            if ($current !== null) {
                $current[] = $line;
            }
        }

        if ($current !== null) {
            $steps[] = implode("\n", $current);
        }

        return $steps;
    }

    /** The ONE step of a job whose name starts with `$namePrefix`. */
    public static function step(string $jobText, string $namePrefix): string
    {
        $named = self::stepsNamed($jobText, $namePrefix);

        if (count($named) !== 1) {
            throw new RuntimeException(sprintf(
                'expected exactly one step named `%s…` in this job, found %d',
                $namePrefix,
                count($named)
            ));
        }

        return $named[0];
    }

    /** The ONE `actions/checkout` step of a job, which carries no name. */
    public static function checkout(string $jobText): string
    {
        $checkouts = array_values(array_filter(
            self::steps($jobText),
            static fn (string $step): bool => str_starts_with($step, '      - uses: actions/checkout@')
        ));

        if (count($checkouts) !== 1) {
            throw new RuntimeException(sprintf('expected exactly one checkout step in this job, found %d', count($checkouts)));
        }

        return $checkouts[0];
    }

    /**
     * The lines that run: comments and blank lines dropped. A step's comments have to NAME what they explain,
     * so a gate that reads them reads prose, not what runs.
     */
    public static function executable(string $text): string
    {
        return implode("\n", array_filter(
            explode("\n", $text),
            static fn (string $line): bool => trim($line) !== '' && ! str_starts_with(ltrim($line), '#')
        ));
    }

    /**
     * The steps of a job whose name starts with the prefix.
     *
     * @return list<string>
     */
    private static function stepsNamed(string $jobText, string $namePrefix): array
    {
        return array_values(array_filter(
            self::collectSteps($jobText),
            static fn (string $step): bool => str_starts_with($step, '      - name: '.$namePrefix)
        ));
    }

    /**
     * The lines after `jobs:`. A trigger under `on:` is a two-space key too, so the job scan starts below it.
     *
     * @return list<string>
     */
    private static function jobLines(string $yaml): array
    {
        $lines = explode("\n", $yaml);
        $start = array_search('jobs:', $lines, true);

        if ($start === false) {
            throw new RuntimeException('no top-level `jobs:` key — this reader is blind');
        }

        return array_slice($lines, $start + 1);
    }
}
