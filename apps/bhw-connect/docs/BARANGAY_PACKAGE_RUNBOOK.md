# Barangay map package — rebuild, install and switch on (operator runbook)

9 November 2026. For the box operator, in an **administrator PowerShell**. Everything here is one step per
command; paste **only the command** (never the `PS C:\>` prompt or earlier output) and send the output of
each step to whoever is helping before running the next. The scripts are untested on the box: they stop at
the first error and check their inputs, but read each message.

**Where this stands.** Barangay drill-down is merged and **off** (`BHW_BARANGAY_RELEASE` unset; see
[the implementation notes](DATASET_MAPS_IMPLEMENTATION.md)). The package it needs is not on the box and not in
git, so it is rebuilt here from the repository's recorded recipe
([ring-preserving build](BARANGAY_RING_RETENTION.md), [packaging](BARANGAY_CANDIDATE_PACKAGING.md)). The owner
has said to treat the boundary acceptance gates as reviewed ([DECISIONS.md](DECISIONS.md), 9 November 2026).

## 0. Get the two scripts onto the box

Open PowerShell **as Administrator** and run these two lines (they download from this repository's `main`;
read the scripts first if you wish, at `apps/bhw-connect/ingestion/barangay-ops/`):

```powershell
Invoke-WebRequest -UseBasicParsing -Uri https://raw.githubusercontent.com/avincentpatrick/dev_formbuilder_app/main/apps/bhw-connect/ingestion/barangay-ops/bhw-barangay-rebuild.ps1 -OutFile C:\meridian\bhw-barangay-rebuild.ps1
Invoke-WebRequest -UseBasicParsing -Uri https://raw.githubusercontent.com/avincentpatrick/dev_formbuilder_app/main/apps/bhw-connect/ingestion/barangay-ops/bhw-barangay-release.ps1 -OutFile C:\meridian\bhw-barangay-release.ps1
```

Then, in **every** new window:

```powershell
cd C:\meridian
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass -Force
```

## 1. Rebuild the package (`bhw-barangay-rebuild.ps1`)

Run each, one at a time, in order. Work files live in `C:\meridian\bhw\barangay-build\` (outside the app and
outside git); each file's fingerprint is stored in its `state.json`, so nothing needs typing in.

| # | Command | What it does | Expect |
| - | --- | --- | --- |
| 1 | `.\bhw-barangay-rebuild.ps1 -Step Check` | Tools, memory (needs ~14 GB), disk, database. Changes nothing. | "All checks passed". If libraries are missing: add `-InstallDeps`. **Stop if memory is too low.** |
| 2 | `.\bhw-barangay-rebuild.ps1 -Step Fetch` | Downloads the ~200 MB pinned source and checks its SHA-256. | "Source verified". A mismatch deletes the file: do not continue. |
| 3 | `.\bhw-barangay-rebuild.ps1 -Step Reference` | Writes the geography list from `bhw_connect.dim_geo` (read only; credentials never printed). | "Reference written". |
| 4 | `.\bhw-barangay-rebuild.ps1 -Step Rings` | Splits the source into protected rings. | "Rings prepared". |
| 5 | `.\bhw-barangay-rebuild.ps1 -Step Simplify` | Mapshaper 0.6.113, 10%, keep-shapes. **Tens of minutes, silent, ~12 GB.** Leave the window open. | "Simplified". |
| 6 | `.\bhw-barangay-rebuild.ps1 -Step Assemble` | Rebuilds barangays from rings. | "Exit 1 … withheld" is **expected** (about 5). |
| 7 | `.\bhw-barangay-rebuild.ps1 -Step Validate` | Retention and overlap checks. | "Exit 1 … blocking checks" is **expected**, as before. |
| 8 | `.\bhw-barangay-rebuild.ps1 -Step Package` | Builds and verifies the municipality package. | A green **PACKAGE BUILT AND VERIFIED** block: release, folder, **manifest fingerprint**, file count, and the exact install command. |

`-Step Status` shows progress. To retry a step, delete the file or folder named in its error. **Send the
four lines from step 8** (release, folder, fingerprint, file count) so the new fingerprint can be recorded.

The new fingerprint will differ from the one in `ingestion/barangay_package_summary.json`: the package records
the geography list it was built against (now the box's database) and its release name. The install script
checks the folder against the fingerprint just built, which is what that check is for.

## 2. Install it (`bhw-barangay-release.ps1`)

Use the command that step 8 printed, which has this shape (your values will differ):

```powershell
cd C:\meridian
.\bhw-barangay-release.ps1 -Step Install -Release <release> -ManifestSha256 <fingerprint> -ExpectedFiles <count> -Source C:\meridian\bhw\barangay-build\package
```

It refuses an existing release folder, copies to `C:\meridian\bhw\maps\<release>\`, checks the file count and
lets Apache's service account read it. Then, **choosing a moment when a short Apache restart is acceptable**:

```powershell
.\bhw-barangay-release.ps1 -Step Apache -Release <release>
```

It backs up `meridian.conf`, adds the `/bhw-connect/maps` route before `ProxyPass /bhw-connect`, runs
`httpd -t`, restarts Apache and checks `/up`, `/login` and the package manifest answer 200. **It restores the
backup itself if anything fails** — send the message if it does.

## 3. Prove delivery, then switch on

```powershell
.\bhw-barangay-release.ps1 -Step Probe -Release <release> -ManifestSha256 <fingerprint>
```

It reads every file once through the real route (checksums, immutable caching, JSON type, no compression,
304, true 404). **Only if it says the hosting check passed:**

```powershell
.\bhw-barangay-release.ps1 -Step Enable -Release <release>
```

That sets `BHW_BARANGAY_RELEASE` in `C:\meridian\bhw\.env` (backed up first, never printed) and restarts
`bhw-connect`, restoring `.env` itself if the service does not return. **Tell whoever is helping**: the next
check is a read-only probe of one municipality each for NHFR and UUC (drill-down is enabled for those two
only; whether the live database has barangay rows for them is unknown until then).

## Rollback

```powershell
.\bhw-barangay-release.ps1 -Step Rollback
```

Removes the setting and restarts: barangay drill-down is off again. The package and the Apache route stay.
To undo the Apache route, restore `meridian.conf.before-barangay-maps.<time>` beside it, run
`C:\Apache24\bin\httpd.exe -t`, then restart the `Apache2.4` service and recheck `/up` and `/login`.

## If a zip of the original package turns up instead

Unzip it, find the folder that holds `manifest.json`, and run step 2 with
`-ManifestSha256 d4295476593d0429aa500a09f3352b83e6aa777ef8b38dcd984e702881139002 -Release
psgc-2026q3-ring-candidate-v1`. Skip step 1.
