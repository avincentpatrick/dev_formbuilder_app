# BHW card: live verification findings

Date: 2026-11-15, approximately 08:21–08:28 UTC.
Companion to [the phased analytics assessment](BHW_CARD_ANALYTICS_ASSESSMENT.md).

## Method and limits

Reviewed the public application at `https://iconnect.com.de/bhw-connect` using Chromium and Playwright, with desktop viewport 1440 × 1000 and phone viewport 390 × 844. A separate phone context enabled mobile/touch emulation for locality and release-switch checks. Inspected rendered screenshots, DOM text/control state, public map responses and their referenced boundary files. No administrator access, database access, application edits, individual records or production data modifications were involved.

The older `staging.pitahc.gov.ph/bhw-connect` address returned HTTP 503 through this environment; the current domain returned HTTP 200. This is an environment-specific reachability observation, not a determination that the old origin is down for everyone.

Screenshots, downloaded map image and transient responses were inspected in session scratch storage, not committed to this public repository. This report records behavior, geographic coverage metadata and reproduction steps, not production workforce counts. The deployed commit could not be established from the UI; code assessment baseline remains `73affc27`.

This is a bounded live assessment. It does not certify every indicator/locality, screen-reader behavior, physical touch gestures, historical boundary compatibility, statistical validity or sustained performance. Emulated phones are not substitutes for physical-device validation.

## Findings and priorities

| ID | Priority | Reproduction and observation | Required improvement |
| --- | --- | --- | --- |
| L01 | High | Portal → BHW opens `/catalog?mode=all&dataset=bhw`. Location browsing, availability text and a long indicator table precede any analytical visual; the card details contain no map/chart. The richer dashboard is a separate link. | Make BHW's primary destination the agreed visual story and purposeful subpages. Keep inventory/methodology accessible. |
| L02 | High | Open the national Headcount map; search `Cebu`: zero matching areas, because only regions are loaded. Drill to Cebu and search `Bohol`: zero matches among Cebu's municipalities. The UI explicitly says search changes the selector only. | Implement card-wide search; keep local filtering as a separately labeled function if retained. |
| L03 | High | On `/bhw`, geographic search for `Cebu` returns several levels and districts with parent descriptions. `Cebu accreditation` instead returns an unrelated barangay in Camarines Sur. Both search requests returned HTTP 200. | Parse geography/indicator intent before fuzzy geographic retrieval; define relevance thresholds and unsupported-query feedback. A nonempty response is not evidence of a successful search. |
| L04 | High | From BHW catalog details select Region VII and press Show datasets. Destination is `/catalog?mode=psgc&location=region%3A07`, without `dataset=bhw`. Map's back-to-BHW link similarly points to all-locations details regardless of locality. | Preserve dataset and valid geographic/indicator context across navigation. |
| L05 | High | The national map's canvas measured 1078 × 550 pixels on desktop, beginning around y=624. At 390 × 844, it measured 316 × 464 and began around y=691. Its legend was below the initial viewport. Both catalog details and map stayed within the tested page widths. | Reclaim space from introductory controls/text; offer an expanded workspace that retains readable legend and important controls. This is primarily information hierarchy, not a confirmed whole-page overflow defect. |
| L06 | High | Rendered national and locality maps show colored shapes without place labels, scale bar, national locator inset or explicit zoom/reset buttons. The phone locality screenshot is an isolated outline with no national orientation. | Apply the agreed map standard; retain the existing area selector as an accessible interaction path. |
| L07 | High | At Region VII, public map data returns five areas with values, but the referenced province boundary file contains matches for only two. The three missing matches are the Cebu, Lapu-Lapu and Mandaue HUC entries. | Reconcile geography and boundary representations; expose missing-map coverage beside the visual. Never remove these areas from totals/comparisons to make the map appear complete. |
| L08 | Medium | Headcount quantile legends expose floating-point tails rather than clean labels at national, regional, provincial and barangay levels. Colors are recomputed relative to the displayed areas. | Format boundaries consistently; separate presentation precision from classification logic; make changing breaks explicit and enable stable comparison breaks. |
| L09 | Medium | Following national → Region VII → Cebu through map drill buttons creates breadcrumb buttons labeled with codes such as `PH`, `07` and `07022`. | Use human-readable parent names and preserve hierarchy on direct/shared links. |
| L10 | High prerequisite | Catalog reports 56 of 66 BHW measures available. Ten are deliberately withheld: six demographic dimensions and four payer-specific honorarium receipt measures. The older `/bhw` dashboard visibly includes education and related compensation summaries. | Reconcile each surface's suppression/aggregation contract before reusing figures. A difference in displayed scope does not by itself prove a disclosure breach, and does not authorize publishing withheld catalog measures. |
| L11 | Medium | Map release/indicator pickers expose useful choices, but lengthy topic labels form a long flat list. On the portal, general geographic search appears below all six dataset cards. | Bring search near the beginning; group/search measures by purpose and explain the chosen population. |

## What already works and should be preserved

