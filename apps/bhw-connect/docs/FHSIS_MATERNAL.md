# FHSIS 2025 maternal batch — hold for consolidated loading

This package is prepared only. No server load is requested until the user finishes preparing all
additional data. Deploying the code does not insert facts or definitions. No migration or grant
change is required.

## Content and coverage

Eight Annual workbooks supply 17 indicators and 9,656 rows, including totals and the source's
10–14, 15–19 and 20–49 age groups:

- Three first-trimester BMI categories: normal, low and high.
- Two Td indicators: first pregnancy with at least two doses, and second/later pregnancy with at
  least three doses.
- Three prenatal supplements: completed iron/folic acid, multiple micronutrients and calcium
  carbonate for high-risk pregnancies.
- Six screening indicators: screened/tested and reactive/positive/diagnosed for hepatitis B,
  anemia and gestational diabetes.
- One deworming indicator and two postpartum supplement indicators (iron/folic acid and vitamin A).

The existing ANC and PNC indicators are excluded from this batch to avoid duplicate loads. Each
sheet contains 142 named national, regional, province/HUC and selected city/municipality records.
Do not distribute province totals among municipalities. Source names and workbook/sheet/row
locations are retained in the private package.

## Denominators and source exception

The general denominator is the workbook's **Estimated Population**, including for its maternal
age-group percentage columns. Those percentages are shares of the same all-age population, not
coverage within each age group. BMI supplies age counts only. Screening-positive/diagnosed rates
instead use the matching screened/tested total, or matching age-group screened/tested counts.
The dashboard displays the denominator definition alongside the breakdown table.

The second/later-pregnancy Td age-rate columns report zero despite nonzero age counts. These
three rate columns are withheld pending clarification; their counts and the valid overall rate
are retained. The dashboard explicitly explains the withheld age rates. The raw workbook remains
in the private package, and the omission is recorded in QA. No rates are recalculated or substituted.
DQC columns are not indicators.

All 17 national total rates reconcile to their source numerator/denominator. Across all areas and
included age groups, 7,914 rate rows with available counts and nonzero denominators agree within
0.01 percentage point. This validates the column mapping, not the underlying reporting completeness.

## Preparation and eventual operator validation

```sh
python ingestion/prepare_fhsis_expansion.py ingestion/data/fhsis_maternal \
  --reference ingestion/data/fhsis_2025 --retrieved YYYY-MM-DD --programme-set maternal
```

The loader accepts only the exact reviewed metadata in `ingestion/fhsis-maternal-definitions.json`
for `programme_set: maternal`. Earlier core and child-health packages remain accepted separately.
All workbooks, facts, checksums, manifests and receipts remain private, outside git.

Local PostgreSQL 17 checks confirmed rollback, a local-only append of 9,656 rows and 17 definitions,
unchanged earlier facts, repeat-load rejection and anonymous access to all added rows. For the
later consolidated handoff, the server PostgreSQL 15 dry run must show the same addition and
17 national checkpoints before apply. Use `db/load-fhsis-expansion.mjs` with the private JSON and
SHA256, without `--apply` for the dry run.

After the original live load and all three currently prepared batches, planned totals are 274,088
indicator rows, 118 definitions and unchanged 13,952 workforce rows. These are future totals,
not current live content. Further batches will change the consolidated totals.
