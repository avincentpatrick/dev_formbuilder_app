# Municipality package delivery validation

8 November 2026. The [private barangay package](BARANGAY_CANDIDATE_PACKAGING.md) is
partitioned and locally verified. Its geometry remains unaccepted and unpublished. The new
read-only probe validates HTTP delivery independently of that acceptance decision.

```sh
node scripts/validate-boundary-hosting.mjs \
  https://HOST/bhw-connect/maps/IMMUTABLE_RELEASE/ \
  /private/package/manifest.json RECORDED_MANIFEST_SHA256
```

Use the release from the pinned manifest as the final directory name. Keep the trailing
slash. Use a checksum recorded during package creation, not one calculated from an unknown
download. The probe rejects missing prefixes, `latest`, release mismatches, invalid shard
paths and duplicate municipality entries before making requests. It never uploads files,
changes a manifest, installs server configuration or approves geometry.

For every manifest-listed nonempty municipality file, plus the coverage index and manifest,
it requires HTTP 200, JSON MIME, identity encoding, exact SHA-256, a strong ETag, public
immutable caching with positive max-age, and conditional 304 with the same ETag. Files are
requested sequentially with a 20-second timeout per request. Reads are bounded by recorded
shard sizes; coverage is limited to 32 MiB. Empty areas do not cause geometry requests.
Finally, a deliberately absent filename must return 404 rather than an app HTML fallback.
Redirects fail. A failure names the artifact and exits nonzero. This is a potentially long
operator check, not a page-load request; it reads every listed file once plus validators.

A passing report always keeps `publication_ready: false`. It proves bytes and the tested
HTTP behavior only. Geometry validity, reference completeness, historical compatibility,
dataset disclosure rules, MapLibre rendering/CSP, browser cache reuse, compression and phone
performance are separate checks. Run the offline package verifier first. A matching checksum
alone does not make a candidate suitable for a dataset or reporting year.

## Measured local result

A controlled HTTP server on loopback served the actual private package, including all 1,636
geometry shards. The probe verified 1,638 files and 114,148,940 bytes, all checksums,
conditional responses and the missing-file response. The local server was then stopped.
This is not evidence about Apache, the live route, public availability or production speed.
The artifact manifest pin remains recorded in `ingestion/barangay_package_summary.json`.
Only aggregate evidence is committed; no private package files entered git.

Eleven automated cases cover success, real local HTTP, changed bytes, oversized bodies,
weak validators, contradictory caching, MIME/encoding failures, conditional and missing-file
failures, bad pins, unversioned paths, release mismatches and path traversal.

## Actual host acceptance still required

After geometry and historical review, the operator must provision versioned same-origin
storage outside rotating application releases, retain the prior release for rollback, and
install the accepted package privately. No actual persistent route has been supplied or
certified here. Run this command against that route and preserve its report. Check compressed
GeoJSON transfer separately, retaining correct validators for each representation, then
verify the map under the existing CSP and prefix. Do not configure compression that changes
PMTiles byte offsets.

PMTiles still has its separate `scripts/validate-map-hosting.mjs` probe for ranges. This
GeoJSON check neither replaces that probe nor provides a representative licensed basemap
archive. Actual PMTiles rendering, range delivery, glyphs and sprites remain outstanding.
Server/Apache and shared `ops/` changes remain with the operator under the working agreement.
