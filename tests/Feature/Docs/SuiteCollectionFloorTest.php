<?php

declare(strict_types=1);

use SebastianBergmann\FileIterator\Facade as FileIteratorFacade;
use Tests\Support\SourceTree;

/*
|--------------------------------------------------------------------------
| Increment M76 — THE SUITE IS ALLOWED TO BE INCOMPLETE. IT IS NOT ALLOWED
| TO BE SILENTLY INCOMPLETE.
|--------------------------------------------------------------------------
|
| ⛔ PHPUnit COLLECTS ITS OWN TEST FILES WITH THE ITERATOR THAT TRUNCATES ON THIS PROJECT'S WINDOWS BIND
| MOUNT. Measured inside `dev_formbuilder_app-app-1` on 2026-09-06: `tests/` holds **424** `*Test.php`
| files and `SebastianBergmann\FileIterator\Facade` — the class `phpunit.xml`'s `<directory>` entries are
| expanded through — returned **384**. The 40 missing files were the whole of `tests/Feature/Forms`:
| 6 of its 46 files were collected and the other 40 were never loaded, never run, and never reported as
| absent. A local run of the full suite printed a green summary for a suite that had silently skipped
| every form lifecycle, policy, publish, schedule and RLS test in the repository.
|
| ⚠️ THIS IS NOT THE `scripts/` TRAP, IT IS THE SAME TRAP ONE LAYER DOWN. `CLAUDE.md` and
| `docs/gate-baselines.md` both record that the lint gates must run on the host because
| `RecursiveDirectoryIterator` descends the bind mount only partially. What neither records is that it is
| a property of PHP's SPL directory iterators on this mount rather than of those five scripts — so it also
| reaches Larastan (the entire cause of the 18 phantom `Access to an undefined property` errors a local
| container PHPStan run reports against CI's zero) and PHPUnit itself. {@see \Tests\Support\SourceTree}
| carries the full measurement: every SPL iterator returns the same wrong number under every flag
| combination, while `scandir`, `glob`, `find` and Symfony's `Finder` all return the truth.
|
| ⛔ THE NEXT DIRECTORY TO GO BLIND CANNOT BE PREDICTED, WHICH IS WHY THIS IS A GATE AND NOT A COMMENT.
| The obvious theory — that a directory truncates once it exceeds roughly forty entries — was tested and
| is FALSE: synthetic directories of up to sixty files, created from inside the container on the same
| mount, do not truncate at all. `tests/Feature` itself holds 41 entries and is enumerated perfectly,
| while `tests/Feature/Forms` holds 46 and collapses to 6. So there is no threshold to document and no
| list to keep; the only durable instrument is a comparison that runs every time.
|
| ⚠️ WHAT THIS CASE DOES ON EACH MACHINE, STATED SO A RED RESULT IS NOT MISREAD AS A REGRESSION.
| In CI it is GREEN — the runner has no bind mount, SPL agrees with the truth, and this gate blocks
| nothing. On a Windows host running the suite inside the container it is RED, and it is TELLING THE
| TRUTH: that run really is missing the files it names. The remedy is to run the named directories
| explicitly (`pest tests/Feature/Forms`), which does collect them, or to read CI as the authority.
| ⛔ Do NOT "fix" a red here by loosening the comparison. A green obtained that way restores exactly the
| silent 40-file omission this file was written to end, and this project has a name for that shape.
|
| ⚠️ IT LIVES IN `tests/Feature/Docs/` DELIBERATELY, ALONGSIDE THE OTHER HARNESS GATES, RATHER THAN IN A
| NEW DIRECTORY OF ITS OWN. A new subdirectory would change `tests/Feature`'s own entry count, and since
| the truncation rule is not understood, a gate that can disappear by being added is not a gate.
|
| Helper names are prefixed `suiteCollection*` deliberately: Pest loads every file in a directory into one
| process, so a same-named file-scope helper is a fatal redeclaration.
*/

/**
 * The `<directory>` entries `phpunit.xml` declares, as absolute paths.
 *
 * Read from the file rather than hardcoded, so adding a third testsuite cannot silently escape this gate.
 *
 * @return list<string>
 */
