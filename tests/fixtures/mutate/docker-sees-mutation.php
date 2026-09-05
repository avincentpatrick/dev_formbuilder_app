<?php

declare(strict_types=1);

/*
 * A container whose "suite" actually READS THE TARGET FILE, so the verdict arms are proved end to end
 * rather than asserted about.
 *
 * R1's probe answers `0`. The Pest arm opens `tests/fixtures/mutate/target.txt` and goes red exactly
 * when the mutant is on disk. That makes one stub drive both verdicts and, more importantly, proves
 * the things a summary-line stub could not:
 *
 *   - the mutant was genuinely WRITTEN before the mutant run (the baseline is green, the mutant is red,
 *     and the only difference between the two invocations is the bytes on disk);
 *   - the restore in R5 genuinely ran (a second baseline after the run is green again).
 *
 * ⚠️ It prints a REAL `Tests:` summary line, because that line is what `measured` keys on. A stub that
 * only set an exit code would leave the harness reading an unmeasured run, which is the defect under test.
 */

$argv = $_SERVER['argv'];

if (in_array('sh', $argv, true)) {
    echo "0\n";

    exit(0);
}

$target = dirname(__DIR__, 3).'/tests/fixtures/mutate/target.txt';
$contents = is_file($target) ? (string) file_get_contents($target) : '';

if (str_contains($contents, 'mutated')) {
    echo "   FAILED  Tests\\Fixture\\SentinelTest > the sentinel line is intact\n";
    echo "  Tests:    1 failed (1 assertions)\n";

    exit(1);
}

echo "  Tests:    1 passed (1 assertions)\n";

exit(0);
