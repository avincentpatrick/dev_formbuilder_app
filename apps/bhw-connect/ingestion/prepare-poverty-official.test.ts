import { expect, it } from "vitest";
import ExcelJS from "exceljs";
import { createHash } from "node:crypto";
import { parseEstimate, prepareWorkbook } from "./prepare-poverty-official.mjs";
it("preserves numeric zero and refuses to guess source symbols", () => {
  expect(parseEstimate(0)).toEqual({ value_status: "available", poverty_incidence: 0 });
  expect(parseEstimate("-")).toEqual({
    value_status: "not_absolute_zero",
    poverty_incidence: null,
  });
  expect(parseEstimate("..").value_status).toBe("unavailable");
  for (const value of ["*", "0", "<0.05", undefined, NaN, 101])
    expect(() => parseEstimate(value)).toThrow();
});
it("checks checksum, population table, year, interval confidence and row labels before extracting", async () => {
  const book = new ExcelJS.Workbook();
  const sheet = book.addWorksheet("tab2");
  sheet.getCell("A1").value = "Poverty Incidence Among Population";
  sheet.getCell("G5").value = 2023;
  sheet.getCell("N4").value = "95% Confidence Interval";
  sheet.getCell("A8").value = "Synthetic area";
  sheet.getCell("G8").value = 0;
  sheet.getCell("R8").value = -0.2;
  sheet.getCell("S8").value = 2;
  const bytes = await book.xlsx.writeBuffer();
  const contract = {
    workbook_sha256: createHash("sha256").update(Buffer.from(bytes)).digest("hex"),
    mapping: [
      { source_table: "tab2", source_row: 8, source_label: "Synthetic area", geo_code: "PH" },
    ],
  };
  const result = await prepareWorkbook(bytes, contract);
  expect(result.rows[0].poverty_incidence).toBe(0);
  expect(result.rows[0].ci_low).toBe(-0.2);
  await expect(prepareWorkbook(bytes, { ...contract, workbook_sha256: "bad" })).rejects.toThrow(
    "checksum",
  );
  await expect(
    prepareWorkbook(bytes, {
      ...contract,
      mapping: [{ ...contract.mapping[0], source_label: "Wrong place" }],
    }),
  ).rejects.toThrow("label");
  await expect(
    prepareWorkbook(bytes, { ...contract, mapping: [...contract.mapping, ...contract.mapping] }),
  ).rejects.toThrow("Ambiguous");
});
