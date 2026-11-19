import { expect, it, vi } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { parseHTML } from "linkedom";
import { initialMapChoice, mapHref } from "@/lib/maps/prototype";
import { BhwOverview } from "./overview";
import type { CatalogDataset, CatalogIndicator } from "@/lib/catalog/types";

vi.mock("@/components/maps/dataset-map-card", () => ({
  DatasetMapCard: ({
    dataset,
    overviewPreview,
  }: {
    dataset: string;
    overviewPreview?: boolean;
  }) => <div data-map-preview={overviewPreview} data-dataset={dataset} />,
}));
// Inspect the exact props crossing into interactive charts/presentations.
vi.mock("@/components/charts/figure-view", () => ({
  FigureView: ({
    data,
    preserveOrder,
    yLabel,
  }: {
    data: unknown;
    preserveOrder?: boolean;
    yLabel?: string;
  }) => (
    <pre data-chart data-preserve-order={preserveOrder} data-axis={yLabel}>
      {JSON.stringify(data)}
    </pre>
  ),
}));
const item = (id: string, state: CatalogIndicator["state"], value: number): CatalogIndicator => ({
  id,
  label: id,
  sourceSlug: "bhw-2025",
  unit: "people",
  state,
  values: [{ value, label: "PRIVATE_SENTINEL" }],
  reason: null,
  reportedAreaRecords: 1,
});
function render(indicators: CatalogIndicator[], mapState?: string | string[]) {
  const dataset: CatalogDataset = {
    id: "bhw",
    title: "BHW",
    category: "Health workforce",
    state: "partial",
    indicators,
    availableIndicators: 1,
    totalIndicators: indicators.length,
    supportedLevels: ["national"],
    detailHref: "/bhw",
    sources: [],
  };
  return renderToStaticMarkup(<BhwOverview dataset={dataset} mapState={mapState} />);
}
it("does not serialize withheld categories or payer values into charts, labels or presentation content", () => {
  const html = render([
    item("bhw.total", "unavailable", 999999),
    item("bhw.validated-profiles", "available", 10),
    item("bhw.demographics.education", "suppressed", 123456),
    item("bhw.honorarium.region", "failed", 98.7654),
  ]);
  expect(html).not.toContain("PRIVATE_SENTINEL");
  expect(html).not.toContain("123456");
  expect(html).not.toContain("98.7654");
  expect(html).not.toContain("999999");
  expect(html).toContain("Withheld across locations");
  expect(html).toContain("Could not verify or load");
  expect(html).not.toContain("/api/export/");
});
it("starts with figures and provides named purpose anchors before methodology", () => {
  const { document } = parseHTML(render([item("bhw.total", "available", 0)]));
  expect(document.querySelector('[data-testid="bhw-first-visual"]')?.textContent).toContain("0");
  expect(document.querySelector('nav[aria-label="BHW analysis"]')).not.toBeNull();
  for (const id of ["workforce", "qualifications", "support", "methodology"])
    expect(document.getElementById(id)).not.toBeNull();
  expect(document.getElementById("methodology")?.tagName).toBe("DETAILS");
  expect(document.querySelectorAll('[href*="bhw-profiling-status-2026"]')).toHaveLength(0);
});

it("routes every overview comparison entry through the public headcount contract", () => {
  const { document } = parseHTML(render([item("bhw.total", "available", 0)]));
  const links = [...document.querySelectorAll('a[href^="/compare"]')].filter((link) =>
    link.textContent?.trim().startsWith("Compare places"),
  );
  expect(links).toHaveLength(2);
  for (const link of links) expect(link.getAttribute("href")).toBe("/compare?measure=bhw.total");
});

