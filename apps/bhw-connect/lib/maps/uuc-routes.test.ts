import { expect, it } from "vitest";
import { joinUucRouteRows } from "./uuc-routes";
const children = [
  { geo_code: "01", geo_name: "Synthetic" },
  { geo_code: "02", geo_name: "Missing" },
];
const row = {
  geo_code: "01",
  n_listed: 10,
  n_health_evaluable: 6,
  n_route_ip: 8,
  n_route_conflict: 7,
  n_route_four_ps: 3,
  n_route_health: 4,
};
it("preserves overlapping counts without adding or averaging routes", () => {
  for (const column of ["n_route_ip", "n_route_conflict", "n_route_four_ps"] as const) {
    const result = joinUucRouteRows(children, [row], column);
    expect(result[0]).toMatchObject({
      value: row[column],
      note: "10 listed barangays; routes overlap",
    });
    expect(result[1]).toMatchObject({ value: null, note: "No reported route count" });
  }
});
it("retains the health-evaluable denominator and excluded barangays", () => {
  expect(joinUucRouteRows(children, [row], "n_route_health")[0]).toMatchObject({
    value: 4,
    note: "6 health-evaluable of 10 listed barangays; 4 excluded from health evaluation",
  });
});
it("keeps a reported zero count even with no evaluable denominator", () => {
  expect(
    joinUucRouteRows(
      children,
      [{ ...row, n_route_health: 0, n_health_evaluable: 0 }],
      "n_route_health",
    )[0],
  ).toMatchObject({
    value: 0,
    note: expect.stringContaining("no evaluable denominator for a share"),
  });
});
it("fails closed on inconsistent, invalid or ambiguous source rows", () => {
  for (const rows of [
    [row, row],
    [{ ...row, n_route_health: 7 }],
    [{ ...row, n_listed: 2 }],
    [{ ...row, n_health_evaluable: null }],
    [{ ...row, n_route_health: -1 }],
    [{ ...row, n_route_health: 1.5 }],
  ]) {
    expect(joinUucRouteRows(children, rows, "n_route_health")[0].value).toBeNull();
  }
});
