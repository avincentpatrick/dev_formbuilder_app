import { expect, it, vi } from "vitest";
import { GET } from "./route";
import { searchGeo } from "@/lib/db/search";
vi.mock("@/lib/db/search", () => ({ searchGeo: vi.fn() }));
it("returns geography metadata without profile counts", async () => {
  vi.mocked(searchGeo).mockResolvedValue([
    { geoCode: "01", geoLevel: "region", geoName: "Example", parentChain: {}, nTotal: 987654 },
  ]);
  const response = await GET(new Request("https://example.test/api/bhw/places?q=Example"));
  expect(response.status).toBe(200);
  expect(await response.text()).not.toContain("987654");
});
it("separates failed reads from empty results and rejects invalid input", async () => {
  vi.mocked(searchGeo).mockRejectedValueOnce(new Error("internal detail"));
  const failed = await GET(new Request("https://example.test/api/bhw/places?q=Example"));
  expect(failed.status).toBe(503);
  expect(await failed.text()).not.toContain("internal detail");
  vi.mocked(searchGeo).mockResolvedValue([]);
  const empty = await GET(new Request("https://example.test/api/bhw/places?q=Unknown"));
  expect(empty.status).toBe(200);
  expect(await empty.json()).toEqual({ results: [] });
  expect((await GET(new Request("https://example.test/api/bhw/places?q="))).status).toBe(400);
});
