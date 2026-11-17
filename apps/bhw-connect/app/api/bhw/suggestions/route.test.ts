import { beforeEach, expect, it, vi } from "vitest";
import { GET } from "./route";
import { searchGeo } from "@/lib/db/search";
vi.mock("@/lib/db/search", () => ({ searchGeo: vi.fn() }));
beforeEach(() => {
  vi.mocked(searchGeo).mockReset();
  vi.mocked(searchGeo).mockResolvedValue([]);
});
it("suggests places with parent labels, no counts, and validated map context", async () => {
  vi.mocked(searchGeo).mockResolvedValue([
    {
      geoCode: "0102801",
      geoLevel: "citymun",
      geoName: "Example",
      parentChain: { province: "Parent province" },
      nTotal: 987654,
    },
  ]);
  const response = await GET(
    new Request(
      "https://example.test/api/bhw/suggestions?" +
        new URLSearchParams({
          q: "Exam",
          mapState: "dataset=bhw-stepzero-2026&level=region&code=01",
        }),
    ),
  );
  const data = await response.json();
  expect(data.suggestions[0].detail).toContain("Parent province");
  expect(data.suggestions[0].href).toContain("mapState=");
  expect(data.suggestions[0].href).toMatch(/^\/bhw\?location=citymun%3A0102801/);
  expect(JSON.stringify(data)).not.toContain("987654");
});
it("suggests partial indicator terms and preserves explicit accreditation choices", async () => {
  const partial = await (
    await GET(new Request("https://example.test/api/bhw/suggestions?q=headc"))
  ).json();
  expect(partial.suggestions.some((s: { href: string }) => s.href.includes("bhw.total"))).toBe(
    true,
  );
  vi.mocked(searchGeo).mockResolvedValue([
    { geoCode: "01", geoLevel: "region", geoName: "Example", parentChain: {}, nTotal: null },
  ]);
  const mixed = await (
    await GET(new Request("https://example.test/api/bhw/suggestions?q=Example%20accreditation"))
  ).json();
  expect(mixed.suggestions).toHaveLength(2);
  expect(mixed.suggestions[0].href).not.toBe(mixed.suggestions[1].href);
});
it("distinguishes errors from no matches, bounds input, and strips invalid context", async () => {
  expect((await GET(new Request("https://example.test/api/bhw/suggestions?q=a"))).status).toBe(400);
  expect(searchGeo).not.toHaveBeenCalled();
  vi.mocked(searchGeo).mockRejectedValue(new Error("private connection details"));
  const response = await GET(
    new Request("https://example.test/api/bhw/suggestions?q=headc&mapState=https://evil.test"),
  );
  const data = await response.json();
  expect(data.placesFailed).toBe(true);
  expect(data.suggestions.length).toBeGreaterThan(0);
  expect(JSON.stringify(data)).not.toMatch(/evil|private connection/);
});
