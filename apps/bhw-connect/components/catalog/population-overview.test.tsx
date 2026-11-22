import { expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { PopulationOverview } from "./population-overview";
import { CatalogDetails } from "./catalog-results";
import { populationReading, POPULATION_DEFINITION } from "@/lib/catalog/population-reading";
import { measureDefinition } from "@/lib/catalog/measure-notes";
import { summarizeCatalog } from "@/lib/catalog/summary";
import type { CatalogDataset, CatalogSelection, DataState } from "@/lib/catalog/types";
const scope: CatalogSelection = { mode: "psgc", level: "citymun", code: "0102801" };
const dataset: CatalogDataset = {
  id: "population",
  title: "Population",
  category: "Population and demographics",
  state: "available",
  availableIndicators: 1,
  totalIndicators: 1,
  supportedLevels: ["national", "region", "province", "citymun"],
  detailHref: null,
  sources: [],
  indicators: [
    {
      id: "population.residents",
      label: "PSA 2024 residents",
      unit: "people",
      state: "available",
      reason: null,
      sourceSlug: "psa-popcen-2024",
      values: [{ value: 0 }],
      reportedAreaRecords: 1,
    },
  ],
};
const indicator = dataset.indicators[0];
function html(selection: CatalogSelection = scope, state: DataState = "available") {
  return renderToStaticMarkup(
    <PopulationOverview
      dataset={{ ...dataset, indicators: [{ ...indicator, state, values: [{ value: 123456 }] }] }}
      selection={selection}
      locationName="Synthetic place"
    />,
  );
}
it("preserves zero and shares the definition with exact-data descriptions", () => {
  expect(populationReading(indicator, scope)?.value).toBe("0");
  expect(measureDefinition(indicator)).toContain(POPULATION_DEFINITION);
  expect(html()).toContain("123,456");
  expect(html()).toContain("not today");
});
it("never exposes rejected sentinel payloads or replaces unavailable scopes", () => {
  for (const state of ["failed", "unavailable", "unsupported", "suppressed"] as const) {
    expect(html(scope, state)).not.toContain("123,456");
    expect(populationReading({ ...indicator, state }, scope)).toBeNull();
  }
  expect(html(scope, "failed")).toContain("could not be loaded");
  expect(html(scope, "suppressed")).toContain("withheld");
  expect(html(scope, "unavailable")).toContain("does not mean zero");
  for (const selection of [
    { mode: "all" },
    { mode: "psgc", level: "barangay", code: "0102801001" },
    { mode: "district", districtCode: "test", barangayCode: "0102801001" },
  ] as CatalogSelection[])
    expect(html(selection)).not.toContain("123,456");
});
it("rejects incompatible releases, units and invalid or multiple counts", () => {
  for (const patch of [
    { sourceSlug: "bhw-stepzero-2026" },
    { unit: "households" },
    { values: [{ value: 1.5 }] },
    { values: [{ value: -1 }] },
    { values: [{ value: Infinity }] },
    { values: [{ value: 1 }, { value: 2 }] },
  ])
    expect(populationReading({ ...indicator, ...patch }, scope)).toBeNull();
});
it("keeps partial totals prominent even if a parent observation is mistakenly marked available", () => {
  for (const [level, code] of [
    ["national", "PH"],
    ["region", "01"],
    ["province", "01028"],
  ] as const) {
    expect(populationReading(indicator, { mode: "psgc", level, code })?.partial).toBe(true);
    expect(html({ mode: "psgc", level, code })).toContain("Do not use this as the full population");
  }
  expect(populationReading({ ...indicator, state: "partial" }, scope)?.partial).toBe(true);
});
it("keeps the Manila exception at its province node and honors incomplete status", () => {
  const manila: CatalogSelection = { mode: "psgc", level: "province", code: "13806" };
  expect(populationReading(indicator, manila)?.label).toBe("Published Manila city population");
  expect(populationReading({ ...indicator, state: "partial" }, manila)?.partial).toBe(true);
  expect(
    populationReading(
      { ...indicator, state: "unavailable", values: [] },
      { mode: "psgc", level: "citymun", code: "1380601" },
    ),
  ).toBeNull();
});
it("requires an approved district sum and preserves its source coverage", () => {
  const district: CatalogSelection = { mode: "district", districtCode: "synthetic" };
  expect(populationReading(indicator, district)).toBeNull();
  expect(populationReading({ ...indicator, aggregation: "sum" }, district)?.label).toContain(
    "compatible mapped areas",
  );
});
it("retains exact data in a named disclosure with the source definition", () => {
  const catalog = { selection: scope, datasets: [dataset], overview: summarizeCatalog([dataset]) };
  const markup = renderToStaticMarkup(
    <CatalogDetails catalog={catalog} dataset={dataset} locationName="Synthetic place" />,
  );
  expect(markup).toContain("Population in 2024");
  expect(markup).toContain("Exact data, availability and sources");
  expect(markup).toContain("<table");
  expect(markup).toContain("Open full Population map");
});
