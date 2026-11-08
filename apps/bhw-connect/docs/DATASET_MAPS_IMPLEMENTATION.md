# Dataset maps: foundation increment

7 November 2026. Implements phase 1 contracts and phase 2 validation tooling from
DATASET_MAPS_PLAN.md. This is not the card/fullscreen rollout. No production data,
server settings, CSP changes, external asset origins or database migrations are added.

## Contracts

`lib/maps/definitions.ts` has independent definitions for Census 2025, StepZero
2026, profiling status, UUC, NHFR, districts, FHSIS, population, poverty, income
classification and the Ways forward availability slot. The catalog's combined BHW
entry is split by accepted source release. Existing allowlisted indicator definitions
are reused, including filters, suppression fields, uncertainty and denominator metadata.
An unknown dataset, indicator, period or unsupported level is rejected, never redirected
to a BHW default. Values and geometry have separate availability states; zero is an
available number, not a missing value.

Capability is not publication approval or evidence of accessible production rows.
Every boundary version remains null until accepted reconciliation. Source slugs and
known periods are pinned; exact attribution/license/date must be read from accepted
release metadata. FHSIS rates additionally require the accepted indicator dictionary.
Profiling remains hidden on the homepage. District measures, StepZero population and
households, standalone income classification and further UUC/FHSIS measures still need
adapters. The planned work registry records these extensions without inventing metrics.

## Boundary validation

Run the offline gate separately for each level and dataset-compatible vintage:

```sh
python ingestion/validate_map_boundaries.py \
  --geojson /private/candidate.json --reference /private/permitted-codes.json \
  --crosswalk /private/reviewed-crosswalk.json --property geo_code \
  --sha256 ACTUAL_SHA256 --release IMMUTABLE_RELEASE \
  --level barangay --source PINNED_SOURCE_URL --license VERIFIED_LICENSE \
  --output /private/reconciliation.json
```

Reference input: JSON array of exact string codes. Crosswalk: object mapping each exact
supplier string to an array of reviewed target codes. An empty array is unresolved;
multiple targets are ambiguous, never redistributed. Numeric supplier codes must be
normalized through the existing PSGC preparation machinery first, with evidence for
padding/extraction. This tool deliberately does not guess codes. The SHA256 must match
and moving `latest` releases are refused. Nonzero exit means missing/unmatched/ambiguous
codes, duplicates, null shapes or invalid basic polygon geometry. The report still writes
on reconciliation failure so gaps can be reviewed. Code matching does not imply usable
geometry. Topology and historical compatibility remain separate review gates.

Fresh local inventory: 83 municipality files, 1,601 features, five null shapes; 18
province files, 88 features; regions and districts together have 207 features. No other
basic ring/coordinate failures were found. All have geo_code properties. These are asset
counts, not reconciliation against production geography. Centroid dictionaries are not
polygons and are excluded. Existing stored reconciliation denominators remain separate.

Before selecting a new supplier release, reconcile NCR, Manila submunicipalities,
province-level HUCs, NIR, Sulu, BARMM and SGA explicitly. Review topology, shared edges,
small islands and historical transfers/splits. District completeness requires all
validated members. Do not certify a district from the subset with shapes. Retain the
previous version for rollback. Do not commit permitted extracts or observation data.

## Archive delivery validation

```sh
node scripts/validate-map-hosting.mjs \
  https://HOST/bhw-connect/maps/IMMUTABLE_VERSION/basemap.pmtiles \
  /private/basemap.pmtiles
```

This read-only probe requires a local PMTiles v3 header, checks first/middle/late ranges
and a repeated range, exact bytes and lengths against the original, 206 Content-Range,
identity encoding, binary MIME, strong consistent ETag, public immutable caching and
conditional 304. It bounds each response and refuses redirects. Its synthetic unit
fixture tests the probe, **not** a renderable archive. A passing result does not prove
archive structure, basemap license, actual browser cache reuse, MapLibre/CSP rendering,
range concurrency, bandwidth, archive-size limits or phone performance.

