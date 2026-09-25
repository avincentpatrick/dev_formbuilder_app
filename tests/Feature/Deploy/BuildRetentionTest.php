<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| deploy.ps1 keeps the previous release's chunks reachable (M109, R-e7d6f223).
|--------------------------------------------------------------------------
| A deploy renames `public\build` to `public\build.prev` and moves the staged build in, then step 8
| deletes `build.prev`. From the instant of that rename, every hashed chunk the previous release
| served is unreachable — and a tab that is ALREADY OPEN fetches chunks on demand: three shipped
| sites do it without any navigation (`FieldInput.vue`'s two `defineAsyncComponent` loaders and
| `GeoInput.vue`'s `import('leaflet')`). Inertia's asset-version check cannot rescue them, because it
| fires only on an Inertia GET, and the guest runtime is not Inertia at all.
|
| `Copy-RetainedBuildAssets` carries the orphans forward and ages them out. What THIS file can check
| is the shape, and there are three ways the shape could be wrong:
|
|   (1) THE STEP IS GONE, or never called. Then the defect is simply back, silently, and the only
|       symptom is a user's failed chunk fetch days later.
|   (2) THE ORDER IS WRONG. Carrying forward before the swap copies into a directory that is about to
|       be renamed away; carrying forward after `build.prev` is deleted has nothing left to read. The
|       step is only correct strictly between them, so the ORDER is the assertion, not the presence.
|   (3) THE WINDOW IS GONE. Without the age test the directory grows without bound, and "it kept
|       working" is indistinguishable from "it never pruned".
|
| ⚠️ THIS IS A STATIC READING AND IT PROVES NOTHING ABOUT BEHAVIOUR — the same caveat
| `DeploySkipAllowlistTest.php` states, and for the same reason: the script runs on Windows
| PowerShell 5.1 on the self-hosted box and this suite runs in a Linux container. The behaviour is
| proved by the PowerShell harness recorded in `docs/claims/lane-a.md` for this increment, which
| extracts this very function from `deploy.ps1` by AST and runs seven scenarios against a scratch
| tree — including the two that matter most, a name colliding with the new build (the new bytes must
| win) and a swap that produced no build at all (it must throw rather than pass).
*/

/** `deploy.ps1` as it ships, read once per case. */
function deployScript(): string
{
    $script = file_get_contents(base_path('deploy.ps1'));

    expect($script)->toBeString();

    return (string) $script;
}

it('carries the previous build forward, strictly between the swap and the tidy', function (): void {
    $script = deployScript();

    $swap = strpos($script, 'Move-DirectoryWithRetry -From $StageBuild -To $LiveBuild');
    $carry = strpos($script, 'Copy-RetainedBuildAssets -FromBuild $PrevBuild -ToBuild $LiveBuild');
    // The LAST one: an earlier, defensive `Remove-DirectoryIfPresent $PrevBuild` runs before the
    // window as well, and asserting against that one would pass on a script that never tidies at all.
    $tidy = strrpos($script, 'Remove-DirectoryIfPresent $PrevBuild');

    expect($swap)->not->toBeFalse('deploy.ps1 no longer moves the staged build into place.');
    expect($carry)->not->toBeFalse(
        'deploy.ps1 no longer carries the previous release\'s chunks forward, so a deploy makes every '
        .'hashed chunk an open tab might still fetch unreachable the instant public\build is renamed.'
    );
    expect($tidy)->not->toBeFalse('deploy.ps1 no longer removes public\build.prev.');

    expect($carry)->toBeGreaterThan((int) $swap,
        'the carry-forward runs BEFORE the staged build is moved in, so it copies into a directory '
        .'that is renamed away moments later and the retention is silently a no-op.');
    expect($carry)->toBeLessThan((int) $tidy,
        'the carry-forward runs AFTER public\build.prev is deleted, so there is nothing left to read '
        .'and the retention is silently a no-op.');
});

it('bounds what it retains, so the build directory cannot grow without end', function (): void {
    $script = deployScript();

    $matched = preg_match('/^\$BuildRetentionDays\s*=\s*(\d+)\s*$/mu', $script, $m);

    expect($matched)->toBe(1,
        '`$BuildRetentionDays` is no longer a single-line integer literal in deploy.ps1. It is the only '
        .'thing bounding how many previous releases accumulate in public\build, so it stays readable '
        .'from here: if the declaration has to change shape, change this reader in the same commit.');
    expect((int) $m[1])->toBeGreaterThan(0,
        'a retention window of zero days carries nothing forward, which is the defect this exists to close.');

    // ⚠️ `toContain()` takes NEEDLES, not a message — a failure message passed as its second argument
    // becomes a second string it looks for, and the case then fails for a reason that is not the one
    // it reports. Measured here on the first run. `str_contains` keeps the message a message.
    expect(str_contains($script, 'GetLastWriteTime'))->toBeTrue(
        'the retention no longer ages anything out by timestamp, so every release\'s chunks accumulate.');
});

it('is additive only — it never overwrites the new release or its manifest', function (): void {
    $script = deployScript();

    $start = strpos($script, 'function Copy-RetainedBuildAssets');
    expect($start)->not->toBeFalse('Copy-RetainedBuildAssets is no longer declared in deploy.ps1.');

    $body = substr($script, (int) $start, 2000);

    // Scoped to assets\: manifest.json and sw.js describe the release and must always be the new
    // build's own. A retention that carried the manifest forward would serve the OLD release.
    expect(str_contains($body, "Join-Path \$FromBuild 'assets'"))->toBeTrue(
        'the retention no longer reads the previous release\'s assets directory.');
    expect(str_contains($body, "Join-Path \$ToBuild 'assets'"))->toBeTrue(
        'the retention no longer writes into the new release\'s assets directory.');
    expect(str_contains($body, 'manifest.json'))->toBeFalse(
        'the retention names manifest.json, which would let a previous release describe the new one.');

    // The collision guard: a hashed name the new build also produced is never replaced by the old bytes.
    expect(str_contains($body, '[IO.File]::Exists($dest)'))->toBeTrue(
        'the retention no longer skips a file the new build already produced, so a stale chunk can '
        .'overwrite a fresh one whenever a content hash repeats.');
});
