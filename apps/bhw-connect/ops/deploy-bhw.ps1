#Requires -Version 5.1
<#
.SYNOPSIS
  Deploys BHW Connect on the testing box - only BHW, never Meridian (Meridian's ADR-0024).

.DESCRIPTION
  Runs after deploy.ps1 in the same Deploy job (from bhw-p1-box on; NOT wired in yet), against the
  same live checkout, which deploy.ps1 has already moved to the commit being deployed. It never
  touches Meridian: no maintenance window, no artisan, no Meridian database. In order:

    1. DECIDE. Compare BHW's OWN deployed sha (<BhwRoot>\deployed-sha) with the checkout's HEAD,
       over apps/bhw-connect only. Nothing changed there, or only what the server never loads
       (docs/, ingestion/, README.md, CLAUDE.md): record the sha and stop. A Meridian-only push
       therefore never restarts BHW, and a BHW change that rode a failed run is picked up by the
       next one, because the comparison is against what BHW last deployed, never the push's diff.
       Deny by default: no recorded sha, an unknown one, or a git error deploys in full.
    2. BUILD ASIDE. In the checkout's apps\bhw-connect (nothing runs from there): copy
       <BhwRoot>\.env to .env.production, npm ci, npm run build, delete .env.production, and
       assemble <BhwRoot>\releases\<sha> from .next\standalone, .next\static and public\.
       A failure here changes nothing live.
    3. MIGRATE, only when <BhwRoot>\.env names BHW_DATABASE_URL (from the database move on;
       until then the data is on Supabase): db\migrate.mjs --check; when anything is pending, a
       row-count census, a pg_dump of bhw_connect, the migration, and a second census - and a
       table that lost rows stops the deploy before the swap (BHW's additive rule, measured).
    4. SWAP. Stop the service, point the <BhwRoot>\current junction at the new release, start the
       service, and poll the health URL for HTTP 200 for up to 60 seconds. BHW is down for
       seconds; Meridian is not touched.
    5. On failure: point the junction back at the release it left, start, exit non-zero (the run
       goes red; Meridian stays up). On success: record the sha, keep the newest releases.

  -Rollback points the junction at the previous release and restarts: no git, no build.
  -BuildOnly does steps 2 only (the first install, before the service exists).
  -PlanOnly prints step 1's decision and changes nothing.

  Plain ASCII on purpose: Windows PowerShell 5.1 reads a file without a BOM as ANSI.
#>
param(
    [Parameter(Mandatory = $true)][string]$AppPath,
    [string]$BhwRoot = 'C:\meridian\bhw',
    [string]$ServiceName = 'bhw-connect',
    [string]$HealthUrl = 'http://127.0.0.1:3100/bhw-connect',
    [int]$KeepReleases = 3,
    [switch]$Rollback,
    [switch]$BuildOnly,
    [switch]$PlanOnly
)

$ErrorActionPreference = 'Stop'

# Paths under apps/bhw-connect that the running server never loads: a change only there needs no
# release. Everything else deploys.
$NotServedPrefixes = @('apps/bhw-connect/docs/', 'apps/bhw-connect/ingestion/')
$NotServedFiles = @('apps/bhw-connect/README.md', 'apps/bhw-connect/CLAUDE.md')

$AppDir = Join-Path $AppPath 'apps\bhw-connect'
$ReleasesDir = Join-Path $BhwRoot 'releases'
$CurrentLink = Join-Path $BhwRoot 'current'
$DeployedShaFile = Join-Path $BhwRoot 'deployed-sha'
$EnvFile = Join-Path $BhwRoot '.env'
$BackupDir = 'C:\meridian\backups\bhw'

function Write-Step([string]$Message) {
    Write-Host ("[{0}] {1}" -f (Get-Date -Format 's'), $Message)
}

# Runs one native command and throws when it exits non-zero; its output passes through.
function Invoke-Native {
    param(
        [Parameter(Mandatory = $true)][string]$File,
        [string[]]$Arguments = @()
    )
    Write-Host ("--> {0} {1}" -f $File, ($Arguments -join ' '))
    & $File @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw ("BHW deploy step failed: '{0} {1}' exited with code {2}." -f $File, ($Arguments -join ' '), $LASTEXITCODE)
    }
}

# KEY=VALUE lines of <BhwRoot>\.env, comments and blanks skipped, surrounding quotes removed.
function Read-EnvFile([string]$Path) {
    $values = @{}
    if (-not (Test-Path -LiteralPath $Path)) {
        return $values
    }
    foreach ($line in Get-Content -LiteralPath $Path) {
        $trimmed = $line.Trim()
        if ($trimmed -eq '' -or $trimmed.StartsWith('#') -or -not $trimmed.Contains('=')) {
            continue
        }
        $name, $value = $trimmed.Split('=', 2)
        $values[$name.Trim()] = $value.Trim().Trim('"').Trim("'")
    }
    return $values
}

function Test-NotServed([string]$Path) {
    if ($NotServedFiles -ccontains $Path) {
        return $true
    }
    foreach ($prefix in $NotServedPrefixes) {
        if ($Path.StartsWith($prefix, [StringComparison]::Ordinal)) {
            return $true
        }
    }
    return $false
}

# Step 1. Returns @{ Action = 'deploy' | 'skip'; Reason; Sha }.
function Get-DeployPlan {
    $sha = (& git -C $AppPath rev-parse HEAD).Trim()
    if ($LASTEXITCODE -ne 0 -or $sha -notmatch '^[0-9a-f]{40}$') {
        throw "Cannot read the checkout's HEAD in $AppPath."
    }
    if (-not (Test-Path -LiteralPath $DeployedShaFile)) {
        return @{ Action = 'deploy'; Reason = 'no deployed-sha recorded: first deploy'; Sha = $sha }
    }
    $deployed = (Get-Content -LiteralPath $DeployedShaFile -Raw).Trim()
    if ($deployed -eq $sha) {
        return @{ Action = 'skip'; Reason = 'this commit is already deployed'; Sha = $sha }
    }
    if ($deployed -notmatch '^[0-9a-f]{40}$') {
        return @{ Action = 'deploy'; Reason = "deployed-sha is not a commit ('$deployed'): deploying in full"; Sha = $sha }
    }
    # Under 'Stop', PS 5.1 turns a redirected native stderr line into a terminating error, so the
    # one call that may legitimately fail (an unknown deployed sha) runs under 'Continue'.
    $preference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $changed = @(& git -C $AppPath diff-tree -r --name-only --no-renames --no-commit-id $deployed $sha -- apps/bhw-connect 2>$null)
        $diffExit = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $preference
    }
    if ($diffExit -ne 0) {
        return @{ Action = 'deploy'; Reason = "cannot diff from $deployed (unknown here?): deploying in full"; Sha = $sha }
    }
    $served = @($changed | Where-Object { $_ -and -not (Test-NotServed $_) })
    if ($served.Count -eq 0) {
        return @{ Action = 'skip'; Reason = ("{0} path(s) changed under apps/bhw-connect, none the server loads" -f $changed.Count); Sha = $sha }
    }
    return @{ Action = 'deploy'; Reason = ("{0} served path(s) changed, e.g. {1}" -f $served.Count, $served[0]); Sha = $sha }
}

