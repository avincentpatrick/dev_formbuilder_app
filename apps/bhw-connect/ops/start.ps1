#Requires -Version 5.1
<#
.SYNOPSIS
  Starts BHW Connect's server on the testing box. The NSSM service "bhw-connect" runs this file.

.DESCRIPTION
  Next's standalone server.js does not read .env files, so this loads <BhwRoot>\.env into the
  process environment, sets the port, host and mode, and runs <BhwRoot>\current\server.js - the
  release the current junction points at (ops/deploy-bhw.ps1 moves it). It listens on loopback
  only; Apache's ProxyPass for /bhw-connect is the only way in. Its output goes wherever NSSM sends
  stdout and stderr (<BhwRoot>\logs, rotated by NSSM).

  Plain ASCII on purpose: Windows PowerShell 5.1 reads a file without a BOM as ANSI.
#>
param(
    [string]$BhwRoot = 'C:\meridian\bhw',
    [int]$Port = 3100
)

$ErrorActionPreference = 'Stop'

$envFile = Join-Path $BhwRoot '.env'
if (-not (Test-Path -LiteralPath $envFile)) {
    throw "$envFile is missing; the server would start with no configuration."
}
foreach ($line in Get-Content -LiteralPath $envFile) {
    $trimmed = $line.Trim()
    if ($trimmed -eq '' -or $trimmed.StartsWith('#') -or -not $trimmed.Contains('=')) {
        continue
    }
    $name, $value = $trimmed.Split('=', 2)
    [Environment]::SetEnvironmentVariable($name.Trim(), $value.Trim().Trim('"').Trim("'"), 'Process')
}

$env:PORT = [string]$Port
$env:HOSTNAME = '127.0.0.1'
$env:NODE_ENV = 'production'
$env:NEXT_TELEMETRY_DISABLED = '1'

$server = Join-Path $BhwRoot 'current\server.js'
if (-not (Test-Path -LiteralPath $server)) {
    throw "$server is missing: no release is current. Run ops\deploy-bhw.ps1 -BuildOnly, then point the junction (runbook 8.4)."
}
Set-Location (Join-Path $BhwRoot 'current')
& node $server
exit $LASTEXITCODE
