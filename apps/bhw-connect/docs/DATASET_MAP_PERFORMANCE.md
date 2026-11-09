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

## Boundary download beside the data request (trial, rejected, 9 November 2026)

The boundary asset depends only on the validated scope, yet the canvas fetched it only after
the aggregate response arrived, so the two downloads ran in series. A trial started the
boundary for a visible, leased card beside its data request and handed that download to the
canvas. [Evidence](DATASET_MAP_BOUNDARY_PRELOAD_TRIAL.json), same emulation profile, three
fresh contexts per scenario, run on a machine with no database, so the dataset API was
answered by a synthetic payload after 150ms (`MEASURE_STUB_API=150`): client start-up only.

The waterfall did overlap (the boundary began with the data request instead of 530ms later),
but time to ready got **worse**: portal post-document median 2,644 → 2,963 ms, enlarged map
2,554 → 2,766 ms, in the same direction in every sample. The boundary's own transfer stretched
from 512 to 819ms because it shares the emulated 512KB/s link with the renderer chunk and the
map worker. This profile is bandwidth-bound: the national boundary (179,009 bytes compressed),
the worker (148,331) and the renderer chunk are about 1.3s of transfer on their own, so
reordering cannot recover time and contention slightly delays the renderer. The change was not
shipped.

What remains is bytes, not ordering. Reducing the national boundary's size (coarser geometry or
coordinate precision for the national view) would need the boundary-acceptance gates, so it is
recorded here, not attempted. Do not retry request overlap without a change in what is
transferred. The probe now accepts `MEASURE_STUB_API=<ms>` for a machine with no database and
`MEASURE_CHROMIUM=<path>` for a pre-installed browser; the report states when the API was
stubbed. Production hosting and real-handset checks remain outstanding.
