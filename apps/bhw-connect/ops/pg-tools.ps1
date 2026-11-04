#Requires -Version 5.1
<#
.SYNOPSIS
  Shared helpers for BHW Connect's ops scripts on the testing box, dot-sourced by deploy-bhw.ps1 and
  nightly-dump.ps1.

.DESCRIPTION
  Resolve-PgDump: where pg_dump is. The box's PATH has no PostgreSQL bin folder (measured at Meridian's
  M206, B0), so a bare "pg_dump" fails there. The order is the one Meridian's own deploy:backup uses
  (app/Console/Commands/DeployBackupCommand.php): BHW_PG_DUMP from <BhwRoot>\.env when it is set, else
  the highest-numbered "C:\Program Files\PostgreSQL\<n>\bin\pg_dump.exe", else pg_dump on PATH.

  Hide-Secret: a command line with any URL password masked, for the deploy's "-->" echo lines. Once
  deploy.yml runs these scripts, that echo is the GitHub Actions log.

  Plain ASCII on purpose: Windows PowerShell 5.1 reads a file without a BOM as ANSI.
#>

function Resolve-PgDump {
    param(
        [hashtable]$Env = @{},
        [string]$ProgramFiles = $env:ProgramFiles
    )
    $configured = $Env['BHW_PG_DUMP']
    if ($configured) {
        if (-not (Test-Path -LiteralPath $configured -PathType Leaf)) {
            throw "BHW_PG_DUMP in .env names $configured, which does not exist."
        }
        return $configured
    }

    $root = Join-Path $ProgramFiles 'PostgreSQL'
    if (Test-Path -LiteralPath $root) {
        # Numerically, not by name: "9.6" sorts after "15" as text.
        $newest = Get-ChildItem -LiteralPath $root -Directory |
            Where-Object { Test-Path -LiteralPath (Join-Path $_.FullName 'bin\pg_dump.exe') } |
            Sort-Object -Descending -Property {
                $version = 0.0
                [void][double]::TryParse($_.Name, [Globalization.NumberStyles]::Float, [Globalization.CultureInfo]::InvariantCulture, [ref]$version)
                $version
            } |
            Select-Object -First 1
        if ($newest) {
            return (Join-Path $newest.FullName 'bin\pg_dump.exe')
        }
    }

    $onPath = Get-Command -Name 'pg_dump' -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($onPath) {
        return $onPath.Source
    }
    throw 'pg_dump was not found: no BHW_PG_DUMP in .env, no PostgreSQL under Program Files, none on PATH.'
}

function Hide-Secret([string]$Text) {
    return ($Text -replace '(://[^:/@\s]+:)[^@\s]+@', '$1***@')
}
