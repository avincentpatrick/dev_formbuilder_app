# Vincent: import the official 2023 poverty estimates

For **avincentpatrick**, the server operator. This is the remaining data-load step for
[PR #613](https://github.com/avincentpatrick/dev_formbuilder_app/pull/613) and
[PR #614](https://github.com/avincentpatrick/dev_formbuilder_app/pull/614).
The schema and application support were deployed; the official observations were not loaded
as part of that delivery. Merging this documentation does not import any observations.

Import **103 official 2023 r2 records**: Philippines, 18 regions, 82 provinces, Isabela City
and Cotabato City. The accepted source and geographic limitations are documented in
[POVERTY_OFFICIAL_SOURCE.md](POVERTY_OFFICIAL_SOURCE.md). Municipal SAE remains a separate source.

## Before starting

- Use the Windows server's BHW **`bhw_connect`** PostgreSQL database, with your normal operator
  credentials. This task does not target Meridian's database.
- Use a current source checkout containing #613 and #614, Node 24 and installed app dependencies.
  Run from `apps/bhw-connect`, where `ingestion/` and `db/` exist; do not assume those scripts
  are included in Next's standalone runtime directory.
- Confirm migration `20261120120000_official_poverty.sql` is applied. Deployment normally handles
  schema migrations. If its table is missing, investigate deployment before loading data.
- Keep the workbook, generated bundle, credentials and detailed load output outside public git.
  Use a private directory restricted to the operator by Windows permissions.

## 1. Download and validate the source

In PowerShell, from the app source directory:

```powershell
New-Item -ItemType Directory -Force C:\private | Out-Null
Invoke-WebRequest 'https://psa.gov.ph/system/files/phdsd/2023%20Full%20Year%20Official%20Poverty%20Statistics_r2.xlsx' -OutFile C:\private\poverty-2023-r2.xlsx
node ingestion/prepare-poverty-official.mjs C:\private\poverty-2023-r2.xlsx C:\private\poverty-official-2023.json
if ($LASTEXITCODE -ne 0) { throw 'Preparation failed; stop and inspect the error.' }
```

Expected: `prepared_rows: 103`, `excluded_rows: 5`, and SHA-256
`fd374441fa8746f816758089010a00d9179970cea96348d68de021958345f44a`.
Preparation verifies the workbook checksum, headings and exact mapped source labels. If PSA has
changed the file, stop for a source audit; do not bypass the pinned checksum. The output file is
created exclusively: if it already exists, inspect it or use a fresh private output filename
consistently in all following commands.

## 2. Set the database connection privately

```powershell
$povertyConnection = Read-Host 'BHW production PostgreSQL connection URL' -AsSecureString
$env:BHW_LOAD_DATABASE_URL = [System.Net.NetworkCredential]::new('', $povertyConnection).Password
```

Enter the operator PostgreSQL connection URL for `bhw_connect`, not a website or PostgREST URL.
This prompt avoids putting the URL directly into command history. Do not post it in the PR or chat.

## 3. Dry run, then apply

Run the dry run first. It validates the live geographic codes, levels and names, inserts inside a
transaction, and **rolls back**:

```powershell
node db/load-poverty-official.mjs C:\private\poverty-official-2023.json
if ($LASTEXITCODE -ne 0) { throw 'Dry run failed; do not apply.' }
```

Expected: `{ applied: false, rows: 103 }`. Only after that succeeds, run:

```powershell
node db/load-poverty-official.mjs C:\private\poverty-official-2023.json --apply
if ($LASTEXITCODE -ne 0) { throw 'Import failed; inspect before retrying.' }
```

Expected: `{ applied: true, rows: 103 }`. This atomically registers
`psa-official-poverty-2023-r2` and inserts its observations into `agg_poverty_official`.
It does not replace municipal SAE or change BHW's active dataset. A repeated load refuses to
overwrite a registered source. If the result is uncertain after a connection failure, inspect
the registered source before retrying; do not delete it to force a retry.

After completion, or after stopping on any error, clear the session credentials:

```powershell
Remove-Item Env:BHW_LOAD_DATABASE_URL -ErrorAction SilentlyContinue
Remove-Variable povertyConnection -ErrorAction SilentlyContinue
```

## 4. Verify live and record completion

Open [the Poverty card](https://iconnect.com.de/bhw-connect/catalog?dataset=poverty).

- Select Philippines, BARMM, Lanao del Sur, Maguindanao del Norte, Maguindanao del Sur and
  Cotabato City individually. Compare each official estimate and confidence limits against its
  exact workbook Table 2 row; check the source label, 2023 date and reporting-boundary notes.
- Official estimates use **95% confidence intervals**. Municipal SAE keeps its separate **90%**
  intervals and existing map. Missing local estimates must not inherit a parent or national rate.
- Confirm SGA remains unavailable where the accepted source has no matched reporting unit.
- Follow BARMM coverage to a province, search a municipality, open it and use browser Back.
  Scope and selection should remain intact.

Record the import timestamp, source slug, accepted row count, dry-run/apply success and verification
outcomes in the handoff PR. Keep credentials and observation bundles private. Do not mark populated
live verification complete from a successful deploy or dry run alone.

If validation fails, retain the original files and error privately and report the failure category
(checksum, source label, geography, existing release or database connection). A partially prepared
or mismatched source must not be made publishable by weakening validation.
