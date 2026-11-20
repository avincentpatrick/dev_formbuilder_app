import { expect, it } from "vitest";
import { contextHref, parseContext, joinContext } from "./context";
import { withBasePath } from "@/lib/base-path";
import type { MapPayload } from "@/lib/maps/prototype";
const choice = () => parseContext(new URLSearchParams("level=region&code=07"))!;
const payload = (): MapPayload => ({
  state: "available",
  area: "Synthetic VII",
  childLevel: "province",
  source: "Synthetic Headcount",
  date: "2025-01-01",
  license: null,
  total: null,
  geometryUrl: withBasePath("/geo/provinces/07.json"),
  rows: [
    { code: "07012", name: "Bohol", value: 0 },
    { code: "07022", name: "Cebu", value: 50 },
    { code: "07306", name: "Cebu City", value: null },
  ],
});
it("preserves scope, selected locality and context in shared URLs", () => {
  const c = choice();
  c.map.selected = "07306";
  c.context = "population";
  expect(parseContext(new URLSearchParams(contextHref(c).split("?")[1]))).toEqual(c);
});
it("rejects unsupported or ambiguous choices without a national fallback", () => {
  for (const q of [
    "context=poverty",
    "level=barangay&code=0702234001",
    "level=region&code=07&selected=01",
    "code=PH&code=PH",
    "dataset=hidden",
    "period=2024",
  ])
    expect(parseContext(new URLSearchParams(q))).toBeNull();
});
it("joins exact same-code records preserving zero and excluding unavailable or flagged values", () => {
  const h = payload(),
    x = payload();
  x.rows.reverse();
  h.rows[1].smallSample = true;
  const rows = joinContext(h, x, choice().map)!;
  expect(rows.map((r) => r.eligible)).toEqual([true, false, false]);
  expect(rows[0]).toMatchObject({ headcount: 0, context: 0 });
  expect(rows[1].headcount).toBeNull();
  expect(h.rows[1].value).toBe(50);
});
it("refuses incompatible source/date/geography rosters and duplicate or malformed observations", () => {
  const h = payload();
  for (const x of [
    { ...payload(), date: "2024-01-01" },
    { ...payload(), source: "Other" },
    { ...payload(), area: "Other" },
    { ...payload(), childLevel: "citymun" },
    { ...payload(), rows: [] },
    { ...payload(), rows: [h.rows[0], h.rows[0], h.rows[2]] },
    { ...payload(), rows: [{ ...h.rows[0], name: "Changed" }, ...h.rows.slice(1)] },
    { ...payload(), valueSupport: "navigation-only" },
    { ...payload(), rows: [{ ...h.rows[0], value: -1 }, ...h.rows.slice(1)] },
  ])
    expect(joinContext(h, x, choice().map)).toBeNull();
});
it("never recovers a missing headcount from context or vice versa", () => {
  const h = payload(),
    x = payload();
  h.rows[0].value = null;
  x.rows[1].value = null;
  expect(joinContext(h, x, choice().map)?.map((r) => r.eligible)).toEqual([false, false, false]);
});
