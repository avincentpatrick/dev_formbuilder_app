import { describe, expect, it, vi } from "vitest";
import {
  INFECTIOUS_DEFINITIONS,
  MATERNAL_DEFINITIONS,
  CHILD_HEALTH_DEFINITIONS,
  DEFINITIONS,
  validateExpansion,
  loadExpansion,
} from "./load-fhsis-expansion.mjs";
function fixture(child = false, maternal = false, infectious = false) {
  const definitions = structuredClone(
    infectious
      ? INFECTIOUS_DEFINITIONS
      : maternal
        ? MATERNAL_DEFINITIONS
        : child
          ? CHILD_HEALTH_DEFINITIONS
          : DEFINITIONS,
  );
  const sources = [
    ...new Set(definitions.map((d: { source_workbook: string }) => d.source_workbook)),
  ].map((file) => ({ file, sha256: "a".repeat(64) }));
  return {
    programme_set: infectious
      ? "infectious"
      : maternal
        ? "maternal"
        : child
          ? "child-health"
          : undefined,
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
  it("accepts the infectious batch without flagging population rates as excess percentage coverage", () => {
    const bundle = fixture(false, false, true);
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
    const maternal = fixture(false, true);
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
    const child = fixture(true);
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
