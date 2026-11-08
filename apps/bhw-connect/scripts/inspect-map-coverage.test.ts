import { expect, it } from "vitest";
import { inspectMapCoverage } from "./inspect-map-coverage.mjs";
const url = "https://example.test/bhw-connect/api/dataset-map?dataset=nhfr-2026-09&indicator=nhfr.facilities&period=September+2026&level=national&code=PH";
const payload = { state: "available", rows: [
  { code: "01", name: "Zero", value: 0 }, { code: "02", name: "Missing", value: null },
  { code: "99", name: "Unplaced", value: 42 },
], total: 123, source: "Test", date: "2026-09-01", license: null,
  childLevel: "region", geometryUrl: "/bhw-connect/geo/regions.json" };
const geometry = { type: "FeatureCollection", features: ["01", "02"].map(code => ({
  type: "Feature", properties: { geo_code: code },
  geometry: { type: "Polygon", coordinates: [[[120, 12], [121, 12], [121, 13], [120, 12]]] },
})) };
const response = (body: unknown, status = 200) => Response.json(body, { status });
it("reports coverage separately from geometry without printing observations or recomputing totals", async () => {
  const result = await inspectMapCoverage(url, async (target) => response(String(target).includes("/geo/") ? geometry : payload));
  expect(result.coverage).toEqual({ rows: 3, reportedRows: 2, zeroRows: 1, missingOrUnavailableRows: 1,
    navigationOnlyRows: 0, hasReportedParentTotal: true });
  expect(result.geometry).toMatchObject({ state: "checked", rowsWithoutGeometry: 1, reportedRowsWithoutGeometry: 1 });
  expect(JSON.stringify(result)).not.toContain('"value"');
  expect(JSON.stringify(result)).not.toContain('Unplaced');
});
it.each([["unsupported", 400], ["unavailable", 200], ["failed", 503]] as const)("keeps %s distinct from empty observations", async (state, status) => {
  let calls = 0;
  const result = await inspectMapCoverage(url, async () => { calls++; return response({ state }, status); });
  expect(result).toMatchObject({ state, httpStatus: status, coverage: null });
  expect(calls).toBe(1);
});
it("retains table coverage when geometry fails, instead of claiming every polygon is missing", async () => {
  const result = await inspectMapCoverage(url, async (target) => String(target).includes("/geo/")
    ? new Response("failure", { status: 503 }) : response(payload));
  expect(result.coverage?.rows).toBe(3);
  expect(result.geometry?.state).toBe("not-checked");
  expect(result.geometry).not.toHaveProperty("rowsWithoutGeometry");
});
it("distinguishes navigation-only levels and rejects invented values there", async () => {
  const data = { ...payload, total: null, valueSupport: "navigation-only", rows: payload.rows.map(row => ({ ...row, value: null })) };
  expect((await inspectMapCoverage(url, async (target) => response(String(target).includes("/geo/") ? geometry : data))).coverage)
    .toMatchObject({ navigationOnlyRows: 3, missingOrUnavailableRows: 0 });
  await expect(inspectMapCoverage(url, async () => response({ ...payload, valueSupport: "navigation-only" }))).rejects.toThrow("Navigation-only");
});
it("refuses duplicate rows and cross-origin/scope geometry requests", async () => {
  await expect(inspectMapCoverage(url, async () => response({ ...payload, rows: [payload.rows[0], payload.rows[0]] }))).rejects.toThrow("duplicate");
  await expect(inspectMapCoverage(url, async () => response({ ...payload, geometryUrl: "https://elsewhere.test/geometry" }))).rejects.toThrow("geometry path");
});
it("rejects missing/duplicate query choices before reading and bounds response bodies", async () => {
  await expect(inspectMapCoverage(url + "&period=2025")).rejects.toThrow("exactly");
  await expect(inspectMapCoverage(url.replace("/bhw-connect", ""))).rejects.toThrow("prefixed");
  await expect(inspectMapCoverage(url, async () => response("x".repeat(2 * 1024 * 1024)))).rejects.toThrow("size limit");
});

const rateUrl = url.replace("dataset=nhfr-2026-09", "dataset=bhw-stepzero-2026")
  .replace("indicator=nhfr.facilities", "indicator=bhw.declared-accreditation").replace("September+2026", "2026");
it.each([0, 33.33, 100, null])("audits parent rate presence without publishing the rate (%s)", async parentRate => {
  const data = { ...payload, total: null, parentRate };
  const report = await inspectMapCoverage(rateUrl, async target => response(String(target).includes("/geo/") ? geometry : data));
  expect(report.coverage).toMatchObject({ hasReportedParentTotal: false, hasReportedParentRate: parentRate !== null });
  expect(report).not.toHaveProperty("parentRate");
  expect(JSON.stringify(report)).not.toContain('"value"');
});
it.each([
  { parentRate: undefined }, { parentRate: -1 }, { parentRate: 101 }, { parentRate: "25" },
  { parentRate: 25, total: 25 }, { parentRate: 25, rows: [{ code: "01", name: "Invalid", value: 101 }] },
])("rejects malformed or mislabeled published rates: %j", async override => {
  await expect(inspectMapCoverage(rateUrl, async () => response({ ...payload, total: null, ...override })))
    .rejects.toThrow("Invalid published rate");
});
it("rejects rate fields on count indicators and values in navigation-only rate views", async () => {
  await expect(inspectMapCoverage(url, async () => response({ ...payload, parentRate: 25 })))
    .rejects.toThrow("Unexpected parent rate");
  await expect(inspectMapCoverage(rateUrl, async () => response({ ...payload, total: null, parentRate: 0,
    valueSupport: "navigation-only", rows: [] }))).rejects.toThrow("Navigation-only");
});

it("audits Census rates without treating them as additive totals", async () => {
  const census = rateUrl.replace("bhw-stepzero-2026", "bhw-2025").replace("bhw.declared-accreditation", "bhw.profile-accreditation").replace("period=2026", "period=2025");
  const data = { ...payload, total: null, parentRate: 0 };
  const result = await inspectMapCoverage(census, async target => response(String(target).includes("/geo/") ? geometry : data));
  expect(result.coverage).toMatchObject({ hasReportedParentTotal: false, hasReportedParentRate: true });
  await expect(inspectMapCoverage(census, async () => response({ ...data, parentRate: 101 }))).rejects.toThrow("Invalid published rate");
});
