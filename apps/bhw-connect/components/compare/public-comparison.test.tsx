import { beforeEach, expect, it, vi } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { PublicComparison } from "./public-comparison";
import { getGeoByCode } from "@/lib/db/geo";
import { getPublicDataset } from "@/lib/catalog/catalog";
import type { CatalogDataset, DataState } from "@/lib/catalog/types";

vi.mock("@/lib/db/geo", () => ({ getGeoByCode: vi.fn() }));
vi.mock("@/lib/catalog/catalog", () => ({ getPublicDataset: vi.fn() }));
vi.mock("./public-controls", () => ({
  PublicCompareControls: ({ places }: { places: unknown }) => <pre>{JSON.stringify(places)}</pre>,
}));
beforeEach(() => {
  vi.mocked(getGeoByCode).mockReset();
  vi.mocked(getPublicDataset).mockReset();
  vi.mocked(getGeoByCode).mockImplementation(async (code) => ({
    geoCode: code,
    geoLevel: "region",
    geoName: `Region ${code}`,
    incomeClass: null,
  }));
});
const render = async (measure = "bhw.total", geos = "01,02") =>
  renderToStaticMarkup(await PublicComparison({ params: { measure, geos } }));
function fixture(id: string, state: DataState, value = 987654): CatalogDataset {
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
        id,
        label: "Synthetic measure",
        unit: "people",
        state,
        values: [{ value, label: state === "available" ? undefined : "PRIVATE_SENTINEL" }],
        sourceSlug: "bhw-2025",
        reason: null,
        reportedAreaRecords: 1,
      },
    ],
  };
}
it.each(["suppressed", "failed", "unavailable", "unsupported"] as DataState[])(
  "never renders %s payloads in bars or place details",
  async (state) => {
    vi.mocked(getPublicDataset).mockResolvedValue(fixture("bhw.total", state));
    const html = await render();
    expect(html).not.toContain("987654");
    expect(html).not.toContain("PRIVATE_SENTINEL");
    const calls = vi.mocked(getPublicDataset).mock.calls;
    expect(calls).toHaveLength(2);
    expect(calls[0][2]).toBe(calls[1][2]);
  },
);
it("retains zero and unequal denominators without averaging percentages", async () => {
  vi.mocked(getPublicDataset).mockImplementation(async (_, selection) => {
    const first = selection.mode === "psgc" && selection.code === "01";
    const data = fixture("bhw.profile-accreditation", "available", first ? 0 : 50);
    data.indicators[0].unit = "%";
    data.indicators[0].values[0].denominator = first ? 100 : 20;
    return data;
  });
  const html = await render("bhw.profile-accreditation");
  expect(html).toContain("0%");
  expect(html).toContain("50%");
  expect(html).toContain("Denominator: 100");
  expect(html).toContain("Denominator: 20");
  expect(html).not.toContain("25%");
});
it("does not read figures for excess, unverifiable or mixed-level places", async () => {
  const excess = await render("bhw.total", "01,02,03,04,05");
  expect(excess).toContain("at most four");
  expect(excess).toContain("Region 05");
  vi.mocked(getGeoByCode).mockResolvedValueOnce(null);
  expect(await render()).toContain("Could not verify every place");
  vi.mocked(getGeoByCode).mockResolvedValueOnce({
    geoCode: "01028",
    geoLevel: "province",
    geoName: "Example province",
    incomeClass: null,
  });
  expect(await render("bhw.total", "01028,02")).toContain("same geographic level");
  expect(getPublicDataset).not.toHaveBeenCalled();
});
it("invalid public modes cannot read data", async () => {
  expect(await render("not-a-measure")).toContain("Invalid comparison settings");
  expect(getPublicDataset).not.toHaveBeenCalled();
  expect(getGeoByCode).not.toHaveBeenCalled();
});
