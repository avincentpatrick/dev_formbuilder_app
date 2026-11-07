# Dataset maps technical assessment and implementation plan

Status: planning only. Assessed 7 November 2026. No application, database, deployment or dataset changes are authorized by this document alone.

Implementation has begun at the owner’s request: see [foundation progress and validation commands](DATASET_MAPS_IMPLEMENTATION.md). Card/fullscreen rollout and production validation remain pending.

Every dataset card gets its own embedded map. Each map represents that dataset's indicators, geography, reporting period and source. This applies to existing datasets, additional ingested datasets and planned dataset cards. There is no privileged BHW map with the other datasets relegated to overlays.

The confirmed scope includes embedded maps and a dedicated Map page, dataset-specific fullscreen views, and barangay drill-down where the source data and reliable boundaries support it. Storage capacity is not a current constraint. Shared software and basemap assets are compatible with independent dataset experiences.

## Product scope

“Card” means a dataset entry, as clarified by the owner, rather than every statistical figure receiving another map instance. Each dataset dashboard can reuse its dataset's map and provide Map, Chart and Table representations where meaningful. Figure-level map entry points can select an indicator in that same dataset experience.

Each embedded map must have its own indicator, legend, period, selection and reporting coverage. Enlarging it preserves those choices. The dedicated Map page selects a dataset without falling back to BHW indicators. Switching datasets preserves a geographic selection only when the destination supports it; otherwise explain the supported parent level. Changes in one portal card must not silently change neighboring dataset maps.

Planned cards have a map slot and capability definition from the outset. Until their figures are available, show a clearly labeled availability state or geographic coverage preview. Do not shade a map with invented values. “Ways forward” is currently a roadmap placeholder, not an ingested dataset with map-ready measures.

## Verified findings

| Finding | Evidence | Consequence |
| --- | --- | --- |
| Portal has five linked dataset cards and a Ways forward placeholder | `app/page.tsx`, `components/portal/dataset-card.tsx` | Replace whole-card anchors with card containers, separate dashboard links and map controls. Interactive controls cannot be nested inside the existing link. |
| MapLibre is already installed at the declared `^6.16.0` range | `package.json` | Reuse it; PMTiles integration and basemap assets still need adding. |
| Existing maps use a blank background and 320px height | `components/maps/choropleth-map.tsx`, `components/maps/point-map.tsx`; live desktop and phone inspection | Add geographic context and a usable expanded view. Embedded size should suit each card rather than copying the current chart dimensions. |
| Map filtering assumes BHW indicators | `lib/filters/schema.ts`, `components/explore/geo-comparison-figure.tsx` | Introduce dataset-specific configuration and URL state. The old comment excluding non-BHW map indicators is superseded by this owner's expanded scope. |
| Barangay files contain centre points, not polygons | Inventory of `public/geo`, `lib/geo/barangay-centroids.ts` | True barangay boundary drill-down requires a new boundary preparation pipeline. |
| FHSIS is unavailable on the live national page | Public `/health-services` read on assessment date | Keep its independent map in scope, but gate numeric display on verified accessible figures. |
| The live static route supports byte ranges | Request to `/bhw-connect/geo/regions.json` with `Range: bytes=0-127` returned 206, 128 bytes and `Content-Range: bytes 0-127/534649` | PMTiles delivery is plausible on the current host; a real archive still needs an end-to-end test. |

### Boundary inventory

Current committed geography is approximately 5 MiB on disk:

| Asset | Files | Feature count |
| --- | ---: | ---: |
| Regions | 1 | 18 |
| Province-level boundaries | 18 | 88 |
| City/municipality boundaries | 83 | 1,601 |
| Legislative districts | 1 | 189 |
| Barangay centre-point dictionaries | 117 | 41,085 placed points in the reconciliation report |

Five city/municipality features have null geometry. The existing boundary reconciliation report records 34 missing province-level and 51 missing city/municipality entries. The centroid report records 906 barangays without a point out of 41,991 in its reference geography. The district report records 42 cases requiring barangay-grain geometry and 19 missing city/municipality source cases. These are stored report results from different preparation runs, not a fresh nationwide comparison against the production database; their denominators must not be mixed.

