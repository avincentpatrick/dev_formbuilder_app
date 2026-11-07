# Public catalog data contract — Increment 2

Implemented by `lib/catalog/catalog.ts` (`getPublicCatalog`) and the fixed registry in
`lib/catalog/definitions.ts`. This is a server-only read API, not a Server Action or public
HTTP endpoint. The existing portal is unchanged; Increment 3 will connect the first UI.
It uses the current PostgreSQL/PostgREST public client, never an admin/service-role client.
Increment 4 extensions are recorded below; they supersede the initial barangay and query-budget limits.

## Selection and destinations

- `{ mode: "all" }` checks whether measures have usable public observations anywhere across
  their supported levels. It returns **no numeric sample values**. A source date or dictionary
  alone does not establish availability.
- `{ mode: "psgc", level, code }` reads only the exact area and level. National means `PH`.
  No parent fallback, population allocation, or equivalent-place inference occurs.
- `{ mode: "district", districtCode, citymunCode?, barangayCode? }` preserves the requested
  scope but returns unsupported until Increment 5 implements verified district adapters.
- Barangay adapters remain for Increment 4. No municipality value is returned as a barangay
  value. `supportedLevels` describes this adapter's current capabilities, not every possibility
  documented in the inventory's capability matrix.
- `detailHref` is null when an existing route cannot express the scope, including population
  and poverty's not-yet-built standalone details, barangays and districts. It never silently
  drops scope. Non-null links are Next navigation destinations; raw links must still use
  `withBasePath` when the UI is built.

## Fixed initial indicator inventory

`CATALOG` is the executable inventory, with globally unique logical IDs independent of numeric
release IDs, ingestion runs and display rows. This initial public response has **110 indicators**:

| Logical dataset | Indicators | Counting rule |
| --- | ---: | --- |
| BHW | 66 | Five StepZero measures; four profile measures; 44 named training topics; three certification measures; six demographic distributions; four honorarium receipt measures by payer |
| NHFR | 9 | Total, ownership pair, four named headline facility types, beds, barangay presence |
| UUC-PHC | 5 | Listed count plus the four overlapping qualifying routes |
| FHSIS | 28 | Twenty dictionary-defined service rates plus eight explicitly named non-BHW cadres |
| Population | 1 | PSA 2024 population |
| Poverty | 1 | PSA SAE 2023 incidence, with its confidence interval as an attribute |

A count and its denominator, confidence interval, distribution categories or source appearances
are not extra indicators. The full NHFR type table is a breakdown of facilities, not an
unbounded extension of the four explicitly cataloged headline type measures. UUC assessment
readings remain supporting listing evidence in details, not duplicate current service indicators
alongside FHSIS. BHW cross-source workload/profile-coverage ratios and extended analyses remain
in existing details; they are not silently added to this initial counted inventory. This freezes
the first catalog's denominator explicitly; adding a cataloged measure requires a definition,
source rule and tests. It does not remove those existing detail features or reopen source authority.

The BHW headline total is StepZero; validated profiles are a different indicator and source.
The eight FHSIS cadre keys come from the accepted plan, but a missing loaded key stays unavailable.
FHSIS service labels, units, numerator/denominator definitions and source-workbook metadata must
also resolve from its public dictionary. Its dictionary never establishes that facts are loaded.
The FHSIS BHW cadre is not in the registry. Neither Profiling Status nor district mapping is a
seventh dataset.

## Read boundary and failure semantics

`readDatasetBySlug` adds a typed result to the existing dataset helper: available, unavailable
(no metadata row), or failed. Existing `getActiveDataset` / `getDatasetBySlug` retain their nullable
contracts and the active-profile guard. The catalog reuses this source selection and reads the
same accepted aggregates as the existing helpers, using explicit public-column projections.
It does not duplicate numeric reconciliation or read `fact_bhw_raw`/admin profile documents.
Nullable legacy value helpers cannot preserve a missing-row versus query-error distinction,
so the catalog's `reader.ts` owns typed aggregate reads instead of guessing from their nulls.

Every indicator has a state and a readable reason:

- **available** includes an actual numeric zero. All locations means a successful presence
  check, not a value for a national area.
- **partial** preserves usable values with their limitation: a mixed/withheld breakdown,
  incomplete census rollup, health-route evaluability exclusions, or Q3–Q4-only ANC8.
- **unsupported** means the current adapter cannot safely express the requested level/scope.
- **unavailable** means the accepted source/row/value is absent in a successful read. It does
  not assert that DOH failed to report, nor turn a missing sparse aggregate into zero.
- **suppressed** contains no withheld values. Honorarium's small-group flag withholds the
  catalog measure. Demographic breakdowns reuse complementary suppression; an unprotectable
  breakdown is entirely withheld before composing it with profile totals.
- **failed** means metadata, dictionary, count or value reading failed (including malformed
  values, absent privacy flags, ambiguous scalar rows or a potentially truncated result).
  Database error strings and credentials never enter the public result.

Dataset state is available only if every cataloged indicator is available. Any usable measure
with missing, failed, unsupported, suppressed or partial peers makes the dataset partial, with
`availableIndicators / totalIndicators` for the UI. With none usable, failed takes precedence,
then suppressed, then unsupported if all are unsupported, otherwise unavailable. Keep all six
results so the UI can explain omissions; it can show cards with `availableIndicators > 0`.

## Overview, coverage and freshness

Available datasets have at least one available/partial indicator. Available indicators count
unique canonical IDs in those two states. Unsupported, unavailable, suppressed and failed
indicators do not count as available. Failed/suppressed counts are separate. Total indicators
are the fixed inventory, not the count of rows returned by a source.

