import { beforeEach, expect, it, vi } from "vitest";
import { readNhfrHeadline } from "./nhfr-headline";
const mocks = vi.hoisted(() => ({
  rows: vi.fn(),
  rpc: vi.fn(),
  source: vi.fn(),
  geo: vi.fn(),
  loaded: 1,
}));
vi.mock("./supabase", () => ({
  createSupabaseServerClient: () => ({
    rpc: mocks.rpc,
    from: () => ({ select: () => ({ eq: async () => ({ error: null, count: mocks.loaded }) }) }),
  }),
}));
vi.mock("./dataset", () => ({
  DATASET_SLUGS: { nhfr: "nhfr-2026-09" },
  readDatasetBySlug: mocks.source,
}));
vi.mock("./geo", () => ({ getGeoByCode: mocks.geo }));
vi.mock("@/lib/catalog/reader", () => ({ createCatalogReader: () => ({ rows: mocks.rows }) }));
const selection = { mode: "psgc", code: "0102801", level: "citymun" } as const;
const summary = {
  total: 3,
  count: 3,
  government: 2,
  private: 1,
  beds: 4,
  present: 1,
  barangays: 2,
  unattributed: 1,
  types: ["Hospital", "Laboratory"],
};
beforeEach(() => {
  vi.clearAllMocks();
  mocks.loaded = 1;
  mocks.source.mockResolvedValue({ state: "available", value: { datasetId: 11 } });
  mocks.geo.mockResolvedValue({ geoName: "Synthetic", geoLevel: "citymun" });
  mocks.rows.mockResolvedValue({
    state: "available",
    rows: [
      {
        n_facilities: 3,
        n_government: 2,
        n_private: 1,
        total_bed_capacity: 4,
        n_barangays_with_facility: 1,
        n_barangays: 2,
      },
    ],
  });
  mocks.rpc.mockResolvedValue({ error: null, data: summary });
});
it("returns only summaries reconciled with accepted source aggregates", async () => {
  expect(await readNhfrHeadline(selection, [])).toMatchObject({
    ...summary,
    locationName: "Synthetic",
  });
  mocks.rpc.mockResolvedValue({ error: null, data: { ...summary, beds: 10 } });
  expect(await readNhfrHeadline(selection, [])).toBeNull();
});
it("rejects partial or unavailable leaf observations at the API/export boundary", async () => {
  mocks.rows.mockResolvedValue({
    state: "available",
    rows: [],
    note: { partial: true, reason: "Unassigned facilities" },
  });
  expect(await readNhfrHeadline(selection, [])).toBeNull();
  expect(mocks.rpc).not.toHaveBeenCalled();
  mocks.rows.mockResolvedValue({ state: "failed" });
  expect(await readNhfrHeadline(selection, [])).toBeNull();
});
it("allows a known registry type with verified zero local facilities", async () => {
  mocks.rpc.mockResolvedValue({
    error: null,
    data: { ...summary, count: 0, government: 0, private: 0, beds: 0, present: 0, unattributed: 0 },
  });
  expect(await readNhfrHeadline(selection, ["Laboratory"])).toMatchObject({
    count: 0,
    total: 3,
    barangays: 2,
  });
  expect(await readNhfrHeadline(selection, ["Invented category"])).toBeNull();
});
it("refuses filtered denominators or totals that differ from the accepted scope", async () => {
  mocks.rpc.mockResolvedValue({ error: null, data: { ...summary, total: 4 } });
  expect(await readNhfrHeadline(selection, ["Hospital"])).toBeNull();
  mocks.rpc.mockResolvedValue({ error: null, data: { ...summary, barangays: 3 } });
  expect(await readNhfrHeadline(selection, ["Hospital"])).toBeNull();
});
it("does not turn missing source data or a wrong geographic level into zero", async () => {
  mocks.loaded = 0;
  expect(await readNhfrHeadline(selection, [])).toBeNull();
  mocks.loaded = 1;
  mocks.geo.mockResolvedValue({ geoName: "Synthetic", geoLevel: "barangay" });
  expect(await readNhfrHeadline(selection, [])).toBeNull();
  mocks.source.mockResolvedValue({ state: "unavailable" });
  expect(await readNhfrHeadline(selection, [])).toBeNull();
});
