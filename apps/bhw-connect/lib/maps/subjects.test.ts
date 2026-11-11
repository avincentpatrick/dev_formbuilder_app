import { describe, expect, it } from "vitest";
import { DATASET_MAPS } from "./definitions";
import { parseMapChoice } from "./prototype";
import { MAP_SUBJECTS, subjectMapHref, subjectOfRelease, subjectReleases } from "./subjects";

const params = (href: string) => new URLSearchParams(href.split("?")[1]);

describe("map subjects", () => {
  it("files every map release under exactly one dataset, except the empty roadmap slot", () => {
    for (const d of DATASET_MAPS) {
      const owners = MAP_SUBJECTS.filter((s) => s.releases.includes(d.slug));
      expect(owners.length, d.slug).toBe(d.slug === "ways-forward" ? 0 : 1);
    }
  });

  it("gives BHW the headcount first, then the profiles, and leaves the hidden 2026 release out", () => {
    expect(subjectReleases(MAP_SUBJECTS.find((s) => s.id === "bhw")!)).toEqual([
      "bhw-stepzero-2026",
      "bhw-2025",
    ]);
    expect(subjectOfRelease("bhw-2025")?.title).toBe("BHW");
  });

  it("opens a dataset's map at the place chosen on the catalog, as a link the map page accepts", () => {
    const national = subjectMapHref("bhw", { mode: "all" })!;
    expect(parseMapChoice(params(national))).toMatchObject({ dataset: "bhw-stepzero-2026", level: "national", code: "PH" });
    const region = subjectMapHref("uuc-phc", { mode: "psgc", level: "region", code: "01" })!;
    expect(parseMapChoice(params(region))).toMatchObject({ dataset: "uuc-phc-2025", level: "region", code: "01" });
    const district = subjectMapHref("nhfr", { mode: "district", districtCode: "x" })!;
    expect(parseMapChoice(params(district))).toMatchObject({ level: "national" });
  });

  it("opens a barangay inside its municipality or, without barangay figures, its province", () => {
    const href = subjectMapHref("population", { mode: "psgc", level: "barangay", code: "0102801001" })!;
    const choice = parseMapChoice(params(href))!;
    expect(choice).not.toBeNull();
    expect(["citymun", "province"]).toContain(choice.level);
    expect(choice.code).toBe(choice.level === "citymun" ? "0102801" : "01028");
  });
});
