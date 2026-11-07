import { describe, expect, it, vi } from "vitest";
import { CADRES, INDICATORS, loadBundle, validateBundle } from "./load-fhsis.mjs";

function fixture() {
  const base = {
    geo_code: "PH",
    geo_level: "national",
    source_file: "synthetic.xlsx",
    source_sheet: "Annual",
    source_row: 7,
  };
  return {
    format: "bhw-fhsis-2025-v1",
    dataset_slug: "fhsis-2025",
    retrieved: "2026-11-07",
    sources: Array.from({ length: 7 }, (_, i) => ({
      file: `synthetic-${i}.xlsx`,
      sha256: "a".repeat(64),
    })),
    geographies: [
      { geo_code: "PH", geo_level: "national", geo_name: "PHILIPPINES", parent_code: null },
    ],
    indicators: INDICATORS.map((indicator_key: string) => ({
      ...base,
      indicator_key,
      breakdown: "total",
      numerator_exact: "2.125",
      denominator_exact: "10.5",
      rate_pct: "20.238",
      over_100: false,
    })),
    workforce: CADRES.map((cadre: string) => ({
      ...base,
      cadre,
      lgu_hired: 1,
      doh_hired: 0,
      total: 1,
      population_2025: 100,
      households_2025: 20,
    })),
    qa: { indicator_rows: 20, workforce_rows: 8 },
  };
}
describe("private FHSIS first load", () => {
  it("accepts exact decimal strings and source negatives, but rejects non-finite values", () => {
    const b = fixture();
    expect(() => validateBundle(b)).not.toThrow();
    b.indicators[0].denominator_exact = "-1.25";
    expect(() => validateBundle(b)).not.toThrow();
    b.indicators[0].denominator_exact = "NaN";
    expect(() => validateBundle(b)).toThrow("decimal");
  });
  it("refuses BHW counts, duplicate keys, incorrect geography, and invented overshoot flags", () => {
    const bhw = fixture();
    bhw.workforce[0].cadre = "bhw";
    expect(() => validateBundle(bhw)).toThrow("BHW is excluded");
    const dup = fixture();
    dup.indicators.push(dup.indicators[0]);
    expect(() => validateBundle(dup)).toThrow("Duplicate fact");
    const geo = fixture();
    geo.indicators[0].geo_level = "province";
    expect(() => validateBundle(geo)).toThrow("geography");
    const flag = fixture();
    flag.indicators[0].over_100 = true;
    expect(() => validateBundle(flag)).toThrow("percentage flag");
  });
  it("requires reported services and all national checkpoints", () => {
    const empty = fixture();
    Object.assign(empty.indicators[0], { numerator_exact: null, rate_pct: null });
    expect(() => validateBundle(empty)).toThrow("Denominator-only");
    const incomplete = fixture();
    incomplete.indicators.pop();
    expect(() => validateBundle(incomplete)).toThrow("national checkpoints");
  });
  it("rolls back before inserting if the dataset already has facts", async () => {
    const query = vi.fn(async (sql: string) => {
      if (sql.startsWith("SELECT dataset_id"))
        return { rows: [{ dataset_id: 123, status: "published" }] };
      if (sql.includes("AS indicators")) return { rows: [{ indicators: 1, workforce: 0 }] };
      return { rows: [] };
    });
    await expect(loadBundle({ query }, fixture(), "a".repeat(64), { apply: true })).rejects.toThrow(
      "already contains facts",
    );
    expect(query).toHaveBeenLastCalledWith("ROLLBACK");
    expect(query.mock.calls.some(([sql]) => sql.startsWith("INSERT"))).toBe(false);
  });
});