The existing boundary supplier now advertises PSGC 3Q 2026. Its `latest/manifest.json` reported version 1.1.1, built 7 November 2026. A fetched Region II province sample contained five features; after zero-padding and province-code extraction, all five codes matched the local sample. This establishes sample compatibility only. The sample exposes `adm2_psgc`, not the app's added `geo_code`, so a normalization adapter remains necessary.

## Dataset coverage and required work

Availability below describes inspected code and stored schema, not certification of every production row.

| Dataset or reference source | Independent map content | Finest supported or proposed detail | Required work and limits |
| --- | --- | --- | --- |
| BHW Census 2025 | Accreditation, training, honorarium, workforce and demographic measures | Barangay where aggregate and disclosure rules permit | Adapt existing aggregate readers. Preserve measure-specific denominators, small-cell and complementary suppression; never map personal records. |
| StepZero 2026 | Total, registered and accredited BHW counts; source population and household measures | Barangay where source rows exist | Give it its own configuration and reporting period. Keep totals distinct from validated BHW profiles. |
| Profiling status 2026 | Encoding, validation and approval progress | Aggregate readers support geography levels; verify actual rows at each level | Extend current region/province navigation where data supports it. Inclusion in this plan does not reverse an earlier decision to hide a homepage card. |
| UUC for PHC 2025 | Listed barangays, counts and supported criteria/indicator summaries | Barangay membership | Build polygon joins. Distinguish official list membership from an indicator value or a newly inferred classification. Preserve existing cleaning/quality rules; do not average source percentages into regional rates. |
| NHFR September 2026 | Facility counts, types, ownership and barangays with registered facilities | Barangay aggregation | Current source lacks facility coordinates. Use barangay polygons or explicitly labeled representative points, never apparent exact facility pins. Reconcile unplaced counts against authoritative totals. |
| Legislative districts | District membership and available district measures | District polygons with validated municipal/barangay membership | Keep district slugs separate from PSGC levels. Rebuild incomplete shapes from validated membership; do not infer a complete district from only its available members. Current BHW district aggregate exposes a limited measure set. |
| FHSIS 2025 | Health-service indicators and non-BHW workforce measures | City/municipality; individual indicators may be coarser | Existing child reader is hardcoded to fully immunized children. Generalize it by supported indicator. Preserve published units, numerator/denominator definitions and Q3–Q4-only ANC8 period. Do not manufacture barangay rates or average percentages. Resolve live availability first. |
| PSA population 2024 | Census population by supported area | Verify available aggregate rows and source coverage | Create a standalone adapter using `agg_population`. Show census population itself; do not silently substitute StepZero population in this dataset's map. |
| PSA SAE poverty | Poverty incidence and published uncertainty | City/municipality only | Existing reader uses 2023 estimates, with 2018/2021 back-estimates in the source. HUC exclusions are missing coverage, not zero poverty. No invented barangay estimates or unweighted regional rollups. |
| DOF/BLGF income classification | Published LGU income class | Applicable province/city/municipality units | Use `dim_lgu_income_reclass`; categorical legend. A barangay may show its parent LGU context, clearly labeled, but must not appear to have an independently published income class. |
| Future ingested datasets | Their own approved measures | Declared per dataset and indicator | Registration must include geography, source vintage, units, availability and map metadata before publishing the card. |

Population, poverty and income-class reference data are presently used within other analyses. Their standalone map adapters are included in the expanded scope; card publication follows their data readiness, not assumptions from a schema table's existence.

## Proposed architecture

Use MapLibre for rendering, a self-hosted Philippines-focused basemap, and versioned geographic assets. PMTiles is the preferred delivery candidate, subject to the archive test. Small region/province geometry can remain GeoJSON where it is simpler and fast enough; detailed barangay polygons are the strongest candidate for vector tiles.

Keep three concerns separate:

1. Basemap assets: roads, water, place labels, fonts and sprites, shared across datasets.
2. Analytical boundaries: normalized identifiers and versioned shapes, shared where geographically compatible.
3. Dataset values: bounded, public, disclosure-checked aggregates loaded for that dataset, metric, period and area.

Do not bake sensitive or frequently changing indicators into public tile archives. Separating values from geometry permits dataset updates without rebuilding the basemap, and preserves each dataset's access and disclosure rules.

Add a typed map-capability definition per dataset: slug, default indicator, available indicators, units, denominator, periods, supported geographic levels, boundary version, join strategy, missing/suppressed states, legend rules, source attribution and query adapter. Reuse the existing dataset metadata and registry where suitable; do not expose arbitrary database columns as map indicators.

