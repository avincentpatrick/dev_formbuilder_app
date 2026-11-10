#Requires -Version 5.1
#Requires -RunAsAdministrator
<#
.SYNOPSIS
  Installs and switches on BHW Connect's barangay map package on the testing box, one step per run.

.DESCRIPTION
  Steps, run in this order from an ADMIN PowerShell, each stopping at the first error:

    Install   verify the package fingerprint, copy it to <BhwRoot>\maps\<Release>, let Apache read it.
    Apache    add the /bhw-connect/maps route to meridian.conf (backup, httpd -t, restart, health
              check; restores the backup by itself if anything fails).
    Probe     run the repository's hosting check against the real route. Exit 0 = all files good.
    Enable    set BHW_BARANGAY_RELEASE in <BhwRoot>\.env and restart the bhw-connect service
              (restores .env by itself if the service does not come back).
    Rollback  remove that setting and restart: barangay drill-down is off again.

  It never overwrites a release folder and never prints .env. Plain ASCII on purpose.

.EXAMPLE
  .\bhw-barangay-release.ps1 -Step Install -Source D:\transfer\psgc-2026q3-ring-candidate-v1
  .\bhw-barangay-release.ps1 -Step Apache
  .\bhw-barangay-release.ps1 -Step Probe
  .\bhw-barangay-release.ps1 -Step Enable
#>
param(
    [Parameter(Mandatory = $true)][ValidateSet('Install', 'Apache', 'Probe', 'Enable', 'Rollback')][string]$Step,
    [string]$Release = 'psgc-2026q3-ring-candidate-v1',
    [string]$ManifestSha256 = 'd4295476593d0429aa500a09f3352b83e6aa777ef8b38dcd984e702881139002',
    [string]$Source,
    [string]$BhwRoot = 'C:\meridian\bhw',
    [string]$AppPath = 'C:\meridian\test-app',
    [string]$Site = 'https://staging.pitahc.gov.ph',
    [string]$ApacheRoot = 'C:\Apache24',
    [string]$ApacheService = 'Apache2.4',
    [string]$BhwService = 'bhw-connect',
    [int]$ExpectedFiles = 1638
)

$ErrorActionPreference = 'Stop'
# Windows PowerShell 5.1 on the box asks for TLS 1.0 by default; the site needs 1.2, so every web check failed.
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
if ($Release -notmatch '^[a-z0-9][a-z0-9.-]{2,79}$' -or $Release -eq 'latest' -or $Release.Contains('..')) {
    throw "Release '$Release' is not an accepted release name."
}
$MapsRoot = Join-Path $BhwRoot 'maps'
$Dest = Join-Path $MapsRoot $Release
$EnvFile = Join-Path $BhwRoot '.env'
$Conf = Join-Path $ApacheRoot 'conf\extra\meridian.conf'
$Httpd = Join-Path $ApacheRoot 'bin\httpd.exe'

function Test-Url([string]$Url, [int]$Seconds = 60) {
    $deadline = (Get-Date).AddSeconds($Seconds)
    do {
        try {
            $response = Invoke-WebRequest -UseBasicParsing -Uri $Url -TimeoutSec 15 -MaximumRedirection 0
            if ($response.StatusCode -eq 200) { return $true }
        } catch { }
        Start-Sleep -Seconds 3
    } while ((Get-Date) -lt $deadline)
    return $false
}

function Write-Utf8NoBom([string]$Path, [string]$Text) {
    [IO.File]::WriteAllText($Path, $Text, (New-Object System.Text.UTF8Encoding($false)))
}

