<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The commands README.md prescribes vs. the tree that has to run them (M59).
|--------------------------------------------------------------------------
| `README.md`'s command blocks are the first thing a new contributor runs, and until M59 the last
| line of the design-system block told them to run the merge-blocking accessibility suite as
| `ds:test` inside the `node` service. It could not work, for two independent reasons that this
| gate now holds separately, and the working recipe had been sitting in `docs/feature-backlog.md`
| — a file no reader of the README will ever open — since J4b.
|
| ⛔ WHY THIS IS A TEST AND NOT A LINT SCRIPT. Three reasons, and the third decided it.
| (1) `scripts/constraint-boundary-lint.php` exists because a drift failure names a constraint and
|     not the file that wrote it, so a static pass adds the file:line a catalog cannot carry. That
|     argument does not reach here: the defect IS the document, and every failure below already
|     names the file, the line and the command.
| (2) `php artisan test` discovers `tests/Feature`, so this needs no `composer.json` alias, no
|     `quality` entry and no `ci.yml` step — where a `scripts/*-lint.php` sibling needs all three,
|     and no CI job runs the `quality` aggregate.
| (3) `scripts/mutate.php` drives Pest in a container and nothing else. A lint script would have to
|     reimplement its discipline by hand at the call site, which M42 recorded as the weaker form.
|     The four controls in the release notes were run through `mutate.php` because of this choice.
|
| ⚠️ EVERY ARM ASSERTS AN ARTIFACT, NEVER A BEHAVIOUR. `scripts/citation-liveness-lint.php` carries
| the standing warning: negatives about artifacts are mechanisable, negatives about behaviour are
| not, and a gate that pretends otherwise will confidently ratify the error. Nothing here claims a
| command WORKS. Each arm claims that a named script, a named service or a named flag does or does
| not exist, and every one of those is read from a file in the tree.
|
| ⚠️ NOTHING IS HARD-CODED. The script graph is resolved out of the two `package.json` files
| through the `npm --prefix <dir> run <name>` indirection, and the musl service set is read out of
| `docker-compose.yml`. A renamed script, a new browser-driving script or a re-based image is
| covered on the day it lands rather than on the day somebody remembers this file.
|
| ⚠️ COMMENT LINES ARE SKIPPED, DELIBERATELY. A fenced block explains itself, and the README's own
| explanation of this defect necessarily names the broken command and the missing flag. Gating
| prose would make the fix unwritable — this gate is about what a reader would COPY.
|
| ⚠️ Reads three named files and iterates no directory, so the partial `RecursiveDirectoryIterator`
| descent of the Windows bind mount that `docs/gate-baselines.md` records for the lint gates cannot
| reach it. It is equally correct on the host and in the container.
|
| Helper names are prefixed `documentedCommand*` deliberately: Pest loads every file in a directory
| into one process, so a same-named file-scope helper is a fatal redeclaration.
*/

/**
 * Plausible floors for the discovery pass.
 *
 * Measured on the tree this gate was written against: 3 fenced blocks, 8 `npm run` invocations and
 * 11 `docker compose exec` invocations on non-comment lines, and 3 musl services. These sit well
 * below that so ordinary editing does not trip them, and well above zero so a renamed file, a
 * changed fence marker or a broken parser fails LOUDLY instead of reporting green over nothing.
 *
 * Four, because there are four independent ways for this gate to pass while blind: the document
 * could stop being found, the fences could stop being recognised, the command shapes could stop
 * matching, or the compose file could stop yielding an image.
 */
const DOCUMENTED_COMMAND_MIN_FENCED_BLOCKS = 2;

const DOCUMENTED_COMMAND_MIN_NPM_INVOCATIONS = 6;

const DOCUMENTED_COMMAND_MIN_COMPOSE_INVOCATIONS = 6;

const DOCUMENTED_COMMAND_MIN_MUSL_SERVICES = 1;

