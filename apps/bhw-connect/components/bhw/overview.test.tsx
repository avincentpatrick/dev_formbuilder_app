import { expect, it, vi } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { parseHTML } from "linkedom";
import { initialMapChoice, mapHref } from "@/lib/maps/prototype";
import { BhwOverview } from "./overview";
import type { CatalogDataset, CatalogIndicator } from "@/lib/catalog/types";

// Inspect the exact props crossing into interactive charts/presentations.
vi.mock("@/components/charts/figure-view", () => ({
  FigureView: ({ data }: { data: unknown }) => <pre data-chart>{JSON.stringify(data)}</pre>,
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
  const links = [...document.querySelectorAll('a[href^="/compare"]')];
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
  const comparisons = [...document.querySelectorAll("a")].filter(
    (a) => a.textContent?.trim().startsWith("Compare places"),
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
