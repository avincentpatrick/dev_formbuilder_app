import { expect, it } from "vitest";
import { countAndShare, formatPct, formatterFor, percentageFormatter } from "./format";

it("uses whole percentages for headlines and one decimal for comparisons", () => {
  expect(countAndShare(270917, 88.29)).toBe("270,917 (88%)");
  expect(formatPct(65.72, "single")).toBe("66%");
  expect(formatPct(65.72)).toBe("65.7%");
  expect(formatterFor("percent")(65.72)).toBe("65.7%");
  expect(formatPct(50)).toBe("50%");
  expect(formatPct(null)).toBe("—");
});

it("does not turn small nonzero values or near-complete shares into zero or complete", () => {
  expect(formatPct(0.03, "single")).toBe("0.03%");
  expect(formatPct(99.97, "single")).toBe("99.97%");
  expect(formatPct(0.0001)).toBe("<0.01%");
  expect(formatPct(99.999)).toBe(">99.99%");
  expect(formatPct(0)).toBe("0%");
  expect(formatPct(100)).toBe("100%");
});

it("uses at most two decimals to preserve distinctions in a related series", () => {
  const values = [65.71, 65.72, 88.29];
  expect(values.map(percentageFormatter(values))).toEqual(["65.71%", "65.72%", "88.29%"]);
  const ordinary = [65.72, 88.29];
  expect(ordinary.map(percentageFormatter(ordinary))).toEqual(["65.7%", "88.3%"]);
});
