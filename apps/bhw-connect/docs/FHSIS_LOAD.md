# FHSIS 2025: private first load

The server diagnosis confirmed that the published `fhsis-2025` dataset has a dictionary but
no indicator or workforce facts. Public SELECT/RLS is already configured. No role membership
or grant change is needed. Only the server operator can run this load.

## Operator steps (Windows, Node 24)

Wait until the release containing `20261107090000_fhsis_exact_values.sql` has deployed.
From the app directory in the checkout, with its installed `pg` dependency, extract the private
package outside the checkout. Read `qa-report.json` and `local-validation.json`. Use the SHA256
printed in the private package's `fhsis-load.sha256` (or independently supplied with the handoff).

```powershell
$bundle = 'C:\private\fhsis-2025\fhsis-load.json'
$hash = (Get-Content 'C:\private\fhsis-2025\fhsis-load.sha256').Split(' ')[0]
(Get-FileHash $bundle -Algorithm SHA256).Hash.ToLower() -eq $hash
node --env-file=C:\meridian\bhw\.env db/load-fhsis.mjs $bundle $hash
```

The default executes the whole load and rolls back the rows. Check for
`dry-run-rolled-back`, the expected row counts, and all 20 national checkpoints.
Then run exactly the same command with `--apply`:

```powershell
node --env-file=C:\meridian\bhw\.env db/load-fhsis.mjs $bundle $hash --apply
node --env-file=C:\meridian\bhw\.env db/inspect-fhsis.mjs
```

Verify the national Health services page and at least one region, province and municipality.
The inspection's `SET ROLE anon` probe can still be refused for `bhw_app`; do not change membership
just for that check. Test the public page/PostgREST with its normal public credentials instead.
Keep the private bundle, printed receipt and source workbooks with the ingestion audit.

## What the loader guarantees

- Resolves the dataset by slug, never assumes ID 12; requires its published registration and all
  20 dictionary keys. Requires the new exact numeric columns/view before inserting.
- Checks the supplied SHA256 before connecting. Validates unique keys, geography levels, decimal
  representations, eight allowed non-BHW cadres and complete national checkpoints.
- Verifies every used server geography's code, name, level and parent against the prepared map.
  A different geography vintage stops the transaction; rebuild using the server's geography.
- Locks the two FHSIS fact tables against competing writers (readers continue). Both tables must
  be empty **for this dataset**. A repeated load is rejected, never appended or overwritten.
- Inserts both sets of facts, corrects six WASH component denominator definitions, records actual
  workbook names and updates the dataset's retrieval version in one transaction. It preserves
  the existing reference date (`as_of_date`) rather than treating retrieval as observation date.
- Writes a checksum/provenance/QA receipt to `ingestion_batches` only on commit. Failures roll
  back. A dry run can consume identity sequence values, as normal for PostgreSQL; it leaves no
  fact rows or receipt. It does not change permissions, BHW counts or other datasets.

## Reproducing preparation

Install the app's ingestion requirements (`openpyxl` is the only parser dependency). Supply the
seven source workbooks listed in `ingestion/prepare_fhsis.py` and a JSON object keyed by `geo_code`
containing `geo_code`, `geo_name`, `geo_level`, `parent_code`, `region_code`, `province_code` from
`dim_geo`. The original preparation uses the repository's real geography lookup, then the loader
checks it against the server. Export the current server lookup if that check fails.

```sh
python ingestion/prepare_fhsis.py ingestion/data/fhsis_2025 \
  --geography ingestion/data/fhsis_2025/dim_geo.json --retrieved YYYY-MM-DD
python -m unittest discover -s ingestion -p test_prepare_fhsis.py
```

Output: `fhsis-load.json`, `fhsis-load.sha256`, `qa-report.json`. The private package additionally
contains source metadata and local transaction validation results. None of those files belongs in
public git, including the original workbooks. The parser reads cached published values, not Excel
formula text, and stops on unfamiliar numeric tokens, unresolved geography or duplicate keys.
It does not extrapolate values from other files or calculate missing service rates.

## Source findings addressed by this load

- Annual sheets: FIC/CIC, four-visit ANC and TB. Eight-visit ANC is **Q3–Q4 2025** despite its
  `Annual` sheet name. Water and sanitation have no annual sheet: use **Qtr4**, labelled Q4.
- The water-level and sanitation-facility percentages divide by households with **basic services**.
  Overall basic and safely managed coverage divide by projected households.
- Fractional counts/projections exist in the source. New `numerator_exact`/`denominator_exact`
  numeric columns preserve them. Legacy integer columns are populated only for integral values;
  the app and new reconciliation view prefer exact columns and fall back for old records.
- Source rates are retained, including overshoots, negative CIC denominators/rates and floating
  point residuals in cached Excel values. Negative CIC coverage is not meaningful coverage;
  the methodology explains this limitation. No percentages are capped, averaged or recomputed.
- TB's Annual sheet supplies a common geography/name reference. Malformed codes are resolved by
  name within the current province, then region; a unique national name is the final fallback.
  Known spelling variants are explicit. Each correction retains source file/sheet/row and raw code.
  Same-name ambiguous places stop preparation. Extra hospital rows in ANC are excluded from LGU
  rows and listed in the audit. Published parent rows remain authoritative.
- Total and supplied age/sex breakdowns are loaded. Sex-only counts have null denominators/rates
  where the source has no matching sex-specific denominator. A denominator alone creates no
  service report; a reported zero does. The dashboard uses total rows only.
- Workforce reads exactly eight cadres; the separate FHSIS BHW filing tally is excluded.

## Validation before handoff

The preparation was exercised on all seven recovered workbooks. A disposable PostgreSQL 17
instance loaded the committed baseline and this migration, then ran the complete private load:
rollback left zero target facts, apply loaded both tables, a second apply was rejected, and the
public `anon` role could read the loaded rows and exact reconciliation. The server is PostgreSQL
15, so its default dry run is still required. No live server load has been performed by Codex.

After this schema deployment, refresh the synthetic development copy with the existing
**BHW Connect dev copy** workflow and commit its artifact in a separate PR, per `CLAUDE.md`.
