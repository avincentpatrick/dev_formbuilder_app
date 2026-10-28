#Requires -Version 5.1
<#
.SYNOPSIS
  Dumps BHW Connect's database (bhw_connect) on the testing box. Task "bhw-connect-db-backup"
  runs it nightly (ops/register-cron-tasks.ps1).

.DESCRIPTION
  Meridian's own backups (deploy:backup, its nightly task) cover the database "meridian" only and
  never see "bhw_connect", so BHW has its own: pg_dump --format=custom into the backup folder, the
  newest -Keep dumps kept. fact_bhw_raw holds personal records, so the folder is readable by the
  operator account only (the runbook's 8.4 sets that ACL once). The connection comes from
  BHW_DATABASE_URL in <BhwRoot>\.env. Exits non-zero on any failure, so the task's last result
  shows it.

  Plain ASCII on purpose: Windows PowerShell 5.1 reads a file without a BOM as ANSI.
#>
param(
    [string]$BhwRoot = 'C:\meridian\bhw',
    [string]$BackupDir = 'C:\meridian\backups\bhw',
    [int]$Keep = 14
)

$ErrorActionPreference = 'Stop'

$url = $null
foreach ($line in Get-Content -LiteralPath (Join-Path $BhwRoot '.env')) {
    if ($line -match '^\s*BHW_DATABASE_URL\s*=\s*(.+?)\s*$') {
        $url = $Matches[1].Trim('"').Trim("'")
    }
}
if (-not $url) {
    throw "BHW_DATABASE_URL is not set in $BhwRoot\.env; there is no local BHW database to dump yet."
}

New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null
$file = Join-Path $BackupDir ("bhw_connect-{0}.dump" -f (Get-Date -Format 'yyyyMMdd-HHmmss'))
& pg_dump --format=custom "--file=$file" --dbname $url
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
