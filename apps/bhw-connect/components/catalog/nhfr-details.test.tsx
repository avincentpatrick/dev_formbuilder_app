import { beforeEach, expect, it, vi } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import type { CatalogDataset } from "@/lib/catalog/types";
const db = vi.hoisted(() => ({
  counts: vi.fn(),
  types: vi.fn(),
  children: vi.fn(),
  facilities: vi.fn(),
}));
vi.mock("@/lib/db/nhfr", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/db/nhfr")>()),
  getNhfrCounts: db.counts,
  getNhfrTypes: db.types,
  getNhfrChildren: db.children,
  getNhfrFacilities: db.facilities,
}));
vi.mock("@/lib/db/geo", () => ({
  getChildGeos: vi.fn().mockResolvedValue([]),
  getGeoAncestors: vi.fn().mockResolvedValue({ province: null }),
}));
vi.mock("@/lib/geo/barangay-centroids", () => ({ getProvinceBarangayCentroids: vi.fn() }));
import { NhfrDetails } from "./nhfr-details";
const dataset: CatalogDataset = {
  id: "nhfr",
  title: "Health facilities",
  category: "Health facilities",
  state: "available",
  availableIndicators: 1,
  totalIndicators: 9,
  supportedLevels: ["national", "citymun"],
  detailHref: null,
  sources: [],
  indicators: [
    {
      id: "nhfr.facilities",
      label: "Registered facilities",
      unit: "facilities",
      state: "available",
      reason: null,
      sourceSlug: "nhfr-2026-09",
      values: [{ value: 3 }],
      reportedAreaRecords: 1,
    },
  ],
};
beforeEach(() => {
  vi.clearAllMocks();
  db.counts.mockResolvedValue({ nFacilities: 3 });
  db.types.mockResolvedValue(null);
  db.children.mockResolvedValue([]);
  db.facilities.mockResolvedValue(null);
});
it("does not substitute national or municipal details for availability, barangay or district scope", async () => {
  for (const selection of [
    { mode: "all" } as const,
    { mode: "psgc", level: "barangay", code: "0102801001" } as const,
    { mode: "district", districtCode: "test" } as const,
  ]) {
    const html = renderToStaticMarkup(
      await NhfrDetails({ dataset, selection, locationName: "Synthetic", query: "" }),
    );
    expect(html).not.toContain('data-testid="health-facilities-details"');
  }
  expect(db.counts).not.toHaveBeenCalled();
});
it("withholds mismatched details and keeps failed lists distinct from zero", async () => {
  const props = {
    dataset,
    selection: { mode: "psgc", level: "citymun", code: "0102801" } as const,
    locationName: "Synthetic",
    query: "",
  };
  db.counts.mockResolvedValueOnce({ nFacilities: 4 });
  expect(renderToStaticMarkup(await NhfrDetails(props))).toContain("could not be verified");
  const html = renderToStaticMarkup(await NhfrDetails(props));
  expect(html).toContain("not a reported zero");
  expect(html).toContain("map is unavailable");
  expect(html).not.toContain("No health facilities are on");
});
it("keeps geographic drill-down inside the same catalog dataset", async () => {
  db.children.mockResolvedValue([
    {
      geoCode: "01",
      geoLevel: "region",
      geoName: "Synthetic region",
      nFacilities: 3,
      nBarangays: 2,
      nBarangaysWithFacility: 1,
      coveragePct: 50,
    },
  ]);
  const html = renderToStaticMarkup(
    await NhfrDetails({
      dataset,
      selection: { mode: "psgc", level: "national", code: "PH" },
      locationName: "Synthetic",
      query: "",
    }),
  );
  expect(html).toContain("/catalog?mode=psgc&amp;location=region%3A01&amp;dataset=nhfr");
  expect(html).not.toContain("/facilities/region/01");
});
