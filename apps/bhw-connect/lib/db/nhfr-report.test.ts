import { beforeEach, expect, it, vi } from "vitest";
import { readNhfrReport } from "./nhfr-report";
import { areaFixture } from "@/lib/facilities/area-figures.fixtures";
const m = vi.hoisted(() => ({
  summary: vi.fn(),
  types: vi.fn(),
  distribution: vi.fn(),
  presence: vi.fn(),
}));
vi.mock("./nhfr-headline", () => ({ readNhfrHeadline: m.summary }));
vi.mock("./nhfr", () => ({ getNhfrTypes: m.types }));
vi.mock("./nhfr-distribution", () => ({ getNhfrDistribution: m.distribution }));
vi.mock("./nhfr-presence", () => ({ getNhfrPresence: m.presence }));
beforeEach(() => {
  vi.clearAllMocks();
  const { input, presence } = areaFixture();
  m.summary.mockResolvedValue({
    locationName: input.name,
    total: input.total.count,
    count: input.total.count,
    government: input.total.government,
    private: input.total.private,
    beds: 100,
    present: presence.rows.reduce((s, a) => s + a.present, 0),
    barangays: presence.rows.reduce((s, a) => s + a.barangays, 0),
    unattributed: 0,
    types: presence.types,
  });
  m.types.mockResolvedValue(
    input.types.map((t) => ({
      facilityType: t.type,
      nFacilities: t.count,
      nGovernment: t.government,
      nPrivate: t.private,
    })),
  );
  m.distribution.mockResolvedValue(input);
  m.presence.mockResolvedValue(presence);
});
it("verifies the complete report against independent headline, type and area margins", async () => {
  expect((await readNhfrReport("PH", "national"))?.areas?.rows).toHaveLength(18);
  expect(m.summary).toHaveBeenCalledWith({ mode: "psgc", level: "national", code: "PH" }, []);
  expect(m.presence).toHaveBeenCalledWith("PH", "national");
  m.presence.mockResolvedValue(null);
  expect(await readNhfrReport("PH", "national")).toBeNull();
});
it("does not substitute a partial report and omits directories at city level", async () => {
  m.types.mockResolvedValue([]);
  expect(await readNhfrReport("PH", "national")).toBeNull();
  m.types.mockResolvedValue([
    { facilityType: "Invented", nFacilities: 323, nGovernment: 170, nPrivate: 153 },
  ]);
  expect(await readNhfrReport("PH", "national")).toBeNull();
  m.types.mockRejectedValue(new Error("private"));
  expect(await readNhfrReport("PH", "national")).toBeNull();
});
