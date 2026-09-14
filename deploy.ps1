#Requires -Version 5.1
<#
.SYNOPSIS
  Meridian deploy for a self-hosted Windows Server site (ADR-0005; docs/deployment-infrastructure.md section 3).

.DESCRIPTION
  Invoked by the self-hosted GitHub Actions runner after CI passes on `main`
  (.github/workflows/deploy.yml), or run by hand from an elevated Windows PowerShell 5.1
  console on the server. In order:

    0. Refuse, before changing anything, when this site's queue worker service is not
       installed. The script never creates that service.
    1. git fetch, then git reset --hard to -Ref (default: origin/<Branch>).
    2. composer install --no-dev.
    3. npm ci, the design-system tokens, npm run build.
    4. The maintenance window: artisan down; migrate --force; config:cache, route:cache,
       view:cache and event:cache; queue:restart last. Then artisan up.
    5. Start the worker service if, and only if, it is Stopped.

  FAIL-FAST. Windows PowerShell 5.1 does not stop on a native command's non-zero exit
  code, even under $ErrorActionPreference = 'Stop', and a run that throws nothing exits
  with the code of the last native command. Every git, composer, npm and php call
  therefore goes through Invoke-Native, which throws on a non-zero exit, so any failed
  step ends the run red (the runner exits 1). Never add 2>&1 to a native call: 5.1 would
  turn the progress that git and npm write to stderr into terminating errors.

  A FAILURE IN STEPS 0-3 never reaches `down`, so the site stays up. After step 1, though,
  the checkout may already hold the new code on top of the previous (or a partly
  installed) vendor/ and public/build.

  A FAILURE INSIDE THE WINDOW LEAVES THE SITE DOWN, on purpose: bringing it up would serve
  the new code over a schema or caches that did not finish. Fix the cause and re-run, or
  roll back with
      deploy.ps1 -Ref <previous-good-sha>
  Roll back only through -Ref. Resetting the checkout by hand and re-running without it
  is undone by step 1, which resets to origin/<Branch> again.

  THE WORKER IS RESTARTED BY SIGNAL, NEVER BY RESTARTING ITS SERVICE. queue:restart makes
  the running worker exit 0 once its current job ends, and NSSM's default exit action
  (Restart) relaunches it on the new release. Stopping the service instead kills the job
  in flight: PHP on Windows has no pcntl, so the worker cannot trap a stop request.

  A CHANGED deploy.ps1 TAKES EFFECT ONE DEPLOY LATE. The runner starts the copy inside the
  live checkout, and PowerShell parses the whole file before running it, so the run whose
  step 1 brings in a new version still executes the old one; the next run uses the new.
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

if (-not $Ref) {
    $Ref = "origin/$Branch"
}

if (-not (Test-Path -LiteralPath $AppPath)) {
    throw "App path not found: $AppPath (set MERIDIAN_APP_PATH or pass -AppPath)."
}

# 0. Refuse before changing anything when this site has no worker service. Without one, queued
#    mail (verification, invitations, password resets) never sends, and nothing would say so.
#    The script never creates the service: docs/deployment-infrastructure.md section 8 installs it.
if (-not (Get-Service -Name $WorkerService -ErrorAction SilentlyContinue)) {
    throw "Queue worker service '$WorkerService' is not installed. Install it first (docs/deployment-infrastructure.md section 8, step 6), or pass -WorkerService or set MERIDIAN_WORKER_SERVICE to this site's service name."
}

Write-Host "==> Deploying $Ref into $AppPath (worker service '$WorkerService')"
Set-Location -LiteralPath $AppPath

# 1. Match the checkout to the target exactly (deterministic; discards any local drift).
Invoke-Native git @('fetch', '--all', '--prune')
Invoke-Native git @('reset', '--hard', $Ref)

# 2. Production PHP dependencies.
Invoke-Native composer @('install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--prefer-dist')

# 3. Front-end assets and design-system tokens. The site is still up during steps 1-3.
Invoke-Native npm @('ci')
Invoke-Native npm @('run', 'ds:install')
Invoke-Native npm @('run', 'ds:tokens')
Invoke-Native npm @('run', 'build')

# 4. The maintenance window. A worker started without --force pauses while the site is down,
#    and still reads the restart signal while paused.
Invoke-Native php @('artisan', 'down', '--retry=15')
$windowCompleted = $false
try {
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

# 5. A Stopped worker drains nothing, so start it. This runs after `up`, so it can never hold
#    the site down.
#    This is a Stopped-only guard, NOT a health check. NSSM reports a worker it is throttling
#    (one that keeps exiting soon after it starts, as in a crash loop) as Paused, not Stopped,
#    and this guard cannot see that; nor has the worker told to restart a moment ago
#    necessarily exited yet. A release whose worker dies at boot therefore passes here.
#    After a deploy, confirm with: nssm status <service>
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

$sha = Invoke-Native git @('rev-parse', '--short', 'HEAD')
Write-Host "==> Deploy complete at $sha (worker service '$WorkerService' is $((Get-Service -Name $WorkerService).Status))"
