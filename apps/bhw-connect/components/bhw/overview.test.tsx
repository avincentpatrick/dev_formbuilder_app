import { expect, it, vi } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { parseHTML } from "linkedom";
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
function render(indicators: CatalogIndicator[]) {
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
  return renderToStaticMarkup(<BhwOverview dataset={dataset} />);
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
