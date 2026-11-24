import { beforeEach, expect, it, vi } from "vitest";
import { parse } from "csv-parse/sync";
import { GET } from "./route";
const mock = vi.hoisted(() => ({ types: vi.fn(), counts: vi.fn() }));
vi.mock("@/lib/db/nhfr", () => ({ getNhfrCounts: mock.counts, getNhfrTypes: mock.types }));
vi.mock("@/lib/db/geo", () => ({
  getGeoByCode: async () => ({ geoName: "Synthetic", geoLevel: "citymun" }),
}));
const types = [
  { facilityType: "Clinic", nFacilities: 6, nGovernment: 2, nPrivate: 4 },
  { facilityType: "Hospital", nFacilities: 4, nGovernment: 3, nPrivate: 1 },
];
const url = "http://localhost/api/export/facilities/mix?mode=psgc&location=citymun:0102801&";
beforeEach(() => {
  vi.clearAllMocks();
  mock.counts.mockResolvedValue({ nFacilities: 10 });
  mock.types.mockResolvedValue(types);
});
it("exports exact search results with the full denominator, raw precision and search metadata", async () => {
  const response = await GET(
    new Request(url + "format=csv&view=table&order=lowest&type=Hospital&type=Clinic&q=hosp"),
  );
  expect(response.status).toBe(200);
  const rows = parse(await response.text(), { bom: true, columns: true }) as Record<
    string,
    string
  >[];
  expect(rows).toHaveLength(1);
  expect(rows[0]["Facility type"]).toBe("Hospital");
  expect(rows[0]["Share of all facilities (%)"]).toBe("40");
  expect(rows[0]["Area facilities"]).toBe("10");
  expect(rows[0]["Displayed facilities"]).toBe("4");
  expect(rows[0]["Exact-figure search"]).toBe("hosp");
  expect(rows[0]["Selected types"]).toBe("Clinic; Hospital");
});
it("generates all six real formats in exact-table view", async () => {
  for (const format of ["csv", "xlsx", "svg", "png", "pdf", "pptx"]) {
    const response = await GET(new Request(url + `format=${format}&view=table&q=Hospital`));
    expect(response.status).toBe(200);
    const bytes = Buffer.from(await response.arrayBuffer());
    expect(bytes.length).toBeGreaterThan(100);
    if (format === "pdf") expect(bytes.subarray(0, 4).toString()).toBe("%PDF");
    if (format === "png") expect([...bytes.subarray(0, 4)]).toEqual([137, 80, 78, 71]);
    if (["xlsx", "pptx"].includes(format)) expect(bytes.subarray(0, 2).toString()).toBe("PK");
    if (format === "svg") {
      expect(bytes.toString()).toContain("Exact figures by facility type");
      expect(bytes.toString()).toContain("4 (40%)");
    }
  }
});
it("rejects malformed search and unverified partitions instead of exporting partial tables", async () => {
  for (const query of [
    "view=table&q=a&q=b",
    "view=mix&q=hosp",
    "view=table&q=" + "x".repeat(201),
    "view=table&order=bad",
  ]) {
    expect((await GET(new Request(url + "format=csv&" + query))).status).toBe(400);
  }
  for (const query of ["q=Unmatched", "type=Invented"]) {
    expect((await GET(new Request(url + "format=csv&view=table&" + query))).status).toBe(503);
  }
  for (const rows of [
    [types[0]],
    [types[0], types[0]],
    [{ ...types[0], nGovernment: 100 }, types[1]],
  ]) {
    mock.types.mockResolvedValue(rows);
    expect((await GET(new Request(url + "format=csv&view=table"))).status).toBe(503);
  }
});
