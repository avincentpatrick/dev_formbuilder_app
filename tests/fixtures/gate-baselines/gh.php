<?php

declare(strict_types=1);

/*
 * A stub `gh` for the controls in tests/Feature/Docs/GateBaselinesTest.php (M74).
 *
 * ⚠️ IT REPLACES THE RUNTIME, NEVER A GUARD. Every refusal in scripts/gate-baselines.php still runs
 * exactly as it does in anger; this only decides which run the CLI reports, so an arm can be driven
 * deterministically instead of depending on whatever GitHub happens to return today. The shape and
 * the argument are scripts/mutate.php's `docker_bin()` seam, copied rather than reinvented.
 *
 * The scenario is named by GATE_BASELINES_SCENARIO and read from the sibling <scenario>.json.
 */

$scenario = getenv('GATE_BASELINES_SCENARIO');

if ($scenario === false || $scenario === '') {
    fwrite(STDERR, "gh-stub: GATE_BASELINES_SCENARIO is not set.\n");
    exit(64);
}

$path = __DIR__.'/'.$scenario.'.json';

if (! is_file($path)) {
    fwrite(STDERR, "gh-stub: no such scenario: {$path}\n");
    exit(64);
}

$run = json_decode((string) file_get_contents($path), true);

if (! is_array($run)) {
    fwrite(STDERR, "gh-stub: scenario {$scenario} is not a JSON object.\n");
    exit(64);
}

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
$verb = $arguments[0] ?? '';
$noun = $arguments[1] ?? '';

// `gh run view <id> --log` — the scraped text. Checked BEFORE the --json arm because both are
// `run view`, and the log arm is the one distinguished by a flag rather than by position.
if ($verb === 'run' && $noun === 'view' && in_array('--log', $arguments, true)) {
    fwrite(STDOUT, (string) file_get_contents(__DIR__.'/ci-log.txt'));
    exit(0);
}

// The six keys scripts/gate-baselines.php reads off a run, and nothing else: a stub that answered
// more than the caller asks for would hide a caller that started asking for more.
$meta = [
    'databaseId' => $run['databaseId'],
    'headSha' => $run['headSha'],
    'createdAt' => $run['createdAt'],
    'conclusion' => $run['conclusion'],
    'url' => $run['url'],
    'event' => $run['event'],
    'headBranch' => $run['headBranch'],
];

if ($verb === 'run' && $noun === 'list') {
    // ⛔ A LIST, AND THE DEFECT UNDER TEST IS THAT [0] IS A CONVENTION RATHER THAN A CONTRACT. The
    //    stub returns exactly one element, so every case here is about what the guard does with the
    //    run it was handed — never about re-litigating gh's ordering, which is unobservable from here.
    fwrite(STDOUT, json_encode([$meta], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    exit(0);
}

if ($verb === 'run' && $noun === 'view') {
    fwrite(STDOUT, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    exit(0);
}

if ($verb === 'api') {
    // Already reduced by the caller's --jq to {name, conclusion, steps}; the stub emits that shape
    // directly rather than modelling jq.
    fwrite(STDOUT, json_encode($run['jobs'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    exit(0);
}

fwrite(STDERR, 'gh-stub: unhandled invocation: '.implode(' ', $arguments)."\n");
exit(64);