Provide a shared renderer and card wrapper with dataset adapters. Keep local state independent across portal cards. A fullscreen route or modal serializes dataset, indicator, period, geography and applicable filters. Existing BHW Explore URLs remain valid. All new asset and fetch URLs must honor `/bhw-connect` through `withBasePath()`.

### Interaction and accessibility

- Each card contains a visible dataset map, an indicator selector where useful, a legend, source/date, Open dashboard and Enlarge actions.
- Tap selects an area and opens details; an explicit action drills down. Show breadcrumbs and supported drill levels.
- Phone fullscreen views use a detail panel that leaves enough map visible. Page scrolling must continue to work over embedded maps.
- Search, selection details and table/list alternatives are keyboard-accessible. Current map canvases and their injected controls are deliberately hidden from assistive technology; new visible controls must be outside that hidden container or the accessibility model must be redesigned and tested.
- Distinguish zero, missing report, unavailable dataset, suppressed value and missing geometry. Table totals must remain complete even when shapes are absent.
- Rates and counts have different legend definitions. Label quantiles as relative to the displayed areas, and offer stable breaks where meaningful comparisons require them.
- Dataset exports include metric, period, source, legend and attribution. Map PNG support needs an explicit WebGL capture approach; ordinary DOM capture is not sufficient evidence that maps export correctly.

### Multiple map performance

Storage does not remove browser memory, WebGL or bandwidth constraints. Render geographic previews in off-screen cards; initialize interactive maps as cards approach the viewport, retain state and dispose unused map instances. Do not download nationwide barangay geometry or every dataset's rows for the portal. Share cached basemap resources and limit queries to the current view or hierarchy level. The standalone Map view can allocate more resources to its one active dataset.

Proposed acceptance targets, to validate in the prototype: at most two live embedded WebGL maps at once; immediate textual/loading feedback; no background loading of hidden datasets; and an interactive visible map within three seconds on a documented mid-range phone/network test profile. These are targets, not measured performance claims.

## Boundary preparation

1. Pin a supplier release and checksum; do not serve runtime boundaries from the moving `latest/` path.
2. Normalize codes using the existing PSGC crosswalk machinery. Validate leading zeros, province-level HUC treatment, NCR, Manila submunicipalities, NIR, Sulu, BARMM and SGA changes explicitly.
3. Choose a boundary vintage per dataset. A later split or transfer does not justify redistributing historical totals without an evidenced crosswalk. Preserve unresolved splits/merges as unresolved.
4. Compare the full candidate against a permitted geography extract. Report matched, missing, duplicate and ambiguous codes and null/invalid geometries for every level.
5. Generate multiple detail levels and validate shared edges, small islands, topology and antimeridian/coordinate handling as applicable. Replace the current regex-based bounds extraction with a geometry traversal before relying on it for new geometry types/detail.
6. Build district polygons separately from validated membership, including barangay divisions. Emit completeness metadata.
7. Publish geometry version, provenance and attribution with the assets; retain the previous release for rollback.

Updated faeldon data is the first candidate because of the sample compatibility. HDX COD-AB remains a comparison source, not a presumed replacement: its retrieved metadata describes 17 regions and older geographic validity. Direct PSA/Geoportal requests were blocked during reference research, so no live government map endpoint is assumed usable.

## Hosting assessment

The current deployment is Apache and Node on Windows Server, without Docker. A static PMTiles archive does not require a persistent tile-generation service. Generate archives outside the production request path; serve versioned assets from storage whose lifecycle survives app release rotation.

The successful 128-byte range probe proves the current JSON asset route supports ranges. It does not establish PMTiles MIME handling, archive-size limits, CDN/proxy behavior, conditional requests or sustained bandwidth. The returned JSON cache policy was `public, max-age=0`; immutable versioned map archives should have an appropriate long-lived cache policy.

Before committing to PMTiles delivery, test a representative archive on the actual intended route: valid 206 responses and Content-Range, late-file ranges, unchanged byte offsets without incompatible content transformation, cache validators, repeated view reuse, MIME handling, and full MapLibre rendering under the existing CSP. Prefer same-origin tiles, glyphs and sprites. If another origin is required, add only the exact required sources; preserve the existing security policy.

