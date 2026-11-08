import { expect, it } from "vitest";
import { joinProfilingRows } from "./profiling";
const children = [
  { geo_code: "01", geo_name: "Synthetic" },
  { geo_code: "02", geo_name: "Missing" },
];
const row = {
  geo_code: "01",
  n_total_bhw: 100,
  n_drafted: 10,
  n_for_validation: 20,
  n_back_to_encoder: 5,
  n_validated: 15,
  n_approved: 40,
};
it("uses exclusive stages and source headcount, preserving missing areas", () => {
  for (const [stage, value] of Object.entries({
    encoded: 35,
    validated: 15,
    attested: 40,
    notEncoded: 10,
  })) {
    const result = joinProfilingRows(children, [row], stage);
    expect(result[0].value).toBe(value);
    expect(result[1]).toMatchObject({ value: null, note: "No reported snapshot" });
  }
});
it("preserves over-headcount shares and floors not-encoded as the workflow reader does", () => {
  const over = { ...row, n_approved: 120 };
  expect(joinProfilingRows(children, [over], "attested")[0]).toMatchObject({
    value: 120,
    note: expect.stringContaining("pipeline exceeds headcount"),
  });
  expect(joinProfilingRows(children, [over], "notEncoded")[0].value).toBe(0);
});
it("does not turn zero denominators, malformed or duplicate snapshots into zero shares", () => {
  for (const rows of [
    [{ ...row, n_total_bhw: 0 }],
    [{ ...row, n_drafted: null }],
    [{ ...row, n_validated: -1 }],
    [row, row],
  ])
    expect(joinProfilingRows(children, rows, "encoded")[0].value).toBeNull();
  expect(joinProfilingRows(children, [{ ...row, n_approved: 0 }], "attested")[0].value).toBe(0);
});
it("matches published workflow rounding", () => {
  expect(joinProfilingRows(children, [{ ...row, n_total_bhw: 300 }], "attested")[0].value).toBe(13);
});
