import { TRAINING_TOPICS } from "@/lib/catalog/definitions";
import { beforeEach, expect, it, vi } from "vitest";
import { NextRequest } from "next/server";
const mock = vi.hoisted(() => ({ source: vi.fn(), geo: vi.fn(), from: vi.fn() }));
vi.mock("@/lib/db/dataset", () => ({ readDatasetBySlug: mock.source }));
vi.mock("@/lib/db/geo", () => ({ getGeoByCode: mock.geo }));
vi.mock("@/lib/db/supabase", () => ({ createSupabaseServerClient: () => ({ from: mock.from }) }));
import { GET } from "./route";
function query(result: unknown) {
  const chain: Record<string, unknown> = {};
  for (const name of ["select", "eq", "in", "order", "limit", "range"]) chain[name] = vi.fn(() => chain);
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
it("reads StepZero counts without validated profiles or cross-release rate measures", async () => {
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
      "http://localhost/api/dataset-map?dataset=bhw-stepzero-2026&indicator=bhw.profile-accreditation",
    ),
  );
  expect(rate.status).toBe(400);
});
it("requires an active census release and refuses demographic or payer-level honorarium query access", async () => {
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

it("reads profiling stage snapshots from their own dataset and level without parent averaging", async () => {
  const values = query({
    data: [
      {
        geo_code: "01",
        n_total_bhw: 100,
        n_drafted: 10,
        n_for_validation: 20,
        n_back_to_encoder: 5,
        n_validated: 15,
        n_approved: 40,
      },
    ],
    error: null,
  });
  mock.from
    .mockReturnValueOnce(query({ data: [{ geo_code: "01", geo_name: "Synthetic" }], error: null }))
    .mockReturnValueOnce(values);
  const response = await GET(
    new NextRequest(
      "http://localhost/api/dataset-map?dataset=bhw-profiling-status-2026&indicator=encoded",
    ),
  );
  expect(response.status).toBe(200);
  expect(await response.json()).toMatchObject({
    total: null,
    rows: [{ value: 35, note: "35 records / 100 BHWs to profile" }],
  });
  expect(mock.source).toHaveBeenCalledWith("bhw-profiling-status-2026");
  expect(mock.from.mock.calls.map((c) => c[0])).toEqual(["dim_geo", "agg_bhw_profiling_status"]);
  expect(values.eq).toHaveBeenCalledWith("dataset_id", 7);
  expect(values.eq).toHaveBeenCalledWith("geo_level", "region");
});
it("rejects profiling periods and individual record fields outside the map contract", async () => {
  for (const query of ["period=2025", "indicator=n_drafted", "level=barangay&code=0102801001"]) {
    expect(
      (
        await GET(
          new NextRequest(
            `http://localhost/api/dataset-map?dataset=bhw-profiling-status-2026&${query}`,
          ),
        )
      ).status,
    ).toBe(400);
  }
  expect(mock.from).not.toHaveBeenCalled();
});

it("reads UUC route counts and their own denominator context for children and parent", async () => {
  const values = query({
    data: [{ geo_code: "01", n_route_health: 4, n_listed: 10, n_health_evaluable: 6 }],
    error: null,
  });
  const parent = query({
    data: [{ geo_code: "PH", n_route_health: 12, n_listed: 30, n_health_evaluable: 20 }],
    error: null,
  });
  mock.from
    .mockReturnValueOnce(query({ data: [{ geo_code: "01", geo_name: "Synthetic" }], error: null }))
    .mockReturnValueOnce(values)
    .mockReturnValueOnce(parent);
  const response = await GET(
    new NextRequest(
      "http://localhost/api/dataset-map?dataset=uuc-phc-2025&indicator=uuc.route.health",
    ),
  );
  expect(response.status).toBe(200);
  expect(await response.json()).toMatchObject({
    rows: [
      {
        value: 4,
        note: "6 health-evaluable of 10 listed barangays; 4 excluded from health evaluation",
      },
    ],
    total: 12,
    totalNote: "20 health-evaluable of 30 listed barangays; 10 excluded from health evaluation",
  });
  expect(mock.source).toHaveBeenCalledWith("uuc-phc-2025");
  expect(mock.from.mock.calls.map((c) => c[0])).toEqual([
    "dim_geo",
    "agg_uuc_phc_criteria",
    "agg_uuc_phc_criteria",
  ]);
  for (const q of [values, parent]) {
    expect(q.select).toHaveBeenCalledWith("geo_code,n_route_health,n_listed,n_health_evaluable");
    expect(q.eq).toHaveBeenCalledWith("dataset_id", 7);
  }
  expect(values.eq).toHaveBeenCalledWith("geo_level", "region");
  expect(parent.eq).toHaveBeenCalledWith("geo_level", "national");
});

it.each(["population", "households"])("reads StepZero %s directly without fallback and preserves complete rows", async (column) => {
  const children = query({ data: [
    { geo_code: "01", geo_name: "Zero" }, { geo_code: "02", geo_name: "Null" },
    { geo_code: "03", geo_name: "Absent" }, { geo_code: "04", geo_name: "Present" },
  ], error: null });
  const values = query({ data: [
    { geo_code: "01", [column]: 0 }, { geo_code: "02", [column]: null },
    { geo_code: "04", [column]: 42 },
  ], error: null });
  const parent = query({ data: [{ geo_code: "PH", [column]: 123 }], error: null });
  mock.from.mockReturnValueOnce(children).mockReturnValueOnce(values).mockReturnValueOnce(parent);
  const response = await GET(new NextRequest(
    `http://localhost/api/dataset-map?dataset=bhw-stepzero-2026&indicator=stepzero.${column}&period=2026`,
  ));
  expect(response.status).toBe(200);
  const payload = await response.json();
  expect(payload.rows.map((row: { value: number | null }) => row.value)).toEqual([0, null, null, 42]);
  expect(payload.total).toBe(123);
  expect(mock.source).toHaveBeenCalledWith("bhw-stepzero-2026");
  expect(mock.from.mock.calls.map((call) => call[0])).toEqual(["dim_geo", "agg_bhw_stepzero_counts", "agg_bhw_stepzero_counts"]);
  for (const q of [values, parent]) {
    expect(q.select).toHaveBeenCalledWith(`geo_code,${column}`);
    expect(q.eq).toHaveBeenCalledWith("dataset_id", 7);
  }
});

it("keeps the published StepZero parent rate separate from totals and child averages", async () => {
  const values = query({ data: [
    { geo_code: "01", n_registered_accredited: 1, n_total_bhw: 3, pct_registered_accredited: 33.33 },
    { geo_code: "02", n_registered_accredited: 0, n_total_bhw: 0, pct_registered_accredited: 0 },
  ], error: null });
  const parent = query({ data: [{ geo_code: "PH", n_registered_accredited: 1, n_total_bhw: 4, pct_registered_accredited: 25 }], error: null });
  mock.from.mockReturnValueOnce(query({ data: [{ geo_code: "01", geo_name: "A" }, { geo_code: "02", geo_name: "B" }], error: null }))
    .mockReturnValueOnce(values).mockReturnValueOnce(parent);
  const response = await GET(new NextRequest("http://localhost/api/dataset-map?dataset=bhw-stepzero-2026&indicator=bhw.declared-accreditation&period=2026"));
  const payload = await response.json();
  expect(payload.rows.map((row: { value: number | null }) => row.value)).toEqual([33.33, null]);
  expect(payload.parentRate).toBe(25);
  expect(payload.total).toBeNull();
  expect(mock.source).toHaveBeenCalledWith("bhw-stepzero-2026");
  for (const q of [values, parent]) expect(q.select).toHaveBeenCalledWith("geo_code,pct_registered_accredited,n_registered_accredited,n_total_bhw");
});

it("reads Census accreditation from its active release with independent parent rate and published intervals", async () => {
  const child = query({ data: [{ geo_code: "01", n_total: 3, n_accredited: 1, pct_accredited: 33.33, ci_low: 6.15, ci_high: 79.23 }], error: null });
  const parent = query({ data: [{ geo_code: "PH", n_total: 100, n_accredited: 25, pct_accredited: 25 }], error: null });
  mock.from.mockReturnValueOnce(query({ data: [{ geo_code: "01", geo_name: "Synthetic" }], error: null }))
    .mockReturnValueOnce(child).mockReturnValueOnce(parent);
  const result = await GET(new NextRequest("http://localhost/api/dataset-map?dataset=bhw-2025&indicator=bhw.profile-accreditation"));
  expect(await result.json()).toMatchObject({ total: null, parentRate: 25, rows: [{ value: 33.33, ciLow: 6.15, ciHigh: 79.23 }] });
  expect(mock.source).toHaveBeenCalledWith("bhw-2025", true);
  expect(mock.from.mock.calls.map(c => c[0])).toEqual(["dim_geo", "agg_bhw_counts", "agg_bhw_counts"]);
  expect(child.select).toHaveBeenCalledWith("geo_code,pct_accredited,n_accredited,n_total,ci_low,ci_high");
  expect(parent.eq).toHaveBeenCalledWith("dataset_id", 7);
});

it.each(TRAINING_TOPICS)("pins Census training topic %s on child and parent reads", async topic => {
  const child = query({ data: [{ geo_code: "01", n_total: 3, n_trained: 2, coverage_pct: 66.67, ci_low: 20.77, ci_high: 93.85 }], error: null });
  const parent = query({ data: [{ geo_code: "PH", n_total: 100, n_trained: 75, coverage_pct: 75 }], error: null });
  mock.from.mockReturnValueOnce(query({ data: [{ geo_code: "01", geo_name: "Synthetic" }], error: null }))
    .mockReturnValueOnce(child).mockReturnValueOnce(parent);
  const response = await GET(new NextRequest(`http://localhost/api/dataset-map?dataset=bhw-2025&indicator=bhw.training.${topic}`));
  expect(await response.json()).toMatchObject({ total: null, parentRate: 75, rows: [{ value: 66.67, ciLow: 20.77, ciHigh: 93.85 }] });
  expect(mock.source).toHaveBeenCalledWith("bhw-2025", true);
  expect(mock.from.mock.calls.map(c => c[0])).toEqual(["dim_geo", "agg_training", "agg_training"]);
  for (const read of [child, parent]) {
    expect(read.eq).toHaveBeenCalledWith("topic_slug", topic);
    expect(read.eq).toHaveBeenCalledWith("dataset_id", 7);
    expect(read.select).toHaveBeenCalledWith("geo_code,coverage_pct,n_trained,n_total,ci_low,ci_high");
  }
});
it("rejects unknown training topics and barangay requests before data access", async () => {
  for (const suffix of ["indicator=bhw.training.unknown", "indicator=bhw.training.basic_life_support&level=barangay&code=0100101001"]) {
    expect((await GET(new NextRequest(`http://localhost/api/dataset-map?dataset=bhw-2025&${suffix}`))).status).toBe(400);
  }
  expect(mock.source).not.toHaveBeenCalled();
});

it("reads only public honorarium receipt percentage and profile denominator, never payer amounts or accreditation intervals", async () => {
  const child = query({ data: [{ geo_code: "01", n_total: 3, any_honorarium_pct: 33.33, ci_low: 6.15, ci_high: 79.23 }], error: null });
  const parent = query({ data: [{ geo_code: "PH", n_total: 100, any_honorarium_pct: 75 }], error: null });
  mock.from.mockReturnValueOnce(query({ data: [{ geo_code: "01", geo_name: "Synthetic" }], error: null }))
    .mockReturnValueOnce(child).mockReturnValueOnce(parent);
  const response = await GET(new NextRequest("http://localhost/api/dataset-map?dataset=bhw-2025&indicator=bhw.any-honorarium"));
  expect(await response.json()).toMatchObject({ total: null, parentRate: 75, rows: [{ value: 33.33, ciLow: null, ciHigh: null, smallSample: true }] });
  expect(mock.source).toHaveBeenCalledWith("bhw-2025", true);
  expect(mock.from.mock.calls.map(c => c[0])).toEqual(["dim_geo", "agg_bhw_counts", "agg_bhw_counts"]);
  for (const read of [child, parent]) expect(read.select).toHaveBeenCalledWith("geo_code,any_honorarium_pct,n_total");
});

it("preserves an independent Census parent mean without summing or averaging child means", async () => {
  const child = query({ data: [{ geo_code: "01", n_total: 5, avg_active_years: 12.34 }], error: null });
  const parent = query({ data: [{ geo_code: "PH", n_total: 100, avg_active_years: 9.87 }], error: null });
  mock.from.mockReturnValueOnce(query({ data: [{ geo_code: "01", geo_name: "Synthetic" }], error: null }))
    .mockReturnValueOnce(child).mockReturnValueOnce(parent);
  const response = await GET(new NextRequest("http://localhost/api/dataset-map?dataset=bhw-2025&indicator=bhw.active-years"));
  const data = await response.json();
  expect(data).toMatchObject({ total: null, parentMean: 9.87, rows: [{ value: 12.34 }] });
  expect(data).not.toHaveProperty("parentRate");
  expect(mock.source).toHaveBeenCalledWith("bhw-2025", true);
  for (const read of [child, parent]) expect(read.select).toHaveBeenCalledWith("geo_code,avg_active_years,n_total");
});

it("rejects ambiguous query choices before reading a dataset", async () => {
  const result = await GET(new NextRequest("http://localhost/api/dataset-map?dataset=bhw-2025&indicator=bhw.validated-profiles&indicator=bhw.any-honorarium"));
  expect(result.status).toBe(400);
  expect(mock.source).not.toHaveBeenCalled();
  expect(mock.from).not.toHaveBeenCalled();
});

const LINK = (dataset: string, indicator: string, level: string, code: string) =>
  `http://localhost/api/dataset-map?dataset=${dataset}&indicator=${indicator}&level=${level}&code=${code}`;
/** Answers by table, since the barangay view reads a source table the municipality view does not. */
function tables(map: Record<string, unknown>) {
  mock.from.mockImplementation((table: string) => {
    if (!(table in map)) throw new Error(`unexpected table ${table}`);
    return query({ data: map[table], error: null });
  });
}
const BARANGAYS = [
  { geo_code: "0102801001", geo_name: "Adams (Pob.)" },
  { geo_code: "0102801002", geo_name: "Bacsil" },
];
it("refuses a municipality scope while no barangay release is configured, before reading any data", async () => {
  vi.stubEnv("BHW_BARANGAY_RELEASE", "");
  const response = await GET(new NextRequest(LINK("uuc-phc-2025", "uuc.listed", "citymun", "0102801")));
  expect(response.status).toBe(400);
  expect(mock.source).not.toHaveBeenCalled();
  expect(mock.from).not.toHaveBeenCalled();
  vi.unstubAllEnvs();
});
it("refuses barangay drill-down for datasets and indicators without a barangay reader, even with a release set", async () => {
  vi.stubEnv("BHW_BARANGAY_RELEASE", "rel-1");
  for (const url of [
    LINK("bhw-stepzero-2026", "bhw.declared-accreditation", "citymun", "0102801"),
    LINK("bhw-2025", "bhw.profile-accreditation", "citymun", "0102801"),
    LINK("bhw-2025", "bhw.any-honorarium", "citymun", "0102801"),
    LINK("bhw-2025", "bhw.active-years", "citymun", "0102801"),
    LINK("bhw-2025", `bhw.training.${TRAINING_TOPICS[0][0]}`, "citymun", "0102801"),
    LINK("uuc-phc-2025", "uuc.route.ip", "citymun", "0102801"),
  ])
    expect((await GET(new NextRequest(url))).status, url).toBe(400);
  expect(mock.source).not.toHaveBeenCalled();
  vi.unstubAllEnvs();
});
it("reads UUC list membership per barangay from the source table, with zero for an unlisted barangay", async () => {
  vi.stubEnv("BHW_BARANGAY_RELEASE", "rel-1");
  mock.geo.mockResolvedValue({ geoCode: "0102801", geoLevel: "citymun", geoName: "Adams" });
  tables({ dim_geo: BARANGAYS, fact_uuc_phc_barangay: [{ geo_code: "0102801002" }], agg_uuc_phc_counts: [{ geo_code: "0102801", n_listed: 1 }] });
  const response = await GET(new NextRequest(LINK("uuc-phc-2025", "uuc.listed", "citymun", "0102801")));
  const data = await response.json();
  expect(response.status).toBe(200);
  expect(data.childLevel).toBe("barangay");
  expect(data.rows).toEqual([{ code: "0102801001", name: "Adams (Pob.)", value: 0 }, { code: "0102801002", name: "Bacsil", value: 1 }]);
  expect(data.total).toBe(1);
  expect(data.geometryUrl).toMatch(/\/maps\/rel-1\/citymun\/0102801\.json$/);
  expect(data.barangayDrill).toBeUndefined();
  vi.unstubAllEnvs();
});
it("counts NHFR facilities per barangay and says how many have no barangay recorded", async () => {
  vi.stubEnv("BHW_BARANGAY_RELEASE", "rel-1");
  mock.geo.mockResolvedValue({ geoCode: "0102801", geoLevel: "citymun", geoName: "Adams" });
  const facility = (barangay: string | null) => ({ barangay_geo_code: barangay, ownership_major: "Government", facility_type: "Barangay Health Station", bed_capacity: 0 });
  tables({
    dim_geo: BARANGAYS,
    fact_nhfr_facility: [facility("0102801001"), facility("0102801001"), facility(null)],
    agg_nhfr_counts: [{ geo_code: "0102801", n_facilities: 3 }],
  });
  const data = await (await GET(new NextRequest(LINK("nhfr-2026-09", "nhfr.facilities", "citymun", "0102801")))).json();
  expect(data.rows).toEqual([{ code: "0102801001", name: "Adams (Pob.)", value: 2 }, { code: "0102801002", name: "Bacsil", value: 0 }]);
  expect(data.total).toBe(3); // the municipal total, never the sum of barangays
  expect(data.totalNote).toContain("1 facilities in the municipal total come from facilities with no barangay recorded");
  vi.unstubAllEnvs();
});
it("adds no note when the unplaced facilities cannot change the indicator", async () => {
  vi.stubEnv("BHW_BARANGAY_RELEASE", "rel-1");
  mock.geo.mockResolvedValue({ geoCode: "0102801", geoLevel: "citymun", geoName: "Adams" });
  const facility = (barangay: string | null) => ({ barangay_geo_code: barangay, ownership_major: "Government", facility_type: "Barangay Health Station", bed_capacity: 0 });
  tables({ dim_geo: BARANGAYS, fact_nhfr_facility: [facility("0102801001"), facility(null)], agg_nhfr_counts: [{ geo_code: "0102801", n_barangays_with_facility: 1 }] });
  for (const indicator of ["nhfr.barangay-presence", "nhfr.beds", "nhfr.type.hospital"]) {
    const data = await (await GET(new NextRequest(LINK("nhfr-2026-09", indicator, "citymun", "0102801")))).json();
    expect(data.totalNote, indicator).toBeUndefined();
  }
  vi.unstubAllEnvs();
});
it("maps BHW counts per barangay from the barangay aggregate rows, with a note that rates stay at municipalities", async () => {
  vi.stubEnv("BHW_BARANGAY_RELEASE", "rel-1");
  mock.geo.mockResolvedValue({ geoCode: "0102801", geoLevel: "citymun", geoName: "Adams" });
  for (const [dataset, indicator, table, column] of [
    ["bhw-2025", "bhw.validated-profiles", "agg_bhw_counts", "n_total"],
    ["bhw-stepzero-2026", "bhw.total", "agg_bhw_stepzero_counts", "n_total_bhw"],
    ["bhw-stepzero-2026", "bhw.nonregistered", "agg_bhw_stepzero_counts", "n_non_registered"],
  ]) {
    tables({ dim_geo: BARANGAYS, [table]: [{ geo_code: "0102801001", [column]: 3 }, { geo_code: "0102801002", [column]: 0 }, { geo_code: "0102801", [column]: 3 }] });
    const data = await (await GET(new NextRequest(LINK(dataset, indicator, "citymun", "0102801")))).json();
    expect(data.childLevel, indicator).toBe("barangay");
    expect(data.rows, indicator).toEqual([{ code: "0102801001", name: "Adams (Pob.)", value: 3 }, { code: "0102801002", name: "Bacsil", value: 0 }]);
    expect(data.total, indicator).toBe(3);
    expect(data.totalNote, indicator).toContain("Barangay maps show counts only");
    expect(data.geometryUrl).toMatch(/\/maps\/rel-1\/citymun\/0102801\.json$/);
  }
  vi.unstubAllEnvs();
});
it("offers the barangay drill for a BHW count and not for a BHW rate", async () => {
  vi.stubEnv("BHW_BARANGAY_RELEASE", "rel-1");
  mock.geo.mockResolvedValue({ geoCode: "01028", geoLevel: "province", geoName: "Ilocos Norte" });
  for (const [indicator, expected] of [["bhw.validated-profiles", true], ["bhw.any-honorarium", undefined]] as const) {
    tables({ dim_geo: [{ geo_code: "0102801", geo_name: "Adams" }], agg_bhw_counts: [{ geo_code: "0102801", n_total: 5, any_honorarium_pct: 10 }, { geo_code: "01028", n_total: 50, any_honorarium_pct: 10 }] });
    const data = await (await GET(new NextRequest(LINK("bhw-2025", indicator, "province", "01028")))).json();
    expect(data.barangayDrill, indicator).toBe(expected);
  }
  vi.unstubAllEnvs();
});
it("fails closed rather than showing partial barangay values when the source read fails", async () => {
  vi.stubEnv("BHW_BARANGAY_RELEASE", "rel-1");
  mock.geo.mockResolvedValue({ geoCode: "0102801", geoLevel: "citymun", geoName: "Adams" });
  mock.from.mockImplementation((table: string) =>
    query(table === "fact_nhfr_facility" ? { data: null, error: { message: "x" } } : { data: table === "dim_geo" ? BARANGAYS : [], error: null }));
  const response = await GET(new NextRequest(LINK("nhfr-2026-09", "nhfr.facilities", "citymun", "0102801")));
  expect(response.status).toBe(503);
  vi.unstubAllEnvs();
});
it("tells the card a municipality can drill only for a supported indicator with a release installed", async () => {
  mock.geo.mockResolvedValue({ geoCode: "01028", geoLevel: "province", geoName: "Ilocos Norte" });
  const cases: [string, string, string, boolean | undefined][] = [
    ["rel-1", "uuc-phc-2025", "uuc.listed", true],
    ["rel-1", "uuc-phc-2025", "uuc.route.ip", undefined],
    ["", "uuc-phc-2025", "uuc.listed", undefined],
  ];
  for (const [release, dataset, indicator, expected] of cases) {
    vi.stubEnv("BHW_BARANGAY_RELEASE", release);
    tables({
      dim_geo: [{ geo_code: "0102801", geo_name: "Adams" }],
      agg_uuc_phc_counts: [{ geo_code: "0102801", n_listed: 1, n_barangays: 1 }],
      agg_uuc_phc_criteria: [{ geo_code: "0102801", n_listed: 1, n_route_ip: 1, n_health_evaluable: 1 }],
    });
    const data = await (await GET(new NextRequest(LINK(dataset, indicator, "province", "01028")))).json();
    expect(data.childLevel).toBe("citymun");
    expect(data.barangayDrill, `${release}/${indicator}`).toBe(expected);
  }
  vi.unstubAllEnvs();
});
