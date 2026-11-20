import { expect, it } from "vitest";
import {
  povertyMapChoice,
  povertyCatalogReturnHref,
  restoredPovertyMapHref,
} from "./poverty-navigation";
import { mapHref } from "@/lib/maps/prototype";
import { catalogJourneyHref } from "./journey";
it("keeps a selected municipality and complete validated map settings independently of catalog scope", () => {
  const choice = { ...povertyMapChoice("01028", "0102801"), view: "choropleth" as const };
  const params = new URLSearchParams(povertyCatalogReturnHref(choice).split("?")[1]);
  expect(params.get("location")).toBe("citymun:0102801");
  expect(params.get("dataset")).toBe("poverty");
  expect(restoredPovertyMapHref(params.get("mapState")!)).toBe(mapHref(choice));
  const ancestor = new URLSearchParams(
    catalogJourneyHref(
      { mode: "psgc", level: "province", code: "01028" },
      "poverty",
      params.get("mapState")!,
    ).split("?")[1],
  );
  expect(ancestor.get("location")).toBe("province:01028");
  expect(ancestor.get("mapState")).toBe(params.get("mapState"));
  expect(catalogJourneyHref({ mode: "all" }, "population", params.get("mapState")!)).not.toContain(
    "mapState",
  );
});
it("derives catalog level from map scope, including navigation-only selections", () => {
  for (const [level, code, selected, expected] of [
    ["national", "PH", null, "national:PH"],
    ["national", "PH", "01", "region:01"],
    ["region", "01", "01028", "province:01028"],
    ["province", "01028", null, "province:01028"],
  ] as const) {
    expect(
      new URLSearchParams(
        povertyCatalogReturnHref({ ...povertyMapChoice("01028"), level, code, selected }).split(
          "?",
        )[1],
      ).get("location"),
    ).toBe(expected);
  }
});
it("rejects other releases, duplicate values, arbitrary URLs and invalid filters", () => {
  for (const raw of [
    undefined,
    [],
    "",
    "javascript:alert(1)",
    "https://example.com/map?dataset=psa-sae-poverty-2023",
    "dataset=bhw-2025",
    "dataset=psa-sae-poverty-2023&dataset=bhw-2025",
    "dataset=psa-sae-poverty-2023&valueMin=-1",
    "x".repeat(4097),
  ])
    expect(restoredPovertyMapHref(raw)).toBeNull();
  expect(
    restoredPovertyMapHref("dataset=psa-sae-poverty-2023&redirect=https://example.com"),
  ).not.toContain("redirect");
});
it("retains a bounded province-specific list search across the map round trip", () => {
  const choice = povertyMapChoice("01028", "0102801");
  const search = "&povertyProvince=01028&povertySearch=Adams";
  const params = new URLSearchParams(povertyCatalogReturnHref(choice, search).split("?")[1]);
  expect(params.get("povertySearch")).toBe("Adams");
  expect(restoredPovertyMapHref(params.get("mapState")!)).toBe(mapHref(choice) + search);
});
