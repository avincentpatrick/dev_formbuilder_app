<?php

declare(strict_types=1);

/*
 * A stub `git` for the controls in tests/Feature/Docs/GateBaselinesTest.php (M74).
 *
 * ⛔ THIS SEAM IS WHY THE ANCESTRY ARM IS PROVABLE AT ALL, and it is worth stating because a
 * gh-only seam looks sufficient and is not:
 *
 *   - With a real git, "the head sha is NOT an ancestor of origin/main" cannot be driven. A
 *     fabricated sha makes git exit 128 (`bad object`) — a THIRD outcome — so the case would pass
 *     through the "could not decide" arm while claiming to prove the "not an ancestor" one. That is
 *     the M43 shape: a green case that measures something other than what it names.
 *   - git is absent inside the app container where Pest runs (tests/Feature/Docs/MutateHarnessTest.php
 *     records the same absence for its own arms), so the arms would measure the runner.
 *   - And the distance arm would otherwise depend on the local clone's history and on
 *     actions/checkout's fetch depth, which is a measurement of CI configuration, not of the guard.
 *
 * ⚠️ It answers only the two invocations scripts/gate-baselines.php makes. Anything else exits 64 so
 * a caller that starts asking for more is loud rather than silently satisfied.
 */

$scenario = getenv('GATE_BASELINES_SCENARIO');

if ($scenario === false || $scenario === '') {
    fwrite(STDERR, "git-stub: GATE_BASELINES_SCENARIO is not set.\n");
    exit(64);
}

$path = __DIR__.'/'.$scenario.'.json';

if (! is_file($path)) {
    fwrite(STDERR, "git-stub: no such scenario: {$path}\n");
    exit(64);
}

$run = json_decode((string) file_get_contents($path), true);

if (! is_array($run)) {
    fwrite(STDERR, "git-stub: scenario {$scenario} is not a JSON object.\n");
    exit(64);
}

$arguments = array_slice($_SERVER['argv'] ?? [], 1);

// `git merge-base --is-ancestor <sha> origin/main` answers ONLY through its exit status and prints
// nothing. `ancestor` is a three-valued fixture field for exactly that reason: true, false, or a
// literal exit code for the undecidable case.
if (($arguments[0] ?? '') === 'merge-base' && ($arguments[1] ?? '') === '--is-ancestor') {
    $ancestor = $run['ancestor'] ?? null;

    if ($ancestor === true) {
        exit(0);
    }

    if ($ancestor === false) {
        exit(1);
    }

    fwrite(STDERR, "fatal: Not a valid object name\n");

    exit(is_int($ancestor) ? $ancestor : 128);
}

if (($arguments[0] ?? '') === 'rev-list' && ($arguments[1] ?? '') === '--count') {
    if (($run['revListFails'] ?? false) === true) {
        fwrite(STDERR, "fatal: bad revision\n");
        exit(128);
    }

    fwrite(STDOUT, (string) ($run['behind'] ?? 0)."\n");
    exit(0);
}

fwrite(STDERR, 'git-stub: unhandled invocation: '.implode(' ', $arguments)."\n");
exit(64);
