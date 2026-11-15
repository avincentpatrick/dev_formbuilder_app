import { expect, it } from "vitest";
import { parseCountRange, valueFilterState, countFilterSummary } from "./value-filter";
import { initialMapChoice, mapHref, parseMapChoice, type MapPayload } from "./prototype";
import { datasetMapExport } from "./export";
import { pngNotes } from "./png";
const rows = [
  { code: "07012", name: "Bohol", value: 0 },
  { code: "07022", name: "Cebu", value: 100 },
  { code: "07306", name: "Cebu City", value: 50 },
  { code: "07311", name: "Lapu-Lapu", value: null },
  { code: "07313", name: "Mandaue", value: 25 },
];
it("uses inclusive whole-count bounds, retaining zero and separate unavailable states", () => {
  expect(parseCountRange("0", "0")).toEqual({ valueMin: 0, valueMax: 0 });
  expect(rows.map((r) => valueFilterState(r, { valueMin: 25, valueMax: 50 }))).toEqual([
    "Excluded by range",
    "Excluded by range",
    "Included",
    "Unavailable",
    "Included",
  ]);
  expect(countFilterSummary(rows, { valueMin: 101 })).toMatchObject({
    included: 0,
    excluded: 4,
    unavailable: 1,
  });
  for (const min of ["", "-1", "1.5", "NaN", "Infinity", "1e2", "9007199254740992"])
    expect(parseCountRange(min, null)).toBeNull();
  expect(parseCountRange("2", "1")).toBeNull();
  expect(parseCountRange(null, null)).toEqual({});
});
it("round-trips ranges and rejects invalid, duplicate or unsupported range links", () => {
  const choice = { ...initialMapChoice("bhw-stepzero-2026"), valueMin: 0, valueMax: 50 };
  expect(parseMapChoice(new URLSearchParams(mapHref(choice).split("?")[1]))).toEqual(choice);
  for (const query of [
    "valueMin=2&valueMax=1",
    "valueMin=-1",
    "valueMin=0&valueMin=1",
    "valueMax=1.5",
  ])
    expect(parseMapChoice(new URLSearchParams(`dataset=bhw-stepzero-2026&${query}`))).toBeNull();
  expect(
    parseMapChoice(
      new URLSearchParams(
        "dataset=bhw-stepzero-2026&indicator=bhw.declared-accreditation&valueMin=0",
      ),
    ),
  ).toBeNull();
  expect(parseMapChoice(new URLSearchParams("dataset=nhfr-2026-09&valueMin=0"))).toBeNull();
});
it("exports full values and independent geometry with filter states, stable scales and reported total", () => {
  const choice = { ...initialMapChoice("bhw-stepzero-2026"), valueMin: 25, valueMax: 50 };
  const data: MapPayload = {
    state: "available",
    rows,
    total: 999,
    area: "Region VII",
    childLevel: "province",
    source: "Synthetic",
    date: "2025-12-31",
    license: "Test only",
    geometryUrl: "/bhw-connect/geo/provinces/07.json",
  };
  const result = datasetMapExport(choice, data, [], ["07012", "07022"]);
  expect(result.total).toBe(999);
  expect(result.rows.map((r) => r.value)).toEqual([0, 100, 50, null, 25]);
  expect(result.rows[2]).toMatchObject({ filterState: "Included", geometry: "missing" });
  expect(result.rows[1]).toMatchObject({ filterState: "Excluded by range", geometry: "available" });
  expect(result.legend.symbols?.maximum).toBe(100);
  expect(result.valueFilter).toMatchObject({ included: 2, excluded: 2, unavailable: 1 });
  expect(pngNotes(result).join(" ")).toContain("Count range: 25 to 50, inclusive");
});
