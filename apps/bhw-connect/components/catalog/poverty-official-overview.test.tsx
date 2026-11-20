import { expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { PovertyOfficialOverview } from "./poverty-official-overview";
import { CatalogDetails } from "./catalog-results";
import { summarizeCatalog } from "@/lib/catalog/summary";
import type { CatalogDataset } from "@/lib/catalog/types";
const dataset: CatalogDataset = {
  id: "poverty",
  title: "Poverty",
  category: "Context",
  state: "available",
  availableIndicators: 1,
  totalIndicators: 1,
  supportedLevels: ["national", "region", "province"],
  detailHref: null,
  sources: [],
  indicators: [
    {
      id: "poverty.official-incidence",
      label: "Official population poverty",
      unit: "%",
      sourceSlug: "psa-official-poverty-2023-r2",
      state: "available",
      reason: "Synthetic boundary caveat and precision caution.",
      values: [{ value: 0, ciLow: -0.2, ciHigh: 2.4 }],
      reportedAreaRecords: 1,
    },
  ],
};
const selection = { mode: "psgc", level: "national", code: "PH" } as const;
it("shows national zero, 95% interval and source boundary alongside the estimate", () => {
  const html = renderToStaticMarkup(
    <PovertyOfficialOverview dataset={dataset} selection={selection} />,
  );
  expect(html).toContain("0%");
  expect(html).toContain("95% confidence interval: -0.2% to 2.4%");
  expect(html).toContain("Synthetic boundary caveat");
  expect(html).not.toContain("90% confidence interval:");
  const exact = renderToStaticMarkup(
    <CatalogDetails
      dataset={dataset}
      catalog={{ selection, datasets: [dataset], overview: summarizeCatalog([dataset]) }}
    />,
  );
  expect(exact).toContain("95% confidence interval");
});
it("all locations links to an explicit national scope without serializing a national sample", () => {
  const html = renderToStaticMarkup(
    <PovertyOfficialOverview dataset={dataset} selection={{ mode: "all" }} />,
  );
  expect(html).toContain("national%3APH");
  expect(html).not.toContain("-0.2%");
});
it.each(["unavailable", "failed", "suppressed"] as const)(
  "never displays stale values for %s",
  (state) => {
    const html = renderToStaticMarkup(
      <PovertyOfficialOverview
        dataset={{
          ...dataset,
          indicators: [
            {
              ...dataset.indicators[0],
              state,
              reason: state === "suppressed" ? "Withheld by the source" : null,
            },
          ],
        }}
        selection={selection}
      />,
    );
    expect(html).not.toContain("-0.2%");
    expect(html).toContain(
      state === "failed"
        ? "could not be loaded"
        : state === "suppressed"
          ? "Withheld"
          : "does not mean PSA has no published estimate",
    );
  },
);
