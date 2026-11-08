# Reproducible district boundary candidates

Use `ingestion/build_reviewed_district_boundaries.py` for the dataset-map pipeline.
The older `build_district_boundaries.py` explains the historical committed assets;
it reads live rows and unpinned NCR downloads, repairs invalid polygons with `buffer(0)`,
and is not an acceptance gate for new releases. This increment does not replace those
assets or claim they have passed the new gate.

The new builder runs offline. It needs Shapely (already in ingestion/requirements.txt;
validated here with 2.1.2) and three privately prepared inputs:

1. A reviewed district roster specifying **all expected leaf members**, the Congress,
   compatible boundary vintage and evidence reference. Do not manufacture this roster by
   copying only the rows or shapes that happen to exist. Existing membership is incomplete;
   its absence is not evidence that a place belongs to no district.
2. A permitted snapshot of membership, including exact district/PSGC identifiers,
   `geo_level`, `status`, `superseded_by`, `source_ref` and `corroboration`. `auto` and
   `approved` are accepted workflow states, not proof of independent corroboration.
   `single_source` remains explicit in output; `conflict` blocks the district.
3. Normalized Polygon/MultiPolygon FeatureCollections keyed by exact `geo_code`,
   separately declared as citymun or barangay. Pin every file by SHA256, release,
   vintage, source and license. The preparation/crosswalk review must establish vintage
   compatibility; a matching code alone does not establish historical geography.

A manifest has this structure (illustrative placeholders, not real source evidence):

```json
{
  "schema_version": 1,
  "release": "district-candidate-IMMUTABLE-ID",
  "boundary_vintage": "REVIEWED-VINTAGE",
  "congress_no": 20,
  "review_ref": "REFERENCE-TO-REVIEW",
  "roster": {"path": "roster.json", "sha256": "ACTUAL-SHA256"},
  "members": {"path": "members.json", "sha256": "ACTUAL-SHA256"},
  "sources": [{
    "path": "normalized-citymun.json", "sha256": "ACTUAL-SHA256",
    "level": "citymun", "release": "IMMUTABLE-SUPPLIER-RELEASE",
    "boundary_vintage": "REVIEWED-VINTAGE",
    "source": "PINNED-SOURCE-URL", "license": "VERIFIED-LICENSE"
  }]
}
```

Paths resolve relative to the manifest. Each roster entry has `district_code` (a district
slug, never a PSGC code), `district_name`, `congress_no`, `boundary_vintage`, `review_ref`,
`membership_complete: true`, and `expected_members: [{"geo_level":"citymun",
"geo_code":"EXACT-SEVEN-DIGIT-CODE"}]`. Barangay codes are exact nine-digit internal
codes. Numeric identifiers, implicit padding, names and centroid dictionaries are rejected.
Additional barangay source entries use the same manifest shape with `level: "barangay"`.

```sh
python ingestion/build_reviewed_district_boundaries.py \
  --manifest /private/reviewed-manifest.json \
  --output /private/NEW-district-candidate.json \
  --report /private/NEW-district-report.json
python -m unittest discover -s ingestion -p 'test*boundaries.py' -v
```

Exit 0 means every district **in the supplied roster** has a complete valid candidate;
it is not a nationwide certification. Exit 1 writes a gap report and only complete
candidate district shapes. Never publish the successful subset as a complete country
release. Missing members or polygons, duplicate assignments/shapes, inactive or conflicting
membership, invalid geometry and positive-area overlaps block the affected district.
Shared edges are allowed; even tiny positive-area overlaps are blocked for review, without
a tolerance that hides slivers. Disconnected islands remain MultiPolygons. Geometry is
never silently repaired or simplified. Cross-district overlaps also block both candidates.
The roster review must separately catch whole-municipality versus partial-barangay membership
errors; the geometry checks cannot certify legal district membership.

Exit 2 means an input/configuration error; no successful report is produced. Outputs must
be distinct new paths so an old accepted release cannot be overwritten. Inspect the process
exit status and matching report, not the existence of a file alone. Reports include input,
manifest and output hashes, source attribution, geometry-library version and per-district
completeness/gap reasons. Retain those together for reproducibility and rollback.

Publication still requires historical crosswalk review, full roster/coverage review,
cartographic checks (especially shared edges and small islands), license acceptance and
versioned hosting validation. No app route consumes these candidates automatically.
No production data, membership assignments or existing public geometry is changed.

## Fresh local topology check — 8 November 2026

Shapely 2.1.2 checked all 1,601 committed citymun features: 1,595 valid, five null, one
invalid. `public/geo/citymun/08048.json`, code `0804808`, has a ring self-intersection.
The earlier basic ring/coordinate check did not test topology, so this is an additional
finding, not a change in that report's denominator. The new builder blocks that shape;
it does not apply a guessed repair. These are committed asset checks, not reconciliation
against the production database or acceptance of a new supplier release.