function suiteCollectionDirectories(): array
{
    $document = base_path('phpunit.xml');

    expect(is_file($document))->toBeTrue('phpunit.xml is missing, so this gate has nothing to check');

    $xml = simplexml_load_string((string) file_get_contents($document));

    expect($xml)->not->toBeFalse('phpunit.xml did not parse');

    $directories = [];

    foreach ($xml->testsuites->testsuite as $suite) {
        foreach ($suite->directory as $directory) {
            $directories[] = base_path(trim((string) $directory));
        }
    }

    sort($directories);

    return $directories;
}

/**
 * One spelling of a path, so two enumerators can be compared at all.
 *
 * ⛔ THIS EXISTS BECAUSE THE FIRST DRAFT OF THIS GATE WAS WRONG AND A CONTROL CAUGHT IT. Run in the
 * container the comparison behaved correctly; run on the Windows host — where nothing truncates and the
 * answer should have been a clean pass — it reported *"sees 425 of the 425 test files on disk, so 425
 * test file(s) will not run"*. Both sides had counted the same 425 files and `array_diff` still matched
 * none of them, because `SourceTree` builds paths with `DIRECTORY_SEPARATOR` while
 * `FileIterator\Facade` normalises its own, so the two lists were the same files spelled two ways.
 * ⚠️ A gate that fires on every file in the repository is not a stricter gate, it is a broken one, and it
 * would have been read as this trap having spread rather than as its own defect.
 */
function suiteCollectionCanonical(string $path): string
{
    return str_replace('\\', '/', $path);
}

it('declares at least one testsuite directory, so the comparison below is not vacuous', function (): void {
    // ⚠️ THE FLOOR COMES FIRST AND IT IS NOT CEREMONY. Every other assertion in this file compares two
    // enumerations of a directory list; over an EMPTY list they agree perfectly and the gate reports that
    // the suite is completely collected. That is the exact shape — a sweep that reads nothing and passes
    // cheerfully — that `ClientUuidScopeTest`'s `toBeGreaterThan(200)` failed to prevent, and it is the
    // reason this project counts the succeeds-on-empty-input family rather than fixing it case by case.
    $directories = suiteCollectionDirectories();

    expect($directories)->toHaveCount(2);

    foreach ($directories as $directory) {
        expect(is_dir($directory))->toBeTrue("phpunit.xml names a testsuite directory that does not exist: {$directory}");
    }
});

it('collects every test file that is actually on disk', function (): void {
    $onDisk = [];
    $collected = [];

    foreach (suiteCollectionDirectories() as $directory) {
        // The truth, via the two enumerators SourceTree cross-checks against each other.
        foreach (SourceTree::filesUnder($directory) as $path) {
            if (str_ends_with($path, 'Test.php')) {
                $onDisk[] = suiteCollectionCanonical($path);
            }
        }

        // What PHPUnit itself will see, through the very class that expands `<directory>`.
        foreach ((new FileIteratorFacade)->getFilesAsArray($directory, 'Test.php') as $path) {
            $collected[] = suiteCollectionCanonical($path);
        }
    }

    sort($onDisk);
    sort($collected);

    // Non-vacuity again, and against a real number rather than a token one: the tree held 424 `*Test.php`
    // files when this was written, and `docs/gate-baselines.md` records the CI test count separately.
    expect(count($onDisk))->toBeGreaterThan(400);

    $missing = array_values(array_diff($onDisk, $collected));

    $report = array_map(
        static fn (string $path): string => str_replace(suiteCollectionCanonical(base_path()).'/', '', $path),
        array_slice($missing, 0, 20),
    );

    // ⚠️ ASSERTED ON THE COUNT RATHER THAN THE ARRAY, DELIBERATELY. `toBe([])` against 40 paths prints a
    // forty-line array diff that buries the sentence explaining what a reader is looking at — and the
    // sentence is the entire value of this gate. The message below carries the first twenty names.
    expect(count($missing))->toBe(0, sprintf(
        "PHPUnit's collector sees %d of the %d test files on disk, so %d test file(s) will not run and ".
        "nothing else would have said so.\nFirst %d missing:\n  %s\n".
        'This is the bind-mount truncation documented at the top of this file. Run those directories '.
        'explicitly, or read CI as the authority — do not loosen this assertion.',
        count($collected),
        count($onDisk),
        count($missing),
        count($report),
        implode("\n  ", $report),
    ));
});
