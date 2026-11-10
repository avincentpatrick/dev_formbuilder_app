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

it("labels partial barangay totals and municipal attribution separately from service presence", () => {
  const partial = {
    ...dataset,
    state: "partial" as const,
    indicators: dataset.indicators.map((i) => ({
      ...i,
      state: "partial" as const,
      reason: "Unassigned facilities are excluded.",
      coverage: { label: "Municipal facilities with barangay attribution", covered: 8, total: 10 },
    })),
  };
  const result = {
    ...catalog([partial]),
    selection: { mode: "psgc", level: "barangay", code: "0102801001" } as const,
  };
  const html = renderToStaticMarkup(<CatalogResults catalog={result} locationName="Village" />);
  expect(html).toContain("Partial total");
  expect(html).toContain("Municipal facilities with barangay attribution");
  expect(html).toContain("8 of 10");
  expect(html).toContain("0 facilities");
  const detail = renderToStaticMarkup(<CatalogDetails catalog={result} dataset={partial} />);
  expect(detail).toContain("Partial total");
  expect(detail).toContain("8 of 10");
});

it("retains district context, exact inputs, definitions and reporting coverage in details", () => {
  const detailed = {
    ...dataset,
    id: "fhsis" as const,
    title: "FHSIS",
    indicators: [
      {
        ...dataset.indicators[0],
        id: "fhsis.fic",
        label: "Fully immunized children",
        unit: "%",
        values: [{ value: 81.23, numerator: 11.25, denominator: 13.85 }],
        definition: {
          numerator: "Children immunized",
          denominator: "Projected cohort",
          sourceWorkbook: "Synthetic dictionary",
        },
        coverage: { label: "Reporting units", covered: 1, total: 2 },
      },
    ],
  };
  const result = {
    ...catalog([detailed]),
    selection: {
      mode: "district",
      districtCode: "example",
      citymunCode: "0102801",
      barangayCode: "0102801001",
    } as const,
  };
  const { document } = parseHTML(
    renderToStaticMarkup(<CatalogDetails catalog={result} dataset={detailed} />),
  );
  expect(document.querySelector("a")?.getAttribute("href")).toBe(
    "/catalog?mode=district&district=example&citymun=0102801&barangay=0102801001",
  );
  const text = document.documentElement.textContent;
  for (const expected of [
    "Children immunized",
    "Projected cohort",
    "Synthetic dictionary",
    "11.25",
    "13.85",
    "Reporting units: 1 of 2",
    "Source date:",
    "Updated: Date not provided",
  ])
    expect(text).toContain(expected);
  expect(
    document.querySelector('[aria-label="Scrollable indicator table"]')?.getAttribute("tabindex"),
  ).toBe("0");
});

it.each(["unsupported", "unavailable", "suppressed", "failed"] as const)(
  "explains %s detail measures without displaying a zero",
  (state) => {
    const unavailable = {
      ...dataset,
      availableIndicators: 0,
      state,
      indicators: [
        { ...dataset.indicators[0], state, values: [], reason: `Explicit ${state} reason` },
      ],
    };
    const { document } = parseHTML(
      renderToStaticMarkup(
        <CatalogDetails catalog={catalog([unavailable])} dataset={unavailable} />,
      ),
    );
    expect(document.documentElement.textContent).toContain(`Explicit ${state} reason`);
    expect(document.documentElement.textContent).not.toContain("0 facilities");
    expect(document.querySelector("table")).toBeNull();
  },
);
