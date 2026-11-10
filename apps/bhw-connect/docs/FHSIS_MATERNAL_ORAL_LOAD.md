# Pregnant-women oral health — prepared, hold for loading

Four count-only measures distinguish first visits and completed two visits in facility and
non-facility settings. The separate `oral-maternal` batch adds 2,272 facts across 142 reporting
areas, with total and ages 10–14, 15–19 and 20–49. These are age breakdowns, not male/female counts.
All 30 earlier payloads remain unchanged. No server load is authorized by code deployment.

## Source interpretation

The FHSIS 2025 Annual Report, printed pp. 77 and 80 (PDF pp. 88 and 91), explicitly identifies
pregnant women and reproduces the four national percentages. Preserve `Annual!CO2`'s conflicting
senior-citizen title in provenance. `CO5`'s projected under-one population is the report's proxy
denominator for pregnant women, not the service cohort. Figure 1.D.2.3's caption says non-facility,
but its legend and narrative explicitly distinguish both settings.

Use `Annual` total columns CS, CX, DC and DH, each preceded by the three maternal-age columns.
Do not ingest rates or denominators: annual denominators sum quarterly population estimates.
Report agreement confirms the published figures but does not resolve annual coverage semantics.
Do not divide denominators by four. Counts for visits/settings can overlap and are not evidence
of unique-person deduplication.

All 568 annual age sums reconcile. All 2,169 complete quarterly count comparisons match annual
counts; 103 comparisons have missing quarterly markers and cannot establish completeness. All
annual count cells are numeric nonnegative integers. Preserve published annual counts, including
source zeros, without claiming every quarter reported. The preparation QA and dashboard retain
that limitation. Geography covers provinces/HUCs and selected cities, not every municipality.

## Preparation and validation

```sh
python ingestion/prepare_fhsis_expansion.py ingestion/data/fhsis_maternal_oral \
  --reference ingestion/data/fhsis_2025 --retrieved YYYY-MM-DD --programme-set oral-maternal
```

Source: `2. Completed 2 Visits.xlsx`. The loader requires exactly the four definitions in
`ingestion/fhsis-maternal-oral-definitions.json`, annual counts and the approved age breakdowns;
it rejects supplied coverage rates, denominators, sex breakdowns or quarterly rows. QA provenance
is saved with the ingestion receipt. Real sources, facts and evidence stay private.

Use the existing expansion loader with private payload and checksum; dry run is the default.
Expect 2,272 rows, four definitions and four national checkpoints. No migration or grant change.
After the baseline and all 31 prepared batches, planned totals are 638 definitions, 2,614,054
indicator rows and unchanged 13,952 workforce rows. These are planned, not live totals.
ZOD and oral coverage rates remain held. Server loading waits for the user's later instruction.
