# BHW locality verification — 2026-11-17

This is a bounded follow-up to #575–577, not nationwide coverage or Phase 1 signoff.
The original assessment and live report were absent from main and were read from
`origin/docs/bhw-card-analytics-assessment`. Repository baseline for this check:
`f005a25f0bfd0c0d85eeabccec48ff130313a753` (#584).

## Deployment evidence

#577 merged as `98cfd835489b8fdd5882f0c498db359344234dc5`. Its own
[deploy 44022658468](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/44022658468)
was **skipped**, so it is not evidence of a successful deployment. The subsequent
[deploy 44027721039](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/44027721039)
for descendant `e98039ea` succeeded, including the BHW deploy step. At the start of
this verification, the latest successful deployment was
[44088822439](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/44088822439)
for descendant `b87de30a` (#583), again with a successful BHW step.

The live checks below observed locality dashboards and #583's additional analysis
sections at `https://iconnect.com.de/bhw-connect`. This verifies #577's behavior as
incorporated into the newer release, not an isolated replay of #577. There is no
public commit identifier in the checked UI; release attribution combines workflow
ancestry with observed behavior. #584's initial main CI failed and its deploy was
skipped; its tooltip fix was not assumed live.

## Method and limits

Read-only Chromium 149.0.7827.55, desktop 1440×1000 for scoped value checks and
1440×844 / 390×844 for journeys. The 390px context used mobile/touch emulation.
No CPU/network throttling was applied to these functional checks. Shared desktop
startup failed (`the gaas-browser node_repl sandbox cannot be disabled`); the
repository's Playwright installation supplied a separate headless browser.

Checks ran approximately 14:18–14:25 UTC. Private scratch files held public
aggregate responses, screenshots and rendered text; no production values,
individual records or screenshots are committed here. Comparing two public
surfaces establishes consistency, not independent correctness of source records.
No physical phone, screen-reader session, reader study, sustained performance or
exhaustive indicator/geography test is claimed.

## Observations

| Check | Exact bounded observation |
| --- | --- |
| National / locality content | National dashboard and Region VII `region:07`, Cebu `province:07022`, City of Naga (Cebu) `citymun:0702234`, Alfaco `barangay:0702234001` rendered workforce, qualifications, support and methodology sections. Local workforce headings named the selected place. |
| Values, not captions alone | For all four localities, the displayed headcount matched its exact row in the parent's public Headcount map response. Validated-profile headlines likewise matched the parent's Profiles response and differed from the national headline. Four headcount and four profile comparisons passed. These readers may share underlying aggregates. |
| Unavailable values | Alfaco's combined-payment insights explicitly reported unavailable. Household workload, training recency and combined-payment fields had no numerical substitute; regional-payer receipt reported no accepted observation. Other locally stored measures remained visible. `location=province:01` rendered “Could not verify this locality” with no overview, rather than national figures. |
| Search selection | At both widths, typing `Cebu` exposed the province suggestion with parent/code detail; click on desktop and tap on mobile opened `/bhw?location=province%3A07022`, retaining the visual dashboard. |
| Refresh and history | Reload retained Cebu. “View Philippines dashboard” restored “National workforce”; browser Back restored Cebu. Passed at both widths. |
| Methodology | Each direct sample's catalog link retained its exact `location` and `dataset=bhw`. Following Cebu's link at both widths reached the scoped catalog. |
| Presentation | Cebu's title slide named CEBU and the first content slide retained Cebu scope at both widths. Remaining slides were not individually toured. |
| Map preview | Cebu's preview became ready when scrolled into view at both widths, with Cebu breadcrumb/caption and municipality shapes. The mobile screenshot had usable map/panel controls and no ordinary page overflow. |
| Map Open dashboard | With City of Naga as map scope and no selected child, Open dashboard targeted that municipality. After tapping Alfaco, it targeted the selected barangay; following it opened Alfaco. This is the newer #581 behavior. |
| Circle values | Mobile Map only: tapping Alfaco's visible circle displayed its name and the value matching its public row. Close hid the value card; tapping again after selection settled reopened it. One immediate repeat tap during the first exploratory run did not reopen it; the settled-state repeat passed. No general touch-timing guarantee is inferred. |
| Map only / exit | City of Naga canvas measured exactly `(0,0,390,844)` and `(0,0,1440,844)`. Exit closed the dialog and restored focus to Map only; Escape also closed it. These are browser viewport measurements, not physical Safari/browser-chrome tests. |
| Automatic detail | At both widths, Zoom in from Cebu province changed the URL to `level=citymun&code=0702219` and displayed “Barangay figures”. The area follows visible map focus; no predetermined locality was inferred from zoom alone. |
| Indicator autocomplete | Typing `headc` produced “Total BHWs (LGU-reported)” at both widths. Its indicator-only link explicitly targeted `location=national:PH`; global indicator search is not implicitly constrained to the current dashboard locality. |
| Errors / layout | The completed desktop/mobile journeys captured no page errors and no ordinary horizontal overflow. This does not establish every chart, dialog, browser or long-name state. |

Search/presentation exploration initially used an incorrect dialog selector and
an unscoped option selector; corrected selectors passed. Those automation errors
are not application defects.

## Data prerequisites rechecked

The Region VII public Headcount response still returned five area rows; its linked
boundary file lacked `07306`, `07311`, `07313` (Cebu City, Lapu-Lapu, Mandaue). This
is the original required missing-HUC regression case, still a supply gap. The
#581 NCR repair supplies 17 city shapes; it does not resolve Region VII or certify
historical compatibility. The four sampled Headcount responses still supplied
`license: null`. UI boundary notes still state historical compatibility is under
review. No new license or historical acceptance evidence was found.

The original catalog-wide withholding criterion has been superseded for stored
aggregate counts/receipt shares by the explicit owner decision in
[BHW_AGGREGATE_PUBLICATION.md](BHW_AGGREGATE_PUBLICATION.md). Do not interpret
newly visible stored aggregates as an accidental search disclosure, or reimpose
the old rule. Source nulls and unsupported scope remain unavailable. Historical
nulls would require a privately supplied aggregate rebuild; this session did not
supply or rebuild source data.

## PNG test regression and validation

The initial #584 main CI failed twice in the same 390px PNG case while waiting for
the download after zoom. Local traces show the zoom changes Region VII to Cebu.
The old test waited only for a changed scale label, which can precede scope/render
replacement; an export of an outgoing selection is deliberately cancelled by the
application. Its fixture also incorrectly returned province rows for every scope.

The small test correction waits for the Cebu URL and ready renderer before the
second export, and supplies municipality rows/geometry after drill. It keeps the
existing export safety checks, assertions and timeouts. No application component,
source rule, deployment file or production behavior changes.

Before correction, one complete PNG suite passed locally; a five-repeat mobile
sample passed four and failed once at initial five-second map readiness while
other checks were running. That was a separate readiness miss, not a reproduced
export timeout, and is not erased by later passes. Tests are functional evidence,
not performance-budget acceptance. Final checks and deployed follow-up are recorded
with the PR and in the acceptance record.

Local final validation: npm ci; lint (one existing warning in the deliberate
base-path defect fixture); typecheck; 2,029 unit tests passed, 55 database-dependent
skips; migration lint; prefixed no-data production build and standalone extractor
verification passed. All seven PNG / Map only / automatic-zoom browser cases
passed against that build with synthetic map fixtures, one worker, no retries.
The full seeded-database browser run remains CI's gate. Test-only final edits
were checked again with ESLint, Prettier and typecheck; application build inputs
were unchanged.
