# Map startup: transient response recovery

18 November 2026. Follow-up to the unexplained ready-state timeout recorded in
[#590](https://github.com/avincentpatrick/dev_formbuilder_app/pull/590).

## Captured evidence

A read-only, instrumented live run used Chromium 149.0.7827.55 at 390×844,
mobile/touch, 4× CPU slowdown, 150 ms latency, 1.6 Mbps down / 750 Kbps up.
Tracing adds overhead; these observations diagnose failures, not performance
percentiles. Ten cold/warm pairs produced 18 ready maps and two failed cold reads.

The first failed navigation began at 08:57:35.175 UTC. The page and region geometry
returned HTTP 200. At 08:57:38.605 UTC, `/api/dataset-map` returned HTTP 503,
`Content-Type: text/plain`, `Content-Length: 95`, `Server: envoy`, without the
upstream service timing header present on successful responses. The body was not
captured: the failed fetch was abandoned. This differs from the application's JSON
503 contract. It identifies a proxy/service response, not a demonstrated renderer
hang; it does not establish which upstream connection failed or retrospectively
prove the cause of every earlier timeout.

The screenshot showed the correct failure state: “Map data could not be loaded.
This does not mean zero.” and “Retry map”. No renderer was present; the timing
probe incorrectly waited only for a ready renderer and consequently timed out.
The second failed cold read had the same API 503 pattern. Warm revisits recovered.
Twelve additional visits with failure-body capture enabled all returned 200, so
no body or live manual-retry recovery was obtained in that bounded follow-up.
Private traces/screenshots stay outside the public repository; no production
figures or records are committed.

## Bounded application recovery

Reuse the existing map request, result validation, source contracts and error UI.
Retry only HTTP 502/503/504 once after 500 ms. A `Retry-After` header prevents the
automatic retry so the server's backoff is not overridden. Other responses,
including unavailable data, 400/403/429 and 500, are returned immediately. Network
exceptions are not retried by this change. A persistent failure remains explicit
and the existing manual Retry action remains available.

The exact URL and abort signal are reused. Changing scope, leaving the page or
losing the map activity lease cancels a pending delay or request. The existing
key check still rejects obsolete results. No additional request is made on the
successful path; no national fallback, cached-value substitution or new public
source is introduced. One additional read during a transient outage is the
intentional tradeoff; this does not repair the upstream service itself.

Unit coverage checks the three retry statuses, one-retry limit, non-retry cases,
server backoff and cancellation during the delay. Browser fixtures check recovery
at phone/desktop widths with null values retained, persistent failure, and manual
retry. Required checks, deployment and bounded live follow-up belong in the PR.

Phase 1 is not unconditionally signed off. Physical-device timing and Headcount
reuse metadata remain unresolved. VoiceOver remains accepted by owner report;
this work does not reopen that checklist or claim nationwide data coverage.
