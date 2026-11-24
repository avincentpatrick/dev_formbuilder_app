import { beforeEach, expect, it, vi } from "vitest";
import { parse } from "csv-parse/sync";
import type { DistributionInput } from "@/lib/facilities/distribution";
import { GET } from "./route";
const mock = vi.hoisted(() => ({ read: vi.fn() }));
vi.mock("@/lib/db/nhfr-distribution", () => ({ getNhfrDistribution: mock.read }));
const input: DistributionInput = {
  code: "PH",
  name: "Synthetic",
  level: "national",
  total: { count: 10, government: 7, private: 3 },
  areas: [
    { code: "01", name: "A", level: "region", count: 6, government: 4, private: 2 },
    { code: "02", name: "B", level: "region", count: 4, government: 3, private: 1 },
  ],
  types: [
    { type: "Hospital", count: 7, government: 4, private: 3 },
    { type: "Clinic", count: 3, government: 3, private: 0 },
  ],
  cells: [
    { code: "01", type: "Hospital", count: 3, government: 1, private: 2 },
    { code: "01", type: "Clinic", count: 3, government: 3, private: 0 },
    { code: "02", type: "Hospital", count: 4, government: 3, private: 1 },
  ],
};
const url = "http://localhost/api/export/facilities/distribution?mode=psgc&location=national:PH&";
beforeEach(() => {
  vi.clearAllMocks();
  mock.read.mockResolvedValue(input);
});
it("exports exact filtered shares against the full matching-type parent total", async () => {
  const response = await GET(new Request(url + "format=csv&area=01&type=Hospital&order=lowest"));
  expect(response.status).toBe(200);
  const rows = parse(await response.text(), { bom: true, columns: true }) as Record<
    string,
    string
  >[];
  expect(rows).toHaveLength(1);
  expect(rows[0].Facilities).toBe("3");
  expect(Number(rows[0]["Share of parent (%)"])).toBe(300 / 7);
  expect(rows[0]["Parent total for selected types"]).toBe("7");
  expect(rows[0]["Count scale maximum"]).toBe("4");
  expect(rows[0]["Geographic code"]).toBe("01");
});
it("generates all six real formats for the same selected areas and types", async () => {
  for (const format of ["csv", "xlsx", "svg", "png", "pdf", "pptx"]) {
    const response = await GET(
      new Request(url + `format=${format}&area=01&area=02&type=Hospital&type=Clinic&notes=1`),
    );
    expect(response.status).toBe(200);
    const bytes = Buffer.from(await response.arrayBuffer());
    expect(bytes.length).toBeGreaterThan(100);
    if (format === "pdf") expect(bytes.subarray(0, 4).toString()).toBe("%PDF");
    if (format === "png") expect([...bytes.subarray(0, 4)]).toEqual([137, 80, 78, 71]);
    if (["xlsx", "pptx"].includes(format)) expect(bytes.subarray(0, 2).toString()).toBe("PK");
    expect(response.headers.get("content-disposition")).toContain(`.${format}"`);
  }
});
it("rejects invalid scopes, unknown filters and incomplete data rather than borrowing or exporting zeros", async () => {
  for (const query of [
    "mode=all&format=csv",
    "mode=psgc&location=citymun:0102801&format=csv",
    "mode=psgc&location=barangay:0102801001&format=csv",
  ]) {
    expect((await GET(new Request("http://localhost/?" + query))).status).toBe(400);
  }
  expect((await GET(new Request(url + "format=csv&order=bad"))).status).toBe(400);
  expect((await GET(new Request(url + "format=csv&type=Unknown"))).status).toBe(503);
  expect((await GET(new Request(url + "format=csv&area=99"))).status).toBe(503);
  mock.read.mockResolvedValue({ ...input, cells: [] });
  expect((await GET(new Request(url + "format=csv"))).status).toBe(503);
});

it("exports a verified zero count with an unavailable share when the parent denominator is zero", async () => {
  mock.read.mockResolvedValue({
    ...input,
    total: { count: 0, government: 0, private: 0 },
    areas: input.areas.map((a) => ({ ...a, count: 0, government: 0, private: 0 })),
    types: [],
    cells: [],
  });
  const response = await GET(new Request(url + "format=csv"));
  expect(response.status).toBe(200);
  const rows = parse(await response.text(), { bom: true, columns: true }) as Record<
    string,
    string
  >[];
  expect(rows[0].Facilities).toBe("0");
  expect(rows[0]["Share of parent (%)"]).toBe("");
  expect(rows[0]["Share status"]).toBe("unavailable");
});