function Get-CurrentRelease {
    if (-not (Test-Path -LiteralPath $CurrentLink)) {
        return $null
    }
    $target = @((Get-Item -LiteralPath $CurrentLink).Target)
    if ($target.Count -eq 0) {
        return $null
    }
    return [string]$target[0]
}

# Removes the junction itself (never the release it points at) and points it at $Release.
function Set-CurrentRelease([string]$Release) {
    if (Test-Path -LiteralPath $CurrentLink) {
        # rmdir on a junction removes the link only; Remove-Item -Recurse on PS 5.1 can follow it.
        & cmd /c rmdir "$CurrentLink"
        if ($LASTEXITCODE -ne 0) {
            throw "Could not remove the junction $CurrentLink."
        }
    }
    Invoke-Native cmd @('/c', 'mklink', '/J', $CurrentLink, $Release) | Out-Null
}

function Wait-Healthy {
    $deadline = (Get-Date).AddSeconds(60)
    while ((Get-Date) -lt $deadline) {
        try {
            $response = Invoke-WebRequest -Uri $HealthUrl -UseBasicParsing -TimeoutSec 5
            if ($response.StatusCode -eq 200) {
                return $true
            }
        }
        catch {
            Start-Sleep -Seconds 2
        }
    }
    return $false
}

