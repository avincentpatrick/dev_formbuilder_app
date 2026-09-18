<#
.SYNOPSIS
    Activate a certificate that mod_md has renewed but cannot install by itself.

.DESCRIPTION
    ON WINDOWS mod_md CAN NEVER ACTIVATE A RENEWED CERTIFICATE. md_server_graceful() is
    APR_ENOTIMPL on WIN32 and has no caller anywhere in the module, so staged -> live promotion
    happens only in md_reg_load_stagings(), which is called from exactly one place:
    md_post_config_before_ssl() -- that is, an Apache restart. A renewed certificate therefore
    sits in md\staging\<domain>\ for ever while the served one expires, and every visitor meets
    a browser trust error on a .gov.ph address.

    This script is the restart mod_md cannot perform. Run it daily from Task Scheduler. It must
    exist REGARDLESS of whether any renewal has succeeded yet: gating it behind a success is the
    trap that leaves a valid certificate on disk while testers meet an interstitial.

    It restarts Apache ONLY when a certificate is actually staged, REFUSES to restart when the
    configuration does not test clean, and PROVES activation by re-reading the served
    certificate afterwards rather than trusting the restart's exit code.

.NOTES
    THE STAGED FILENAME CARRIES THE KEY TYPE. With "MDPrivateKeys secp256r1" in force, mod_md
    writes pubcert.secp256r1.pem, not pubcert.pem. The predecessor of this script tested for the
    exact name pubcert.pem, so its activation arm was dead code from the moment the key-type fix
    landed -- while still exiting 0 and logging success every day. The glob below is
    load-bearing; do not narrow it to an exact filename.

    PowerShell 5.1 on Windows Server 2016: no && or ||, no ternary, no null-coalescing. A native
    executable's stderr is NEVER redirected inline, because 5.1 wraps each line in an ErrorRecord
    and falsifies $? even on exit 0 -- httpd is run through Start-Process with file redirection
    instead. TLS is pinned explicitly for the read-back, because the parameterless
    AuthenticateAsClient overload negotiates the host's defaults.

.PARAMETER StagingRoot
    Overrides mod_md's staging root. Exists so the positive arm can be proved against a
    temporary directory without ever handing mod_md a certificate it did not mint.

.PARAMETER ConfigFile
    Overrides the config that "httpd -t" reads. Exists so the refusal arm can be proved against
    a deliberately malformed temporary file without touching the real configuration.

    Exit codes:
      0  nothing staged, or a staged certificate was activated and the activation was proved
      1  an unexpected error
      2  a certificate was staged but "httpd -t" failed, so the restart was REFUSED
      3  Apache was restarted but the activation could not be proved
#>
[CmdletBinding()]
param(
    [string] $ApacheRoot  = 'C:\Apache24',
    [string] $Domain      = 'staging.pitahc.gov.ph',
    [string] $ServiceName = 'Apache2.4',
    [string] $StagingRoot = '',
    [string] $ConfigFile  = '',
    [string] $LogPath     = 'C:\meridian\certificate-activation.log',
    [switch] $DryRun
)

$ErrorActionPreference = 'Stop'

function Write-ActivationLog {
    param([string] $Level, [string] $Message)

    $line = '{0:yyyy-MM-dd HH:mm:ss}  {1,-9} {2}' -f (Get-Date), $Level, $Message
    Write-Output $line

    if ([string]::IsNullOrWhiteSpace($LogPath)) { return }
    try {
        $dir = Split-Path -Parent $LogPath
        if ($dir -and -not (Test-Path -LiteralPath $dir)) {
            New-Item -ItemType Directory -Path $dir -Force | Out-Null
        }
        Add-Content -LiteralPath $LogPath -Value $line -Encoding utf8
    } catch {
        Write-Output ('  (could not write the log: ' + $_.Exception.Message + ')')
    }
}

function Get-ServedCertificate {
    # Reads the certificate Apache is serving RIGHT NOW, over the loopback with SNI. The hosts
    # file points both names at 127.0.0.1 because the network does not hairpin, so the literal
    # loopback address plus an explicit SNI name is the only form that works on this box.
    param([string] $SniName, [int] $Port = 443)

    $tcp = $null
    $ssl = $null
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        $tcp = New-Object System.Net.Sockets.TcpClient
        $tcp.Connect('127.0.0.1', $Port)
        $accept = { param($sender, $certificate, $chain, $errors) return $true }
        $ssl = New-Object System.Net.Security.SslStream($tcp.GetStream(), $false, $accept)
        $ssl.AuthenticateAsClient($SniName, $null, [Security.Authentication.SslProtocols]::Tls12, $false)
        $cert = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2($ssl.RemoteCertificate)
        return [pscustomobject]@{
            Serial   = $cert.SerialNumber
            NotAfter = $cert.NotAfter
            Subject  = $cert.Subject
        }
    } catch {
        return $null
    } finally {
        if ($ssl) { $ssl.Dispose() }
        if ($tcp) { $tcp.Close() }
    }
}

function Format-Certificate {
    param($Certificate)

    if ($null -eq $Certificate) { return '<could not read the served certificate>' }
    $days = [math]::Floor(($Certificate.NotAfter - (Get-Date)).TotalDays)
    return ('serial {0}, expires {1:yyyy-MM-dd HH:mm:ss} ({2} days left)' -f $Certificate.Serial, $Certificate.NotAfter, $days)
}

