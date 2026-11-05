#Requires -Version 5.1
<#
.SYNOPSIS
  Backs up BHW Connect on the testing box: its database (bhw_connect) and its feedback screenshots.
  Task "bhw-connect-db-backup" runs it nightly (ops/register-cron-tasks.ps1).

.DESCRIPTION
  Meridian's own backups (deploy:backup, its nightly task) cover the database "meridian" only and
  never see "bhw_connect", so BHW has its own: pg_dump --format=custom into the backup folder, the
  newest -Keep dumps kept. fact_bhw_raw holds personal records, so the folder is readable by the
  operator account only (the runbook's 8.4 sets that ACL once). The connection comes from
  BHW_DATABASE_URL in <BhwRoot>\.env, and pg_dump from Resolve-PgDump (ops\pg-tools.ps1), since the
  box's PATH has none. Exits non-zero on any failure, so the task's last result shows it.

  The dump holds a screenshot's row and not the screenshot: the picture is a file on disk, under
  BHW_STORAGE_DIR (lib/storage/screenshots.ts). So each night the storage folder is copied beside the
  dump, named by the same stamp, the newest -Keep copies kept, and the copy's file count is checked. A
  missing folder fails the task rather than passing quietly, because then no backup holds them. The dump
  runs first, so that failure never costs the database's backup.

  Plain ASCII on purpose: Windows PowerShell 5.1 reads a file without a BOM as ANSI.
#>
param(
    [string]$BhwRoot = 'C:\meridian\bhw',
    [string]$BackupDir = 'C:\meridian\backups\bhw',
    [int]$Keep = 14
)

$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'pg-tools.ps1')

$values = @{}
foreach ($line in Get-Content -LiteralPath (Join-Path $BhwRoot '.env')) {
    if ($line -match '^\s*(BHW_DATABASE_URL|BHW_PG_DUMP|BHW_STORAGE_DIR)\s*=\s*(.+?)\s*$') {
        $values[$Matches[1]] = $Matches[2].Trim('"').Trim("'")
    }
}
$url = $values['BHW_DATABASE_URL']
if (-not $url) {
    throw "BHW_DATABASE_URL is not set in $BhwRoot\.env; there is no local BHW database to dump yet."
}

New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$file = Join-Path $BackupDir ("bhw_connect-{0}.dump" -f $stamp)
& (Resolve-PgDump $values) --format=custom "--file=$file" --dbname $url
if ($LASTEXITCODE -ne 0) {
    Remove-Item -LiteralPath $file -Force -ErrorAction SilentlyContinue
    throw "pg_dump exited with code $LASTEXITCODE."
}
if ((Get-Item -LiteralPath $file).Length -eq 0) {
    throw "pg_dump wrote an empty file: $file"
}
Write-Host ("[{0}] dumped {1} ({2:N0} bytes)" -f (Get-Date -Format 's'), $file, (Get-Item -LiteralPath $file).Length)

Get-ChildItem -LiteralPath $BackupDir -Filter 'bhw_connect-*.dump' |
    Sort-Object LastWriteTime -Descending |
    Select-Object -Skip $Keep |
    ForEach-Object {
        Write-Host "pruning $($_.Name)"
        Remove-Item -LiteralPath $_.FullName -Force
    }

$storage = $values['BHW_STORAGE_DIR']
if (-not $storage) {
    $storage = Join-Path $BhwRoot 'storage'
}
if (-not (Test-Path -LiteralPath $storage -PathType Container)) {
    throw "The screenshot folder $storage does not exist, so no backup holds the feedback screenshots. The database's dump is done."
}

$copy = Join-Path $BackupDir ("bhw_connect-{0}-storage" -f $stamp)
& robocopy $storage $copy /E /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) {
    Remove-Item -LiteralPath $copy -Recurse -Force -ErrorAction SilentlyContinue
    throw "robocopy of $storage failed ($LASTEXITCODE)."
}
New-Item -ItemType Directory -Force -Path $copy | Out-Null
$expected = @(Get-ChildItem -LiteralPath $storage -Recurse -File).Count
$actual = @(Get-ChildItem -LiteralPath $copy -Recurse -File).Count
if ($actual -ne $expected) {
    throw "The screenshot copy holds $actual file(s), and $storage holds $expected."
}
Write-Host ("[{0}] copied {1} screenshot file(s) to {2}" -f (Get-Date -Format 's'), $actual, $copy)

Get-ChildItem -LiteralPath $BackupDir -Directory -Filter 'bhw_connect-*-storage' |
    Sort-Object LastWriteTime -Descending |
    Select-Object -Skip $Keep |
    ForEach-Object {
        Write-Host "pruning $($_.Name)"
        Remove-Item -LiteralPath $_.FullName -Recurse -Force
    }

# robocopy's success codes run up to 7, and this script's result is the task's last result.
exit 0