/** The document whose command blocks are gated. */
const DOCUMENTED_COMMAND_DOCUMENT = 'README.md';

/**
 * A resolved leaf command reaching one of these drives a real browser.
 *
 * Matched with a hyphen-aware boundary so `build-storybook` is not mistaken for `test-storybook`.
 */
const DOCUMENTED_COMMAND_BROWSER_RUNNERS = ['test-storybook', 'playwright'];

/**
 * A resolved leaf reaching this invokes the Storybook BUILD cli, which exits 0 on a crash unless
 * telemetry is disabled (M71).
 *
 * ⛔ THE FAILURE IT GUARDS IS NOT THE ONE THE README USED TO DESCRIBE, AND THE DIFFERENCE DECIDES
 * THE FIX. Nothing "swallows" the status: the preset error is thrown as critical and `withTelemetry`
 * rethrows it unconditionally, so the cli's own `.catch(() => process.exit(1))` would fire. What
 * happens instead is ABANDONMENT — the bundled `prompts` base class binds only a `keypress`
 * listener and no readline `close`/EOF handler, so on a non-TTY stdin the crash-report confirm never
 * settles, the rethrow is never reached, the event loop drains and Node exits with its default 0.
 * Answering the prompt is therefore not a fix and neither is a retry; removing it from the code path
 * is, which is what the flag does — with `disableTelemetry` truthy the reporter is never constructed.
 *
 * ⚠️ THE FLAG AND NOT THE ENVIRONMENT VARIABLE, DELIBERATELY. `STORYBOOK_DISABLE_TELEMETRY=1 …` sets
 * the same option's default and is what CI would reach for, but it is not valid `cmd.exe` syntax and
 * would break this script for the Windows host README.md still documents as supported.
 *
 * ⚠️ Matched with a boundary rather than `str_contains`, so the npm script NAME `build-storybook` is
 * never mistaken for the cli invocation `storybook build` that it resolves to.
 */
const DOCUMENTED_COMMAND_BUILD_INVOCATION = '/(?<![\w-])storybook\s+build(?![\w-])/';

const DOCUMENTED_COMMAND_TELEMETRY_FLAG = '--disable-telemetry';

/** Floor: the arm below cannot fail on nothing, so it fails here instead. */
const DOCUMENTED_COMMAND_MIN_BUILD_INVOCATIONS = 1;

/**
 * A dev server that binds a port, as prescribed by a package script — `storybook dev -p 6006`,
 * `vite --port 5173`. The port is captured, because the point of the arm below is to compare it to
 * what the compose service publishes.
 *
 * ⚠️ DERIVED FROM THE SCRIPT, NEVER DECLARED HERE. A list of "our dev servers" is a second copy of
 * a fact and drifts from the package.json that owns it, which is the failure this whole file exists
 * to catch one level up.
 */
const DOCUMENTED_COMMAND_DEV_SERVER_PORT = '/(?<![\w-])(?:dev|serve|start)(?![\w-]).*?(?:-p|--port)[ =]+(\d{2,5})/';

/**
 * Every line of the gated document that a reader would COPY, keyed by 1-based line number.
 *
 * Fenced blocks only; comment lines and blank lines dropped. `explode` and never `preg_split` on
 * `\R` — PCRE's `\R` without `/u` matches the byte 0x85 INSIDE UTF-8 characters, and these
 * documents are full of them, which silently shifts every line number after the first (M42).
 */
