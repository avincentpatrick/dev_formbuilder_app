import { expect, it } from "vitest";
import { bhwCatalogReturnHref, restoredBhwMapHref } from "./catalog-return";
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
