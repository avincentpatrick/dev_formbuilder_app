#Requires -Version 5.1
<#
.SYNOPSIS
  Meridian production deploy for the self-hosted Windows Server (ADR-0005, Doc #22 §3).

.DESCRIPTION
  Invoked by the self-hosted GitHub Actions runner after CI passes on `main`
  (.github/workflows/deploy.yml), or run manually from an elevated Windows PowerShell
  on the server. It syncs the live checkout to origin/<branch>, rebuilds, migrates inside
  a brief maintenance window, and cycles the Horizon/Reverb Windows services.
#>
[CmdletBinding()]
param(
    [string]$AppPath = $(if ($env:MERIDIAN_APP_PATH) { $env:MERIDIAN_APP_PATH } else { 'C:\meridian\app' }),
    [string]$Branch = 'main'
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $AppPath)) {
    throw "App path not found: $AppPath (set MERIDIAN_APP_PATH or pass -AppPath)."
}

Write-Host "==> Deploying '$Branch' into $AppPath"
Set-Location $AppPath

# 1. Match the server to the remote exactly (deterministic; discards any local drift).
git fetch --all --prune
git reset --hard "origin/$Branch"

# 2. Production PHP dependencies.
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# 3. Front-end assets + design-system tokens.
npm ci
npm run ds:install
npm run ds:tokens
npm run build

# 4. Brief maintenance window around schema + cache changes.
php artisan down --retry=15
try {
    php artisan migrate --force
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache

    # 5. Cycle workers/realtime so they load the new code (NSSM service names, Doc #22 §8).
    foreach ($svc in @('meridian-horizon', 'meridian-reverb')) {
        if (Get-Service -Name $svc -ErrorAction SilentlyContinue) {
            Restart-Service -Name $svc -Force
        }
    }
}
finally {
    php artisan up
}

Write-Host "==> Deploy complete at $(git rev-parse --short HEAD)"