function documentedCommandLines(): array
{
    $path = base_path(DOCUMENTED_COMMAND_DOCUMENT);

    expect(is_file($path))->toBeTrue(
        'Discovery floor: '.DOCUMENTED_COMMAND_DOCUMENT.' was not found at '.$path.'. '.
        'A moved or renamed document makes this gate blind, so it fails instead.'
    );

    $lines = explode("\n", (string) file_get_contents($path));

    $inFence = false;
    $fences = 0;
    $commands = [];

    foreach ($lines as $index => $line) {
        if (str_starts_with(ltrim($line), '```')) {
            if (! $inFence) {
                $fences++;
            }
            $inFence = ! $inFence;

            continue;
        }

        if (! $inFence) {
            continue;
        }

        $trimmed = trim($line);

        // Prose inside a fence explains the commands; it is not one.
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $commands[$index + 1] = $trimmed;
    }

    expect($fences)->toBeGreaterThanOrEqual(
        DOCUMENTED_COMMAND_MIN_FENCED_BLOCKS,
        'Discovery floor: found '.$fences.' fenced block(s) in '.DOCUMENTED_COMMAND_DOCUMENT.'. '.
        'A changed fence marker makes this gate blind, so it fails instead of reporting a clean run.'
    );

    return $commands;
}

/**
 * The host ports a compose service publishes, read from the compose file rather than declared.
 *
 * Only the HOST side of `"<host>:<container>"` is returned — that is the number a browser on this
 * machine can reach, and reaching it is the whole claim the arm below checks.
 *
 * @return list<int>
 */
