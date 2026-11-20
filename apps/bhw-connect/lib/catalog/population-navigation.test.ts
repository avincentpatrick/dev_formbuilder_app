import { expect, it } from "vitest";
import { initialMapChoice, mapHref } from "@/lib/maps/prototype";
import {
  populationCatalogReturnHref,
  restoredPopulationMapHref,
  populationSearch,
  populationSearchSuffix,
} from "./population-navigation";
import { catalogJourneyHref } from "./journey";
import { populationAreaLabel } from "./population-reading";
const choice = {
  ...initialMapChoice("psa-popcen-2024"),
  level: "province" as const,
  code: "01028",
  selected: "0102801",
};
it("preserves selected child, scoped search and display choices through details and ancestors", () => {
  const params = new URLSearchParams(populationCatalogReturnHref(choice, "Ádams").split("?")[1]);
  expect(params.get("location")).toBe("citymun:0102801");
  const raw = params.get("mapState")!;
  expect(restoredPopulationMapHref(raw)).toBe(
    mapHref(choice) + populationSearchSuffix(choice, "Ádams"),
  );
  const ancestor = new URLSearchParams(
    catalogJourneyHref({ mode: "psgc", level: "province", code: "01028" }, "population", raw).split(
      "?",
    )[1],
  );
  expect(ancestor.get("mapState")).toBe(raw);
  expect(catalogJourneyHref({ mode: "all" }, "poverty", raw)).not.toContain("mapState");
});
it("rejects foreign and malformed snapshots and drops ambiguous or out-of-scope search", () => {
  for (const raw of [
    undefined,
    [],
    "",
    "javascript:alert(1)",
    "dataset=bhw-2025",
    "dataset=psa-popcen-2024&period=2020",
    "dataset=psa-popcen-2024&dataset=psa-popcen-2024",
    "x".repeat(4097),
  ])
    expect(restoredPopulationMapHref(raw)).toBeNull();
  for (const extra of [
    "populationScope=region:01&populationSearch=Adams",
    "populationScope=province:01028&populationSearch=A&populationSearch=B",
    `populationScope=province:01028&populationSearch=${"x".repeat(101)}`,
  ])
    expect(populationSearch(new URLSearchParams(extra), choice)).toBe("");
  expect(
    restoredPopulationMapHref("dataset=psa-popcen-2024&redirect=https://example.com"),
  ).not.toContain("redirect");
});
it("keeps partial parents, published Manila and municipal counts distinct", () => {
  expect(populationAreaLabel("region", "13")).toContain("Partial total");
  expect(populationAreaLabel("province", "01028")).toContain("Partial total");
  expect(populationAreaLabel("province", "13806")).toBe("Published Manila city population");
  expect(populationAreaLabel("citymun", "1380601")).toBe("Recorded population");
});