it("resumes the validated map from navigation while retaining a fresh national headcount link", () => {
  const previous = mapHref({
    ...initialMapChoice("bhw-2025"),
    level: "region",
    code: "07",
    indicator: "bhw.active-years",
    selected: "07306",
    valueMin: 12.34,
    classification: "custom",
    classBreaks: [10, 20],
  });
  const { document } = parseHTML(render([], previous.split("?")[1]));
  const explorer = [...document.querySelectorAll("nav a")].find((a) =>
    a.textContent?.includes("Map explorer"),
  );
  expect(explorer?.getAttribute("href")).toBe(previous);
  expect(explorer?.textContent).toContain("previous settings");
  const comparisons = [...document.querySelectorAll("a")].filter((a) =>
    a.textContent?.trim().startsWith("Compare places"),
  );
  expect(comparisons).toHaveLength(2);
  for (const link of comparisons) {
    const params = new URLSearchParams(link.getAttribute("href")!.split("?")[1]);
    expect(params.get("mapState")).toBe(previous.split("?")[1]);
    expect(params.get("measure")).toBe("bhw.total");
    expect(params.has("geos")).toBe(false);
  }
  const fresh = [...document.querySelectorAll("a")].find((a) =>
    a.textContent?.includes("Explore national headcount"),
  );
  expect(fresh?.getAttribute("href")).toBe(mapHref(initialMapChoice("bhw-stepzero-2026")));
});
it("keeps the fresh map default when a snapshot is absent, invalid, hidden or duplicated", () => {
  for (const raw of [
    undefined,
    "https://example.com",
    "dataset=bhw-profiling-status-2026",
    ["dataset=bhw-2025", "dataset=bhw-2025"],
  ]) {
    const { document } = parseHTML(render([], raw));
    const explorer = [...document.querySelectorAll("nav a")].find((a) =>
      a.textContent?.includes("Map explorer"),
    );
    expect(explorer?.getAttribute("href")).toBe(mapHref(initialMapChoice("bhw-stepzero-2026")));
    expect(explorer?.textContent).not.toContain("previous settings");
  }
});

it("opens a fresh headcount distribution preview independently of a saved map return", () => {
  const { document } = parseHTML(render([], "dataset=bhw-2025&indicator=bhw.active-years"));
  expect(document.querySelector('[aria-label="Workforce distribution preview"]')).not.toBeNull();
  expect(document.querySelector('[data-map-preview="true"]')?.getAttribute("data-dataset")).toBe(
    "bhw-stepzero-2026",
  );
});

it("keeps the national figure sections for a locality and scopes captions, map and onward links", () => {
  const selection = { mode: "psgc", level: "province", code: "01028" } as const;
  const dataset: CatalogDataset = {
    id: "bhw",
    title: "BHW",
    category: "Health workforce",
    state: "available",
    indicators: [item("bhw.total", "available", 125)],
    availableIndicators: 1,
    totalIndicators: 1,
    supportedLevels: ["province"],
    detailHref: null,
    sources: [],
  };
  const { document } = parseHTML(
    renderToStaticMarkup(
      <BhwOverview dataset={dataset} selection={selection} areaName="Chosen province" />,
    ),
  );
  for (const id of ["workforce", "qualifications", "support", "methodology"])
    expect(document.getElementById(id)).not.toBeNull();
  expect(document.getElementById("workforce-heading")?.textContent).toBe(
    "Workforce in Chosen province",
  );
  expect(document.querySelector('[data-testid="bhw-first-visual"]')?.textContent).toContain("125");
  expect(document.querySelector('[data-testid="bhw-overview"]')?.textContent).not.toContain(
    "Philippines",
  );
  expect(document.querySelector('a[href^="/compare"]')?.getAttribute("href")).toContain(
    "geos=01028",
  );
  expect(document.querySelector('a[href^="/map?"]')?.getAttribute("href")).toContain("code=01028");
  expect(document.querySelector("#methodology a")?.getAttribute("href")).toContain(
    "location=province%3A01028",
  );
});

it("shows both counts and shares on every national summary card", () => {
  const accreditation = {
    ...item("bhw.declared-accreditation", "available", 60),
    values: [{ value: 60, numerator: 60, denominator: 100 }],
  };
  const { document } = parseHTML(
    render([
      item("bhw.total", "available", 100),
      item("bhw.validated-profiles", "available", 80),
      accreditation,
    ]),
  );
  const workforce = document.querySelector('[data-testid="bhw-first-visual"]')!.textContent;
  expect(workforce).toContain("100 (100%)");
  expect(workforce).toContain("80 (80%)");
  expect(workforce).toContain("60 (60%)");
  expect(workforce).toContain("% of LGU-reported headcount");
  expect(document.getElementById("support")!.textContent).toContain(
    "Count unavailable (percentage unavailable)",
  );
});

