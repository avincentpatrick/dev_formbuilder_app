import { describe, expect, it } from "vitest";
import { officialPovertyIndicator } from "./poverty-official";
import { povertyCoverage } from "./poverty-coverage";
import type { CatalogIndicator } from "./types";
const item: CatalogIndicator = {
  id: "poverty.official-incidence",
  label: "Official",
  unit: "%",
  sourceSlug: "psa-official-poverty-2023-r2",
  state: "unavailable",
  reason: null,
  values: [],
  reportedAreaRecords: null,
};
const row = {
  reference_year: 2023,
  poverty_incidence: 0,
  ci_low: -0.3,
  ci_high: 2.4,
  cv: 12,
  source_label: "Synthetic reporting area",
  source_table: "tab2",
  boundary_note: "Synthetic source boundaries differ from the map.",
  publication_note: "Synthetic precision caution.",
  value_status: "available",
  is_suppressed: false,
};
describe("official source contract", () => {
  it("preserves zero, unclipped CI and the boundary/precision explanation", () => {
    const result = officialPovertyIndicator([row], item);
    expect(result.state).toBe("available");
    expect(result.values[0]).toEqual({ value: 0, ciLow: -0.3, ciHigh: 2.4 });
    expect(result.reason).toContain("boundaries differ");
    expect(result.reason).toContain("exceeds 10%");
  });
  it.each(["unavailable", "not_absolute_zero", "suppressed"])(
    "does not expose numbers for %s",
    (value_status) => {
      const result = officialPovertyIndicator(
        [
          {
            ...row,
            value_status,
            is_suppressed: value_status === "suppressed",
            poverty_incidence: 42,
          },
        ],
        item,
      );
      expect(result.values).toEqual([]);
      expect(result.state).toBe(value_status === "suppressed" ? "suppressed" : "unavailable");
      if (value_status === "not_absolute_zero")
        expect(result.reason).toContain("not absolute zero");
    },
  );
  it.each([
    { source_table: "tab1" },
    { boundary_note: "" },
    { poverty_incidence: 101 },
    { ci_low: 10, ci_high: 2 },
    { cv: NaN },
    { value_status: "guessed" },
  ])("fails closed on %j", (patch) =>
    expect(officialPovertyIndicator([{ ...row, ...patch }], item).state).toBe("failed"),
  );
  it("rejects duplicate records and never substitutes another year", () => {
    expect(officialPovertyIndicator([row, row], item).state).toBe("failed");
    expect(officialPovertyIndicator([{ ...row, reference_year: 2025 }], item).values).toEqual([]);
    expect(officialPovertyIndicator([], item).reason).toContain("does not establish");
  });
});
it("coverage counts zero but not missing or invalid estimates, and distinguishes failures", () => {
  const payload = {
    state: "available",
    childLevel: "citymun",
    valueSupport: "supported",
    rows: [{ value: 0 }, { value: null }, { value: 14 }, { value: NaN }],
  };
  expect(povertyCoverage(payload)).toBe("2 of 4 cities/municipalities have usable SAE estimates");
  expect(povertyCoverage({ state: "failed" })).toContain("could not be loaded");
  expect(povertyCoverage({ state: "suppressed" })).toBe("Withheld");
  const navigation = { ...payload, valueSupport: "navigation-only" };
  expect(povertyCoverage(navigation)).toContain("could not be verified");
});
