import { beforeEach, expect, it, vi } from "vitest";
import { getNhfrPresence } from "./nhfr-presence";
const mock = vi.hoisted(() => ({
  counts: vi.fn(),
  children: vi.fn(),
  rpc: vi.fn(),
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
  getNhfrChildGeos: mock.roster,
}));
vi.mock("./supabase", () => ({ createSupabaseServerClient: () => ({ rpc: mock.rpc }) }));
const children = [
  {
    geoCode: "01",
    geoLevel: "region",
    geoName: "A",
    nFacilities: 3,
    nBarangays: 4,
    nBarangaysWithFacility: 2,
  },
  {
    geoCode: "02",
    geoLevel: "region",
    geoName: "B",
    nFacilities: 0,
    nBarangays: 1,
    nBarangaysWithFacility: 0,
  },
];
const rows = [
  { code: "01", total: 3, allPresent: 2, present: 2, barangays: 4 },
  { code: "02", total: 0, allPresent: 0, present: 0, barangays: 1 },
];
beforeEach(() => {
  vi.clearAllMocks();
  mock.counts.mockResolvedValue({ nFacilities: 3, nBarangays: 5, nBarangaysWithFacility: 2 });
  mock.children.mockResolvedValue(children);
  mock.roster.mockResolvedValue(children);
  mock.rpc.mockResolvedValue({ data: { types: ["Hospital", "Station"], rows }, error: null });
});
it("reconciles every unfiltered child margin before accepting filtered union counts", async () => {
  expect((await getNhfrPresence("PH", "national"))?.rows[0]).toMatchObject({
    present: 2,
    barangays: 4,
  });
  mock.rpc.mockResolvedValue({
    data: { types: ["Hospital", "Station"], rows: [{ ...rows[0], present: 1 }, rows[1]] },
    error: null,
  });
  expect((await getNhfrPresence("PH", "national", ["Hospital"]))?.rows[0].present).toBe(1);
  expect(await getNhfrPresence("PH", "national", ["Unknown"])).toBeNull();
  expect(await getNhfrPresence("PH", "national")).toBeNull();
});
it("rejects partial rosters, failed reads and inconsistent baseline and selected counts", async () => {
  mock.roster.mockResolvedValue(children.slice(0, 1));
  expect(await getNhfrPresence("PH", "national")).toBeNull();
  mock.roster.mockResolvedValue(children);
  for (const patch of [{ total: 2 }, { allPresent: 1 }, { barangays: 5 }, { present: 3 }]) {
    mock.rpc.mockResolvedValue({
      data: { types: ["Hospital"], rows: [{ ...rows[0], ...patch }, rows[1]] },
      error: null,
    });
    expect(await getNhfrPresence("PH", "national", ["Hospital"])).toBeNull();
  }
  mock.rpc.mockResolvedValue({ data: null, error: { message: "failure" } });
  expect(await getNhfrPresence("PH", "national")).toBeNull();
  mock.rpc.mockResolvedValue({ data: { types: [], rows: [rows[0], rows[0]] }, error: null });
  expect(await getNhfrPresence("PH", "national")).toBeNull();
});
