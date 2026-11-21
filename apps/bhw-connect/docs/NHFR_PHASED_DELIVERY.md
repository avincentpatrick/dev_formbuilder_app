# Health facilities: phased delivery

Owner request, 21 November 2026: execute the assessment in phases and merge tested
increments through completion. Preserve the unified Health facilities card delivered
in #619, which supersedes the earlier suggestion to revive a separate entry.

1. Direct national entry and map continuity. Main card title/action and legacy
   all-location URLs open the selected national card. Scoped entries retain scope;
   validated NHFR map state survives location navigation and facility search.
2. Question-led visual analysis. Reuse accepted counts for facility mix, ownership
   within type and geographic distribution/presence; retain exact tables and source
   limitations. Searchable local lists and honest read failures already shipped in
   #618/#619 and must remain intact.
3. Linked evidence. Assess population, UUC-PHC, workforce and poverty against their
   actual release, geography and denominator contracts. Deliver only supportable
   context and explicitly document deferred ratios or claims. No inference of worker
   assignment, service quality, operating status, occupancy or travel access.

Each increment gets local checks, a separate PR, green required CI, a merge, and
release/live verification. No real source records are committed. Synthetic local
fixtures are not production evidence. No new import or shared deployment change is
planned.

## Phase 1

Implementation: canonical national catalog entry (not an availability sum), legacy
redirect, validated map-return links, selected map-child details, scoped location and
facility-search continuity. All locations in location controls returns to the general
availability catalog. This avoids two identical national navigation buttons.

Validation and release receipts are recorded on the implementing PR. React review:
existing parallel readers unchanged, no additional fetches or client state, direct
imports, URL state validated independently of the displayed data scope.

Phase 1 local evidence: full lint passed with the existing deliberate base-path
fixture warning; types passed; 2,185 unit tests passed, 56 DB-dependent tests skipped;
migration lint and both no-data and seeded prefixed builds passed, including the
standalone extractor checks. Nine browser cases passed at 390/1440px using installed
Chromium and a scratch config: direct entry, map return/reload, title/action agreement,
location navigation, exact data disclosure, and legacy/unified facility search/history.