- The map renders on both tested viewport classes; this is not a universal blank-map failure.
- National → Region VII → Cebu drill-down works through area selection and “Show areas within.” The sampled City of Naga, Cebu view renders barangay boundaries.
- Switching Headcount to Profiles at the sampled locality renders the corresponding profile map. Both release choices remain separately labeled.
- Headcount indicator switching to LGU-reported accreditation updates the map and rate explanation.
- “Share this map selection” produces a URL with dataset, indicator, period and geography. Opening the sampled URL restores the accreditation indicator.
- Download map image (PNG) succeeds. Visual inspection confirms a title, units/period, legend, source/denominator notes and coverage notes. Add the new control state and standard map elements without discarding this provenance work.
- Existing comparison works for Region I and Region VII: adding them produces `/compare?geos=01,07`, a head-to-head summary and a figure-focus selector. At phone width, document width remains 390 pixels; several long place labels are truncated. Reuse the comparison calculations and improve readable labels and phone navigation rather than starting from an empty implementation.
- Local search is accent-normalized in code and preserves the complete map/table rather than silently redefining totals. This behavior is useful for a local selector, but insufficient as the primary search.
- Geographic search returns disambiguating parent chains and multiple levels. Reuse that work, while correcting confusing cases such as HUC entries labeled as “Province.”

## Sampled coverage

All eleven sampled public map requests below returned HTTP 200 and `state=available`. “Values” means a non-null value returned by that public adapter; it is not independent validation of the source observation. “Matching shapes” compares returned geographic codes with the referenced boundary file; it does not establish historically correct boundaries or topology.

| Measure / selected geography | Child level | Returned areas | Areas with values | Matching shapes |
| --- | --- | ---: | ---: | ---: |
| Headcount / Philippines | Region | 18 | 18 | 18 |
| Headcount / Region VII | Province/HUC entries | 5 | 5 | 2 |
| Headcount / Cebu province | City/municipality | 50 | 50 | 50 |
| Headcount / City of Naga, Cebu | Barangay | 28 | 28 | 28 |
| Validated profiles / Philippines | Region | 18 | 18 | 18 |
| Profile accreditation / Philippines | Region | 18 | 18 | 18 |
| Any-honorarium receipt / Philippines | Region | 18 | 18 | 18 |
| Basic Life Support training / Philippines | Region | 18 | 18 | 18 |
| Validated profiles / City of Naga, Cebu | Barangay | 28 | 28 | 28 |
| PSA population 2024 / Philippines | Region | 18 | 18 | 18 |
| PSA poverty 2023 / Cebu province | City/municipality | 50 | 50 | 50 |

Reproduce through `/api/dataset-map` using `dataset`, `indicator`, `period`, `level`, `code`, then inspect that response's `geometryUrl`. The BHW headcount release identifier remains `bhw-stepzero-2026` with public period `2025`; Profiles uses `bhw-2025`. Sample geography codes: `PH`, `07`, `07022`, `0702234`.

Coverage implications:

- Initial national/regional workforce and selected qualification/support visuals have usable published inputs in the sample.
- Population and poverty are available for contextual analysis in the sampled scopes, but date alignment and geographic joins still need a per-analysis contract. Availability alone does not authorize a cross-source ratio or causal claim.
- Barangay headcount/profile counts are supported in the sampled municipality. The live UI explicitly limits barangay maps to counts, with percentages, means, honorarium and training at municipality level or above. Preserve this restriction; selectable indicators must not suggest every measure exists at every level.
- The national catalog's ten withheld measures are not ingestion failures. Track demographic and payer-specific visuals as requiring disclosure review, rather than “unblocking” them through another endpoint.
- Existing map/export text says historical boundary acceptance remains unassigned and license information is not supplied for the sampled headcount source. Retain honest provenance and create explicit metadata/boundary follow-ups.

## Timing observations

Three sequential national Headcount map navigations in one Chromium context reached the application's `data-map-state=ready` marker in approximately **6.47 s, 3.33 s and 3.41 s**. The first browser navigation was cold relative to that context; later ones could benefit from cache. This measures navigation-to-map-ready, not initial page readability or AI completion.

These are three observations through the execution environment's network proxy, without mobile network/CPU throttling. They are not production percentiles or a general speed claim. Sol should use the same ready-state measurement for regression comparison and establish separate representative-device budgets for initial visual, search response and interactive map readiness. Do not use full streaming-page completion to assess when the user can first read a chart.

## Changes to the implementation handoff

1. Phase 0 can start from this evidence; it still needs route/layout decisions, exhaustive measure eligibility, disclosure reconciliation and physical-device/user validation.
2. Phase 1 must explicitly fix context loss, irrelevant mixed-query results, legend formatting and missing-geometry visibility alongside the agreed overview/search/map work.
3. Phase 2 must not promise demographic/payer charts without resolving the catalog/legacy disclosure difference. Use synthetic cases for regression tests and preserve required withholding.
4. Phase 3 contextual work has promising sampled inputs, but remains conditional on joins, reference dates and denominators.
5. Regression fixtures should include the Region VII HUC mismatch, global `Cebu` search from the national map, `Bohol` from Cebu, mixed `Cebu accreditation`, and direct/shared locality links.

No application tests/build were needed for this documentation-only update. Live browser checks and public endpoint/geometry comparisons provide the evidence above. Browser automation synchronization errors encountered during exploratory checks were rerun with appropriate rendered-state waits; they are not reported as application defects.
