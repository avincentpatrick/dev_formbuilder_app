# Public dataset-map coverage audit

8 November 2026. `scripts/inspect-map-coverage.mjs` makes one explicit public aggregate
request and, when supported, one same-origin geometry request. It reports technical coverage
counts and response hashes, not observation values or individual geography records. No
credentials, database access, data changes or automatic crawl are required.

## Run

Use Node 24 and a complete map choice. For example:

```sh
node scripts/inspect-map-coverage.mjs 'https://staging.pitahc.gov.ph/bhw-connect/api/dataset-map?dataset=uuc-phc-2025&indicator=uuc.listed&period=2025&level=national&code=PH'
```

Where the execution environment supplies HTTP(S) proxy variables, enable Node's inherited
proxy handling with `NODE_USE_ENV_PROXY=1` and retain its supplied CA trust. Do not disable
TLS verification. The first workspace attempt without proxy handling could not connect;
the inherited proxy succeeded. That transport failure was not interpreted as a data failure.

Exactly `dataset`, `indicator`, `period`, `level` and `code` must be supplied once. Supported
scopes are national, region and province, matching the current API. The probe rejects
credentials, extra query keys, redirects, wrong prefixes and unexpected geometry paths.
Each request has a 20-second timeout. Decoded JSON is bounded to 2 MiB for values and 32 MiB
for geometry. Invalid/duplicate rows and contradictory navigation-only values fail validation.
It reuses the app's coordinate traversal, with basic polygon-type checking; no new dependency.

Exit 0 means the supported API response and basic geometry check were inspected successfully,
not that all data is present or the dataset is ready for every use. Unavailable/failed/
unsupported responses and unchecked geometry exit 1; malformed inputs/responses also fail.
The report always leaves historical compatibility untested and production readiness
uncertified. Preserve reports privately or commit only technical summaries without values.

## Interpretation

- `unsupported` (400): this deployed API does not support the exact choice. This can reflect
  a pending deployment or a planned map; it does not diagnose the underlying facts.
- `unavailable` (200) and `failed` (503): keep those states separate; neither proves a table
  is empty. FHSIS still needs its documented operator diagnosis.
- `reportedRows` counts numeric rows, including zero. Missing/unavailable values remain
  separate from navigation-only geography; no suppressed value or rate is reconstructed.
- `hasReportedParentTotal` records only presence. It never computes a total from children,
  averages rates or prints the numeric parent value.
- Geometry is checked separately. A failed geometry fetch is `not-checked`, not a claim that
  every area lacks a shape. Confirmed missing geometry does not reduce the data row count.
- Polygon type and valid-coordinate presence are basic checks only. They do not establish
  valid rings/topology, historical crosswalks, renderability or accepted barangay boundaries.

## Selected live evidence

[Technical reports](DATASET_MAP_PUBLIC_COVERAGE.json), captured through the supplied proxy
on 8 November 2026, without writing to the service:

| Map choice | Scope | API state | Reported / returned rows | Rows with basic geometry |
| --- | --- | --- | --- | --- |
| UUC listed barangays, 2025 | National → regions | Available | 18 / 18 | 18 / 18 |
| NHFR facility counts, September 2026 | National → regions | Available | 18 / 18 | 18 / 18 |
| PSA population, 2024 | National → regions | Available | 18 / 18 | 18 / 18 |
| PSA SAE poverty incidence, 2023 | Ilocos Norte → municipalities | Available | 23 / 23 | 23 / 23 |
| StepZero source population, 2026 | National | Unsupported | Not inspected | Not inspected |
| FHSIS fully immunized children, 2025 | National | Unsupported | Not inspected | Not inspected |

Poverty correctly supplied no provincial parent total. The StepZero indicator had just
merged in #474 and was not recognized by the deployed API during this check; rerun after
its deployment. FHSIS's independent numeric map remains gated. Neither response justifies
loading data, changing registration, granting privileges or removing filters.

These samples do not certify every indicator, province, municipality or source record.
Regional aggregate coverage does not prove barangay coverage or source completeness.
Dataset-specific dates, definitions, disclosures, source licensing and boundary acceptance
remain separate checks. No real values or source extracts were added to git.

## StepZero deployment follow-up

A later 8 November check after #474 reached the running app returned **available** for both
`stepzero.population` and `stepzero.households`: each had 18 reported regional rows, a
reported parent total and 18 matching basic geometries. Dated reports are appended to the
same evidence file, preserving the earlier unsupported result. This resolves the observed
rollout limitation for those two regional views; it does not prove detailed national
coverage, source completeness or historical boundary acceptance. The new accreditation-rate
map is a separate change and is not certified by these population/household checks.
