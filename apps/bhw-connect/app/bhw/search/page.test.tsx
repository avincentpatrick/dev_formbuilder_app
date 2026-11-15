import { beforeEach, expect, it, vi } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import Page from "./page";
import { searchGeo } from "@/lib/db/search";

vi.mock("@/lib/db/search", () => ({ searchGeo: vi.fn() }));
const search = vi.mocked(searchGeo);
beforeEach(() => search.mockReset());
const render = async (q: string) =>
  renderToStaticMarkup(await Page({ searchParams: Promise.resolve({ q }) }));

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
  expect(await render("Compare Cebu and Bohol accreditation")).toContain("not supported here yet");
  expect(search).not.toHaveBeenCalled();
});

it("offers indicator-only national analysis without a geography RPC", async () => {
  const html = await render("headcount");
  expect(html).toContain("national%3APH");
  expect(html).toContain("bhw.total");
  expect(search).not.toHaveBeenCalled();
});
