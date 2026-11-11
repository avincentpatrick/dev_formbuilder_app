import { describe, expect, it } from "vitest";
import { colorForValue } from "@/lib/charts/color-scale";
import {
  DISTRICT_COLORS,
  OUTSIDE_COLOR,
  OUTSIDE_VALUE,
  SHARED_COLOR,
  SHARED_VALUE,
  assignMunicipalities,
  districtIndicatorOptions,
  districtMemberLayer,
  districtViewHref,
  memberBins,
  parseDistrictView,
  provinceDistrictLayer,
  shortDistrictName,
} from "./district-view";
import { STEPZERO_ACCREDITATION_BINS } from "./stepzero-accreditation";

const districts = [
  { code: "x-2nd", name: "Xprov's 2nd congressional district" },
  { code: "x-1st", name: "Xprov's 1st congressional district" },
  { code: "x-10th", name: "Xprov's 10th congressional district" },
  { code: "city-lone", name: "Cityville's lone congressional district" },
];
const children = [
  { geo_code: "9900101", geo_name: "Alpha" },
  { geo_code: "9900102", geo_name: "Beta" },
  { geo_code: "9900103", geo_name: "Big City" },
  { geo_code: "9900104", geo_name: "Unplaced" },
  { geo_code: "9900105", geo_name: "Partly placed city" },
];
const membership = [
  { district_code: "x-1st", geo_code: "9900101", geo_level: "citymun" },
  { district_code: "x-2nd", geo_code: "9900102", geo_level: "citymun" },
  { district_code: "x-2nd", geo_code: "9900103001", geo_level: "barangay" },
  { district_code: "x-10th", geo_code: "9900103002", geo_level: "barangay" },
  { district_code: "x-10th", geo_code: "9900103003", geo_level: "barangay" },
  { district_code: "city-lone", geo_code: "9900105001", geo_level: "barangay" },
  // A row for a municipality outside this province is ignored, not invented into a child.
  { district_code: "x-1st", geo_code: "8800101", geo_level: "citymun" },
];

describe("shortDistrictName", () => {
  it("shortens the source's long form and keeps any other form", () => {
    expect(shortDistrictName("Bohol's 3rd congressional district")).toBe("Bohol 3rd");
    expect(shortDistrictName("Biñan's lone congressional district")).toBe("Biñan (lone)");
    expect(shortDistrictName("Taguig–Pateros's 1st congressional district")).toBe(
      "Taguig–Pateros 1st",
    );
    expect(shortDistrictName("Something else")).toBe("Something else");
  });
});

describe("assignMunicipalities", () => {
  const assigned = assignMunicipalities(children, membership, districts);
  it("places each municipality, including through its barangays, in district-name order", () => {
    expect(assigned.map((m) => [m.code, m.districts, m.byBarangay])).toEqual([
      ["9900101", ["x-1st"], false],
      ["9900102", ["x-2nd"], false],
      ["9900103", ["x-2nd", "x-10th"], true],
      ["9900104", [], false],
      ["9900105", ["city-lone"], true],
    ]);
  });
});

describe("provinceDistrictLayer", () => {
  const assigned = assignMunicipalities(children, membership, districts);
  const layer = provinceDistrictLayer(assigned, districts);
  it("colours one district per category, a divided city in the shared colour, unplaced grey", () => {
    expect(layer.present.map((d) => d.code)).toEqual(["city-lone", "x-1st", "x-2nd", "x-10th"]);
    const value = (code: string) => layer.rows.find((r) => r.code === code)!.value;
    expect(value("9900103")).toBe(SHARED_VALUE);
    expect(value("9900104")).toBeNull();
    expect(colorForValue(value("9900103")!, layer.bins)).toBe(SHARED_COLOR);
    const colours = ["9900105", "9900101", "9900102"].map((c) =>
      colorForValue(value(c)!, layer.bins),
    );
    expect(colours).toEqual(DISTRICT_COLORS.slice(0, 3));
    expect(layer.labels[value("9900101")!]).toBe("Xprov 1st");
    expect(layer.labels[SHARED_VALUE]).toBe("Divided between districts");
  });
  it("says what each colour means in words, for the table", () => {
    const note = (code: string) => layer.rows.find((r) => r.code === code)!.note;
    expect(note("9900103")).toBe("Divided between Xprov 2nd and Xprov 10th, barangay by barangay.");
    expect(note("9900104")).toMatch(/not no district/);
    expect(note("9900105")).toMatch(/^Cityville \(lone\), placed barangay by barangay/);
  });
  it("offers no shared category when nothing is divided", () => {
    const simple = assignMunicipalities(children.slice(0, 2), membership, districts);
    expect(provinceDistrictLayer(simple, districts).bins.map((b) => b.min)).toEqual([1, 2]);
  });
});

