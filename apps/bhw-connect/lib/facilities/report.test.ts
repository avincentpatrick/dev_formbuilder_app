import { expect, it } from "vitest";
import { validateReport, parseReportQuery } from "./report";
import { reportFixture } from "./report.fixtures";
it("requires every full table and both exact denominators, preserving a verified zero", () => {
  const r = reportFixture();
  expect(validateReport(r)).toBe(true);
  expect(validateReport({ ...r, types: r.types.slice(1) })).toBe(false);
  expect(validateReport({ ...r, areas: null })).toBe(false);
  const areas = { ...r.areas!, rows: r.areas!.rows.slice(1) };
  expect(validateReport({ ...r, areas })).toBe(false);
  const row = { ...r.areas!.rows[0], barangays: 11 };
  expect(
    validateReport({ ...r, areas: { ...r.areas!, rows: [row, ...r.areas!.rows.slice(1)] } }),
  ).toBe(false);
  const zero = {
    ...r,
    summary: { ...r.summary, count: 0, total: 0, government: 0, private: 0, beds: 0, present: 0 },
    types: [],
    areas: {
      ...r.areas!,
      parent: 0,
      count: 0,
      present: 0,
      rows: r.areas!.rows.map((a) => ({
        ...a,
        count: 0,
        government: 0,
        private: 0,
        present: 0,
        facilityShare: null,
        presenceShare: 0,
      })),
    },
  };
  expect(validateReport(zero)).toBe(true);
});
it("accepts city aggregate scope and rejects filters, duplicates and unsupported roots", () => {
  const r = reportFixture();
  expect(validateReport({ ...r, code: "0102801", level: "citymun", areas: null })).toBe(true);
  expect(parseReportQuery(new URLSearchParams("geoLevel=national&geoCode=PH"))).toEqual({
    code: "PH",
    level: "national",
  });
  for (const q of [
    "geoLevel=barangay&geoCode=0102801001",
    "geoLevel=region&geoCode=PH",
    "geoLevel=national&geoCode=PH&type=Hospital",
    "geoLevel=national&geoCode=PH&geoCode=PH",
    "geoLevel=national&geoCode=PH&facilitySearch=x",
  ]) {
    expect(parseReportQuery(new URLSearchParams(q), true)).toBeNull();
  }
});
