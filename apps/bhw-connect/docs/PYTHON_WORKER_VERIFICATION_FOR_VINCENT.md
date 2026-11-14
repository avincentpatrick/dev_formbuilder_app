# Vincent: install and verify the Ask the Data Python runtime

**For:** avincentpatrick, BHW server operator. **Requested by:** jongsky25.

Phase 4 was merged in [PR #513](https://github.com/avincentpatrick/dev_formbuilder_app/pull/513), commit `dad2001c03d777b02c723cbc936ede34f148d7e2`. Merging does not prove that the server has Python configured. Complete this guide after that release is deployed, before calling statistical execution operational.

All examples use synthetic data. This guide changes no shared deployment scripts, Meridian services, database schema or AI credentials.

## What must be verified

BHW runs on Windows Server 2016 with Node 24. Its Node web process launches a fixed Python script for calculations. The separate Node analysis worker dispatches queued jobs to that web process; it is **not** a persistent Python service.

The reference environment passed 15 numerical tests on Linux using:

| Component | Reference version |
| --- | --- |
| Python | 3.12, 64-bit |
| NumPy | 2.3.5 |
| SciPy | 1.17.0 |
| pandas | 2.2.3 |
| statsmodels | 0.14.6 |

Windows compatibility remains unverified. A successful installation is only the first check: native imports, numerical tests and execution under the actual Windows service identity must also pass. If the pinned wheels/runtime cannot run on this OS, leave calculations disabled and report the failure; do not silently downgrade packages or change the method code. A supported separate worker host would need another reviewed deployment change.

## 1. Confirm the deployed files and service identity

The normal BHW locations are:

- Active release junction: `C:\meridian\bhw\current`.
- Persistent environment file: `C:\meridian\bhw\.env`.
- Proposed persistent Python environment: `C:\meridian\bhw\python-stats`.
- BHW web service: `bhw-connect`, listening on loopback port 3100.

Inspect the actual identity and release target in PowerShell:

```powershell
Get-CimInstance Win32_Service -Filter "Name='bhw-connect'" |
    Select-Object Name, State, StartName, PathName
Get-Item C:\meridian\bhw\current | Select-Object FullName, Target
```

Use the installed configuration as authoritative. Do not paste an environment file or credentials into a ticket. The reference checks below must run under the web service's `StartName`, since that process launches Python. If the separate Node analysis worker uses a different account, verify its own service/file access too.

The active release must contain:

```text
scripts\statistics\engine.py
scripts\statistics\requirements.txt
scripts\statistics\test_engine.py
scripts\analysis-worker.mjs
analysis-extractor.mjs
lib\exports\fonts\DejaVuSans.ttf
lib\exports\fonts\DejaVuSans-Bold.ttf
```

The reference test also reads `docs\fixtures\ask-the-data-phase-0.json`. That fixture is in the **full source checkout**, and is not guaranteed to be in Next's standalone release. Run the reference tests from a complete checkout/download of the deployed revision, not an incomplete copied test script.

## 2. Install a dedicated Python environment

Use a trusted, supported 64-bit Python 3.12 distribution and verify its Windows Server 2016 support. Select an appropriate maintained patch release; the reference does not establish support for a particular Windows installer. If the Python launcher is unavailable, replace `py -3.12` with the absolute installed interpreter path.

Run installation with the necessary operator permissions. Replace `$src` with the actual full checkout/download of the deployed revision. For the first Phase 4 release, use commit `dad2001c03d777b02c723cbc936ede34f148d7e2`.

```powershell
$ErrorActionPreference = 'Stop'
$src = 'C:\path\to\repo\apps\bhw-connect'
$release = 'C:\meridian\bhw\current'
$venv = 'C:\meridian\bhw\python-stats'
$python = Join-Path $venv 'Scripts\python.exe'

# Use a new environment; do not overwrite an existing managed environment blindly.
if (Test-Path $venv) { throw 'Environment already exists: inspect it before proceeding.' }
py -3.12 -m venv $venv
if ($LASTEXITCODE -ne 0) { throw 'Python environment creation failed.' }

& $python -m pip install --only-binary=:all: -r "$src\scripts\statistics\requirements.txt"
if ($LASTEXITCODE -ne 0) { throw 'Pinned binary package installation failed.' }

& $python -m pip check
if ($LASTEXITCODE -ne 0) { throw 'Dependency consistency check failed.' }
```

`--only-binary=:all:` deliberately refuses unexpected source builds. An unavailable wheel or DLL/runtime failure is a compatibility issue to investigate, not a successful installation. Keep this environment outside versioned releases so it survives a deployment switch. The service identity needs read/execute access to the interpreter/packages, read access to the active scripts, and write/delete access to its own temporary directory. It does not need to install packages while serving requests.

## 3. Verify imports and all reference calculations as the service account

Open a controlled shell or use the operator's normal service-account task mechanism under the actual BHW web identity. An Administrator-only test is insufficient. Do not change the service to run as Administrator just to make the test pass.

Define `$src`, `$release` and `$python` again in that shell, then run:

```powershell
$ErrorActionPreference = 'Stop'
$src = 'C:\path\to\repo\apps\bhw-connect'
$release = 'C:\meridian\bhw\current'
$python = 'C:\meridian\bhw\python-stats\Scripts\python.exe'

whoami
& $python -I -c "import sys, struct, numpy, scipy, pandas, statsmodels; print(sys.executable); print(sys.version); print('bits:', struct.calcsize('P') * 8); print('numpy:', numpy.__version__); print('scipy:', scipy.__version__); print('pandas:', pandas.__version__); print('statsmodels:', statsmodels.__version__)"
if ($LASTEXITCODE -ne 0) { throw 'Native package imports failed under the service account.' }

& $python -m pip check
if ($LASTEXITCODE -ne 0) { throw 'Dependency check failed.' }

# Confirm that the tested code/packages match the active release.
foreach ($name in @('engine.py', 'requirements.txt', 'test_engine.py')) {
    $sourceHash = (Get-FileHash "$src\scripts\statistics\$name" -Algorithm SHA256).Hash
    $releaseHash = (Get-FileHash "$release\scripts\statistics\$name" -Algorithm SHA256).Hash
    if ($sourceHash -ne $releaseHash) { throw "Source/release mismatch: $name" }
}

& $python -I "$src\scripts\statistics\test_engine.py"
if ($LASTEXITCODE -ne 0) { throw 'Statistical reference tests failed.' }
```

Expected output: the intended interpreter path, 64 bits, the pinned package versions, `No broken requirements found`, and:

```text
Ran 15 tests ...
OK
```

The tests cover complete-data versus truncated-preview bias, independent arithmetic, known regression results, supported method families, exact/permutation references, repeatability and rejection of unsupported designs. A Python version banner alone does not establish numerical compatibility.

## 4. Enable the interpreter and verify the Node worker

Only after the preceding checks pass, add or update this one server-owned setting in `C:\meridian\bhw\.env`:

```text
BHW_STATISTICS_PYTHON=C:\meridian\bhw\python-stats\Scripts\python.exe
```

Do not put it in a `NEXT_PUBLIC_` variable. This is an executable path, not a provider credential, and it is not currently a GitHub-managed setting.

Use the existing BHW service configuration to reload the environment and restart **the BHW web service**. Confirm the separate automatically restarted Node analysis worker is installed and running, and restart it if its configuration needs reloading. Restarting only the dispatcher does not refresh the web process's Python setting. Leave Meridian services unchanged.

The separate worker uses the existing `CRON_SECRET` from the same protected environment file. Its normal command is:

```powershell
# Replace node.exe with the actual installed Node 24 executable in the service configuration.
node --env-file=C:\meridian\bhw\.env C:\meridian\bhw\current\scripts\analysis-worker.mjs
```

Configure that as the existing operator-managed Windows service, not an unattended interactive terminal. The default dispatch address is `http://127.0.0.1:3100/bhw-connect/api/analysis/worker`; any override must remain loopback and include the app base path. Never copy the secret into command-line arguments or logs. See [project/worker operations](ASK_THE_DATA_PROJECTS_OPERATIONS.md) for installation/restart/recovery details.

Calculations run as fixed script invocations using Python `-I` and a reduced environment, with a 120-second step timeout. The application smoke test is necessary because a successful interactive shell import does not establish that this Node-to-Python path works.

## 5. Test the application with a synthetic CSV

Sign in as an approved Ask the Data user. Create a private project and upload `python-verification.csv` with exactly:

```csv
area,count
A,2
B,4
C,6
D,8
E,
```

The last row deliberately has a missing count. Wait for extraction status **ready**; a permanently queued upload suggests the separate Node worker is unavailable.

Ask:

> Describe the count column in this complete synthetic census. Each row is one area and the measurement unit is people. Include the valid sample size, missing count, mean, median and sample SD, with a scientific table, figure, sources and explanatory notes. Do not infer sampling p-values from this census.

Verify the calculated table and narrative:

| Quantity | Expected |
| --- | --- |
| Input rows | 5 |
| Valid count, n | 4 |
| Missing count | 1 |
| Mean | 5 |
| Median | 5 |
| Sample SD, using n−1 | 2.581988897471611; displayed 2.582 |
| Sampling p-value | Not claimed for this census description |

Also check that titles, units, source and method/missingness notes are present. Confirm that this is a calculated statistical result with export and replay controls; a general chat answer alone is not enough.

Then:

1. Close the browser after submitting a fresh test job; reopen it and confirm the background job still finishes and the result is saved.
2. Download CSV, XLSX, SVG, PNG and the calculation JSON. Excel estimates should be numeric cells; PNG should be 3,300 pixels wide; JSON should contain the full five input rows and the parameters/software versions.
3. Click **Re-run saved calculation**. Expect **Calculation reproduced exactly.** A changed hash needs investigation, even if the displayed rounded values look the same.
4. Check a shared viewer can read/download the result. An unshared approved account and a signed-out browser must not retrieve the private export URLs.
5. Delete the synthetic project and confirm its export/replay URLs are no longer accessible.

The application uses existing AI keys for automatic tool selection. If a provider outage prevents that initial selection, the UI test is incomplete: direct Python tests alone do not prove the full application path. Do not expose or replace provider secrets while diagnosing it.

## 6. Record the outcome or disable calculations

Report these facts back to jongsky25, with credentials and private data omitted:

```text
Deployed BHW revision/release:
Windows version:
BHW service account and separate worker status:
Python executable, version and architecture:
NumPy / SciPy / pandas / statsmodels versions:
pip check result:
Source/release script hash comparison:
Reference tests: 15 passed / failure details:
CSV result: rows 5; valid 4; missing 1; mean 5; median 5; sample SD 2.582:
Browser-close background completion:
Saved replay reproduced exactly:
CSV/XLSX/SVG/PNG/JSON and private-access checks:
Any remaining failures:
```

If verification fails, leave `BHW_STATISTICS_PYTHON` unset, or remove/empty that setting if it was enabled, then reload the BHW web service environment. Calculations will explicitly report unavailable; saved results remain readable under their existing permissions. Replays need a working calculation runtime. Preserve the useful error/test output without publishing credentials.

This is separate from Phase 3's private-originals backup/ACL requirements and optional Brave search/legacy Office converter setup. Those remain in [source operations](ASK_THE_DATA_SOURCES_OPERATIONS.md). Full scientific method limits are in [statistics operations](ASK_THE_DATA_STATISTICS_OPERATIONS.md).
