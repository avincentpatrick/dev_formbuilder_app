import { expect, it } from "vitest";
import { readBarangayRows } from "./barangay-reader";

type Page = unknown[] | "error";
/** A fake client: each `from(table)` serves the configured pages in order through range(). */
function fakeDb(tables: Record<string, Page[]>) {
  const calls: { table: string; filters: unknown[][]; range?: [number, number] }[] = [];
  const served: Record<string, number> = {};
  return {
    calls,
    from(table: string) {
      const call = { table, filters: [] as unknown[][], range: undefined as [number, number] | undefined };
      calls.push(call);
      const chain: Record<string, unknown> = {};
      for (const name of ["select", "eq", "in", "order"]) chain[name] = (...args: unknown[]) => { call.filters.push([name, ...args]); return chain; };
      chain.range = (from: number, to: number) => {
        call.range = [from, to];
        const page = tables[table]?.[served[table] ?? 0];
        served[table] = (served[table] ?? 0) + 1;
        return Promise.resolve(page === "error" || page === undefined ? { data: null, error: { message: "x" } } : { data: page, error: null });
      };
      return chain;
    },
  };
}
const children = [
  { geo_code: "0102801001", geo_name: "A" },
  { geo_code: "0102801002", geo_name: "B" },
  { geo_code: "0102801003", geo_name: "C" },
];
const fac = (barangay: string | null, extra: Record<string, unknown> = {}) =>
  ({ barangay_geo_code: barangay, ownership_major: "Government", facility_type: "Barangay Health Station", bed_capacity: 0, ...extra });
const input = (db: ReturnType<typeof fakeDb>, column: string) =>
  readBarangayRows(db as never, { dataset: "nhfr-2026-09", column, datasetId: 7, citymunCode: "0102801", children });

it("counts NHFR facilities per barangay with the municipality summaries' definitions, zeros included", async () => {
  const pages = [[
    fac("0102801001"),
    fac("0102801001", { ownership_major: "Private", facility_type: "Hospital", bed_capacity: 30 }),
    fac("0102801002", { facility_type: "Rural Health Unit", bed_capacity: 4 }),
  ]];
  const value = async (column: string) => (await input(fakeDb({ fact_nhfr_facility: pages }), column)).data.map((r) => r[column]);
  expect(await value("n_facilities")).toEqual([2, 1, 0]);
  expect(await value("n_government")).toEqual([1, 1, 0]);
  expect(await value("n_private")).toEqual([1, 0, 0]);
  expect(await value("n_barangay_health_station")).toEqual([1, 0, 0]);
  expect(await value("n_rural_health_unit")).toEqual([0, 1, 0]);
  expect(await value("n_hospital")).toEqual([1, 0, 0]);
  expect(await value("n_birthing_home")).toEqual([0, 0, 0]);
  expect(await value("total_bed_capacity")).toEqual([30, 4, 0]);
  expect(await value("n_barangays_with_facility")).toEqual([1, 1, 0]);
});
it("reads only the requested dataset and municipality, and reports facilities it cannot place", async () => {
  const db = fakeDb({ fact_nhfr_facility: [[fac("0102801001"), fac(null), fac(""), fac("0999999999")]] });
  const read = await input(db, "n_facilities");
  expect(read.unattributed).toBe(3);
  expect(read.data.map((r) => r.n_facilities)).toEqual([1, 0, 0]);
  expect(db.calls[0].table).toBe("fact_nhfr_facility");
  expect(db.calls[0].filters).toEqual(expect.arrayContaining([["eq", "dataset_id", 7], ["eq", "geo_code", "0102801"]]));
});
it("reports the unplaced part of the indicator's own total: its count, its beds, and nothing for presence", async () => {
  const unplaced = [
    fac(null, { ownership_major: "Private", bed_capacity: 10 }),
    fac("0999999999", { ownership_major: "Government", bed_capacity: 5 }),
  ];
  const pages = [[fac("0102801001", { bed_capacity: 1 }), ...unplaced]];
  const unattributed = async (column: string) => (await input(fakeDb({ fact_nhfr_facility: pages }), column)).unattributed;
  expect(await unattributed("n_facilities")).toBe(2);
  expect(await unattributed("n_government")).toBe(1);
  expect(await unattributed("n_private")).toBe(1);
  expect(await unattributed("total_bed_capacity")).toBe(15);
  expect(await unattributed("n_hospital")).toBe(0);
  expect(await unattributed("n_barangays_with_facility")).toBe(0);
});
it("pages through a large municipality and fails closed on a failed or over-large read", async () => {
  const full = Array.from({ length: 1000 }, () => fac("0102801001"));
  const db = fakeDb({ fact_nhfr_facility: [full, [fac("0102801002")]] });
  const read = await input(db, "n_facilities");
  expect(read.data.map((r) => r.n_facilities)).toEqual([1000, 1, 0]);
  expect(db.calls.map((c) => c.range)).toEqual([[0, 999], [1000, 1999]]);
  await expect(input(fakeDb({ fact_nhfr_facility: [full, "error"] }), "n_facilities")).rejects.toThrow("unavailable");
  await expect(input(fakeDb({ fact_nhfr_facility: Array.from({ length: 20 }, () => full) }), "n_facilities")).rejects.toThrow("cap");
});
it("refuses a column it has no definition for", async () => {
  await expect(input(fakeDb({ fact_nhfr_facility: [[]] }), "n_unknown")).rejects.toThrow("Unsupported");
  await expect(readBarangayRows(fakeDb({}) as never, { dataset: "bhw-2025", column: "n_total", datasetId: 1, citymunCode: "0102801", children })).rejects.toThrow("Unsupported");
});
it("marks UUC barangays as on the list (1) or not (0)", async () => {
  const db = fakeDb({ fact_uuc_phc_barangay: [[{ geo_code: "0102801002" }]] });
  const read = await readBarangayRows(db as never, { dataset: "uuc-phc-2025", column: "n_listed", datasetId: 3, citymunCode: "0102801", children });
  expect(read.data).toEqual([{ geo_code: "0102801001", n_listed: 0 }, { geo_code: "0102801002", n_listed: 1 }, { geo_code: "0102801003", n_listed: 0 }]);
  expect(read.unattributed).toBe(0);
  expect(db.calls[0].filters).toEqual(expect.arrayContaining([["eq", "dataset_id", 3]]));
  await expect(readBarangayRows(fakeDb({ fact_uuc_phc_barangay: ["error"] }) as never, { dataset: "uuc-phc-2025", column: "n_listed", datasetId: 3, citymunCode: "0102801", children })).rejects.toThrow("unavailable");
});
