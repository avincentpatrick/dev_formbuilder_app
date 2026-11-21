import { expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { NhfrOverview } from "./nhfr-overview";
import { CatalogDetails } from "./catalog-results";
import { nhfrReading, NHFR_DEFINITION, NHFR_BEDS, NHFR_PRESENCE } from "@/lib/catalog/nhfr-reading";
import { measureDefinition } from "@/lib/catalog/measure-notes";
import { summarizeCatalog } from "@/lib/catalog/summary";
import type { CatalogDataset, CatalogIndicator, CatalogSelection } from "@/lib/catalog/types";
const scope: CatalogSelection = { mode: "psgc", level: "citymun", code: "0102801" };
const indicator: CatalogIndicator = {
  id: "nhfr.facilities",
  label: "Registered facilities",
  unit: "facilities",
  state: "available",
  sourceSlug: "nhfr-2026-09",
  reason: null,
  values: [{ value: 123456 }],
  reportedAreaRecords: 1,
};
const dataset: CatalogDataset = {
  id: "nhfr",
  title: "NHFR",
  category: "Health facilities",
  state: "available",
  availableIndicators: 1,
  totalIndicators: 9,
  supportedLevels: ["national", "region", "province", "citymun", "barangay"],
  detailHref: "/facilities/citymun/0102801",
  sources: [],
  indicators: [indicator],
};
function html(patch: Partial<CatalogIndicator> = {}, selection = scope) {
  return renderToStaticMarkup(
    <NhfrOverview
      dataset={{ ...dataset, indicators: [{ ...indicator, ...patch }] }}
      selection={selection}
      locationName="Synthetic place"
    />,
  );
}
it("renders accepted counts including zero, with date, unit and attribution limits", () => {
  expect(html()).toContain("123,456");
  expect(nhfrReading({ ...indicator, values: [{ value: 0 }] }, scope)?.display).toBe("0");
  expect(html()).toContain("September 2026 snapshot");
  expect(html()).toContain("not proof that no facility exists");
  expect(html()).toContain("not a count of health workers");
});
it("keeps rejected payloads out of the rendered overview", () => {
  for (const patch of [
    ...(["failed", "unavailable", "unsupported", "suppressed"] as const).map((state) => ({
      state,
    })),
    { sourceSlug: "future-snapshot" },
    { unit: "people" },
    { values: [{ value: -123456 }] },
    { values: [{ value: 123456.5 }] },
    { values: [{ value: Infinity }] },
    { values: [{ value: NaN }] },
    { values: [{ value: 123456 }, { value: 123456 }] },
  ])
    expect(html(patch)).not.toContain("123,456");
  expect(html({ state: "failed" })).toContain("Could not be loaded");
  expect(html({ state: "suppressed" })).toContain("Withheld");
  expect(html({ state: "unsupported" })).toContain("Not supported");
  expect(html({ state: "unavailable" })).toContain("does not mean zero");
  expect(html({}, { mode: "all" })).not.toContain("123,456");
  expect(html({}, { mode: "all" })).toContain("not a national facility total");
});
it("preserves partial and district attribution qualifications", () => {
  expect(
    html({
      state: "partial",
      coverage: { covered: 2, total: 3, label: "Mapped areas" },
      reason: "One area is missing.",
    }),
  ).toContain("Partial total");
  expect(
    html({ state: "partial", coverage: { covered: 2, total: 3, label: "Mapped areas" } }),
  ).toContain("2 of 3");
  expect(html({ state: "partial", reason: "One area is missing." })).toContain(
    "One area is missing.",
  );
  const district: CatalogSelection = { mode: "district", districtCode: "synthetic" };
  expect(nhfrReading(indicator, district)).toBeNull();
  expect(nhfrReading({ ...indicator, aggregation: "sum" }, district)?.display).toBe("123,456");
});
it("derives presence only from its own valid denominator, without rounding inputs", () => {
  const presence = { ...indicator, id: "nhfr.barangay-presence", unit: "barangays" };
  expect(nhfrReading({ ...presence, values: [{ value: 1, denominator: 3 }] }, scope)?.display).toBe(
    "1 of 3 (33%)",
  );
  expect(nhfrReading({ ...presence, values: [{ value: 0, denominator: 0 }] }, scope)?.display).toBe(
    "0 of 0",
  );
  expect(nhfrReading({ ...presence, values: [{ value: 0 }] }, scope)?.denominatorNote).toContain(
    "denominator unavailable",
  );
  for (const denominator of [-1, 0, 1.5, NaN, Infinity]) {
    expect(nhfrReading({ ...presence, values: [{ value: 2, denominator }] }, scope)).toBeNull();
  }
});
it("shares definitions and retains exact data, source disclosure and scoped links", () => {
  for (const [id, definition] of [
    ["nhfr.facilities", NHFR_DEFINITION],
    ["nhfr.beds", NHFR_BEDS],
    ["nhfr.barangay-presence", NHFR_PRESENCE],
  ]) {
    expect(measureDefinition({ ...indicator, id })).toBe(definition);
  }
  const catalog = { selection: scope, datasets: [dataset], overview: summarizeCatalog([dataset]) };
  const markup = renderToStaticMarkup(
    <CatalogDetails catalog={catalog} dataset={dataset} locationName="Synthetic place" />,
  );
  expect(markup).toContain("Exact data, availability and sources");
  expect(markup).toContain("<table");
  expect(markup).toContain("/facilities/citymun/0102801");
  expect(markup).toContain("Open full NHFR map");
});
