import { expect, it } from "vitest";
import { searchMapAreas } from "./area-search";
const rows = [
  { code: "0100101", name: "Santo Niño", value: null },
  { code: "0100102", name: "San José", value: 0 },
  { code: "0200101", name: "Santo Tomas", value: 42 },
];
it("matches accent-insensitive names and every whitespace-separated term in any order", () => {
  expect(searchMapAreas(rows, "  NINO   santo ")).toEqual([rows[0]]);
  expect(searchMapAreas(rows, "JOSE")).toEqual([rows[1]]);
});
it("retains leading-zero codes and supports combined name/code queries", () => {
  expect(searchMapAreas(rows, "0100102")).toEqual([rows[1]]);
  expect(searchMapAreas(rows, "santo 020")).toEqual([rows[2]]);
});
it("keeps zero/missing rows and never changes inputs or invents matches", () => {
  expect(searchMapAreas(rows, " ")).toEqual(rows);
  expect(searchMapAreas(rows, "not an area")).toEqual([]);
  expect(rows.map((row) => row.value)).toEqual([null, 0, 42]);
});
