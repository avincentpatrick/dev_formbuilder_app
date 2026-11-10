# Barangay topology: upstream comparison and joint-build trial

8 November 2026. Follows the [nationwide candidate assessment](BARANGAY_BOUNDARY_CANDIDATE.md).
No production boundaries changed. No new dataset values, membership assignments or
historical crosswalks were created. The outputs remain private candidates.

## Upstream comparison

Downloaded the exact upstream source named by the candidate supplier: release **v1.0.1**
of `faeldon/philippines-psgc-shapefiles`, `PH_Adm4_BgySubMuns.shp.zip` (200,432,096 bytes).
Its SHA256 matches the supplier's published checksum:
`0c1627035e1bef618a7f57ca96bc93822710aef03aa0c273a922f3b7cd3b198a`.
The comparison consumes the earlier detailed report by checksum and reads source barangays
by exact ten-digit PSGC code. It excludes non-barangay features and preserves null, invalid,
missing or duplicate upstream cases as unresolved. It does not repair them.

Each reported overlap pair was tested in the common **native source CRS** (WGS 84 / UTM
zone 51N). No cross-CRS area subtraction or distance tolerance is used. A positive-area
intersection is an overlap; a line/point intersection is touching; an empty intersection
is disjoint. Polygon holes are retained.

| Relationship in unsimplified source | Candidate overlap pairs |
| --- | ---: |
| Touching, with no positive-area overlap | 10,314 |
| Disjoint | 3 |
| Overlap already present upstream | 5 |
| Missing/duplicate/invalid source cases | 0 |
| Total examined | 10,322 |

Of the 10,314 touching pairs, **10,310 cross municipal file boundaries**; four are within
one source municipality. The five upstream overlaps comprise three cross-municipality
and two within-municipality pairs. All three disjoint pairs cross municipalities.

This checks only the previously reported downstream overlaps, **not every upstream pair**.
It cannot certify the upstream country's topology or identify every export defect. Touching
or disjoint upstream means a downstream processing difference; it does not alone prove
which reprojection/export/simplification step introduced it. The pinned supplier script
`topojson-municities.sh` imports and simplifies each municipality separately, which motivates
the controlled test below. No tolerance was used to dismiss small overlaps.

## Controlled shared-topology test

Selected complete source municipalities `0102802000` and `0102812000` (Bacarra and Laoag),
containing **123 barangays**. The preparation tool copies their native shapefile geometry,
attributes and CRS without editing coordinates. It produces a joint input plus independent
municipal inputs for a control. These are scoped experiments, not historical acceptance.

Mapshaper **0.6.113** ran the same operations for each input:

```text
-proj wgs84 -simplify 10% keep-shapes no-repair -o format=geojson
```

The `no-repair` flag disables simplification's intersection repair; no snap, clean, clipping
or shape-repair command was used. The control files are separated **before import**, so their
simplification processes cannot share topology. Every output was then checked with the
candidate assessor across both municipalities together.

| Output | Features / unique codes | Polygon components | Invalid polygons | Overlap pairs |
| --- | ---: | ---: | ---: | ---: |
| Supplier hires for these municipalities | 123 / 123 | 123 | 0 | 7 |
| Independent municipal control | 123 / 123 | 123 | 0 | 7 |
| Joint input, simplified together | 123 / 123 | 123 | 0 | 0 |

All 123 codes and their parent matches were retained. There were no missing or unmatched
codes within this scoped reference. The control reproduces the seven supplier overlaps;
the joint build eliminates them without dropping features or components. This supports
**building shared topology before simplification, then splitting delivery files**. It is
not proof that every island, municipality boundary or simplification level will behave
identically. The five known upstream overlaps remain source-review cases, not repair targets.

## Reproduce

Install `ingestion/requirements.txt` (the tools use Shapely 2 and pinned PyShp 2.3.1).
Keep downloaded inputs, geography extracts and detailed reports outside git. Obtain the
supplier source and its `SHA256SUMS` from the pinned release, not a moving branch.

```sh
python ingestion/compare_barangay_topology.py \
  --source /private/PH_Adm4_BgySubMuns.shp.zip \
  --source-sha256 0c1627035e1bef618a7f57ca96bc93822710aef03aa0c273a922f3b7cd3b198a \
  --source-url https://github.com/faeldon/philippines-psgc-shapefiles/releases/download/v1.0.1/PH_Adm4_BgySubMuns.shp.zip \
  --source-release v1.0.1 \
  --candidate-report /private/reconciliation.json \
  --candidate-report-sha256 ACTUAL_REPORT_SHA256 \
  --output /private/NEW-comparison.json

python ingestion/prepare_barangay_topology_trial.py \
  --source /private/PH_Adm4_BgySubMuns.shp.zip \
  --sha256 0c1627035e1bef618a7f57ca96bc93822710aef03aa0c273a922f3b7cd3b198a \
  --municipality 0102802000 --municipality 0102812000 \
  --output /private/NEW-trial

npx --yes --package mapshaper@0.6.113 -- mapshaper /private/NEW-trial/trial.shp \
  -proj wgs84 -simplify 10% keep-shapes no-repair \
  -o format=geojson /private/NEW-joint.geojson
```

Run that same Mapshaper command independently on `municipality-0102802000.shp` and
`municipality-0102812000.shp` from the trial directory for the controls. Do not use a split
command after importing both municipalities as the control; that would already share topology.
The preparation manifest records all input-file hashes, selected source parents and counts.
New output paths are mandatory; existing reports/trials are never overwritten.

To check an output, call the existing `assess()` function with the output features and a
permitted reference filtered to the trial's exact barangay codes. Combine the two independent
outputs before assessing overlaps, or cross-file errors would be missed. Example (Python
launched with the app's `ingestion` directory on `PYTHONPATH`):

```python
import json
from reconcile_barangay_candidate import assess
features = json.load(open('/private/NEW-joint.geojson'))['features']
manifest = json.load(open('/private/NEW-trial/manifest.json'))
codes = set(manifest['barangay_codes'])
reference = [r for r in json.load(open('/private/reference.json'))
             if r['geo_level'] == 'barangay' and r['geo_code'] in codes]
report = assess([(str(i), f) for i, f in enumerate(features)], reference)
print(report['summary'])
```

Compare output polygon-component counts to `polygon_components_by_code` in the preparation
manifest as well. The reference above is scoped from the **input manifest**, so missing output
features remain missing rather than disappearing from the comparison. The trial verified
identical input/output code sets and component counts; full release acceptance additionally
requires cartographic review and historical compatibility.

Both tools exit 0 for a completed assessment/preparation and 2 for input/configuration errors.
`publication_ready` remains false. `ingestion/barangay_topology_summary.json` commits only
aggregate evidence, tool versions, CRS and checksums, including trial output hashes. Detailed
pair identifiers and geometries stay outside git. Preserve the original supplier notices and
NAMRIA/PSA through OCHA COD-AB CC BY 3.0 IGO attribution if artifacts are later redistributed.

Validation: six new tests cover the three pair relationships, invalid/missing shapes, holes,
missing/duplicate source codes, parent scope, checksum/overwrite failures, native-coordinate
preservation and independent control extraction. The earlier ten candidate and thirteen
boundary/district tests also pass. No browser changes are made.

## Next build gate

Build topology jointly across the intended geography before creating delivery partitions;
validate every detail level across partition edges, including polygon components/small
islands. Compare the full rebuilt country with the complete reference, inspect the upstream
conflicts and any new intersections, and resolve historical crosswalks before setting a
boundary version. A successful two-municipality trial does not authorize releasing the
29,377 earlier candidates or loading current boundaries under historical observations.
