# Vincent: unblock deployment of the CSV extractor fix

**Requested by jongsky25. Status recorded 2026-11-14.** This is a deployment handoff, not a request to change Meridian's merge order or bypass its security checks.

## Current situation

Jong's `python-verification.csv` is the expected **30 bytes** and contains five synthetic rows. Upload succeeded, but the Node attachment extractor failed before Python analysis could start. The assistant's suggestion that the file was nearly empty/corrupt was unsupported.

The application fix is already merged:

- [BHW fix PR #522](https://github.com/avincentpatrick/dev_formbuilder_app/pull/522).
- Fix commit: `3c556b98eea547f00a77ecb6071badb8c82ab533`.
- [PR CI passed](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/43593555583): 1,895 unit/database tests, 56 browser tests, both production builds and detached-release extraction checks. Thirteen optional/known tests were skipped.
- [Main CI failed](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/43593958210) at **Static analysis, style & security → Composer audit (SCA)**.
- [Deployment was skipped](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/43597102882). The last successful BHW release therefore does not yet establish delivery of #522.

The separate [Python verification report](https://github.com/avincentpatrick/dev_formbuilder_app/pull/515#issuecomment-6856073570) remains useful: Python 3.12.10, pinned numerical packages and all 15 reference tests passed under NetworkService. This fix repairs the Node attachment extractor used before those calculations.

## 1. Resolve the existing Meridian audit at your authorised merge point

Your [PR #520](https://github.com/avincentpatrick/dev_formbuilder_app/pull/520) addresses the reported Laravel advisory, **CVE-2026-128083 / GHSA-63hm-g96r-2m2q**, by updating `laravel/framework` to 13.42.0 and handling the associated console-command typing changes. Its recorded required checks passed.

Its PR description explicitly says it is **held for the next Meridian merge point**, ahead of #519 and the stacked work. Please review its current state and follow that ownership/merge-point plan. This BHW guide does not authorise merging held Meridian work early, nor merging the other feature PRs merely to deploy BHW.

At the appropriate point:

1. Confirm #520's current code, review and required checks are valid against the current trunk. Reconcile any drift through the normal process.
2. Land the approved audit remediation, or another reviewed fix if the advisory/current failure has changed.
3. Require a successful **new main CI run containing both the remediation and BHW commit #522**.

Retain the existing Composer audit and deployment conditions. Do not disable/ignore the advisory, weaken CI, or substitute an unchecked manual deployment. The BHW change needs no edits to shared workflows or `ops/`.

## 2. Let the successful main run trigger the normal deployment

`Deploy (production, self-hosted)` is triggered by completion of CI on `main`. Its deployment job requires the triggering CI conclusion to be `success` and `DEPLOY_ENABLED=true`.

Rerunning the old skipped deploy alone will not fix it: that run is associated with failed CI. Use the new successful main run and its resulting deploy.

Follow the deploy logs through the **BHW** step, not just Meridian's successful step. BHW's script compares its recorded deployed revision with the new checkout, so it should pick up the merged #522 changes when it builds the next release. Confirm that it actually builds/swaps the BHW release and that its health check passes.

In the BHW `npm run build` output, require this new postbuild result:

```text
Standalone extractor verified outside checkout through a release junction: CSV, XLSX, PDF, GeoJSON.
```

That check copies the built release outside the repository and invokes it through a temporary `current` directory junction. It proves that the extractor can start without borrowing development dependencies from the checkout. A successful parser run directly inside the checkout is insufficient evidence for this particular defect.

If the deployment or this check fails, retain the existing rollback/health-check behavior and report the failed step. Do not ask Jong to retry against an unverified release.

## 3. Confirm the active Windows release contains the fix

Use the actual repository path from the deployment configuration. Your previous verification report identified `C:\meridian\test-app`; confirm that it is still correct.

Run in PowerShell with appropriate operator permissions:

```powershell
$ErrorActionPreference = 'Stop'
$repo = 'C:\meridian\test-app' # Confirm against the actual deployment configuration.
$app = Join-Path $repo 'apps\bhw-connect'
$current = 'C:\meridian\bhw\current'
$fix = '3c556b98eea547f00a77ecb6071badb8c82ab533'

$target = (Get-Item -LiteralPath $current).Target | Select-Object -First 1
if (-not $target) { throw 'Could not read the current release junction target.' }
$releaseSha = Split-Path -Path $target -Leaf
$recordedSha = (Get-Content -LiteralPath 'C:\meridian\bhw\deployed-sha' -Raw).Trim()

Write-Output "Current release target: $target"
Write-Output "Recorded BHW deployed revision: $recordedSha"

& git -C $repo merge-base --is-ancestor $fix $releaseSha
if ($LASTEXITCODE -ne 0) { throw 'The active release does not establish inclusion of #522.' }

& git -C $repo merge-base --is-ancestor $fix $recordedSha
if ($LASTEXITCODE -ne 0) { throw 'The recorded deployed revision does not contain #522.' }

Get-Item -LiteralPath "$current\analysis-extractor.mjs"
Get-Item -LiteralPath "$current\node_modules\exceljs\package.json"
Get-Service -Name 'bhw-connect', 'bhw-analysis-worker' |
    Select-Object Name, Status
```

A docs-only deployment can advance `deployed-sha` while keeping an earlier release junction. That is why both are checked independently for inclusion of #522. A descendant revision is fine; it need not equal the fix commit exactly. If repository history is shallow/missing, fetch the required history normally and repeat the ancestry check before treating it as passed.

The missing `exceljs` package was one reproducible defect. Next previously bundled it into its own server chunks while the extraction sidecar needed the native package and dependency closure. #522 explicitly traces it into standalone output. Another defect skipped CLI execution through a directory junction; the entry-path comparison now uses `realpath`.

If the full checkout's build artifacts are retained, the detached check can also be rerun there using the same service identity as deployment:

```powershell
Push-Location $app
try {
    & node.exe scripts/verify-analysis-extractor.mjs
    if ($LASTEXITCODE -ne 0) { throw 'Detached standalone extractor verification failed.' }
}
finally {
    Pop-Location
}
```

Use the actual installed Node 24 executable if `node.exe` is not on that account's PATH. This command needs the full checkout, its installed build dependencies and the matching `.next\standalone` artifacts. The verifier is a build-time script; do not assume it is installed in the active standalone release. The automatic successful build log is the primary deployment evidence.

## 4. Check the existing services and preserve their configuration

The existing worker dispatch command remains:

```text
node.exe --env-file=C:\meridian\bhw\.env C:\meridian\bhw\current\scripts\analysis-worker.mjs
```

Your choices to run the worker from `C:\meridian\bhw` and avoid a hard service dependency on `bhw-connect` are consistent with release pruning and web-service restarts. Preserve those settings. The worker should retry loopback dispatch while the web service restarts.

The #522 patch runs in the web service/extraction sidecar; it does not change the dispatcher script. If `analysis-worker.mjs` changes in a future release, the separate worker needs an operator restart because current deploy scripts restart only the web service:

```powershell
Restart-Service -Name 'bhw-analysis-worker'
```

Keep `BHW_STATISTICS_PYTHON` and the verified Python environment in place. This patch introduces no new environment variable, converter, database migration or service. Existing private-original storage ACLs/backups continue to apply.

If an extraction still fails, the revised handler emits `analysis_source_extraction_failed` with a source ID and safe failure code, and stores a more specific user-facing message. Use that code and the actual service logs to investigate; do not infer file corruption from its small size. Keep private contents, environment files and credentials out of comments/log excerpts shared publicly.

## 5. Hand the signed-in retry back to Jong

After the correct release is live and the services/checks pass, tell Jong the fix is deployed. His existing private project already holds the CSV; administrator status does not grant access to another user's private source.

Jong's first action is:

1. Open the existing project.
2. Expand `python-verification.csv` under **Project sources**.
3. Tap **Retry extraction** and wait for **ready**.

The original is retained, so another upload is unnecessary. Retrying the analysis question alone does not necessarily retry a failed source extraction.

Once the source is ready, submit the synthetic census-description request from [the Python verification guide](PYTHON_WORKER_VERIFICATION_FOR_VINCENT.md). Expected: input rows **5**, valid count **4**, missing count **1**, mean **5**, median **5**, sample SD **2.582**, and no census sampling p-value. Then complete background/browser-close, export, private-access and exact-replay checks in that guide. Those production checks remain outstanding; passing CI does not replace them.

## Return this status

```text
Audit-remediation PR/revision and authorised merge point:
Successful main CI run:
Successful deployment run:
Active BHW release target/revision contains #522:
Recorded deployed revision contains #522:
Detached CSV/XLSX/PDF/GeoJSON postbuild check passed:
exceljs package present in active release:
BHW web and analysis-worker service status:
Any safe extractor failure codes / remaining blockers:
Ready for Jong to tap Retry extraction: yes/no
```