function Switch-Release([string]$Release) {
    $previous = Get-CurrentRelease
    Write-Step "swap: $previous -> $Release"
    Stop-Service -Name $ServiceName
    Set-CurrentRelease $Release
    Start-Service -Name $ServiceName
    if (Wait-Healthy) {
        if ($previous) {
            Set-Content -LiteralPath (Join-Path $BhwRoot 'previous-release') -Value $previous -Encoding ASCII
        }
        return
    }
    Write-Step "the new release did not answer 200 at $HealthUrl within 60 s: putting $previous back"
    Stop-Service -Name $ServiceName
    if ($previous) {
        Set-CurrentRelease $previous
        Start-Service -Name $ServiceName
    }
    throw "BHW release $Release failed its health check and was rolled back. Read $BhwRoot\logs."
}

function Get-Census([hashtable]$Env) {
    $env:BHW_DATABASE_URL = $Env['BHW_DATABASE_URL']
    $json = Invoke-Native node @('db\migrate.mjs', '--census')
    return ($json | ConvertFrom-Json)
}

function Invoke-Migrations([hashtable]$Env, [string]$Sha) {
    if (-not $Env['BHW_DATABASE_URL']) {
        Write-Step 'migrations: BHW_DATABASE_URL is not set, so the data is still on Supabase; nothing to apply here'
        return
    }
    $env:BHW_DATABASE_URL = $Env['BHW_DATABASE_URL']
    & node db\migrate.mjs --check
    if ($LASTEXITCODE -eq 0) {
        Write-Step 'migrations: nothing pending'
        return
    }
    if ($LASTEXITCODE -ne 1) {
        throw "db\migrate.mjs --check failed with code $LASTEXITCODE."
    }
    $before = Get-Census $Env
    New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null
    $dump = Join-Path $BackupDir ("pre-deploy-{0}-{1}.dump" -f (Get-Date -Format 'yyyyMMdd-HHmmss'), $Sha.Substring(0, 8))
    Invoke-Native pg_dump @('--format=custom', "--file=$dump", '--dbname', $Env['BHW_DATABASE_URL'])
    Write-Step "backup: $dump"
    Invoke-Native node @('db\migrate.mjs')
    $after = Get-Census $Env
    $dropped = @()
    foreach ($table in $before.PSObject.Properties.Name) {
        $was = [long]$before.$table
        $now = if ($after.PSObject.Properties.Name -contains $table) { [long]$after.$table } else { -1 }
        if ($now -lt $was) {
            $dropped += ("{0}: {1} -> {2}" -f $table, $was, $now)
        }
    }
    if ($dropped.Count -gt 0) {
        throw ("A migration removed rows, which staging never allows: {0}. The old release is still live; the dump is {1}." -f ($dropped -join '; '), $dump)
    }
    Write-Step ("census: {0} table(s), none lost a row" -f $before.PSObject.Properties.Name.Count)
}

