#Requires -Version 5.1
<#
.SYNOPSIS
  Rebuilds BHW Connect's barangay candidate package on the testing box, one step per run.

.DESCRIPTION
  Follows the repository's recorded recipe (apps\bhw-connect\docs\BARANGAY_RING_RETENTION.md and
  BARANGAY_CANDIDATE_PACKAGING.md). Run the steps in this order, each in the same admin PowerShell:

    Check      tools, memory, disk, database access. Changes nothing.
    Fetch      download the pinned source archive and verify its SHA-256.
    Reference  write the geography list from the bhw_connect database (never printed credentials).
    Rings      split the source into protected rings.
    Simplify   Mapshaper 0.6.113, 10 percent, keep-shapes. SLOW (tens of minutes) and needs ~12 GB memory.
    Assemble   rebuild barangays from the rings. Exit 1 with a few withheld barangays is expected.
    Validate   retention and overlap checks. Exit 1 with blocking checks is expected, as before.
    Package    write the municipality package and verify it. Prints the new fingerprint.

  Every file's fingerprint is stored in <Work>\state.json, so no step needs a fingerprint typed in.
  Nothing here touches the website, Apache or .env. Plain ASCII on purpose.
#>
param(
    [Parameter(Mandatory = $true)][ValidateSet('Check', 'Fetch', 'Reference', 'Rings', 'Simplify', 'Assemble', 'Validate', 'Package', 'Status')][string]$Step,
    [string]$Release = 'psgc-2026q3-ring-rebuilt-20261109',
    [string]$Work = 'C:\meridian\bhw\barangay-build',
    [string]$AppPath = 'C:\meridian\test-app',
    [string]$BhwRoot = 'C:\meridian\bhw',
    [string]$SourceSha256 = '0c1627035e1bef618a7f57ca96bc93822710aef03aa0c273a922f3b7cd3b198a',
    [string]$SourceUrl = 'https://github.com/faeldon/philippines-psgc-shapefiles/releases/download/v1.0.1/PH_Adm4_BgySubMuns.shp.zip',
    [int]$MemoryMb = 12000,
    [switch]$InstallDeps
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
if ($Release -notmatch '^[a-z0-9][a-z0-9.-]{2,79}$' -or $Release -eq 'latest' -or $Release.Contains('..')) {
    throw "Release '$Release' is not an accepted release name."
}
$Ing = Join-Path $AppPath 'apps\bhw-connect\ingestion'
$StateFile = Join-Path $Work 'state.json'
$SourceZip = Join-Path $Work 'PH_Adm4_BgySubMuns.shp.zip'
$RefFile = Join-Path $Work 'reference.json'
$RingsDir = Join-Path $Work 'rings-input'
$Simplified = Join-Path $Work 'simplified-rings.geojson'
$Candidate = Join-Path $Work 'candidate.geojson'
$AssemblyReport = Join-Path $Work 'assembly-report.json'
$Validation = Join-Path $Work 'validation.json'
$PackageDir = Join-Path $Work 'package'

function Get-Sha([string]$Path) { (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLower() }

function Get-State {
    if (Test-Path -LiteralPath $StateFile) { return (Get-Content -LiteralPath $StateFile -Raw | ConvertFrom-Json) }
    return (New-Object psobject)
}
function Set-State([string]$Key, [string]$Value) {
    $s = Get-State
    if ($s.PSObject.Properties[$Key]) { $s.$Key = $Value } else { Add-Member -InputObject $s -NotePropertyName $Key -NotePropertyValue $Value }
    New-Item -ItemType Directory -Path $Work -Force | Out-Null
    ($s | ConvertTo-Json) | Set-Content -LiteralPath $StateFile -Encoding ASCII
}
function Need-State([string]$Key, [string]$FromStep) {
    $s = Get-State
    if (-not $s.PSObject.Properties[$Key] -or -not $s.$Key) { throw "Missing '$Key'. Run -Step $FromStep first." }
    return [string]$s.$Key
}

# Python: "python", else the "py -3" launcher.
$script:PyExe = $null
$script:PyPre = @()
if (Get-Command python -ErrorAction SilentlyContinue) { $script:PyExe = 'python' }
elseif (Get-Command py -ErrorAction SilentlyContinue) { $script:PyExe = 'py'; $script:PyPre = @('-3') }
function Invoke-Py([string[]]$PyArgs) {
    if (-not $script:PyExe) { throw 'Python is not on PATH.' }
    & $script:PyExe @script:PyPre @PyArgs
}

function Read-EnvValue([string]$Name) {
    $file = Join-Path $BhwRoot '.env'
    if (-not (Test-Path -LiteralPath $file)) { throw "$file is missing." }
    foreach ($line in Get-Content -LiteralPath $file) {
        $t = $line.Trim()
        if ($t.StartsWith('#') -or -not $t.Contains('=')) { continue }
        $k, $v = $t.Split('=', 2)
        if ($k.Trim() -eq $Name) { return $v.Trim().Trim('"').Trim("'") }
    }
    return $null
}

function Get-Psql {
    . (Join-Path $AppPath 'apps\bhw-connect\ops\pg-tools.ps1')
    $envMap = @{}
    $configured = Read-EnvValue 'BHW_PG_DUMP'
    if ($configured) { $envMap['BHW_PG_DUMP'] = $configured }
    $dump = Resolve-PgDump -Env $envMap
    $psql = Join-Path (Split-Path -Parent $dump) 'psql.exe'
    if (-not (Test-Path -LiteralPath $psql)) { throw "psql.exe not found beside $dump." }
    return $psql
}

switch ($Step) {

    'Check' {
        $problems = @()
        foreach ($f in 'barangay_ring_pipeline.py', 'validate_national_barangay_build.py', 'package_barangay_candidates.py', 'reconcile_barangay_candidate.py') {
            if (-not (Test-Path -LiteralPath (Join-Path $Ing $f))) { $problems += "Missing script $Ing\$f" }
        }
        if (-not $script:PyExe) { $problems += 'Python not found (neither python nor py).' }
        else {
            Write-Host ("Python: " + (Invoke-Py @('--version') 2>&1))
            $probe = Invoke-Py @('-c', 'import shapely, shapefile; print("shapely", shapely.__version__, "pyshp", shapefile.__version__)') 2>&1
            if ($LASTEXITCODE -ne 0) {
                if ($InstallDeps) {
                    Invoke-Py @('-m', 'pip', 'install', 'shapely>=2,<3', 'pyshp==2.3.1')
                    if ($LASTEXITCODE -ne 0) { $problems += 'pip install failed.' }
                } else { $problems += 'Python libraries missing. Re-run: -Step Check -InstallDeps' }
            } else { Write-Host $probe }
        }
        $node = Get-Command node -ErrorAction SilentlyContinue
        if (-not $node) { $problems += 'Node not found.' } else { Write-Host ("Node: " + (& node --version)) }
        if (-not (Get-Command npx.cmd -ErrorAction SilentlyContinue)) { $problems += 'npx.cmd not found.' }
        $ramGb = [math]::Round((Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory / 1GB, 1)
        Write-Host "Memory: $ramGb GB (the Simplify step wants about 12 GB for itself)"
        if ($ramGb -lt 14) { $problems += "Only $ramGb GB of memory; the national simplify step needs about 14 GB." }
        $drive = (Split-Path -Qualifier $Work)
        $freeGb = [math]::Round((Get-PSDrive ($drive.TrimEnd(':'))).Free / 1GB, 1)
        Write-Host "Free disk on ${drive}: $freeGb GB (needs about 10 GB)"
        if ($freeGb -lt 10) { $problems += "Only $freeGb GB free on $drive." }
        try {
            $psql = Get-Psql
            $url = Read-EnvValue 'BHW_DATABASE_URL'
            if (-not $url) { $problems += 'BHW_DATABASE_URL is not set in .env.' }
            else {
                $count = (& $psql --dbname $url -At -c "SELECT count(*) FROM public.dim_geo WHERE geo_level = 'barangay'" | Out-String).Trim()
                Write-Host "Barangays in the database: $count (the earlier reference had 41,991)"
                if ($LASTEXITCODE -ne 0 -or $count -notmatch '^\d+$') { $problems += 'Could not read dim_geo.' }
            }
        } catch { $problems += "Database check: $($_.Exception.Message)" }
        if ($problems.Count) {
            Write-Host 'PROBLEMS:' -ForegroundColor Red
            $problems | ForEach-Object { Write-Host " - $_" -ForegroundColor Red }
            throw 'Fix the problems above before continuing.'
        }
        Write-Host 'All checks passed. Next: -Step Fetch'
    }

    'Fetch' {
        New-Item -ItemType Directory -Path $Work -Force | Out-Null
        if (-not (Test-Path -LiteralPath $SourceZip)) {
            Write-Host 'Downloading about 200 MB ...'
            Invoke-WebRequest -UseBasicParsing -Uri $SourceUrl -OutFile $SourceZip
        }
        $h = Get-Sha $SourceZip
        if ($h -ne $SourceSha256.ToLower()) { Remove-Item -LiteralPath $SourceZip -Force; throw "Source fingerprint is $h, expected $SourceSha256. The file was deleted. Do not continue." }
        Set-State 'source_sha256' $h
        Write-Host "Source verified ($h). Next: -Step Reference"
    }

    'Reference' {
        if (Test-Path -LiteralPath $RefFile) { throw "$RefFile exists. Delete it to rebuild the reference." }
        $psql = Get-Psql
        $url = Read-EnvValue 'BHW_DATABASE_URL'
        if (-not $url) { throw 'BHW_DATABASE_URL is not set in .env.' }
        $env:PGCLIENTENCODING = 'UTF8'
        $sql = "SELECT json_agg(json_build_object('geo_code', geo_code, 'geo_level', geo_level::text, 'geo_name', geo_name, 'region_code', region_code, 'province_code', province_code, 'citymun_code', citymun_code) ORDER BY geo_code) FROM public.dim_geo WHERE geo_level IN ('province', 'citymun', 'barangay')"
        & $psql --dbname $url -At -o $RefFile -c $sql
        if ($LASTEXITCODE -ne 0) { throw 'psql failed.' }
        $size = (Get-Item -LiteralPath $RefFile).Length
        if ($size -lt 1MB) { throw "Reference is only $size bytes; something is wrong." }
        Set-State 'reference_sha256' (Get-Sha $RefFile)
        Write-Host ("Reference written: {0:N0} bytes. Next: -Step Rings" -f $size)
    }

    'Rings' {
        $src = Need-State 'source_sha256' 'Fetch'
        if (Test-Path -LiteralPath $RingsDir) { throw "$RingsDir exists. Delete it to redo this step." }
        Invoke-Py @((Join-Path $Ing 'barangay_ring_pipeline.py'), 'prepare', '--source', $SourceZip, '--sha256', $src, '--output', $RingsDir)
        if ($LASTEXITCODE -ne 0) { throw "prepare failed (exit $LASTEXITCODE)." }
        Set-State 'rings_manifest_sha256' (Get-Sha (Join-Path $RingsDir 'manifest.json'))
        Write-Host 'Rings prepared. Next: -Step Simplify (slow; leave the window open)'
    }

    'Simplify' {
        Need-State 'rings_manifest_sha256' 'Rings' | Out-Null
        if (Test-Path -LiteralPath $Simplified) { throw "$Simplified exists. Delete it to redo this step." }
        $env:NODE_OPTIONS = "--max-old-space-size=$MemoryMb"
        Write-Host 'Running Mapshaper 0.6.113 (tens of minutes; no output until it finishes) ...'
        & npx.cmd --yes --package mapshaper@0.6.113 -- mapshaper (Join-Path $RingsDir 'rings.shp') -proj wgs84 -simplify '10%' keep-shapes -o format=geojson $Simplified
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $Simplified)) { throw "Mapshaper failed (exit $LASTEXITCODE). If it ran out of memory, close other programs and retry." }
        Set-State 'rings_sha256' (Get-Sha $Simplified)
        Write-Host 'Simplified. Next: -Step Assemble'
    }

    'Assemble' {
        $ms = Need-State 'rings_manifest_sha256' 'Rings'
        $rs = Need-State 'rings_sha256' 'Simplify'
        if (Test-Path -LiteralPath $Candidate) { throw "$Candidate exists. Delete it to redo this step." }
        Invoke-Py @((Join-Path $Ing 'barangay_ring_pipeline.py'), 'assemble', '--manifest', (Join-Path $RingsDir 'manifest.json'), '--manifest-sha256', $ms, '--rings', $Simplified, '--rings-sha256', $rs, '--output', $Candidate, '--report', $AssemblyReport)
        $code = $LASTEXITCODE
        if ($code -ge 2 -or -not (Test-Path -LiteralPath $Candidate)) { throw "assemble failed (exit $code)." }
        if ($code -eq 1) { Write-Host 'Exit 1: a few barangays were withheld. That is expected (about 5 last time).' }
        Set-State 'candidate_sha256' (Get-Sha $Candidate)
        Write-Host 'Assembled. Next: -Step Validate'
    }

    'Validate' {
        $src = Need-State 'source_sha256' 'Fetch'
        $ref = Need-State 'reference_sha256' 'Reference'
        $cand = Need-State 'candidate_sha256' 'Assemble'
        if (Test-Path -LiteralPath $Validation) { throw "$Validation exists. Delete it to redo this step." }
        Invoke-Py @((Join-Path $Ing 'validate_national_barangay_build.py'), '--source', $SourceZip, '--source-sha256', $src, '--reference', $RefFile, '--reference-sha256', $ref, '--candidate', $Candidate, '--candidate-sha256', $cand, '--output', $Validation)
        $code = $LASTEXITCODE
        if ($code -ge 2 -or -not (Test-Path -LiteralPath $Validation)) { throw "validate failed (exit $code)." }
        if ($code -eq 1) { Write-Host 'Exit 1: blocking checks were reported (the withheld barangays). That is expected; the package withholds them.' }
        Set-State 'validation_sha256' (Get-Sha $Validation)
        Write-Host 'Validated. Next: -Step Package'
    }

    'Package' {
        $ref = Need-State 'reference_sha256' 'Reference'
        $cand = Need-State 'candidate_sha256' 'Assemble'
        $val = Need-State 'validation_sha256' 'Validate'
        if (Test-Path -LiteralPath $PackageDir) { throw "$PackageDir exists. Delete it to rebuild the package." }
        Invoke-Py @((Join-Path $Ing 'package_barangay_candidates.py'), 'build', '--candidate', $Candidate, '--candidate-sha256', $cand, '--reference', $RefFile, '--reference-sha256', $ref, '--validation', $Validation, '--validation-sha256', $val, '--release', $Release, '--output', $PackageDir)
        if ($LASTEXITCODE -ne 0) { throw "package build failed (exit $LASTEXITCODE)." }
        $manifestSha = Get-Sha (Join-Path $PackageDir 'manifest.json')
        Invoke-Py @((Join-Path $Ing 'package_barangay_candidates.py'), 'verify', '--package', $PackageDir, '--candidate', $Candidate, '--reference', $RefFile, '--manifest-sha256', $manifestSha)
        if ($LASTEXITCODE -ne 0) { throw "package verification failed (exit $LASTEXITCODE)." }
        Set-State 'manifest_sha256' $manifestSha
        $count = (Get-ChildItem -LiteralPath $PackageDir -Recurse -File | Measure-Object).Count
        Write-Host ''
        Write-Host 'PACKAGE BUILT AND VERIFIED.' -ForegroundColor Green
        Write-Host "Release:         $Release"
        Write-Host "Package folder:  $PackageDir"
        Write-Host "Manifest SHA256: $manifestSha"
        Write-Host "File count:      $count"
        Write-Host 'Send me the four lines above. Then install it with:'
        Write-Host "  cd C:\meridian"
        Write-Host "  .\bhw-barangay-release.ps1 -Step Install -Release $Release -ManifestSha256 $manifestSha -ExpectedFiles $count -Source $PackageDir"
    }

    'Status' {
        Get-State | Format-List
        foreach ($p in $SourceZip, $RefFile, $RingsDir, $Simplified, $Candidate, $Validation, $PackageDir) { '{0,-70} {1}' -f $p, (Test-Path -LiteralPath $p) }
    }
}
