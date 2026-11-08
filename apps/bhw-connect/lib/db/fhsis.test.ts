import { beforeEach, describe, expect, it, vi } from "vitest";
import {
  fhsisCaption,
  fhsisPeriodLabel,
  fhsisRateLabel,
  toFhsisIndicator,
  workforceRatio,
  sortFhsisChildren,
  isFhsisLevel,
  getFhsisArea,
  getFhsisChildren,
  getFhsisBreakdowns,
  fhsisBreakdownLabel,
  fhsisLimitedGeography,
  type IndicatorDefinition,
  type IndicatorRow,
} from "./fhsis";

const mocks = vi.hoisted(() => ({ from: vi.fn(), id: vi.fn(), children: vi.fn() }));
vi.mock("./supabase", () => ({ createSupabaseServerClient: () => ({ from: mocks.from }) }));
vi.mock("./dataset", () => ({
  DATASET_SLUGS: { fhsis: "fhsis-2025" },
  getDatasetIdBySlug: mocks.id,
}));
vi.mock("./geo", () => ({ getChildGeos: mocks.children }));
const definition: IndicatorDefinition = {
  indicator_key: "fic",
  program_area: "immunization",
  label: "FIC",
  numerator_def: "Fully immunised children",
  denominator_def: "Projected eligible children",
  unit: "percent (0-100, may exceed)",
  source_workbook: "synthetic.xlsx",
  uuc_criterion_d: true,
};
const row: IndicatorRow = {
  id: 1,
  dataset_id: 12,
  geo_code: "PH",
  geo_level: "national",
  indicator_key: "fic",
  breakdown: "total",
  numerator_exact: null,
  denominator_exact: null,
  numerator: 0,
  denominator: 100,
  rate_pct: 0,
  over_100: false,
  source_psgc: null,
  source_area_name: null,
};
function query(data: unknown, error: unknown = null) {
  const q = {
    data,
    error,
    select: vi.fn(),
    eq: vi.fn(),
    neq: vi.fn(),
    order: vi.fn(),
    in: vi.fn(),
    limit: vi.fn(),
    range: vi.fn(),
  };
  for (const fn of [q.select, q.eq, q.neq, q.order, q.in, q.limit, q.range]) fn.mockReturnValue(q);
  return q;
}
beforeEach(() => {
  vi.clearAllMocks();
  mocks.id.mockResolvedValue(12);
  mocks.children.mockResolvedValue([]);
});
describe("FHSIS interpretation", () => {
  it("prefers precise source values, including zero, without rounding to legacy integers", () => {
    const actual = toFhsisIndicator(definition, {
      ...row,
      numerator: 12,
      numerator_exact: 0,
      denominator_exact: 12.375,
    });
    expect(actual.numerator).toBe(0);
    expect(actual.denominator).toBe(12.375);
  });
  it("identifies quarterly sources separately from annual indicators", () => {
    expect(fhsisPeriodLabel("anc8")).toBe("Q3–Q4 2025");
    expect(fhsisPeriodLabel("water_basic")).toBe("Q4 2025");
    expect(fhsisPeriodLabel("sanitation_basic")).toBe("Q4 2025");
    expect(fhsisPeriodLabel("fic")).toBe("Annual 2025");
  });
  it("distinguishes zero, an absent row and a reported row without a rate", () => {
    expect(fhsisRateLabel(toFhsisIndicator(definition, row))).toBe("0%");
    expect(fhsisRateLabel(toFhsisIndicator(definition))).toBe("Not reported in 2025");
    const partial = toFhsisIndicator(definition, { ...row, numerator: 23, rate_pct: null });
    expect(fhsisRateLabel(partial)).toBe("Rate not reported");
    expect(partial.numerator).toBe(23);
  });
  it("retains overshoots, including when the source flag was missing", () => {
    expect(fhsisRateLabel(toFhsisIndicator(definition, { ...row, rate_pct: 2233 }))).toBe(
      "2,233%†",
    );
  });
  it("never labels a TB notification rate as excess percentage coverage", () => {
    const tb = toFhsisIndicator(
      { ...definition, unit: "cases per 100,000 population" },
      { ...row, rate_pct: 350, over_100: true },
    );
    expect(fhsisRateLabel(tb)).toBe("350 per 100,000");
    expect(fhsisRateLabel({ ...tb, rate: 0.126738, unit: "cases per 10,000 population" })).toBe(
      "0.127 per 10,000",
    );
    expect(fhsisRateLabel({ ...tb, rate: 0.0063067, unit: "percent (0-100, may exceed)" })).toBe(
      "0.00631%",
    );
    expect(tb.over100).toBe(false);
  });
  it("uses the indicator's denominator in the caption", () => {
    expect(fhsisCaption(toFhsisIndicator(definition, row), "Example town")).toContain(
      "Denominator N = 100: Projected eligible children",
    );
    expect(fhsisCaption(toFhsisIndicator(definition), "Example town")).toContain(
      "N = not reported",
    );
  });
  it("does not invent workforce ratios for missing/suppressed counts or zero staff", () => {
    expect(workforceRatio(null, 10)).toBeNull();
    expect(workforceRatio(100, null)).toBeNull();
    expect(workforceRatio(100, 0)).toBeNull();
    expect(workforceRatio(0, 10)).toBe(0);
    expect(workforceRatio(100, 10)).toBe(10);
  });
  it("sorts real zero coverage first and missing coverage last without mutating input", () => {
    const children = [null, 120, 0, 50].map((rate, index) => ({
      geoCode: `${index}`,
      geoLevel: "region" as const,
      geoName: `Area ${index}`,
      incomeClass: null,
      fic: toFhsisIndicator(definition, rate === null ? undefined : { ...row, rate_pct: rate }),
    }));
    expect(sortFhsisChildren(children).map((c) => c.fic.rate)).toEqual([0, 50, 120, null]);
    expect(children[0].fic.rate).toBeNull();
  });
  it("permits only the three drill-down levels", () => {
    expect(["region", "province", "citymun"].every(isFhsisLevel)).toBe(true);
    expect(isFhsisLevel("barangay")).toBe(false);
    expect(isFhsisLevel("national")).toBe(false);
  });
});
describe("FHSIS read boundary", () => {
  it("scopes facts to the FHSIS dataset, geography and total breakdown", async () => {
    const facts = query([row]),
      workforce = query([]);
    mocks.from.mockImplementation((table: string) =>
      table === "ref_fhsis_indicator"
        ? query([definition])
        : table === "fact_fhsis_indicator"
          ? facts
          : workforce,
    );
    const area = await getFhsisArea("PH", "national");
    expect(mocks.id).toHaveBeenCalledWith("fhsis-2025");
    for (const args of [
      ["dataset_id", 12],
      ["geo_code", "PH"],
      ["geo_level", "national"],
      ["breakdown", "total"],
    ])
      expect(facts.eq).toHaveBeenCalledWith(...args);
    expect(workforce.neq).toHaveBeenCalledWith("cadre", "bhw");
    expect(area?.indicators[0].rate).toBe(0);
  });
  it("a successful empty result means not reported, while a failed read is unavailable", async () => {
    mocks.from.mockImplementation((table: string) => {
      if (table === "ref_fhsis_indicator") return query([definition]);
      const q = query([]);
      q.limit.mockReturnValue(query([{ id: 99 }]));
      return q;
    });
    expect((await getFhsisArea("PH", "national"))?.indicators[0].reported).toBe(false);
    mocks.from.mockImplementation((table: string) =>
      table === "ref_fhsis_indicator" ? query([definition]) : query(null, { message: "timeout" }),
    );
    expect(await getFhsisArea("PH", "national")).toBeNull();
  });
  it("does not claim nationwide non-reporting when no public total rows are available", async () => {
    mocks.from.mockImplementation((table: string) =>
      query(table === "ref_fhsis_indicator" ? [definition] : []),
    );
    expect(await getFhsisArea("PH", "national")).toBeNull();
  });

  it("includes children with no FIC report rather than dropping them", async () => {
    mocks.children.mockResolvedValue([
      { geoCode: "01", geoLevel: "region", geoName: "Example region", incomeClass: null },
    ]);
    const facts = query([]);
    mocks.from.mockImplementation((table: string) =>
      table === "ref_fhsis_indicator" ? query([definition]) : facts,
    );
    const children = await getFhsisChildren("PH", "national");
    expect(children).toHaveLength(1);
    expect(children?.[0].fic.reported).toBe(false);
    expect(facts.eq).toHaveBeenCalledWith("indicator_key", "fic");
    expect(facts.eq).toHaveBeenCalledWith("breakdown", "total");
  });
});