function New-Release([string]$Sha) {
    $release = Join-Path $ReleasesDir $Sha
    if (Test-Path -LiteralPath (Join-Path $release 'server.js')) {
        Write-Step "release $Sha is already assembled"
        return $release
    }
    if (-not (Test-Path -LiteralPath $EnvFile)) {
        throw "$EnvFile is missing: the build reads its NEXT_PUBLIC_* values. Write it on the box first (runbook 8.4)."
    }
    Push-Location $AppDir
    try {
        $envProduction = Join-Path $AppDir '.env.production'
        Copy-Item -LiteralPath $EnvFile -Destination $envProduction -Force
        try {
            Invoke-Native npm.cmd @('ci', '--no-audit', '--no-fund')
            Invoke-Native npm.cmd @('run', 'build')
        }
        finally {
            Remove-Item -LiteralPath $envProduction -Force -ErrorAction SilentlyContinue
        }
        $staging = "$release.partial"
        if (Test-Path -LiteralPath $staging) {
            Remove-Item -LiteralPath $staging -Recurse -Force
        }
        New-Item -ItemType Directory -Force -Path $staging | Out-Null
        # robocopy exits 0-7 on success; 8 and above is a failure.
        & robocopy (Join-Path $AppDir '.next\standalone') $staging /E /NFL /NDL /NJH /NJS /NP | Out-Null
        if ($LASTEXITCODE -ge 8) { throw "robocopy of .next\standalone failed ($LASTEXITCODE)." }
        & robocopy (Join-Path $AppDir '.next\static') (Join-Path $staging '.next\static') /E /NFL /NDL /NJH /NJS /NP | Out-Null
        if ($LASTEXITCODE -ge 8) { throw "robocopy of .next\static failed ($LASTEXITCODE)." }
        & robocopy (Join-Path $AppDir 'public') (Join-Path $staging 'public') /E /NFL /NDL /NJH /NJS /NP | Out-Null
        if ($LASTEXITCODE -ge 8) { throw "robocopy of public failed ($LASTEXITCODE)." }
        if (-not (Test-Path -LiteralPath (Join-Path $staging 'server.js'))) {
            throw "The build produced no server.js in .next\standalone (is output: 'standalone' still set?)."
        }
        Rename-Item -LiteralPath $staging -NewName (Split-Path $release -Leaf)
        Write-Step "release assembled: $release"
        return $release
    }
    finally {
        Pop-Location
    }
}

function Remove-OldReleases {
    $keep = @((Get-CurrentRelease), (Get-Content -LiteralPath (Join-Path $BhwRoot 'previous-release') -ErrorAction SilentlyContinue)) |
        Where-Object { $_ } | ForEach-Object { [IO.Path]::GetFullPath($_).TrimEnd('\') }
    $releases = @(Get-ChildItem -LiteralPath $ReleasesDir -Directory | Sort-Object LastWriteTime -Descending)
    foreach ($old in ($releases | Select-Object -Skip $KeepReleases)) {
        if ($keep -notcontains $old.FullName.TrimEnd('\')) {
            Write-Step "pruning release $($old.Name)"
            Remove-Item -LiteralPath $old.FullName -Recurse -Force
        }
    }
}

# ---------------------------------------------------------------------------------------------

if ($Rollback) {
    $previousFile = Join-Path $BhwRoot 'previous-release'
    if (-not (Test-Path -LiteralPath $previousFile)) {
        throw "No previous release is recorded in $previousFile; nothing to roll back to."
    }
    $previous = (Get-Content -LiteralPath $previousFile -Raw).Trim()
    if (-not (Test-Path -LiteralPath (Join-Path $previous 'server.js'))) {
        throw "The recorded previous release $previous has no server.js."
    }
    Switch-Release $previous
    Write-Step "rolled back to $previous (deployed-sha left as it was: the next push redeploys)"
    exit 0
}

$plan = Get-DeployPlan
Write-Step ("plan: {0} - {1} ({2})" -f $plan.Action, $plan.Reason, $plan.Sha)
if ($PlanOnly) {
    exit 0
}
if ($plan.Action -eq 'skip' -and -not $BuildOnly) {
    Set-Content -LiteralPath $DeployedShaFile -Value $plan.Sha -Encoding ASCII
    exit 0
}

New-Item -ItemType Directory -Force -Path $ReleasesDir | Out-Null
$release = New-Release $plan.Sha
if ($BuildOnly) {
    Write-Step "built only: $release (nothing live changed)"
    exit 0
}

$environment = Read-EnvFile $EnvFile
Push-Location $AppDir
try {
    Invoke-Migrations $environment $plan.Sha
}
finally {
    Pop-Location
}

Switch-Release $release
Set-Content -LiteralPath $DeployedShaFile -Value $plan.Sha -Encoding ASCII
Remove-OldReleases
Write-Step "BHW Connect $($plan.Sha) is live"
