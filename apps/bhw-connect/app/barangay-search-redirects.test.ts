import { beforeEach, describe, expect, it, vi } from "vitest";
const mocks = vi.hoisted(() => ({ geo: vi.fn(), ancestors: vi.fn() }));
vi.mock("@/lib/db/geo", () => ({
  getGeoByCode: mocks.geo,
  getGeoAncestors: mocks.ancestors,
}));
vi.mock("next/navigation", () => ({
  notFound: () => {
    throw new Error("NOT_FOUND");
  },
  redirect: (href: string) => {
    throw new Error(`REDIRECT:${href}`);
  },
}));
import Facilities from "./facilities/[geoLevel]/[geoCode]/page";
import Uuc from "./uuc-phc/[geoLevel]/[geoCode]/page";
import Services from "./health-services/[geoLevel]/[geoCode]/page";

beforeEach(() => {
  vi.clearAllMocks();
  mocks.geo.mockResolvedValue({
    geoLevel: "barangay",
    geoCode: "test-barangay",
    geoName: "Example",
  });
  mocks.ancestors.mockResolvedValue({ citymun: { geoCode: "stored-parent" } });
});
for (const [section, Page] of [
  ["facilities", Facilities],
  ["uuc-phc", Uuc],
  ["health-services", Services],
] as const) {
  describe(section, () => {
    it("opens the stored municipality rather than deriving a parent from the code", async () => {
      await expect(
        Page({ params: Promise.resolve({ geoLevel: "barangay", geoCode: "test-barangay" }) }),
      ).rejects.toThrow(`REDIRECT:/${section}/citymun/stored-parent`);
    });
    it("does not redirect an unknown barangay", async () => {
      mocks.geo.mockResolvedValue(null);
      await expect(
        Page({ params: Promise.resolve({ geoLevel: "barangay", geoCode: "unknown" }) }),
      ).rejects.toThrow("NOT_FOUND");
    });
    it("does not invent a municipality when ancestry is missing", async () => {
      mocks.ancestors.mockResolvedValue({ citymun: null });
      await expect(
        Page({ params: Promise.resolve({ geoLevel: "barangay", geoCode: "test-barangay" }) }),
      ).rejects.toThrow("NOT_FOUND");
    });
  });
}