try {
    if ([string]::IsNullOrWhiteSpace($StagingRoot)) {
        $StagingRoot = Join-Path $ApacheRoot 'md\staging'
    }
    $stageDir = Join-Path $StagingRoot $Domain

    # A GLOB, NOT AN EXACT NAME. See .NOTES -- the exact-name test is what made the predecessor's
    # activation arm unreachable while every signal stayed green.
    $staged = @()
    if (Test-Path -LiteralPath $stageDir) {
        $staged = @(Get-ChildItem -LiteralPath $stageDir -Filter 'pubcert*.pem' -File -ErrorAction SilentlyContinue)
    }

    $served = Get-ServedCertificate -SniName $Domain

    if ($staged.Count -eq 0) {
        Write-ActivationLog 'OK' ('nothing staged in ' + $stageDir + ' - no restart needed. Serving ' + (Format-Certificate $served))
        exit 0
    }

    $names = ($staged | ForEach-Object { $_.Name }) -join ', '
    Write-ActivationLog 'STAGED' ([string] $staged.Count + ' certificate file(s) waiting in ' + $stageDir + ': ' + $names)
    Write-ActivationLog 'SERVING' ('before the restart: ' + (Format-Certificate $served))

    # --- the guard ---------------------------------------------------------------------------
    # Never restart against a configuration that does not test clean: Restart-Service would stop
    # Apache and then fail to start it, leaving the site down behind a trust error until someone
    # notices.
    $httpd = Join-Path $ApacheRoot 'bin\httpd.exe'
    if (-not (Test-Path -LiteralPath $httpd)) {
        Write-ActivationLog 'ERROR' ('httpd.exe not found at ' + $httpd + ' - refusing to restart')
        exit 1
    }

    $testArgs = @('-t')
    if (-not [string]::IsNullOrWhiteSpace($ConfigFile)) {
        $testArgs = @('-t', '-f', $ConfigFile)
    }

    $outFile = [IO.Path]::GetTempFileName()
    $errFile = [IO.Path]::GetTempFileName()
    $testExit = 1
    $testText = ''
    try {
        $proc = Start-Process -FilePath $httpd -ArgumentList $testArgs -NoNewWindow -Wait -PassThru -RedirectStandardOutput $outFile -RedirectStandardError $errFile
        $testExit = $proc.ExitCode
        $errText = Get-Content -LiteralPath $errFile -Raw -ErrorAction SilentlyContinue
        $stdText = Get-Content -LiteralPath $outFile -Raw -ErrorAction SilentlyContinue
        $testText = ([string] $errText + ' ' + [string] $stdText).Trim()
    } finally {
        Remove-Item -LiteralPath $outFile -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $errFile -Force -ErrorAction SilentlyContinue
    }

    if ($testExit -ne 0) {
        Write-ActivationLog 'REFUSED' ('httpd -t exited ' + $testExit + ' - NOT restarting. ' + $testText)
        exit 2
    }
    Write-ActivationLog 'CONFIG' ('httpd -t exited 0 (' + $testText + ')')

    if ($DryRun) {
        Write-ActivationLog 'DRYRUN' ('would restart ' + $ServiceName + ' now - stopping here because -DryRun was given')
        exit 0
    }

    # --- the restart mod_md cannot perform -----------------------------------------------------
    Write-ActivationLog 'RESTART' ('restarting ' + $ServiceName + ' to promote the staged certificate')
    Restart-Service -Name $ServiceName -Force

    # --- the proof -----------------------------------------------------------------------------
    # The restart's exit code says a service came back, never that a certificate was activated.
    # Re-read what Apache is actually serving.
    $after = $null
    for ($attempt = 1; $attempt -le 15; $attempt++) {
        $after = Get-ServedCertificate -SniName $Domain
        if ($null -ne $after) { break }
        Start-Sleep -Seconds 2
    }

    $stagedNow = @()
    if (Test-Path -LiteralPath $stageDir) {
        $stagedNow = @(Get-ChildItem -LiteralPath $stageDir -Filter 'pubcert*.pem' -File -ErrorAction SilentlyContinue)
    }

    if ($null -eq $after) {
        Write-ActivationLog 'FAILED' 'Apache was restarted but the served certificate could not be read back - CHECK THE SITE NOW'
        exit 3
    }

    $serialMoved = $true
    if ($null -ne $served) {
        $serialMoved = ($after.Serial -ne $served.Serial)
    }

    if ($serialMoved) {
        Write-ActivationLog 'ACTIVATED' ('now serving ' + (Format-Certificate $after))
        exit 0
    }

    if ($stagedNow.Count -eq 0) {
        Write-ActivationLog 'ACTIVATED' ('staging is now empty and the served certificate is unchanged, so mod_md had already promoted it: ' + (Format-Certificate $after))
        exit 0
    }

    Write-ActivationLog 'FAILED' ('restarted, but the served serial did not move and ' + [string] $stagedNow.Count + ' file(s) remain staged. Still serving ' + (Format-Certificate $after))
    exit 3

} catch {
    Write-ActivationLog 'ERROR' ($_.Exception.GetType().Name + ': ' + $_.Exception.Message)
    exit 1
}
