# Cebu and independent-city boundary repair

18 November 2026. This is a geometry/PSGC join repair, not a workforce-data load.

## Source and historical evidence

Use the supplier's archived **2023-4q** collection, pinned to
[`209763ab80e8a3def8446fad878a726749128942`](https://github.com/faeldon/philippines-json-maps/commit/209763ab80e8a3def8446fad878a726749128942):

- Path: `2023/geojson/provdists/lowres/municities-provdist-702200000.0.001.json`
- File SHA256: `9e285b8f6882abfd90f80c2314e8cc5122f745d16e5b9beca01db7082efaccc9`
- [Archived release](https://github.com/faeldon/philippines-json-maps/releases/tag/2023-4q), published 25 October 2026, explicitly preserves the former `2023/` tree at that commit.
- `geojson-lowres.zip` SHA256: `98edb8416672b135c9df0a4e0b02b2b09cc20fa2bbb33f9878a1d492c31aa855`, verified against the release's `SHA256SUMS`.

The supplier's commit documents regenerated historical maps with missing cities
restored and independent cities grouped under their geographic province. The
source contains 53 Cebu cities/municipalities, including the three HUCs. Its
medium-resolution archived Cebu collection is byte-identical to the previously
reviewed 2026 candidate; this repair uses the lower-resolution historical file,
matching the existing importer's resolution. A 2023 PSGC label is not a claim
that the archive was published in 2023 or that it supplies cadastral/survey detail.

| Source city code (10 digits, padded) | App city row | App independent province row |
| --- | --- | --- |
| 0730600000 | 0730600 — Cebu City | 07306 |
| 0731100000 | 0731100 — Lapu-Lapu | 07311 |
| 0731300000 | 0731300 — Mandaue | 07313 |

A bounded live public geography check on 18 November returned all five Region VII
province entries and the corresponding single city child for each of these HUCs.
Only codes and names were used; no workforce values or personal records enter
this change. The committed development reference agrees. This is not a complete
production geography extraction or nationwide historical crosswalk audit.

## Coherent partition and affected assets

Run `python ingestion/reconcile_cebu_huc_boundaries.py` after the general boundary
importer. It uses Shapely, as the general importer does, and verifies the pinned
source checksum before writing anything.

- Retain all 50 ordinary Cebu municipality codes in `citymun/07022.json`, using
  their geometries from the same archived collection as the HUCs.
- Dissolve those 50 into Cebu province. Keep each HUC's supplied city geometry
  unchanged as its independent province polygon and its one-city child file.
- Keep Bohol's feature unchanged and write the five-entry `provinces/07.json`.
- Recompose only Region VII in `regions.json` from those five entries. All other
  17 national region features remain unchanged.

This is deliberately not an append to the old Cebu outline: that outline
includes the HUC areas. Nor are old and regenerated city shapes mixed: a measured
Cordova/Lapu-Lapu overlap would remain. Fourteen of the 50 ordinary municipality
geometries are identical to the old assets; the other 36 follow the regenerated
historical source. No manual clipping, guessed boundary or representative point
stands in for a missing polygon.

Shapely 2.1.2 validation: all 53 source geometries valid/nonempty, no positive-area
pair overlaps; rebuilt Cebu plus three HUCs has zero symmetric difference from
the source union. The five regional entries are valid and disjoint. The script
checks these invariants before writing. Computations use WGS84 planar coordinates
as topology diagnostics, not geodesic area statistics. The existing Cebu outline
and the new 53-area union differ by about 1.14% of the old planar area; this is a
source/generalization change and is not hidden as an identical outline.

Gzip sizes before/after in the local diagnostic: national regions 179,728/177,295
bytes; Region VII provinces 10,973/10,444; Cebu municipality collection
11,368/12,239. These are file compression measurements, not live transfer timings.

## Attribution and acceptance limits

Underlying boundaries: NAMRIA and PSA via OCHA COD-AB,
[CC BY 3.0 IGO](https://creativecommons.org/licenses/by/3.0/igo/).
Supplier maps/scripts: [faeldon/philippines-json-maps](https://github.com/faeldon/philippines-json-maps), MIT.
The supplier's full MIT copyright/permission notice is distributed with the
assets at `public/geo/LICENSE.faeldon.txt`.
Adaptations are the PSGC joins, independent-city partition and regional dissolve
above. Repaired assets carry provenance; the map footer and PNG/JSON exports
carry geometry credits separately from the observation dataset's license.
The Headcount observation license remains not supplied.

The owner confirmed the previously displayed map's compatibility with 2025 data.
The new shapes have the historical-source, join and topology evidence above; the
owner's earlier confirmation is not represented as inspection of this unpublished
repair. Nationwide historical acceptance, other missing boundaries and absent
observation values remain separate. A shape does not supply a missing value.

Unit/browser checks cover all three HUC locators and child joins, retained null
values, source credits and the deliberately missing-shape fallback. The latter
uses an explicit fixture now that the real assets have shapes. Required checks,
CI, release SHA and bounded live observations are recorded in the delivery PR.