No representative licensed basemap archive or operator-provisioned versioned storage
route is present in this checkout. Those tests remain blocked on actual artifacts and
hosting. The operator must provision persistent versioned storage outside rotating app
releases and supply the intended same-origin route; then run the probe against the actual
archive. Never weaken CSP or add a tile server to the production request path to bypass
this dependency. Glyphs, sprites and styles should share the same origin and prefix.

## Next increments and publication gate

1. Build/reconcile pinned candidate boundaries and a licensed representative archive.
   Perform bounded public coverage checks; follow FHSIS_DIAGNOSIS.md before any reload.
2. Prototype UUC and NHFR independent embeds, with separate local state and table
   alternatives. Enlarge must preserve dataset, indicator, period, geography and filters.
3. Add the dataset-selectable Map page and remaining dataset cards. Preserve supported
   geography only; explain an unsupported selection and offer its evidenced parent.
4. Test many-card lifecycle (at most two active embedded WebGL maps), keyboard controls,
   phone scrolling, WebGL/asset failure, missing geometry with complete table totals,
   numeric agreement, suppression, shareable fullscreen state and genuine WebGL export.
   The three-second phone target is unmeasured.

For every future dataset card, require its own definition and map slot before publishing:
source/vintage/license, approved indicators and filters, units and denominators, periods,
supported levels, availability probe, disclosure rules, query adapter, reviewed join and
boundary version, legend semantics, accessible table and fullscreen state. A planned
card may show explicit unavailability; it must never inherit BHW figures or invented data.

## Verification in this increment

- `npm ci`, lint, typecheck, migration lint and the prefixed no-data production build passed.
- Full Vitest run: 113 files passed; 1,406 tests passed, 16 skipped. Database-dependent
  coverage was not exercised without a configured test database.
- Python boundary validator: two tests passed (ambiguous/duplicate/missing/null codes,
  numeric identifier rejection, closed rings and coordinate bounds).
- Chromium attempted Explore at 390×844 and 1280×900. Both returned 500: the production
  runtime intentionally rejects `BHW_DATA_SOURCE=none` outside builds. No rendered-map,
  mobile gesture or accessibility success is claimed; rerun against the seeded database.
- Production PMTiles and nationwide supplier reconciliation remain unrun for the explicit
  artifact, reference-extract and operator dependencies above.

## Independent-card prototype (next increment)

The portal now has a map gallery with one independently owned card per dataset release;
profiling status stays hidden there as previously requested. This prototype adds working
UUC list-count and NHFR count/type/ownership/bed indicators, and explicit availability
slots for the remaining definitions. The existing unified catalog remains intact.

`/map` offers a dataset chooser without a BHW default. Enlarge/share links serialize the
dataset, metric, period, parent geography and selected child. Switching the two implemented
datasets preserves the current supported geography; pending maps explain their national
availability view. Cards own their state, and do not use the global BHW filter store.

Public reads use an allowlisted GET adapter and anonymous RLS client. It validates the
parent against dim_geo, fetches immediate children only (fails closed at the row cap),
and retains missing child values and the independent authoritative parent total. No BHW,
FHSIS, income-class or personal-data query is reachable through this adapter. Query errors,
absent releases, missing rows, zero values and missing geometry have separate handling.

Maps use the existing same-origin MapLibre worker and GeoJSON, with cooperative gestures,
accessible selection, explicit drill actions and complete tables. National → regional →
provincial views show children through city/municipality. Barangay polygon drill remains
blocked on accepted shapes; municipal dashboard links provide existing barangay information.
No facility coordinates, new UUC classification, cross-vintage redistribution or missing
values are invented. MapLibre instances are disposed when offscreen; only the two
implemented cards can allocate WebGL. Planned cards never request observations.

This is an increment, not final rollout: the licensed detailed basemap, production-route
PMTiles validation, historical crosswalk acceptance, remaining adapters, genuine map PNG
export, richer geographic search and measured phone/network performance remain pending.
The three-second target is not claimed. These dependencies do not justify weakening CSP.

