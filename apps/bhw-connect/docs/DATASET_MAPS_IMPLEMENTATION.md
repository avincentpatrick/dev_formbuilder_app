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

## Reviewed district geometry build gate (8 November 2026)

Adds an offline, checksummed district candidate builder and an explicit complete-membership
review input. This addresses the district geometry dependency before publishing its map:
existing live membership rows alone cannot prove a district's full legal extent. See
[the input contract, commands and publication limits](REVIEWED_DISTRICT_BOUNDARIES.md).

The builder requires exact leaf-code matches, pinned geometry/vintage and membership
provenance. Missing or duplicate members/shapes, inactive/conflicting assignments,
self-intersections and positive-area overlaps block the entire affected district. It can
dissolve accepted barangay polygons as well as municipality polygons, preserves islands,
and never substitutes points or silently repairs invalid topology. Cross-district overlap
blocks both candidates. Single-source membership remains labeled as such. Reports retain
input/output hashes, library version, attribution and completeness; output is a private
candidate, not automatically consumed by the app. Existing public assets remain unchanged.

A fresh Shapely check of the 1,601 committed municipal features found five null geometries
and one ring self-intersection (`0804808`), in addition to 1,595 valid shapes. This is not
nationwide reconciliation or acceptance of a newer boundary vintage. No guessed repair
has been made. Full reviewed membership, historical crosswalks and pinned barangay inputs
remain required before claiming district completeness. FHSIS still requires the documented
operator-side read-only diagnosis; no figures or availability have been invented.

Validation: 12 Python boundary tests passed (including checksum/CLI exit codes, missing
members, duplicates, source status, barangay polygons vs points, topology, overlaps and
islands). Lint, typecheck, migration lint and the prefixed production build passed;
seeded-database Vitest: 1,451 passed, 11 skipped. Lint retains the existing fixture warning.
No UI or browser behavior changes in this increment; browser tests were not rerun.

## UUC qualifying-route count maps (8 November 2026)

Extends UUC's independent map with the four published qualifying-route counts: Indigenous
Peoples, conflict-affected/displaced, 4Ps/CCT enrolled, and worse than province on health.
The listed-barangay indicator stays separate. Each selection retains the UUC source,
2025 period and geography when enlarged or shared, without changing adjacent dataset maps.

Reads use the accepted `uuc-phc-2025` release and `agg_uuc_phc_criteria`; they never recompute
eligibility from cleaned or capped indicator measurements. Child rows and the independently
reported parent total retain the same route projection and denominator context. Tables and
selection details show listed counts for the first three routes and health-evaluable counts
plus excluded listed barangays for the health route. Routes overlap and must not be added
as a partition. No source percentages are averaged or new shares manufactured.

A reported zero health-route count remains zero even when no barangays are evaluable; its
note explains that a share has no evaluable denominator. Missing/duplicate source rows and
inconsistent counts remain unavailable, never converted to zero. Areas without geometry
remain in the table, and the parent total is not replaced by drawable child sums. Municipal
route rows absent from the synthetic source remain explicitly missing. Barangay polygon
navigation and accepted historical boundaries remain separate dependencies.

Validation: lint (existing fixture warning), typecheck, migration lint and prefixed production
build passed. Seeded-database Vitest: 1,457 passed, 11 skipped. All 17 map browser tests passed,
including independent UUC selection, enlarged-view sharing, route exclusions, reported zero,
missing rows and independent parent totals. Phone emulation at 390×844 had no axe violations,
horizontal overflow or map-error fallback. Synthetic checks do not certify production
coverage. The workspace interruption occurred after tests/build and before browser checks;
the saved results and changes were recovered, and browser verification completed on resume.

## Nationwide barangay candidate reconciliation (8 November 2026)

Completed a full offline comparison of the supplier's pinned v1.1.1 / PSGC 3Q 2026
high-resolution archive against 41,991 barangays in a local geography-only development
extract. The supplier's published archive checksum was verified. See
[the assessment, reproduction commands and remaining gates](BARANGAY_BOUNDARY_CANDIDATE.md)
and `ingestion/barangay_candidate_summary.json` for aggregate evidence and attribution.

42,006 supplier barangay polygons have unique codes and individually valid geometry;
41,576 match reference codes, 415 reference codes are missing, and 430 supplier codes are
unmatched. The documented Manila parent rule resolves hierarchy differences without
historical remapping. Submunicipal polygons and untyped claimed areas are not counted as
barangays. All 410 reference Sulu barangays remain unmatched pending a reviewed vintage
crosswalk. No region prefixes are silently substituted.

