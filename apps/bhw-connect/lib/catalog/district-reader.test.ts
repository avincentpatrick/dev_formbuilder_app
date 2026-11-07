import { beforeEach, expect, it, vi } from "vitest";
import { createDistrictReader } from "./district-reader";
import { CATALOG } from "./definitions";
import type { DistrictScope } from "./district-geography";
const mock = vi.hoisted(() => ({ client: vi.fn() }));
vi.mock("@/lib/db/supabase", () => ({ createSupabaseServerClient: mock.client }));
const spec = (id: string) => CATALOG.flatMap((d) => d.indicators).find((i) => i.id === id)!;
type Query = {
  table: string;
  projection: string;
  filters: Record<string, unknown>;
  codes: string[];
  limit: number;
};
let calls: Query[] = [];
let fail = false;
let truncate = false;
beforeEach(() => {
  calls = [];
  fail = false;
  truncate = false;
  mock.client.mockImplementation(() => ({
    from(table: string) {
      const state: Query = { table, projection: "", filters: {}, codes: [], limit: 0 };
      calls.push(state);
      const q = {
        select(p: string) {
          state.projection = p;
          return q;
        },
        eq(k: string, v: unknown) {
          state.filters[k] = v;
          return q;
        },
        in(_k: string, codes: string[]) {
          state.codes = codes;
          return q;
        },
        limit(n: number) {
          state.limit = n;
          return q;
        },
        order: () => q,
        range: () => q,
        then(resolve: (v: unknown) => unknown) {
          const data =
            table === "fact_nhfr_facility"
              ? state.limit === 1
                ? [{ dataset_id: 11 }]
                : [
                    {
                      barangay_geo_code: "01001001",
                      facility_type: "Hospital",
                      ownership_major: "Government",
                      bed_capacity: 4,
                    },
                    {
                      barangay_geo_code: "01001002",
                      facility_type: "Hospital",
                      ownership_major: "Private",
                      bed_capacity: 8,
                    },
                    {
                      barangay_geo_code: "01001003",
                      facility_type: "Hospital",
                      ownership_major: "Private",
                      bed_capacity: 99,
                    },
                    {
                      barangay_geo_code: null,
                      facility_type: "Hospital",
                      ownership_major: "Private",
                      bed_capacity: 99,
                    },
                  ]
              : state.codes.map((code) => ({ geo_code: code, n_total_bhw: 1 }));
          return Promise.resolve(
            resolve({ data: truncate ? Array(1000).fill(data[0]) : data, error: fail ? {} : null }),
          );
        },
      };
      return q;
    },
  }));
});
it("batches ten exact peer codes and reuses each table read without reading raw BHWs", async () => {
  const scope: DistrictScope = {
    cities: [],
    issues: [],
    units: Array.from({ length: 11 }, (_, i) => ({
      code: `010${i}`,
      name: `City ${i}`,
      level: "citymun",
      parentCode: "01",
    })),
  };
  const reader = createDistrictReader(scope);
  for (const unit of scope.units)
    await reader.rows(spec("bhw.total"), 42, { mode: "psgc", level: unit.level, code: unit.code });
  expect(calls).toHaveLength(2);
  expect(calls[0].codes).toHaveLength(10);
  expect(calls[1].codes).toEqual(["01010"]);
  expect(
    calls.every(
      (q) =>
        q.table === "agg_bhw_stepzero_counts" &&
        q.filters.dataset_id === 42 &&
        q.filters.geo_level === "citymun",
    ),
  ).toBe(true);
  expect(calls[0].projection).not.toContain("*");
  expect(
    await reader.rows(spec("bhw.total"), 42, { mode: "psgc", level: "citymun", code: "outside" }),
  ).toEqual({ state: "failed" });
});
it("shares municipal facility attribution but excludes other district barangays from values", async () => {
  const barangays = ["01001001", "01001002", "01001003"].map((code) => ({
    code,
    name: code,
    level: "barangay" as const,
    parentCode: "01001",
  }));
  const scope: DistrictScope = {
    issues: [],
    units: barangays.slice(0, 2),
    cities: [
      {
        code: "01001",
        name: "Split",
        level: "citymun",
        parentCode: "01",
        whole: false,
        barangays: barangays.slice(0, 2),
        allBarangays: barangays,
      },
    ],
  };
  const reader = createDistrictReader(scope);
  const results = await Promise.all(
    scope.units.map((unit) =>
      reader.rows(spec("nhfr.facilities"), 11, {
        mode: "psgc",
        level: unit.level,
        code: unit.code,
      }),
    ),
  );
  expect(results[0]).toMatchObject({
    rows: [{ n_facilities: 1, total_bed_capacity: 4 }],
    note: { partial: true, coverage: { covered: 3, total: 4 } },
  });
  expect(results[1]).toMatchObject({ rows: [{ n_facilities: 1, total_bed_capacity: 8 }] });
  expect(calls).toHaveLength(2);
});
it("fails closed on a failed or potentially truncated aggregate batch", async () => {
  const scope: DistrictScope = {
    cities: [],
    issues: [],
    units: [{ code: "01001", name: "City", level: "citymun", parentCode: "01" }],
  };
  fail = true;
  expect(
    await createDistrictReader(scope).rows(spec("bhw.total"), 42, {
      mode: "psgc",
      level: "citymun",
      code: "01001",
    }),
  ).toEqual({ state: "failed" });
  fail = false;
  truncate = true;
  expect(
    await createDistrictReader(scope).rows(spec("bhw.total"), 42, {
      mode: "psgc",
      level: "citymun",
      code: "01001",
    }),
  ).toEqual({ state: "failed" });
});
