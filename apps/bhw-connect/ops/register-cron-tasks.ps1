#Requires -Version 5.1
<#
.SYNOPSIS
  Registers BHW Connect's scheduled tasks on the testing box, from the versioned schedule.

.DESCRIPTION
  On Vercel, vercel.json's crons called /api/cron/* with "Authorization: Bearer <CRON_SECRET>".
  On the box, Windows Task Scheduler does: one daily task per entry in ops/cron-schedule.json, each a
  short PowerShell call to http://127.0.0.1:3100/bhw-connect<path> carrying the secret read from
  <BhwRoot>\.env at run time (never stored in the task). The schedule is UTC cron "M H * * *",
  converted to the box's local time here. Plus "bhw-connect-db-backup", nightly at 01:45 local,
  running ops/nightly-dump.ps1 from the live checkout.

  Re-running replaces the tasks (-Force), so a schedule change is: edit the JSON, merge, run this.
  Run from an elevated Windows PowerShell 5.1 console. The tasks run as SYSTEM.

  Plain ASCII on purpose: Windows PowerShell 5.1 reads a file without a BOM as ANSI.
#>
param(
    [string]$AppPath = 'C:\meridian\test-app',
    [string]$BhwRoot = 'C:\meridian\bhw',
    [string]$BaseUrl = 'http://127.0.0.1:3100/bhw-connect'
)

$ErrorActionPreference = 'Stop'

$opsDir = Join-Path $AppPath 'apps\bhw-connect\ops'
$schedule = Get-Content -LiteralPath (Join-Path $opsDir 'cron-schedule.json') -Raw | ConvertFrom-Json
$logDir = Join-Path $BhwRoot 'logs'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

foreach ($cron in $schedule.crons) {
    $fields = $cron.schedule -split '\s+'
    if ($fields.Count -ne 5 -or $fields[2] -ne '*' -or $fields[3] -ne '*' -or $fields[4] -ne '*' -or
        $fields[0] -notmatch '^\d+$' -or $fields[1] -notmatch '^\d+$') {
        throw "$($cron.name): only a daily 'M H * * *' schedule is supported here, not '$($cron.schedule)'."
    }
    $utc = [DateTime]::SpecifyKind([DateTime]::UtcNow.Date.AddHours([int]$fields[1]).AddMinutes([int]$fields[0]), 'Utc')
    $local = $utc.ToLocalTime()

    $log = Join-Path $logDir "$($cron.name).log"
    # The command reads the secret when it runs, so a rotated CRON_SECRET needs no re-registration.
    $command = @"
`$secret = ((Get-Content -LiteralPath '$BhwRoot\.env') -match '^\s*CRON_SECRET\s*=' | Select-Object -First 1) -replace '^\s*CRON_SECRET\s*=\s*', '';
try {
  `$r = Invoke-WebRequest -UseBasicParsing -TimeoutSec 300 -Uri '$BaseUrl$($cron.path)' -Headers @{ Authorization = "Bearer `$(`$secret.Trim().Trim('`"'))" };
  Add-Content -LiteralPath '$log' -Value ("{0} {1} {2}" -f (Get-Date -Format s), `$r.StatusCode, '$($cron.path)');
  exit 0
} catch {
  Add-Content -LiteralPath '$log' -Value ("{0} FAILED {1} {2}" -f (Get-Date -Format s), '$($cron.path)', `$_.Exception.Message);
  exit 1
}
"@
    $encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($command))
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -NonInteractive -EncodedCommand $encoded"
    $trigger = New-ScheduledTaskTrigger -Daily -At $local
    Register-ScheduledTask -TaskName $cron.name -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
    Write-Host ("registered {0}: daily at {1:HH:mm} local ({2:HH:mm} UTC) -> {3}" -f $cron.name, $local, $utc, $cron.path)
}

$dump = Join-Path $opsDir 'nightly-dump.ps1'
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$dump`" -BhwRoot `"$BhwRoot`""
$trigger = New-ScheduledTaskTrigger -Daily -At '01:45'
Register-ScheduledTask -TaskName 'bhw-connect-db-backup' -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
Write-Host 'registered bhw-connect-db-backup: daily at 01:45 local -> ops\nightly-dump.ps1'
