import { describe, expect, it, vi } from "vitest";
import { DEFINITIONS, validateExpansion, loadExpansion } from "./load-fhsis-expansion.mjs";
function fixture() {
  const definitions = structuredClone(DEFINITIONS);
  const sources = [
    ...new Set(definitions.map((d: { source_workbook: string }) => d.source_workbook)),
  ].map((file) => ({ file, sha256: "a".repeat(64) }));
  return {
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