describe("FHSIS age and sex breakdowns", () => {
  it("retains source groups and counts without inventing sex-specific rates", async () => {
    const facts = query([
      { ...row, breakdown: "male", numerator_exact: 8, denominator: null, rate_pct: null },
    ]);
    mocks.from.mockImplementation((table: string) =>
      table === "ref_fhsis_indicator" ? query([definition]) : facts,
    );
    const result = await getFhsisBreakdowns("PH", "national");
    expect(result?.[0].breakdown).toBe("male");
    expect(result?.[0].numerator).toBe(8);
    expect(result?.[0].rate).toBeNull();
    expect(facts.neq).toHaveBeenCalledWith("breakdown", "total");
    expect(facts.eq).toHaveBeenCalledWith("geo_code", "PH");
    expect(facts.eq).toHaveBeenCalledWith("geo_level", "national");
    expect(facts.eq).toHaveBeenCalledWith("dataset_id", 12);
  });
  it("does not return a partial list after a later page fails", async () => {
    const facts = query([]);
    facts.range
      .mockReturnValueOnce(
        query(Array.from({ length: 500 }, () => ({ ...row, breakdown: "male" }))),
      )
      .mockReturnValueOnce(query(null, { message: "failed" }));
    mocks.from.mockImplementation((table: string) =>
      table === "ref_fhsis_indicator" ? query([definition]) : facts,
    );
    expect(await getFhsisBreakdowns("PH", "national")).toBeNull();
    expect(facts.range).toHaveBeenLastCalledWith(500, 999);
  });
  it("labels counts, age groups and limited geography explicitly", () => {
    expect(fhsisBreakdownLabel("10-14")).toBe("Age 10–14");
    expect(
      fhsisRateLabel(
        toFhsisIndicator({ ...definition, unit: "count" }, { ...row, rate_pct: null }),
      ),
    ).toBe("Count only");
    expect(fhsisLimitedGeography("maternal_hepb_screened")).toBe(true);
    expect(fhsisLimitedGeography("infect_leprosy_new")).toBe(true);
    expect(fhsisLimitedGeography("fp_end_total")).toBe(true);
    expect(fhsisPeriodLabel("fp_demand_q4")).toBe("Q4 2025");
    expect(fhsisPeriodLabel("fp_begin_total")).toBe("Beginning of 2025");
    expect(fhsisPeriodLabel("fp_end_total")).toBe("End of 2025");
    expect(fhsisPeriodLabel("ncd_dec_60plus_htn_total")).toBe("December 2025");
    expect(fhsisLimitedGeography("ncd_senior_ppv")).toBe(true);
    expect(fhsisLimitedGeography("ncd_20_59_assessed")).toBe(false);
    expect(fhsisLimitedGeography("oral_infant_first")).toBe(true);
    expect(fhsisLimitedGeography("school_hpv1")).toBe(true);
    expect(fhsisLimitedGeography("child_ors")).toBe(true);
    expect(fhsisLimitedGeography("nutrition_mam_cured")).toBe(true);
    expect(fhsisLimitedGeography("vax_opv1_0_12m")).toBe(false);
  });
});
