# National BHW map startup — 18 November 2026

The dedicated national BHW map now preloads its existing regional boundary file
from the initial HTML. Previously that download waited for client initialization,
aggregate data and canvas mounting. The normal renderer consumes the preload;
there is no separate geometry cache or map implementation. The URL retains the
base path and the preload uses the same credentials mode as the canvas fetch.
Only valid national BHW map requests receive this hint. Locality and invalid map
requests do not fetch the national file for this optimization. No boundary
coordinates, source figures, disclosure rules, CSP or deployment files change.

## Baseline and controlled comparison

The deployed e39d52a0 release's preceding five cold/warm phone map samples were
cold `[10336,10723,9302,14474,9734]` ms and warm
`[4749,4106,4042,5082,4717]` ms. Three cold samples missed 10,000 ms; all warm
samples met 6,000 ms. These were bounded cloud-browser observations, not
physical-device acceptance.

For this change, five alternating control/candidate pairs used Chromium
156.0.8078.79, 390×844 touch, 4× CPU slowdown, 150 ms latency, 1.6 Mbps download
and 750 Kbps upload. Each variant/pair used a new context, then a warm repeat.
Browser cache remained enabled: no Playwright request routes were used. A local
HTTP proxy supplied synthetic national aggregate rows and both builds used the
same committed geometry. Other data reads used an explicit unavailable HTTP
fixture. No concurrent local build or browser suite ran during measurement.
Timing is navigation invocation to the map-ready marker, not final GPU paint.
Server caches/processes were not reset.

| Build | Cold observations (ms) | Warm observations (ms) | Cold median | Warm median |
| --- | --- | --- | ---: | ---: |
| Current main | 8540, 9310, 8743, 11204, 8995 | 3912, 3890, 4931, 4940, 4203 | 8995 | 4203 |
| Boundary preload | 7605, 10056, 8409, 7655, 7491 | 3625, 3496, 3230, 3998, 5096 | 7655 | 3625 |

Every map reached ready, with no captured page errors or ordinary overflow.
Resource timing showed one regional-boundary request per navigation, initiated by
the link for the candidate and reused by the canvas. The candidate reduced both
medians in this sample; one candidate cold observation still exceeded 10,000 ms.
The warm maximum was higher for the candidate despite its lower median. This is
not proof that intermittent misses are resolved or that all devices benefit.

An earlier exploratory comparison was excluded: the restarted local servers
used build-only BHW_DATA_SOURCE=none at runtime, causing background-prefetch
errors. The comparison above followed correction to the explicit HTTP fixture.
No result from that exploratory run establishes acceptance.

## Verification and remaining acceptance

Browser coverage holds the aggregate response pending, verifies that boundaries
start anyway, then releases data and checks the ready renderer reused the one
request. Separate cases check locality and invalid links do not receive the
national preload. Existing map interaction suites remain applicable.

Required checks, CI and actual deployed behavior will be recorded in the PR.
Phase 1 remains unsigned: representative cold-map performance, physical
VoiceOver/reader tasks, historical-boundary acceptance and Headcount source
reuse terms remain open. iPhone/Chrome feedback did not include VoiceOver.