Geographic coverage is reported **per indicator**, not added across sources or levels.
`reportedAreaRecords` counts public scalar observations across that indicator's supported levels
in All locations; for a successfully read selected-area scalar it is one. Different geographic
levels overlap, so this is **not** a percentage of the country, population, or municipalities.
Distribution category rows are not places and return null here. No complete geographic roster
is inferred from these counts. Unsupported/failed/missing selected reads leave coverage unknown.
NHFR barangay presence and UUC listing coverage retain their own recorded denominators as value
attributes; neither is treated as record completeness, and the two denominators are not merged.
The UI must label these distinctions rather than show an invented countrywide coverage percentage.

Freshness is derived only from sources contributing at least one available/partial indicator.
It reports oldest/newest source reference dates, latest update timestamp, and number of contributing
sources with unknown reference dates. Dates are validated, unknown remains null, and timestamps
are ordered chronologically including UTC offsets. Different source dates are expected. A deploy,
new ingestion row or new catalog query does not change a reference date or select a new release.

Higher-level population rows are labeled Partial total unless the requested node is Manila's
published province-level census row. Source coverage still needs live verification. Poverty is
city/municipality-only. No rates are averaged, no percentages summed, and no UUC bounded assessment
values rolled up. FHSIS keeps published rates and denominators, including above-100 percentages
with its established explanation and TB rates in their own units.

## Query and privacy budget

One reader instance caches metadata and selected-area table reads for one catalog request.
Through city/municipality the upper bound is seven metadata reads, 13 projected table reads and
one FHSIS dictionary read (21 requests), independent of the number of training topics. Queries
read at most 1,000 records per exact area; reaching the cap fails closed rather than truncating.
The outputs are built field by field, not by spreading rows or source objects.

All locations uses HEAD/count queries rather than downloading nationwide rows: 110 availability
probes, ten additional suppression-presence probes, seven metadata reads and one dictionary
read at most (128 requests). Four indicator jobs run at a time across the entire catalog.
This is a measured structural bound, not a claim about production latency. Increment 3 should
measure that summary before wiring it to the homepage; Increment 7 must verify query latency and
volume on the real service. A future batch optimization must preserve error and per-measure
availability semantics, rather than replace observations with a national-row shortcut.

## Verification and remaining boundaries

Focused tests cover source identity stability, canonical counts, zero/missing/failed reads,
public field whitelisting, partial health coverage, population source/year rules, published
Manila handling, suppression, supported scopes, dictionary-only FHSIS, timestamp ordering,
read caching, bounded concurrency and truncation detection. Source-level tests prove the typed
helper preserves legacy nullable behavior and active-profile selection.

These tests use synthetic fixtures. No live database, raw source data, credentials, migrations,
new UI, or ingestion changes are part of this increment. FHSIS's production cause remains unknown;
the operator check in `FHSIS_DIAGNOSIS.md` is still pending. Barangay calculations, district
membership, dedicated details and browser end-to-end verification retain their planned increments.

## Increment 4 — barangay adapters and cross-location privacy

The same 110 canonical indicators remain. Barangay navigation follows actual parents. BHW
StepZero/profile counts, certification, demographic and payer-honorarium measures can read exact
barangay aggregates; topic training stays unsupported. FHSIS, population and poverty never inherit
municipal values. Missing BHW rows remain unavailable rather than fabricated zeros.

`barangay.ts` calculates NHFR count/type/ownership/presence and recorded bed sums from explicitly
attributed public facilities. It reads only the accepted release and the selected barangay within
its actual municipal parent. An inner geography join verifies municipal facilities' barangay
level and parent, so null, wrong-level and wrong-parent attribution all remain excluded. The UI
reports municipal attribution as `covered / total`, separately from the one-barangay service
presence denominator. Any excluded municipal facilities make the barangay result a **Partial
total**; no allocation is attempted. Zero means no attributed registered facilities. A nonempty
accepted source is checked before a successful empty leaf read may become zero. A leaf read
reaching 1,000 records fails closed, never silently truncates. No parent total is replaced by a
sum of leaf records, and no percentages or rates are averaged or summed.

UUC membership comes from `fact_uuc_phc_barangay`, independently of qualification availability.
A successful absent membership becomes 0 of 1 only after a nonempty accepted list is established.
It means not listed, not assessed and found well served. Qualification uses the existing public
`ref_uuc_phc_list` definitions, retaining its recorded health score rather than recalculating
bounded assessment readings. Missing qualification stays unavailable. An unevaluable health
route is null, not false; an evaluable route uses one evaluated barangay as denominator. No
parent listing percentage or health rate is repeated at the leaf.

Before publishing a demographic dimension or payer-honorarium measure at **any catalog scope**,
the reader checks the accepted source for protected cells in that measure group. If any exist,
the entire group is withheld across catalog scopes, including All locations. This conservative
policy prevents parent-minus-peer reconstruction that within-row complementary suppression alone
cannot prevent. A failed privacy check fails closed. Existing complementary suppression remains
as a second guard on returned rows. This deliberately reduces available counts for protected
groups; a less conservative release needs verified cross-geography suppression closure. It does
not change existing non-catalog routes or claim to repair their historical disclosure behavior.
No raw person data is read, and no values or private error details enter suppressed payloads.

The ten privacy-group probes are cached per request. Maximum structural query bounds are now
138 for All locations (including existing probes), 31 through city/municipality, and 30 at
barangay: four source metadata reads, up to five BHW table reads plus ten privacy probes, five
NHFR reads, and six UUC reads. The page separately resolves geography. The NHFR independent leaf,
total and validated-attribution reads run together; repeated indicator reads share their result.
These are query-count bounds, not a production latency guarantee. Live source completeness still
requires the existing operator checks; accepted metadata alone never establishes loaded facts.
