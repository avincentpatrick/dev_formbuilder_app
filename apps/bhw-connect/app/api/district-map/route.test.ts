import { beforeEach, describe, expect, it, vi } from "vitest";
import { NextRequest } from "next/server";

const mock = vi.hoisted(() => ({
  source: vi.fn(),
  tables: {} as Record<string, unknown[] | ((filters: [string, string, unknown][]) => unknown[])>,
  calls: [] as { table: string; filters: [string, string, unknown][] }[],
  failOn: null as string | null,
}));
vi.mock("@/lib/db/dataset", () => ({ readDatasetBySlug: mock.source }));
vi.mock("@/lib/db/supabase", () => ({
  createSupabaseServerClient: () => ({
    from: (table: string) => {
      const filters: [string, string, unknown][] = [];
      mock.calls.push({ table, filters });
      const chain: Record<string, unknown> = {};
      for (const op of ["eq", "neq", "is", "in", "like"])
        chain[op] = vi.fn((column: string, value: unknown) => {
          filters.push([op, column, value]);
          return chain;
        });
      for (const op of ["select", "order", "limit"]) chain[op] = vi.fn(() => chain);
      const result = () => {
        if (mock.failOn === table) return { data: null, error: { message: "boom" } };
        const rows = mock.tables[table];
        return { data: typeof rows === "function" ? rows(filters) : (rows ?? []), error: null };
      };
      chain.maybeSingle = vi.fn(() => {
        const r = result();
        return Promise.resolve({
          ...r,
          data: Array.isArray(r.data) ? (r.data[0] ?? null) : r.data,
        });
      });
      chain.then = (resolve: (value: unknown) => void) => Promise.resolve(result()).then(resolve);
      return chain;
    },
  }),
}));
import { GET } from "./route";

const get = (q: string) => GET(new NextRequest(`http://localhost/api/district-map${q}`));
const byEq = (filters: [string, string, unknown][], column: string) =>
  filters.find(([op, c]) => op === "eq" && c === column)?.[2];

beforeEach(() => {
  mock.calls = [];
  mock.failOn = null;
  mock.source.mockReset().mockResolvedValue({
    state: "available",
    value: {
      sourceName: "Synthetic district source",
      name: "x",
      asOfDate: "2025-06-30",
      license: "CC BY-SA",
    },
  });
  mock.tables = {
    dim_geo: (filters) => {
      const code = byEq(filters, "geo_code");
      if (code === "99001")
        return [{ geo_code: "99001", geo_name: "Synthetic Province", geo_level: "province" }];
      if (code === "9900101")
        return [{ geo_code: "9900101", geo_name: "A town", geo_level: "citymun" }];
      if (byEq(filters, "geo_level") === "province")
        return [{ geo_code: "99001", geo_name: "Synthetic Province" }];
      if (byEq(filters, "parent_code") === "99001")
        return [
          { geo_code: "9900101", geo_name: "A town" },
          { geo_code: "9900102", geo_name: "B city" },
        ];
      return [];
    },
    dim_legislative_district: [
      {
        district_code: "syn-1st",
        district_name: "Syn's 1st congressional district",
        parent_geo_code: "99001",
      },
      {
        district_code: "syn-2nd",
        district_name: "Syn's 2nd congressional district",
        parent_geo_code: null,
      },
    ],
    geo_district_map: (filters) =>
      filters.some(([op]) => op === "like")
        ? [
            { district_code: "syn-1st", geo_code: "9900101", geo_level: "citymun" },
            { district_code: "syn-1st", geo_code: "9900102001", geo_level: "barangay" },
            { district_code: "syn-2nd", geo_code: "9900102002", geo_level: "barangay" },
          ]
        : [{ geo_code: "9900102002" }, { geo_code: "8800101" }, { geo_code: "9900102003" }],
  };
});

describe("GET /api/district-map", () => {
  it("lists provinces and districts for the pickers, with each district's province where recorded", async () => {
    const data = await (await get("")).json();
    expect(data.provinces).toEqual([{ code: "99001", name: "Synthetic Province" }]);
    expect(data.districts).toEqual([
      { code: "syn-1st", name: "Syn's 1st congressional district", province: "99001" },
      { code: "syn-2nd", name: "Syn's 2nd congressional district", province: null },
    ]);
  });

  it("returns a province's municipalities with their districts, and reads no figures", async () => {
    const data = await (await get("?province=99001")).json();
    expect(data.province).toEqual({ code: "99001", name: "Synthetic Province" });
    expect(data.municipalities).toEqual([
      { code: "9900101", name: "A town", districts: ["syn-1st"], byBarangay: false },
      { code: "9900102", name: "B city", districts: ["syn-1st", "syn-2nd"], byBarangay: true },
    ]);
    expect(data.geometryUrl).toMatch(/\/geo\/citymun\/99001\.json$/);
    expect(data.source).toBe("Synthetic district source");
    expect(
      mock.calls.map((c) => c.table).filter((t) => t.startsWith("agg_") || t.startsWith("fact_")),
    ).toEqual([]);
    const membership = mock.calls.find((c) => c.table === "geo_district_map")!.filters;
    expect(membership).toContainEqual(["neq", "status", "rejected"]);
    expect(membership).toContainEqual(["is", "superseded_by", null]);
  });

  it("opens a district on the province holding most of its members, naming the others", async () => {
    const data = await (await get("?district=syn-2nd")).json();
    expect(data.province.code).toBe("99001");
    expect(data.otherProvinces).toEqual(["88001"]);
  });

  it("refuses a municipality where a province is expected, and malformed codes before any read", async () => {
    expect((await get("?province=9900101")).status).toBe(400);
    expect((await get("?province=99")).status).toBe(400);
    expect((await get("?district=Bad%20Code")).status).toBe(400);
    expect((await get("?province=99001&province=99002")).status).toBe(400);
    expect(mock.calls).toHaveLength(0);
  });

  it("says unavailable for an unknown district and failed when a read fails, never an empty map", async () => {
    mock.tables.dim_legislative_district = [];
    mock.tables.geo_district_map = [];
    expect((await (await get("?district=nowhere-1st")).json()).state).toBe("unavailable");
    mock.failOn = "geo_district_map";
    const failed = await get("?province=99001");
    expect(failed.status).toBe(503);
  });
});
