# Preserve barangay parts and interior rings during simplification

8 November 2026. Follows the [national build failures](BARANGAY_NATIONAL_BUILD.md).
This increment fixes the widespread component loss in the experimental pipeline by
simplifying individual source rings as protected features, then reconstructing each
barangay from an exact input manifest. **The result is still not publication-ready.**
No application assets, historical crosswalks or observations changed.

## Preparation and reconstruction

`ingestion/barangay_ring_pipeline.py prepare` verifies the upstream archive checksum,
reads only `Bgy` records and inventories every polygon part and its interior rings.
It preserves native coordinates and CRS, assigning each ring a stable code/part/ring key.
An interior ring is temporarily exported as a standalone polygon solely to protect it
from being dropped by simplification; its role as a hole stays in the manifest.
Reversing ring winding for shapefile shell orientation changes no coordinates or extent.

All **45,585 rings** (45,573 exteriors and 12 holes) are imported together into Mapshaper
0.6.113 for WGS84 projection and 10% simplification with `keep-shapes` and the default
intersection-repair behavior. This protects each part rather than only each barangay
record. No independent municipality simplification, snapping or clipping is used.
Mapshaper reported 128 repaired intersections and nine it could not repair; its log alone
is not an acceptance test.

The `assemble` command pins both the source manifest and simplified rings by SHA256.
It requires exactly one output for every expected ring. Missing, duplicate, invalid,
newly multipart or newly holed ring outputs block the entire barangay. Unknown ring keys
or an inconsistent manifest fail the operation. It reconstructs the original Polygon/
MultiPolygon structure and checks validity; it never fills a missing hole or emits only
the available islands. Blocked barangays remain records with **null geometry** and a
reason in the report. Null source records stay null.

## Full-country result

| Check | Previous 10% default-repair build | Ring-preserving reconstruction |
| --- | ---: | ---: |
| Source records / output records | 42,010 / 42,010 | 42,010 / 42,010 |
| Unique codes missing or added | 0 | 0 |
| Polygon components | 45,080 | 45,572 |
| Source components absent from output | 493 | 1 (whole barangay withheld) |
| Barangays losing components | 216 | 1 (withheld) |
| Holes retained | 9 of 12 | 11 of 12 (one in withheld barangay) |
| Null output records | 4 | 5 |
| Invalid **non-null** output polygons | 0 | 0 |
| Positive-area overlap pairs | 11 | 8 |

The four original source-null records remain unavailable. One additional barangay is
withheld because its interior ring cannot be represented validly after projection. Its
native hole is an extremely thin four-vertex polygon: area approximately
`2.10e-10 m²`, minimum clearance `4.91e-11 m`. Its bounding extent is several metres;
small area does not mean its coordinates are all in one tiny location. A direct PyProj
3.7.2 transformation also collapses this ring. It has not been silently deleted or filled.
All other source parts and holes are retained, with no gained parts/holes. The full
validator deliberately continues to flag the withheld unit's component/hole loss.

The earlier six newly invalid unsimplified polygons also fail a direct PyProj transformation
without Mapshaper, so their problem cannot be attributed solely to Mapshaper's import.
The new simplified reconstruction emits no invalid non-null polygons, but that does not
establish cartographic or historical acceptance. Default simplification repair remains an
explicit part of this experimental transformation, not permission to repair source conflicts.

The eight remaining overlap pairs were compared with their unsimplified source geometries:
four already overlap upstream and four only touch upstream. Withheld polygons are not part
of those pair checks. These overlaps remain blockers; no tolerance or source correction was
invented to remove them.

The geography comparison still has 41,580 same-code reference matches, 411 missing reference
codes and 430 unmatched source codes. These are local development geography counts, not a
fresh production certificate. Adding four null source records compared with the supplier
archive does not add four usable boundaries. Historical Sulu and other mismatches remain.

## Reproduce

Use the exact upstream source/archive from the national assessment and existing Python
geography dependencies. Keep all source extracts, manifests, detailed reports and candidate
geometry outside git. Neither stage publishes assets or changes dataset boundary versions.

```sh
python ingestion/barangay_ring_pipeline.py prepare \
  --source /private/source.zip \
  --sha256 0c1627035e1bef618a7f57ca96bc93822710aef03aa0c273a922f3b7cd3b198a \
  --output /private/NEW-rings-input

NODE_OPTIONS=--max-old-space-size=12000 \
  npx --yes --package mapshaper@0.6.113 -- mapshaper /private/NEW-rings-input/rings.shp \
  -proj wgs84 -simplify 10% keep-shapes \
  -o format=geojson /private/NEW-simplified-rings.geojson

python ingestion/barangay_ring_pipeline.py assemble \
  --manifest /private/NEW-rings-input/manifest.json --manifest-sha256 ACTUAL_MANIFEST_SHA256 \
  --rings /private/NEW-simplified-rings.geojson --rings-sha256 ACTUAL_RINGS_SHA256 \
  --output /private/NEW-assembled.geojson --report /private/NEW-assembly-report.json
```

Preparation creates a new directory and records source and shapefile hashes. The manifest
must exist and preparation must succeed before its output is used; an interrupted preparation
directory is not a completed artifact. Assembly refuses existing paths and returns **1** when
any record is withheld, while writing the complete record list and blocked-reason report.
It returns **2** for input/configuration errors. A zero exit still does not approve publication.
Run the [national validator](BARANGAY_NATIONAL_BUILD.md) on the reconstructed file afterward;
assembly alone does not test all cross-barangay overlaps or geographic vintage compatibility.

`ingestion/barangay_ring_summary.json` commits aggregate results, command/tool version,
source/manifest/output hashes and the unchanged publication block. Detailed identifiers,
input and output geometry remain outside git. Preserve source notices and NAMRIA/PSA
through OCHA COD-AB CC BY 3.0 IGO attribution for any future accepted distribution.

## Remaining work

Resolve or explicitly withhold the four source-null records and the projection-degenerate
unit based on reviewed source evidence; do not fill a hole merely because it is inconvenient.
Investigate the four downstream overlap pairs separately from the four upstream conflicts.
Accept historical crosswalks and per-dataset vintages before any public barangay joins.
Validate additional detail levels and delivery partitions, including shared edges and all
parts/rings, before packaging accepted assets into persistent storage or vector tiles.

Eight new tests cover preserved holes/islands, missing or duplicate rings, changed topology,
invalid reconstruction, unexpected keys, null-source handling, coordinate-preserving
preparation, pinned hashes, refused overwrites and the nonzero withheld-output exit.
