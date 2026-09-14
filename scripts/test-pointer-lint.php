<?php

declare(strict_types=1);

/*
 * Test-class pointer gate (M90, widened to app/, database/ and routes/ in M92) — a file that names a
 * `*Test` class which has never been written.
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
 *   TenantCustomColumnsTest      named as the tenants column-whitelist guard by four sites that had
 *                               ALREADY been corrected to cite TenantColumnWhitelistTest at P2a, and
 *                               keep the dead name only as the one they used to carry (M91).
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
 * ⛔ M92 WIDENED IT, AND THE ROW THAT ASKED FOR THAT NAMED SEVEN VIOLATIONS WHERE THERE WERE EIGHT.
 * Scanning `tests/` only meant the species this gate exists to end was still free everywhere else: seven
 * dead pointers across twelve sites in `app/` and `database/`, plus `AchievementsRouteGuardsTest` in
 * `routes/tenant.php` that nobody had filed. All eight were dispositioned the same way — a real arm
 * asserting the exact cited property existed in every case, so every one was a wrong NAME rather than
 * missing coverage. ⚠️ Two of the eight would have been mis-corrected by the obvious same-name candidate:
 * the member-joined property lives in `PointAwardRlsTest` and not `PointAwardTest`, and the toggleable-
 * modules property lives in `SettingsVocabularyTest` and not `ModuleToggleTest`.
 *
 * ⚠️ AND A CORRECTION NOTE MAY NOT NAME THE CORPSE. This gate reads identifiers, not intent, so
 * "corrected from FooTest, which never existed" re-arms it against the very comment that fixed it. That
 * is what the standing `TenantCustomColumnsTest` exemption is for — a case where the mention IS the
 * finding — and eight more of those would turn EXEMPTIONS into a list of excuses. The corrected sites
 * say what the arm does and leave the provenance to git.
 *
 * RULES
 *   R1  Every `*Test` identifier appearing anywhere under MENTION_ROOTS must resolve to a `<Name>.php`
 *       file under DISK_ROOT, unless it is declared in EXEMPTIONS below with a reason. ⚠️ The two roles
 *       are separate parameters: `tests/` alone answers "does this file exist", and every root is
 *       searched for mentions. Conflating them is what made a naive widening report the whole corpus as
 *       dangling.
 *   R2  Each root must find files and must find identifiers. An empty harvest FAILS — this is a regex over
 *       PHP, so its failure mode is finding nothing, which would otherwise read as a clean tree. The
 *       floors are PER ROOT and set near each census rather than at 1, because the container defect above
 *       is a PARTIAL collapse and one pooled floor would let a small root vanish inside a large one.
 *       A floor breach skips R1 entirely: a broken scan is not a clean tree and is not a hundred
 *       violations either.
 *   R3  An exemption naming a file that now exists FAILS. A list that can only grow becomes a list of
 *       false statements.
 *
 * ⚠️ `scripts/` IS DELIBERATELY NOT A ROOT, AND THAT IS A KNOWN BLIND SPOT RATHER THAN AN OVERSIGHT.
 * This file's own header names four classes that have never existed — they are the FINDINGS the gate was
 * built on, and each is load-bearing prose. Adding `scripts/` would redden on them and the only way out
 * would be four exemptions whose reason is "the gate's documentation says so", which is the list becoming
 * a list of excuses. It is filed as a row rather than papered over here.
 *
 * Usage:  php scripts/test-pointer-lint.php [--selftest]
 *
 * `--selftest` is the positive control, and it is here rather than in Pest for the reason in R2's note:
 * the truthful container answer for this gate is RED, and `scripts/mutate.php` cannot drive a control for
 * a gate that is not green in a container (docs/feature-backlog.md:8251). It builds three synthetic trees
 * in a temp directory — clean, one dangling pointer, one stale exemption — and asserts the verdict for
 * each, so the RED arms are exercised deliberately instead of being assumed. M92 took it to six, and the
 * three it added are the ones the single-root shape made impossible to write: a pointer dangling OUTSIDE
 * `tests/`, a pointer outside `tests/` resolving to a file that exists only under it, and a reported site
 * that must carry its own root. The second is the false POSITIVE a naive widening produces by the
 * hundred, which is the more dangerous half — a gate that cries wolf gets switched off.
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
    // ⛔ M91: THE EXEMPTION IS STILL REQUIRED AND ITS OLD REASON WAS FALSE. The four sites it named were
    // corrected at P2a and cite TenantColumnWhitelistTest; what survives is a CORRECTION NOTE in that
    // file, inside tests/, which is the single mention this gate can see and the only one it exempts.
    'TenantCustomColumnsTest' => 'named by TenantColumnWhitelistTest to record that it has never existed and was the name four sites carried until P2a; removing the mention would delete the finding',
];

/**
 * The directory whose `*Test.php` basenames DEFINE what exists. There is exactly one, and it is not a
 * member of MENTION_ROOTS by accident — see the note on scan().
 */
