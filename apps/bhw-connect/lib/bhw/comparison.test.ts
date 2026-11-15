import { expect, it } from "vitest";
import {
  comparisonHref,
  parseComparisonSearch,
  parsePublicComparison,
  publicPlace,
} from "./comparison";

it("resolves comparison grammar without choosing an accreditation population", () => {
  const result = parseComparisonSearch("Compare Cebu and Bohol accreditation")!;
  expect(result.valid).toBe(true);
  expect(result.places).toEqual(["cebu", "bohol"]);
  expect(result.measures.map((m) => m.id)).toEqual([
    "bhw.declared-accreditation",
    "bhw.profile-accreditation",
  ]);
  expect(parseComparisonSearch("Compare 01, 02, 03, and 04 headcount")?.places).toEqual([
    "01",
    "02",
    "03",
    "04",
  ]);
  expect(
    parseComparisonSearch("Compare 01 and 02 Registered and accredited (LGU-reported)")?.measures[0]
      .id,
  ).toBe("bhw.registered-accredited");
});
it("keeps requests over four available for user selection instead of truncating", () => {
  const result = parseComparisonSearch("Compare 01, 02, 03, 04, 05 headcount")!;
  expect(result.valid).toBe(true);
  expect(result.places).toHaveLength(5);
  expect(
    parsePublicComparison({ measure: "bhw.total", geos: "01,02,03,04,05" })?.codes,
  ).toHaveLength(5);
});
it("rejects unsupported requests and ambiguous URL settings without fallback", () => {
  expect(parseComparisonSearch("headcount")).toBeNull();
  for (const q of ["Compare Cebu and Bohol", "Compare headcount", "Compare Cebu and accreditation"])
    expect(parseComparisonSearch(q)?.valid).toBe(false);
  for (const params of [
    { measure: "hidden-profiling", geos: "01,02" },
    { measure: "bhw.total", geos: "01,01" },
    { measure: "bhw.total", geos: ["01", "02"] },
    { measure: "bhw.total", geos: "01,02", focus: "03" },
    { measure: "bhw.total", geos: "01,02", districts: "example" },
  ])
    expect(parsePublicComparison(params)).toBeNull();
});
it("serializes measure, all places and phone focus and excludes counts from matches", () => {
  const href = comparisonHref("bhw.profile-accreditation", ["01", "02", "03", "04"], "03");
  const parsed = parsePublicComparison(
    Object.fromEntries(new URL(href, "https://example.test").searchParams),
  );
  expect(parsed?.codes).toEqual(["01", "02", "03", "04"]);
  expect(parsed?.focus).toBe("03");
  expect(parsed?.spec.id).toBe("bhw.profile-accreditation");
  expect(
    publicPlace({
      geoCode: "01",
      geoLevel: "region",
      geoName: "Example",
      parentChain: {},
      nTotal: 987654,
    }),
  ).not.toHaveProperty("nTotal");
});
