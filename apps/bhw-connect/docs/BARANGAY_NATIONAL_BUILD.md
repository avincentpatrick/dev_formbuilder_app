# Nationwide shared-topology build — retention and validity gate

8 November 2026. Extends the [two-municipality trial](BARANGAY_TOPOLOGY_COMPARISON.md).
**None of the three national outputs passed the publication prerequisites.** No geometry
was deployed, no dataset boundary version changed, and no values were redistributed.

## Reproducible builds

All runs used the pinned unsimplified source archive from
`faeldon/philippines-psgc-shapefiles` v1.0.1, verified SHA256
`0c1627035e1bef618a7f57ca96bc93822710aef03aa0c273a922f3b7cd3b198a`,
and Mapshaper **0.6.113**. Each imports the whole country before filtering to `Bgy`,
projects to WGS84 and exports a single GeoJSON, without splitting municipal delivery files.
These diagnostic imports include the original source topology before the non-barangay
features are filtered; they do not claim to isolate every import/projection effect.

```sh
NODE_OPTIONS=--max-old-space-size=12000 \
  npx --yes --package mapshaper@0.6.113 -- mapshaper /private/source.zip \
  -filter 'geo_level == "Bgy"' -proj wgs84 \
  -simplify 10% keep-shapes no-repair \
  -o format=geojson /private/NEW-joint-10.geojson
```

Two controls used the same source, filter, projection and output format:

- **10% with default intersection repair:** omit `no-repair`. Mapshaper reported 121
  repaired intersections and nine it could not repair. This is a diagnostic control,
  not approval to repair upstream conflicts.
- **Unsimplified joint export:** omit the entire `-simplify …` command. This baseline
  tests import/projection/export behavior independently of vertex reduction.

The raw shapefile has **42,010 barangay records**, not the simplified supplier archive's
42,006. The extra four source records have null shapes. They remain null in every output;
they are not newly recovered boundaries. Every output retains all source codes exactly,
without duplicate or unexpected barangay codes. The upstream geometry inventory has
**45,573 polygon components and 12 holes**, with four invalid/null records.

## Measured results

| Check | 10%, repair disabled | 10%, default repair | Unsimplified export |
| --- | ---: | ---: | ---: |
| Barangay records / unique codes | 42,010 | 42,010 | 42,010 |
| Missing source codes | 0 | 0 | 0 |
| Polygon components | 45,080 | 45,080 | 45,573 |
| Barangays losing components | 216 | 216 | 0 |
| Total components lost | 493 | 493 | 0 |
| Holes | 9 | 9 | 11 |
| Barangays losing holes | 3 | 3 | 1 |
| Newly gained components/holes | 0 / 0 | 0 / 0 | 0 / 0 |
| Invalid/null output records | 108 | 4 | 10 |
| Newly invalid, previously valid source records | 104 | 0 | 6 |
| Positive-area overlap pairs among valid output polygons | 16 | 11 | 5 |

`keep-shapes` protects feature survival; **it does not guarantee retention of every island
or interior ring**. Default intersection repair eliminates the new invalid polygons in
that control but does not restore lost components or holes and does not remove all overlaps.
The unsimplified export keeps every component yet loses one hole and introduces six invalid
polygons. Removing simplification alone is therefore insufficient; import/projection/export
also needs investigation. No blanket snapping, clipping, hole filling or source-shape repair
has been applied to make these results pass.

Overlap counts exclude invalid polygons, which remain blockers in their own right.
Consequently a smaller overlap count cannot by itself establish a better or acceptable build.
The unsimplified result's count of five is not asserted to be the same five upstream pairs
without a pair-level comparison. Components are geometric polygon parts, not a certified
island inventory; losing them blocks acceptance until reviewed.

All three builds have 41,580 same-code reference matches, 411 missing reference codes,
430 unmatched source codes and no matched-parent mismatches after the documented Manila
hierarchy normalization. The apparent four-code improvement over the earlier candidate
assessment consists entirely of the null source records. It adds no usable geometry.
Historical Sulu and other crosswalk gaps remain unresolved. Counts refer to the local
geography-only development extract, not a fresh production inventory.

## New validation tool

`ingestion/validate_national_barangay_build.py` binds the source archive, generated candidate
and permitted reference extract by SHA256. It inventories upstream polygon parts, holes
and validity, then checks output code retention, duplicates, unexpected levels/codes,
new invalid polygons, per-code lost **or gained** components/holes, reference reconciliation,
parent compatibility and positive-area overlaps. Upstream null/invalid geometry blocks
acceptance even if a candidate supplies a new polygon for that code; an absent source shape
is not permission to invent one.

```sh
python ingestion/validate_national_barangay_build.py \
  --source /private/source.zip \
  --source-sha256 0c1627035e1bef618a7f57ca96bc93822710aef03aa0c273a922f3b7cd3b198a \
  --reference /private/reference.json --reference-sha256 ACTUAL_REFERENCE_SHA256 \
  --candidate /private/NEW-joint-10.geojson --candidate-sha256 ACTUAL_CANDIDATE_SHA256 \
  --output /private/NEW-validation.json
```

Exit **1** writes the failed-gate report with its blocking checks. Exit **2** indicates an
input/configuration error. Exit **0** means automated retention/topology checks passed,
not release approval: `publication_ready` remains false until historical, cartographic,
licensing and delivery checks are accepted. Existing reports are never overwritten.

All source and candidate identifiers in detailed reports stay outside git. The committed
`ingestion/barangay_national_build_summary.json` contains aggregate results, exact output
sizes/hashes and commands. The outputs are approximately 111 MiB for each 10% variant and
644 MiB unsimplified. These are diagnostic country files, not acceptable homepage downloads;
accepted assets must be partitioned or tiled only after building and validating shared topology.
Preserve the existing MIT and underlying NAMRIA/PSA through OCHA COD-AB CC BY 3.0 IGO notices
when any later accepted assets are redistributed.

## What this changes for the next build

The small trial's success does not authorize using the same settings countrywide. A future
build must protect individual polygon components and holes, investigate the six baseline
validity failures and missing hole, and handle upstream null/conflicting records explicitly.
Test multiple detail levels and partition edges against the source/reference before publishing.
No tolerance or silent repair is introduced by this gate. Source invalid records and historical
mismatches may require a partial availability policy backed by per-area evidence; this increment
does not approve or publish such a subset.