it("shows the derived honorarium count with its percentage and explains the approximation", () => {
  const { document } = parseHTML(
    render([
      item("bhw.validated-profiles", "available", 1000),
      item("bhw.any-honorarium", "available", 94.7),
    ]),
  );
  const support = document.getElementById("support")!;
  expect(support.textContent).toContain("≈947 (95%)");
  expect(support.textContent).toContain("Approximate count calculated");
  expect(support.textContent).not.toContain("Count unavailable");
  expect(support.querySelector('svg[role="img"]')?.getAttribute("aria-label")).toContain(
    "≈947 (95%)",
  );
});

it("makes extended analyses discoverable from overview categories with exact-value and comparison links", () => {
  const html = render([
    { ...item("bhw.honorarium-amount.barangay.median", "available", 1000), unit: "PHP/month" },
    item("bhw.cohorts.registered", "available", 25),
    item("bhw.workload.median", "available", 50),
    item("bhw.training-recency.basic_life_support", "available", 2023),
    item("bhw.demographics.civil_status", "available", 80),
    item("bhw.combined-honorarium.median", "suppressed", 987654),
  ]);
  const { document } = parseHTML(html);
  for (const id of [
    "demographics",
    "service-history",
    "training-topics",
    "honorarium-amounts",
    "combined-honorarium",
  ])
    expect(document.getElementById(id)).not.toBeNull();
  expect(html).toContain("₱1,000/month");
  expect(html).toContain("indicator=bhw.honorarium-amount.barangay.median");
  expect(html).toContain("measure=bhw.honorarium-amount.barangay.median");
  expect(html).not.toContain("987654");
});

it("shows education once while retaining its exact and comparison destinations", () => {
  const { document } = parseHTML(render([item("bhw.demographics.education", "available", 42)]));
  const qualifications = document.getElementById("qualifications")!;
  expect(qualifications.querySelectorAll("[data-chart]")).toHaveLength(1);
  expect(
    qualifications.querySelector('a[href*="indicator=bhw.demographics.education"]'),
  ).not.toBeNull();
  expect(
    qualifications.querySelector('a[href*="measure=bhw.demographics.education"]'),
  ).not.toBeNull();
  expect(document.getElementById("demographics")!.querySelectorAll("[data-chart]")).toHaveLength(0);
});

it("groups long lists without losing exact links, unavailable statuses or exposing withheld values", () => {
  const { document } = parseHTML(
    render([
      { ...item("bhw.training.basic_life_support", "available", 0), unit: "%" },
      item("bhw.training-recency.basic_life_support", "unavailable", 111111),
      item("bhw.honorarium-amount.region.mean", "suppressed", 222222),
    ]),
  );
  for (const [section, id] of [
    ["training-topics", "bhw.training.basic_life_support"],
    ["honorarium-amounts", "bhw.honorarium-amount.region.mean"],
  ]) {
    const group = document.querySelector(`#${section} > details`)!;
    expect(group).not.toBeNull();
    expect(group.hasAttribute("open")).toBe(false);
    expect(group.querySelector(`a[href*="indicator=${id}"]`)).not.toBeNull();
    expect(group.querySelector(`a[href*="measure=${id}"]`)).not.toBeNull();
  }
  const training = document.getElementById("training-topics")!;
  expect(training.textContent).toContain("0%");
  expect(training.textContent).not.toContain("111111");
  expect(document.getElementById("honorarium-amounts")!.textContent).not.toContain("222222");
  expect(training.querySelector("article h3")).toBeNull();
});

it("connects ordered age values to the chart sequence and descriptive axis", () => {
  const age = {
    ...item("bhw.demographics.age_band", "available", 1),
    values: [
      { label: "60+", value: 7 },
      { label: "<30", value: 0 },
      { label: "40-49", value: 100 },
    ],
  };
  const { document } = parseHTML(render([age]));
  const chart = document.querySelector("#demographics [data-chart]")!;
  expect(chart.getAttribute("data-preserve-order")).toBe("true");
  expect(chart.getAttribute("data-axis")).toBe("Age group");
  expect(JSON.parse(chart.textContent!).map((row: { label: string }) => row.label)).toEqual([
    "<30",
    "40-49",
    "60+",
  ]);
});
