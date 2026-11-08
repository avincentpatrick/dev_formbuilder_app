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
