import { describe, expect, it } from "vitest";
import { DATASET_MAPS, getDatasetMap, validMapChoice } from "./definitions";

describe("independent dataset map contracts", () => {
  it("separates the two BHW releases and refuses cross-dataset URL choices", () => {
    expect(
      getDatasetMap("bhw-2025")?.indicators.every((i) => i.catalogSpec?.source === "bhw-2025"),
    ).toBe(true);
    expect(validMapChoice("bhw-2025", "bhw.total", "2026", "region")).toBe(false);
    expect(validMapChoice("bhw-stepzero-2026", "bhw.total", "2026", "barangay")).toBe(true);
    expect(getDatasetMap("unknown")).toBeUndefined();
  });
  it("preserves partial periods and refuses invented barangay rates", () => {
    expect(validMapChoice("fhsis-2025", "fhsis.anc8", "2025", "citymun")).toBe(false);
    expect(validMapChoice("fhsis-2025", "fhsis.anc8", "Q3–Q4 2025", "citymun")).toBe(true);
    expect(validMapChoice("fhsis-2025", "fhsis.fic", "2025", "barangay")).toBe(false);
    expect(validMapChoice("psa-sae-poverty-2023", "poverty.incidence", "2023", "region")).toBe(
      false,
    );
  });
  it("registers planned datasets without claiming verified boundaries or values", () => {
    expect(DATASET_MAPS).toHaveLength(11);
    expect(new Set(DATASET_MAPS.map((d) => d.slug)).size).toBe(DATASET_MAPS.length);
    for (const d of DATASET_MAPS) {
      expect(d.boundaryVersion).toBeNull();
      expect(d.dependencies.length).toBeGreaterThan(0);
      if (d.defaultIndicator)
        expect(d.indicators.some((i) => i.id === d.defaultIndicator)).toBe(true);
      for (const i of d.indicators) expect(i.period).toBeTruthy();
    }
    expect(getDatasetMap("ways-forward")?.indicators).toEqual([]);
    expect(getDatasetMap("lgu-income-class")?.indicators[0].legend).toBe("categorical");
  });
});
