# FHSIS 2025 — Vincent’s server-loading instructions

This is the single operator guide for the **31-batch consolidated package**. Follow it from a
full BHW Connect checkout. All actual payloads and source data remain private in Drive.

## Download and authorization

[Open the private loading folder](https://drive.google.com/drive/folders/1e-HNkNIFo0QjOTJuo4sC1zA0jVyYJN1X).
It contains the ZIP and its SHA256 file. The owner should give Vincent Viewer access to this folder;
public access is not required. Moving the files into this folder did not change their bytes or IDs.

- ZIP: `fhsis-31-bundles-pregnant-oral-HOLD-2026-11-08.zip`
- Expected ZIP SHA256: `4848112a75a62bcb8ed694effcb422a58cdf034cacf0cb987e9571ee79d378b0`
- Required code: [PR #473](https://github.com/avincentpatrick/dev_formbuilder_app/pull/473), commit
  `285e4103c600ced643b504cf1676986cde7af9d7`, or a descendant.

**The archive is marked HOLD.** Download, verify and dry-run first; apply only after the owner
confirms the server-load go-ahead. Receipt of this document is not an instruction to apply.
Abortion/miscarriage figures are intentionally excluded. ZOD and unresolved rates remain excluded.
This package supersedes earlier packages; do not mix them or load more than one version.

## Verify and extract

Use Node 24 and the dependencies matching the checkout. Keep downloaded/extracted files outside
any public repository. Adjust the private paths below if necessary; use a fresh extraction directory.

```powershell
$ErrorActionPreference = 'Stop'
$zip = 'C:\private\fhsis-31-bundles-pregnant-oral-HOLD-2026-11-08.zip'
$expected = '4848112a75a62bcb8ed694effcb422a58cdf034cacf0cb987e9571ee79d378b0'
$actual = (Get-FileHash $zip -Algorithm SHA256).Hash.ToLowerInvariant()
if ($actual -ne $expected) { throw 'ZIP checksum mismatch. Stop.' }
$extract = 'C:\private\fhsis-31-batches'
if (Test-Path $extract) { throw 'Use a fresh extraction directory.' }
Expand-Archive -LiteralPath $zip -DestinationPath $extract
$package = Join-Path $extract 'fhsis-consolidated'
```

Then change directory to the full checkout’s `apps/bhw-connect` folder. The standalone runtime
release may omit ingestion scripts. The existing `C:\meridian\bhw\.env` supplies `BHW_DATABASE_URL`;
do not paste credentials into receipts or messages. Read the packaged `READINESS-AUDIT.md`,
`load-plan.json` and `LOAD-CHECKSUMS.tsv` alongside this guide.

## Prerequisites for the later approved session

1. Use the app checkout containing commit `285e4103c600ced643b504cf1676986cde7af9d7` (PR #473, pregnant oral preparation) or a
   descendant, with the matching installed dependencies. Run from `apps/bhw-connect`, not a standalone
   runtime release that omits ingestion scripts. Do not move or change Meridian's deployment junction.
2. Keep the ZIP and extracted directory private and outside the public checkout. Verify the independently
   supplied ZIP SHA256, then run offline validation below. Do not edit payloads or the manifest.
3. Inspect `fhsis-2025` with `db/inspect-fhsis.mjs`. It must be published, with the original 20 definitions,
   83,496 indicator rows, 13,952 workforce rows and zero geography mismatches before a first consolidation.
   If it differs, stop and reconcile; do not force-load, reset, grant access or assume a partially loaded
   batch is safe to skip. In particular, never rerun `db/load-fhsis.mjs`: the original load is already present.
4. Verify a fresh database backup using the established server procedure immediately before applying.
   Retain its location, time and restore procedure with the receipts. No new grants or migrations are
   required for these prepared batches. `bhw_app` being unable to SET ROLE anon (42501) remains expected;
   verify normal public access rather than changing role membership.

## Offline validation (no database connection)

```powershell
# Keep $package from the extraction step above.
node "$package\verify-package.mjs" (Get-Location).Path
if ($LASTEXITCODE -ne 0) { throw 'Offline package validation failed' }
node --env-file=C:\meridian\bhw\.env db/inspect-fhsis.mjs
```

`load-plan.json` records every payload's exact SHA256, definitions, source hashes and cumulative counts.
The verifier checks all payload hashes, reviewed mappings, checkpoint counts and cross-batch keys.
No original live payload and no held-data payload is included.

## Dry-run every bundle before applying any

The loader defaults to rollback. Each bundle can be dry-run against the original baseline independently.
Use a new private receipt directory for this session; do not overwrite a previous session's logs.

```powershell
$plan = Get-Content -Raw "$package\load-plan.json" | ConvertFrom-Json
$receipts = Join-Path $package ('receipts-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Path $receipts -ErrorAction Stop | Out-Null
foreach ($part in $plan.batches) {
    $payload = Join-Path $package $part.file
    $actual = (Get-FileHash $payload -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -ne $part.sha256) { throw ('Hash mismatch: ' + $part.programme) }
    $output = & node --env-file=C:\meridian\bhw\.env db/load-fhsis-expansion.mjs $payload $part.sha256
    if ($LASTEXITCODE -ne 0) { throw ('Dry run failed: ' + $part.programme) }
    $output | Set-Content -Encoding utf8 (Join-Path $receipts ($part.programme + '-dry.json'))
    $result = ($output -join "`n") | ConvertFrom-Json
    if ($result.mode -ne 'dry-run-rolled-back' -or
        $result.added_indicators -ne $part.added_rows -or
        $result.added_definitions -ne $part.added_definitions -or
        @($result.checkpoints).Count -ne $part.national_checkpoints) {
        throw ('Unexpected dry-run result: ' + $part.programme)
    }
}
node --env-file=C:\meridian\bhw\.env db/inspect-fhsis.mjs
```

Compare every dry-run national checkpoint's numerator, denominator and rate with the payload's national
rows. They are source facts, not sums of children. Confirm that inspection still shows the original
baseline; sequence IDs can advance during rollback and do not indicate persisted facts.

## Apply only during the later authorized session

After **all** dry runs pass and the baseline/backup are confirmed, the following loop applies the
complete list in order. It is for the first approved session only, not an automatic resume mechanism.
Keep the `$package`, `$plan` and `$receipts` values from that session. This code has not been run on
the server. Do not run it while this package remains on HOLD.

```powershell
foreach ($part in $plan.batches) {
    $payload = Join-Path $package $part.file
    $actual = (Get-FileHash $payload -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -ne $part.sha256) { throw ('Hash mismatch: ' + $part.programme) }
    $receipt = Join-Path $receipts ($part.programme + '-apply.json')
    if (Test-Path $receipt) { throw ('Existing receipt; investigate: ' + $part.programme) }
    $output = & node --env-file=C:\meridian\bhw\.env db/load-fhsis-expansion.mjs $payload $part.sha256 --apply
    if ($LASTEXITCODE -ne 0) { throw ('Apply failed: ' + $part.programme) }
    $output | Set-Content -Encoding utf8 $receipt
    $result = ($output -join "`n") | ConvertFrom-Json
    if ($result.mode -ne 'committed' -or
        $result.before.indicators -ne $part.before_rows -or
        $result.added_indicators -ne $part.added_rows -or
        $result.added_definitions -ne $part.added_definitions -or
        @($result.checkpoints).Count -ne $part.national_checkpoints) {
        throw ('Committed result needs investigation; stop: ' + $part.programme)
    }
}
```

The complete per-batch path, hash and expected counts are in load-plan.json and LOAD-CHECKSUMS.tsv.
Each transaction inserts in chunks of 5,000 but commits only after its full bundle. A failed result
check after `mode=committed` does not undo that commit: stop and inspect its receipt.

If a command fails, stop. Its transaction rolls back, but earlier committed bundles remain committed.
Retain their receipts and verify the exact committed prefix against the table below. Never restart the
whole list, auto-skip an unexplained overlap, delete records or force an overwrite. Resume only the
verified uncommitted remainder after resolving the failure. An all-session rollback is not automatic;
a database restore requires the operator's established procedure and an impact review for intervening data.

## Expected progression

Baseline: 83,496 indicator rows / 20 definitions. Workforce remains 13,952 throughout.

| Step | Programme | Add rows | Add definitions | After rows | After definitions |
|---:|---|---:|---:|---:|---:|
| 1 | core | 176,676 | 69 | 260,172 | 89 |
| 2 | child-health | 4,260 | 12 | 264,432 | 101 |
| 3 | maternal | 9,656 | 17 | 274,088 | 118 |
| 4 | infectious | 9,230 | 21 | 283,318 | 139 |
| 5 | family-planning | 45,582 | 81 | 328,900 | 220 |
| 6 | ncd | 33,730 | 64 | 362,630 | 284 |
| 7 | oral-health | 8,946 | 21 | 371,576 | 305 |
| 8 | vital-statistics | 3,550 | 11 | 375,126 | 316 |
| 9 | morbidity-01 | 463,488 | 64 | 838,614 | 380 |
| 10 | morbidity-02 | 282,438 | 39 | 1,121,052 | 419 |
| 11 | morbidity-03 | 28,968 | 4 | 1,150,020 | 423 |
| 12 | morbidity-04 | 79,662 | 11 | 1,229,682 | 434 |
| 13 | morbidity-05 | 57,936 | 8 | 1,287,618 | 442 |
| 14 | morbidity-06 | 72,420 | 10 | 1,360,038 | 452 |
| 15 | morbidity-07 | 72,420 | 10 | 1,432,458 | 462 |
| 16 | morbidity-08 | 21,726 | 3 | 1,454,184 | 465 |
| 17 | morbidity-09 | 152,082 | 21 | 1,606,266 | 486 |
| 18 | morbidity-10 | 130,356 | 18 | 1,736,622 | 504 |
| 19 | morbidity-11 | 137,598 | 19 | 1,874,220 | 523 |
| 20 | morbidity-12 | 14,484 | 2 | 1,888,704 | 525 |
| 21 | morbidity-13 | 79,662 | 11 | 1,968,366 | 536 |
| 22 | morbidity-14 | 173,808 | 24 | 2,142,174 | 560 |
| 23 | morbidity-15 | 72,420 | 10 | 2,214,594 | 570 |
| 24 | morbidity-16 | 65,178 | 9 | 2,279,772 | 579 |
| 25 | morbidity-17 | 123,114 | 17 | 2,402,886 | 596 |
| 26 | morbidity-18 | 144,840 | 20 | 2,547,726 | 616 |
| 27 | morbidity-19 | 43,452 | 6 | 2,591,178 | 622 |
| 28 | demographics | 4,340 | 8 | 2,595,518 | 630 |
| 29 | combined-delivery | 568 | 1 | 2,596,086 | 631 |
| 30 | ncd-senior | 15,696 | 3 | 2,611,782 | 634 |
| 31 | oral-maternal | 2,272 | 4 | 2,614,054 | 638 |

## Completion checks

- Run `db/inspect-fhsis.mjs`: planned final 2,614,054 indicator rows, 638 definitions, 13,952 workforce
  rows, 638 national total rows, eight national workforce rows and zero geography mismatches.
- Check the public `/bhw-connect/health-services` page: 638 measures. Check a region, province and
  municipality. Missing municipality reports must remain missing; source values must not be allocated.
- Open `/bhw-connect/health-services/morbidity/morb_01_001/PH` and one regional/provincial disease page:
  50 age/sex breakdown rows, in age order, with reporting-gap notes. Check all 16 age groups.
- Check demographic ratios use persons per unit and zero-unit cases say undefined. Confirm municipality population/household estimates are present without allocating province facility totals.
- Compare an original FIC count, earlier source checkpoints and workforce counts with pre-load readings.
  Check vital rate units, NCD December labels, FP beginning/end/Q4 periods and held-rate explanations.
- Preserve the private payloads, source archives, hashes, backup reference and receipts. Send the owner
  the counts and completion result; this runbook itself sends nothing.
- Only after confirmation, refresh the synthetic development copy using the existing workflow. Never
  commit real facts, workbooks or load receipts to the public repository.

## Public-page checks

- [National health services](https://staging.pitahc.gov.ph/bhw-connect/health-services)
- [Region V](https://staging.pitahc.gov.ph/bhw-connect/health-services/region/05)
- [Ilocos Norte](https://staging.pitahc.gov.ph/bhw-connect/health-services/province/01028)
- [Adams](https://staging.pitahc.gov.ph/bhw-connect/health-services/citymun/0102801)
- [National morbidity detail](https://staging.pitahc.gov.ph/bhw-connect/health-services/morbidity/morb_01_001/PH)

Use the private runbook’s numerical checkpoints. Confirm senior annual PhilPEN includes supplied
municipality detail, pregnant oral-health measures show Count only with maternal age breakdowns,
and zero-denominator rates show undefined. Abortion/miscarriage and ZOD must remain excluded.

## Completion report to the owner

Keep all detailed receipts private. Send this summary through the agreed channel:

```text
FHSIS consolidated load
Code commit used:
ZIP SHA256 verified:
Backup timestamp/reference:
Dry runs: __ / 31 passed
Applies: __ / 31 committed
Final definitions:           expected 638
Final indicator records:     expected 2,614,054
Final workforce records:     expected 13,952
Geography mismatches:        expected 0
Public-page checks:
Errors or exceptions:
Private receipt location:
```

Only after successful server-load confirmation should the synthetic development-copy workflow
be refreshed. Never commit actual facts, workbooks or receipts to this public repository.
