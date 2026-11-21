import { renderToStaticMarkup } from "react-dom/server";
import { parseHTML } from "linkedom";
import { expect, it } from "vitest";
import { PovertyIntervalChart, povertyChartDomain } from "./poverty-interval-chart";

it("shows zero and published bounds outside 0–100 without clipping their geometry", () => {
  const point = { value: 0, ciLow: -0.2, ciHigh: 105 };
  const { document } = parseHTML(
    renderToStaticMarkup(
      <PovertyIntervalChart point={point} confidence={90} label="Synthetic municipality" />,
    ),
  );
  const svg = document.querySelector("svg")!;
  expect(svg.getAttribute("aria-label")).toContain(
    "0%. Published 90% confidence interval: -0.2% to 105%",
  );
  for (const line of svg.querySelectorAll("line")) {
    for (const attribute of ["x1", "x2"]) {
      expect(Number(line.getAttribute(attribute))).toBeGreaterThanOrEqual(30);
      expect(Number(line.getAttribute(attribute))).toBeLessThanOrEqual(370);
    }
  }
  expect(Number(svg.querySelector("circle")!.getAttribute("cx"))).toBeGreaterThan(30);
});

it("uses identical geometry for identical values across comparisons, even when intervals differ", () => {
  const points = [
    { value: 10, ciLow: -1, ciHigh: 25 },
    { value: 10, ciLow: 9, ciHigh: 11 },
  ];
  const domain = povertyChartDomain(points);
  const positions = points.map((point) => {
    const { document } = parseHTML(
      renderToStaticMarkup(
        <PovertyIntervalChart
          point={point}
          domain={domain}
          confidence={90}
          label="Synthetic place"
        />,
      ),
    );
    return document.querySelector("circle")!.getAttribute("cx");
  });
  expect(positions[0]).toBe(positions[1]);
  expect(domain).toEqual([-10, 100]);
});

it.each([null, NaN, Infinity, -1, 101])("does not chart an unusable estimate (%s)", (value) => {
  expect(
    renderToStaticMarkup(
      <PovertyIntervalChart
        point={{ value, ciLow: -5, ciHigh: 110 }}
        confidence={95}
        label="Synthetic area"
      />,
    ),
  ).toBe("");
});

it.each([
  { ciLow: 7, ciHigh: 2 },
  { ciLow: NaN, ciHigh: 4 },
  { ciLow: null, ciHigh: 4 },
])("keeps an estimate without inventing an interval: %j", (bounds) => {
  const { document } = parseHTML(
    renderToStaticMarkup(
      <PovertyIntervalChart
        point={{ value: 5, ...bounds }}
        confidence={95}
        label="Synthetic area"
      />,
    ),
  );
  expect(document.querySelector("circle")).not.toBeNull();
  expect(document.querySelector("figcaption")!.textContent).toContain(
    "95% confidence interval is unavailable or unusable",
  );
  expect(document.querySelector("g.stroke-accent")).toBeNull();
});
