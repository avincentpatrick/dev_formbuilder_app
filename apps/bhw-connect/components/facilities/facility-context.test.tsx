import { expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { FacilityContext } from "./facility-context";
import { summarizeCatalog } from "@/lib/catalog/summary";
import type { CatalogDataset, PublicCatalog } from "@/lib/catalog/types";
const dataset: CatalogDataset = {
  id: "poverty",
  title: "Poverty",
  category: "Poverty",
  state: "available",
  availableIndicators: 1,
  totalIndicators: 1,
  supportedLevels: ["citymun"],
  detailHref: null,
  sources: [],
  indicators: [
    {
      id: "poverty.incidence",
      label: "SAE",
      sourceSlug: "psa-sae-poverty-2023",
      unit: "%",
      state: "available",
      reason: "Synthetic source note",
      values: [{ value: 12, ciLow: 10, ciHigh: 14 }],
      reportedAreaRecords: 1,
    },
  ],
};
const catalog: PublicCatalog = {
  selection: { mode: "psgc", level: "citymun", code: "0102801" },
  datasets: [dataset],
  overview: summarizeCatalog([dataset]),
};
it("keeps uncertainty, source notes and exact selected-place links with linked context", () => {
  const html = renderToStaticMarkup(<FacilityContext catalog={catalog} />);
  expect(html).toContain("90% confidence interval: 10% to 14%");
  expect(html).toContain("Synthetic source note");
  expect(html).toContain("location=citymun%3A0102801");
  expect(html).toContain("No facilities-per-population rate is shown");
  expect(html).toContain("requires a validated barangay-level join");
});
it("never shows parent context for barangay selection", () => {
  const html = renderToStaticMarkup(
    <FacilityContext
      catalog={{ ...catalog, selection: { mode: "psgc", level: "barangay", code: "0102801001" } }}
    />,
  );
  expect(html).toContain("No parent population");
  expect(html).not.toContain("12%");
});
