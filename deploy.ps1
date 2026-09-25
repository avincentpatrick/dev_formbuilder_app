#Requires -Version 5.1
<#
.SYNOPSIS
  Meridian deploy for a self-hosted Windows Server site (ADR-0005; docs/deployment-infrastructure.md section 3).

.DESCRIPTION
  Invoked by the self-hosted GitHub Actions runner after CI passes on `main`
  (.github/workflows/deploy.yml), or run by hand from an elevated Windows PowerShell 5.1
  console on the server. It builds the release in a staging worktree while the site stays
  up, then swaps it into the live checkout inside a short maintenance window. In order:

    0. Refuse, before changing anything, when this site's queue worker service is not
       installed (the script never creates that service), or when <AppPath>\.deploy-stage
       exists but is not a git worktree of this repository.
    1. git fetch, then resolve -Ref (default: origin/<Branch>) to one commit, so the stage and
       the live checkout get the same commit even if the branch moves during the run.
    2. NOTHING TO DO: when the live checkout is already at that commit, the site is not in
       maintenance mode, and storage\framework\deployed-sha names that commit, open no window;
       only step 7 runs. A scheduled CI run that changed nothing lands here. The first run of
       this version finds no deployed-sha and deploys in full.
    2b. NOTHING THE SITE RUNS CHANGED: when what is live is exactly what is recorded as deployed,
       the site is up, the live build is present, and every path changed since is one the site
       does not load (docs/, tests/, scripts/, .github/ and four top-level markdown files), then
       fast-forward the checkout, record the commit and open NO window. Deny by default: one
       unrecognised path deploys in full. This is what keeps a documentation close-out from taking
       the site down to ship a markdown file.
    3. STAGE, with the site still up on the old release: git worktree prune; check the commit
       out into <AppPath>\.deploy-stage (added on the first run, reused after); copy the live
       .env into it, because the build reads VITE_APP_NAME and ASSET_URL; then, there,
       composer install --no-dev, npm ci, the design-system tokens and npm run build.
    4. RECOVER a swap an earlier run left half done: when live vendor\ or public\build is
       missing and its .prev copy exists, move the copy back, so artisan can boot. Then delete
       any stale .prev copy.
    5. THE WINDOW: artisan down --render=deploy-window, which prerenders the page served
       during the window (public\index.php echoes it without loading vendor\). Inside it:
       git reset --hard to the commit; move live vendor\ and public\build aside to .prev and
       the staged ones in; delete the bootstrap\cache files that describe the old vendor\ and
       routes; package:discover; migrate --force; config:cache, route:cache, view:cache and
       event:cache; queue:restart last.
    6. artisan up, then record the commit in storage\framework\deployed-sha.
    7. Start the worker service if, and only if, it is Stopped.
    8. Tidy, warnings only: move vendor.prev into the stage, so the next composer install is
       incremental, and delete public\build.prev and the stage's copy of .env.

  FAIL-FAST. Windows PowerShell 5.1 does not stop on a native command's non-zero exit
  code, even under $ErrorActionPreference = 'Stop', and a run that throws nothing exits
  with the code of the last native command. Every git, composer, npm and php call
  therefore goes through Invoke-Native, which throws on a non-zero exit, so any failed
  step ends the run red (the runner exits 1). Never add 2>&1 to a native call: 5.1 would
  turn the progress that git and npm write to stderr into terminating errors.

  A FAILURE IN STEPS 0-4 never reaches `down`, and the live checkout is untouched: the site
  stays up on the release it was already serving.

  A FAILURE INSIDE THE WINDOW LEAVES THE SITE DOWN, on purpose: bringing it up would serve
  the new code over a schema or caches that did not finish. A move that NTFS refuses because
  a process holds a file open inside the directory is retried for about ten seconds first.
  Fix the cause and re-run (step 4 repairs a half-done swap), or roll back with
      deploy.ps1 -Ref <previous-good-sha>
  which stages and swaps that commit like any other. Roll back only through -Ref. Resetting
  the checkout by hand and re-running without it is undone by step 1, which resolves
  origin/<Branch> again.

  THE WORKER IS RESTARTED BY SIGNAL, NEVER BY RESTARTING ITS SERVICE. queue:restart makes
  the running worker exit 0 once its current job ends, and NSSM's default exit action
  (Restart) relaunches it on the new release. Stopping the service instead kills the job
  in flight: PHP on Windows has no pcntl, so the worker cannot trap a stop request.

  A CHANGED deploy.ps1 TAKES EFFECT ONE DEPLOY LATE. The runner starts the copy inside the
  live checkout, and PowerShell parses the whole file before running it, so the run whose
  window brings in a new version still executes the old one; the next run uses the new.
  A priming run from a fresh clone is unaffected.

