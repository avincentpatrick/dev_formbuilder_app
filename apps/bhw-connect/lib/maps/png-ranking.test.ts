import { expect, it } from "vitest";
import { pngRanking } from "./png-ranking";
import { datasetMapExport } from "./export";
import { initialMapChoice, type MapPayload } from "./prototype";
const data: MapPayload = {
  state: "available",
  rows: [
    { code: "01", name: "Zero", value: 0 },
    { code: "02", name: "B", value: 25 },
    { code: "03", name: "A", value: 25 },
    { code: "04", name: "Maximum", value: 100 },
    { code: "05", name: "Missing", value: null },
  ],
  total: 150,
  area: "Synthetic",
  childLevel: "province",
  source: "Synthetic",
  date: null,
  license: "Test only",
  geometryUrl: "unused",
};
it("reuses full-set scale, includes missing shapes and preserves tie ordering under a filter", () => {
  const context = datasetMapExport(
    { ...initialMapChoice("bhw-stepzero-2026"), valueMax: 25 },
    data,
    [],
    ["01"],
  );
  const result = pngRanking(context)!;
  expect(result.maximum).toBe(100);
  expect(result.rows.map((r) => [r.code, r.value, r.missingGeometry])).toEqual([
    ["03", 25, true],
    ["02", 25, true],
    ["01", 0, false],
  ]);
  expect(result.note).toContain("1 excluded by range; 1 without a usable count");
  expect(context.total).toBe(150);
  expect(context.rows).toHaveLength(5);
});
it("bounds the image summary and names omissions without truncating JSON", () => {
  const context = datasetMapExport(
    initialMapChoice("bhw-stepzero-2026"),
    {
      ...data,
      rows: Array.from({ length: 25 }, (_, i) => ({
        code: String(i),
        name: `Area ${i}`,
        value: i,
      })),
    },
    [],
    [],
  );
  const result = pngRanking(context)!;
  expect(result.rows).toHaveLength(20);
  expect(result.note).toContain("20 of 25 included child areas shown; 5 omitted");
  expect(context.rows).toHaveLength(25);
});
it("keeps zero, empty filtered data, unsupported measures and navigation-only distinct", () => {
  const choice = initialMapChoice("bhw-stepzero-2026");
  expect(pngRanking(datasetMapExport({ ...choice, valueMin: 200 }, data, [], []))!.rows).toEqual(
    [],
  );
  expect(
    pngRanking(
      datasetMapExport({ ...choice, indicator: "bhw.declared-accreditation" }, data, [], []),
    ),
  ).toBeNull();
  expect(
    pngRanking(datasetMapExport(choice, { ...data, valueSupport: "navigation-only" }, [], [])),
  ).toBeNull();
  const zero = pngRanking(datasetMapExport(choice, { ...data, rows: [data.rows[0]] }, [], []))!;
  expect(zero.maximum).toBe(0);
  expect(zero.rows[0].value).toBe(0);
});
