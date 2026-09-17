<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| deploy.ps1's site-safe allowlist, read from the script itself (M98).
|--------------------------------------------------------------------------
| `deploy.ps1` step 2b skips the maintenance window when every path changed since the deployed commit
| is one the site does not load. That list is the whole safety argument, and it is a PowerShell array
| in a file no gate here can execute: the script runs on Windows PowerShell 5.1 on the self-hosted
| box, and the suite runs in a Linux container. What CAN be checked from here is the list's SHAPE,
| and these are the two ways it could be wrong:
|
|   (1) TOO NARROW is a nuisance, not a defect — a window nobody needed. It is still worth pinning:
|       the whole point of the arm is that a close-out push, which regenerates `docs/pipeline.md`,
|       stops taking the site down. So every path `ci.yml` skips on push, plus `docs/pipeline.md`
|       itself, must be inside the allowlist. Otherwise the arm cannot do the one job it exists for.
|
|   (2) TOO WIDE serves stale code against a migrated database, which is the failure this file is
|       really guarding. So no prefix that the running site loads may appear in it — and the list of
|       those is stated here rather than inferred, because "which directories does the site load"
|       is a question about the application, not about the script.
|
| ⚠️ THIS IS A STATIC READING AND IT PROVES NOTHING ABOUT BEHAVIOUR. The behaviour is proved by the
| PowerShell harness recorded in `docs/claims/lane-a.md` for this increment: eleven scenarios,
| including the same documentation-only push against the trunk script, which opens a window.
*/

use Illuminate\Support\Str;

/** The allowlist as `deploy.ps1` declares it: @('docs/', …) and @('PROGRESS.md', …). */
function deployAllowlist(string $variable): array
{
    $script = file_get_contents(base_path('deploy.ps1'));

    expect($script)->toBeString();

    $matched = preg_match('/^\$'.preg_quote($variable, '/').'\s*=\s*@\(([^)]*)\)/mu', (string) $script, $m);

    expect($matched)->toBe(1, sprintf(
        '`$%s` is no longer a single-line PowerShell array literal in deploy.ps1. It is the safety '
        .'argument for skipping the maintenance window, so it stays readable from here: if the '
        .'declaration has to change shape, change this reader in the same commit.',
        $variable,
    ));

    preg_match_all("/'([^']*)'/", $m[1], $entries);

    return $entries[1];
}

it('covers every path a close-out push touches, or the arm cannot do its job', function (): void {
    $allowed = array_merge(deployAllowlist('SiteSafePrefixes'), deployAllowlist('SiteSafeFiles'));

    // What `ci.yml` skips on push, plus the one close-out path it deliberately does NOT skip —
    // `docs/pipeline.md` is outside `paths-ignore` because `pipeline-lint` has to see it, which is
    // exactly why a close-out reaches the deploy at all.
    $ciWorkflow = file_get_contents(base_path('.github/workflows/ci.yml'));
    preg_match('/paths-ignore:\n((?:\s*-\s*\'[^\']+\'\n)+)/u', (string) $ciWorkflow, $block);

    expect($block)->toHaveCount(2, 'ci.yml no longer declares a `paths-ignore` block of quoted paths.');

    preg_match_all("/-\s*'([^']+)'/", $block[1], $ignored);

    $mustBeCovered = array_merge($ignored[1], ['docs/pipeline.md']);

    foreach ($mustBeCovered as $path) {
        $trimmed = rtrim(str_replace('/**', '/', $path), '*');

        $covered = in_array($trimmed, $allowed, true) || collect($allowed)
            ->contains(fn (string $entry): bool => str_ends_with($entry, '/') && Str::startsWith($trimmed, $entry));

        expect($covered)->toBeTrue(sprintf(
            '`%s` is skipped by ci.yml on push (or is the close-out path that is not), but deploy.ps1 '
            .'would still open a maintenance window for it. The allowlist is: %s.',
            $path,
            implode(', ', $allowed),
        ));
    }
});

it('never allows a path the running site loads', function (): void {
    $allowed = array_merge(deployAllowlist('SiteSafePrefixes'), deployAllowlist('SiteSafeFiles'));

    // Stated, not inferred. Each of these is either PHP the site executes, an asset the build
    // produces, a dependency manifest, or a file whose change alters what `artisan` does — so a
    // skip that included one would leave the live release serving something other than the commit
    // recorded as deployed.
    $siteLoads = [
        'app/', 'bootstrap/', 'config/', 'database/', 'lang/', 'public/', 'resources/', 'routes/',
        'storage/', 'packages/', 'vendor/', 'artisan', 'composer.json', 'composer.lock',
        'package.json', 'package-lock.json', 'vite.config.ts', 'deploy.ps1', '.env.example',
        'openapi.json', 'phpunit.xml',
    ];

    foreach ($siteLoads as $path) {
        foreach ($allowed as $entry) {
            $clashes = $entry === $path
                || (str_ends_with($entry, '/') && Str::startsWith($path, $entry))
                || (str_ends_with($path, '/') && Str::startsWith($entry, $path));

            expect($clashes)->toBeFalse(sprintf(
                'deploy.ps1 would skip its maintenance window for `%s`, which the running site loads '
                .'(allowlist entry `%s`). A skip leaves the live release at a commit it never built: '
                .'stale code against a migrated database.',
                $path,
                $entry,
            ));
        }
    }
});
