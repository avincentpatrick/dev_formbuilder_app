import { expect, it, vi } from "vitest";
import { GET } from "./route";
const mock = vi.hoisted(() => ({ read: vi.fn() }));
vi.mock("@/lib/db/nhfr-exploration", () => ({ getNhfrExploration: mock.read }));
const input = {
  code: "0102801",
  name: "Synthetic town",
  expected: 1,
  expectedBarangays: 2,
  outlineUrl: null,
  facilities: [
    {
      facilityCode: "f1",
      facilityName: "=Synthetic clinic",
      facilityType: "Clinic",
      ownershipMajor: "Government",
      ownershipSub: null,
      barangayGeoCode: "b1",
      barangayName: "One",
      bedCapacity: 0,
      licensingStatus: null,
    },
  ],
  barangays: [
    { geoCode: "b1", geoName: "One" },
    { geoCode: "b2", geoName: "Empty" },
  ],
  centroids: [["b1", [120, 14]]],
};
it("generates real selected-map PNG/SVG/PDF/PPTX and complete CSV/XLSX tables", async () => {
  mock.read.mockResolvedValue(input);
  for (const format of ["png", "svg", "pdf", "pptx", "csv", "xlsx"]) {
    const response = await GET(
      new Request(
        `http://localhost/api/export/facilities/exploration?code=0102801&type=Clinic&format=${format}`,
      ),
    );
    expect(response.status).toBe(200);
    const bytes = new Uint8Array(await response.arrayBuffer());
    expect(bytes.length).toBeGreaterThan(100);
    const text = new TextDecoder().decode(bytes);
    if (format === "png") expect([...bytes.slice(0, 4)]).toEqual([137, 80, 78, 71]);
    if (format === "pdf") expect(text.startsWith("%PDF")).toBe(true);
    if (format === "pptx" || format === "xlsx") expect(text.startsWith("PK")).toBe(true);
    if (format === "csv") {
      expect(text).toContain("Empty");
      expect(text).toContain("Geometry unavailable");
      expect(text).toContain("September 2026");
    }
    if (format === "svg") expect(text).toContain("not buildings");
  }
});
it("applies selected barangay and search only to facility downloads and escapes formula cells", async () => {
  mock.read.mockResolvedValue(input);
  const response = await GET(
    new Request(
      "http://localhost/?code=0102801&format=csv&table=facilities&barangay=b1&search=clinic",
    ),
  );
  expect(await response.text()).toContain("'=Synthetic clinic");
  const empty = await GET(
    new Request("http://localhost/?code=0102801&format=csv&table=facilities&barangay=b2"),
  );
  const text = await empty.text();
  expect(text).not.toContain("Synthetic clinic");
  expect(text).toContain("September 2026");
});
it("rejects bad selections and returns unavailable on failed inventory", async () => {
  expect((await GET(new Request("http://localhost/?code=bad&format=csv"))).status).toBe(400);
  mock.read.mockResolvedValue(input);
  expect(
    (await GET(new Request("http://localhost/?code=0102801&type=Unknown&format=csv"))).status,
  ).toBe(400);
  mock.read.mockResolvedValue(null);
  expect((await GET(new Request("http://localhost/?code=0102801&format=csv"))).status).toBe(503);
});
