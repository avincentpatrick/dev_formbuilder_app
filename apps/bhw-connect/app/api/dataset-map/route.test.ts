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
it("provides poverty navigation without requesting or inventing regional rates", async () => {
  mock.from.mockReturnValueOnce(
    query({ data: [{ geo_code: "01", geo_name: "Region" }], error: null }),
  );
  const response = await GET(
    new NextRequest("http://localhost/api/dataset-map?dataset=psa-sae-poverty-2023"),
  );
  const data = await response.json();
  expect(data.valueSupport).toBe("navigation-only");
  expect(data.total).toBeNull();
  expect(data.rows[0].value).toBeNull();
  expect(mock.from.mock.calls.map((c) => c[0])).toEqual(["dim_geo"]);
});
it("reads only municipal 2023 poverty observations, preserves CI, and never queries parent rates", async () => {
  mock.geo.mockResolvedValueOnce({ geoCode: "01028", geoLevel: "province", geoName: "Province" });
  const values = query({
    data: [{ geo_code: "0102801", poverty_incidence: 12.5, ci_low: 10.2, ci_high: 14.8 }],
    error: null,
  });
  mock.from
    .mockReturnValueOnce(query({ data: [{ geo_code: "0102801", geo_name: "Town" }], error: null }))
    .mockReturnValueOnce(values);
  const response = await GET(
    new NextRequest(
      "http://localhost/api/dataset-map?dataset=psa-sae-poverty-2023&level=province&code=01028",
    ),
  );
  const data = await response.json();
  expect(data.rows[0]).toEqual({
    code: "0102801",
    name: "Town",
    value: 12.5,
    ciLow: 10.2,
    ciHigh: 14.8,
  });
  expect(data.total).toBeNull();
  expect(data.valueSupport).toBe("supported");
  expect(values.eq).toHaveBeenCalledWith("sae_year", 2023);
  expect(values.eq).toHaveBeenCalledWith("geo_level", "citymun");
  expect(mock.from.mock.calls.map((c) => c[0])).toEqual(["dim_geo", "agg_poverty"]);
});
it("rejects poverty back-estimate periods and barangay rates", async () => {
  for (const suffix of ["&period=2021", "&level=barangay&code=0102801001"]) {
    expect(
      (
        await GET(
          new NextRequest(`http://localhost/api/dataset-map?dataset=psa-sae-poverty-2023${suffix}`),
        )
      ).status,
    ).toBe(400);
  }
  expect(mock.from).not.toHaveBeenCalled();
});
it("pins income source metadata and provides national navigation without a regional class query", async () => {
  mock.from.mockReturnValueOnce(
    query({ data: [{ geo_code: "01", geo_name: "Region" }], error: null }),
  );
  const response = await GET(
    new NextRequest("http://localhost/api/dataset-map?dataset=lgu-income-class"),
  );
  const data = await response.json();
  expect(mock.source).toHaveBeenCalledWith("dof-blgf-income-2024");
  expect(data.valueSupport).toBe("navigation-only");
  expect(data.total).toBeNull();
  expect(mock.from.mock.calls.map((c) => c[0])).toEqual(["dim_geo"]);
});
it("queries only accepted reclassification records, never old classes or parent aggregates", async () => {
  mock.geo.mockResolvedValueOnce({ geoCode: "01", geoLevel: "region", geoName: "Region" });
  const values = query({
    data: [{ geo_code: "01028", new_class: 1, dof_kind: "province", converted: false }],
    error: null,
  });
  mock.from
    .mockReturnValueOnce(
      query({ data: [{ geo_code: "01028", geo_name: "Province" }], error: null }),
    )
    .mockReturnValueOnce(values);
  const response = await GET(
    new NextRequest(
      "http://localhost/api/dataset-map?dataset=lgu-income-class&level=region&code=01",
    ),
  );
  const data = await response.json();
  expect(data.rows[0]).toMatchObject({ value: 1, note: "Source LGU type: province" });
  expect(data.total).toBeNull();
  expect(values.eq).toHaveBeenCalledWith("dataset_id", 7);
  expect(values.eq).toHaveBeenCalledWith("geo_level", "province");
  expect(values.select).toHaveBeenCalledWith("geo_code,new_class,dof_kind,converted");
  expect(mock.from.mock.calls.map((c) => c[0])).toEqual(["dim_geo", "dim_lgu_income_reclass"]);
});
it("rejects unsupported income periods and barangay scopes", async () => {
  for (const suffix of ["&period=2008", "&level=barangay&code=0102801001"])
    expect(
      (
        await GET(
          new NextRequest(`http://localhost/api/dataset-map?dataset=lgu-income-class${suffix}`),
        )
      ).status,
    ).toBe(400);
  expect(mock.from).not.toHaveBeenCalled();
});