switch ($Step) {

    'Install' {
        if (-not $Source) { throw 'Give -Source: the folder holding manifest.json, coverage.json and citymun\.' }
        $manifest = Join-Path $Source 'manifest.json'
        if (-not (Test-Path -LiteralPath $manifest)) { throw "$manifest not found." }
        $actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $manifest).Hash.ToLower()
        if ($actual -ne $ManifestSha256.ToLower()) {
            throw "manifest.json fingerprint is $actual, expected $ManifestSha256. Stop and ask the package's builder which package this is."
        }
        if (Test-Path -LiteralPath $Dest) { throw "$Dest already exists. A release folder is never overwritten; use a new release name." }
        New-Item -ItemType Directory -Path $MapsRoot -Force | Out-Null
        Copy-Item -LiteralPath $Source -Destination $Dest -Recurse
        $files = @(Get-ChildItem -LiteralPath $Dest -Recurse -File)
        $bytes = ($files | Measure-Object -Property Length -Sum).Sum
        Write-Host ("Copied {0} files, {1:N0} bytes (the recorded package was 1,638 files, about 114,148,940 bytes)." -f $files.Count, $bytes)
        if ($files.Count -ne $ExpectedFiles) { throw "Expected $ExpectedFiles files, found $($files.Count). Do not continue." }
        $account = (Get-CimInstance Win32_Service -Filter "Name='$ApacheService'").StartName
        if ($account -eq 'LocalSystem') { $account = 'NT AUTHORITY\SYSTEM' }
        & icacls $MapsRoot /grant "${account}:(OI)(CI)RX" | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "icacls failed for $account." }
        Write-Host "Apache runs as $account and can now read $MapsRoot. Next: -Step Apache"
    }

    'Apache' {
        if (-not (Test-Path -LiteralPath (Join-Path $Dest 'manifest.json'))) { throw "Install the package first: $Dest\manifest.json is missing." }
        $text = [IO.File]::ReadAllText($Conf)
        if ($text -match 'bhw-connect/maps') { Write-Host 'The maps route is already in meridian.conf. Nothing changed.'; break }
        $lines = [System.Collections.Generic.List[string]]($text -split "`r?`n")
        $at = -1
        for ($i = 0; $i -lt $lines.Count; $i++) { if ($lines[$i] -match '^\s*ProxyPass\s+/bhw-connect\s') { $at = $i; break } }
        if ($at -lt 0) { throw 'Could not find the "ProxyPass /bhw-connect" line in meridian.conf. Nothing changed.' }
        $indent = ([regex]::Match($lines[$at], '^\s*')).Value
        $mapsDir = ($MapsRoot -replace '\\', '/')
        $block = @(
            'ProxyPass /bhw-connect/maps !',
            "Alias /bhw-connect/maps/ `"$mapsDir/`"",
            "<Directory `"$mapsDir/`">",
            '    Options -Indexes',
            '    AllowOverride None',
            '    Require all granted',
            '    Header set Cache-Control "public, max-age=31536000, immutable"',
            '    SetEnv no-gzip 1',
            '</Directory>'
        ) | ForEach-Object { $indent + $_ }
        $backup = "$Conf.before-barangay-maps." + (Get-Date -Format 'yyyyMMdd-HHmmss')
        Copy-Item -LiteralPath $Conf -Destination $backup
        Write-Host "Backed up to $backup"
        try {
            $lines.InsertRange($at, [string[]]$block)
            Write-Utf8NoBom $Conf (($lines -join "`r`n"))
            & $Httpd -t
            if ($LASTEXITCODE -ne 0) { throw 'httpd -t refused the new configuration.' }
            Restart-Service -Name $ApacheService
            if (-not (Test-Url "$Site/up" 60)) { throw "$Site/up did not answer 200 after the restart." }
            if (-not (Test-Url "$Site/login" 30)) { throw "$Site/login did not answer 200 after the restart." }
            if (-not (Test-Url "$Site/bhw-connect/maps/$Release/manifest.json" 30)) { throw 'The maps route did not serve manifest.json.' }
            Write-Host 'Apache is serving the maps route; Meridian /up and /login answer 200. Next: -Step Probe'
        } catch {
            Write-Host "FAILED: $($_.Exception.Message) Restoring the backup." -ForegroundColor Red
            Copy-Item -LiteralPath $backup -Destination $Conf -Force
            & $Httpd -t
            Restart-Service -Name $ApacheService
            if (-not (Test-Url "$Site/up" 60)) { Write-Host "CHECK THE SITE NOW: $Site/up did not answer after the restore." -ForegroundColor Red }
            throw
        }
    }

    'Probe' {
        $node = Get-Command node -ErrorAction SilentlyContinue
        if (-not $node) { throw 'node is not on PATH here. Run the same probe from any machine that has Node and the package folder:  node scripts\validate-boundary-hosting.mjs <url> <manifest.json> <sha256>' }
        $probe = Join-Path $AppPath 'apps\bhw-connect\scripts\validate-boundary-hosting.mjs'
        if (-not (Test-Path -LiteralPath $probe)) { throw "$probe not found." }
        $manifest = Join-Path $Dest 'manifest.json'
        Write-Host 'Reading all 1,638 files once; this takes a few minutes.'
        & $node.Source $probe "$Site/bhw-connect/maps/$Release/" $manifest $ManifestSha256
        if ($LASTEXITCODE -ne 0) { throw "The hosting check failed (exit $LASTEXITCODE). Do not run -Step Enable. Send me the output." }
        Write-Host 'Hosting check passed. Next: -Step Enable'
    }

    'Enable' {
        if (-not (Test-Path -LiteralPath $EnvFile)) { throw "$EnvFile is missing." }
        $backup = "$EnvFile.before-barangay." + (Get-Date -Format 'yyyyMMdd-HHmmss')
        Copy-Item -LiteralPath $EnvFile -Destination $backup
        $lines = [System.Collections.Generic.List[string]]([IO.File]::ReadAllText($EnvFile) -split "`r?`n")
        $found = $false
        for ($i = 0; $i -lt $lines.Count; $i++) {
            if ($lines[$i] -match '^\s*BHW_BARANGAY_RELEASE\s*=') { $lines[$i] = "BHW_BARANGAY_RELEASE=$Release"; $found = $true }
        }
        if (-not $found) {
            while ($lines.Count -gt 0 -and $lines[$lines.Count - 1] -eq '') { $lines.RemoveAt($lines.Count - 1) }
            $lines.Add("BHW_BARANGAY_RELEASE=$Release")
        }
        Write-Utf8NoBom $EnvFile (($lines -join "`r`n") + "`r`n")
        try {
            Restart-Service -Name $BhwService
            if (-not (Test-Url 'http://127.0.0.1:3100/bhw-connect' 90)) { throw 'BHW did not answer after the restart.' }
            Write-Host "Barangay drill-down is ON for release $Release (NHFR and UUC). Tell me and I will run the live probe."
        } catch {
            Write-Host "FAILED: $($_.Exception.Message) Restoring .env." -ForegroundColor Red
            Copy-Item -LiteralPath $backup -Destination $EnvFile -Force
            Restart-Service -Name $BhwService
            throw
        }
    }

    'Rollback' {
        $backup = "$EnvFile.before-rollback." + (Get-Date -Format 'yyyyMMdd-HHmmss')
        Copy-Item -LiteralPath $EnvFile -Destination $backup
        $kept = [IO.File]::ReadAllText($EnvFile) -split "`r?`n" | Where-Object { $_ -notmatch '^\s*BHW_BARANGAY_RELEASE\s*=' }
        Write-Utf8NoBom $EnvFile (($kept -join "`r`n"))
        Restart-Service -Name $BhwService
        if (-not (Test-Url 'http://127.0.0.1:3100/bhw-connect' 90)) { throw 'BHW did not answer after the restart. Check the service.' }
        Write-Host 'Barangay drill-down is OFF. The package folder and the Apache route stay in place.'
    }
}
