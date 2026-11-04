#Requires -Version 5.1
<#
.SYNOPSIS
  Writes BHW Connect's own settings, from GitHub repository secrets, into <BhwRoot>\.env on the testing
  box. deploy.yml and bhw-settings.yml run it as the runner, before ops\deploy-bhw.ps1 (Meridian's D199).

.DESCRIPTION
  Only the names in $Settings are ever touched: BHW's third-party API keys, CRON_SECRET and the usage-log
  salt, the settings jongsky25 owns after the handover. The database URL, the PostgREST keys and every
  NEXT_PUBLIC_ value stay the operator's, set by hand on the box.

  Each name arrives as the environment variable BHW_SETTING_<NAME>, which the workflow maps from the
  repository secret BHW_<NAME>. An unset secret arrives empty and means "keep the box's value", so a
  deploy can never blank a key. A value that differs replaces its line, or is added.

  Values are never printed: the output names what changed. Before a change, .env is copied into
  <BackupDir>, which only SYSTEM, NETWORK SERVICE and Administrators can read (the BHW folder itself is
  readable by every local user); the newest 10 copies are kept. Returns how many settings changed, so the
  caller restarts BHW when one did and no new release is going live.

  Plain ASCII on purpose: Windows PowerShell 5.1 reads a file without a BOM as ANSI.
#>
param(
    [string]$BhwRoot = 'C:\meridian\bhw',
    [string]$BackupDir = 'C:\meridian\backups\bhw\env'
)

$ErrorActionPreference = 'Stop'

$Settings = @('GEMINI_API_KEY', 'GROQ_API_KEY', 'OPENROUTER_API_KEY', 'MISTRAL_API_KEY', 'CRON_SECRET', 'USAGE_EVENTS_IP_SALT')

$envFile = Join-Path $BhwRoot '.env'
$lines = New-Object System.Collections.Generic.List[string]
foreach ($line in Get-Content -LiteralPath $envFile) {
    $lines.Add($line)
}

$changed = @()
$kept = 0
$unset = 0
foreach ($name in $Settings) {
    $value = [Environment]::GetEnvironmentVariable("BHW_SETTING_$name")
    if ([string]::IsNullOrWhiteSpace($value)) {
        $unset++
        continue
    }
    $value = $value.Trim()
    if ($value -match '[\r\n]') {
        throw "The secret BHW_$name holds a line break, which a .env value cannot."
    }
    $pattern = '^\s*' + [regex]::Escape($name) + '\s*='
    $at = -1
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match $pattern) {
            $at = $i
            break
        }
    }
    if ($at -ge 0) {
        $current = ($lines[$at] -replace ($pattern + '\s*'), '').Trim().Trim('"').Trim("'")
        if ($current -ceq $value) {
            $kept++
            continue
        }
        $lines[$at] = "$name=$value"
    }
    else {
        $lines.Add("$name=$value")
    }
    $changed += $name
}

if ($changed.Count -gt 0) {
    New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null
    Copy-Item -LiteralPath $envFile -Destination (Join-Path $BackupDir ('.env-{0}.bak' -f (Get-Date -Format 'yyyyMMdd-HHmmss')))
    Get-ChildItem -LiteralPath $BackupDir -Filter '.env-*.bak' |
        Sort-Object LastWriteTime -Descending |
        Select-Object -Skip 10 |
        ForEach-Object { Remove-Item -LiteralPath $_.FullName -Force }
    # Rewritten in place, so the file keeps its own ACL: Set-Content truncates an existing file, it does
    # not create a new one.
    Set-Content -LiteralPath $envFile -Value $lines -Encoding ASCII
}

Write-Host ("[{0}] settings: {1} changed ({2}), {3} already equal, {4} not set in GitHub" -f (Get-Date -Format 's'), $changed.Count, ($changed -join ', '), $kept, $unset)
return $changed.Count
