<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * Increment M76 — ONE RELIABLE ENUMERATION OF A DIRECTORY, because SPL's are not reliable here.
 *
 * ⛔ EVERY SPL DIRECTORY ITERATOR TRUNCATES SILENTLY OVER THIS PROJECT'S WINDOWS BIND MOUNT, AND THE
 * TRUNCATION IS PER-DIRECTORY RATHER THAN PER-FILE. Measured inside `dev_formbuilder_app-app-1`, same
 * directory and same process, on 2026-09-06:
 *
 *     tests/Feature/Forms        SPL 6    · scandir 46  · glob 46  · find 46  · Finder 46
 *     app                        SPL 719  ·                          find 814 · Finder 814
 *     database/migrations        SPL 86   · scandir 113 · glob 113 · find 113 · Finder 113
 *
 * `RecursiveDirectoryIterator`, `DirectoryIterator` and `FilesystemIterator` all return the SAME wrong
 * number, and **no flag combination changes it** — `SKIP_DOTS`, `CURRENT_AS_PATHNAME`, `FOLLOW_SYMLINKS`
 * and `KEY_AS_FILENAME` were each measured. It is deterministic rather than racy: five consecutive runs
 * over `tests/Feature/Forms` returned 6 every time. Whole directories disappear — `app/Enums` (63 files),
 * `app/Http/Controllers/Tenant` (52) and `app/Http/Controllers/Tenant/Sso` — which is why a sweep can lose
 * every tenant controller at once and still look like it swept `app/`.
 *
 * ⚠️ `docs/gate-baselines.md` and `CLAUDE.md` already record this for the `scripts/` lint gates and say to
 * run those on the host. What neither records is that it also reaches **Larastan** (which is the whole of
 * the 18 phantom `Access to an undefined property` errors a local container PHPStan run reports against
 * CI's zero) and **PHPUnit's own test-file collector**. It is not a `scripts/` problem; it is a
 * `php`-on-this-mount problem, and any code that enumerates a directory inherits it.
 *
 * ⚠️ CI IS NOT AFFECTED — its runner has no bind mount, so SPL agrees with `find` there. That is precisely
 * what makes this dangerous rather than merely annoying: the blindness exists ONLY where a human reads the
 * result, and never where the merge gate does.
 *
 * ⛔ THE DISAGREEMENT GUARD BELOW IS THE POINT, AND IT IS DELIBERATELY NOT A HAND-KEPT FLOOR. A magic
 * number ages into folklore and gets raised whenever it complains; `ClientUuidScopeTest` shipped one as
 * `toBeGreaterThan(200)` and it sat happily through the loss of 95 files including every tenant
 * controller. Two independent enumerators that must agree cannot rot that way, and the failure names the
 * delta instead of a threshold.
 */
final class SourceTree
{
    /**
     * Every file under $directory, as sorted absolute paths.
     *
     * @param  string|null  $extension  Restrict to this extension, or null for every file.
     * @return list<string>
     *
     * @throws RuntimeException when the two enumerators disagree, which means one of them has gone blind.
     */
    public static function filesUnder(string $directory, ?string $extension = 'php'): array
    {
        if (! is_dir($directory)) {
            throw new RuntimeException("SourceTree: not a directory: {$directory}");
        }

        $finder = self::viaFinder($directory, $extension);
        $walk = self::viaScandir($directory, $extension);

        if ($finder !== $walk) {
            $onlyFinder = array_diff($finder, $walk);
            $onlyWalk = array_diff($walk, $finder);

            throw new RuntimeException(sprintf(
                "SourceTree: two enumerators disagree about %s, so one of them is blind.\n".
                "  Finder saw %d, the scandir walk saw %d.\n".
                "  Only Finder: %s\n".
                "  Only walk  : %s\n".
                'This is the bind-mount truncation this class documents, arriving somewhere new.',
                $directory,
                count($finder),
                count($walk),
                implode(', ', array_slice($onlyFinder, 0, 10)) ?: '(none)',
                implode(', ', array_slice($onlyWalk, 0, 10)) ?: '(none)',
            ));
        }

        return $finder;
    }

    /**
     * The same list, with the repository root stripped, for readable assertion output.
     *
     * @return list<string>
     */
    public static function relativeFilesUnder(string $directory, string $root, ?string $extension = 'php'): array
    {
        $prefix = rtrim($root, '/\\').DIRECTORY_SEPARATOR;

        return array_values(array_map(
            static fn (string $path): string => str_replace($prefix, '', $path),
            self::filesUnder($directory, $extension),
        ));
    }

    /** @return list<string> */
    private static function viaFinder(string $directory, ?string $extension): array
    {
        // ⚠️ `ignoreDotFiles(false)` is not cosmetic. Finder skips dotfiles by default, which is a 2-file
        // disagreement with the walk over `tests/` — measured, and it would have read as a truncation.
        $finder = (new Finder)->files()->in($directory)->ignoreDotFiles(false)->ignoreVCS(false);

        if ($extension !== null) {
            $finder->name('*.'.$extension);
        }

        $paths = [];

        foreach ($finder as $file) {
            $paths[] = $file->getPathname();
        }

        sort($paths);

        return $paths;
    }

    /**
     * A recursive `scandir`, which reads a whole directory in one call rather than streaming it.
     *
     * That is the measured difference: the SPL iterators walk `readdir` incrementally and the mount
     * returns a short stream; `scandir`, `glob` and `find` each ask for everything at once and get it.
     *
     * @return list<string>
     */
    private static function viaScandir(string $directory, ?string $extension): array
    {
        $paths = [];
        $queue = [$directory];

        while ($queue !== []) {
            $current = array_pop($queue);
            $entries = scandir($current);

            if ($entries === false) {
                throw new RuntimeException("SourceTree: scandir failed on {$current}");
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $current.DIRECTORY_SEPARATOR.$entry;

                if (is_dir($path)) {
                    $queue[] = $path;

                    continue;
                }

                if ($extension !== null && pathinfo($path, PATHINFO_EXTENSION) !== $extension) {
                    continue;
                }

                $paths[] = $path;
            }
        }

        sort($paths);

        return $paths;
    }
}