Prototype verification: lint passed (one existing fixture warning), typecheck and prefixed
production build passed; the seeded-database Vitest run passed 1,419 tests with 11 skipped.
Six Playwright cases passed: independent card state, enlarge/share state, dataset switching
after drill, unavailable boundaries, invalid choices, synthetic zero/missing-value/missing-
geometry distinctions, and WebGL failure with usable table/selection controls. A 390×844
Chromium phone emulation rendered without horizontal overflow; axe reported no violations.
A single-finger drag over the map scrolled the page (scrollY 288 → 461). Scrolling between
cards and a pending card observed one active map at peak on that phone viewport and zero
after leaving supported cards. This is not a measured mid-range device/network performance
claim. Local development figures remain synthetic; live numeric coverage is not certified.

## Count-map rollout and gallery resource limit (8 November 2026)

Adds three independent adapters: BHW Census 2025 validated-profile counts, StepZero 2026
LGU-declared total/registered/registered-and-accredited/nonregistered counts, and PSA
population 2024. Census requires the accepted release to be active. StepZero does not
query validated profiles, and PSA reads only `agg_population` with `census_year = 2024`
on both child and parent queries. No fallback substitutes StepZero population. Source
metadata dates continue to display separately from release labels, including discrepancies
already present in the accepted metadata. No source dates are rewritten.

This remains a count-only rollout: BHW demographic/honorarium breakdowns, rates, StepZero
household/source-population extensions and the other pending adapters are not enabled by
loosening the endpoint allowlist. They still require their own measure and disclosure work.
The existing catalog stays intact; consolidating catalog and map-gallery presentation is
still a later UI step, not a claim that the whole dataset maps plan is complete.

A per-gallery lease controller now gates both requests and MapLibre instances, with at
most two owners. Each card retains its own choices and loaded result. Waiting or offscreen
cards do not fetch values; leaving the map viewport releases the lease. Visibility watches
the map area, not a long card's remaining footer. Returning to a loaded card reuses its
result without a second request; reload the page for a fresh source read. Standalone Map
views have one active dataset. Controllers are created per provider, never shared across
server requests. Planned cards never join the queue.

Validation: lint (existing fixture warning only), typecheck, migration lint and the
prefixed production build passed. Seeded-database Vitest: 1,430 passed, 11 skipped.
Eight browser cases passed, including source/period isolation for the three new adapters,
a tall viewport with multiple eligible cards limited to two maps/two requested datasets,
offscreen disposal and cached return. Phone emulation of the population map at 390×844
had no horizontal overflow, no map-unavailable fallback and no axe violations. These checks
use the synthetic dev database; they do not certify production coverage, historical
boundary compatibility, licensed basemap delivery or real-device network performance.

## PSA poverty map and published uncertainty (8 November 2026)

Adds an independent PSA SAE poverty 2023 map. National and regional views are explicitly
geographic navigation: they query geography only, do not request higher-level poverty
observations, and label their values “Not published at this level.” Selecting a province
loads only its immediate city/municipality estimates from the accepted source, with
`sae_year = 2023` and `geo_level = citymun`. No parent rate is requested or derived, no
percentages are averaged, and 2018/2021 back-estimate periods and barangay scopes remain
rejected. Missing coverage, including the source's HUC exclusions, is not zero.

The join preserves decimal point estimates and published 90% confidence intervals. Missing,
invalid or duplicate observations do not become zero. Missing/reversed/non-finite interval
bounds remain unavailable; valid published bounds are not clamped to 0–100 or rounded.
Selected-area details and the complete table expose the uncertainty alongside the estimate.
Fixed descriptive breaks at 10, 20, 30 and 40 percent support comparisons between provinces;
they are display intervals, not official poverty classifications. Count maps retain their
existing quantile legends. The map explicitly ends at municipal estimates: boundary work
cannot create barangay poverty data that the source does not publish.

Expanded canvases now observe their own container size and resize without requiring a
window resize. This supports layout changes in the enlarged view while retaining the
same two-map gallery budget and independent choices.

