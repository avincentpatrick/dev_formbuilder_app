import { beforeEach, describe, expect, it, vi } from "vitest";
import { createCatalogReader } from "./reader";
import { CATALOG } from "./definitions";

const mock = vi.hoisted(() => ({ client: vi.fn(), source: vi.fn() }));
vi.mock("@/lib/db/supabase", () => ({ createSupabaseServerClient: mock.client }));
vi.mock("@/lib/db/dataset", () => ({ readDatasetBySlug: mock.source }));
const all = CATALOG.flatMap((d) => d.indicators);
const spec = (id: string) => all.find((s) => s.id === id)!;
const area = { mode: "psgc", code: "0102801", level: "citymun" } as const;

function client(response: { data?: unknown[] | null; error?: unknown; count?: number | null }) {
  const query = {
    select: vi.fn().mockReturnThis(),
    eq: vi.fn().mockReturnThis(),
    in: vi.fn().mockReturnThis(),
    not: vi.fn().mockReturnThis(),
    limit: vi.fn().mockReturnThis(),
    then: (resolve: (r: typeof response) => unknown) => Promise.resolve(resolve(response)),
  };
  const from = vi.fn(() => query);
  mock.client.mockReturnValue({ from });
  return { from, query };
}

beforeEach(() => {
  vi.clearAllMocks();
  mock.source.mockResolvedValue({ state: "unavailable" });
});

describe("catalog read boundary", () => {
  it("pins source slugs and retains the active-profile guard; caches within one request", async () => {
    const db = createCatalogReader();
    await Promise.all([db.source("bhw-2025"), db.source("bhw-2025"), db.source("nhfr-2026-09")]);
    expect(mock.source.mock.calls).toEqual([
      ["bhw-2025", true],
      ["nhfr-2026-09", false],
    ]);
    await createCatalogReader().source("bhw-2025");
    expect(mock.source).toHaveBeenCalledTimes(3);
  });

  it("coalesces training topics into one projected area query with exact dataset and geography", async () => {
    const { query, from } = client({ data: [], error: null });
    const db = createCatalogReader();
    await Promise.all(
      all.filter((s) => s.table === "agg_training").map((s) => db.rows(s, 12, area)),
    );
    expect(from).toHaveBeenCalledTimes(1);
    expect(from).toHaveBeenCalledWith("agg_training");
    expect(query.eq.mock.calls).toEqual([
      ["dataset_id", 12],
      ["geo_code", "0102801"],
      ["geo_level", "citymun"],
    ]);
    expect(query.select).toHaveBeenCalledWith(
      "coverage_pct,topic_slug,n_trained,n_total,ci_low,ci_high",
    );
    expect(query.select.mock.calls.flat()).not.toContain("*");
  });

  it("does not leak selected-location cache into another area, source or request", async () => {
    const { from } = client({ data: [], error: null });
    const db = createCatalogReader(),
      indicator = spec("nhfr.facilities");
    await db.rows(indicator, 12, area);
    await db.rows(indicator, 13, area);
    await db.rows(indicator, 12, { ...area, code: "0102802" });
    await createCatalogReader().rows(indicator, 12, area);
    expect(from).toHaveBeenCalledTimes(4);
  });

  it("counts available zero-capable observations without fetching sample rows", async () => {
    const { query } = client({ count: 7, error: null });
    const result = await createCatalogReader().probe(spec("fhsis.fic"), 10);
    expect(result).toEqual({ state: "available", count: 7, hasSuppressed: false });
    expect(query.select).toHaveBeenCalledWith("rate_pct", { head: true, count: "exact" });
    expect(query.not).toHaveBeenCalledWith("rate_pct", "is", null);
    expect(query.eq.mock.calls).toEqual([
      ["dataset_id", 10],
      ["indicator_key", "fic"],
      ["breakdown", "total"],
    ]);
    expect(query.in).toHaveBeenCalledWith("geo_level", [
      "national",
      "region",
      "province",
      "citymun",
    ]);
  });

  it("never interprets query errors, exceptions, missing counts or truncation as missing source data", async () => {
    for (const response of [
      { data: null, error: { message: "private" } },
      { data: null, error: null },
      { data: Array.from({ length: 1000 }, () => ({})), error: null },
    ]) {
      client(response);
      expect(await createCatalogReader().rows(spec("nhfr.facilities"), 10, area)).toEqual({
        state: "failed",
      });
    }
    client({ count: null, error: null });
    expect(await createCatalogReader().probe(spec("nhfr.facilities"), 10)).toEqual({
      state: "failed",
    });
    mock.client.mockImplementation(() => {
      throw new Error("private credential");
    });
    expect(await createCatalogReader().rows(spec("nhfr.facilities"), 10, area)).toEqual({
      state: "failed",
    });
  });

  it("distinguishes suppressed-only records from no records in All locations", async () => {
    const { query } = client({ count: 3, error: null });
    expect(await createCatalogReader().probe(spec("bhw.honorarium.province"), 10)).toEqual({
      state: "available",
      count: 3,
      hasSuppressed: true,
    });
    expect(query.eq).toHaveBeenCalledWith("is_suppressed", false);
    expect(query.eq).toHaveBeenCalledWith("is_suppressed", true);
  });

  it("reads only public definition metadata once per catalog request", async () => {
    const { from, query } = client({ data: [], error: null });
    const db = createCatalogReader();
    await Promise.all([db.fhsisDefinitions(), db.fhsisDefinitions()]);
    expect(from).toHaveBeenCalledTimes(1);
    expect(from).toHaveBeenCalledWith("ref_fhsis_indicator");
    expect(query.select).toHaveBeenCalledWith(
      "indicator_key,label,unit,numerator_def,denominator_def,source_workbook",
    );
  });
});

it("checks protected cells across all source locations before permitting geographic peers", async () => {
  const { query } = client({ count: 1, data: [] });
  const db = createCatalogReader();
  expect(await db.privacy(spec("bhw.demographics.sex"), 42)).toBe("suppressed");
  expect(await db.privacy(spec("bhw.demographics.sex"), 42)).toBe("suppressed");
  expect(query.eq.mock.calls).toEqual([
    ["dataset_id", 42],
    ["is_suppressed", true],
    ["dimension", "sex"],
  ]);
  expect(query.select).toHaveBeenCalledTimes(1);
  expect(query.in).not.toHaveBeenCalled();
});
