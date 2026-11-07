import { beforeEach, describe, expect, it, vi } from "vitest";
import { getCatalogGeography } from "./geography";
const mock = vi.hoisted(() => ({ client: vi.fn() }));
vi.mock("@/lib/db/supabase", () => ({ createSupabaseServerClient: mock.client }));
const rows = [
  { geo_code: "01", geo_name: "Region", geo_level: "region", parent_code: null },
  { geo_code: "01001", geo_name: "Province", geo_level: "province", parent_code: "01" },
  { geo_code: "0100101", geo_name: "Town", geo_level: "citymun", parent_code: "01001" },
  { geo_code: "0109901", geo_name: "Independent city", geo_level: "citymun", parent_code: "01" },
];
let fail = false;
const queries: { filters: Record<string, unknown>; levels?: readonly string[] }[] = [];
beforeEach(() => {
  fail = false;
  queries.length = 0;
  mock.client.mockImplementation(() => ({
    from: () => {
      const state = {
        filters: {} as Record<string, unknown>,
        levels: undefined as readonly string[] | undefined,
      };
      queries.push(state);
      const read = () =>
        rows.filter(
          (row) =>
            Object.entries(state.filters).every(
              ([key, value]) => row[key as keyof typeof row] === value,
            ) &&
            (!state.levels || state.levels.includes(row.geo_level)),
        );
      const q = {
        select: () => q,
        order: () => q,
        range: () => q,
        in: (_: string, levels: readonly string[]) => {
          state.levels = levels;
          return q;
        },
        eq: (key: string, value: unknown) => {
          state.filters[key] = value;
          return q;
        },
        maybeSingle: async () => ({ data: read()[0] ?? null, error: fail ? {} : null }),
        then: (resolve: (v: unknown) => unknown) =>
          Promise.resolve(resolve({ data: read(), error: fail ? {} : null })),
      };
      return q;
    },
  }));
});
describe("catalog hierarchy", () => {
  it("lists both direct province and independent city children without inventing a province", async () => {
    const result = await getCatalogGeography({ mode: "psgc", level: "region", code: "01" });
    expect(result).toMatchObject({
      state: "available",
      children: [{ level: "province" }, { level: "citymun" }],
    });
    expect(queries.at(-1)?.filters).toEqual({ parent_code: "01" });
  });
  it("follows the actual ancestors of a province-less city", async () => {
    const result = await getCatalogGeography({ mode: "psgc", level: "citymun", code: "0109901" });
    expect(result).toMatchObject({
      state: "available",
      ancestors: [{ code: "PH" }, { code: "01" }],
      children: [],
    });
  });
  it("rejects a code/level mismatch and distinguishes failed queries", async () => {
    expect(await getCatalogGeography({ mode: "psgc", level: "province", code: "01" })).toEqual({
      state: "not-found",
    });
    fail = true;
    expect(await getCatalogGeography({ mode: "all" })).toEqual({ state: "failed" });
  });
});
