<?php

declare(strict_types=1);

/*
 * Test-class pointer gate (M90) — a test file that names a `*Test` class which has never been written.
 *
 * WHY THIS EXISTS. A test file that says "the other half of this is covered by SomeOtherTest" is making a
 * claim about the tree, and nothing in this repository could check it.
 * `scripts/citation-liveness-lint.php` reads `path:N` citations in DOCUMENTS; a bare PHP class name inside
 * a `|`-comment carries no path and no line, so it matches no citation pattern. This entire species of
 * pointer was invisible to every gate, and it rots the same way every other pointer does.
 *
 * ⛔ FOUR WERE DEAD WHEN THIS WAS WRITTEN, AND THE BACKLOG ROW THAT PROMPTED IT NAMED ONE.
 *   ScopeNodeConcurrentMoveTest  cited as the file where "genuine contention" lived. `git log --all -S`
 *                               shows the name entered the tree as a COMMENT in G10b1 and was never a
 *                               file in any commit — so "not written yet" was never true either.
 *   FormSectionRoutesTest        cited by StepProjectionTest as exercising the section call sites.
 *   ImpersonationConsumeTest     cited by ImpersonationMintTest as "the consume half".
 *   TenantCustomColumnsTest      cited by four sites as the tenants column-whitelist guard.
 * One filed, three found by writing this gate. That is the argument for a gate over a sweep: the row was
 * a census of one and the tree held four.
 *
 * ⛔⛔ IT RUNS ON THE HOST, AND THAT IS MEASURED RATHER THAN INHERITED FROM THE OTHER GATES.
 * The first draft was a Pest arm. Inside the app container `RecursiveDirectoryIterator` descends the
 * Windows bind mount only PARTIALLY: it reports 410 `.php` files under `tests/` where the host reports
 * 449 — and `tests/Feature/Forms/PublishLockingTest.php` is one of the 39 it cannot see. The consequence
 * is not a missed violation but a FALSE one: a real file goes missing from the on-disk set, so every
 * pointer to it reads as dangling. That draft reported fourteen violations, nine of which were phantoms
 * of the harness rather than of the tree. CLAUDE.md's gate table already sends the lint gates to the host
 * for this exact reason; this is one more instrument agreeing, and it corroborates
 * `docs/feature-backlog.md:6918`'s "PHPUnit's own collector loses 40 test files in the container" from a
 * different direction entirely.
 *
 * RULES
 *   R1  Every `*Test` identifier appearing anywhere under `tests/` must resolve to a `<Name>.php` file
 *       under `tests/`, unless it is declared in EXEMPTIONS below with a reason.
 *   R2  The scan must find files and must find identifiers. An empty harvest FAILS — this is a regex over
 *       PHP, so its failure mode is finding nothing, which would otherwise read as a clean tree. The
 *       floors are set near the current census rather than at 1, because the container defect above is a
 *       PARTIAL collapse and a floor of 1 would not have caught it.
 *   R3  An exemption naming a file that now exists FAILS. A list that can only grow becomes a list of
 *       false statements.
 *
 * Usage:  php scripts/test-pointer-lint.php [--selftest]
 *
 * `--selftest` is the positive control, and it is here rather than in Pest for the reason in R2's note:
 * the truthful container answer for this gate is RED, and `scripts/mutate.php` cannot drive a control for
 * a gate that is not green in a container (docs/feature-backlog.md:8251). It builds three synthetic trees
 * in a temp directory — clean, one dangling pointer, one stale exemption — and asserts the verdict for
 * each, so the RED arms are exercised deliberately instead of being assumed.
 */

const EXIT_OK = 0;
const EXIT_VIOLATION = 1;

/**
 * Names that are deliberately not files, each with the reason it is one.
 *
 * ⚠️ A DECLARED LIST RATHER THAN A CLEVER PREDICATE, DELIBERATELY. A name can legitimately appear with no
 * file behind it — a fixture identity, or a sentence whose entire point is that the file does NOT exist.
 * An earlier draft tried to infer that from surrounding negation words, which is the kind of rule that
 * passes on the corpus it was written against and fails on the next sentence somebody writes. Declaring
 * each one means adding a fifth is a deliberate act with an author and a reason.
 *
 * @var array<string, string>
 */
