import { beforeEach, expect, it, vi } from "vitest";
import { reportFixture } from "@/lib/facilities/report.fixtures";
import { GET } from "./route";
const m = vi.hoisted(() => ({ read: vi.fn() }));
vi.mock("@/lib/db/nhfr-report", () => ({ readNhfrReport: m.read }));
beforeEach(() => {
  vi.clearAllMocks();
  m.read.mockResolvedValue(reportFixture());
});
it("returns only readiness metadata for the verified full location and rejects filters", async () => {
  const response = await GET(new Request("http://localhost/?geoLevel=national&geoCode=PH"));
  expect(response.status).toBe(200);
  expect(await response.json()).toEqual({
    code: "PH",
    level: "national",
    name: "Synthetic parent",
    facilities: 110,
    types: 25,
    areas: 55,
  });
  expect(m.read).toHaveBeenCalledWith("PH", "national");
  expect(
    (await GET(new Request("http://localhost/?geoLevel=national&geoCode=PH&type=Hospital"))).status,
  ).toBe(400);
  m.read.mockResolvedValue(null);
  expect((await GET(new Request("http://localhost/?geoLevel=national&geoCode=PH"))).status).toBe(
    503,
  );
});
