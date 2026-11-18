import { expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import type { CatalogDataset } from "@/lib/catalog/types";
import { BhwHonorariumDistribution } from "./honorarium-distribution";

it("keeps rejected quantiles out of chart labels, table and serialized chart props", () => {
  const dataset = {
    sources: [],
    indicators: ["min", "p25", "median", "p75", "max"].map((key) => ({
      id: `bhw.honorarium-amount.region.${key}`,
      unit: "PHP/month",
      sourceSlug: "bhw-2025",
      state: "suppressed",
      reason: null,
      values: [{ value: 987654321, label: "PRIVATE_SENTINEL" }],
    })),
  } as unknown as CatalogDataset;
  const html = renderToStaticMarkup(
    <BhwHonorariumDistribution dataset={dataset} areaName="Synthetic locality" />,
  );
  expect(html).not.toContain("987654321");
  expect(html).not.toContain("PRIVATE_SENTINEL");
  expect(html).not.toContain('role="img"');
  expect(html).toContain("Synthetic locality");
  expect(html).toContain("Withheld");
  expect(html).toContain("No complete public payment distributions");
  expect(html).not.toContain("/api/export/");
});
