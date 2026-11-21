import { expect, it } from "vitest";
import { nhfrOverviewHref, restoredNhfrMapHref } from "./nhfr-navigation";
import { catalogJourneyHref } from "./journey";
import { mapDetailsHref } from "./map-details-navigation";
import { initialMapChoice } from "@/lib/maps/prototype";

it("opens national figures and retains independent validated map scope", () => {
  const raw = "dataset=nhfr-2026-09&indicator=nhfr.facilities&level=region&code=01&selected=01028";
  const href = catalogJourneyHref({ mode: "all" }, "nhfr", raw);
  const params = new URLSearchParams(href.split("?")[1]);
  expect(params.get("location")).toBe("national:PH");
  expect(restoredNhfrMapHref(params.get("mapState")!)).toBe(restoredNhfrMapHref(raw));
  const scoped = catalogJourneyHref(
    { mode: "psgc", level: "citymun", code: "0102801" },
    "nhfr",
    raw,
  );
  expect(new URLSearchParams(scoped.split("?")[1]).get("location")).toBe("citymun:0102801");
  expect(scoped).toContain("mapState=");
});
it("rejects foreign datasets, arbitrary URLs and duplicate context", () => {
  for (const raw of [
    "https://example.test",
    "dataset=bhw-2025",
    ["dataset=nhfr-2026-09"],
    "x".repeat(4097),
  ]) {
    expect(restoredNhfrMapHref(raw)).toBeNull();
    expect(nhfrOverviewHref(raw)).toBe(
      "/catalog?mode=psgc&location=national%3APH&dataset=nhfr&mapState=",
    );
  }
});
it("opens the selected map child, not its parent", () => {
  const href = mapDetailsHref({ ...initialMapChoice("nhfr-2026-09"), selected: "01" }, "nhfr");
  expect(new URLSearchParams(href.split("?")[1]).get("location")).toBe("region:01");
});