10,322 positive-area overlap pairs affect 12,199 matching reference barangays; some overlaps
cover an entire smaller polygon. The release is therefore not publication-ready. The 29,377
candidates passing local checks are not certified historical boundaries. No geometry was
repaired, clipped, redistributed or published. Full details and the permitted extract stay
outside git; the committed report contains counts/provenance only. MIT and underlying
NAMRIA/PSA via OCHA COD-AB CC BY 3.0 IGO attribution requirements are documented.

Also corrects the district candidate builder's barangay code length from nine to the app's
actual ten digits, with regression coverage. No published assets used the incorrect check.

Validation: ten new candidate tests and thirteen district/basic boundary tests passed;
lint (existing fixture warning), typecheck, migration lint and the prefixed build passed.
Seeded-database Vitest: 1,459 passed, 11 skipped. No UI changes; browser tests were not rerun.
Production geography certification, reviewed historical crosswalks, shared-edge-safe
geometry, accepted versioned storage delivery and actual barangay drill-down remain pending.

## Upstream topology comparison and joint simplification trial (8 November 2026)

Compared all 10,322 reported candidate-overlap pairs against the pinned unsimplified
v1.0.1 shapefile source, verified against its supplier checksum. In the source CRS,
10,314 pairs only touch, three are disjoint and five overlap. Of the touching pairs,
10,310 cross municipal source-file boundaries. This isolates a downstream processing
problem for most reported overlaps without claiming nationwide upstream topology is clean.
See [comparison and reproduction instructions](BARANGAY_TOPOLOGY_COMPARISON.md).

A controlled trial copied all 123 source barangays of Bacarra and Laoag with their original
coordinates, attributes and CRS. With Mapshaper 0.6.113, identical WGS84/10%-retention settings
and simplification repair disabled, independent municipal imports reproduced seven overlap
pairs; the joint input produced zero. Both retained 123 unique codes and 123 polygon
components, with no invalid/missing polygons. No snap, clipping or cleaning command was used.
The result supports constructing shared topology before simplifying and splitting delivery
files. It is not blanket acceptance of nationwide simplification or small-island retention.

Two offline tools pin source/report checksums, preserve unresolved/invalid source cases,
prepare joint and independent control inputs without editing coordinates, refuse overwrites,
and retain attribution/CRS/tool versions. Only aggregate evidence is committed; detailed
pair identifiers, shapefiles and trial geometry remain outside git. Neither tool publishes
assets or changes dataset boundary versions. The five observed upstream overlaps, historical
crosswalks, full-country shared-edge checks and actual storage delivery remain unresolved.

Validation: six topology/trial tests, ten candidate tests and thirteen boundary/district
tests passed (29 total). Lint (existing fixture warning), typecheck, migration lint and
prefixed production build passed. Seeded-database Vitest: 1,459 passed, 11 skipped.
No UI changes; browser tests were not rerun.

## National shared-topology build and retention gate (8 November 2026)

Extended the two-municipality trial to three full-country exports from the checksummed
unsimplified source: 10% with intersection repair disabled, 10% with Mapshaper's default
intersection repair, and an unsimplified joint export. See the [national build evidence and
reproduction commands](BARANGAY_NATIONAL_BUILD.md) and aggregate machine-readable summary.
No candidate passed acceptance, and no public assets or dataset boundary versions changed.

All three retained 42,010 source records/codes, including four null geometries absent from
the supplier's simplified archive. These are not newly recovered boundaries. Both 10%
variants lost 493 polygon components across 216 barangays and three holes despite keeping
every code. The unsimplified export preserved components but lost one hole and introduced
six invalid polygons. The default-repair control eliminated newly invalid polygons, but
component/hole loss and eleven overlap pairs remained. A lower overlap count alone is not
an acceptance criterion: invalid shapes are excluded from those pair checks and separately
block the gate. Historical code differences and source nulls remain unresolved.

