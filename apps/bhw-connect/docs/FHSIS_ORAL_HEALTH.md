# FHSIS 2025 oral health — hold for consolidated loading

Prepared only; no server load is requested. Code deployment does not insert this batch. No schema
migration or grant change is required. Keep it with the other private packages until the user is
ready for the consolidated handoff.

## Reviewed content

Two Annual 2025 workbooks supply 21 indicators and 8,946 rows across 142 national, regional,
province/HUC and selected city/municipality reporting areas. Each indicator retains its source
male, female and total counts:

- Infants aged 0–11 months having their first dental visit, with the source projected infant
  population and annual percentage.
- First oral-health visits and completed two visits, each facility-based and non-facility-based,
  for ages 1–4, 5–9, 10–19, 20–59 and 60+. These are 20 distinct indicators. The four ages-1–4
  indicators retain their projected-population denominator and source percentage. The other 16
  retain counts only pending denominator clarification.

Do not add first visits to completed two visits or sum facility/non-facility counts as unique
people. Sex counts do not have invented sex-specific denominators or rates. Missing municipality
figures remain missing; provincial totals are not allocated to municipalities.

## Held columns and geography corrections

For ages 5–9, 10–19, 20–59 and 60+, the Annual sheet sums quarterly projected populations into its
annual denominator. All 563 comparable numeric cells across these four cohorts equal the sum of
Qtr1–Qtr4 populations. The displayed source percentages use that sum, so they cannot be presented
as annual population coverage without clarification. These 16 rates and denominators are withheld;
visit counts are preserved. No replacement denominator or recomputed rate is supplied.

The final block of `2. Completed 2 Visits.xlsx` (columns 93–113) has conflicting labels: a senior
citizen title, an under-one population denominator and age groups 10–14, 15–19 and 20–49. Its four
measures are withheld entirely until the source owner identifies the cohort. They are not assumed
to be pregnancy services or relabelled as infant/senior care.

Both sheets report the same 142-area hierarchy, but some source PSGCs point to same-named places
elsewhere: Quirino, Aurora, Quezon and Rizal in the infant file, and Caraga in the two-visit file.
Preparation therefore resolves this limited-area source by its area names and region/province
hierarchy, not by trusting those codes. It also handles existing code corrections such as Dagupan,
Zamboanga Sibugay, Isabela City and Surigao del Norte. Eleven code corrections are recorded in the
private audit; original names and PSGCs remain in each fact. Duplicate, ambiguous or out-of-region
name matches stop preparation. A synthetic test covers a wrong code for a same-named municipality.

All 710 retained rates reconcile within 0.01 percentage point. This validates mapping and rate
arithmetic, not source completeness or unique-person tracking. Original workbooks remain private
for review of all held columns.

## Preparation and eventual validation

```sh
python ingestion/prepare_fhsis_expansion.py ingestion/data/fhsis_oral_health \
  --reference ingestion/data/fhsis_2025 --retrieved YYYY-MM-DD --programme-set oral-health
```

The loader accepts exactly `ingestion/fhsis-oral-health-definitions.json` for programme set
`oral-health`. Earlier packages retain their mappings. Sources, facts, manifest, checksums and
validation receipts stay outside git.

Local PostgreSQL 17 validation confirmed rollback, local append, unchanged earlier facts,
repeat-load rejection and anonymous access. For the eventual PostgreSQL 15 server dry run, expect
**8,946 added rows, 21 definitions and 21 national checkpoints**. Use the existing expansion loader
with the private JSON and SHA256, without `--apply` for the dry run.

After the original live load and all seven prepared loadable batches, planned totals are 371,576
indicator rows, 305 definitions and unchanged 13,952 workforce rows. These are future totals, not
current live content. Environmental ZOD remains held separately due to its 2024 headings.
