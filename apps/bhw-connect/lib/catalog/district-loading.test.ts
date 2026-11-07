import { beforeEach, expect, it, vi } from "vitest";
import { getDistrictGeography } from "./district-geography";
const mock = vi.hoisted(() => ({ client: vi.fn() }));
vi.mock("@/lib/db/supabase", () => ({ createSupabaseServerClient: mock.client }));
const membership = {
  id: 1,
  district_code: "d1",
  geo_code: "01001001",
  geo_level: "barangay",
  status: "auto",
  superseded_by: null,
  source_kind: "manual",
  source_ref: "synthetic",
  retrieved_at: "2026-01-01",
  corroboration: "single_source",
};
let copies = 1;
let fail = false;
const filters: { table: string; key: string; value: unknown }[] = [];
beforeEach(() => {
  copies = 1;
  fail = false;
  filters.length = 0;
  mock.client.mockImplementation(() => ({
    rpc: async () => ({
      data: fail ? null : [{ uncovered_citymun_count: 0, unplaced_barangay_count: 0 }],
      error: fail ? {} : null,
    }),
    from(table: string) {
      const conditions: ((r: Record<string, unknown>) => boolean)[] = [];
      let start = 0,
        end = 999;
      const q = {
        select: () => q,
        order: () => q,
        range(a: number, b: number) {
          start = a;
          end = b;
          return q;
        },
        eq(key: string, value: unknown) {
          conditions.push((r) => r[key] === value);
          return q;
        },
        neq(key: string, value: unknown) {
          filters.push({ table, key, value });
          conditions.push((r) => r[key] !== value);
          return q;
        },
        is(key: string, value: unknown) {
          filters.push({ table, key, value });
          conditions.push((r) => r[key] === value);
          return q;
        },
        in(key: string, values: unknown[]) {
          conditions.push((r) => values.includes(r[key]));
          return q;
        },
        then(resolve: (v: unknown) => unknown) {
          const rows =
            table === "dim_legislative_district"
              ? [
                  {
                    district_code: "d1",
                    district_name: "District",
                    congress_no: 20,
                    status: "auto",
                  },
                ]
              : table === "geo_district_map"
                ? Array.from({ length: copies }, (_, id) => ({ ...membership, id }))
                : [
                    {
                      geo_code: "01001",
                      geo_name: "City",
                      geo_level: "citymun",
                      parent_code: "01",
                    },
                    {
                      geo_code: "01001001",
                      geo_name: "Included",
                      geo_level: "barangay",
                      parent_code: "01001",
                    },
                    {
                      geo_code: "01001002",
                      geo_name: "Outside",
                      geo_level: "barangay",
                      parent_code: "01001",
                    },
                  ];
          return Promise.resolve(
            resolve({
              data: rows.filter((r) => conditions.every((c) => c(r))).slice(start, end + 1),
              error: fail ? {} : null,
            }),
          );
        },
      };
      return q;
    },
  }));
});
it("uses only live accepted mapping rows, paginates, and rejects an outside barangay", async () => {
  copies = 1001;
  const read = await getDistrictGeography({
    mode: "district",
    districtCode: "d1",
    citymunCode: "01001",
  });
  expect(read).toMatchObject({ state: "available", scope: { units: [{ code: "01001001" }] } });
  if (read.state === "available") expect(read.receipts).toHaveLength(1001);
  expect(filters).toContainEqual({ table: "geo_district_map", key: "status", value: "rejected" });
  expect(filters).toContainEqual({ table: "geo_district_map", key: "superseded_by", value: null });
  expect(
    await getDistrictGeography({
      mode: "district",
      districtCode: "d1",
      citymunCode: "01001",
      barangayCode: "01001002",
    }),
  ).toEqual({ state: "not-found" });
});
it("distinguishes a loading failure from an unknown district", async () => {
  expect(await getDistrictGeography({ mode: "district", districtCode: "missing" })).toEqual({
    state: "not-found",
  });
  fail = true;
  expect(await getDistrictGeography({ mode: "district", districtCode: "d1" })).toEqual({
    state: "failed",
  });
});