The new offline validator pins source, candidate and reference hashes. It checks exact code
retention, unexpected/duplicate records, source and candidate validity, lost or gained
components/holes per code, reference geography and topology. Failed checks write a report
and exit 1; bad inputs exit 2. Even an automated pass does not set publication readiness.
Detailed identifiers, source inventory and generated geometry stay outside git; aggregate
results and reproduction metadata are committed. No snapping, clipping or silent acceptance
tolerance was introduced. Future builds must verify component/hole preservation and isolate
import/projection effects before attempting accepted delivery partitions or barangay drill-down.

Validation: eight national retention/gate tests, sixteen candidate/topology tests and thirteen
boundary/district tests passed (37 total). Lint (existing fixture warning), typecheck, migration
lint and prefixed app build passed. Seeded-database Vitest: 1,463 passed, 11 skipped.
No UI changes; browser tests were not rerun. Geospatial candidate failures are intentionally
separate from passing software tests and app builds.

## Ring-preserving barangay reconstruction (8 November 2026)

Prepared all 45,585 source exterior/interior rings as protected simplification features,
with an exact code/part/ring manifest, then reconstructed original barangay geometry types.
The assembler requires every ring and blocks whole units rather than filling absent holes
or dropping islands. Source coordinates/CRS remain unchanged during preparation; the
projection/simplification stage is pinned and explicit. See [ring-retention evidence and
reproduction commands](BARANGAY_RING_RETENTION.md).

The nationwide result retains 45,572 of 45,573 polygon components and 11 of 12 holes; the
single missing component/hole belongs to one whole barangay deliberately withheld after its
extremely thin source ring degenerates on projection. All other parts and holes are retained.
Four source-null records remain null. All 42,010 codes remain present; no invalid non-null
polygons are emitted. Eight overlap pairs remain (four upstream overlaps and four touching
upstream), so publication is still blocked. Historical reference mismatches are unchanged.
No source ring was silently deleted or filled, and no boundary assets were published.

Validation: eight new ring-pipeline tests plus the previous 37 Python checks passed (45 total).
Lint (existing fixture warning), typecheck, migration lint and prefixed app build passed.
Seeded-database Vitest: 1,463 passed, 11 skipped. No UI changes; browser tests were not rerun.
The geospatial acceptance gate correctly remains failed, separately from software validation.

## Private municipality packages and complete coverage (8 November 2026)

Added offline packaging and verification for the ring-preserving national candidate. Both
sides of all overlap conflicts are quarantined, along with invalid/null shapes, membership
mismatches and changed components/holes. Geometry is partitioned without further alteration.
The private result has 41,567 polygons across 1,636 files and a complete 41,991-barangay
reference index, including 424 explicit withheld records. All hashes and original coordinates
match; global cross-partition validation finds no remaining overlaps. This is withholding,
not repair or acceptance of the source conflicts. See [packaging evidence and commands](BARANGAY_CANDIDATE_PACKAGING.md).

Every manifest remains unpublished with no dataset boundary assignments. Historical
compatibility and actual self-hosted delivery remain gated; no geometry or detailed
reference records entered git, and no UI or public map assets changed.

Validation: 52 Python tests passed, including seven packaging tests. Lint (existing fixture
warning), typecheck, migration lint and prefixed no-data build passed. Seeded-database
Vitest: 1,463 passed, 11 skipped. Browser tests were not rerun because this increment changes
only offline packaging and documentation. Geospatial publication remains blocked separately
from passing software checks.

## Municipality package HTTP verification (8 November 2026)

Added a read-only delivery probe for the private municipality package contract. It verifies
pinned manifest paths and every file's bytes, JSON MIME, immutable cache policy, strong
validators, conditional 304 responses and a genuine missing-file 404. Requests are sequential,
time-limited and bounded. It refuses redirects and never promotes a candidate or modifies
hosting. See [delivery commands, measured local evidence and production limits](BOUNDARY_HOSTING_VALIDATION.md).

The actual private package passed over controlled loopback HTTP: 1,638 files, 114,148,940
bytes, all checksums and conditionals matched. This establishes local probe behavior only;
operator-provisioned persistent storage, production headers, compression, MapLibre/CSP and
phone performance remain unverified. No UI, public assets, settings or shared deploy files
changed. The barangay publication gate remains closed.

Validation: eleven new delivery tests passed, including a real local HTTP server. Full
seeded-database Vitest: 1,476 passed, 11 skipped. Lint (existing fixture warning), typecheck,
migration lint and prefixed no-data production build passed. Browser tests were not rerun:
this increment changes only an offline/operator probe and documentation.

