import { expect, it } from "vitest";
import { catalogJourneyHref } from "./journey";
import { parseCatalogParams } from "./navigation";
import { restoredBhwMapHref } from "@/lib/maps/catalog-return";

const raw =
  "dataset=bhw-2025&indicator=bhw.active-years&level=region&code=07&selected=07306&valueMin=12.34";
it("retains independent catalog scope and map context through BHW and all-dataset views", () => {
  for (const dataset of ["bhw", null] as const) {
    const selection = { mode: "psgc", level: "region", code: "01" } as const;
    const query = new URLSearchParams(catalogJourneyHref(selection, dataset, raw).split("?")[1]);
    expect(parseCatalogParams(Object.fromEntries(query))).toEqual({ selection, dataset });
    expect(restoredBhwMapHref(query.get("mapState")!)).toBe(restoredBhwMapHref(raw));
  }
});
it("drops invalid snapshots and leaves unrelated dataset links unchanged", () => {
  for (const raw of [
    undefined,
    "https://example.com",
    ["dataset=bhw-2025", "dataset=bhw-2025"],
    "dataset=bhw-profiling-status-2026",
  ]) {
    expect(catalogJourneyHref({ mode: "all" }, "bhw", raw)).toBe("/catalog?mode=all&dataset=bhw");
  }
  expect(catalogJourneyHref({ mode: "all" }, "population", raw)).toBe(
    "/catalog?mode=all&dataset=population",
  );
});
