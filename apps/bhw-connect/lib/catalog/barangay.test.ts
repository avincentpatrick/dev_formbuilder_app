import { beforeEach, describe, expect, it, vi } from "vitest";
import { attributedFacilities, barangayMembership, createBarangayReader } from "./barangay";
const mock = vi.hoisted(() => ({ client: vi.fn() }));
vi.mock("@/lib/db/supabase", () => ({ createSupabaseServerClient: mock.client }));
const facility = { facility_type: "Hospital", ownership_major: "Government", bed_capacity: 4 };

describe("barangay calculations", () => {
  it("counts only attributed facilities and sums recorded beds with coverage lineage", () => {
    expect(attributedFacilities([facility], 3, 2)).toMatchObject({
      state: "available",
      rows: [
        {
          n_facilities: 1,
          n_hospital: 1,
          total_bed_capacity: 4,
          n_barangays_with_facility: 1,
          n_barangays: 1,
        },
      ],
      note: { partial: true, coverage: { covered: 1, total: 3 } },
    });
    expect(attributedFacilities([facility], 1, 0)).toMatchObject({
      note: { partial: false, coverage: { covered: 1, total: 1 } },
    });
    expect(attributedFacilities([], 3, 2)).toMatchObject({
      rows: [{ n_facilities: 0, total_bed_capacity: 0 }],
      note: { partial: true },
    });
    expect(attributedFacilities([], 0, 0)).toMatchObject({
      rows: [{ n_facilities: 0 }],
      note: { partial: false },
    });
  });
  it("fails closed on inconsistent coverage or invalid bed counts", () => {
    expect(attributedFacilities([facility], 1, 1)).toEqual({ state: "failed" });
    expect(attributedFacilities([{ ...facility, bed_capacity: null }], 1, 0)).toEqual({
      state: "failed",
    });
  });
  it("distinguishes not listed, missing qualification and an unevaluable health route", () => {
    expect(barangayMembership(false, undefined, "agg_uuc_phc_counts")).toMatchObject({
      rows: [{ n_listed: 0, n_barangays: 1 }],
    });
    expect(barangayMembership(false, undefined, "agg_uuc_phc_criteria")).toMatchObject({
      rows: [],
    });
    expect(barangayMembership(true, undefined, "agg_uuc_phc_criteria")).toMatchObject({ rows: [] });
    expect(
      barangayMembership(
        true,
        {
          route_ip: true,
          route_conflict: false,
          route_four_ps: false,
          route_health: false,
          health_evaluable: false,
        },
        "agg_uuc_phc_criteria",
      ),
    ).toMatchObject({
      rows: [{ n_route_ip: 1, n_route_conflict: 0, n_route_health: null, n_health_evaluable: 0 }],
    });
    expect(
      barangayMembership(
        true,
        {
          route_ip: true,
          route_conflict: false,
          route_four_ps: false,
          route_health: true,
          health_evaluable: true,
        },
        "agg_uuc_phc_criteria",
      ),
    ).toMatchObject({ rows: [{ n_route_health: 1, n_health_evaluable: 1 }] });
  });
});

type Query = { table: string; projection: string; filters: Record<string, unknown>; head: boolean };
let calls: Query[] = [];
let loaded = true,
  fail = false,
  cap = false;
beforeEach(() => {
  calls = [];
  loaded = true;
  fail = false;
  cap = false;
  mock.client.mockImplementation(() => ({
    from(table: string) {
      const state: Query = { table, projection: "", filters: {}, head: false };
      calls.push(state);
      const q = {
        select(projection: string, options?: { head?: boolean }) {
          state.projection = projection;
          state.head = !!options?.head;
          return q;
        },
        eq(key: string, value: unknown) {
          state.filters[key] = value;
          return q;
        },
        is(key: string, value: unknown) {
          state.filters[key] = value;
          return q;
        },
        limit: () => q,
        maybeSingle: () => q,
        then(resolve: (v: unknown) => unknown) {
          const data =
            table === "dim_geo"
              ? { geo_code: "0102801001", parent_code: "0102801" }
              : table === "fact_nhfr_facility" && !state.head
                ? cap
                  ? Array(1000).fill(facility)
                  : [facility]
                : [];
          const count =
            state.filters["barangay.geo_level"] === "barangay"
              ? 1
              : state.filters.geo_code
                ? 3
                : loaded
                  ? 10
                  : 0;
          return Promise.resolve(resolve({ data, count, error: fail ? {} : null }));
        },
      };
      return q;
    },
  }));
});
it("uses exact accepted source and actual city parent, projects only public fields and coalesces reads", async () => {
  const read = createBarangayReader();
  const result = await Promise.all([
    read("agg_nhfr_counts", 11, "0102801001"),
    read("agg_nhfr_counts", 11, "0102801001"),
  ]);
  expect(result[0]).toMatchObject({ note: { partial: true, coverage: { covered: 1, total: 3 } } });
  expect(calls).toHaveLength(5);
  expect(calls.find((c) => c.table === "fact_nhfr_facility" && !c.head)).toMatchObject({
    projection: "facility_type,ownership_major,bed_capacity",
    filters: { dataset_id: 11, geo_code: "0102801", barangay_geo_code: "0102801001" },
  });
  expect(calls.every((c) => !c.projection.includes("*"))).toBe(true);
  expect(calls.at(-1)?.filters).toMatchObject({
    "barangay.geo_level": "barangay",
    "barangay.parent_code": "0102801",
  });
});
it("does not manufacture zeros from an unloaded source or failed query", async () => {
  loaded = false;
  expect(await createBarangayReader()("agg_nhfr_counts", 11, "0102801001")).toEqual({
    state: "available",
    rows: [],
  });
  fail = true;
  expect(await createBarangayReader()("agg_uuc_phc_counts", 12, "0102801001")).toEqual({
    state: "failed",
  });
});
it("fails rather than silently truncating a large barangay", async () => {
  cap = true;
  expect(await createBarangayReader()("agg_nhfr_counts", 11, "0102801001")).toEqual({
    state: "failed",
  });
});
it("returns not-listed only after successful loaded-source, geography and membership reads", async () => {
  expect(await createBarangayReader()("agg_uuc_phc_counts", 12, "0102801001")).toMatchObject({
    rows: [{ n_listed: 0 }],
  });
  expect(calls.at(-1)).toMatchObject({
    table: "fact_uuc_phc_barangay",
    projection: "geo_code",
    filters: { dataset_id: 12, geo_code: "0102801001" },
  });
});
