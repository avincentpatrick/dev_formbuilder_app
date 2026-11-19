import { expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { parseHTML } from "linkedom";
import { PovertyOverview } from "./poverty-overview";
import { CatalogDetails } from "./catalog-results";
import type { CatalogDataset, CatalogSelection, DataState } from "@/lib/catalog/types";
import { summarizeCatalog } from "@/lib/catalog/summary";
import { povertyReading, POVERTY_DEFINITION } from "@/lib/catalog/poverty-reading";
import { measureDefinition } from "@/lib/catalog/measure-notes";

const scope: CatalogSelection = { mode: "psgc", level: "citymun", code: "0102801" };
const dataset: CatalogDataset = {
  id: "poverty",
  title: "Poverty",
  category: "Socioeconomic conditions",
  state: "available",
  availableIndicators: 1,
  totalIndicators: 1,
  supportedLevels: ["citymun"],
  detailHref: null,
  sources: [
    {
      slug: "psa-sae-poverty-2023",
      name: "PSA SAE 2023",
      sourceName: "Synthetic PSA source",
      asOfDate: "2023-12-31",
      lastUpdatedAt: null,
    },
  ],
  indicators: [
    {
      id: "poverty.incidence",
      label: "PSA SAE 2023 poverty incidence",
      unit: "%",
      state: "available",
      reason: null,
      sourceSlug: "psa-sae-poverty-2023",
      values: [{ value: 0, ciLow: -0.2, ciHigh: 4.7 }],
      reportedAreaRecords: 1,
    },
  ],
};
function render(data = dataset, selection = scope) {
  return renderToStaticMarkup(
    <PovertyOverview dataset={data} selection={selection} locationName="A long municipal name" />,
  );
}
it("keeps zero and unclamped published bounds beside the scoped explanation", () => {
  const html = render();
  expect(html).toContain("0%");
  expect(html).toContain("-0.2% to 4.7%");
  expect(html).toContain("A long municipal name");
  expect(html).toContain("2023 · people");
  expect(html).toContain("not households");
  expect(measureDefinition(dataset.indicators[0])).toContain(POVERTY_DEFINITION);
});
it.each(["failed", "suppressed", "unsupported", "unavailable"] as DataState[])(
  "does not serialize a %s payload into the overview",
  (state) => {
    const data = {
      ...dataset,
      state,
      indicators: [
        {
          ...dataset.indicators[0],
          state,
          values: [{ value: 73.456, ciLow: 67.891, ciHigh: 89.123 }],
        },
      ],
    };
    const html = render(data);
    expect(html).not.toContain("73.46");
    expect(html).not.toContain("67.89");
    expect(html).not.toContain("89.12");
    expect(html).toContain(
      state === "failed"
        ? "could not be loaded"
        : state === "suppressed"
          ? "withheld"
          : "No usable accepted",
    );
  },
);
it("never turns nonmunicipal or all-location data into a local estimate", () => {
  for (const selection of [
    { mode: "all" },
    { mode: "psgc", level: "national", code: "PH" },
    { mode: "psgc", level: "barangay", code: "0102801001" },
    { mode: "district", districtCode: "test" },
  ] as CatalogSelection[]) {
    expect(render(dataset, selection)).not.toContain("Published 90% confidence interval:");
  }
});
it("rejects incompatible observations and preserves a valid estimate without an interval", () => {
  const item = dataset.indicators[0];
  for (const patch of [
    { sourceSlug: "other" },
    { unit: "people" },
    { values: [{ value: 101 }] },
    { values: [{ value: NaN }] },
    { values: [{ value: 1 }, { value: 2 }] },
  ]) {
    expect(povertyReading({ ...item, ...patch })).toBeNull();
  }
  expect(povertyReading({ ...item, values: [{ value: 1.234, ciLow: 5, ciHigh: 2 }] })).toEqual({
    estimate: "1.2%",
    interval: null,
  });
  expect(
    povertyReading({ ...item, values: [{ value: 99, ciLow: 95, ciHigh: 105 }] })?.interval,
  ).toBe("95% to 105%");
});
it("retains exact values, source dates and scoped links in a named native disclosure", () => {
  const { document } = parseHTML(
    renderToStaticMarkup(
      <CatalogDetails
        dataset={dataset}
        locationName="Municipality"
        catalog={{ selection: scope, datasets: [dataset], overview: summarizeCatalog([dataset]) }}
      />,
    ),
  );
  const disclosure = [...document.querySelectorAll("details")].find(
    (d) => d.querySelector("summary")?.textContent === "Exact data, availability and sources",
  );
  expect(disclosure?.hasAttribute("open")).toBe(false);
  expect(disclosure?.querySelector("table")).not.toBeNull();
  expect(disclosure?.textContent).toContain("Synthetic PSA source");
  expect(disclosure?.textContent).toContain("2023");
  expect(document.querySelector("a")?.getAttribute("href")).toContain("location=citymun%3A0102801");
});
