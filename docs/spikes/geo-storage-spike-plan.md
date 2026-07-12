# Spike Plan — Geospatial Field Storage & Map Picker

**Project:** Form-Builder SaaS (`dev_formbuilder_app`, "Meridian")
**Status:** ✅ **Completed 2026-07-12** — decision recorded in `docs/adr/0006-geospatial-field-storage-and-map-picker.md` (**Accepted: PostGIS-hybrid storage + Leaflet/OSM capture over a manual/GPS baseline**).
**Produces:** the decision in `docs/adr/0006-geospatial-field-storage-and-map-picker.md` (fills its two scorecards).
**Owner:** whoever runs the Phase-2 Increment-G5 geo spike.

> **How it was run (2026-07-12).** Executed as a **rubric desk-evaluation plus a hands-on Linux-PostGIS validation**. The two decision axes — *storage* and *capture* — were scored against the §6 rubrics; the storage axis additionally ran a throwaway `postgis/postgis:17-3.5` container to prove the parts a Linux environment *can* prove (see §5.1 evidence). This was proportionate because (a) PostGIS on Linux is a decades-mature, well-documented path — the only genuinely unknown facts were the *extension-privilege* requirement and whether a `geometry` column behaves under our **FORCE ROW LEVEL SECURITY** + `NOSUPERUSER`-app-role model, both of which the throwaway container settled definitively; (b) the *Windows-prod* PostGIS install is the one thing a Linux spike cannot prove and is carried as an accepted risk in ADR-0006; and (c) the *capture* axis is analytically determinate given the gate structure (a mandatory offline/DOM baseline, with any map strictly additive), so a map prototype would only have confirmed the rubric. The filled scorecards + rationale live in ADR-0006 §Decision.

> A **spike** is a time-boxed, throwaway investigation. The only deliverable is a *decision plus evidence* — the prototype code (here, a scratch Docker container) is discarded. A spike that overruns has already told you the answer is "harder than it looks."

---

## 1. The question

**How do we store and capture geospatial answers (`geopoint`, `geotrace`, `geoshape`)?** Three coupled unknowns:

1. **Storage** — a real PostGIS `geometry`/GiST column (spatial querying, the reason ADR-0001 chose Postgres) vs. plain JSONB in the existing `submission_answers.answers` (zero-migration, pattern-consistent with repeats/grids, no spatial querying) vs. a hybrid of both.
2. **PostGIS availability** — ADR-0005 calls it "guaranteed" on the self-hosted Windows Server, but it is installed/verified in **none** of the three environments today (local Docker + CI run stock `postgres:17-alpine`; the Windows box has never had the EDB PostGIS component installed). Can we actually depend on it?
3. **Capture / map** — the UX (`docs/ux/form-filling-ux-flow.md` §8.3) wants an interactive map, but the guest runtime is committed to being **request-free / offline-capable** (ADR-0004 C3, `docs/ux/exceptions-log.md`), and map tiles are third-party network requests. Which library (if any), and how to reconcile with the posture?

See ADR-0006 for why this is the one *undesigned* Phase-2 surface and what each outcome invalidates.

---

## 2. Timebox & team

- **Timebox: 3 working days, hard stop.** One engineer.
- Day 1: rubric harness + the Linux-PostGIS validation container. Day 2: score storage + capture; pin the canonical value shape + the XLSForm serialization. Day 3: write ADR-0006 + the doc updates (risk register, PRD, xlsform spec).
- If the Linux-PostGIS validation had failed on any core capability (extension, `geometry(4326)`, GiST, RLS under the app role), storage would have fallen back to **Pure JSONB** immediately — the JSONB envelope is identical either way, so that fallback is non-breaking.

---

## 3. Candidates

**Storage:** Pure JSONB · **PostGIS-hybrid** (JSONB source of truth + a persist-time GiST-indexed geometry projection) · PostGIS-only. **Capture:** manual-entry-only (the mandatory baseline) · **Leaflet + online OSM raster tiles** · MapLibre GL · Mapbox GL JS. Score the *same* reference slice (§4) against each so scores are comparable. For storage, a thin throwaway container is enough — do **not** build the real projection during the spike.

---

## 4. Reference slice — "Site Assessment"

One vertical slice concentrates the geo risk. It must be modelled and reasoned through against every non-disqualified candidate.

