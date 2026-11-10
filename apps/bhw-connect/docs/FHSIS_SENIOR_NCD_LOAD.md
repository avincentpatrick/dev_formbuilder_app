# Senior PhilPEN annual measures — prepared, hold for loading

Three additional measures cover ages 60 and above: risk assessment, identified hypertension and
identified type 2 diabetes. This separate `ncd-senior` batch leaves all 29 earlier payloads unchanged.
It adds 15,696 facts at 1,744 areas (national, region, province/HUC and municipality reporting rows),
including source total, male and female counts. It does not add quarterly histories or medicine-access
snapshots, and it does not change workforce data. Server loading remains on hold.

## Reporting-year evidence and limitations

The FHSIS 2025 Annual Report, printed pp. 83–85 (PDF pp. 94–96), corroborates the senior national
rates and the adult-plus-senior national totals. The worksheet `Q4!A3` says **Annual 2024**, conflicting
with RA annual headings. Preserve that heading and the report citation in `qa.source_year_resolution`;
the loader saves QA with its ingestion receipt. Do not edit the workbook or infer quarterly years.
The 142 overlapping RA/Q4 summary areas have matching annual counts. The RA worksheet additionally
contains municipal detail; the report match does not independently certify every local submission.

Use `RA` annual block GN:GY: total count/rate GP:GQ, GT:GU and GX:GY. Risk assessment uses projected
senior population in C. Hypertension and diabetes use the assessed senior count in GP, not projected
population. Male/female counts are the preceding two columns; no sex-specific rates are invented.
Annual values differ from quarter sums in Region V and its national rollup. Retain source annual
values, rather than rebuilding them from quarters. Service findings are not population prevalence
or proof of unique-person deduplication. Geography uses the existing reviewed resolver and records
source-code corrections; it never allocates higher-level totals down to municipalities.

All 5,232 male/female sums reconcile to annual totals. All 5,214 positive-denominator rates reconcile
within 0.01 percentage point. Eighteen zero-denominator source rates are retained in the payload but
shown as undefined. The dashboard shows the reporting-year caveat when these measures are present.

## Preparation and eventual loading

```sh
python ingestion/prepare_fhsis_expansion.py ingestion/data/fhsis_senior_ncd \
  --reference ingestion/data/fhsis_2025 --retrieved YYYY-MM-DD --programme-set ncd-senior
```

The source directory contains `1b. NCD Meds Senior Citizens_nofml.xlsx`. The loader accepts exactly
`ingestion/fhsis-senior-ncd-definitions.json`. Sources, annual-report excerpts, fact payload, checksums,
source audit and local-validation receipt stay private. Use the existing expansion loader with
private payload and SHA256; dry run is the default. Expect 3 definitions and 3 national checkpoints.
No migration or grant change is needed. A code deployment alone inserts no data.

After the original live baseline and all 30 prepared batches, planned totals are 634 definitions,
2,611,782 indicator rows and unchanged 13,952 workforce rows. These are planned totals, not live counts.
ZOD remains excluded because the report and workbook disagree on certification status.