## Dataset map data exports (8 November 2026)

Each loaded dataset map now offers **Download map data (JSON)** in its card and enlarged
view. The export contains its current dataset/indicator/period/geographic selection, units,
denominator, source name/date/license/attribution, disclosure context, exact legend bins,
complete area rows, reported parent total and separate geometry availability. It uses only
the public aggregate payload already displayed; it makes no additional database requests
and never filters out rows because they lack polygons. No hidden or personal fields are
copied. Unavailable/planned maps have no export action.

Zeros remain numeric zero; null values remain null with an explicitly ambiguous
not-reported-or-unavailable state and the reader's note. The current map payload does not
supply separate suppression reasons, so the export does not invent one or reconstruct a
withheld value. Navigation-only levels export null values and no legend/aggregate rather
than fabricated regional rates. Poverty exports retain published 90% confidence intervals;
income categories retain labels. Quantiles are marked relative to displayed areas, while
poverty, income and workflow legends retain fixed bins. Geometry not yet checked remains
not-checked, distinct from a confirmed missing polygon. Boundary-version acceptance remains
separate from geometry availability.

This is a structured data export, not a rendered map image. WebGL PNG capture, image
attribution layout and performance remain separate unfinished plan items. Existing barangay
acceptance and self-hosted delivery gates are unchanged.

Validation: four export unit tests and all 18 dataset-map browser tests passed, including
an actual downloaded file with failed geometry and a planned-dataset switch. Full seeded
Vitest: 1,480 passed, 11 skipped. Lint (existing fixture warning), typecheck, migration lint
and prefixed no-data production build passed. Browser tests ran against the prefixed local
production server with development data and explicitly synthetic request fixtures.

## Rendered dataset map PNG downloads (8 November 2026)

Enlarged dataset maps now offer **Download map image (PNG)** after their WebGL map reaches
idle with drawable geometry. Embedded cards keep their data export and Enlarge action.
The capture copies MapLibre's canvas synchronously during a fresh render event, before the
WebGL drawing buffer is discarded. It does not use a DOM screenshot, enable permanent
`preserveDrawingBuffer`, add another live map or fetch additional dataset values.

A separate canvas composes the actual current viewport with dataset/indicator, period,
units, geographic scope, exact legend colors/bounds, selected area/value, denominator where
applicable, published selected poverty uncertainty, complete-table coverage, reported parent
total, source/date/license/attribution and disclosure notes. It explicitly states that the
image is only the current viewport and directs readers to the JSON export for complete
rows and area-specific notes. Boundary availability is not represented as historical
acceptance. Existing adapted 2023-series boundary provenance is credited; no new candidate
boundaries are promoted. Long metadata wraps without clipping ordinary words.

The exported frame is 1,200 pixels wide with a bounded height, preserving its aspect ratio.
Capture has a five-second timeout and rejects removed/failed maps. A loading, unavailable or
failed map cannot export PNG. Selection changes during asynchronous encoding cancel that
export, and failures offer an accessible status message plus the JSON alternative. Temporary
Blob URLs are revoked. Phone dimensions here are automated Chromium viewport tests, not a
claim about real-device/network performance or production basemap readiness.

Validation: two metadata unit tests; full seeded Vitest 1,483 passed, 11 skipped. Lint
(existing fixture warning), typecheck, migration lint and prefixed no-data production build
passed. All 20 dataset-map browser cases passed, then the PNG checks were expanded and all
three focused cases passed (1,280px and 390px downloads plus failed geometry; 21 distinct
cases in the final suite). Downloads were decoded and checked for painted map pixels beyond
the legend's area; the resulting desktop image was also visually inspected. Synthetic
values explicitly supply the map colors; development NHFR provincial rows alone are missing.
The React review checked effect cleanup, stable callback refs, lazy map initialization,
keyboard-accessible controls and no new data-fetching or persistent WebGL-buffer overhead.

## Accessible dataset map area search (8 November 2026)

Loaded dataset cards and enlarged maps now include a labeled search field for current-area
names and PSGC codes. Matching ignores case and diacritics, accepts multiple words in any
order, and preserves leading-zero codes. It searches only the authoritative child rows
already loaded, including rows with missing values or polygons, with no additional requests.
Search state belongs to each card and geographic scope; indicator changes retain it and
switching datasets starts with an empty search.