- **Fields:** one `geopoint` (captured via GPS with an accuracy reading, and via manual lat/lon), one `geotrace` (a walked path, ≥ 2 vertices), one `geoshape` (a closed plot boundary, ≥ 4 positions incl. the repeated first point).
- **Capture paths exercised:** (a) **online** — pin-drop / tap-to-place vertices on a map; (b) **offline** — GPS "use my location" + hand-typed coordinates + an editable vertex list, with **no network**, keyboard-only.
- **Validation:** out-of-range lat/lon, an unclosed geoshape ring, a geotrace with < 2 points — must reach an identical verdict in the PHP authority and the TS mirror (the two-engine gate GG2).
- **Interop (→ G7):** the same three answers must serialize losslessly to/from the XLSForm/ODK string form (`"lat lon alt accuracy"`, `;`-joined for trace/shape) — see the value-shape mapping pinned in ADR-0006 §D2 and `docs/xlsform-interop-spec.md` §3.1.
- **Spatial (→ storage):** a "find all submissions whose `geopoint` is within N metres of X" query must be expressible as an indexed `ST_DWithin` predicate under the chosen storage model.

---

## 5. What to do per candidate

For each (candidate × axis), record the outcome and a friction note:

1. **Model** the reference-slice answers in the candidate's value shape.
2. **Store & read back** — for storage candidates, prove the write + a representative spatial read; for JSONB, prove that a spatial read means app-side JSON parsing (no index).
3. **Capture client-side** — reason through online (map) and offline (manual/GPS) paths against gates GG1/GG3.
4. **Server-authoritative re-evaluation (GG2)** — confirm the value shape validates identically in PHP + TS (structural checks over a plain JSON object; no engine/grammar change).
5. **Interop** — confirm the lossless XLSForm string ↔ envelope mapping (incl. the lat/lon order flip).
6. **Measure** — migration/infra cost, bundle weight, request-free posture impact, and every point of friction.

### 5.1 Linux-PostGIS validation evidence (throwaway `postgis/postgis:17-3.5`, run 2026-07-12)

The scratch container proved the storage-axis unknowns on Linux (the container was discarded after):

| Check | Result |
|---|---|
| Image / versions | **PostgreSQL 17.5** + **PostGIS 3.5** (`USE_GEOS=1 USE_PROJ=1 USE_STATS=1`) |
| Extension auto-enabled outside `POSTGRES_DB`? | **No** — a freshly `CREATE DATABASE`-d DB (the `meridian_testing` analog) has no `postgis` in `pg_extension`; `CREATE EXTENSION` is required per-DB |
| **`CREATE EXTENSION postgis` as the `NOSUPERUSER` app role** | **Denied** — `ERROR: permission denied to create extension "postgis" / HINT: Must be superuser to create this extension.` → the extension **must** be enabled by the privileged provisioning role (the same dual init-SQL + guarded-migration pattern the RLS roles already use) |
| `CREATE EXTENSION postgis` as superuser | ✅ succeeds; `postgis_version()` → `3.5 USE_GEOS=1 USE_PROJ=1 USE_STATS=1` |
| `geometry(Geometry, 4326)` column + **GiST** index | ✅ `CREATE INDEX … USING gist (geom)` |
| `ST_SetSRID(ST_GeomFromGeoJSON('{"type":"Point","coordinates":[121.0,14.6]}'),4326)` round-trip | ✅ `ST_AsText` → `POINT(121 14.6)`, `ST_SRID` → `4326`, `GeometryType` → `POINT` — confirms **GeoJSON `[lon,lat]` maps to WKT `lon lat`** (the lon-first invariant) |
| Closed Polygon (geoshape) round-trip | ✅ `POLYGON((121 14.6,121.1 14.6,121.1 14.7,121 14.6))`, SRID 4326, `GeometryType` → `POLYGON` |
| **FORCE ROW LEVEL SECURITY** under the `NOSUPERUSER` app role, `INSERT`/`SELECT` as `meridian_app` gated by `current_setting('app.current_tenant_id')` | ✅ a different tenant sees **0** rows; the owning tenant sees **2** — tenant isolation holds on the geometry table exactly as on every other tenant-scoped table |

