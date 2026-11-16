import { expect, it } from "vitest";
import {
  bhwNationalCatalogHref,
  bhwCatalogReturnHref,
  restoredBhwMapHref,
  bhwOverviewReturnHref,
} from "./catalog-return";
import { initialMapChoice, mapHref, type MapChoice } from "./prototype";
it("restores complete public BHW settings independently of catalog scope", () => {
  const choices: MapChoice[] = [
    {
      ...initialMapChoice("bhw-stepzero-2026"),
      level: "region",
      code: "07",
      selected: "07306",
      view: "choropleth",
      valueMin: 25,
      classification: "custom",
      classBreaks: [25, 50],
    },
    {
      ...initialMapChoice("bhw-2025"),
      indicator: "bhw.active-years",
      level: "region",
      code: "07",
      valueMin: 12.34,
      valueMax: 101.25,
      classification: "equal",
      classCount: 3,
    },
    {
      ...initialMapChoice("bhw-2025"),
      indicator: "bhw.profile-accreditation",
      valueMin: 25.5,
      classification: "custom",
      classBreaks: [25, 75],
    },
  ];
  for (const choice of choices) {
    const params = new URLSearchParams(bhwCatalogReturnHref(choice)!.split("?")[1]);
    expect(params.get("dataset")).toBe("bhw");
    expect(params.get("location")).toBe(`${choice.level}:${choice.code}`);
    expect(restoredBhwMapHref(params.get("mapState")!)).toBe(mapHref(choice));
  }
});
it("rejects duplicate, malformed, hidden and non-BHW snapshots without accepting destinations", () => {
  for (const raw of [
    undefined,
    [],
    ["dataset=bhw-2025", "dataset=bhw-stepzero-2026"],
    "",
    "https://example.com/map?dataset=bhw-2025",
    "javascript:alert(1)",
    "dataset=bhw-2025&dataset=bhw-stepzero-2026",
    "dataset=bhw-profiling-status-2026",
    "dataset=psa-popcen-2024",
    "dataset=bhw-2025&valueMin=-1",
    "x".repeat(4097),
  ])
    expect(restoredBhwMapHref(raw)).toBeNull();
  expect(bhwCatalogReturnHref(initialMapChoice("psa-popcen-2024"))).toBeNull();
});
it("canonicalizes known settings rather than forwarding arbitrary parameters", () => {
  const href = restoredBhwMapHref(
    "dataset=bhw-2025&redirect=https%3A%2F%2Fexample.com&mapState=recursive",
  );
  expect(href).toBe(mapHref(initialMapChoice("bhw-2025")));
});

it("preserves the complete BHW map snapshot through the national overview without changing its scope", () => {
  const choice: MapChoice = {
    ...initialMapChoice("bhw-2025"),
    indicator: "bhw.active-years",
    level: "region",
    code: "07",
    selected: "07306",
    view: "choropleth",
    valueMin: 12.34,
    classification: "custom",
    classBreaks: [10, 20],
  };
  const href = bhwOverviewReturnHref(choice)!;
  expect(href.startsWith("/bhw?")).toBe(true);
  const params = new URLSearchParams(href.split("?")[1]);
  expect([...params.keys()]).toEqual(["mapState"]);
  expect(restoredBhwMapHref(params.get("mapState")!)).toBe(mapHref(choice));
  expect(bhwOverviewReturnHref(initialMapChoice("psa-popcen-2024"))).toBeNull();
});

it("carries a local map snapshot into national methodology without changing catalog scope", () => {
  const choice: MapChoice = {
    ...initialMapChoice("bhw-2025"),
    level: "region",
    code: "07",
    indicator: "bhw.active-years",
    selected: "07306",
    valueMin: 12.34,
    classification: "custom",
    classBreaks: [10, 20],
  };
  const raw = mapHref(choice).split("?")[1];
  const query = new URLSearchParams(bhwNationalCatalogHref(raw).split("?")[1]);
  expect(query.get("location")).toBe("national:PH");
  expect(query.get("dataset")).toBe("bhw");
  expect(restoredBhwMapHref(query.get("mapState")!)).toBe(mapHref(choice));
  for (const invalid of [
    undefined,
    "https://example.com",
    [raw, raw],
    "dataset=bhw-profiling-status-2026",
  ])
    expect(bhwNationalCatalogHref(invalid)).toBe(bhwNationalCatalogHref());
});