Archive size, bandwidth and build time are not estimated yet because the geographic extent, zoom/detail levels and assets have not been built. Storage is available, but the selected basemap's license, attribution, update process and distribution terms still apply. Self-hosting removes the hosted-map API subscription, not storage/traffic/maintenance work.

Server-level asset paths, headers or Apache configuration require the existing server operator. Changes to `ops/` retain the repository's shared-path approval rule. This is an implementation dependency, not a request for approval to finish this assessment.

## Implementation phases

1. **Contracts and inventory:** finalize per-dataset capability definitions, enumerate planned additions, document missing metrics and preserve historical source dates. Integrate this requirement into future dataset ingestion and publication checklists.
2. **Geography and delivery:** prepare pinned boundaries, crosswalk/reconciliation reports, representative basemap archive and production-route delivery validation. Keep full-country data preparation separate from UI work.
3. **Independent card prototype:** build two contrasting dataset cards, UUC and NHFR, with independent state, embeds, legends, fullscreen and table alternatives. Validate geometry and point semantics on phones. This proves the framework without assuming BHW is the default.
4. **Coverage rollout:** adapt BHW, StepZero, profiling status, legislative districts, population, poverty and income classification. Add FHSIS's independently configured map once its figures pass the availability check; its explicit unavailable state can ship earlier.
5. **Dedicated Map page and parity:** dataset chooser, shareable state, appropriate barangay navigation, accessible controls, presentation/export support and performance checks.
6. **Planned-feature integration:** FHSIS F3–F6, further UUC criteria/indicators, district geometry completion, map PNG export, and any later spatial-clustering/scorecard work register their own map capabilities. Dataset maps move out of the old E5 deferral; this does not automatically authorize the separate spatial-analysis algorithms.

The new scope supersedes the BHW-only map restriction and the blanket deferral of barangay map support in earlier plans. It does not remove dataset quality, publication, attribution or disclosure requirements. Planned maps are included even when their underlying data work remains pending.

## Verification and remaining dependencies

Implementation checks must cover code joins and vintage changes, supported drill levels, per-dataset state isolation, numeric agreement with the dataset's tables, correct denominators/units, zero/missing/suppressed distinctions, missing polygons, complete counts, mobile gestures, keyboard alternatives, WebGL failure, asset failure and map exports. Performance checks must include the many-card portal, not only a single fullscreen map.

The remaining unresolved items are technical, not missing product choices:

- Nationwide 2026 boundary compatibility and barangay/district completeness need a full reconciliation build.
- Production counts/coverage for each dataset need bounded read-only checks; schema presence is insufficient.
- FHSIS currently displays “Health services data is not available right now.” Follow `FHSIS_DIAGNOSIS.md`; its server-side read-only check remains necessary to distinguish absent facts, registration mismatch and access issues. No reload is justified by the empty UI alone.
- The actual PMTiles archive route and cache policy need operator-assisted validation if server configuration changes are required.
- Final tile sizes, build times and phone performance require the prototype and chosen zoom/detail levels.

## Evidence and references

Local sources: `app/page.tsx`; `components/portal/dataset-card.tsx`; `components/maps/`; `components/facilities/facility-map.tsx`; `lib/filters/schema.ts`; `lib/geo/`; `lib/db/dataset.ts`, `fhsis.ts`, `poverty.ts`, `population.ts`, `profiling-status.ts`; `lib/csp.ts`; `public/geo/`; the three reconciliation JSON reports under `ingestion/`; and the Explore, FHSIS, UUC, NHFR and legislative-district plans under `docs/`.

Online references inspected on 7 November 2026:

- [MapLibre examples](https://maplibre.org/maplibre-gl-js/docs/examples/)
- [Protomaps documentation](https://docs.protomaps.com/) and [PMTiles concepts](https://docs.protomaps.com/pmtiles/)
- [Philippine boundary supplier](https://github.com/faeldon/philippines-json-maps) and [current manifest](https://raw.githubusercontent.com/faeldon/philippines-json-maps/master/latest/manifest.json)
- [HDX Philippines boundary metadata](https://data.humdata.org/api/3/action/package_show?id=cod-ab-phl)
- [MapTiler map designs](https://www.maptiler.com/maps/) as an optional design reference

Assessment activities were read-only inspections and HTTP requests, plus this plan file. No app build or automated test suite was needed for this documentation-only change. Production database access, full boundary processing and a representative PMTiles archive test remain explicitly pending.