const DISK_ROOT = 'tests';

/**
 * Where a `*Test` mention is looked for, with the floor for each.
 *
 * ⛔ M92 — THE ROOTS ARE PLURAL NOW, AND ADDING THEM WAS NOT THE HARD PART. The gate shipped scanning
 * `tests/` only, so seven dead pointers in `app/` and `database/` — and an eighth in `routes/` that the
 * row filing them did not name — were invisible to the very gate written to end that species. The row
 * warned that the repair was "not simply widening the scan roots" and gave two reasons, both true and
 * both smaller than the real one: scan() took ONE directory and used it BOTH as the mention corpus AND
 * as the definition of which test files exist. A second call over `app/` would have found zero
 * `*Test.php` files there and reported all 147 mentioned names as dangling — in practice it would have
 * bailed on the file floor first, which is a broken gate reporting a broken scan. The two roles are now
 * separate parameters, and DISK_ROOT is the one that answers "does this file exist".
 *
 * ⚠️ FLOORS ARE PER ROOT BECAUSE A COLLAPSE IS PARTIAL. The container's RecursiveDirectoryIterator sees
 * roughly 90% of `tests/` across the Windows bind mount, which is why these sit near the census rather
 * than at 1 — a floor of 1 would not have caught the failure that put this gate on the host. A single
 * pooled floor would let a total collapse of `routes/` (7 files) hide inside `app/`'s 814.
 *
 * ⚠️ They RATCHET UP ONLY. A floor lowered to make a red run green is the gate being edited to agree
 * with the tree instead of the other way round.
 *
 * @var array<string, array{files: int, names: int}>
 */
const MENTION_ROOTS = [
    'tests' => ['files' => 400, 'names' => 150],
    'app' => ['files' => 750, 'names' => 100],
    'database' => ['files' => 140, 'names' => 25],
    'routes' => ['files' => 6, 'names' => 8],
];

$root = dirname(__DIR__);

if (in_array('--selftest', array_slice($argv, 1), true)) {
    exit(selftest());
}

$result = scan($root, $root.'/'.DISK_ROOT, MENTION_ROOTS, EXEMPTIONS);

foreach ($result['errors'] as $error) {
    fwrite(STDERR, 'test-pointer-lint: '.$error."\n");
}

if ($result['errors'] !== []) {
    fwrite(STDERR, "test-pointer-lint: FAILED\n");
    exit(EXIT_VIOLATION);
}

printf(
    "test-pointer-lint: passed (%d file(s) over %d root(s), %d test file(s) on disk, %d `*Test` name(s) mentioned, %d exemption(s)).\n",
    $result['files'],
    count(MENTION_ROOTS),
    $result['onDisk'],
    $result['names'],
    count(EXEMPTIONS)
);

foreach ($result['perRoot'] as $rootName => $counts) {
    printf("test-pointer-lint:   %-9s %4d file(s), %3d name(s)\n", $rootName, $counts['files'], $counts['names']);
}

exit(EXIT_OK);

