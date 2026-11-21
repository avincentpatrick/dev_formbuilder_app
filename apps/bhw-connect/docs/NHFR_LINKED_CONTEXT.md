# Health facilities linked context — Phase 3

## Source/denominator assessment

The catalog already reads the selected place across accepted datasets. Reuse that
result; do not add a new reader, national sum or import.

| Evidence | Accepted use | Limits |
| --- | --- | --- |
| NHFR September 2026 + PSA July 2024 census | Municipal registered facilities per 100,000 census residents, with the raw formula and both years beside it | Complete available scalar counts only, positive census denominator, exact accepted releases, units and reference dates. No barangay/district rate, no national/regional/provincial rate: broader population totals are mostly partial. It is an inventory-density proxy with an older denominator, not current service capacity/access. |
| BHW Headcount 2025 | Same-place LGU-reported workforce context | Do not substitute individual profiles, infer facility assignment, or produce a staffing/workload ratio. |
| UUC-PHC 2025 | Same-place listed barangay count and its own validated denominator/share | Does not identify overlap with barangays having an NHFR facility. That requires a validated barangay-level join across vintages and complete attribution. |
| PSA official/SAE poverty 2023 | Separate published estimates with source notes and confidence intervals | Official 95% and municipal SAE 90% intervals remain distinct. No averaged rates, poor-person reconstruction, causal claim or combined priority score. |

Municipal population is accepted as the matched census observation under the
existing population reader contract. Population partial states and coverage
qualifications are retained. The municipal proxy is not a nationally standardized
healthcare access measure: NHFR contains different kinds/sizes of facilities and
residents can cross boundaries for care.

Unknown/malformed/withheld/unsupported observations never become a numeric zero.
Exact dataset views retain their full states and notes. Barangay/district selections
receive an explanation instead of borrowed parent context. Links preserve the
selected geographic scope. No source record, schema, contact information or personal
worker record is added.

## Deferred with concrete reasons

- Time trends: only one accepted NHFR snapshot; needs versioned repeat exports and
  stable facility-code reconciliation.
- Travel access/referral networks: barangay representative points are not facility
  coordinates; needs verified locations, transport network and referral evidence.
- Operating services, staff, occupancy and care quality: not established by source
  type, service-capability text, recorded beds or licensing labels.
- Bed completeness: cleaning conflates blank capacity with zero; a provenance-
  preserving source reprocess is needed before absence/completeness comparisons.
- Licensing compliance: missing status is not unlicensed and the eligible licensing
  denominator is not established.
- Joint UUC/poverty/workforce prioritisation: requires a separate geographic/year
  compatibility and policy-method assessment; no unsupported ranking is shipped.

## Validation

Pure model tests exercise valid municipal density, rejected partial/suppressed/
failed/wrong-unit/wrong-release/zero/ambiguous denominators, missing dates,
unsupported scopes, UUC denominator validation and withheld poverty values.
Rendering tests preserve confidence levels, published intervals, source notes and
scope links. Browser checks cover mobile/desktop context and source navigation.
Full combined lint/types/unit tests, migration lint, prefixed no-data and seeded
builds, extractor checks and browser results are recorded in the PR delivery ledger.
React review: server-rendered, no new fetches, no mutable client state, native links,
values and context remain text accessible to assistive technology.

Combined local evidence: lint passed with the existing deliberate fixture warning;
types passed; 2,194 unit tests passed / 56 database-dependent skips; migration lint,
no-data and seeded prefixed builds and standalone extractor verification passed.
Eighteen targeted browser cases passed at 390/1440px across entry, scope/map return,
charts, searchable lists, context links, reload/history and the general catalog.
A local-only municipal population fixture verified 3 / 10,000 × 100,000 = 30 and
both source years in the rendered formula. New analysis/context sections had zero
axe WCAG 2 A/AA and 2.1 AA violations and no overflow at both widths. Populated
phone context was visually reviewed. No synthetic fixture or screenshot is committed.