const EXEMPTIONS = [
    'SentinelTest' => 'the mutation harness fixture identity — tests/fixtures/mutate/ is a corpus rather than a suite, and MutateHarnessTest drives it by path',
    'TenantCustomColumnsTest' => 'named BY TenantColumnWhitelistTest precisely to record that it has never existed and that four sites cite it; removing the mention would delete the finding',
];

const FILE_FLOOR = 400;
const NAME_FLOOR = 150;

$root = dirname(__DIR__);

if (in_array('--selftest', array_slice($argv, 1), true)) {
    exit(selftest());
}

$result = scan($root.'/tests', EXEMPTIONS, FILE_FLOOR, NAME_FLOOR);

foreach ($result['errors'] as $error) {
    fwrite(STDERR, 'test-pointer-lint: '.$error."\n");
}

if ($result['errors'] !== []) {
    fwrite(STDERR, "test-pointer-lint: FAILED\n");
    exit(EXIT_VIOLATION);
}

printf(
    "test-pointer-lint: passed (%d test file(s), %d `*Test` name(s) mentioned, %d exemption(s)).\n",
    $result['files'],
    $result['names'],
    count(EXEMPTIONS)
);

exit(EXIT_OK);

/**
 * @param  array<string, string>  $exemptions
 * @return array{files: int, names: int, errors: list<string>}
 */
function scan(string $testsDir, array $exemptions, int $fileFloor, int $nameFloor): array
{
    $errors = [];

    if (! is_dir($testsDir)) {
        return ['files' => 0, 'names' => 0, 'errors' => ["not a directory: {$testsDir}"]];
    }

    /** @var array<string, true> $onDisk */
    $onDisk = [];
    /** @var list<string> $paths */
    $paths = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $entry) {
        if (! $entry instanceof SplFileInfo || ! $entry->isFile() || $entry->getExtension() !== 'php') {
            continue;
        }

        $paths[] = $entry->getPathname();
        $onDisk[$entry->getBasename('.php')] = true;
    }

    // R2 — the floor. See the header: a PARTIAL collapse is the failure mode that actually happens here,
    // so this sits near the census rather than at 1.
    if (count($paths) < $fileFloor) {
        $errors[] = sprintf(
            'harvested only %d test file(s), under the floor of %d — this is a broken scan, not a clean tree (are you inside the container?)',
            count($paths),
            $fileFloor
        );

        return ['files' => count($paths), 'names' => 0, 'errors' => $errors];
    }

    /** @var array<string, array<string, true>> $mentions */
    $mentions = [];

    foreach ($paths as $path) {
        $source = file_get_contents($path);

        if ($source === false) {
            $errors[] = "could not read {$path}";

            continue;
        }

        if (preg_match_all('/\b([A-Z][A-Za-z0-9]*Test)\b/', $source, $matches) === false) {
            $errors[] = "the identifier pattern failed against {$path}";

            continue;
        }

        $relative = str_replace('\\', '/', ltrim(str_replace($testsDir, '', $path), '\\/'));

        foreach ($matches[1] as $name) {
            $mentions[$name][$relative] = true;
        }
    }

    if (count($mentions) < $nameFloor) {
        $errors[] = sprintf(
            'harvested only %d `*Test` name(s), under the floor of %d — the identifier pattern has stopped matching',
            count($mentions),
            $nameFloor
        );

        return ['files' => count($paths), 'names' => count($mentions), 'errors' => $errors];
    }

    // R1
    $dangling = [];

    foreach ($mentions as $name => $sites) {
        if (isset($onDisk[$name]) || array_key_exists($name, $exemptions)) {
            continue;
        }

        $dangling[$name] = array_keys($sites);
    }

    ksort($dangling);

    foreach ($dangling as $name => $sites) {
        $errors[] = sprintf(
            'R1 %s is named by %s and no such file exists — correct the name, write the file, or declare it in EXEMPTIONS with a reason',
            $name,
            implode(', ', $sites)
        );
    }

    // R3
    foreach (array_keys($exemptions) as $name) {
        if (isset($onDisk[$name])) {
            $errors[] = sprintf('R3 %s is exempted but now exists as a file — remove the exemption', $name);
        }
    }

    return ['files' => count($paths), 'names' => count($mentions), 'errors' => $errors];
}