/**
 * @param  string  $relativeTo  every site is rendered relative to THIS, never to its own root — a site
 *                              reported as `Models/Domain.php` is ambiguous between `app/` and `tests/`,
 *                              which is the defect the M91 row named first.
 * @param  string  $diskDir  the directory whose `*Test.php` basenames define existence
 * @param  array<string, array{files: int, names: int}>  $mentionRoots  relative path => floors
 * @param  array<string, string>  $exemptions
 * @return array{files: int, onDisk: int, names: int, perRoot: array<string, array{files: int, names: int}>, errors: list<string>}
 */
function scan(string $relativeTo, string $diskDir, array $mentionRoots, array $exemptions): array
{
    $errors = [];

    if (! is_dir($diskDir)) {
        return ['files' => 0, 'onDisk' => 0, 'names' => 0, 'perRoot' => [], 'errors' => ["not a directory: {$diskDir}"]];
    }

    // The EXISTENCE half. Only DISK_ROOT answers "is there a file behind this name", and it answers by
    // BASENAME, so a test moved between directories under it is not a violation.
    /** @var array<string, true> $onDisk */
    $onDisk = [];

    foreach (phpFilesUnder($diskDir) as $path) {
        $onDisk[basename($path, '.php')] = true;
    }

    // The MENTION half, per root, each with its own floor.
    /** @var array<string, array<string, true>> $mentions */
    $mentions = [];
    /** @var array<string, array{files: int, names: int}> $perRoot */
    $perRoot = [];
    $totalFiles = 0;

    foreach ($mentionRoots as $rootName => $floors) {
        $dir = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $relativeTo), '/').'/'.$rootName;

        if (! is_dir($dir)) {
            $errors[] = "not a directory: {$dir}";

            continue;
        }

        $paths = phpFilesUnder($dir);
        $totalFiles += count($paths);

        // R2 — the floor, PER ROOT. See MENTION_ROOTS: a partial collapse is the failure mode that
        // actually happens here, and one pooled floor would let a small root vanish inside a large one.
        if (count($paths) < $floors['files']) {
            $errors[] = sprintf(
                'R2 %s harvested only %d file(s), under the floor of %d — this is a broken scan, not a clean tree (are you inside the container?)',
                $rootName,
                count($paths),
                $floors['files']
            );

            continue;
        }

        /** @var array<string, true> $namesHere */
        $namesHere = [];

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

            // ⚠️ RELATIVE TO THE REPOSITORY ROOT, NEVER TO ITS OWN ROOT. The single-root version stripped
            // the scanned directory, so a second root would have reported `Models/Domain.php` —
            // ambiguous between `app/` and `tests/`, and not a path anything can open.
            $relative = relativeSite($relativeTo, $path);

            foreach ($matches[1] as $name) {
                $mentions[$name][$relative] = true;
                $namesHere[$name] = true;
            }
        }

        if (count($namesHere) < $floors['names']) {
            $errors[] = sprintf(
                'R2 %s harvested only %d `*Test` name(s), under the floor of %d — the identifier pattern has stopped matching',
                $rootName,
                count($namesHere),
                $floors['names']
            );
        }

        $perRoot[$rootName] = ['files' => count($paths), 'names' => count($namesHere)];
    }

    // ⛔ A BROKEN SCAN IS NOT A CLEAN TREE, AND IT IS NOT A HUNDRED VIOLATIONS EITHER. R1 run over a
    // collapsed harvest would report the whole corpus as dangling and bury the one line that says why.
    if ($errors !== []) {
        return [
            'files' => $totalFiles,
            'onDisk' => count($onDisk),
            'names' => count($mentions),
            'perRoot' => $perRoot,
            'errors' => $errors,
        ];
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

    return [
        'files' => $totalFiles,
        'onDisk' => count($onDisk),
        'names' => count($mentions),
        'perRoot' => $perRoot,
        'errors' => $errors,
    ];
}

/**
 * Every `.php` file under $dir, recursively.
 *
 * ⚠️ Extracted in M92 because it now has TWO callers with different jobs — the existence set and each
 * mention root — and having one of them drift from the other is the shape of defect this whole gate is
 * about. It is the same iterator the single-root version used, moved rather than rewritten.
 *
 * @return list<string>
 */