describe("districtMemberLayer", () => {
  const assigned = assignMunicipalities(children, membership, districts);
  const values = [
    { code: "9900101", name: "Alpha", value: 5 },
    { code: "9900102", name: "Beta", value: 0, note: "Source note." },
    { code: "9900103", name: "Big City", value: 40, smallSample: true },
    { code: "9900104", name: "Unplaced", value: 7 },
  ];
  const layer = districtMemberLayer(values, assigned, "x-2nd", districts, (v) =>
    memberBins("nhfr-2026-09", "nhfr.facilities", v),
  );
  it("lists members only, keeps zero, and marks a divided city's figure as the whole city's", () => {
    expect(layer.table.map((r) => [r.code, r.value])).toEqual([
      ["9900102", 0],
      ["9900103", 40],
    ]);
    expect(layer.table[0].note).toBe("Source note.");
    expect(layer.table[1].note).toMatch(
      /^Whole-city figure: the city is divided between Xprov 2nd and Xprov 10th/,
    );
    expect(layer.table[1].smallSample).toBe(true);
  });
  it("draws every other municipality pale as context, never as a value", () => {
    const outside = layer.canvasRows.filter((r) => r.value === OUTSIDE_VALUE).map((r) => r.code);
    expect(outside).toEqual(["9900101", "9900104", "9900105"]);
    expect(colorForValue(OUTSIDE_VALUE, layer.bins)).toBe(OUTSIDE_COLOR);
    expect(colorForValue(0, layer.bins)).not.toBe(OUTSIDE_COLOR);
  });
  it("keeps a member with no published figure as not reported", () => {
    const empty = districtMemberLayer([], assigned, "x-1st", districts, () => []);
    expect(empty.table).toEqual([{ code: "9900101", name: "Alpha", value: null }]);
    expect(empty.canvasRows.find((r) => r.code === "9900101")!.value).toBeNull();
  });
});

describe("memberBins", () => {
  it("uses the same fixed breaks as the other maps for published shares", () => {
    expect(memberBins("bhw-stepzero-2026", "bhw.declared-accreditation", [10])).toBe(
      STEPZERO_ACCREDITATION_BINS,
    );
    expect(memberBins("nhfr-2026-09", "nhfr.facilities", [1, 2, 3]).length).toBeGreaterThan(0);
  });
});

describe("indicator options and the share link", () => {
  const options = districtIndicatorOptions();
  it("offers municipal figures from public releases, never income classes or hidden releases", () => {
    expect(options.length).toBeGreaterThan(5);
    expect(
      options.some((o) => o.dataset === "bhw-stepzero-2026" && o.indicator === "bhw.total"),
    ).toBe(true);
    expect(options.some((o) => o.dataset === "lgu-income-class")).toBe(false);
    expect(options.some((o) => o.dataset === "bhw-profiling-status-2026")).toBe(false);
    expect(options.some((o) => o.dataset === "ph-legislative-districts")).toBe(false);
  });
  it("round-trips a district view and refuses malformed values", () => {
    const state = {
      view: "district" as const,
      province: "07012",
      district: "bohol-3rd",
      dataset: options[1].dataset,
      indicator: options[1].indicator,
    };
    const href = districtViewHref(state);
    expect(href.startsWith("/map?dataset=ph-legislative-districts&level=national&code=PH")).toBe(
      true,
    );
    expect(parseDistrictView(new URLSearchParams(href.split("?")[1]))).toEqual(state);
    const bad = parseDistrictView(
      new URLSearchParams("view=district&province=7012&district=Bohol 3rd&figures=x&figure=y"),
    );
    expect(bad).toEqual({
      view: "province",
      province: null,
      district: null,
      dataset: options[0].dataset,
      indicator: options[0].indicator,
    });
  });
});
