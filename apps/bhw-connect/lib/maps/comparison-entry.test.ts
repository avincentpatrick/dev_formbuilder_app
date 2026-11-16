import { expect, it } from "vitest";
import { bhwMapComparisonHref } from "./comparison-entry";
import { initialMapChoice, mapHref, type MapPayload } from "./prototype";
const data: MapPayload = {
  state: "available",
  rows: [{ code: "07306", name: "Synthetic HUC", value: null }],
  total: null,
  area: "Region VII",
  childLevel: "province",
  source: "Synthetic",
  date: "2025-12-31",
  license: null,
  geometryUrl: "synthetic.json",
};
it("carries the same public measure and selected child without map-only settings", () => {
  const choice = {
    ...initialMapChoice("bhw-2025"),
    level: "region" as const,
    code: "07",
    indicator: "bhw.active-years",
    selected: "07306",
    valueMin: 12.34,
    classification: "custom" as const,
    classBreaks: [10, 20],
  };
  const query = new URL(bhwMapComparisonHref(choice, data)!, "https://example.test").searchParams;
  expect(query.get("measure")).toBe("bhw.active-years");
  expect(query.get("geos")).toBe("07306");
  expect(query.get("mapState")).toBe(mapHref(choice).split("?")[1]);
  expect(query.has("valueMin")).toBe(false);
  expect(
    bhwMapComparisonHref(
      {
        ...initialMapChoice("bhw-stepzero-2026"),
        level: "region" as const,
        code: "07",
        selected: "07306",
      },
      data,
    ),
  ).toContain("/compare?measure=bhw.total&geos=07306&mapState=");
});
it("does not invent a comparison for unloaded, unsupported or mismatched selections", () => {
  const choice = {
    ...initialMapChoice("bhw-2025"),
    level: "region" as const,
    code: "07",
    selected: "07306",
  };
  expect(bhwMapComparisonHref(choice, null)).toBeNull();
  expect(bhwMapComparisonHref({ ...choice, selected: null }, data)).toBeNull();
  expect(bhwMapComparisonHref({ ...choice, selected: "07022" }, data)).toBeNull();
  expect(bhwMapComparisonHref(choice, { ...data, valueSupport: "navigation-only" })).toBeNull();
  for (const changes of [
    { dataset: "bhw-profiling-status-2026" },
    { dataset: "psa-popcen-2024" },
    { indicator: "bhw.total" },
    { period: "2026" },
    { indicator: "stepzero.population" },
  ])
    expect(bhwMapComparisonHref({ ...choice, ...changes }, data)).toBeNull();
  expect(
    bhwMapComparisonHref(
      { ...choice, indicator: "bhw.active-years" },
      { ...data, childLevel: "barangay" },
    ),
  ).toBeNull();
});