Validation: lint (existing fixture warning only), typecheck, migration lint and the prefixed
build passed. Seeded-database Vitest passed 1,437 tests, with 11 skipped. API tests confirm no
poverty aggregate query on navigation levels and no parent query at municipal detail; unit
fixtures cover decimals, uncertainty, exclusions, duplicates and fixed color cutpoints.
Eleven browser tests passed, covering navigation → municipal estimates, exact synthetic interval display,
no barangay drill and container resizing, alongside the existing state-isolation and
resource-limit checks. A 390×844 phone emulation with the poverty table open had no axe
violations, no horizontal overflow and no map-error fallback. Synthetic development rows
are not evidence of complete production coverage. Other adapters, historical boundary
acceptance, detailed basemap/PMTiles hosting and map PNG export remain pending.

## DOF/BLGF income-class map (8 November 2026)

Adds the independent LGU income-class map to the gallery and dataset-selectable Map page,
with the existing enlarged/shareable view and per-card state. The adapter reads published
`dof-blgf-income-2024` metadata and `dim_lgu_income_reclass`, preserving the source date
(5 November 2024) separately from the effective date (1 January 2025). It selects only
`new_class`, source LGU type and conversion context, never historical `old_class_dof` or
`dim_geo.income_class`. National regions provide navigation only. Region and province
views request only immediate LGUs of the supported level from the accepted dataset.

Five fixed categorical colors and named classes replace numeric quantiles. The table and
selection distinguish source-unclassified, unmatched, ambiguous and invalid records;
missing geometry does not remove an LGU. No class total or average is calculated. Source
conversion notes are preserved, Manila submunicipal entries are disclosed as city context,
and no barangay is assigned an independently published class. This does not resolve
historical boundary compatibility or certify nationwide production coverage.

Validation: lint (existing fixture warning only), typecheck, migration lint and the prefixed
production build passed. Seeded-database Vitest: 1,444 passed, 11 skipped. Thirteen browser
cases passed, including income navigation, categorical labels, shareable effective date,
source-unclassified versus unmatched rows and no barangay drill, alongside prior map
isolation and resource-budget checks. A 390×844 phone emulation with the income table open
had no axe violations, horizontal overflow or map-error fallback. These use synthetic
development data. Remaining adapters, boundary acceptance, detailed basemap/PMTiles
hosting and map PNG export remain pending as described above.

## Profiling-status stage map (8 November 2026)

Adds the independent 2026 profiling-status map to the dataset-selectable Map page, with
stage selection, expanded view and shareable geography. The homepage card remains hidden.
Bounded anonymous reads use only published `bhw-profiling-status-2026` metadata and its
`agg_bhw_profiling_status` rows at the requested child level, never census or StepZero rows.
No parent rate or average of child percentages is requested or calculated.

The four stages follow the existing workflow reader: encoded is drafted + for-validation +
back-to-encoder, validated excludes approved records, attested is approved, and not-encoded
is headcount minus all pipeline records, floored at zero. Each share uses the area's own
`n_total_bhw`, rounded to whole percentages as in the existing dashboard. Fixed breaks retain
values at or above 100%; an overshooting snapshot is flagged, never normalized to the
pipeline total or silently capped. Selected details and tables expose counts and denominators.
Missing, duplicate, malformed or zero-denominator snapshots have no mapped share; a valid
zero stage count remains zero. Municipal rows absent from the synthetic source stay missing.
Barangay boundary navigation remains gated on the outstanding geometry acceptance work.

Validation: lint (one existing fixture warning), typecheck, migration lint and prefixed
production build passed. Seeded-database Vitest: 1,451 passed, 11 skipped. Fifteen browser
cases passed, including homepage hiding, stage selection, geography sharing, over-headcount
and zero-denominator fixtures, and prior independent-map/resource-budget checks. Phone
emulation at 390×844 had no axe violations, horizontal overflow or map-error fallback.
Production coverage and historical boundary compatibility are not certified by these
synthetic checks. FHSIS availability, districts, detailed basemap/PMTiles hosting, accepted
barangay geometry and PNG export remain dependencies in the plan.
