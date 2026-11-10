# Private barangay candidate packages

8 November 2026. This continues the [ring-preserving build](BARANGAY_RING_RETENTION.md).
It prepares municipality delivery partitions for review, not public map assets. No dataset
boundary version changes and no historical crosswalk is inferred from current PSGC codes.

`ingestion/package_barangay_candidates.py` consumes the checksummed national candidate,
full geography reference and national validation report. It partitions already jointly
processed geometry without simplifying, clipping, snapping, repairing or changing coordinates.
Both endpoints of every positive-area overlap are withheld; a conflict never chooses a winner.
Source-invalid shapes, candidate-invalid shapes, component/hole changes, parent mismatches,
duplicate codes and unmatched codes are also withheld. Unknown blockers fail closed.

The package contains 41,567 polygons across 1,636 nonempty municipality files. Its coverage
index retains all 41,991 reference barangays: 424 are withheld (411 unmatched reference codes,
four source-null shapes, one projection failure and eight reference overlap endpoints).
Ten source overlap endpoints are quarantined overall; two are outside the reference.
The unmatched-source audit retains 430 source records. Of 1,655 municipalities, 1,629 have
complete candidate coverage, seven partial coverage and 19 no candidate geometry.
“Complete-candidate” describes membership only, not acceptance for any dataset or date.
Empty reference municipalities have a distinct state, never a false complete claim.

Geometry totals 108,927,063 bytes, with a 718,722-byte largest file and 51,046-byte median.
The complete coverage index is 4,705,692 bytes. These are uncompressed local sizes, not
production transfer measurements. All file hashes and unchanged coordinates were verified;
a global check across partitions found zero remaining positive-area overlaps. That result
comes from explicit withholding, not resolution of the original source/topology defects.
Aggregate pins and results are in `ingestion/barangay_package_summary.json`; detailed
geography, coverage records and artifacts remain outside this public repository.

## Reproduction

Use `ingestion/requirements.txt` and the national validator report for the exact
candidate/reference pair. Supply independently recorded SHA-256 values, not new values
calculated from an untrusted replacement. The report's provenance must match both inputs.

```sh
python ingestion/package_barangay_candidates.py build \
  --candidate "$CANDIDATE" --candidate-sha256 "$CANDIDATE_SHA256" \
  --reference "$REFERENCE" --reference-sha256 "$REFERENCE_SHA256" \
  --validation "$VALIDATION" --validation-sha256 "$VALIDATION_SHA256" \
  --release psgc-2026q3-ring-candidate-v1 --output "$PRIVATE_NEW_DIRECTORY"
python ingestion/package_barangay_candidates.py verify \
  --package "$PRIVATE_NEW_DIRECTORY" --candidate "$CANDIDATE" \
  --reference "$REFERENCE" --manifest-sha256 "$RECORDED_MANIFEST_SHA256"
```

Build refuses existing output directories and the app's public asset directory. Every shard
and the coverage index has a manifest checksum. Verify pins the manifest, checks complete
reference membership, municipality counts, file hashes, unchanged geometry and topology
across all shards. Exit 0 means packaging/verification succeeded, not publication approval.
The manifest always has `publication_ready: false`, no dataset boundary versions, and
historical compatibility `not-reviewed`. Attribution and source provenance travel with it.

## Remaining acceptance and hosting work

Resolve or explicitly review withheld cases; retain full tabular membership and disclosure
rules when mapping any accepted subset. Review each dataset's geography and dates before
assigning a boundary version, including historical Sulu/SGA differences. Never make a table
by filtering to polygons. The current UI remains unchanged while this gate is closed.

Self-hosted delivery still requires an accepted release, a private artifact transfer,
operator-installed immutable paths, attribution, and measured headers/cache/compression,
404 and (where applicable) range behavior under `/bhw-connect`. Use `withBasePath` for future
raw asset requests. This tool does not configure shared Apache paths, publish files, alter
CSP or establish that production hosting has passed verification.
