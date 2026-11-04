#Requires -Version 5.1
<#
.SYNOPSIS
  Takes BHW Connect's development copy from the box's database: the cleaned schema, the newest migration
  version it holds, and a made-up seed (Meridian's bhw-p4-loop, D207). The "BHW Connect dev copy" workflow
  runs it on the box and uploads the three files for a day; a pull request commits them under
  apps\bhw-connect (db\baseline\schema.sql, db\baseline\version.txt, supabase\seed.sql).

.DESCRIPTION
  Only the box's runner can read the box's database from GitHub, so this is how the copy is refreshed after
  a schema change, with no box access. Nothing it writes holds a real record or count:
    schema.sql   pg_dump --schema-only of public, cleaned by db\clean-baseline.mjs
    version.txt  the newest version in supabase_migrations.schema_migrations; a fresh database built from
                 schema.sql is marked up to it with db\migrate.mjs --baseline=, then newer files apply
    seed.sql     db\make-seed.mjs: dim_* and the named reference tables copied, agg_* and fact_* generated

  The connection comes from BHW_DATABASE_URL in <BhwRoot>\.env and is never printed; pg_dump and psql
  from Resolve-PgDump (ops\pg-tools.ps1).

  Plain ASCII on purpose: Windows PowerShell 5.1 reads a file without a BOM as ANSI.
#>
param(
    [Parameter(Mandatory = $true)][string]$AppPath,
    [Parameter(Mandatory = $true)][string]$OutDir,
    [string]$BhwRoot = 'C:\meridian\bhw'
)

$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'pg-tools.ps1')

$values = @{}
foreach ($line in Get-Content -LiteralPath (Join-Path $BhwRoot '.env')) {
    if ($line -match '^\s*(BHW_DATABASE_URL|BHW_PG_DUMP)\s*=\s*(.+?)\s*$') {
        $values[$Matches[1]] = $Matches[2].Trim('"').Trim("'")
    }
}
$url = $values['BHW_DATABASE_URL']
if (-not $url) {
    throw "BHW_DATABASE_URL is not set in $BhwRoot\.env."
}
$pgDump = Resolve-PgDump $values
$psql = Join-Path (Split-Path -Parent $pgDump) 'psql.exe'
$appDir = Join-Path $AppPath 'apps\bhw-connect'
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

# 1. The schema, cleaned.
$raw = Join-Path $OutDir 'schema.raw.sql'
& $pgDump --schema-only --schema=public --no-owner "--file=$raw" --dbname $url
if ($LASTEXITCODE -ne 0) {
    throw "pg_dump of the schema exited with code $LASTEXITCODE."
}
& node (Join-Path $appDir 'db\clean-baseline.mjs') $raw (Join-Path $OutDir 'schema.sql')
if ($LASTEXITCODE -ne 0) {
    throw "db\clean-baseline.mjs exited with code $LASTEXITCODE."
}
Remove-Item -LiteralPath $raw -Force

# 2. The newest migration version the schema already holds.
$version = (& $psql --dbname $url -At -c 'SELECT max(version) FROM supabase_migrations.schema_migrations' | Out-String).Trim()
if ($LASTEXITCODE -ne 0 -or $version -notmatch '^\d{14}$') {
    throw "Could not read the newest migration version (got '$version')."
}
Set-Content -LiteralPath (Join-Path $OutDir 'version.txt') -Value $version -Encoding ASCII

# 3. The seed: dim_* copied, agg_* and fact_* generated.
$env:BHW_SEED_SOURCE_URL = $url
try {
    & node (Join-Path $appDir 'db\make-seed.mjs') "--out=$(Join-Path $OutDir 'seed.sql')"
    if ($LASTEXITCODE -ne 0) {
        throw "db\make-seed.mjs exited with code $LASTEXITCODE."
    }
}
finally {
    Remove-Item Env:BHW_SEED_SOURCE_URL -ErrorAction SilentlyContinue
}

Get-ChildItem -LiteralPath $OutDir | ForEach-Object {
    Write-Host ("[{0}] {1}: {2:N0} bytes" -f (Get-Date -Format 's'), $_.Name, $_.Length)
}
Write-Host "[$(Get-Date -Format 's')] baseline version $version"
