import { expect, it } from "vitest";
import { contextReview, type ContextRow } from "./context-review";
const row = (code: string, headcount: number | null, context: number | null): ContextRow => ({
  code,
  name: code,
  headcount,
  context,
  eligible: headcount !== null && context !== null,
});
it("separates gaps, reported zeros and transparent joint-median contrasts", () => {
  const rows = [
    row("a", 0, 100),
    row("b", 10, 90),
    row("c", 20, 20),
    row("d", 30, 10),
    row("e", null, 10000),
    row("f", 10000, null),
  ];
  const result = contextReview(rows);
  expect(result.headMedian).toBe(15);
  expect(result.contextMedian).toBe(55);
  expect(result.contrasts.map((r) => r.code)).toEqual(["a", "b"]);
  expect(result.zeros.map((r) => r.code)).toEqual(["a"]);
  expect(result.gaps.map((r) => r.code)).toEqual(["e", "f"]);
});
it("does not fabricate a comparative list for tiny samples or ties at either threshold", () => {
  expect(contextReview([row("a", 0, 100), row("b", 5, 5)]).headMedian).toBeNull();
  expect(
    contextReview([row("a", 5, 100), row("b", 5, 100), row("c", 5, 100), row("d", 5, 100)])
      .contrasts,
  ).toEqual([]);
});
it("uses raw observations and does not mutate or treat missing values as zeros", () => {
  const rows = [row("b", null, null), row("a", 0, 0)];
  const snapshot = JSON.stringify(rows);
  expect(contextReview(rows).zeros.map((r) => r.code)).toEqual(["a"]);
  expect(JSON.stringify(rows)).toBe(snapshot);
});
