import { expect, it } from "vitest";
import { parseHTML } from "linkedom";
import { styleAxisTitles } from "./style-axis";

it("pairs dynamically created tooltip backgrounds with themed chart text on the SVG itself", () => {
  const { document } = parseHTML('<svg style="font-size:13px"><text>Label</text></svg>');
  const svg = document.querySelector("svg")!;
  styleAxisTitles(svg);
  expect(svg.style.getPropertyValue("--plot-background")).toBe("var(--surface, #ffffff)");
  expect(svg.style.fontSize).toBe("13px");
  expect(svg.querySelector("text")!.getAttribute("fill")).toBe("currentColor");
});