.PARAMETER AppPath
  The live checkout. Defaults to $env:MERIDIAN_APP_PATH, else C:\meridian\app.

.PARAMETER Branch
  The branch whose remote tip is deployed when -Ref is empty. Defaults to main.

.PARAMETER Ref
  What to deploy: a commit sha, tag or ref. Empty means origin/<Branch>.

.PARAMETER WorkerService
  The NSSM service that runs this site's `php artisan queue:work`. Each site on the box
  needs its own. Defaults to $env:MERIDIAN_WORKER_SERVICE, else meridian-worker.
#>
[CmdletBinding()]
param(
    [string]$AppPath = $(if ($env:MERIDIAN_APP_PATH) { $env:MERIDIAN_APP_PATH } else { 'C:\meridian\app' }),
    [string]$Branch = 'main',
    [string]$Ref = '',
    [string]$WorkerService = $(if ($env:MERIDIAN_WORKER_SERVICE) { $env:MERIDIAN_WORKER_SERVICE } else { 'meridian-worker' })
)

$ErrorActionPreference = 'Stop'

# Runs one native command and throws when it exits non-zero (see FAIL-FAST above). Its
# standard output passes through, so `$x = Invoke-Native ...` captures it.
function Invoke-Native {
    param(
        [Parameter(Mandatory = $true)][string]$File,
        [string[]]$Arguments = @()
    )
    $line = (@($File) + $Arguments) -join ' '
    Write-Host "--> $line"
    & $File @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Deploy step failed: '$line' exited with code $LASTEXITCODE."
    }
}

# Runs git through Invoke-Native and returns its first line of output, trimmed. The output is
# collected whole: piping it into Select-Object -First would stop Invoke-Native before its
# exit-code check.
function Get-GitLine {
    param([string[]]$Arguments)
    $output = @(Invoke-Native git $Arguments)
    if ($output.Count -eq 0) {
        return ''
    }
    return ([string]$output[0]).Trim()
}

