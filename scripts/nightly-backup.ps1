<#
.SYNOPSIS
    The testing site's nightly database backup: a dump, and a checked copy of it off the box.

.DESCRIPTION
    M216 (R-5f180437, R-4bda047a, D212). The task "meridian-test-db-backup" runs this every night;
    docs/deployment-infrastructure.md section 8.2 sets it up and section 8.3 records it as built.

    A backup on the same machine as the database is not disaster recovery: a dead disk takes both.
    So this takes the dump with "artisan deploy:backup", the one way this box takes a dump, then
    copies it to -OffBoxDir and compares SHA-256 on both sides.

    A copy is written under a ".partial" name and renamed only after its hash matches, so a file
    named as a dump on the target is always a whole one. The target is pruned only after a good
    copy, and only files named exactly like this script's own dumps are ever removed.

    The target is never created here. It must already exist, because a folder made on the wrong
    disk after a drive went missing would look like a working backup.

    It covers the database "meridian" only. BHW Connect has its own backup (section 8.4).

    Plain ASCII on purpose: Windows PowerShell 5.1 reads a file without a BOM as ANSI.

.NOTES
    THE EXIT CODE IS THE MONITORING SURFACE. Read the task's LastTaskResult:
      0  the dump was taken, copied off the box, and the copy's hash matches
      1  no dump was taken
      2  the dump is on the box, but the copy failed or did not match
      3  the dump is on the box, and no target is set: NOTHING IS OFF THE BOX

    Code 3 is deliberately not 0. This script's certificate sibling had a predecessor that logged
    success every day while its protective arm was dead (section 8.3); a backup with no copy must
    not read as a backup that worked.
#>
param(
    [string]$AppPath = 'C:\meridian\test-app',
    [string]$PhpPath = 'C:\php\php.exe',
    [string]$BackupDir = 'C:\meridian\backups\nightly',
    [string]$OffBoxDir = '',
    [ValidateRange(1, 3650)]
    [int]$Keep = 30,
    [string]$LogFile = 'C:\meridian\backups\backup.log'
)

$ErrorActionPreference = 'Stop'

$OwnDump = '^deploy-\d{8}-\d{6}\.dump$'

function Write-BackupLog([string]$Message) {
    $line = '{0} {1}' -f (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ'), $Message
    Write-Output $line
    # A log that cannot be written must not cost the night's backup.
    try { Add-Content -LiteralPath $LogFile -Value $line -Encoding ASCII } catch { Write-Output "could not write the log: $_" }
}

function Stop-Backup([int]$Code, [string]$Message) {
    Write-BackupLog "result=$Code $Message"
    exit $Code
}

function Get-OwnDumps([string]$Folder) {
    @(Get-ChildItem -LiteralPath $Folder -File | Where-Object { $_.Name -match $OwnDump } | Sort-Object Name -Descending)
}

# 1. The dump. artisan decides success, and a dump older than this run is not this run's dump.
$startedUtc = (Get-Date).ToUniversalTime().AddSeconds(-2)
try {
    $ErrorActionPreference = 'Continue'
    $output = & $PhpPath (Join-Path $AppPath 'artisan') 'deploy:backup' "--dir=$BackupDir" "--keep=$Keep" 2>&1 | ForEach-Object { "$_" }
    $exit = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
} catch {
    $ErrorActionPreference = 'Stop'
    Stop-Backup 1 "the dump could not be started: $_"
}
if ($exit -ne 0) {
    Stop-Backup 1 ("deploy:backup exited {0}: {1}" -f $exit, ($output -join ' '))
}

try {
    $dump = Get-OwnDumps $BackupDir | Select-Object -First 1
} catch {
    Stop-Backup 1 "the backup folder cannot be read: $_"
}
if ($null -eq $dump -or $dump.LastWriteTimeUtc -lt $startedUtc) {
    Stop-Backup 1 "deploy:backup reported success, but left no new dump in $BackupDir"
}
Write-BackupLog ('dumped {0} ({1} bytes)' -f $dump.FullName, $dump.Length)

# 2. The copy.
if ($OffBoxDir -eq '') {
    Stop-Backup 3 "no target is set, so nothing is off the box: $($dump.Name) is on this machine only"
}
if (-not (Test-Path -LiteralPath $OffBoxDir -PathType Container)) {
    Stop-Backup 2 "the target $OffBoxDir cannot be reached: $($dump.Name) is on this machine only"
}

$partial = Join-Path $OffBoxDir ($dump.Name + '.partial')
$final = Join-Path $OffBoxDir $dump.Name
try {
    Copy-Item -LiteralPath $dump.FullName -Destination $partial -Force
    $sourceHash = (Get-FileHash -LiteralPath $dump.FullName -Algorithm SHA256).Hash
    $copyHash = (Get-FileHash -LiteralPath $partial -Algorithm SHA256).Hash
    if ($sourceHash -ne $copyHash) {
        Remove-Item -LiteralPath $partial -Force
        Stop-Backup 2 "the copy of $($dump.Name) did not match (source $sourceHash, copy $copyHash) and was discarded"
    }
    Move-Item -LiteralPath $partial -Destination $final -Force
} catch {
    Stop-Backup 2 "the copy of $($dump.Name) failed: $_"
}
Write-BackupLog ('copied to {0}, SHA-256 {1}' -f $final, $copyHash)

# 3. The prune, only now that a good copy is there, and only of this script's own dumps.
try {
    Get-OwnDumps $OffBoxDir | Select-Object -Skip $Keep | ForEach-Object {
        Remove-Item -LiteralPath $_.FullName -Force
        Write-BackupLog "pruned $($_.Name) from the target"
    }
} catch {
    Stop-Backup 2 "the copy is good, but pruning the target failed: $_"
}

Stop-Backup 0 'dump taken, copied off the box and checked'