function documentedCommandPublishedPorts(string $service): array
{
    $path = base_path('docker-compose.yml');

    expect(is_file($path))->toBeTrue(
        'Discovery floor: docker-compose.yml was not found at '.$path.'.'
    );

    $ports = [];
    $current = null;
    $inPorts = false;

    foreach (explode("
", (string) file_get_contents($path)) as $line) {
        if (preg_match('/^  ([a-z][\w-]*):\s*$/', $line, $match) === 1) {
            $current = $match[1];
            $inPorts = false;

            continue;
        }

        if (preg_match('/^    ([a-z][\w-]*):/', $line, $match) === 1) {
            $inPorts = $match[1] === 'ports';

            continue;
        }

        if ($inPorts && $current === $service && preg_match('/^\s*-\s*"?(\d{2,5}):/', $line, $match) === 1) {
            $ports[] = (int) $match[1];
        }
    }

    return $ports;
}

/** The `scripts` map of one package.json, keyed by script name. */
function documentedCommandScriptsIn(string $relativeDirectory): array
{
    $path = rtrim(base_path($relativeDirectory), '/\\').'/package.json';

    if (! is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded['scripts'] ?? null) ? $decoded['scripts'] : [];
}

/**
 * Resolve a root script through the `npm --prefix <dir> run <name>` indirection to its leaf body.
 *
 * Returns null when the script does not exist, which is what the first arm asserts on.
 */
function documentedCommandResolve(string $script, string $directory = '', int $depth = 0): ?string
{
    $scripts = documentedCommandScriptsIn($directory);

    if (! array_key_exists($script, $scripts)) {
        return null;
    }

    $body = trim((string) $scripts[$script]);

    // A cycle cannot happen in a well-formed tree; refusing to recurse forever is cheaper than
    // trusting that, and a depth-exhausted resolve reports the body it stopped on.
    if ($depth >= 8) {
        return $body;
    }

    if (preg_match('/^npm\s+--prefix\s+(\S+)\s+run\s+(\S+)$/', $body, $match) === 1) {
        return documentedCommandResolve($match[2], $match[1], $depth + 1) ?? $body;
    }

    if (preg_match('/^npm\s+run\s+(\S+)$/', $body, $match) === 1) {
        return documentedCommandResolve($match[1], $directory, $depth + 1) ?? $body;
    }

    return $body;
}

/** Does the command text invoke a runner that launches a real browser? */
function documentedCommandDrivesBrowser(string $command): bool
{
    foreach (DOCUMENTED_COMMAND_BROWSER_RUNNERS as $runner) {
        if (preg_match('/(?<![\w-])'.preg_quote($runner, '/').'(?![\w-])/', $command) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Compose services whose image is musl-based, read from the compose file rather than declared.
 *
 * A service with no `image:` key — one built from a Dockerfile — is not in the set: what its base
 * is cannot be read here, and guessing is how a gate starts asserting behaviour.
 */
function documentedCommandMuslServices(): array
{
    $path = base_path('docker-compose.yml');

    expect(is_file($path))->toBeTrue(
        'Discovery floor: docker-compose.yml was not found at '.$path.'. '.
        'The musl service set is read from it, so an absent file makes this gate blind.'
    );

    $musl = [];
    $service = null;

    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
        if (preg_match('/^  ([a-z][\w-]*):\s*$/', $line, $match) === 1) {
            $service = $match[1];

            continue;
        }

        if ($service !== null && preg_match('/^\s+image:\s*(\S+)/', $line, $match) === 1) {
            if (str_contains($match[1], 'alpine')) {
                $musl[] = $service;
            }
        }
    }

    expect(count($musl))->toBeGreaterThanOrEqual(
        DOCUMENTED_COMMAND_MIN_MUSL_SERVICES,
        'Discovery floor: found '.count($musl).' musl service(s) in docker-compose.yml. '.
        'A parser that yields none cannot fail the arm it exists for, so it fails here instead.'
    );

    return $musl;
}

/**
 * Every `npm run <script>` a reader would copy, as [line, script, wholeCommand].
 *
 * Carries the second floor: commands still found, shapes still matched, nothing parsed is the
 * failure with no symptom that a document count alone cannot see.
 */
function documentedCommandNpmInvocations(): array
{
    $invocations = [];

    foreach (documentedCommandLines() as $line => $command) {
        if (preg_match_all('/npm\s+run\s+([\w:.-]+)/', $command, $matches) > 0) {
            foreach ($matches[1] as $script) {
                $invocations[] = ['line' => $line, 'script' => $script, 'command' => $command];
            }
        }
    }

    expect(count($invocations))->toBeGreaterThanOrEqual(
        DOCUMENTED_COMMAND_MIN_NPM_INVOCATIONS,
        'Discovery floor: parsed '.count($invocations).' `npm run` invocation(s) from '.
        DOCUMENTED_COMMAND_DOCUMENT.'. Lines were found but nothing matched, which is the failure '.
        'mode with no symptom — a block count alone cannot see it.'
    );

    return $invocations;
}

/**
 * Every `docker compose exec <service> …` a reader would copy, as [line, service, wholeCommand].
 *
 * Carries the third floor, for the same reason and independently of the second.
 */
function documentedCommandComposeInvocations(): array
{
    $invocations = [];

    foreach (documentedCommandLines() as $line => $command) {
        if (preg_match_all('/docker\s+compose\s+exec\s+(?:-\S+\s+)*([a-z][\w-]*)\s+(.+?)(?:&&|$)/', $command, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $invocations[] = ['line' => $line, 'service' => $match[1], 'command' => trim($match[2])];
            }
        }
    }

    expect(count($invocations))->toBeGreaterThanOrEqual(
        DOCUMENTED_COMMAND_MIN_COMPOSE_INVOCATIONS,
        'Discovery floor: parsed '.count($invocations).' `docker compose exec` invocation(s) from '.
        DOCUMENTED_COMMAND_DOCUMENT.'. The service-scoped arm cannot fire on nothing, so it fails here.'
    );

    return $invocations;
}

it('prescribes only npm scripts that exist', function (): void {
    $missing = [];

    foreach (documentedCommandNpmInvocations() as $invocation) {
        if (documentedCommandResolve($invocation['script']) === null) {
            $missing[] = DOCUMENTED_COMMAND_DOCUMENT.':'.$invocation['line'].
                ' names `npm run '.$invocation['script'].'`, which package.json does not define';
        }
    }

    expect($missing)->toBe([], implode("\n", $missing));
});

it('never prescribes a browser-driving script inside a musl container', function (): void {
    $musl = documentedCommandMuslServices();
    $violations = [];

    foreach (documentedCommandComposeInvocations() as $invocation) {
        if (! in_array($invocation['service'], $musl, true)) {
            continue;
        }

        if (preg_match('/npm\s+run\s+([\w:.-]+)/', $invocation['command'], $match) !== 1) {
            continue;
        }

        $leaf = documentedCommandResolve($match[1]);

        if ($leaf !== null && documentedCommandDrivesBrowser($leaf)) {
            $violations[] = DOCUMENTED_COMMAND_DOCUMENT.':'.$invocation['line'].
                ' runs `npm run '.$match[1].'` in the musl service `'.$invocation['service'].
                '`. That script resolves to `'.$leaf.'`, which launches a browser, and a glibc '.
                'Chromium fails `spawn ... ENOENT` there WITH THE BINARY PRESENT AND EXECUTABLE. '.
                'Installing it in that container does not help. Use the `e2e` image instead.';
        }
    }

    expect($violations)->toBe([], implode("\n", $violations));
});

it('passes --url wherever it invokes the storybook test runner', function (): void {
    // Derived, not declared: the rule exists only while the package script is bare. If someone
    // gives `test-storybook` a default `--url` of its own, this arm correctly stops applying.
    $leaf = documentedCommandResolve('ds:test');

    if ($leaf === null || str_contains($leaf, '--url')) {
        expect(true)->toBeTrue();

        return;
    }

    $violations = [];

    foreach (documentedCommandLines() as $line => $command) {
        if (! documentedCommandDrivesBrowser($command)) {
            continue;
        }

        if (! str_contains($command, 'test-storybook')) {
            continue;
        }

        if (! str_contains($command, '--url')) {
            $violations[] = DOCUMENTED_COMMAND_DOCUMENT.':'.$line.
                ' invokes the storybook test runner with no `--url`. The package script is `'.
                $leaf.'`, which defaults to a server on :6006 that nothing here starts.';
        }
    }

    expect($violations)->toBe([], implode("\n", $violations));
});

it('disables telemetry wherever it prescribes a storybook build', function (): void {
    // Derived, not declared, exactly like the `--url` arm above: the rule exists only for the scripts
    // README.md actually tells a reader to run. A build script nobody is pointed at is nobody's trap.
    $violations = [];
    $seen = 0;

    foreach (documentedCommandNpmInvocations() as $invocation) {
        $leaf = documentedCommandResolve($invocation['script']);

        if ($leaf === null || preg_match(DOCUMENTED_COMMAND_BUILD_INVOCATION, $leaf) !== 1) {
            continue;
        }

        $seen++;

        if (! str_contains($leaf, DOCUMENTED_COMMAND_TELEMETRY_FLAG)) {
            $violations[] = DOCUMENTED_COMMAND_DOCUMENT.':'.$invocation['line'].
                ' names `npm run '.$invocation['script'].'`, which resolves to `'.$leaf.
                '`. A Storybook build without `'.DOCUMENTED_COMMAND_TELEMETRY_FLAG.'` exits 0 when it '.
                'crashes: the crash-report prompt never settles on a non-TTY stdin, so the rethrow is '.
                'never reached and Node exits on a drained event loop. Every check of this command\'s '.
                'status is then vacuous, which is the whole reason the flag is here.';
        }
    }

    expect($seen)->toBeGreaterThanOrEqual(
        DOCUMENTED_COMMAND_MIN_BUILD_INVOCATIONS,
        'Discovery floor: resolved '.$seen.' storybook-build invocation(s) from '.
        DOCUMENTED_COMMAND_DOCUMENT.'. An arm that matched nothing is green for the wrong reason.'
    );

    expect($violations)->toBe([], implode("\n", $violations));
});

it('publishes the host port of every dev server it prescribes inside a compose service', function (): void {
    // ⛔ WHY THIS ARM EXISTS (M72). `packages/design-system/README.md` documented `npm run storybook`
    // for as long as it has existed, the root had aliases for three of its four scripts, and the
    // `node` service published 5173 and not 6006 — so the ONE command that would show a developer the
    // design system started a server nothing on the host could reach. Nothing was wrong in any single
    // file; the defect lived in the JOIN between a prescribed command and a published port, which is
    // exactly the shape this file was built for and the one nobody had asserted.
    //
    // ⚠️ It reads the port out of the SCRIPT and the publication out of the COMPOSE FILE. Neither
    // number is written here, so this cannot go stale against the tree it measures.
    $musl = documentedCommandMuslServices();
    $checked = 0;
    $violations = [];

    foreach (documentedCommandComposeInvocations() as $invocation) {
        if (preg_match('/npm\s+run\s+([\w:.-]+)/', $invocation['command'], $match) !== 1) {
            continue;
        }

        $leaf = documentedCommandResolve($match[1]);

        if ($leaf === null || preg_match(DOCUMENTED_COMMAND_DEV_SERVER_PORT, $leaf, $port) !== 1) {
            continue;
        }

        $checked++;
        $wanted = (int) $port[1];
        $published = documentedCommandPublishedPorts($invocation['service']);

        if (! in_array($wanted, $published, true)) {
            $violations[] = DOCUMENTED_COMMAND_DOCUMENT.':'.$invocation['line'].
                ' runs `npm run '.$match[1].'` in the service `'.$invocation['service'].
                '`. That resolves to `'.$leaf.'`, which binds port '.$wanted.
                ', and docker-compose.yml publishes ['.implode(', ', $published).'] for that service. '.
                'The server starts and nothing on the host can reach it — a prescribed command that '.
                'appears to work and does nothing.';
        }
    }

    expect($checked)->toBeGreaterThan(
        0,
        'Discovery floor: no prescribed command resolved to a port-binding dev server, so this arm '.
        'compared nothing. A selector that matches none of them cannot fail, which is the decorative '.
        'gate M43 measured — it fails here instead of reporting a clean run.'
    );

    expect($violations)->toBe([], implode("
", $violations));

    // The service the block actually talks to must be in the set the musl arm scopes over, or these
    // two arms are quietly measuring different documents.
    expect($musl)->toContain('node');
});

it('still resolves the script graph the other arms discriminate over', function (): void {
    // The discriminating control, kept as an assertion rather than a comment. Both of these are
    // real root scripts reached through the same `npm --prefix` indirection, and they differ in
    // exactly the property the second arm keys on. A resolver that stopped resolving, or a repair
    // that swept every `ds:*` out of the README, turns this red while the sweeps above stay green.
    expect(documentedCommandResolve('ds:test'))->not->toBeNull();
    expect(documentedCommandDrivesBrowser((string) documentedCommandResolve('ds:test')))->toBeTrue();

    expect(documentedCommandResolve('ds:tokens'))->not->toBeNull();
    expect(documentedCommandDrivesBrowser((string) documentedCommandResolve('ds:tokens')))->toBeFalse();

    // And the musl set must actually contain the service the README's block talks to, or the
    // second arm is green because it matched nothing rather than because nothing is wrong.
    expect(documentedCommandMuslServices())->toContain('node');

    // M71 — the same discriminating pair for the telemetry arm, and it keys on the property that arm
    // selects with rather than on the flag it then asserts. `ds:storybook:build` resolves to a build;
    // `ds:test` resolves to `test-storybook`, which contains the word and is NOT one. A boundary that
    // stopped distinguishing them, or a repair that swept `ds:storybook:build` out of the README,
    // turns this red while the sweep above stays green on an empty set.
    expect(preg_match(DOCUMENTED_COMMAND_BUILD_INVOCATION, (string) documentedCommandResolve('ds:storybook:build')))->toBe(1);
    expect(preg_match(DOCUMENTED_COMMAND_BUILD_INVOCATION, (string) documentedCommandResolve('ds:test')))->toBe(0);
});
