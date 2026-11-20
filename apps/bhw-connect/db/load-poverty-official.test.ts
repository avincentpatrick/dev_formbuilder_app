import { expect, it, vi } from "vitest";
import { CONTRACT } from "../ingestion/prepare-poverty-official.mjs";
import { validateBundle, loadBundle } from "./load-poverty-official.mjs";
function bundle() {
  return {
    format: "bhw-poverty-official-v1",
    dataset_slug: CONTRACT.dataset_slug,
    source_url: CONTRACT.source_url,
    workbook_sha256: CONTRACT.workbook_sha256,
    rows: CONTRACT.mapping.map((m: Record<string, unknown>) => ({
      ...m,
      reference_year: 2023,
      poverty_incidence: 0,
      ci_low: -0.1,
      ci_high: 3,
      cv: 12,
      is_suppressed: false,
      value_status: "available",
      workbook_sha256: CONTRACT.workbook_sha256,
      publication_note: "Synthetic test observation, not a PSA value.",
    })),
  };
}
it("rejects changed geography, incomplete coverage and contradictory state", () => {
  const b = bundle();
  expect(() => validateBundle(b)).not.toThrow();
  expect(() => validateBundle({ ...b, rows: b.rows.slice(1) })).toThrow("Incomplete");
  b.rows[0].geo_code = "wrong";
  expect(() => validateBundle(b)).toThrow("geography");
  const c = bundle();
  c.rows[0].value_status = "not_absolute_zero";
  expect(() => validateBundle(c)).toThrow("estimate");
});
it("rolls back a successful dry run and never overwrites a registered release", async () => {
  const b = bundle();
  const query = vi.fn(async (sql: string) => ({
    rows: sql.startsWith("SELECT geo_code")
      ? b.rows
      : sql.startsWith("INSERT INTO public.dim_dataset")
        ? [{ dataset_id: 99 }]
        : [],
  }));
  expect(await loadBundle({ query }, b)).toEqual({ applied: false, rows: b.rows.length });
  expect(query.mock.calls.at(-1)?.[0]).toBe("ROLLBACK");
  query.mockImplementation(async (sql: string) => ({
    rows: sql.startsWith("SELECT geo_code") ? b.rows : [{ dataset_id: 99 }],
  }));
  await expect(loadBundle({ query }, b, { apply: true })).rejects.toThrow("already registered");
  expect(query.mock.calls.at(-1)?.[0]).toBe("ROLLBACK");
});
