import { beforeEach, expect, it, vi } from "vitest";
import { parse } from "csv-parse/sync";
import { areaFixture, filteredAreaPresence } from "@/lib/facilities/area-figures.fixtures";
import { GET } from "./route";
const mock = vi.hoisted(() => ({ input: vi.fn(), presence: vi.fn() }));
vi.mock("@/lib/db/nhfr-distribution", () => ({ getNhfrDistribution: mock.input }));
vi.mock("@/lib/db/nhfr-presence", () => ({ getNhfrPresence: mock.presence }));
const url = "http://localhost/api/export/facilities/areas?mode=psgc&location=national:PH&";
beforeEach(() => {
  vi.clearAllMocks();
  mock.input.mockResolvedValue(areaFixture().input);
  mock.presence.mockImplementation(async (_code, _level, types: string[]) =>
    filteredAreaPresence(types),
  );
});
it("exports selected union presence with raw, separate denominators and search metadata", async () => {
  const response = await GET(
    new Request(url + "format=csv&area=01&area=02&type=Hospital&q=Area+01&order=presence-lowest"),
  );
  expect(response.status).toBe(200);
  const rows = parse(await response.text(), { bom: true, columns: true }) as Record<
    string,
    string
  >[];
  expect(rows).toHaveLength(1);
  expect(rows[0].Facilities).toBe("1");
  expect(Number(rows[0]["Share of parent (%)"])).toBe(100 / 153);
  expect(rows[0]["Parent total for selected types"]).toBe("153");
  expect(rows[0]["Barangays with a recorded facility"]).toBe("1");
  expect(rows[0]["All barangays"]).toBe("10");
  expect(rows[0]["Share of barangays (%)"]).toBe("10");
  expect(rows[0]["Exact-area search"]).toBe("Area 01");
});
it("generates all six real table file formats for the same scope", async () => {
  for (const format of ["csv", "xlsx", "svg", "png", "pdf", "pptx"]) {
    const response = await GET(
      new Request(url + `format=${format}&area=01&type=Hospital&type=Station&ownership=1&notes=1`),
    );
    expect(response.status).toBe(200);
    const bytes = Buffer.from(await response.arrayBuffer());
    expect(bytes.length).toBeGreaterThan(100);
    if (format === "pdf") expect(bytes.subarray(0, 4).toString()).toBe("%PDF");
    if (format === "png") expect([...bytes.subarray(0, 4)]).toEqual([137, 80, 78, 71]);
    if (["xlsx", "pptx"].includes(format)) expect(bytes.subarray(0, 2).toString()).toBe("PK");
  }
});
it("rejects unsupported, malformed, unmatched or unverified combined figures", async () => {
  for (const query of [
    "ownership=bad",
    "q=x&q=y",
    "q=" + "x".repeat(201),
    "view=chart",
    "order=bad",
  ]) {
    expect((await GET(new Request(url + "format=csv&" + query))).status).toBe(400);
  }
  expect(
    (await GET(new Request("http://localhost/?mode=psgc&location=citymun:0102801&format=csv")))
      .status,
  ).toBe(400);
  for (const query of ["type=Unknown", "area=99", "q=unmatched"])
    expect((await GET(new Request(url + "format=csv&" + query))).status).toBe(503);
  mock.presence.mockResolvedValue(null);
  expect((await GET(new Request(url + "format=csv"))).status).toBe(503);
  mock.presence.mockResolvedValue({ ...areaFixture().presence, rows: [] });
  expect((await GET(new Request(url + "format=csv"))).status).toBe(503);
});
it("keeps zero and unavailable shares distinct in exact data files and escapes formula-like area names", async () => {
  const { input, presence } = areaFixture();
  input.total = { count: 0, government: 0, private: 0 };
  input.areas = input.areas.map((a) => ({ ...a, count: 0, government: 0, private: 0 }));
  input.areas[0].name = "=Area 01";
  input.types = [];
  input.cells = [];
  presence.rows = presence.rows.map((a) => ({ ...a, present: 0 }));
  mock.input.mockResolvedValue(input);
  mock.presence.mockResolvedValue(presence);
  const response = await GET(new Request(url + "format=csv"));
  expect(response.status).toBe(200);
  const rows = parse(await response.text(), { bom: true, columns: true }) as Record<
    string,
    string
  >[];
  expect(rows[0].Area).toBe("'=Area 01");
  expect(rows[0]["Share of parent (%)"]).toBe("");
  expect(rows[0]["Share status"]).toBe("unavailable");
  expect(rows[0]["Share of barangays (%)"]).toBe("0");
  expect(rows[0]["Barangay share status"]).toBe("available");
  expect(rows.at(-1)?.["Share of barangays (%)"]).toBe("");
  expect(rows.at(-1)?.["Barangay share status"]).toBe("unavailable");
});
