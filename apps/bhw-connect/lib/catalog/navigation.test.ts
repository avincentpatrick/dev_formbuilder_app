import { describe, expect, it } from "vitest";
import { catalogHref, parseCatalogParams } from "./navigation";

describe("catalog URL state", () => {
  it("keeps All locations distinct from National", () => {
    expect(parseCatalogParams({})?.selection).toEqual({ mode: "all" });
    expect(parseCatalogParams({ mode: "psgc", location: "national:PH" })?.selection).toEqual({
      mode: "psgc",
      level: "national",
      code: "PH",
    });
  });
  it("round trips scope and detail selection for refresh and shared links", () => {
    const selection = { mode: "psgc", level: "barangay", code: "0102801001" } as const;
    const url = new URL(catalogHref(selection, "population"), "https://example.test");
    expect(parseCatalogParams(Object.fromEntries(url.searchParams))).toEqual({
      selection,
      dataset: "population",
    });
    expect(catalogHref({ mode: "all" })).not.toContain("location");
  });
  it.each([
    { mode: "all", location: "region:01" },
    { mode: "district", location: "citymun:0102801" },
    { mode: "psgc", location: "national:01" },
    { mode: "psgc", location: "province:PH" },

    { mode: "psgc", location: "region:<script>" },
    { mode: ["all", "psgc"] },
    { mode: "psgc", location: ["region:01", "region:02"] },
    { dataset: "profiling-status" },
  ])("rejects ambiguous or unsupported URL state: %j", (params) => {
    expect(parseCatalogParams(params)).toBeNull();
  });
});

it("round trips a district's partial-city barangay and clears geography when modes change", () => {
  const selection = {
    mode: "district",
    districtCode: "quezon-city-1st",
    citymunCode: "137404",
    barangayCode: "137404001",
  } as const;
  const url = new URL(catalogHref(selection, "nhfr"), "https://example.test");
  expect(parseCatalogParams(Object.fromEntries(url.searchParams))).toEqual({
    selection,
    dataset: "nhfr",
  });
  expect(parseCatalogParams({ mode: "district" })).toEqual({
    selection: { mode: "district", districtCode: "" },
    dataset: null,
  });
  expect(catalogHref({ mode: "all" })).not.toMatch(/district|citymun|barangay/);
  for (const params of [
    { mode: "district", district: "d1", location: "citymun:01" },
    { mode: "psgc", district: "d1", location: "region:01" },
    { mode: "district", barangay: "01001001" },
    { mode: "district", district: "d1", barangay: "01001001" },
    { mode: "district", district: ["d1", "d2"] },
  ])
    expect(parseCatalogParams(params)).toBeNull();
});
