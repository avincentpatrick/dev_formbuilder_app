import { beforeEach, expect, it, vi } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import Page from "./page";
import { parseHTML } from "linkedom";
import { restoredBhwMapHref } from "@/lib/maps/catalog-return";
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

it("keeps map return separate from measure scope and retains it on onward comparison and overview", async () => {
  vi.mocked(getPublicDataset).mockResolvedValue(dataset("suppressed"));
  const mapState =
    "dataset=bhw-2025&indicator=bhw.active-years&level=region&code=07&selected=07306&valueMin=12.34";
  const html = renderToStaticMarkup(
    await Page({
      searchParams: Promise.resolve({
        indicator: "bhw.demographics.education",
        location: "region:01",
        mapState,
      }),
    }),
  );
  const { document } = parseHTML(html);
  expect(html).not.toContain("PRIVATE_SENTINEL");
  expect(html).toContain("map filters and color groups do not apply");
  const links = [...document.querySelectorAll("a")];
  expect(
    links.find((a) => a.textContent === "Return to previous BHW map")?.getAttribute("href"),
  ).toBe(restoredBhwMapHref(mapState));
  for (const prefix of ["/bhw?", "/compare?"]) {
    const href = links
      .find((a) => a.getAttribute("href")?.startsWith(prefix))!
      .getAttribute("href")!;
    expect(restoredBhwMapHref(new URLSearchParams(href.split("?")[1]).get("mapState")!)).toBe(
      restoredBhwMapHref(mapState),
    );
  }
});
it("keeps a valid map return on invalid measure selection without reading data", async () => {
  const html = renderToStaticMarkup(
    await Page({
      searchParams: Promise.resolve({ indicator: "invalid", mapState: "dataset=bhw-2025" }),
    }),
  );
  expect(html).toContain("Could not verify this selection");
  expect(html).toContain("Return to previous BHW map");
  expect(getPublicDataset).not.toHaveBeenCalled();
});
it("explains invalid map snapshots without propagating them", async () => {
  vi.mocked(getPublicDataset).mockResolvedValue(dataset("unavailable"));
  for (const mapState of [
    "https://example.com",
    ["dataset=bhw-2025", "dataset=bhw-2025"],
    "dataset=bhw-profiling-status-2026",
  ]) {
    const html = renderToStaticMarkup(
      await Page({
        searchParams: Promise.resolve({
          indicator: "bhw.demographics.education",
          location: "region:01",
          mapState,
        }),
      }),
    );
    expect(html).toContain("previous map settings are invalid");
    expect(html).not.toContain("Return to previous BHW map");
    expect(html).not.toContain("mapState=");
  }
});