/**
 * The positive control. Three synthetic trees, each asserting a verdict — including the two RED ones,
 * because a gate you have never seen go red is a gate you have not tested.
 */
function selftest(): int
{
    $base = sys_get_temp_dir().'/test-pointer-lint-selftest-'.getmypid();
    $failures = [];

    $cases = [
        'clean' => [
            'files' => ['AlphaTest.php' => '// see BetaTest for the other half', 'BetaTest.php' => '// see AlphaTest'],
            'exemptions' => [],
            'expectClean' => true,
            'because' => 'both names resolve',
        ],
        'dangling' => [
            'files' => ['AlphaTest.php' => '// the consume half is GhostTest', 'BetaTest.php' => '//'],
            'exemptions' => [],
            'expectClean' => false,
            'because' => 'GhostTest does not exist — this is the arm the whole gate is for',
        ],
        'stale-exemption' => [
            'files' => ['AlphaTest.php' => '// mentions BetaTest', 'BetaTest.php' => '//'],
            'exemptions' => ['BetaTest' => 'stale on purpose'],
            'expectClean' => false,
            'because' => 'R3 — an exemption for a name that now resolves is a false statement about the tree',
        ],
    ];

    foreach ($cases as $label => $case) {
        $dir = $base.'/'.$label;

        if (! is_dir($dir) && ! mkdir($dir, 0o777, true) && ! is_dir($dir)) {
            $failures[] = "{$label}: could not create {$dir}";

            continue;
        }

        foreach ($case['files'] as $name => $body) {
            file_put_contents($dir.'/'.$name, "<?php\n".$body."\n");
        }

        // Floors of 1 here: the fixtures are two files, and the REAL floors are exercised by the
        // production run above. Their own arm is the `floor` case below.
        $result = scan($dir, $case['exemptions'], 1, 1);
        $clean = $result['errors'] === [];

        if ($clean !== $case['expectClean']) {
            $failures[] = sprintf(
                '%s: expected %s (%s), got %s [%s]',
                $label,
                $case['expectClean'] ? 'CLEAN' : 'VIOLATION',
                $case['because'],
                $clean ? 'CLEAN' : 'VIOLATION',
                implode(' | ', $result['errors'])
            );
        }

        array_map('unlink', glob($dir.'/*.php') ?: []);
        @rmdir($dir);
    }

    // The floor arm, run separately because it must not be masked by the two-file fixtures above.
    $dir = $base.'/floor';

    if (is_dir($dir) || mkdir($dir, 0o777, true) || is_dir($dir)) {
        file_put_contents($dir.'/OneTest.php', "<?php\n// AnotherTest\n");
        $result = scan($dir, [], FILE_FLOOR, NAME_FLOOR);

        if ($result['errors'] === []) {
            $failures[] = 'floor: a one-file tree passed the production floor — R2 is not armed';
        }

        array_map('unlink', glob($dir.'/*.php') ?: []);
        @rmdir($dir);
    }

    @rmdir($base);

    foreach ($failures as $failure) {
        fwrite(STDERR, 'test-pointer-lint selftest: '.$failure."\n");
    }

    if ($failures !== []) {
        fwrite(STDERR, "test-pointer-lint selftest: FAILED\n");

        return EXIT_VIOLATION;
    }

    echo "test-pointer-lint selftest: passed (4 case(s) — clean, dangling, stale exemption, collapsed floor).\n";

    return EXIT_OK;
}
