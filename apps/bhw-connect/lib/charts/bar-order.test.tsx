import { expect, it } from "vitest";
import { parseHTML } from "linkedom";
import { renderToStaticMarkup } from "react-dom/server";
import * as Plot from "@observablehq/plot";
import { horizontalBarSpec } from "./bar-chart";
import { FigureTable } from "@/components/charts/figure-table";

const data = [
  { label: "1999", value: 2 },
  { label: "2000", value: 100 },
  { label: "2025", value: 40 },
];
it("renders an explicit category domain while leaving ordinary charts ranked by value", () => {
  const { document } = parseHTML("<html><body></body></html>");
  const ordered = Plot.plot({ ...horizontalBarSpec(data, { preserveOrder: true }), document });
  const ranked = Plot.plot({ ...horizontalBarSpec(data), document });
  expect(ordered.scale("y")?.domain).toEqual(["1999", "2000", "2025"]);
  expect(ranked.scale("y")?.domain).toEqual(["2000", "2025", "1999"]);
});
it("uses the chart sequence in the default table and retains ranked defaults elsewhere", () => {
  const read = (preserveOrder: boolean) => {
    const { document } = parseHTML(
      renderToStaticMarkup(
        <FigureTable data={data} preserveOrder={preserveOrder} labelHeader="Year" />,
      ),
    );
    return {
      document,
      labels: [...document.querySelectorAll("tbody tr td:first-child")].map((e) => e.textContent),
    };
  };
  expect(read(true).labels).toEqual(["1999", "2000", "2025"]);
  expect(read(false).labels).toEqual(["2000", "2025", "1999"]);
  expect(
    read(true).document.querySelector('button[aria-label="Restore year order"]'),
  ).not.toBeNull();
});

it("wraps long labels within the plot gutter while keeping the full category key", () => {
  const label = "A long education category with an original source definition";
  const spec = horizontalBarSpec([{ label, value: 2 }], { width: 390 });
  const format = spec.y?.tickFormat as (label: string) => string;
  const wrapped = format(label);
  expect(wrapped).toContain("\n");
  expect(wrapped.replaceAll("\n", " ")).toBe(label);
  expect(spec.height).toBeGreaterThan(80);
});
