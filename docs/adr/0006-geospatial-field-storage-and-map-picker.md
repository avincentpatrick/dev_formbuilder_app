# ADR-0006: Geospatial Field Storage & Map Picker (geopoint / geotrace / geoshape)

## Status

**Accepted — 2026-07-12.** The Phase-2 geo-storage spike (`docs/spikes/geo-storage-spike-plan.md`) is complete and its two scorecards are transcribed into §Decision below. **Decision: store geo answers as a canonical GeoJSON envelope in the existing `submission_answers.answers` JSONB *and* project them into a new GiST-indexed PostGIS geometry table at persist time (a "hybrid" storage model); capture them with a bundled Leaflet map over online OpenStreetMap tiles, on top of a mandatory manual-lat/lon-plus-GPS baseline.** This resolves the one *undesigned* Phase-2 surface (Risk **R11** in §9 of the Technical Architecture Doc) and unblocks Increment G5b (the geo field-type build).

- **Deciders:** Founding engineering (architecture owner) + product.
- **Related ADRs:** **ADR-0001** (PostgreSQL — chose Postgres partly *for* PostGIS; PG 16+ floor for PostGIS 3.x), **ADR-0005** (self-hosted Windows Server — asserts RLS + PostGIS "guaranteed available"), and **ADR-0004** (build-custom form engine — its criterion **C3** commits the guest runtime to render "fully from a cached manifest, no network, embeddable cross-domain under CSP"; this ADR knowingly relaxes that for map tiles). Depends on none of them for correctness; it *operationalizes* ADR-0001's PostGIS intent and *narrows* ADR-0004's request-free posture.
- **Related docs:** `docs/spikes/geo-storage-spike-plan.md` (the method + evidence that fills this ADR's scorecards); `docs/data-dictionary.md` §7/§8/§9 (the hybrid answer model this extends); `docs/xlsform-interop-spec.md` §3.1 (the geo value serialization pinned here, for G7 round-trip); `docs/ux/form-filling-ux-flow.md` §8.3 (geo capture UX); `docs/offline-first-sync-design.md` (offline tiles — the deferred G8 dependency); `docs/deployment-infrastructure.md` (the Windows PostGIS install step); PROGRESS.md Increment-G decomposition (G5 → G5a/G5b1/G5b2).

---

## Context

The form engine's field-type catalog includes three geospatial types — **`geopoint`** (a single location), **`geotrace`** (a path / line), **`geoshape`** (a closed area / polygon). They already exist as `FieldType` enum cases (category `Geographic`, `isAdvanced`), and the XLSForm interop spec (§3) already claims all three round-trip with "Full" fidelity. But **everything below the enum is a stub**: the submission pipeline, the semantic validator (PHP + its byte-identical TS mirror), the runtime renderers, and the inbox/export formatter all fall into pass-through / "unsupported" default arms for geo. Geo is the **one Phase-2 surface the architecture never actually designed** — every earlier increment (repeat groups, grids, cascading, the expression engine) either reused an existing shape or was fully specified in advance. Geo is not, because it sits on three coupled, genuinely-undecided questions:

1. **Is PostGIS actually available where we run?** ADR-0005 calls RLS + PostGIS "guaranteed available" on the self-hosted Windows Server (EDB build), and ADR-0001 chose Postgres over MySQL *specifically* for real geo (`ST_*` + GiST) over legacy's "unindexed JSON strings." Yet PostGIS is installed and verified in **none** of the three environments today: local Docker (`docker-compose.yml`) and CI (`ci.yml`, three jobs) both run stock `postgres:17-alpine`, and the Windows box has never had the EDB PostGIS StackBuilder component installed. "Guaranteed" is a prose claim wired into nothing.

2. **Geometry column or plain JSONB?** The whole submission model is *hybrid* (data-dictionary §8/§9): the tenant answer document is a JSONB blob in `submission_answers.answers` (the source of truth), and hot-path scalar fields are transactionally projected into a typed `submission_answer_index` (Risk R1). Geo could ride entirely in the JSONB — zero migration, exactly like repeat groups (G1) and matrix/likert grids (G4b) — but the typed index is scalars-only (`IndexedDataType` has no geometry member), so that path buys **no spatial querying** (distance, containment, map-view, validity), which is the entire reason Postgres was chosen. A real `geometry`/GiST projection buys the querying but is net-new structure that leans on unknown #1.

3. **How is a geo answer captured, given the request-free posture?** The form-filling UX (§8.3) wants an interactive pin-drop map (and, for trace/shape, tap-to-place vertices). But the guest runtime is committed to being **request-free and offline-capable** (ADR-0004 C3, `docs/ux/exceptions-log.md`), and map tiles are network requests to a third-party origin — a direct conflict. There is no chosen map library anywhere in the codebase, and offline tile caching is entirely unaddressed by `docs/offline-first-sync-design.md`.

**The core tension:** the ambitious answer (real PostGIS geometry + a live map) is what ADR-0001 and the UX doc imply, but it takes on an *unverified-on-Windows* extension dependency and *breaks* ADR-0004's request-free guarantee — while the austere answer (JSONB-only + manual coordinate entry) is trivially safe but throws away the spatial capability Postgres was chosen for and makes drawing a polygon by hand nearly unusable. This ADR must pick deliberately rather than default either way.

---

## Options on the table

**Storage:**
1. **Pure JSONB** — the GeoJSON envelope lives only in `submission_answers.answers`; no geometry column, no extension. Spatial reads mean parsing JSON in the app. *(Legacy's exact posture, which ADR-0001 rejected.)*
2. **PostGIS-hybrid** *(the ADR-0001/hybrid-model-consistent option)* — JSONB stays the source of truth **and** a persist-time projection populates a GiST-indexed `geometry(Geometry,4326)` column in a new `submission_geo_index` table, mirroring how `submission_answer_index` projects scalars.
3. **PostGIS-only** — the geometry column is the sole store; the answer document does not carry geo. Breaks the "answer document is self-contained + version-pinned" invariant.

**Map / capture:**
1. **Manual-entry-only baseline** — labeled lat/lon number inputs + `navigator.geolocation` GPS + (for trace/shape) an editable vertex list. Fully offline, pure DOM, no map, no tiles.
2. **Leaflet + online OpenStreetMap raster tiles** — a bundled ~42 KB library rendering a pannable raster map over OSM tiles, *on top of* the manual/GPS baseline (map = progressive enhancement).
3. **MapLibre GL** — a WebGL vector-tile renderer; richer, but ~200 KB+, GPU-dependent, and needs a vector tile/style source.
4. **Mapbox GL JS** — commercial; API-key gated, per-load billing, external tile servers, restrictive license.

---

## Decision drivers — the weighted rubrics

Two orthogonal decisions (storage, capture), each scored **0–5** per criterion with weights summing to 100; weighted total = `Σ(score × weight) / 5` (the ADR-0004 convention). The **capture** decision carries three pass/fail **gates** — any candidate scoring **≤ 2** on a gate criterion is disqualified regardless of total:

- **GG1 — Never-unfillable / offline capture:** a respondent can complete a geo field with no network (data-dictionary spirit + §8.3 "a geo field is never unfillable"). *(criterion M1)*
- **GG2 — Server-authoritative validation in the two-engine model:** the captured value validates to an identical verdict in the PHP authority and the TS mirror (Risk R3 golden-file lockstep). *(a property of the value shape, satisfied by all options — see D2 — so it gates the shape, not the library.)*
- **GG3 — WCAG 2.2 AA / axe-clean, DOM-first:** capture is keyboard-operable through real DOM controls, not a canvas-only interaction axe cannot see. *(criterion M2)*

### Storage criteria

| # | Criterion | Weight | What "5" looks like |
|---|---|---:|---|
| S1 | **Spatial querying** — `ST_*` distance/containment/validity, map-view, GiST | 22 | First-class indexed spatial queries |
| S2 | **Hybrid-model consistency** — JSONB source of truth + typed projection, atomic (Risk R1) | 15 | Fits the `submission_answer_index` pattern exactly |
| S3 | **Migration / operational simplicity + reversibility** | 12 | Zero net-new infra; trivially reversible |
| S4 | **Interop / export** — GeoJSON/KML export, XLSForm round-trip | 13 | Lossless in both directions |
| S5 | **Install / availability risk** across Windows-prod + Linux-CI + local-Docker | 18 | Works identically everywhere, no extension |
| S6 | **Index / query performance at scale** | 10 | Indexed spatial predicates, no full-scan JSON parse |
| S7 | **Immutable-version + RLS fit** | 10 | Answer doc self-contained + version-pinned; table RLS-isolated |

### Capture criteria

| # | Criterion | Weight | Gate |
|---|---|---:|---|
| M1 | **Offline / never-unfillable capture** | 20 | **GG1** |
| M2 | **WCAG 2.2 AA / axe-clean, DOM-first** | 18 | **GG3** |
| M3 | **Visual-capture UX** — pin-drop, trace, shape | 15 | — |
| M4 | **Bundle size / low-bandwidth fit** (Persona A) | 12 | — |
| M5 | **Request-free / CSP posture fit** (ADR-0004 C3) | 12 | — |
| M6 | **Licensing / tile-source freedom / lock-in** | 11 | — |
| M7 | **Maintenance / integration effort** | 12 | — |

---

## Decision

> **Storage = PostGIS-hybrid. Capture = Leaflet + online OSM tiles, atop a mandatory manual-lat/lon + GPS baseline.** JSONB remains the source of truth; a GiST-indexed `submission_geo_index` geometry projection is written transactionally alongside it. PostGIS is adopted now (Docker + CI images swap to `postgis/postgis`; the extension is enabled by a privileged role). Leaflet is bundled (lazy-loaded); the map is a progressive enhancement over always-present, offline-safe, axe-clean DOM controls.

### Storage scorecard

| Candidate | S1 | S2 | S3 | S4 | S5 | S6 | S7 | **Weighted /100** |
|---|---|---|---|---|---|---|---|---|
| **PostGIS-hybrid** | 5 | 5 | 3 | 5 | 2 | 5 | 5 | **84.4** ✅ **chosen** |
| Pure JSONB | 1 | 5 | 5 | 4 | 5 | 2 | 5 | 73.8 |
| PostGIS-only | 5 | 1 | 2 | 4 | 2 | 5 | 3 | 63.4 |

### Capture scorecard (gates: M1, M2 ≥ 3)

| Candidate | M1 | M2 | M3 | M4 | M5 | M6 | M7 | **Weighted /100** | Gate |
|---|---|---|---|---|---|---|---|---|---|
| Manual-only *(mandatory baseline / floor)* | 5 | 5 | 1 | 5 | 5 | 5 | 5 | **88.0** | ✅ PASS |
| **Leaflet + OSM** | 4 | 4 | 5 | 4 | 2 | 4 | 4 | **78.2** | ✅ **chosen** |
| MapLibre GL | 4 | 3 | 5 | 1 | 2 | 4 | 3 | 65.0 | ✅ PASS |
| Mapbox GL JS | 4 | 3 | 5 | 1 | 1 | 1 | 3 | 56.0 | ✅ PASS |

**Reading the capture scorecard honestly:** "Manual-only" scores *highest* — but it is **not a competing option**, it is the **mandatory baseline** every viable capture path must contain (the GG1/GG3 gates *require* real, offline, DOM-first controls). The genuine contest is *"which map library, if any, sits on top of that baseline,"* and among map libraries **Leaflet + OSM wins decisively** (78.2 vs 65.0 vs 56.0). Because Leaflet+OSM is a strict **superset** of manual-only (it inherits the manual/GPS controls and adds a map), it cannot be *less* capable than the floor; its only real cost is **M5 = 2** — online tiles break the request-free posture (ADR-0004 C3). Product deliberately accepts that trade because the rubric's weights under-represent the fact that **`geotrace`/`geoshape` are barely usable without a map** (hand-typing a 20-vertex polygon is not a real workflow), and because the break degrades *gracefully*: offline, geo capture falls back to the manual/GPS baseline and the field stays fillable (GG1 holds). MapLibre and Mapbox lose on bundle weight (M4 = 1, wrong for Persona A's low-bandwidth context) and, for Mapbox, licensing (M6 = 1).

### The three sub-decisions

- **D1 — Storage: hybrid JSONB-canonical + PostGIS projection.** The canonical geo answer is a GeoJSON envelope (D2) in `submission_answers.answers` (the version-pinned, self-contained source of truth). At persist time, top-level geo fields are projected into a new **`submission_geo_index`** table — `geometry(Geometry, 4326)` + GiST + tenant-scoped RLS — written **inside the same transaction** as the JSONB and the scalar `submission_answer_index` (the Risk-R1 atomicity guarantee, extended to geometry). The scalar index (`AnswerIndexProjector`) already skips array/object answers, so it needs no change. Geo-inside-a-repeatable-section is stored in JSONB but **not** projected (the projection is one row per field per submission — top-level only), mirroring how the scalar index never reaches inside repeat arrays; G5b bans geo in repeatable sections at publish (a restriction that is non-breaking to relax later).

- **D2 — Canonical value shape: a GeoJSON-geometry envelope** with a single foreign member `accuracy`:
  - `geopoint` → `{ "type": "Point", "coordinates": [lon, lat] , "accuracy": <m>? }` (altitude, when captured, is the standard 3rd ordinate `[lon, lat, alt]`; `accuracy` in metres is a foreign member `ST_GeomFromGeoJSON` ignores).
  - `geotrace` → `{ "type": "LineString", "coordinates": [[lon,lat], …] }` (≥ 2 positions).
  - `geoshape` → `{ "type": "Polygon", "coordinates": [[ [lon,lat], …, firstPoint ]] }` (one linear ring, **closed** — first == last — with ≥ 4 positions).
  - An empty answer is `null`; the renderer never emits `{}`.
  - **Position order is GeoJSON `[longitude, latitude, (altitude)]` everywhere internal** — the JSONB, the PostGIS geometry (PostGIS is X=lon / Y=lat), the TS/PHP engines, and the wire. This satisfies **GG2**: the value is a plain JSON object both engines see identically, and validation (range, closure, min-points, type-match) is structural — no engine coercion or grammar change. **The load-bearing footgun, pinned:** ODK/XLSForm's `geopoint` string is `"lat lon altitude accuracy"` — **latitude first, the opposite order.** The lat↔lon swap happens in exactly **one** place — the G7 XLSForm converter — plus cosmetically in the manual-entry UI (which shows human "Lat, then Lon" and swaps on assembly). Nothing else ever reorders. See `docs/xlsform-interop-spec.md` §3.1.

- **D3 — Capture: bundled Leaflet + online OSM raster tiles, over a mandatory manual/GPS baseline.** The always-present controls are labeled `MdsNumberInput` lat/lon (+ altitude when configured), a "Use my location" `navigator.geolocation` button that fills them plus `accuracy`, and — for trace/shape — a keyboard-operable, add/remove/reorder **vertex list**; an `aria-live` region announces coordinate/vertex changes. Leaflet renders a pan/tap/drag map as enhancement, dynamically `import()`-ed so geo-free forms pay zero bundle. **CSP:** a new `Content-Security-Policy`, scoped to the map-bearing routes, will allow `img-src 'self' data: https://*.tile.openstreetmap.org https://tile.openstreetmap.org` (with the required OSM attribution); the Leaflet CSS + marker PNGs are bundled locally (no CDN). **Offline tiles are explicitly out of scope and handed to G8** (PWA/offline) — offline, the map is blank and capture falls back to manual/GPS, which keeps the field fillable.

**Method note.** The spike was run 2026-07-12 as a **desk evaluation against the two rubrics plus a hands-on Linux-PostGIS validation** (`docs/spikes/geo-storage-spike-plan.md`). The storage question turned on two facts that documented capability settles: PostGIS on Linux is a solved, decades-mature path (verified in a throwaway `postgis/postgis:17-3.5` container — `CREATE EXTENSION`, a `geometry(Geometry,4326)` column, a GiST index, `ST_GeomFromGeoJSON`/`ST_AsText` round-trip, and **FORCE ROW LEVEL SECURITY under the `NOSUPERUSER` app role** all behaved as designed), and **`CREATE EXTENSION postgis` requires a superuser / DB-owner** (the app role `meridian_app` is `NOSUPERUSER`, so the extension must be enabled by the privileged provisioning role — the same dual "init-SQL + guarded-migration" pattern the RLS roles already use). The **Windows-prod** PostGIS install is the one thing a Linux spike cannot prove and is carried as an accepted risk (below). The capture question was settled by rubric + posture reasoning, not a prototype build, because the gate structure (a mandatory offline/DOM baseline, with the map strictly additive) makes the outcome analytically determinate.

---

## Consequences (chosen path: **PostGIS-hybrid + Leaflet/OSM**)

### Positive
- **Real spatial capability** — GiST-indexed `ST_*` distance/containment/validity queries, GeoJSON/KML export (competitive-matrix Feature #8), and future map dashboards are unlocked, honoring ADR-0001's original rationale for choosing Postgres.
- **No new architectural pattern** — the geo projection is the *same* shape as the existing scalar `submission_answer_index` (JSONB truth + atomic typed projection), so it inherits Risk R1's transactional guarantee, the RLS/migration-lint discipline, and the version-immutability invariant.
- **Byte-identical two-engine validation is free** — because the value is a plain JSON object validated structurally, no `EngineValue` widening, no `Coercion` change, and **no `GRAMMAR_VERSION` bump** (same posture as G4a/G4b); the golden-file drift gate (Risk R3) covers geo with ordinary vectors.
- **The field is never unfillable** — GPS + manual coordinates work with no network, on any device, keyboard-only; the visual map is pure upside when online.
- **XLSForm round-trip becomes honest** — §3.1 now pins the serialization the "Full" fidelity claim depended on, de-risking G7.

### Negative / accepted trade-offs
- **PostGIS becomes a hard install dependency** in all three environments — the Docker + CI images swap to `postgis/postgis`, and the Windows-prod DB must have the EDB PostGIS component installed and the extension enabled by a privileged role before G5b deploys. A DB without PostGIS now fails `migrate:fresh`.
- **The request-free guest-runtime guarantee is knowingly narrowed** — map tiles are third-party network requests, so ADR-0004 C3's "no network" no longer holds *for the tile layer* when a respondent chooses to use the map. Mitigated by graceful degradation (manual/GPS baseline offline) and a scoped CSP; a CSP is introduced where none existed, so it is applied only to map-bearing routes first to avoid breaking Vite HMR / Inertia inline elsewhere.
- **A guest-bundle weight increase** — ~42 KB (gz) of Leaflet + CSS + marker assets, dynamically imported so only geo-bearing forms load it. Persona A's low-bandwidth context makes this non-trivial; tiles remain the dominant runtime network cost.
- **Offline maps remain unsolved** — deferred to G8; until then offline respondents see a blank map and use manual/GPS.
- **The lat/lon order mismatch is a latent bug surface** — GeoJSON `[lon,lat]` internally vs ODK `"lat lon"` externally; contained to one converter + one UI assembly point, unit-tested both directions in G7/G5b2.

### Risks & Mitigations
| Risk | Mitigation |
|---|---|
| PostGIS-on-Windows install unverifiable from Linux CI (EDB StackBuilder packaging / behavior) | Document the StackBuilder + `CREATE EXTENSION` + extension-owner grant step in `docs/deployment-infrastructure.md`; pin PostGIS 3.x; a manual post-deploy geometry smoke on the Windows DB; Linux CI proves the SQL and geometry behavior on the same PG major |
| `CREATE EXTENSION postgis` needs superuser; the app role is `NOSUPERUSER` | Enable via the privileged provisioning role — init SQL (Docker/CI) + a guarded `pgsql_privileged`-connection migration (the existing dual pattern for RLS roles); document the required prod grant |
| Introducing a CSP breaks existing inline scripts/styles or HMR | Scope the CSP to map-bearing routes only; config-driven allowlist; test in dev + all six CI jobs before merge |
| Guest bundle bloat on low bandwidth | Dynamic `import('leaflet')` so geo-free forms pay nothing; no size-gate exists but "small bundle" is a standing goal |
| OSM tile-usage-policy / attribution / availability | Honor the OSM attribution + usage policy; keep the tile origin config-driven so a self-hosted/paid tile source can be swapped in without code change; treat heavy usage as a revisit trigger |
| lat/lon order regression in import/export | Centralize the swap in one converter helper; unit-test both directions; keep everything else lon-first |

---

## Alternatives Considered
- **Pure JSONB (no PostGIS)** — simplest and lowest-risk (S5 = 5, zero migration, request-free-neutral), and genuinely viable if spatial querying were never needed. Rejected because it reproduces exactly legacy's "unindexed JSON strings" that ADR-0001 chose Postgres to escape, and forecloses map-view/GeoJSON export without a later migration. Remains the fallback if PostGIS-on-Windows proves unworkable — the JSONB envelope is identical either way, so dropping the projection is non-breaking.
- **PostGIS-only** — rejected: it breaks the "answer document is self-contained and version-pinned" invariant (geo would live outside the answers blob every other field type lives in) and the hybrid model's clean JSONB-truth/typed-projection split (S2 = 1).
- **Manual-entry-only capture** — the mandatory baseline, not a competitor; kept as the graceful-degradation and offline path. Chosen *plus* Leaflet, not *instead of* it.
- **MapLibre GL / Mapbox GL** — richer WebGL rendering, but too heavy for the guest bundle (M4 = 1) and, for Mapbox, API-key + per-load billing + restrictive license (M6 = 1). Leaflet's raster + DOM model is also friendlier to the axe/keyboard requirement.

## When to Revisit
- **If PostGIS cannot be installed/enabled on the Windows-prod DB** — fall back to Pure JSONB (drop the projection table + `GeoIndexProjector`; the JSONB envelope is unchanged) or move the DB to a Linux host (ADR-0005's Forge+VPS alternative).
- **When G8 (PWA/offline) lands** — revisit offline tile caching (self-hosted raster/vector tiles, or an mbtiles/service-worker cache) so the map works offline, not just the manual baseline.
- **If OSM tile usage/policy/latency becomes a problem** — swap the config-driven tile origin to a self-hosted or paid provider.
- **If guest-bundle weight or map a11y proves costly in the field** — reconsider capture down to the manual-only baseline for the public runtime.

## Related Decisions
- **ADR-0001** (PostgreSQL) — this operationalizes its PostGIS intent (the "geo backed by real spatial indexing, not JSON strings" driver).
- **ADR-0005** (self-hosted Windows Server) — this converts its "PostGIS guaranteed available" assertion into a concrete, tested install dependency + a documented deploy step; the Windows-verify residual is the one gap.
- **ADR-0004** (build-custom engine) — this **narrows** its C3 request-free guarantee for the map-tile layer only, with graceful offline degradation.

## References
- `docs/spikes/geo-storage-spike-plan.md` — the method + Linux-PostGIS evidence that fills this ADR's scorecards.
- `docs/data-dictionary.md` §7/§8/§9 (hybrid answer model), `docs/architecture/technical-architecture.md` §9 Risk R1 & R11.
- `docs/xlsform-interop-spec.md` §3.1 (geo value serialization, pinned by this ADR for G7).
- `docs/ux/form-filling-ux-flow.md` §8.3 (geo capture UX); `docs/offline-first-sync-design.md` (offline tiles → G8).
- OpenStreetMap tile usage policy + Leaflet documentation — **verify the current OSM tile-usage policy and attribution requirement at build time** (consistent with this project's standing caveat on fast-changing external facts).
