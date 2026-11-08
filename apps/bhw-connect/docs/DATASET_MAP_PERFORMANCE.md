# Dataset map performance verification

8 November 2026. This checks the many-card portal as well as one enlarged map. It does
not certify production delivery, a real handset or a future detailed basemap.

## Resource lifecycle regression checks

`e2e/dataset-maps.spec.ts` verifies that a 390px-wide portal initially makes no dataset-map
requests while the gallery is offscreen. Bringing UUC and then NHFR into view requests only
those datasets. Leaving a card disposes its map; returning to the map itself restores its
selection/search without rereading the already loaded aggregate payload. Scrolling to the
page top disposes all gallery maps. A mutation observer records the peak number of live map
containers throughout, rather than checking only a final snapshot. A separate 1,280×3,000
viewport exposes several cards simultaneously and verifies the two-map budget.

Readiness is recorded on the canvas container only after MapLibre's idle event with drawable
geometry. A failed map reports failed, not ready. Merely inserting a canvas is not the end
of the readiness measurement. No dataset values, map styling or boundary gates changed.

## Reproduce the optional timing profile

Build/start the prefixed application against the synthetic database as in `CLAUDE.md`, then:

```sh
node scripts/measure-dataset-maps.mjs http://127.0.0.1:3100/bhw-connect/ > /private/map-performance.json
```

The probe is read-only and opens six fresh browser contexts: three portal and three enlarged
UUC runs, sequentially. Run it without competing builds/tests. It uses Chromium at 390×844,
4× CPU slowdown, 80ms network latency, 524,288 download bytes/s and 131,072 upload bytes/s,
with browser cache disabled. These are explicit emulation settings, not a named phone or a
physical 4G connection. Server caches are not reset. Playwright and its Chromium must already
be installed. The optional measurement does not impose timing assertions on ordinary CI.

It reports navigation-to-ready and a narrower document/card-to-ready interval. For the
portal the latter begins immediately before bringing the target card into view; for the
enlarged map it begins after the document/card exists. Neither should be described as cold
navigation latency. The report includes the browser version, date, profile, dataset requests,
live maps and a subset of map resource transfers. It is not a complete page bandwidth audit.
Negative worker resource durations reported by Chromium under emulation are recorded as
null with `timingValid: false`, not interpreted as real timings.

## Local result and outstanding target

[Recorded measurements](DATASET_MAP_PERFORMANCE_LOCAL.json), Chromium 149.0.7827.55:

| Scenario | Document/card-to-ready range | Median | Navigation-to-ready range |
| --- | --- | --- | --- |
| Portal | 3,248–3,509 ms | 3,340 ms | 3,986–4,189 ms |
| Enlarged UUC map | 3,056–3,149 ms | 3,093 ms | 3,422–3,562 ms |

All six runs missed the proposed 3,000ms target, including the narrower interval. The target
is therefore **not accepted**. Each run requested only UUC and had one live map. Every portal
run had no map-data requests before scrolling. The browser lifecycle checks independently
passed for both phone and tall desktop layouts.

The small data payload and compressed boundary/worker transfers are recorded for follow-up;
this measurement alone does not attribute time to a particular parsing, shader or scheduling
cost. Next work should inspect the initialization waterfall and evaluate an optimization
against the same profile. Do not silently prefetch hidden dataset values, expand the WebGL
budget, remove source/coverage checks or relax CSP to make the target pass. Production
same-origin assets, actual persistent hosting and real-device checks remain outstanding.

## Visible-map initialization optimization (#469)

The new CDP waterfall trace exposed a serial startup path. In a fresh control portal run,
the aggregate request began at 1,170ms and finished at 1,284ms relative to the first recorded
asset request; the canvas module began at 1,298ms and the large renderer chunk only at
1,624ms. Renderer code does not depend on the aggregate values, so this delay is avoidable.

A visible card with an activity lease now loads the canvas module and renderer concurrently
with its aggregate request. No map or worker is created until valid data reaches the canvas.
Module loading is shared by the browser module cache. Offscreen/planned cards do not trigger
this work, and data requests, boundaries, disclosure rules and the two-map budget are unchanged.

[Fresh before/after evidence](DATASET_MAP_INITIALIZATION_COMPARISON.json) uses the same profile
and three fresh contexts per scenario, separately from tests and builds:

| Scenario | Fresh control median | Optimized median | Optimized range |
| --- | --- | --- | --- |
| Portal | 3,191 ms | 2,461 ms | 2,358–2,760 ms |
| Enlarged UUC map | 3,140 ms | 2,695 ms | 2,612–2,720 ms |

All six optimized samples satisfy the 3,000ms **post-document/card** target in this local
profile. The recorded cold-navigation timings remain a separate, longer measure. This does
not establish production or real-handset acceptance. The original baseline above remains
historical evidence rather than being overwritten with the faster results. The measurement
script now includes CDP request start/duration timing to make initialization sequencing
reviewable; unavailable worker completion timings remain null.