Search narrows only the area selector. The selected area remains available and explicitly
labeled when outside the matching results. A live match count, no-match state and keyboard
clear action keep the native selector usable. Maps, legends, totals, complete tables and
JSON/PNG exports are not filtered. The UI states this distinction next to the search.
No unsupported geography, new source values or boundary acceptance is inferred.

Validation: three search unit tests; full database-backed Vitest 1,486 passed, 11 skipped.
Lint (existing fixture warning), typecheck, migration lint and prefixed no-data production
build passed. All 22 initial map browser cases passed; both focused search cases then passed
after adding independent portal-card coverage and a real complete JSON download while the
selector was filtered (23 distinct final cases). Phone-width checks cover accent/code
search, no matches, retained selection, keyboard focus, unchanged table/total, no extra
requests and dataset switching. Existing desktop/phone PNG capture checks still pass.

## Portal lifecycle and throttled performance baseline (8 November 2026)

Added browser regression coverage for hidden-dataset requests, offscreen map disposal,
returning-card state/data reuse and peak live-map counts, including a tall desktop with
several visible cards. Added an opt-in six-run Chromium timing probe and an idle/drawable
readiness marker; canvas insertion alone is not treated as a completed render.

The resource lifecycle checks pass. The throttled 390px profile misses the three-second
readiness target: portal median 3,340ms and enlarged-map median 3,093ms after document/card
availability; navigation-to-ready is longer. All six samples miss, so no performance
acceptance is claimed. See [profile, recorded evidence and remaining work](DATASET_MAP_PERFORMANCE.md).
No hidden dataset prefetching, additional live maps, CSP changes or boundary publication was
introduced. Production storage and real-device performance remain unverified.

Validation: seeded Vitest 1,486 passed, 11 skipped; lint (existing fixture warning), typecheck,
migration lint and prefixed no-data build passed. The 23 existing map browser cases passed;
both new lifecycle cases passed after correcting the return-scroll target to the map rather
than the whole tall card (25 distinct final cases). Six throttled timing runs completed
separately from tests/builds; their target misses are recorded, not treated as test passes.

## Parallel visible-map initialization (#469, 8 November 2026)

The measured aggregate → canvas-module → renderer waterfall delayed first drawing despite
renderer code being independent of the dataset values. Visible cards with activity leases
now load those modules alongside the aggregate request, while retaining lazy initialization
for hidden/planned cards. Map/worker creation still waits for valid data. No extra dataset
reads, additional live maps, boundary promotion or CSP changes.

Fresh three-run medians improved from 3,191ms to 2,461ms in the portal and 3,140ms to 2,695ms
in the enlarged view. All six optimized post-document/card samples meet 3,000ms. Cold
navigation, production delivery and physical-device performance are separate; see the
[performance comparison](DATASET_MAP_PERFORMANCE.md) and retained request waterfalls.

Validation: database-backed Vitest 1,489 passed, 11 skipped; all 25 map browser cases passed,
including hidden requests, disposal/reuse, peak-map count, independent state, failure paths
and desktop/phone PNG exports. Lint (existing fixture warning), typecheck, migration lint
and prefixed no-data production build passed. Measurements ran separately from those checks.

## Recoverable map rendering failures (8 November 2026)

Canvas-reported boundary/renderer failures now offer **Retry map rendering** outside the
assistive-technology-hidden canvas. A retry disposes the failed canvas and recreates only
that dataset's renderer. It clears stale geometry/capture state but retains indicator,
period, geographic selection, search, public aggregate payload, complete table and totals.
It does not reread dataset values or trigger neighboring maps. A loading status announces
the retry; repeated failures keep the table and JSON available and PNG disabled.

The retry preserves the existing map lifecycle cleanup and limits. It cannot repair an
invalid source, create missing polygons, enable permanently unavailable WebGL or approve
candidate boundaries; those cases remain explicit failures with usable data alternatives.
This addresses transient geometry requests and renderer failures caught by DatasetCanvas,
not a failure to download the page's own JavaScript.

Validation: database-backed Vitest 1,489 passed, 11 skipped; lint (existing fixture warning),
typecheck, migration lint and prefixed no-data production build passed. All 27 browser cases
passed, followed by the added WebGL-recovery case (28 distinct final cases). New checks use
keyboard activation and cover a temporary 503, repeated failure, one aggregate read across
retries, retained selection/search, PNG readiness and recovery after WebGL becomes available.
