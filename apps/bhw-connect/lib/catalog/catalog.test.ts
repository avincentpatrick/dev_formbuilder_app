import { describe, expect, it, vi } from "vitest";
import { getPublicCatalog } from "./catalog";
import { ACCEPTED_RELEASES, CATALOG, FHSIS_KEYS, TRAINING_TOPICS } from "./definitions";
import type { CatalogReader, Row } from "./reader";
import { DATASET_SLUGS } from "@/lib/db/dataset";
import { POVERTY_SAE_SLUG } from "@/lib/db/poverty";

function reader(tables: Record<string, Row[]> = {}): CatalogReader {
  return {
    source: vi.fn<CatalogReader["source"]>(async (slug) => ({
      state: "available",
      value: {
        datasetId: 42,
        slug,
        name: slug,
        sourceName: "Accepted public source",
        license: null,
        asOfDate: "2025-12-31",
        lastUpdatedAt: "2026-10-01T00:00:00Z",
      },
    })),
    rows: vi.fn<CatalogReader["rows"]>(async (spec) => ({
      state: "available",
      rows: tables[spec.table] ?? [],
    })),
    probe: vi.fn<CatalogReader["probe"]>(async () => ({
      state: "available",
      count: 0,
      hasSuppressed: false,
    })),
    fhsisDefinitions: vi.fn<CatalogReader["fhsisDefinitions"]>(async () => ({
      state: "available",
      rows: FHSIS_KEYS.map((key) => ({
        indicator_key: key,
        label: `Published ${key}`,
        unit: key === "tb_notified" ? "cases per 100,000 population" : "percent",
        numerator_def: "Source numerator",
        denominator_def: "Source denominator",
        source_workbook: "Public release.xlsx",
      })),
    })),
  };
}
const area = { mode: "psgc", level: "citymun", code: "0102801" } as const;
const find = (result: Awaited<ReturnType<typeof getPublicCatalog>>, id: string) =>
  result.datasets.flatMap((d) => d.indicators).find((i) => i.id === id)!;

