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