function phpFilesUnder(string $dir): array
{
    /** @var list<string> $paths */
    $paths = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $entry) {
        if (! $entry instanceof SplFileInfo || ! $entry->isFile() || $entry->getExtension() !== 'php') {
            continue;
        }

        $paths[] = $entry->getPathname();
    }

    return $paths;
}

/**
 * A site path rendered relative to the repository root, with forward slashes.
 *
 * ⛔ THIS IS THE HALF THE M91 ROW NAMED. The single-root version stripped the SCANNED directory, which
 * was harmless while there was one of them and ambiguous the moment there were four: `Models/Domain.php`
 * names a file under `app/` and could equally have named one under `tests/`, and neither is openable.
 * A violation a reader cannot open is a violation a reader will not fix.
 */
function relativeSite(string $relativeTo, string $path): string
{
    $normalisedRoot = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $relativeTo), '/');
    $normalisedPath = str_replace(DIRECTORY_SEPARATOR, '/', $path);

    if (str_starts_with($normalisedPath, $normalisedRoot.'/')) {
        return substr($normalisedPath, strlen($normalisedRoot) + 1);
    }

    return $normalisedPath;
}

/**
 * The positive control. Synthetic trees, each asserting a verdict — including the RED ones, because a
 * gate you have never seen go red is a gate you have not tested.
 *
 * ⛔ M92 ADDS THE ARMS THE SPLIT MADE POSSIBLE, AND THEY ARE THE POINT OF THE SPLIT. Three of the six
 * cases below could not have been written against the single-root gate at all: a pointer dangling in a
 * NON-test root, a pointer in a non-test root resolving to a file that exists only under `tests/`, and
 * a site path that must carry its root. The first is the defect this widening exists to catch, and the
 * second is the false POSITIVE a naive second call to the old scan() would have produced by the
 * hundred — which is the more dangerous of the two, because a gate that cries wolf gets switched off.
 */
