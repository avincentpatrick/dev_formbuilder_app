# BHW analytics — place and indicator discovery

This is the next reviewable Phase 1 slice after overview PR #529. The requirements
remain those in draft #525 (`docs/bhw-card-analytics-assessment`); the Phase 0 audit
in #527 remains a sampled audit, not nationwide or physical-device certification.

## Reader question and reuse

“Which BHW measure describes my question, and which place does it describe?”
The overview now has a public place/indicator search. It reuses `searchGeo` and its
database fuzzy/full-text matching, all 66 accepted BHW catalog definitions, catalog
disclosure/validation, measure explanations, value formatting and source dates.
No new database, measure formula, dependency, personal-record reader or migration.

## Supported behavior and route mapping

- `/bhw` → GET `/bhw/search?q=…`: place name/code, indicator phrase, or their
  combination, e.g. `Cebu accreditation`, `accreditation in Cebu`, `headcount`,
  `Cebu basic life support`. Exact PSGC codes use the existing geography dimension;
  name queries reuse the existing global search RPC, not the current map's children.
- Longest recognized indicator phrase is removed before place matching. Accreditation
  offers LGU-reported share and profile share explicitly. No result auto-selects the
  first geography or population. Labels and canonical indicator IDs are searchable;
  common aliases are enumerated in `lib/bhw/search.ts`.
- Place-only results retain level/code and route to BHW catalog scope. Search results
  show supplied parent names and codes, never profile counts. Name searches show at
  most 12 approximate matches and tell readers to refine; this is not exhaustive coverage.
- Indicator selections open `/bhw/measure?indicator=…&location=level:code`, a small
  focused view of the existing public catalog measure. Indicator-only search explicitly
  offers national scope. Shared URLs retain measure and location. Invalid level/code
  combinations do not silently fall back to national data.
- Search loading, no matches, failed geography reads and unsupported actions have
  separate messages. An accepted measure can still be unavailable, failed, withheld or
  unsupported at the requested level. Zero stays zero; none of these states becomes zero.
- The focused measure retains denominator, confidence interval when supplied, public
  source dates and interpretation notes. The catalog remains the full detail destination.
  Catalog-wide withholding applies even if a legacy locality reader would return values.

## Acceptance evidence and limits

Targeted tests cover all accepted indicator IDs, mixed-query extraction, explicit
accreditation alternatives, repeated place names with different parent chains, exact
codes, failed versus empty search, retained scope, zero, and withheld/failed payloads
not reaching rendered content. Browser cases cover keyboard submission and selection,
phone/desktop widths, refresh/back and unsupported comparison syntax. Browser fixtures
are synthetic; CI additionally runs against the committed development database.

The Phase 1 search acceptance is **partially delivered**, not complete. Structured
`Compare Cebu and Bohol accreditation` requests currently explain that the action is
unsupported. Connecting them to `/compare` is the next search task: first route that
journey through the same catalog-wide disclosure policy, then reuse its existing
selection/figure components for explicit ambiguity resolution and two-to-four-place
add/replace/remove with retained indicator and share state. Legacy comparison readers
must not be used as a fallback for a withheld catalog measure. Requests over four must
explain the limit, never silently truncate. This is an implementation dependency, not
a reopened product decision about comparison size.

District search, richer spelling aliases and the mature autocomplete/recent-place UI
remain available in existing site journeys; this slice uses explicit server-rendered
search results. General natural-language questions are not a supported grammar.
Maps, classification/range controls, geometry reconciliation, broader accessibility
and real-device testing remain tracked in #525/#527 and are not certified by this slice.
