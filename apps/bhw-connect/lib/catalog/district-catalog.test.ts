import { expect, it, vi } from "vitest";
import { combineDistrictIndicator, getDistrictCatalog } from "./district-catalog";
import type { CatalogIndicator } from "./types";
import type { CatalogReader } from "./reader";
import type { DistrictGeography } from "./district-geography";
const indicator = (id = "bhw.total", value = 4): CatalogIndicator => ({
  id,
  label: id,
  sourceSlug: "accepted",
  unit: "people",
  state: "available",
  reason: null,
  values: [{ value }],
  reportedAreaRecords: 1,
});
it("sums disjoint counts including zero and reports partial observations without inventing zeros", () => {
  expect(
    combineDistrictIndicator([indicator(), indicator("bhw.total", 0)], false, true),
  ).toMatchObject({
    state: "available",
    values: [{ value: 4 }],
    coverage: { covered: 2, total: 2 },
  });
  expect(
    combineDistrictIndicator(
      [indicator(), { ...indicator(), state: "unavailable", values: [] }],
      false,
      true,
    ),
  ).toMatchObject({ state: "partial", values: [{ value: 4 }], coverage: { covered: 1, total: 2 } });
  expect(combineDistrictIndicator([indicator()], true, false)).toMatchObject({
    state: "partial",
    reason: expect.stringContaining("Mapping gaps"),
  });
});
it("recomputes approved BHW ratios from complete numerators/denominators instead of averaging", () => {
  const a = {
    ...indicator("bhw.profile-accreditation"),
    unit: "%",
    values: [{ value: 50, numerator: 1, denominator: 2, ciLow: 0, ciHigh: 100 }],
  };
  const b = { ...a, values: [{ value: 100, numerator: 8, denominator: 8 }] };
  expect(combineDistrictIndicator([a, b], false, false)).toMatchObject({
    values: [{ value: 90, numerator: 9, denominator: 10 }],
  });
  expect(combineDistrictIndicator([a, b], false, false).values[0]).not.toHaveProperty("ciLow");
  expect(combineDistrictIndicator([a, b], true, false).values).toEqual([]);
  expect(
    combineDistrictIndicator(
      [{ ...a, values: [{ value: 0, numerator: 0, denominator: 0 }] }],
      false,
      false,
    ).values,
  ).toEqual([]);
});
it("does not aggregate unsupported rates, poverty, incomplete population or split-city municipal figures", () => {
  for (const id of ["poverty.incidence", "fhsis.fic", "bhw.active-years", "bhw.any-honorarium"])
    expect(combineDistrictIndicator([indicator(id), indicator(id)], false, false).state).toBe(
      "unsupported",
    );
  expect(
    combineDistrictIndicator(
      [
        indicator("population.residents"),
        { ...indicator("population.residents"), state: "unsupported", values: [] },
      ],
      false,
      true,
    ).state,
  ).toBe("unsupported");
  expect(combineDistrictIndicator([indicator("population.residents")], true, false).state).toBe(
    "unavailable",
  );
  expect(
    combineDistrictIndicator(
      [indicator("population.residents", 10), indicator("population.residents", 20)],
      false,
      false,
    ).values,
  ).toEqual([{ value: 30 }]);
});
it("fails closed for protected, failed, negative and incompatible constituent values", () => {
  expect(
    combineDistrictIndicator(
      [indicator(), { ...indicator(), state: "suppressed", values: [] }],
      false,
      false,
    ),
  ).toMatchObject({ state: "suppressed", values: [] });
  expect(
    combineDistrictIndicator(
      [indicator(), { ...indicator(), state: "failed", values: [] }],
      false,
      false,
    ),
  ).toMatchObject({ state: "failed", values: [] });
  expect(
    combineDistrictIndicator([indicator(), indicator("bhw.total", -1)], false, false).state,
  ).toBe("failed");
  expect(
    combineDistrictIndicator([indicator(), { ...indicator(), sourceSlug: "other" }], false, false)
      .state,
  ).toBe("failed");
});
const geography: Extract<DistrictGeography, { state: "available" }> = {
  state: "available",
  choices: [],
  current: { code: "d1", name: "District 1", congress: 20 },
  selection: { mode: "district", districtCode: "d1" },
  receipts: [],
  ownGaps: 0,
  globalGaps: { cities: 0, barangays: 0 },
  scope: {
    issues: [],
    cities: [],
    units: [
      { code: "01001", name: "Whole", level: "citymun", parentCode: "01" },
      { code: "01002001", name: "Part", level: "barangay", parentCode: "01002" },
    ],
  },
};
const reader = (): CatalogReader => ({
  source: async (slug) => ({
    state: "available",
    value: {
      datasetId: 1,
      slug,
      name: slug,
      sourceName: slug,
      license: null,
      asOfDate: "2026-01-01",
      lastUpdatedAt: "2026-01-01",
    },
  }),
  privacy: async () => "clear",
  probe: async () => ({ state: "available", count: 0, hasSuppressed: false }),
  fhsisDefinitions: async () => ({ state: "available", rows: [] }),
  rows: vi.fn(async (spec, _id, selection) => ({
    state: "available" as const,
    rows:
      spec.table === "agg_bhw_stepzero_counts"
        ? [
            {
              n_total_bhw: selection.code === "01001" ? 10 : 2,
              n_registered: 0,
              n_registered_accredited: 0,
              n_non_registered: 0,
              pct_registered_accredited: 0,
            },
          ]
        : [],
  })),
});
it("retains district scope, never reads the rest of a split city, and does not emit whole-city links", async () => {
  const db = reader();
  const catalog = await getDistrictCatalog(geography, db);
  expect(catalog.selection).toEqual(geography.selection);
  expect(catalog.datasets[0].indicators.find((i) => i.id === "bhw.total")).toMatchObject({
    values: [{ value: 12 }],
  });
  expect(catalog.datasets.every((d) => d.detailHref === null)).toBe(true);
  expect(vi.mocked(db.rows).mock.calls.some(([, , selection]) => selection.code === "01002")).toBe(
    false,
  );
});
it("blocks unresolved membership before reading any figures", async () => {
  const db = reader();
  const catalog = await getDistrictCatalog(
    { ...geography, scope: { ...geography.scope, issues: ["conflict"] } },
    db,
  );
  expect(catalog.overview.availableIndicators).toBe(0);
  expect(db.rows).not.toHaveBeenCalled();
});
it("permits a source's published measure for one exact mapped member without creating a district estimate", async () => {
  const db = reader();
  const unit = geography.scope.units[0];
  const catalog = await getDistrictCatalog(
    { ...geography, scope: { ...geography.scope, units: [unit] } },
    db,
  );
  expect(catalog.datasets[0].indicators[0].reason).toContain("exact mapped constituent");
});