function selftest(): int
{
    $base = sys_get_temp_dir().'/test-pointer-lint-selftest-'.getmypid();
    $failures = [];

    // Floors of 1 for the fixture trees: they are two or three files, and the REAL floors are exercised
    // by the production run and by the `floor` arm at the bottom.
    $floors = ['tests' => ['files' => 1, 'names' => 1], 'app' => ['files' => 1, 'names' => 1]];

    $cases = [
        'clean' => [
            'tests' => ['AlphaTest.php' => '// see BetaTest for the other half', 'BetaTest.php' => '// see AlphaTest'],
            'app' => ['Thing.php' => '// covered by AlphaTest'],
            'exemptions' => [],
            'expectClean' => true,
            'because' => 'every name resolves, including the one mentioned from app/',
        ],
        'dangling-in-tests' => [
            'tests' => ['AlphaTest.php' => '// the consume half is GhostTest', 'BetaTest.php' => '//'],
            'app' => ['Thing.php' => '// covered by AlphaTest'],
            'exemptions' => [],
            'expectClean' => false,
            'because' => 'GhostTest does not exist — the arm the original gate was for',
        ],
        'dangling-in-app' => [
            'tests' => ['AlphaTest.php' => '// see BetaTest', 'BetaTest.php' => '//'],
            'app' => ['Thing.php' => '// PhantomTest pins this'],
            'exemptions' => [],
            'expectClean' => false,
            'because' => 'M92 — a dead pointer OUTSIDE tests/, which is the whole reason the roots are plural',
        ],
        'cross-root-existence' => [
            // ⚠️ Each fixture names ITSELF. The name floor counts MENTIONS, not filenames, so a body of
            //    `//` harvests zero names from tests/ and the arm fails on R2 before it reaches R1 —
            //    which is how this case was written the first time, and it read as a real regression.
            'tests' => ['AlphaTest.php' => '// class AlphaTest', 'BetaTest.php' => '// class BetaTest'],
            'app' => ['Thing.php' => '// AlphaTest and BetaTest both cover this'],
            'exemptions' => [],
            'expectClean' => true,
            'because' => 'M92 — a mention in app/ resolves against tests/, NOT against its own root. '
                .'A second scan() call over app/ would have called both of these dangling.',
        ],
        'stale-exemption' => [
            'tests' => ['AlphaTest.php' => '// mentions BetaTest', 'BetaTest.php' => '//'],
            'app' => ['Thing.php' => '// covered by AlphaTest'],
            'exemptions' => ['BetaTest' => 'stale on purpose'],
            'expectClean' => false,
            'because' => 'R3 — an exemption for a name that now resolves is a false statement about the tree',
        ],
    ];

    foreach ($cases as $label => $case) {
        $caseRoot = $base.'/'.$label;

        foreach (['tests', 'app'] as $rootName) {
            $dir = $caseRoot.'/'.$rootName;

            if (! is_dir($dir) && ! mkdir($dir, 0o777, true) && ! is_dir($dir)) {
                $failures[] = "{$label}: could not create {$dir}";

                continue 2;
            }

            foreach ($case[$rootName] as $name => $body) {
                file_put_contents($dir.'/'.$name, "<?php\n".$body."\n");
            }
        }

        $result = scan($caseRoot, $caseRoot.'/tests', $floors, $case['exemptions']);
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

        // ⛔ THE SITE PATH MUST CARRY ITS ROOT, AND THIS IS WHERE THAT IS ASSERTED. `dangling-in-app`
        //    reports one violation; if it reads `Thing.php` rather than `app/Thing.php` the reader
        //    cannot open it, and the gate has traded a dead pointer for an unresolvable one.
        if ($label === 'dangling-in-app' && ! $clean && ! str_contains(implode(' ', $result['errors']), 'app/Thing.php')) {
            $failures[] = 'dangling-in-app: the site was not rendered relative to the repository root — '
                .'relativeSite() is stripping the wrong prefix. Got: '.implode(' | ', $result['errors']);
        }

        foreach (['tests', 'app'] as $rootName) {
            array_map('unlink', glob($caseRoot.'/'.$rootName.'/*.php') ?: []);
            @rmdir($caseRoot.'/'.$rootName);
        }

        @rmdir($caseRoot);
    }

    // The floor arm, run separately because it must not be masked by the small fixtures above, and now
    // per root: the production floors are what would catch a partial container collapse.
    $caseRoot = $base.'/floor';

    if (is_dir($caseRoot.'/tests') || mkdir($caseRoot.'/tests', 0o777, true) || is_dir($caseRoot.'/tests')) {
        @mkdir($caseRoot.'/app', 0o777, true);
        file_put_contents($caseRoot.'/tests/OneTest.php', "<?php\n// AnotherTest\n");
        file_put_contents($caseRoot.'/app/Thing.php', "<?php\n// OneTest\n");

        // The REAL floors, taken from the production map rather than restated — a second copy of a
        // census is the defect this repository gates elsewhere, and it would silently decouple this
        // arm from the floors it exists to prove are armed.
        $productionFloors = ['tests' => MENTION_ROOTS['tests'], 'app' => MENTION_ROOTS['app']];
        $result = scan($caseRoot, $caseRoot.'/tests', $productionFloors, []);

        if ($result['errors'] === []) {
            $failures[] = 'floor: a one-file-per-root tree passed the production floors — R2 is not armed';
        }

        array_map('unlink', glob($caseRoot.'/tests/*.php') ?: []);
        array_map('unlink', glob($caseRoot.'/app/*.php') ?: []);
        @rmdir($caseRoot.'/tests');
        @rmdir($caseRoot.'/app');
        @rmdir($caseRoot);
    }

    @rmdir($base);

    foreach ($failures as $failure) {
        fwrite(STDERR, 'test-pointer-lint selftest: '.$failure."\n");
    }

    if ($failures !== []) {
        fwrite(STDERR, "test-pointer-lint selftest: FAILED\n");

        return EXIT_VIOLATION;
    }

    printf(
        "test-pointer-lint selftest: passed (%d case(s) — %s, collapsed floor).\n",
        count($cases) + 1,
        implode(', ', array_keys($cases))
    );

    return EXIT_OK;
}
