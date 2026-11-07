import { expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { parseHTML } from "linkedom";
import { CatalogDetails, CatalogResults } from "./catalog-results";
import type { CatalogDataset, PublicCatalog } from "@/lib/catalog/types";
import { summarizeCatalog } from "@/lib/catalog/summary";

const dataset: CatalogDataset = {
  id: "nhfr",
  title: "NHFR",
  category: "Facilities",
  state: "available",
  availableIndicators: 1,
  totalIndicators: 9,
  supportedLevels: ["region"],
  detailHref: null,
  sources: [
    {
      slug: "nhfr",
      name: "Registry",
      sourceName: "Public registry",
      asOfDate: "2026-01-01",
      lastUpdatedAt: null,
    },
  ],
  indicators: [
    {
      id: "nhfr.facilities",
      label: "Facilities",
      unit: "facilities",
      state: "available",
      reason: null,
      sourceSlug: "nhfr",
      values: [{ value: 0 }],
      reportedAreaRecords: 1,
    },
  ],
};
const selection = { mode: "psgc", level: "region", code: "01" } as const;
function catalog(datasets: CatalogDataset[]): PublicCatalog {
  return { selection, datasets, overview: summarizeCatalog(datasets) };
}
it("renders a zero card beside a failed dataset with a scope-preserving detail link", () => {
  const failed = {
    ...dataset,
    id: "poverty" as const,
    title: "Poverty",
    state: "failed" as const,
    availableIndicators: 0,
    indicators: [
      { ...dataset.indicators[0], state: "failed" as const, values: [], reason: "Read failed" },
    ],
  };
  const { document } = parseHTML(
    renderToStaticMarkup(
      <CatalogResults catalog={catalog([dataset, failed])} locationName="Region I" />,
    ),
  );
  expect(document.querySelectorAll("article")).toHaveLength(1);
  expect(document.documentElement.textContent).toContain("0 facilities");
  expect(document.documentElement.textContent).toContain("Some figures could not be loaded");
  expect(document.querySelector("article a")?.getAttribute("href")).toContain(
    "location=region%3A01&dataset=nhfr",
  );
  expect(document.documentElement.textContent).toContain("Public registry");
});
it("explains a fully empty selection and preserves scope when returning from details", () => {
  const empty = {
    ...dataset,
    state: "unavailable" as const,
    availableIndicators: 0,
    indicators: [],
  };
  const html = renderToStaticMarkup(
    <CatalogResults catalog={catalog([empty])} locationName="Region I" />,
  );
  expect(html).toContain("No datasets to display");
  expect(html).not.toContain("<article");
  const { document } = parseHTML(
    renderToStaticMarkup(<CatalogDetails catalog={catalog([dataset])} dataset={dataset} />),
  );
  expect(document.querySelector("a")?.getAttribute("href")).toBe(
    "/catalog?mode=psgc&location=region%3A01",
  );
  expect(document.documentElement.textContent).toContain("0 facilities");
});
