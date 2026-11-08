import { describe, expect, it, vi } from "vitest";
import {
  VITAL_STATISTICS_DEFINITIONS,
  DEMOGRAPHICS_DEFINITIONS,
  MORBIDITY_DEFINITIONS,
  MORBIDITY_AGES,
  ORAL_HEALTH_DEFINITIONS,
  NCD_DEFINITIONS,
  FAMILY_PLANNING_DEFINITIONS,
  INFECTIOUS_DEFINITIONS,
  MATERNAL_DEFINITIONS,
  CHILD_HEALTH_DEFINITIONS,
  DEFINITIONS,
  validateExpansion,
  loadExpansion,
} from "./load-fhsis-expansion.mjs";
function fixture(programme = "core") {
  const mapping: Record<string, typeof DEFINITIONS> = {
    core: DEFINITIONS,
    "child-health": CHILD_HEALTH_DEFINITIONS,
    maternal: MATERNAL_DEFINITIONS,
    infectious: INFECTIOUS_DEFINITIONS,
    "family-planning": FAMILY_PLANNING_DEFINITIONS,
    ncd: NCD_DEFINITIONS,
    "oral-health": ORAL_HEALTH_DEFINITIONS,
    "vital-statistics": VITAL_STATISTICS_DEFINITIONS,
    demographics: DEMOGRAPHICS_DEFINITIONS,
    "morbidity-01": MORBIDITY_DEFINITIONS.filter((d: { indicator_key: string }) =>
      d.indicator_key.startsWith("morb_01_"),
    ),
  };
  const definitions = structuredClone(mapping[programme]);
  const sources = [
    ...new Set(definitions.map((d: { source_workbook: string }) => d.source_workbook)),
  ].map((file) => ({ file, sha256: "a".repeat(64) }));
  return {
    programme_set: programme === "core" ? undefined : programme,
    format: "bhw-fhsis-expansion-v1",
    dataset_slug: "fhsis-2025",
    retrieved: "2026-11-07",
    definitions,
    sources,
    geographies: [
      { geo_code: "PH", geo_level: "national", geo_name: "PHILIPPINES", parent_code: null },
    ],
    indicators: definitions.map((d: { indicator_key: string; source_workbook: string }) => ({
      geo_code: "PH",
      geo_level: "national",
      indicator_key: d.indicator_key,
      breakdown: "total",
      numerator_exact: "12.5",
      denominator_exact: "100",
      rate_pct: "12.5",
      over_100: false,
      source_file: d.source_workbook,
      source_sheet: "Annual",
      source_row: 7,
    })),
    qa: { indicator_rows: definitions.length, definitions: definitions.length },
  };
}
describe("FHSIS expansion boundary", () => {
  it("accepts demographics with person-per-unit ratios, without a BHW measure", () => {
    const bundle = fixture("demographics");
    expect(bundle.definitions).toHaveLength(8);
    expect(bundle.sources).toHaveLength(1);
    expect(
      bundle.definitions.some((d: { indicator_key: string }) => d.indicator_key.includes("bhw")),
    ).toBe(false);
    expect(() => validateExpansion(bundle)).not.toThrow();
    expect(() => validateExpansion({ ...bundle, programme_set: "vital-statistics" })).toThrow(
      "reviewed mapping",
    );
  });
  it("requires both morbidity sources and the complete age/sex grid", () => {
    const bundle = fixture("morbidity-01");
    bundle.sources.push({
      file: String(bundle.sources[0].file).replace("_Region_", "_Province_"),
      sha256: "b".repeat(64),
    });
    bundle.indicators = bundle.indicators.flatMap((r: (typeof bundle.indicators)[number]) =>
      [
        "total",
        "male",
        "female",
        ...MORBIDITY_AGES.flatMap((age: string) =>
          ["male", "female", "total"].map((sex) => `age:${age}:${sex}`),
        ),
      ].map((breakdown) => ({
        ...r,
        breakdown,
        numerator_exact: "12",
        denominator_exact: null,
        rate_pct: null,
        source_sheet: "Data",
      })),
    ) as typeof bundle.indicators;
    bundle.qa.indicator_rows = bundle.indicators.length;
    expect(() => validateExpansion(bundle)).not.toThrow();
    const copy = structuredClone(bundle);
    copy.indicators.pop();
    copy.qa.indicator_rows--;
    expect(() => validateExpansion(copy)).toThrow("age/sex grid");
    bundle.sources.pop();
    expect(() => validateExpansion(bundle)).toThrow("checksummed workbooks");
  });
  it("accepts the reviewed vital statistics without interpreting rates as percentages", () => {
    const bundle = fixture("vital-statistics");
    expect(bundle.definitions).toHaveLength(11);
    expect(() => validateExpansion(bundle)).not.toThrow();
    expect(() => validateExpansion({ ...bundle, programme_set: "maternal" })).toThrow(
      "reviewed mapping",
    );
  });
  it("accepts the reviewed oral-health package without permitting programme substitution", () => {
    const bundle = fixture("oral-health");
    expect(bundle.sources).toHaveLength(2);
    expect(bundle.definitions).toHaveLength(21);
    expect(() => validateExpansion(bundle)).not.toThrow();
    expect(() => validateExpansion({ ...bundle, programme_set: "ncd" })).toThrow(
      "reviewed mapping",
    );
  });
  it("accepts only the reviewed NCD batch and all nine source workbooks", () => {
    const bundle = fixture("ncd");
    expect(bundle.sources).toHaveLength(9);
    expect(bundle.definitions).toHaveLength(64);
    expect(() => validateExpansion(bundle)).not.toThrow();
    expect(() => validateExpansion({ ...bundle, programme_set: "maternal" })).toThrow(
      "reviewed mapping",
    );
    bundle.sources.pop();
    expect(() => validateExpansion(bundle)).toThrow("checksummed workbooks");
  });
  it("accepts the reviewed family-planning batch but rejects substitution of another programme", () => {
    const bundle = fixture("family-planning");
    expect(bundle.sources).toHaveLength(1);
    expect(bundle.definitions).toHaveLength(81);
    expect(() => validateExpansion(bundle)).not.toThrow();
    expect(() => validateExpansion({ ...bundle, programme_set: "maternal" })).toThrow(
      "reviewed mapping",
    );
  });
  it("accepts the infectious batch without flagging population rates as excess percentage coverage", () => {
    const bundle = fixture("infectious");
    expect(bundle.sources).toHaveLength(8);
    expect(bundle.definitions).toHaveLength(21);
    const populationRow = bundle.indicators.find(
      (r: { indicator_key: string }) => r.indicator_key === "infect_leprosy_new",
    );
    populationRow.rate_pct = "150";
    expect(() => validateExpansion(bundle)).not.toThrow();
    populationRow.over_100 = true;
    expect(() => validateExpansion(bundle)).toThrow("excess-coverage");
  });
  it("validates maternal definitions independently from the other approved batches", () => {
    const maternal = fixture("maternal");
    expect(maternal.sources).toHaveLength(8);
    expect(maternal.definitions).toHaveLength(17);
    expect(() => validateExpansion(maternal)).not.toThrow();
    expect(() => validateExpansion({ ...maternal, programme_set: "child-health" })).toThrow(
      "reviewed mapping",
    );
    maternal.sources.pop();
    expect(() => validateExpansion(maternal)).toThrow("checksummed workbooks");
  });
  it("accepts only the reviewed child-health batch and its five distinct sources", () => {
    const child = fixture("child-health");
    expect(child.sources).toHaveLength(5);
    expect(child.definitions).toHaveLength(12);
    expect(() => validateExpansion(child)).not.toThrow();
    expect(() => validateExpansion({ ...child, programme_set: undefined })).toThrow(
      "reviewed mapping",
    );
    expect(() => validateExpansion({ ...child, programme_set: "unreviewed" })).toThrow(
      "Unknown programme",
    );
    child.sources.push(child.sources[0]);
    expect(() => validateExpansion(child)).toThrow("checksummed workbooks");
  });
  it("requires the reviewed definitions, provenance, unique facts and all national checkpoints", () => {
    expect(() => validateExpansion(fixture())).not.toThrow();
    const altered = fixture();
    altered.definitions[0].label = "Unreviewed";
    expect(() => validateExpansion(altered)).toThrow("reviewed mapping");
    const missing = fixture();
    missing.indicators.pop();
    expect(() => validateExpansion(missing)).toThrow("national checkpoints");
    const duplicate = fixture();
    duplicate.indicators.push(duplicate.indicators[0]);
    expect(() => validateExpansion(duplicate)).toThrow("Duplicate fact");
    const invalid = fixture();
    invalid.indicators[0].numerator_exact = "NaN";
    expect(() => validateExpansion(invalid)).toThrow("numeric");
  });
  it("refuses any overlap with previously loaded expansion facts", async () => {
    const query = vi.fn(async (sql: string) => {
      if (sql.startsWith("SELECT dataset_id")) return { rows: [{ dataset_id: 12 }] };
      if (sql.includes("AS overlap"))
        return { rows: [{ indicators: 100, workforce: 8, overlap: 1 }] };
      return { rows: [] };
    });
    await expect(
      loadExpansion({ query }, fixture(), "a".repeat(64), { apply: true }),
    ).rejects.toThrow("already exist");
    expect(query).toHaveBeenLastCalledWith("ROLLBACK");
    expect(query.mock.calls.some(([sql]) => sql.startsWith("INSERT"))).toBe(false);
  });
});
