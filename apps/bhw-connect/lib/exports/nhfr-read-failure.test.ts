import { beforeEach, expect, it, vi } from "vitest";
import { buildNhfrFigure } from "./nhfr-figure";
const mock = vi.hoisted(() => ({ counts: vi.fn(), types: vi.fn(), facilities: vi.fn() }));
vi.mock("@/lib/db/geo", () => ({ getGeoByCode: async () => ({ geoName: "Synthetic" }) }));
vi.mock("@/lib/db/nhfr", async (original) => ({
  ...(await original<typeof import("@/lib/db/nhfr")>()),
  getNhfrCounts: mock.counts,
  getNhfrTypes: mock.types,
  getNhfrFacilities: mock.facilities,
  getNhfrChildren: async () => [],
}));
beforeEach(() => {
  mock.counts.mockResolvedValue({ nFacilities: 2 });
  mock.types.mockResolvedValue([{ nFacilities: 2 }]);
  mock.facilities.mockResolvedValue([{}, {}]);
});
it("refuses a download when required type or facility reads failed", async () => {
  mock.types.mockResolvedValue(null);
  expect(await buildNhfrFigure("synthetic", "citymun")).toBeNull();
  mock.types.mockResolvedValue([{ nFacilities: 2 }]);
  mock.facilities.mockResolvedValue(null);
  expect(await buildNhfrFigure("synthetic", "citymun")).toBeNull();
});
it("refuses incomplete details rather than exporting a misleading empty or partial figure", async () => {
  mock.facilities.mockResolvedValue([]);
  expect(await buildNhfrFigure("synthetic", "citymun")).toBeNull();
  mock.facilities.mockResolvedValue([{}, {}]);
  mock.types.mockResolvedValue([{ nFacilities: 1 }]);
  expect(await buildNhfrFigure("synthetic", "citymun")).toBeNull();
});
