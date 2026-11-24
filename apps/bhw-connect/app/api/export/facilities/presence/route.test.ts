import { beforeEach, expect, it, vi } from "vitest";
import { parse } from "csv-parse/sync";
import type { PresenceInput } from "@/lib/facilities/presence";
import { GET } from "./route";
const mock = vi.hoisted(() => ({ read: vi.fn() }));
vi.mock("@/lib/db/nhfr-presence", () => ({ getNhfrPresence: mock.read }));
const input: PresenceInput = {
  code: "PH",
  name: "Synthetic",
  level: "national",
  types: ["Hospital", "Station"],
  selectedTypes: [],
  rows: [
    { code: "01", name: "=Synthetic", level: "region", present: 1, barangays: 3 },
    { code: "02", name: "B", level: "region", present: 0, barangays: 1 },
  ],
};
const url = "http://localhost/api/export/facilities/presence?mode=psgc&location=national:PH&";
beforeEach(() => {
  vi.clearAllMocks();
  mock.read.mockImplementation(async (_code, _level, types: string[]) =>
    types.some((t) => !input.types.includes(t)) ? null : { ...input, selectedTypes: types },
  );
});
it("keeps per-area denominators and raw precision with selections and formula-safe CSV", async () => {
  const response = await GET(
    new Request(url + "format=csv&area=01&type=Hospital&type=Station&order=highest"),
  );
  expect(response.status).toBe(200);
  const rows = parse(await response.text(), { bom: true, columns: true }) as Record<
    string,
    string
  >[];
  expect(rows).toHaveLength(1);
  expect(rows[0].Area).toBe("'=Synthetic");
  expect(rows[0]["All barangays"]).toBe("3");
  expect(Number(rows[0]["Share (%)"])).toBe(100 / 3);
  expect(rows[0]["Barangays with no facility recorded"]).toBe("2");
  expect(rows[0]["Facility types"]).toBe("Hospital; Station");
  expect(mock.read).toHaveBeenCalledWith("PH", "national", ["Hospital", "Station"]);
});
it("generates all six real formats from the same verified summary", async () => {
  for (const format of ["csv", "xlsx", "svg", "png", "pdf", "pptx"]) {
    const response = await GET(new Request(url + `format=${format}&area=01&type=Hospital&notes=1`));
    expect(response.status).toBe(200);
    const bytes = Buffer.from(await response.arrayBuffer());
    expect(bytes.length).toBeGreaterThan(100);
    if (format === "pdf") expect(bytes.subarray(0, 4).toString()).toBe("%PDF");
    if (format === "png") expect([...bytes.subarray(0, 4)]).toEqual([137, 80, 78, 71]);
    if (["xlsx", "pptx"].includes(format)) expect(bytes.subarray(0, 2).toString()).toBe("PK");
    expect(response.headers.get("content-disposition")).toContain(`.${format}"`);
  }
});
it("rejects unsupported or malformed requests and unknown or incomplete data", async () => {
  for (const query of [
    "mode=all&format=csv",
    "mode=psgc&location=citymun:0102801&format=csv",
    "mode=psgc&location=province:01028&format=bad",
    "mode=psgc&location=national:PH&mode=psgc&format=csv",
  ]) {
    expect((await GET(new Request("http://localhost/?" + query))).status).toBe(400);
  }
  for (const query of ["order=bad", "area=bad", "type="])
    expect((await GET(new Request(url + "format=csv&" + query))).status).toBe(400);
  for (const query of ["type=Unknown", "area=99"])
    expect((await GET(new Request(url + "format=csv&" + query))).status).toBe(503);
  mock.read.mockResolvedValue(null);
  expect((await GET(new Request(url + "format=csv"))).status).toBe(503);
});
it("keeps 0% for a positive denominator and blanks the share for zero barangays", async () => {
  mock.read.mockResolvedValue({
    ...input,
    rows: [
      { ...input.rows[0], present: 0 },
      { ...input.rows[1], barangays: 0 },
    ],
  });
  const response = await GET(new Request(url + "format=csv"));
  const rows = parse(await response.text(), { bom: true, columns: true }) as Record<
    string,
    string
  >[];
  expect(rows[0]["Share (%)"]).toBe("0");
  expect(rows[0]["Share status"]).toBe("available");
  expect(rows[1]["Share (%)"]).toBe("");
  expect(rows[1]["Share status"]).toBe("unavailable");
});
