# Sulu → Region IX: rebuilding the aggregates

avincentpatrick/dev_formbuilder_app#627 (merged, commit `fc53686`) moved Sulu to Region IX in the geography (`dim_geo`), its two
districts, and the UUC regional totals. The aggregates below still count Sulu under BARMM until they are
rebuilt. Run this **after the testing-server deploy has finished** (about 30-60 minutes after the merge).

Merge avincentpatrick/dev_formbuilder_app#628 (wording + published-total reconciliation) before or together with this rebuild. The four SQL files are in `ingestion/sulu-rebuild/`.

Everything runs on the box against the `bhw_connect` database. Nothing here needs the private
`dataset.parquet`, except step 6 (PSA population workbooks).

## 0. Before you start

1. Confirm the deploy is live: `https://staging.pitahc.gov.ph/bhw-connect` answers, and
   `node db/check-sulu-region.mjs` shows all five **geography** lines `ok`. If a geography line says FAIL, the
   migration has not run: stop.
2. Back up. The deploy already dumped before its migration, but take your own now:
   `ops\nightly-dump.ps1` from the BHW checkout (writes to `C:\meridian\backups\bhw`).
3. Take the row-count census: `node db/migrate.mjs --census > census-before.json`.
4. Set the connection once per PowerShell window (never print it):

   ```powershell
   Get-Content C:\meridian\bhw\.env | Where-Object { $_ -match '^BHW_DATABASE_URL=' } |
     ForEach-Object { $env:BHW_DATABASE_URL = ($_ -replace '^BHW_DATABASE_URL=','') }
   $psql = "<path to psql.exe>"   # ops\pg-tools.ps1 shows how the box finds it
   ```

5. Record the baseline of what is stale:
   `node db/check-sulu-region.mjs > check-before.txt` (exit code 1 is expected).

## 1. The BHW aggregates (one file)

`ingestion/build_aggregates.sql` rebuilds `agg_bhw_counts`, `agg_demographics`, `agg_certification`,
`agg_training`, `agg_honorarium`, `agg_geo_summary`, `agg_data_completeness`, `agg_peer_ranks`, `agg_cohorts`,
`agg_workload`, `agg_honorarium_inequality`, `agg_by_income_class`, `agg_honorarium_cumulative` from
`fact_bhw_raw` / `fact_honorarium` and `dim_geo`, so it picks up Sulu's new region by itself.

```powershell
& $psql --dbname $env:BHW_DATABASE_URL --single-transaction -v ON_ERROR_STOP=1 -f ingestion\build_aggregates.sql
```

- **`--single-transaction` is required.** The file deletes each table's rows first and does not wrap itself in a
  transaction; without the flag a failure half way leaves the aggregates empty.
- Read the header of the file first: it says to bump the dataset's `data_version` after a re-ingestion. This is not
  a re-ingestion, so you normally do not; do it only if the cards are cached against that version and you need
  them to refresh.
- It is the longest step. On the made-up dev data it stops at section 9b (a Wilson-interval square root);
  that is an artefact of the fake counts, so expect it to pass on the real data, and if it fails on the box send me the error.

## 2. Facilities (two files, in this order)

```powershell
& $psql --dbname $env:BHW_DATABASE_URL -v ON_ERROR_STOP=1 -f ingestion\sulu-rebuild\10_rebuild_nhfr_counts.sql
& $psql --dbname $env:BHW_DATABASE_URL -v ON_ERROR_STOP=1 -f ingestion\sulu-rebuild\11_rebuild_nhfr_by_type.sql
```

These are the populate steps of migrations `20260905090200` and `20260905090300`, extracted because the whole
migrations cannot be re-run (their `create policy` lines fail the second time). Each ends in its own sanity
check (rollups agree, by-type sums to the counts) and rolls back if it fails.

## 3. UUC indicator distribution, then BHW-by-UUC-status (in this order)

```powershell
& $psql --dbname $env:BHW_DATABASE_URL -v ON_ERROR_STOP=1 -f ingestion\sulu-rebuild\12_rebuild_uuc_indicator_dist.sql
& $psql --dbname $env:BHW_DATABASE_URL -v ON_ERROR_STOP=1 -f ingestion\sulu-rebuild\13_rebuild_bhw_by_uuc_status.sql
```

File 13 needs `agg_uuc_phc_counts` already correct (the PR's migration did that) and the BHW aggregates from step 1.
Both end in a sanity check against `agg_uuc_phc_counts`.

## 4. Population

`agg_population` rolls region rows up from the PSA workbooks through `dim_geo`, so re-running the loader follows
Sulu's new region. It needs the two workbooks in `ingestion\data\` (`psa_popcen_2024_tableB.xlsx`,
`psa_cph_2020_tableB.xlsx`) on a machine with Python and `psycopg2`:

```powershell
python ingestion\ingest_population.py --verify          # offline reconciliation first
python ingestion\ingest_population.py --database-url $env:BHW_DATABASE_URL
```

## 5. What a re-run will NOT fix (needs a follow-up change)

- **`agg_bhw_stepzero_counts` and `agg_bhw_profiling_status`**: their region rows were summed from the source
  sheet's own region-code columns, not from `dim_geo`, so `ingest_stepzero.py` would put Sulu back where the
  sheet has it. They need a small in-database fix that re-sums those two tables' region rows from their province
  rows. Ask for it as a separate PR. Until then `check-sulu-region.mjs` keeps reporting them: expected.
- **`agg_poverty`**: carries no region rows (a rate is never rolled up): nothing to do.
- **`agg_poverty_official`**: PSA's own published regional estimates, filed as PSA publishes them. Decide
  separately whether Sulu's official estimate should be shown under Region IX.
- **`ingestion/ingest_nhfr.py`**: its comments and `EXPECTED_REGION_COUNTS` still describe Sulu under BARMM. Before
  the next facilities *load* (not needed for this rebuild), change the BARMM/Region IX expected counts by Sulu's 177.

## 6. Verify

```powershell
node db\check-sulu-region.mjs
node db\migrate.mjs --census > census-after.json
```

- Every **geography** and **additive** line should be `ok`, except the two tables in section 5.
- Lines marked `TODO` list a non-additive table that has Sulu rows and still needs a rebuild: after steps 1-4 only
  the tables in section 5 should remain.
- Compare the two census files: row counts may rise or fall for aggregates only (they are rebuilt), never for `fact_*`
  or `dim_*` tables.
- On the site, check: the UUC-PHC card reads BARMM 313 and Region IX 610. The source list and the cue cards print
  BARMM 400 and Region IX 523, because they count Sulu's 87 in BARMM; the difference is deliberate, and avincentpatrick/dev_formbuilder_app#628 makes the
  data-quality page say so. A BHW region page for Region IX (Sulu now appears among its provinces); facilities by region.

## If something goes wrong

The aggregates are derived data. Restore from the dump in step 0 (`C:\meridian\backups\bhw`), or re-run the same
file: every step is idempotent. Never run a step twice at the same time as a deploy.
