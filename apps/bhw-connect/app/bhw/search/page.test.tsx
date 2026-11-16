import { beforeEach, expect, it, vi } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import Page from "./page";
import { parseHTML } from "linkedom";
import { restoredBhwMapHref } from "@/lib/maps/catalog-return";
import { searchGeo } from "@/lib/db/search";

vi.mock("@/lib/db/search", () => ({ searchGeo: vi.fn() }));
const search = vi.mocked(searchGeo);
beforeEach(() => search.mockReset());
const render = async (q: string, mapState?: string | string[]) =>
  renderToStaticMarkup(await Page({ searchParams: Promise.resolve({ q, mapState }) }));

it("shows parent chains for repeated names, strips profile counts and offers both populations", async () => {
  search.mockResolvedValue(
    ["Alpha", "Beta"].map((province, i) => ({
      geoName: "Poblacion",
      geoLevel: "barangay",
      geoCode: `010280100${i + 1}`,
      parentChain: { citymun: "Example municipality", province },
      nTotal: 987654,
    })),
  );
  const html = await render("Poblacion accreditation");
  expect(search).toHaveBeenCalledWith("poblacion", 12, { throwOnError: true });
  expect(html).toContain("Alpha");
  expect(html).toContain("Beta");
  expect(html).toContain("bhw.declared-accreditation");
  expect(html).toContain("bhw.profile-accreditation");
  expect(html).not.toContain("987654");
  expect(html).not.toContain("/place/");
});

it("distinguishes failed, empty and unsupported searches", async () => {
  search.mockRejectedValueOnce(new Error("private database message"));
  const failed = await render("Cebu");
  expect(failed).toContain("could not be loaded");
  expect(failed).not.toContain("private database message");
  expect(failed).not.toContain("No matching places found");
  search.mockResolvedValue([]);
  expect(await render("Unknown")).toContain("No matching places found");
  search.mockClear();
  expect(await render("forecast headcount")).toContain("not supported");
  expect(search).not.toHaveBeenCalled();
});

it("offers indicator-only national analysis without a geography RPC", async () => {
  const html = await render("headcount");
  expect(html).toContain("national%3APH");
  expect(html).toContain("bhw.total");
  expect(search).not.toHaveBeenCalled();
});

const snapshot =
  "dataset=bhw-2025&indicator=bhw.active-years&level=region&code=07&selected=07306&valueMin=12.34";
it("keeps the map return through measure and place-only results without filtering matches", async () => {
  search.mockResolvedValue([
    { geoCode: "01", geoLevel: "region", geoName: "Example", parentChain: {}, nTotal: null },
  ]);
  for (const q of ["01 headcount", "01"]) {
    const { document } = parseHTML(await render(q, snapshot));
    const hidden = document.querySelector('input[name="mapState"]')!;
    expect(restoredBhwMapHref(hidden.getAttribute("value")!)).toBe(restoredBhwMapHref(snapshot));
    const result = document.querySelector("section a")!.getAttribute("href")!;
    const params = new URLSearchParams(result.split("?")[1]);
    expect(params.get("location")).toBe("region:01");
    expect(restoredBhwMapHref(params.get("mapState")!)).toBe(restoredBhwMapHref(snapshot));
  }
  expect(search).toHaveBeenCalledWith("01", 12, { throwOnError: true });
});
it("keeps the map return on failed, empty and unsupported searches", async () => {
  search.mockRejectedValueOnce(new Error("failure"));
  expect(await render("01", snapshot)).toContain("Return to previous BHW map");
  search.mockResolvedValue([]);
  for (const q of ["Unknown", "forecast headcount", ""])
    expect(await render(q, snapshot)).toContain("Return to previous BHW map");
});
it("does not propagate invalid or repeated map settings", async () => {
  for (const raw of [
    "https://example.com",
    [snapshot, snapshot],
    "dataset=bhw-profiling-status-2026",
  ]) {
    const html = await render("headcount", raw);
    expect(html).toContain("previous map settings are invalid");
    expect(html).not.toContain('name="mapState"');
    expect(html).not.toContain("mapState=");
    expect(html).not.toContain("Return to previous BHW map");
  }
});
