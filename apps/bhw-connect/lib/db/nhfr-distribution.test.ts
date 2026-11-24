import { beforeEach, expect, it, vi } from "vitest";
import { getNhfrDistribution, readDistributionCells } from "./nhfr-distribution";
const mock = vi.hoisted(() => ({
  counts: vi.fn(),
  children: vi.fn(),
  types: vi.fn(),
  roster: vi.fn(),
  range: vi.fn(),
}));
vi.mock("./geo", () => ({
  getGeoByCode: async () => ({ geoName: "Synthetic", geoLevel: "national" }),
}));
vi.mock("./dataset", () => ({
  DATASET_SLUGS: { nhfr: "nhfr-2026-09" },
  getDatasetIdBySlug: async () => 11,
}));
vi.mock("./nhfr", () => ({
  getNhfrCounts: mock.counts,
  getNhfrChildren: mock.children,
  getNhfrTypes: mock.types,
  getNhfrChildGeos: mock.roster,
}));
vi.mock("./supabase", () => ({
  createSupabaseServerClient: () => {
    const q = { select: () => q, eq: () => q, in: () => q, order: () => q, range: mock.range };
    return { from: () => q };
  },
}));
beforeEach(() => {
  vi.clearAllMocks();
  mock.counts.mockResolvedValue({ nFacilities: 3, nGovernment: 2, nPrivate: 1 });
  mock.types.mockResolvedValue([
    { facilityType: "Hospital", nFacilities: 3, nGovernment: 2, nPrivate: 1 },
  ]);
  mock.children.mockResolvedValue([
    {
      geoCode: "01",
      geoLevel: "region",
      geoName: "A",
      nFacilities: 3,
      nGovernment: 2,
      nPrivate: 1,
    },
    {
      geoCode: "02",
      geoLevel: "region",
      geoName: "B",
      nFacilities: 0,
      nGovernment: 0,
      nPrivate: 0,
    },
  ]);
  mock.roster.mockResolvedValue([
    { geoCode: "01", geoLevel: "region" },
    { geoCode: "02", geoLevel: "region" },
  ]);
  mock.range.mockResolvedValue({
    error: null,
    data: [
      { geo_code: "01", facility_type: "Hospital", n_facilities: 3, n_government: 2, n_private: 1 },
    ],
  });
});
it("uses a complete verified roster and preserves zero areas", async () => {
  expect((await getNhfrDistribution("PH", "national"))?.areas).toHaveLength(2);
  mock.roster.mockResolvedValue([{ geoCode: "01", geoLevel: "region" }]);
  expect(await getNhfrDistribution("PH", "national")).toBeNull();
});
it("does not treat failed, truncated or inconsistent cross-tabs as zero", async () => {
  mock.range.mockResolvedValue({ error: { message: "failed" }, data: null });
  expect(await getNhfrDistribution("PH", "national")).toBeNull();
  mock.range.mockResolvedValue({ error: null, data: [] });
  expect(await getNhfrDistribution("PH", "national")).toBeNull();
});
it("reads the page after an exact thousand-row page and rejects a failed continuation", async () => {
  const codes = Array.from({ length: 25 }, (_, i) => String(i));
  const page = Array.from({ length: 1000 }, (_, i) => ({
    geo_code: codes[i % 25],
    facility_type: `Type ${Math.floor(i / 25)}`,
    n_facilities: 1,
    n_government: 1,
    n_private: 0,
  }));
  mock.range
    .mockResolvedValueOnce({ error: null, data: page })
    .mockResolvedValueOnce({ error: null, data: [] });
  expect(await readDistributionCells(codes, "region", 11, 40)).toHaveLength(1000);
  expect(mock.range).toHaveBeenLastCalledWith(1000, 1999);
  mock.range
    .mockResolvedValueOnce({ error: null, data: page })
    .mockResolvedValueOnce({ error: {}, data: null });
  expect(await readDistributionCells(codes, "region", 11, 40)).toBeNull();
});
