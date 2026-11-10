# FHSIS 2025 family planning — hold for consolidated loading

Prepared only. No server load is requested until all additional datasets are ready. Code deployment
does not insert this batch. No schema or grant change is required.

## Content and periods

`FP_2025_PH.xlsx` supplies 81 indicators and 45,582 rows across 142 national, regional, province/HUC
and selected city/municipality reporting areas. Five annual tables each provide 15 method categories
plus their published total, with total and 10–14, 15–19 and 20–49 age counts:

- `CUB_A`: users at the beginning of 2025.
- `NA_A`: new acceptors during 2025.
- `OA_A`: other acceptors during 2025, preserving the source category without assuming they are new.
- `DO_A`: dropouts during 2025.
- `CUE_A`: users at the end of 2025.

Methods are FSTR/BTL, MSTR/NSV, condom, interval/postpartum IUD, POP/COC pills, injectables,
implants/postpartum implants, CCM, BBT, STM, SDM and LAM. Method totals already include age groups.
Beginning and ending users are snapshots, not flows; do not sum them with annual acceptors/dropouts
or add the published all-method total to its components. These measures are not annual unique-person
counts. Missing municipality observations are not replaced with province figures.

The 81st indicator is demand satisfied for modern family planning at **Q4 2025**, ages **15–49**,
from `Demand Satisfied` columns 17–19. The corresponding area column (16) must agree with the first
area column before preparation accepts a row. Its numerator excludes ages 10–14; its denominator
is the table's women-of-reproductive-age count multiplied by the total demand factor.

## Precision and source checks

The source contains 39 fractional count values. They are preserved in exact numeric columns,
not rounded to whole people. The dashboard explicitly explains this. Cached source totals and
rates are used without recalculation from quarters or geographic children.

The standalone `Population` tab's figures do not match the denominator published in `Demand
Satisfied`. The latter remains authoritative for this indicator; no replacement rate is calculated.
Quarterly duplicates, validation/helper columns, and the rounded age-15–49 helper column in `CUE_A`
are not loaded. The actual Q4 demand numerator is retained from `Demand Satisfied`.

All 142 demand percentages reconcile within 0.01 percentage point. For all 9,088 method/age/area
combinations, beginning users + new acceptors + other acceptors − dropouts equals ending users
within 0.01. This is a source consistency check, not a claim about unique-person tracking or the
underlying validity of fractional reports. The source workbook remains in the private package.

## Reproduction and eventual handoff

```sh
python ingestion/prepare_fhsis_expansion.py ingestion/data/fhsis_family_planning \
  --reference ingestion/data/fhsis_2025 --retrieved YYYY-MM-DD --programme-set family-planning
```

The loader accepts exactly `ingestion/fhsis-family-planning-definitions.json` for programme set
`family-planning`. Earlier packages retain their approved definitions. Workbooks, fact rows,
checksums, manifest and validation receipts remain outside git.

Local PostgreSQL 17 validation confirmed rollback, local append, unchanged previous facts, repeat
load rejection and anonymous access. For the later server PostgreSQL 15 dry run, expect **45,582
added indicator rows, 81 definitions and 81 national checkpoints**. Use the existing expansion
loader with the private JSON and SHA256, without `--apply` for a dry run.

After the original live data and all five prepared loadable batches, planned totals are 328,900
indicator rows, 220 definitions and unchanged 13,952 workforce rows. These are future totals,
not live content. The environmental ZOD review remains separately held due to its 2024 headings.
