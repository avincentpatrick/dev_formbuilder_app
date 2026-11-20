# Poverty: official 2023 source and coverage discovery

Implementation record, 2026-11-20. Extends the municipal pilot in
[POVERTY_ANALYTICS_READABILITY.md](POVERTY_ANALYTICS_READABILITY.md).

## Accepted scope and audit

The owner authorized implementation after requesting national, regional and provincial poverty
sources. This increment uses **2023 r2**, deliberately alongside the existing 2023 municipal SAE.
It is not a claim to show the latest poverty release. The newer 2025 report also revises historical
regional boundaries; mixing its 2023 revisions into this vintage would change the reporting domain.

Primary source: [PSA 2023 full-year official workbook, r2](https://psa.gov.ph/system/files/phdsd/2023%20Full%20Year%20Official%20Poverty%20Statistics_r2.xlsx),
retrieved 2026-11-20, SHA-256 `fd374441fa8746f816758089010a00d9179970cea96348d68de021958345f44a`.
[PSA release page](https://psa.gov.ph/content/11-out-18-regions-recorded-significant-decreases-poverty-incidence-2023),
[OpenSTAT Table 2](https://openstat.psa.gov.ph/PXWeb/pxweb/en/DB/DB__1F__FY/0031F3DF020.px/),
[OpenSTAT Table 2a](https://openstat.psa.gov.ph/PXWeb/pxweb/en/DB/DB__1F__FY/0041F3DF02A.px/).
The inspected Excel headers and footnotes, not an inferred meaning of a code, define the contract.

- Measure: poverty incidence **among population**, people below the poverty threshold divided by
  all people in the source reporting area, percent. Not families, households, a count, or need score.
- Table `tab2`, column G: 2023 estimate; J: CV; R/S: 2023 **95%** confidence limits. Headers checked
  during preparation. Municipal SAE remains a separate **90%** interval source and separate map.
- Table 2 includes source province groupings with associated HUCs where applicable. Table 2a has
  separately labelled province-without-HUC domains. They must not be interchanged under a plain
  province label. Source reporting label, table and boundary notes are shown beside each estimate.
- Fixed geographic crosswalk: `ingestion/poverty-official-2023-contract.json`; 103 mappings:
  national, 18 regions, 82 provinces, Isabela City and Cotabato City. The public contract contains
  source coordinates and geographic definitions, **no observations**. Preparation requires both
  the pinned workbook hash and exact source row label. Loading checks the live code, level and name.
- Mt. Province is explicitly mapped to Mountain Province. Isabela City uses its city code, not the
  app's province-like grouping or Isabela province. Cotabato City is separate from Maguindanao del
  Norte even though the app uses that province as a navigation parent.
- Combined Maguindanao is excluded; use the two separately published split-province rows. Four NCR
  statistical districts are excluded: no guessed conversion into app reporting districts/Manila.
- BARMM in this vintage includes Sulu and the transferred 63 barangays. Region IX excludes Sulu;
  Region XII excludes those barangays in 2023. NIR is separate from Regions VI/VII. These are source
  reporting boundaries, not assertions about today's administrative boundaries.
- Source CV above 10% carries caution. Original footnote markers and the workbook link remain.
  Published confidence limits are not clipped. A source dash is **not absolute zero**. Unrecognized
  numeric codes/symbols stop preparation. Missing, suppressed and failed states remain distinct.

Table 2a was inspected, but its additional HUC/NCR city rows are **not loaded by this first contract**.
Manila still needs an explicit reporting-unit match. Source-only SGA rates are not invented.
Later HUC coverage and 2025 require a separate audited mapping/version, not nearest-name matching.

## Delivered code and behavior

- Additive `agg_poverty_official` migration; no changes to `agg_poverty`, source calculations or BHW.
- Catalog pins `psa-official-poverty-2023-r2`; no latest-dataset selection. Row interpretation in
  `lib/catalog/poverty-official.ts` fails closed on duplicate, malformed or untraceable observations.
- Shared catalog measure definition and 95% exact-table label. `PovertyOfficialOverview` shows an
  official estimate only for the selected, matched source reporting area. All locations has an
  explicit national link; it never passes a sample off as a national observation.
- Until operator loading, the UI says **no matched estimate is loaded**, rather than claiming PSA
  publishes no national/regional/provincial poverty rate. Original tables remain accessible.
- `PovertyCoverage` is early on region/national Poverty pages. Regional province links display
  usable municipal-record coverage from the existing accepted map API, with at most three requests
  in flight. No national fan-out. Zero counts as available. Request failures have a retry; withheld
  and unsupported states are named. Every province stays selectable even with no municipal values.
- The all-datasets omission list now links to Poverty coverage/sources. Province pages offer a
  direct jump to the searchable municipal list; its summary separates availability from filtering.
- Existing catalog journey and map-return helpers preserve selection/scope; municipal map values
  remain SAE. No official-source choropleth, relationship or inferred poor-person count is added.

## Private load: avincentpatrick's server action

Repository AGENTS.md requires data loads on the Windows server to be performed by avincentpatrick.
No real workbook, observation bundle or SQL observations enter public git. The operator can download
and prepare directly from the primary source; no private transfer is required if network permits.
From the deployed app source with Node dependencies installed, save the workbook outside git, then:

```powershell
New-Item -ItemType Directory -Force C:\private | Out-Null
Invoke-WebRequest 'https://psa.gov.ph/system/files/phdsd/2023%20Full%20Year%20Official%20Poverty%20Statistics_r2.xlsx' -OutFile C:\private\poverty-2023-r2.xlsx
node ingestion/prepare-poverty-official.mjs C:\private\poverty-2023-r2.xlsx C:\private\poverty-official-2023.json
# Set BHW_LOAD_DATABASE_URL privately using the operator's normal PostgreSQL credentials.
node db/load-poverty-official.mjs C:\private\poverty-official-2023.json
# Dry run validates geography and inserts in a transaction, then rolls back. After it passes:
node db/load-poverty-official.mjs C:\private\poverty-official-2023.json --apply
```

Expected: 103 rows, 5 explicitly excluded source rows. No BHW active dataset changes, no replacement
of an existing release. A second load refuses to overwrite it. Retain original workbook, bundle and
load output privately. If the workbook hash or live geography differs, stop and audit the revision;
do not edit the checksum just to make it load. After commit, verify national, BARMM, Lanao del Sur,
Maguindanao del Norte/Sur and Cotabato City against their exact workbook rows; confirm sources and
95% intervals, municipal SAE90% still intact, and SGA unavailable. A migration deploy alone is **not**
evidence that new observations are live.

## Verification and remaining work

Automated contracts: `lib/catalog/poverty-official.test.ts`,
`components/catalog/poverty-official-overview.test.tsx`,
`ingestion/prepare-poverty-official.test.ts`, `db/load-poverty-official.test.ts`.
Coverage/navigation browser checks: `e2e/poverty-coverage.spec.ts`, existing
`e2e/poverty-overview.spec.ts`, `e2e/poverty-municipalities.spec.ts`, catalog/map suites.
Synthetic tests cover zero, missing, source dash, withheld, failed, malformed, duplicate, changed
source/geography, unclipped uncertainty, wrong-year refusal, rollback and overwrite refusal.

Release/check/live evidence is appended after delivery. Refresh the schema development copy after
the migration is live using the required `bhw-dev-snapshot.yml` workflow and review its three files.
Operator loading and populated official live verification remain open until evidenced. Additional
Table 2a city mappings, a separate newer vintage, representative-reader review and physical-device
acceptance remain separate work. Automated viewport/keyboard checks do not establish comprehension.
The previously deferred BHW Phase 4/performance/Headcount metadata items are not signed off here.

Local validation: 2,154 tests passed / 55 skipped (database services unavailable locally), typecheck,
additive migration lint and prefixed production build passed. Lint has only the existing intentional
base-path fixture warning. The real source was prepared and bundle-validated privately: 103 accepted
rows / 5 exclusions; this is preparation evidence, not a server load or live acceptance.


## Release and bounded live evidence

- Feature delivery: [PR #613](https://github.com/avincentpatrick/dev_formbuilder_app/pull/613),
  merged 2026-11-20 11:06 UTC as `b285231d78f2e446196f44d177f1d3620dde2d48`.
- [PR CI](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/44723459203):
  2,197 tests passed / 13 skipped; 192 browser checks passed / 1 skipped. This includes the real
  migration plus synthetic import/rollback and public read-only permission test.
- [Main CI](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/44724807588):
  every required job passed.
- [Deployment](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/44729446025):
  migration `20261120120000_official_poverty.sql` applied; explicit
  `BHW Connect b285231d78f2e446196f44d177f1d3620dde2d48 is live` at **11:42:23 UTC**.
- Live at `https://iconnect.com.de/bhw-connect`, Chromium viewports **390×844 and 1440×844**:
  all-datasets BARMM omission link opens Poverty; provincial coverage settles and keeps SGA's
  missing municipal estimates visible; keyboard navigation opens Lanao del Sur; the direct jump
  reaches the municipal list; searching Bacolod opens its local SAE90% estimate; browser Back
  retains the search. National view shows the explicit pending-import state and original workbook
  link. Both tested views fit their viewport without horizontal page overflow. Screenshots were
  inspected privately, not committed with live data.
- [Development-copy refresh](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/44729899023)
  completed successfully after the migration became live. Its reviewed schema/version/synthetic seed
  advance the development baseline from `20261107090000` to `20261120120000`, including already-live
  intervening migrations. There are no official poverty observations or source metadata in the seed.
  Existing FHSIS definitions/metadata and generated fixtures refresh with the export; no real facts
  are copied. Whitespace was normalized on the version marker and one generated INSERT header.

**Official observations are not yet loaded.** Live verification confirms the pending-import state,
not populated official rates. avincentpatrick must run the private load above and then the listed
source-row checkpoints. This does not sign off representative-reader comprehension or physical
phones, nor close the previously deferred BHW work.


The first refresh CI exposed a synthetic-data regression in the existing Health Services browser
path: expanded FHSIS breakdowns no longer met the generator's safe categorical-copy limit, so every
breakdown became free text rather than a usable `total`. The follow-up fixes the development seed
generator to use the known `total` code in that fallback case, and applies it to the 200 generated
FHSIS fixture rows. Existing safely copied categorical codes are preserved; unrelated free-text
columns remain synthetic. No production FHSIS record, code meaning or calculation changes. The
existing populated Health Services browser test remains enabled and must pass before merging.