# An absolute path with no trailing separator, for comparing two spellings of one directory.
function Get-NormalizedPath([string]$Path) {
    return [IO.Path]::GetFullPath($Path).TrimEnd([char[]]@('\', '/'))
}

# NTFS refuses to rename a directory while any process holds a file open inside it (measured:
# a .NET handle even with FileShare ReadWrite|Delete, a PHP fopen(), a process whose working
# directory is inside it). Such holds are usually brief, so a refused move is retried for about
# ten seconds before it fails the deploy. Pass absolute paths only: Set-Location does not move
# .NET's current directory, so a relative path would resolve against wherever the runner
# started (measured).
$MoveAttempts = 40
$MoveRetryMilliseconds = 250

# A deploy makes the PREVIOUS release's hashed chunks unreachable the instant public\build is renamed
# away, and step 8 then deletes build.prev outright. An already-open tab that fetches a chunk ON DEMAND
# — a lazily loaded component, not a navigation — asks for a file that no longer exists. Inertia's
# asset-version check cannot save it (it fires only on an Inertia GET, and the guest runtime is not
# Inertia at all), so the previous release's orphaned assets are carried forward into the new build and
# aged out instead. Additive and collision-free: the filenames are content hashes. The window bounds the
# accumulation, and only assets\ is carried — manifest.json and sw.js are always the new build's alone.
$BuildRetentionDays = 7

function Move-DirectoryWithRetry {
    param(
        [Parameter(Mandatory = $true)][string]$From,
        [Parameter(Mandatory = $true)][string]$To
    )
    Write-Host "--> move $From -> $To"
    if (-not [IO.Directory]::Exists($From)) {
        throw "Deploy step failed: cannot move '$From', which does not exist."
    }
    if ([IO.Directory]::Exists($To) -or [IO.File]::Exists($To)) {
        throw "Deploy step failed: cannot move '$From' to '$To', which already exists."
    }
    $lastError = ''
    for ($attempt = 1; $attempt -le $MoveAttempts; $attempt++) {
        try {
            [IO.Directory]::Move($From, $To)
            return
        }
        catch [System.IO.DirectoryNotFoundException] {
            throw
        }
        catch {
            $lastError = $_.Exception.Message
            Write-Host "    move refused (attempt $attempt of $MoveAttempts): $lastError"
            if ($attempt -lt $MoveAttempts) {
                Start-Sleep -Milliseconds $MoveRetryMilliseconds
            }
        }
    }
    throw "Deploy step failed: could not move '$From' to '$To' in $MoveAttempts attempts; a process still holds a file open inside '$From' (last error: $lastError). Find and stop it (Sysinternals handle.exe names it), then re-run."
}

function Remove-DirectoryIfPresent {
    param([Parameter(Mandatory = $true)][string]$Path)
    if ([IO.Directory]::Exists($Path)) {
        Write-Host "--> remove $Path"
        [IO.Directory]::Delete($Path, $true)
    }
}

function Remove-FileIfPresent {
    param([Parameter(Mandatory = $true)][string]$Path)
    if ([IO.File]::Exists($Path)) {
        Write-Host "--> remove $Path"
        [IO.File]::Delete($Path)
    }
}

# Carry the previous release's orphaned hashed chunks into the new build, so a tab opened before the
# swap can still fetch one. Additive only: a hashed name that exists in the new build is never
# overwritten, and manifest.json and sw.js are not touched at all, so the new release describes itself.
# Copy-Item preserves LastWriteTime, which is what lets a carried file age out on a later deploy
# instead of living forever.
#
# ⛔ THIS RUNS INSIDE THE WINDOW, DELIBERATELY. Doing it after `up` would leave a gap in which the new
# build is live and the old chunks are already unreachable, which is the exact defect being closed.
function Copy-RetainedBuildAssets {
    param(
        [Parameter(Mandatory = $true)][string]$FromBuild,
        [Parameter(Mandatory = $true)][string]$ToBuild,
        [Parameter(Mandatory = $true)][int]$RetentionDays
    )
    $fromAssets = Join-Path $FromBuild 'assets'
    $toAssets = Join-Path $ToBuild 'assets'
    if (-not [IO.Directory]::Exists($fromAssets)) {
        Write-Host "--> retain: no previous assets directory at $fromAssets, nothing to carry forward"
        return
    }
    if (-not [IO.Directory]::Exists($toAssets)) {
        throw "Deploy step failed: the new build has no assets directory at '$toAssets', so the swap did not produce a usable build."
    }
    $cutoff = (Get-Date).AddDays(-$RetentionDays)
    $carried = 0
    $aged = 0
    foreach ($file in [IO.Directory]::GetFiles($fromAssets)) {
        $dest = Join-Path $toAssets ([IO.Path]::GetFileName($file))
        if ([IO.File]::Exists($dest)) {
            continue
        }
        if ([IO.File]::GetLastWriteTime($file) -lt $cutoff) {
            $aged++
            continue
        }
        Copy-Item -LiteralPath $file -Destination $dest -Force
        $carried++
    }
    Write-Host "--> retain: carried $carried chunk(s) forward from the previous release, aged out $aged"
}

# 7. A Stopped worker drains nothing, so start it. Every caller runs this after `up`, so it can
#    never hold the site down.
#    This is a Stopped-only guard, NOT a health check. NSSM reports a worker it is throttling
#    (one that keeps exiting soon after it starts, as in a crash loop) as Paused, not Stopped,
#    and this guard cannot see that; nor has the worker told to restart a moment ago
#    necessarily exited yet. A release whose worker dies at boot therefore passes here.
#    After a deploy, confirm with: nssm status <service>
function Start-WorkerIfStopped {
    $worker = Get-Service -Name $WorkerService
    if ($worker.Status -eq 'Stopped') {
        Write-Host "--> Start-Service $WorkerService (it was Stopped)"
        try {
            Start-Service -Name $WorkerService
        }
        catch {
            throw "The release is live, but queue worker service '$WorkerService' is Stopped and could not be started: $($_.Exception.Message) No queued job runs until it starts. Start it from an elevated console (Start-Service $WorkerService), or give the runner's service account the right to start it."
        }
    }
}

if (-not $Ref) {
    $Ref = "origin/$Branch"
}

if (-not (Test-Path -LiteralPath $AppPath)) {
    throw "App path not found: $AppPath (set MERIDIAN_APP_PATH or pass -AppPath)."
}
# Absolute from here on: the .NET calls below need it (see Move-DirectoryWithRetry).
$AppPath = (Resolve-Path -LiteralPath $AppPath).ProviderPath

$StagePath = Join-Path $AppPath '.deploy-stage'
$LiveVendor = Join-Path $AppPath 'vendor'
$PrevVendor = Join-Path $AppPath 'vendor.prev'
$StageVendor = Join-Path $StagePath 'vendor'
$LiveBuild = Join-Path $AppPath 'public\build'
$PrevBuild = Join-Path $AppPath 'public\build.prev'
$StageBuild = Join-Path $StagePath 'public\build'
$LiveEnv = Join-Path $AppPath '.env'
$StageEnv = Join-Path $StagePath '.env'
$DownFile = Join-Path $AppPath 'storage\framework\down'
$DeployedShaFile = Join-Path $AppPath 'storage\framework\deployed-sha'
$BootstrapCache = Join-Path $AppPath 'bootstrap\cache'

# 0. Refuse before changing anything when this site has no worker service. Without one, queued
#    mail (verification, invitations, password resets) never sends, and nothing would say so.
#    The script never creates the service: docs/deployment-infrastructure.md section 8 installs it.
if (-not (Get-Service -Name $WorkerService -ErrorAction SilentlyContinue)) {
    throw "Queue worker service '$WorkerService' is not installed. Install it first (docs/deployment-infrastructure.md section 8, step 6), or pass -WorkerService or set MERIDIAN_WORKER_SERVICE to this site's service name."
}

#    And refuse when .deploy-stage is anything but this script's own worktree. The toplevel check
#    is load-bearing: a plain directory inside the live checkout reports the LIVE repository's
#    common dir, and checking a commit out "there" would move the live checkout instead.
if (Test-Path -LiteralPath $StagePath) {
    $isOwnWorktree = $false
    try {
        $stageTop = Get-GitLine @('-C', $StagePath, 'rev-parse', '--path-format=absolute', '--show-toplevel')
        $stageCommon = Get-GitLine @('-C', $StagePath, 'rev-parse', '--path-format=absolute', '--git-common-dir')
        $liveCommon = Get-GitLine @('-C', $AppPath, 'rev-parse', '--path-format=absolute', '--git-common-dir')
        $isOwnWorktree = ((Get-NormalizedPath $stageTop) -ieq (Get-NormalizedPath $StagePath)) -and ((Get-NormalizedPath $stageCommon) -ieq (Get-NormalizedPath $liveCommon))
    }
    catch {
        $isOwnWorktree = $false
    }
    if (-not $isOwnWorktree) {
        throw "Refusing to deploy: '$StagePath' exists but is not a git worktree of the repository at '$AppPath'. This script builds each release there. Move that directory out of the way and re-run."
    }
}

Write-Host "==> Deploying $Ref into $AppPath (worker service '$WorkerService')"
Set-Location -LiteralPath $AppPath

# 1. Fetch, then pin the target to one commit. rev-list peels a tag to its commit, and
#    --end-of-options keeps a -Ref that starts with '-' from being read as an option.
Invoke-Native git @('-C', $AppPath, 'fetch', '--all', '--prune')
$sha = Get-GitLine @('-C', $AppPath, 'rev-list', '--max-count=1', '--end-of-options', $Ref)
if ($sha -notmatch '^([0-9a-f]{40}|[0-9a-f]{64})$') {
    throw "Deploy step failed: '$Ref' does not name a commit (git answered '$sha')."
}

# 2. Nothing to deploy when that commit is live, its deploy finished, and the site is up.
$liveHead = Get-GitLine @('-C', $AppPath, 'rev-parse', 'HEAD')
$deployedSha = ''
if ([IO.File]::Exists($DeployedShaFile)) {
    $deployedSha = ([IO.File]::ReadAllText($DeployedShaFile)).Trim()
}
if (($liveHead -eq $sha) -and (-not [IO.File]::Exists($DownFile)) -and ($deployedSha -eq $sha)) {
    Write-Host "==> $sha is already live and the site is up: nothing to deploy, and no maintenance window."
    Start-WorkerIfStopped
    Write-Host "==> Nothing deployed (worker service '$WorkerService' is $((Get-Service -Name $WorkerService).Status))"
    return
}

# 2b. NOTHING THE SITE RUNS CHANGED: fast-forward the checkout and open no maintenance window.
#     WHY THIS EXISTS. Every green CI run on `main` reaches deploy.yml, and a close-out push is a
#     REAL push: `docs/pipeline.md` is regenerated by every one of them and is deliberately not in
#     `ci.yml`'s paths-ignore, because `pipeline-lint` has to see it. So before this arm, a
#     documentation-only close-out took the testing site down, migrated it and rebuilt its caches to
#     ship a changed markdown file. That is not what a maintenance window is for.
#
#     ⛔ THE COMPARISON IS AGAINST THE RECORDED deployed-sha, NEVER AGAINST THE PUSH'S OWN DIFF.
#     A close-out that follows a merge whose deploy failed before its window would otherwise skip,
#     and that merge's code would never go live. Every guard below is part of that: the live
#     checkout must be exactly what is recorded as deployed, the site must be up, and the live
#     build must actually be present — otherwise there is nothing safe to fast-forward FROM.
#
#     ⛔ DENY BY DEFAULT. A path is site-safe only if it is named here; anything unrecognised opens
#     the window. `.gitattributes`, `composer.lock`, `resources/`, `public/`, `config/`, `routes/`
#     and the rest are all "unrecognised" on purpose — a wrong skip serves stale code with a fresh
#     database, which is far worse than a window nobody needed. `tests/` and `scripts/` are safe
#     because nothing the site serves loads either: every mention of `scripts/` under `app/`,
#     `database/` and `resources/views/` is a comment about a lint gate.
#
#     ⚠️ `--no-renames`, AND `diff-tree` RATHER THAN `diff`. Porcelain `git diff --name-only` prints
#     only a rename's NEW path, so moving `app/Foo.php` to `docs/Foo.php` would read as
#     documentation-only (measured on this repository). `--no-commit-id` keeps the sha line out.
#
#     ⚠️ `__APP_VERSION__` stays at the last BUILT commit after a skip: vite.config.ts stamps it from
#     the stage's HEAD, and `submissions.app_version` records it. That is the one thing a skip leaves
#     stale, and it is preferred to taking the site down to restamp a string.
$SiteSafePrefixes = @('docs/', 'tests/', 'scripts/', '.github/')
$SiteSafeFiles = @('PROGRESS.md', 'PROGRESS_ARCHIVE.md', 'CLAUDE.md', 'README.md')

if (($deployedSha -match '^([0-9a-f]{40}|[0-9a-f]{64})$') -and
    ($liveHead -eq $deployedSha) -and
    (-not [IO.File]::Exists($DownFile)) -and
    (Test-Path -LiteralPath $LiveVendor) -and
    (Test-Path -LiteralPath (Join-Path $LiveBuild 'manifest.json'))) {

    # A failure here must never block a deploy, so it falls through to the full path rather than
    # throwing: an unreadable diff is a reason to deploy properly, not a reason to stop.
    $changedPaths = $null
    try {
        $changedPaths = @(Invoke-Native git @(
                '-C', $AppPath, 'diff-tree', '-r', '--name-only', '--no-renames', '--no-commit-id',
                '--end-of-options', $deployedSha, $sha
            )) | Where-Object { $_ -and ([string]$_).Trim() -ne '' }
    }
    catch {
        Write-Warning "Could not compare $deployedSha with ${sha}: $($_.Exception.Message) Deploying in full."
        $changedPaths = $null
    }

    if ($null -ne $changedPaths) {
        $unsafe = @($changedPaths | Where-Object {
                $path = ([string]$_).Trim()
                # core.quotePath wraps a non-ASCII path in quotes and escapes its bytes. Such a path
                # is deliberately treated as unsafe rather than unquoted here.
                $safe = ($SiteSafeFiles -ccontains $path)
                if (-not $safe) {
                    foreach ($prefix in $SiteSafePrefixes) {
                        if ($path.StartsWith($prefix, [StringComparison]::Ordinal)) {
                            $safe = $true
                            break
                        }
                    }
                }
                -not $safe
            })

        if (@($changedPaths).Count -gt 0 -and @($unsafe).Count -eq 0) {
            Write-Host "==> Only paths the site does not run changed between $deployedSha and $sha ($(@($changedPaths).Count) file(s)): no maintenance window."
            Invoke-Native git @('-C', $AppPath, 'reset', '--hard', $sha)
            try {
                [IO.File]::WriteAllText($DeployedShaFile, $sha)
            }
            catch {
                Write-Warning "The checkout is at $sha, but '$DeployedShaFile' could not be written: $($_.Exception.Message) The next run deploys this commit in full."
            }
            Start-WorkerIfStopped
            Write-Host "==> Fast-forwarded to $sha with no window (worker service '$WorkerService' is $((Get-Service -Name $WorkerService).Status))"
            return
        }
    }
}

# 3. Build the release in the stage while the site stays up on the old one. Prune first: a stage
#    directory deleted by hand stays registered, and `worktree add` refuses a registered path.
Invoke-Native git @('-C', $AppPath, 'worktree', 'prune')
if (Test-Path -LiteralPath $StagePath) {
    Invoke-Native git @('-C', $StagePath, 'checkout', '--detach', '--force', $sha)
}
else {
    Invoke-Native git @('-C', $AppPath, 'worktree', 'add', '--detach', $StagePath, $sha)
}
if ([IO.File]::Exists($LiveEnv)) {
    Write-Host "--> copy $LiveEnv -> $StageEnv"
    [IO.File]::Copy($LiveEnv, $StageEnv, $true)
}

Set-Location -LiteralPath $StagePath
Invoke-Native composer @('install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--prefer-dist')
Invoke-Native npm @('ci')
Invoke-Native npm @('run', 'ds:install')
Invoke-Native npm @('run', 'ds:tokens')
Invoke-Native npm @('run', 'build')
Set-Location -LiteralPath $AppPath

foreach ($built in @($StageVendor, (Join-Path $StageBuild 'manifest.json'))) {
    if (-not (Test-Path -LiteralPath $built)) {
        throw "Deploy step failed: the staged build is incomplete, because '$built' is missing."
    }
}

# 4. Repair a swap an earlier window left half done, so artisan can boot and the old release is
#    whole again. Then clear stale copies, each only while its live counterpart exists.
if ((-not [IO.Directory]::Exists($LiveVendor)) -and [IO.Directory]::Exists($PrevVendor)) {
    Move-DirectoryWithRetry -From $PrevVendor -To $LiveVendor
}
if ((-not [IO.Directory]::Exists($LiveBuild)) -and [IO.Directory]::Exists($PrevBuild)) {
    Move-DirectoryWithRetry -From $PrevBuild -To $LiveBuild
}
if ([IO.Directory]::Exists($LiveVendor)) {
    Remove-DirectoryIfPresent $PrevVendor
}
if ([IO.Directory]::Exists($LiveBuild)) {
    Remove-DirectoryIfPresent $PrevBuild
}

# 5. The maintenance window. --render prerenders resources/views/deploy-window.blade.php into
#    storage\framework\down, and public\index.php serves that page to a browser without loading
#    vendor\, which the moves below replace. A worker started without --force pauses while the
#    site is down, and still reads the restart signal while paused.
#
#    ⛔ A BROWSER IS NOT THE WHOLE OF IT, AND THE SENTENCE ABOVE USED TO BE THE WHOLE COMMENT (M99,
#    R-4ff3e848). The framework's stub returns early for anything expecting JSON, so the builder's
#    autosave and the guest-form runtime fell THROUGH it and booted the framework while the lines
#    below reset the checkout hard, renamed vendor\ and public\build, and ran migrate --force. That
#    is answered above the autoloader now by public/maintenance-guard.php. Note what the window
#    really contains: the reset comes FIRST, so a fall-through could boot against half-rewritten
#    app\ PHP, and migrate --force runs inside it too, so a clean boot could read a half-migrated
#    schema. Neither is a browser's problem and neither was written down before.
Invoke-Native php @('artisan', 'down', '--retry=15', '--render=deploy-window')
$windowCompleted = $false
try {
    Invoke-Native git @('-C', $AppPath, 'reset', '--hard', $sha)

    if ([IO.Directory]::Exists($LiveVendor)) {
        Move-DirectoryWithRetry -From $LiveVendor -To $PrevVendor
    }
    Move-DirectoryWithRetry -From $StageVendor -To $LiveVendor
    if ([IO.Directory]::Exists($LiveBuild)) {
        Move-DirectoryWithRetry -From $LiveBuild -To $PrevBuild
    }
    Move-DirectoryWithRetry -From $StageBuild -To $LiveBuild
    if ([IO.Directory]::Exists($PrevBuild)) {
        Copy-RetainedBuildAssets -FromBuild $PrevBuild -ToBuild $LiveBuild -RetentionDays $BuildRetentionDays
    }

    # The old release's package, config, route and event caches describe classes and routes that
    # the new vendor\ and code may not have. package:discover rebuilds the package manifest from
    # the new vendor\ before anything else boots against it.
    foreach ($name in @('config.php', 'services.php', 'packages.php', 'events.php')) {
        Remove-FileIfPresent (Join-Path $BootstrapCache $name)
    }
    foreach ($routeCache in @(Get-ChildItem -LiteralPath $BootstrapCache -Filter 'routes-v*.php' -File -ErrorAction SilentlyContinue)) {
        Remove-FileIfPresent $routeCache.FullName
    }
    Invoke-Native php @('artisan', 'package:discover')

    Invoke-Native php @('artisan', 'migrate', '--force')
    Invoke-Native php @('artisan', 'config:cache')
    Invoke-Native php @('artisan', 'route:cache')
    Invoke-Native php @('artisan', 'view:cache')
    Invoke-Native php @('artisan', 'event:cache')

    # Graceful worker restart onto the new release (see .DESCRIPTION). LAST, so a failure in any
    # step above leaves the running worker on the old code, which matches the old schema.
    Invoke-Native php @('artisan', 'queue:restart')
    $windowCompleted = $true
}
finally {
    if (-not $windowCompleted) {
        Write-Warning 'Deploy failed inside the maintenance window. The site has been LEFT DOWN on purpose. Fix the cause and re-run, or roll back with: deploy.ps1 -Ref <previous-good-sha>'
    }
}

# Reached only when the window completed: a failure inside it has already ended the script,
# with its original error, once the warning above was written.
Invoke-Native php @('artisan', 'up')

# 6. Record what is live, for step 2 on the next run. A failure here costs only that shortcut,
#    so it warns rather than turning a live release red.
try {
    [IO.File]::WriteAllText($DeployedShaFile, $sha)
}
catch {
    Write-Warning "The release is live, but '$DeployedShaFile' could not be written: $($_.Exception.Message) The next run deploys this commit again in full."
}

Start-WorkerIfStopped

# 8. Tidy. The release is live whatever happens here, so each step only warns.
try {
    if ([IO.Directory]::Exists($PrevVendor) -and (-not [IO.Directory]::Exists($StageVendor))) {
        Move-DirectoryWithRetry -From $PrevVendor -To $StageVendor
    }
}
catch {
    Write-Warning "Could not move '$PrevVendor' into the stage: $($_.Exception.Message) The next run deletes it and installs the stage's vendor\ from scratch."
}
try {
    Remove-DirectoryIfPresent $PrevBuild
}
catch {
    Write-Warning "Could not delete '$PrevBuild': $($_.Exception.Message) The next run deletes it before its window."
}
try {
    Remove-FileIfPresent $StageEnv
}
catch {
    Write-Warning "Could not delete the stage's copy of .env at '$StageEnv': $($_.Exception.Message) Delete it by hand."
}

$short = Get-GitLine @('-C', $AppPath, 'rev-parse', '--short', 'HEAD')
Write-Host "==> Deploy complete at $short (worker service '$WorkerService' is $((Get-Service -Name $WorkerService).Status))"