**Not provable from Linux (carried as an ADR-0006 risk):** whether the **EDB PostGIS StackBuilder component installs and behaves identically on Windows Server 2016** (the prod host, ADR-0005). Mitigation: a documented deploy-time install + extension-owner grant step in `docs/deployment-infrastructure.md` + a manual post-deploy geometry smoke.

---

## 6. Scorecards (transcribed into ADR-0006)

Score each candidate **0–5**; weights sum to 100; weighted total = `Σ(score × weight) / 5`. **Capture gates:** GG1 (M1), GG2 (value-shape property, all pass), GG3 (M2) — ≤ 2 on a gate disqualifies.

### Storage

| Criterion (weight) | Pure JSONB | PostGIS-hybrid | PostGIS-only |
|---|---|---|---|
| S1 Spatial querying (22) | 1 | **5** | 5 |
| S2 Hybrid-model consistency (15) | 5 | **5** | 1 |
| S3 Migration/operational simplicity + reversibility (12) | 5 | **3** | 2 |
| S4 Interop / export (13) | 4 | **5** | 4 |
| S5 Install/availability risk, 3 envs (18) | 5 | **2** | 2 |
| S6 Index/query performance at scale (10) | 2 | **5** | 5 |
| S7 Immutable-version + RLS fit (10) | 5 | **5** | 3 |
| **Weighted total /100** | 73.8 | **84.4** ✅ | 63.4 |

### Capture (gates: M1, M2 ≥ 3)

| Criterion (weight) | Manual-only *(baseline)* | Leaflet + OSM | MapLibre GL | Mapbox GL |
|---|---|---|---|---|
| M1 Offline / never-unfillable (20) **[GG1]** | 5 | 4 | 4 | 4 |
| M2 WCAG 2.2 AA / DOM-first (18) **[GG3]** | 5 | 4 | 3 | 3 |
| M3 Visual-capture UX (15) | 1 | **5** | 5 | 5 |
| M4 Bundle / low-bandwidth (12) | 5 | 4 | 1 | 1 |
| M5 Request-free / CSP fit (12) | 5 | 2 | 2 | 1 |
| M6 Licensing / tile freedom (11) | 5 | 4 | 4 | 1 |
| M7 Maintenance (12) | 5 | 4 | 3 | 3 |
| **Weighted total /100** | 88.0 (floor) | **78.2** ✅ | 65.0 | 56.0 |
| **Gate passed?** | ✅ | ✅ | ✅ | ✅ |

"Manual-only" is the **mandatory baseline** (the GG1/GG3 gates require it), not a competitor; the real contest is *which map layer sits on top of it*, and Leaflet + OSM wins among map libraries. See ADR-0006 §Decision for the full "reading the scorecard honestly" note (Leaflet+OSM is a strict superset of the baseline; its only real cost is M5, the deliberately-accepted request-free trade).

---

## 7. Exit criteria (done = all of these)

- [x] The Linux-PostGIS core capability set validated with evidence (§5.1), not opinion.
- [x] Both scorecards filled with weighted totals.
- [x] The canonical geo value shape pinned (ADR-0006 §D2) — including the GeoJSON `[lon,lat]` vs ODK `"lat lon"` order footgun.
- [x] The XLSForm serialization mapping pinned so the §3 "Full" fidelity claim is honest (`docs/xlsform-interop-spec.md` §3.1).
- [x] ADR-0006 moved to **Accepted** with the chosen options + scorecards + rationale; Risk **R11** added to `docs/architecture/technical-architecture.md` §9 and PRD §9.2 updated **in the same PR**.
- [x] The Windows-prod PostGIS install carried as an explicit ADR-0006 risk with a documented deploy-time mitigation.

---

## 8. Guardrails

- **No production code.** The Docker container + any SQL were throwaway; the real projection table + `GeoIndexProjector` are built in Increment G5b against this decision, not smuggled in during the spike.
- **Verify external facts at build time.** OSM tile-usage policy, attribution requirements, and the EDB Windows PostGIS packaging change; do not score from memory (this project's standing "indicative, not pinned" caveat).
- **Weight the gates.** It is easy to be dazzled by a slick map (M3) and under-weight the offline/a11y baseline (GG1/GG3) — the gate rule + the "manual-only is the mandatory floor" framing exist precisely to stop that.
