import { beforeEach, expect, it, vi } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import Page from "./page";
import { getGeoByCode } from "@/lib/db/geo";
import { getPublicDataset } from "@/lib/catalog/catalog";
import type { CatalogDataset, DataState } from "@/lib/catalog/types";

vi.mock("@/lib/db/geo", () => ({ getGeoByCode: vi.fn() }));
vi.mock("@/lib/catalog/catalog", () => ({ getPublicDataset: vi.fn() }));
beforeEach(() => {
  vi.mocked(getPublicDataset).mockReset();
  vi.mocked(getGeoByCode).mockResolvedValue({
    geoCode: "01",
    geoLevel: "region",
    geoName: "Example region",
    incomeClass: null,
  });
});
function dataset(state: DataState): CatalogDataset {
  return {
    id: "bhw",
    title: "BHW",
    category: "workforce",
    state,
    availableIndicators: 0,
    totalIndicators: 1,
    supportedLevels: ["region"],
    detailHref: null,
    sources: [],
    indicators: [
      {
        id: "bhw.demographics.education",
        label: "Education",
        unit: "people",
        state,
        reason: null,
        sourceSlug: "bhw-2025",
        reportedAreaRecords: 1,
        values: [{ value: 987654, label: "PRIVATE_SENTINEL" }],
      },
    ],
  };
}
const render = async (location = "region:01") =>
  renderToStaticMarkup(
    await Page({
      searchParams: Promise.resolve({ location, indicator: "bhw.demographics.education" }),
    }),
  );
it.each(["suppressed", "failed", "unsupported", "unavailable"] as DataState[])(
  "does not serialize values for %s",
  async (state) => {
    vi.mocked(getPublicDataset).mockResolvedValue(dataset(state));
    const html = await render();
    expect(html).not.toContain("987654");
    expect(html).not.toContain("PRIVATE_SENTINEL");
    expect(getPublicDataset).toHaveBeenCalledWith("bhw", {
      mode: "psgc",
      level: "region",
      code: "01",
    });
  },
);
it("keeps published zero and refuses invalid geography without national fallback", async () => {
  const data = dataset("available");
  data.indicators[0].values = [{ value: 0 }];
  vi.mocked(getPublicDataset).mockResolvedValue(data);
  expect(await render()).toContain("0 people");
  vi.mocked(getPublicDataset).mockClear();
  expect(await render("barangay:01")).toContain("Could not verify this selection");
  expect(getPublicDataset).not.toHaveBeenCalled();
});
