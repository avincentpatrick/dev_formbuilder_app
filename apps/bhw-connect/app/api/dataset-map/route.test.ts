import { beforeEach, expect, it, vi } from "vitest";
import { NextRequest } from "next/server";
const mock = vi.hoisted(() => ({ source: vi.fn(), geo: vi.fn(), from: vi.fn() }));
vi.mock("@/lib/db/dataset", () => ({ readDatasetBySlug: mock.source }));
vi.mock("@/lib/db/geo", () => ({ getGeoByCode: mock.geo }));
vi.mock("@/lib/db/supabase", () => ({ createSupabaseServerClient: () => ({ from: mock.from }) }));
import { GET } from "./route";
function query(result: unknown) {
  const chain: Record<string, unknown> = {};
  for (const name of ["select", "eq", "in", "order", "limit"]) chain[name] = vi.fn(() => chain);
  chain.then = (resolve: (value: unknown) => void) => Promise.resolve(result).then(resolve);
  return chain;
}
beforeEach(() => {
  vi.resetAllMocks();
  mock.source.mockResolvedValue({
    state: "available",
    value: {
      datasetId: 7,
      sourceName: "Synthetic source",
      name: "Synthetic",
      asOfDate: "2025-12-31",
      license: "Test only",
    },
  });
  mock.geo.mockResolvedValue({ geoCode: "PH", geoLevel: "national", geoName: "Philippines" });
});
it("uses only the requested dataset table, retains zero/missing geos and independent parent total", async () => {
  mock.from
    .mockReturnValueOnce(
      query({
        data: [
          { geo_code: "01", geo_name: "A" },
          { geo_code: "02", geo_name: "B" },
        ],
        error: null,
      }),
    )
    .mockReturnValueOnce(query({ data: [{ geo_code: "01", n_listed: 0 }], error: null }))
    .mockReturnValueOnce(query({ data: [{ geo_code: "PH", n_listed: 99 }], error: null }));
  const response = await GET(
    new NextRequest("http://localhost/api/dataset-map?dataset=uuc-phc-2025"),
  );
  const data = await response.json();
  expect(data.rows).toEqual([
    { code: "01", name: "A", value: 0 },
    { code: "02", name: "B", value: null },
  ]);
  expect(data.total).toBe(99); // never replaced with the sum of drawable/available children
  expect(mock.from.mock.calls.map((c) => c[0])).toEqual([
    "dim_geo",
    "agg_uuc_phc_counts",
    "agg_uuc_phc_counts",
  ]);
  expect(mock.source).toHaveBeenCalledWith("uuc-phc-2025");
});
it("rejects unsupported indicators before accessing data", async () => {
  expect(
    (
      await GET(
        new NextRequest(
          "http://localhost/api/dataset-map?dataset=uuc-phc-2025&indicator=bhw.total",
        ),
      )
    ).status,
  ).toBe(400);
  expect(mock.source).not.toHaveBeenCalled();
});
it("distinguishes absent release from read failure", async () => {
  mock.source.mockResolvedValueOnce({ state: "unavailable" });
  const absent = await GET(
    new NextRequest("http://localhost/api/dataset-map?dataset=nhfr-2026-09"),
  );
  expect(await absent.json()).toEqual({ state: "unavailable" });
  mock.source.mockResolvedValueOnce({ state: "failed" });
  expect(
    (await GET(new NextRequest("http://localhost/api/dataset-map?dataset=nhfr-2026-09"))).status,
  ).toBe(503);
});
it("fails closed on capped or failed child queries", async () => {
  mock.from.mockReturnValue(query({ data: [], error: { message: "private internal error" } }));
  const response = await GET(
    new NextRequest("http://localhost/api/dataset-map?dataset=nhfr-2026-09"),
  );
  expect(response.status).toBe(503);
  expect(await response.json()).toEqual({ state: "failed" });
});

it("pins PSA population to its accepted release and 2024 on both queries without fallback", async () => {
  const child = query({ data: [{ geo_code: "01", population: 12 }], error: null });
  const parent = query({ data: [{ geo_code: "PH", population: 25 }], error: null });
  mock.from
    .mockReturnValueOnce(query({ data: [{ geo_code: "01", geo_name: "A" }], error: null }))
    .mockReturnValueOnce(child)
    .mockReturnValueOnce(parent);
  const response = await GET(
    new NextRequest("http://localhost/api/dataset-map?dataset=psa-popcen-2024"),
  );
  expect(response.status).toBe(200);
  expect((await response.json()).rows[0].value).toBe(12);
  expect(child.eq).toHaveBeenCalledWith("census_year", 2024);
  expect(parent.eq).toHaveBeenCalledWith("census_year", 2024);
  expect(mock.source).toHaveBeenCalledWith("psa-popcen-2024");
  expect(mock.from.mock.calls.map((c) => c[0])).toEqual([
    "dim_geo",
    "agg_population",
    "agg_population",
  ]);
});
it("reads StepZero counts without validated profiles or unapproved rate measures", async () => {
  mock.from
    .mockReturnValueOnce(query({ data: [{ geo_code: "01", geo_name: "A" }], error: null }))
    .mockReturnValueOnce(query({ data: [{ geo_code: "01", n_registered: 4 }], error: null }))
    .mockReturnValueOnce(query({ data: [{ geo_code: "PH", n_registered: 10 }], error: null }));
  const response = await GET(
    new NextRequest(
      "http://localhost/api/dataset-map?dataset=bhw-stepzero-2026&indicator=bhw.registered",
    ),
  );
  expect((await response.json()).rows[0].value).toBe(4);
  expect(mock.from.mock.calls.map((c) => c[0])).toEqual([
    "dim_geo",
    "agg_bhw_stepzero_counts",
    "agg_bhw_stepzero_counts",
  ]);
  expect(mock.source).toHaveBeenCalledWith("bhw-stepzero-2026");
  const rate = await GET(
    new NextRequest(
      "http://localhost/api/dataset-map?dataset=bhw-stepzero-2026&indicator=bhw.declared-accreditation",
    ),
  );
  expect(rate.status).toBe(400);
});
it("requires an active census release and refuses demographic or honorarium query access", async () => {
  mock.source.mockResolvedValueOnce({ state: "unavailable" });
  await GET(new NextRequest("http://localhost/api/dataset-map?dataset=bhw-2025"));
  expect(mock.source).toHaveBeenCalledWith("bhw-2025", true);
  expect(mock.from).not.toHaveBeenCalled();
  for (const indicator of ["bhw.demographics.sex", "bhw.honorarium.barangay", "bhw.total"]) {
    const response = await GET(
      new NextRequest(`http://localhost/api/dataset-map?dataset=bhw-2025&indicator=${indicator}`),
    );
    expect(response.status).toBe(400);
  }
});
