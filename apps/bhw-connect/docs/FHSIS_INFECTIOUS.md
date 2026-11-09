# FHSIS 2025 infectious-disease batch — hold for consolidated loading

Prepared only; no live load is requested. The user will finish preparing all additions before a
consolidated server handoff. Code deployment does not insert these facts or definitions. No schema
or grant change is needed.

## Included content

Eight workbooks supply 21 indicators and 9,230 rows across 142 reporting areas:

- Four pregnancy screening indicators: HIV screened and screen-reactive; syphilis screened and
  positive. Reactive HIV screening is not a confirmed HIV diagnosis. Age counts are retained;
  no age rates are invented. Screening uses the source's projected population under age one as
  its proxy denominator; reactive/positive totals use women screened for the respective condition.
- Four two-dose deworming indicators: ages 1–4, 5–9, 10–19, and women aged 20–49. The first three
  include sex counts without invented sex denominators or rates.
- Two filariasis indicators: positive cases among examined people; disability/deformity among
  positive cases. Both source rates are percentages.
- Two leprosy indicators: cases undergoing treatment (prevalence per **10,000** population), and
  newly detected cases (per **100,000**). These scales reconcile to source values and are displayed
  separately. Population rates are not flagged as excess percentage coverage.
- Two rabies indicators: animal bite counts and deaths due to rabies. The source death rate uses
  reported animal bites as denominator; it is not a population mortality rate or a confirmed-case
  fatality rate.
- Seven schistosomiasis indicators: suspected, acute clinically diagnosed, acute stool-confirmed,
  chronic clinically diagnosed, chronic stool-confirmed, treated in health facilities, and referred
  to hospital. Two are count-only pending clarification of the source rate denominators.

TB is already present and is excluded from this batch. Each source sheet reports national,
regional, province/HUC and selected city/municipality figures. Missing municipality observations
remain missing. Sex and age groups must not be added to their source total.

## Source exceptions and checks

- The STH workbook repeats one-dose pregnancy deworming, with a total different from the maternal
  workbook already prepared. That entire duplicate indicator is held for reconciliation, not
  appended under another code. Both original workbooks remain in their respective private packages.
- Schistosomiasis `Annual1` chronic diagnosis and treatment rate denominators cannot be established
  from the annual tables. Their rates are withheld; source total and sex counts remain. The dashboard
  explains the omission. The treatment header mixes chronic and acute terminology, so its label
  identifies the source chronic table rather than asserting a cleaner clinical definition.
- Source leprosy validation cells flag some sex aggregations as sum errors. Published counts and
  parent totals are preserved rather than rebuilt from children. Validation/DQC columns are excluded.
- The child deworming Annual sheet has an incidental “4th Quarter” header, while its report title
  identifies Annual 2025. We retain that Annual sheet's figures without combining quarterly sheets.
- Explicit source-name corrections: `Nueva Eciea` to Nueva Ecija in the adolescent/WRA STH workbook,
  and `City of Quezon`/`City of Pasay` to Quezon City/Pasay City in the schistosomiasis workbook.
  The region check still applies; original names and corrections are retained in private QA.

All 1,807 included rate rows with available counts and nonzero denominators reconcile within 0.01
of their stated rate unit. Source zeros and missing markers remain distinct. Very small nonzero
rates display three significant digits rather than rounding to zero. This validates mappings and
scales, not underlying case ascertainment or reporting completeness.

## Private preparation and later validation

```sh
python ingestion/prepare_fhsis_expansion.py ingestion/data/fhsis_infectious \
  --reference ingestion/data/fhsis_2025 --retrieved YYYY-MM-DD --programme-set infectious
python -m unittest discover -s ingestion -p 'test_prepare_fhsis*.py'
```

The loader accepts the exact metadata in `ingestion/fhsis-infectious-definitions.json` only for
`programme_set: infectious`. Earlier packages retain their mappings. Workbooks, facts, manifests,
checksums and validation receipts remain outside git.

Local PostgreSQL 17 validation confirmed rollback, a local append of 9,230 facts and 21 definitions,
unchanged earlier facts, duplicate-load rejection and anonymous reads of all added rows. The later
server PostgreSQL 15 dry run must show the same additions and 21 national checkpoints. Use
`db/load-fhsis-expansion.mjs` with the private JSON and SHA256, without `--apply` for a dry run.

After the original live load and all four prepared batches, planned totals are 283,318 indicator
rows, 139 definitions and unchanged 13,952 workforce rows. These are future totals, not live content;
additional batches will change them.