describe("public catalog contract", () => {
  it("has exactly six stable identities and unique canonical indicators independent of releases", () => {
    expect(CATALOG.map((d) => d.id)).toEqual([
      "bhw",
      "nhfr",
      "uuc-phc",
      "fhsis",
      "population",
      "poverty",
    ]);
    const ids = CATALOG.flatMap((d) => d.indicators.map((i) => i.id));
    expect(new Set(ids).size).toBe(ids.length);
    expect(ids.some((id) => /profiling-status|district|2025|2026/.test(id))).toBe(false);
    expect(TRAINING_TOPICS).toHaveLength(44);
    expect(FHSIS_KEYS).toHaveLength(20);
    expect(ACCEPTED_RELEASES).toEqual({
      bhw: DATASET_SLUGS.profiled,
      stepzero: DATASET_SLUGS.stepzero,
      nhfr: DATASET_SLUGS.nhfr,
      uuc: DATASET_SLUGS.uucPhc,
      fhsis: DATASET_SLUGS.fhsis,
      population: DATASET_SLUGS.popcen2024,
      poverty: POVERTY_SAE_SLUG,
    });
  });

  it("preserves zeros, hides no mixed-availability dataset, and counts canonical measures", async () => {
    const result = await getPublicCatalog(
      area,
      reader({
        agg_nhfr_counts: [
          {
            n_facilities: 0,
            n_government: null,
            n_private: null,
            n_barangay_health_station: null,
            n_rural_health_unit: null,
            n_hospital: 0,
            n_birthing_home: null,
            total_bed_capacity: null,
            n_barangays_with_facility: 0,
            n_barangays: 10,
          },
        ],
      }),
    );
    expect(find(result, "nhfr.facilities")).toMatchObject({
      state: "available",
      values: [{ value: 0 }],
    });
    expect(find(result, "nhfr.barangay-presence")).toMatchObject({
      values: [{ value: 0, denominator: 10 }],
    });
    expect(result.datasets.find((d) => d.id === "nhfr")).toMatchObject({
      state: "partial",
      availableIndicators: 3,
      totalIndicators: 9,
    });
    expect(result.overview.availableDatasets).toBe(1);
    expect(result.overview.availableIndicators).toBe(3);
  });

  it("distinguishes missing records, failed queries, missing metadata and failed metadata", async () => {
    const db = reader();
    db.source = vi.fn<CatalogReader["source"]>(async (slug) =>
      slug === ACCEPTED_RELEASES.nhfr ? { state: "failed" } : { state: "unavailable" },
    );
    const result = await getPublicCatalog(area, db);
    expect(find(result, "nhfr.facilities").state).toBe("failed");
    expect(find(result, "bhw.total").state).toBe("unavailable");
    expect(db.rows).not.toHaveBeenCalled();
    const failed = reader();
    failed.rows = vi.fn<CatalogReader["rows"]>(async (spec) =>
      spec.table === "agg_poverty" ? { state: "failed" } : { state: "available", rows: [] },
    );
    const mixed = await getPublicCatalog(area, failed);
    expect(find(mixed, "poverty.incidence").state).toBe("failed");
    expect(find(mixed, "nhfr.facilities").state).toBe("unavailable");
  });

  it("catches rejected reads without exposing errors or disabling other datasets", async () => {
    const db = reader({ agg_population: [{ census_year: 2024, population: 100 }] });
    const original = db.rows;
    db.rows = vi.fn<CatalogReader["rows"]>(async (...args) => {
      if (args[0].table === "agg_nhfr_counts") throw new Error("private connection credential");
      return original(...args);
    });
    const result = await getPublicCatalog(area, db);
    expect(find(result, "nhfr.facilities").state).toBe("failed");
    expect(find(result, "population.residents")).toMatchObject({
      state: "available",
      values: [{ value: 100 }],
    });
    expect(JSON.stringify(result)).not.toContain("private connection");
  });

  it("keeps total workforce and profiles separate, with no FHSIS replacement", async () => {
    const result = await getPublicCatalog(
      area,
      reader({
        agg_bhw_stepzero_counts: [
          {
            n_total_bhw: 100,
            n_registered: 60,
            n_registered_accredited: 30,
            n_non_registered: 10,
            pct_registered_accredited: 30,
          },
        ],
        agg_bhw_counts: [
          {
            n_total: 80,
            n_accredited: 60,
            pct_accredited: 75,
            avg_active_years: 3,
            any_honorarium_pct: 0,
          },
        ],
        fact_fhsis_workforce: [
          { cadre: "bhw", total: 999 },
          { cadre: "nurse", total: 2 },
        ],
      }),
    );
    expect(find(result, "bhw.total").values).toEqual([{ value: 100 }]);
    expect(find(result, "bhw.validated-profiles").values).toEqual([{ value: 80 }]);
    expect(find(result, "bhw.profile-accreditation").values[0]).toMatchObject({
      value: 75,
      numerator: 60,
      denominator: 80,
    });
    expect(find(result, "bhw.declared-accreditation").values[0]).toMatchObject({
      value: 30,
      numerator: 30,
      denominator: 100,
    });
    expect(find(result, "fhsis.workforce.nurse").values).toEqual([{ value: 2 }]);
    expect(JSON.stringify(result)).not.toContain("999");
  });

  it("preserves accepted census year and never substitutes StepZero or a parent", async () => {
    const db = reader({
      agg_population: [
        { census_year: 2020, population: 900 },
        { census_year: 2024, population: 100 },
      ],
      agg_bhw_stepzero_counts: [{ population: 999 }],
    });
    const result = await getPublicCatalog(area, db);
    expect(find(result, "population.residents").values).toEqual([{ value: 100 }]);
    const national = await getPublicCatalog({ mode: "psgc", level: "national", code: "PH" }, db);
    expect(find(national, "population.residents")).toMatchObject({
      state: "partial",
      reason: expect.stringContaining("Partial total"),
    });
    const barangay = await getPublicCatalog(
      { mode: "psgc", level: "barangay", code: "0102801001" },
      db,
    );
    expect(find(barangay, "population.residents").state).toBe("unsupported");
    expect(barangay.datasets.every((d) => d.detailHref === null)).toBe(true);
  });

  it("leaves whole-city figures out of district and barangay scopes", async () => {
    const db = reader();
    for (const selection of [
      {
        mode: "district",
        districtCode: "split-city-1st",
        citymunCode: "0102801",
        barangayCode: "0102801001",
      } as const,
      { mode: "psgc", level: "barangay", code: "0102801001" } as const,
    ]) {
      const result = await getPublicCatalog(selection, db);
      expect(result.selection).toEqual(selection);
      expect(result.datasets.every((d) => d.state === "unsupported" && d.detailHref === null)).toBe(
        true,
      );
    }
    expect(db.rows).not.toHaveBeenCalled();
    expect(db.source).not.toHaveBeenCalled();
  });

  it("uses source FHSIS definitions and units; no capped percentages or borrowed denominators", async () => {
    const result = await getPublicCatalog(
      area,
      reader({
        fact_fhsis_indicator: [
          {
            indicator_key: "fic",
            breakdown: "total",
            rate_pct: 120,
            numerator: 120,
            denominator: 100,
          },
          { indicator_key: "fic", breakdown: "male", rate_pct: 999 },
          {
            indicator_key: "tb_notified",
            breakdown: "total",
            rate_pct: 500,
            numerator: 5,
            denominator: 1000,
          },
          { indicator_key: "anc8", breakdown: "total", rate_pct: 25, numerator: 1, denominator: 4 },
        ],
      }),
    );
    expect(find(result, "fhsis.fic")).toMatchObject({
      label: "Published fic",
      values: [{ value: 120, numerator: 120, denominator: 100 }],
      definition: { denominator: "Source denominator" },
    });
    expect(find(result, "fhsis.fic").reason).toContain("true coverage is unknown");
    expect(find(result, "fhsis.tb_notified").reason).toBeNull();
    expect(find(result, "fhsis.tb_notified").unit).toBe("cases per 100,000 population");
    expect(find(result, "fhsis.anc8")).toMatchObject({
      state: "partial",
      reason: expect.stringContaining("Q3–Q4"),
    });
    expect(find(result, "fhsis.water_basic").state).toBe("unavailable");
  });

  it("does not equate an FHSIS dictionary or failed probe with a populated release", async () => {
    const db = reader();
    const empty = await getPublicCatalog({ mode: "all" }, db);
    expect(empty.datasets.find((d) => d.id === "fhsis")?.state).toBe("unavailable");
    db.probe = vi.fn<CatalogReader["probe"]>(async () => ({ state: "failed" }));
    expect(find(await getPublicCatalog({ mode: "all" }, db), "fhsis.fic").state).toBe("failed");
    db.fhsisDefinitions = vi.fn<CatalogReader["fhsisDefinitions"]>(async () => ({
      state: "failed",
    }));
    expect(find(await getPublicCatalog(area, db), "fhsis.fic").state).toBe("failed");
  });

  it("preserves partial health evaluability and source-specific coverage denominators", async () => {
    const result = await getPublicCatalog(
      area,
      reader({
        agg_uuc_phc_counts: [{ n_listed: 8, n_barangays: 10 }],
        agg_uuc_phc_criteria: [
          {
            n_listed: 8,
            n_health_evaluable: 5,
            n_route_ip: 3,
            n_route_conflict: 4,
            n_route_four_ps: 6,
            n_route_health: 2,
          },
        ],
        agg_nhfr_counts: [{ n_barangays_with_facility: 8, n_barangays: 12 }],
      }),
    );
    expect(find(result, "uuc.route.health")).toMatchObject({
      state: "partial",
      values: [{ value: 2, denominator: 5 }],
    });
    expect(find(result, "uuc.listed").values).toEqual([{ value: 8, denominator: 10 }]);
    expect(find(result, "nhfr.barangay-presence").values).toEqual([{ value: 8, denominator: 12 }]);
  });

  it("applies complementary suppression before composing public values with totals", async () => {
    const result = await getPublicCatalog(
      area,
      reader({
        agg_bhw_counts: [{ n_total: 43 }],
        agg_demographics: [
          { dimension: "sex", category: "A", n: 40, is_suppressed: false },
          { dimension: "sex", category: "B", n: null, is_suppressed: true },
          { dimension: "education", category: "A", n: 0, is_suppressed: false },
          { dimension: "education", category: "B", n: null, is_suppressed: true },
        ],
        agg_honorarium: [
          { payer_level: "province", pct_receiving: 3, n_receiving: 3, is_suppressed: true },
        ],
      }),
    );
    expect(find(result, "bhw.demographics.sex")).toMatchObject({ state: "suppressed", values: [] });
    expect(find(result, "bhw.demographics.education")).toMatchObject({
      state: "suppressed",
      values: [],
    });
    expect(find(result, "bhw.honorarium.province")).toMatchObject({
      state: "suppressed",
      values: [],
    });
    expect(result.overview.suppressedIndicators).toBe(3);
  });

  it("whitelists public output instead of spreading rows or dataset metadata", async () => {
    const result = await getPublicCatalog(
      area,
      reader({
        agg_population: [
          {
            census_year: 2024,
            population: 0,
            private_notes: "INTERNAL",
            name: "PERSON",
            source_payload: "RAW",
          },
        ],
      }),
    );
    const json = JSON.stringify(result);
    for (const secret of ["INTERNAL", "PERSON", "RAW", "private_notes"])
      expect(json).not.toContain(secret);
  });

  it("All locations exposes availability without national sample values and counts source coverage separately", async () => {
    const db = reader();
    db.probe = vi.fn<CatalogReader["probe"]>(async (spec) => ({
      state: "available",
      count: spec.id === "nhfr.facilities" ? 8 : spec.id === "uuc.listed" ? 10 : 0,
      hasSuppressed: false,
    }));
    const result = await getPublicCatalog({ mode: "all" }, db);
    expect(db.rows).not.toHaveBeenCalled();
    expect(result.datasets.flatMap((d) => d.indicators).every((i) => i.values.length === 0)).toBe(
      true,
    );
    expect(result.overview.availableDatasets).toBe(2);
    expect(result.overview.availableIndicators).toBe(2);
    expect(find(result, "nhfr.facilities").reportedAreaRecords).toBe(8);
    expect(find(result, "uuc.listed").reportedAreaRecords).toBe(10);
    expect(result.overview.freshness).toMatchObject({
      oldestSourceDate: "2025-12-31",
      latestUpdate: "2026-10-01T00:00:00Z",
      sourcesWithUnknownDate: 0,
    });
  });

  it("keeps suppressed-only availability out of counts and freshness", async () => {
    const db = reader();
    db.probe = vi.fn<CatalogReader["probe"]>(async (spec) => ({
      state: "available",
      count: 0,
      hasSuppressed: !!spec.suppression,
    }));
    const result = await getPublicCatalog({ mode: "all" }, db);
    expect(find(result, "bhw.demographics.sex").state).toBe("suppressed");
    expect(result.overview.availableDatasets).toBe(0);
    expect(result.overview.freshness.latestUpdate).toBeNull();
  });

  it("fails closed on malformed values and absent privacy flags", async () => {
    const result = await getPublicCatalog(
      area,
      reader({
        agg_population: [{ census_year: 2024, population: "not a number" }],
        agg_honorarium: [{ payer_level: "province", pct_receiving: 1, n_receiving: 1 }],
      }),
    );
    expect(find(result, "population.residents").state).toBe("failed");
    expect(find(result, "bhw.honorarium.province")).toMatchObject({ state: "failed", values: [] });
  });

  it("keeps a published Manila province census value at that node only", async () => {
    const result = await getPublicCatalog(
      { mode: "psgc", level: "province", code: "13806" },
      reader({ agg_population: [{ census_year: 2024, population: 100 }] }),
    );
    expect(find(result, "population.residents")).toMatchObject({
      state: "available",
      values: [{ value: 100 }],
    });
  });

  it("bounds All-locations query concurrency across all six datasets", async () => {
    const db = reader();
    let running = 0,
      maximum = 0;
    db.probe = vi.fn<CatalogReader["probe"]>(async () => {
      running++;
      maximum = Math.max(maximum, running);
      await new Promise((resolve) => setTimeout(resolve, 0));
      running--;
      return { state: "available", count: 0, hasSuppressed: false };
    });
    await getPublicCatalog({ mode: "all" }, db);
    expect(maximum).toBeLessThanOrEqual(4);
    expect(db.probe).toHaveBeenCalledTimes(CATALOG.flatMap((d) => d.indicators).length);
  });

  it("never calls a reader for invalid selections", async () => {
    const db = reader();
    await expect(
      getPublicCatalog({ mode: "psgc", level: "national", code: "01" }, db),
    ).rejects.toThrow("Invalid catalog");
    expect(db.source).not.toHaveBeenCalled();
  });
});
