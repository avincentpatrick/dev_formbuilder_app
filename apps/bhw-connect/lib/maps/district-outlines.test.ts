import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import { districtsWithoutOutline } from "./district-outlines";

const d = (districtCode: string, districtName: string) => ({ districtCode, districtName });

describe("districtsWithoutOutline", () => {
  it("reports nothing until the boundary file has loaded", () => {
    expect(districtsWithoutOutline([d("a", "A")], null)).toEqual([]);
  });

  it("lists every district with no feature, by name", () => {
    const rows = [
      d("manila-3rd", "Manila 3rd"),
      d("bataan-1st", "Bataan 1st"),
      d("butuan-at-large", "Butuan"),
    ];
    expect(districtsWithoutOutline(rows, new Set(["bataan-1st"]))).toEqual([
      d("butuan-at-large", "Butuan"),
      d("manila-3rd", "Manila 3rd"),
    ]);
  });

  it("finds districts the committed boundary file really does not draw", () => {
    const file = fileURLToPath(new URL("../../public/geo/districts.json", import.meta.url));
    const geojson = JSON.parse(readFileSync(file, "utf8")) as {
      features: { properties: { geo_code: string } }[];
    };
    const drawn = new Set(geojson.features.map((f) => f.properties.geo_code));
    // butuan-at-large has a member with no source boundary (ingestion/district_boundary_reconciliation.json).
    const missing = districtsWithoutOutline(
      [d("butuan-at-large", "Butuan"), d("bataan-1st", "Bataan 1st")],
      drawn,
    );
    expect(missing.map((m) => m.districtCode)).toEqual(["butuan-at-large"]);
  });
});
