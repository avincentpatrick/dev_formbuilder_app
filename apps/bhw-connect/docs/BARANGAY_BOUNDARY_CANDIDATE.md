# Barangay boundary candidate — nationwide assessment

8 November 2026. **Not accepted for publication.** This assessment compares a pinned
supplier archive against a permitted geography-only extract from the local development
database. That database has real geography and synthetic observations; no observations
were read. It is not a fresh production-database coverage certification. No public map
assets, dataset values, historical crosswalks or server settings changed.

## Source, provenance and licensing

- Supplier: `faeldon/philippines-json-maps`, release **v1.1.1**, PSGC **2026-3q**,
  built 7 November 2026; upstream shapefile source tag **v1.0.1**.
- [High-resolution GeoJSON archive](https://github.com/faeldon/philippines-json-maps/releases/download/v1.1.1/geojson-hires.zip),
  93,831,214 bytes. Its SHA256 matches the supplier's `SHA256SUMS`:
  `d6aedda878af0884f112d992183639df61f5e74af380cf71a589055f9437a348`.
- Metadata and documentation pinned at commit
  `680e32e62baeccbcd8845d3b1387bc67c1baf78a`.
  [Supplier format and attribution](https://github.com/faeldon/philippines-json-maps/blob/680e32e62baeccbcd8845d3b1387bc67c1baf78a/README.md).
- Scripts/maps carry James Faeldon's MIT license. Underlying boundaries require
  **CC BY 3.0 IGO** attribution to **NAMRIA and PSA through OCHA COD-AB**. Preserve both
  notices and identify adaptations if assets are later republished; MIT alone is insufficient.
- The supplier's “hires” retains 10% of original vertices. It is still simplified geometry,
  not unsimplified survey data. Higher detail was selected to avoid low-resolution collapse;
  storage size is not a reason to accept topology defects.

## Measured results

| Check | Count |
| --- | ---: |
| Reference barangays | 41,991 |
| Supplier barangay features / unique codes | 42,006 / 42,006 |
| Same-code matches | 41,576 |
| Reference codes missing from supplier | 415 |
| Supplier codes absent from reference | 430 |
| Duplicate supplier barangay codes | 0 |
| Null, structurally invalid or individually invalid supplier barangay polygons | 0 |
| Positive-area overlap pairs | 10,322 |
| Matched reference barangays touching those overlaps | 12,199 |
| Candidates passing code, parent, individual geometry and overlap checks | 29,377 |

“Candidates” does not mean accepted historical boundaries. Zero missing geometry among
supplier features also does not establish completeness: the supplier omits barangays for
which it lacks a shape. Individual polygon validity does not establish shared-edge topology.
9,781 overlap pairs exceed one millionth of the smaller polygon's area; the maximum fraction
is 1.0. These cannot all be dismissed as floating-point noise. The diagnostic ratio is not
an acceptance tolerance. No overlap is clipped, repaired or silently tolerated.

The archive contains 1,642 municipal files in the chosen resolution. Only `geo_level: Bgy`
features count as barangays. Fourteen Manila `SubMun` polygons are separately recorded and
excluded, preventing their dissolved shapes from overlapping/counting their own barangays.
Two untyped Manila features (the cemetery and a disputed mall area) are recorded as excluded
source features, never assigned to a barangay. Geometry origins are retained: 41,998 `source`,
one `dissolved`, seven `plotted`; those origins do not establish historical compatibility.

## Geography changes and hierarchy

| Reference group | Reference | Same-code matches | Missing | Overlap-affected |
| --- | ---: | ---: | ---: | ---: |
| NCR | 1,701 | 1,701 | 0 | 313 |
| Manila | 883 | 883 | 0 | 38 |
| NIR | 1,353 | 1,353 | 0 | 391 |
| Sulu | 410 | 0 | 410 | 0 |
| BARMM | 2,590 | 2,177 | 413 | 742 |
| SGA | 63 | 63 | 0 | 51 |
| HUC parents (explicit reference `(HUC)` labels) | 3,046 | 3,046 | 0 | 465 |

Groups overlap and must not be summed. Counts use this extract only, not older reconciliation
report denominators. SGA is reference province `19999`; Sulu remains `19066` in the extract.

The supplier stores Manila barangays under the whole-city `adm3_psgc` but documents their
submunicipality as the barangay code with its last three digits zeroed. The adapter applies
that **documented hierarchy rule only**, retains raw and normalized parent codes in the
report, and excludes `SubMun` features. It records 897 source adaptations, of which 883 match
the reference. All remaining matched candidates agree with the reference parent. This is not
a historical transfer or a redistribution of counts.

The 430 unmatched source codes comprise 410 in Region IX, 14 in NCR, five in BARMM and one
in Caraga. The 415 missing reference codes comprise 413 in BARMM (including all 410 Sulu
barangays), one in Region IV-A and one in Region XII. Sulu's change of region is a crosswalk
review item, not permission to replace historical prefixes or move historical totals.
Same-code NIR/SGA matches likewise do not certify compatibility for each dataset vintage.
No fuzzy name matches, fabricated split/merge weights or automatic historical remaps occur.

## Reproduce the assessment

`ingestion/reconcile_barangay_candidate.py` reads an archive offline, checks its checksum
and the reference checksum before processing, and writes a new report. It needs Shapely 2
(validated with 2.1.2). The reference is a JSON array from `dim_geo` with `geo_code`,
`geo_level`, `geo_name`, `region_code`, `province_code`, and `citymun_code`. Include province
rows to identify HUCs by their explicit labels. Do not include observation or personal tables.
Barangay reference codes must already be ten-digit strings; duplicates fail validation.
Supplier numeric codes are zero-padded to ten digits according to its documented format.

```sh
python ingestion/reconcile_barangay_candidate.py \
  --archive /private/geojson-hires.zip \
  --sha256 d6aedda878af0884f112d992183639df61f5e74af380cf71a589055f9437a348 \
  --reference /private/reference.json \
  --reference-sha256 ACTUAL_REFERENCE_SHA256 \
  --release v1.1.1-PSGC-2026-3Q \
  --source https://github.com/faeldon/philippines-json-maps/releases/download/v1.1.1/geojson-hires.zip \
  --reference-description 'Describe the permitted extract and its vintage' \
  --output /private/NEW-barangay-reconciliation.json
```

Exit 0 means assessment completed, **not release accepted**; `publication_ready` always stays
false. Exit 2 means invalid input/configuration. Existing reports are never overwritten.
The detailed report lists missing/unmatched/duplicate codes, rejected identifiers, parent
adaptations/mismatches, invalid geometry, overlap pairs and candidate identifiers. Keep the
extract, archive and detailed report outside git. The committed
`ingestion/barangay_candidate_summary.json` contains aggregate results and reproducibility
metadata only. Its reference hash identifies the local extract, not an authorized production
snapshot. The tool never emits public polygons or changes dataset boundary versions.

Run the ten candidate tests and thirteen existing district/basic boundary tests:

```sh
python -m unittest discover -s ingestion -p 'test_barangay_candidate.py'
python -m unittest discover -s ingestion -p 'test*boundar*.py'
```

The district builder's earlier nine-digit barangay check was incorrect. This increment fixes
it to the app's actual **ten-digit** codes and tests both acceptance of ten digits and rejection
of nine. No prior district assets were generated or published using that new builder.

## Remaining acceptance work

1. Review overlap pairs against unsimplified upstream geometry and generate shared-edge-safe
   detail levels if appropriate. Do not choose a blanket tolerance or automatic polygon repair
   merely to make the report pass; retain small islands and disputed areas explicitly.
2. Obtain reviewed per-vintage crosswalk evidence for Sulu and the remaining missing/unmatched
   codes, and repeat against an authorized current geography extract before production claims.
3. Review historical compatibility separately for each dataset, including plotted/dissolved
   geometries, transfers, splits and merges. Counts must not be redistributed without evidence.
4. After acceptance, package versioned per-area geometry or vector tiles in persistent storage,
   retain attribution and rollback artifacts, validate the actual delivery route and CSP, then
   enable barangay drill-down only where both data and geometry support it.

This assessment resolves the full-candidate inventory gap. It intentionally does not enable
barangay polygons prematurely or replace the existing self-hosted geography.
