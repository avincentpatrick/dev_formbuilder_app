#!/usr/bin/env node
import { createHash } from "node:crypto";
import { readFileSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";
import ExcelJS from "exceljs";

export const CONTRACT = JSON.parse(
  readFileSync(new URL("./poverty-official-2023-contract.json", import.meta.url), "utf8"),
);
export function parseEstimate(value) {
  if (typeof value === "number" && Number.isFinite(value) && value >= 0 && value <= 100)
    return { value_status: "available", poverty_incidence: value };
  if (value === "-") return { value_status: "not_absolute_zero", poverty_incidence: null };
  if (value === ".." || value === null)
    return { value_status: "unavailable", poverty_incidence: null };
  throw new Error("Unrecognized estimate/footnote: requires source review, never coercion to zero");
}
function numberOrNull(value) {
  if (value === null || value === ".." || value === "-") return null;
  if (typeof value !== "number" || !Number.isFinite(value))
    throw new Error("Unrecognized precision value");
  return value;
}
export async function prepareWorkbook(bytes, contract = CONTRACT) {
  const hash = createHash("sha256").update(bytes).digest("hex");
  if (hash !== contract.workbook_sha256)
    throw new Error("Workbook checksum differs from the audited release");
  const book = new ExcelJS.Workbook();
  await book.xlsx.load(bytes);
  const seen = new Set();
  const rows = contract.mapping.map((mapping) => {
    const sheet = book.getWorksheet(mapping.source_table);
    if (
      !sheet ||
      !sheet.getCell("A1").text.includes("Poverty Incidence Among Population") ||
      sheet.getCell("G5").value !== 2023 ||
      sheet.getCell("N4").text !== "95% Confidence Interval"
    )
      throw new Error("Unexpected table, population, year or confidence level");
    const row = sheet.getRow(mapping.source_row);
    if (row.getCell(1).text.trim() !== mapping.source_label)
      throw new Error("Source label/row mismatch");
    if (seen.has(mapping.geo_code)) throw new Error("Ambiguous reporting geography");
    seen.add(mapping.geo_code);
    const precisionNote = row.getCell(20).text.trim();
    const result = {
      ...mapping,
      reference_year: 2023,
      ...parseEstimate(row.getCell(7).value),
      ci_low: numberOrNull(row.getCell(18).value),
      ci_high: numberOrNull(row.getCell(19).value),
      cv: numberOrNull(row.getCell(10).value),
      is_suppressed: false,
      workbook_sha256: hash,
      publication_note: `Original footnote markers: ${precisionNote || "none"}. See the linked workbook for all footnotes.${/(^|,\s*)\*(,|$)/.test(precisionNote) ? " PSA cautions that this HUC has a small sample size." : ""}`,
    };
    if (
      (result.ci_low !== null && result.ci_high !== null && result.ci_low > result.ci_high) ||
      (result.cv !== null && result.cv < 0)
    )
      throw new Error("Invalid precision fields");
    return result;
  });
  return {
    format: "bhw-poverty-official-v1",
    dataset_slug: contract.dataset_slug,
    source_url: contract.source_url,
    workbook_sha256: hash,
    retrieved: new Date().toISOString(),
    rows,
    excluded: contract.excluded,
  };
}
if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const [, , input, output] = process.argv;
  if (!input || !output)
    throw new Error(
      "Usage: node ingestion/prepare-poverty-official.mjs private-workbook.xlsx private-bundle.json",
    );
  const bundle = await prepareWorkbook(readFileSync(input));
  writeFileSync(output, JSON.stringify(bundle, null, 2) + "\n", { flag: "wx", mode: 0o600 });
  console.log(
    JSON.stringify({
      prepared_rows: bundle.rows.length,
      excluded_rows: bundle.excluded.length,
      sha256: bundle.workbook_sha256,
    }),
  );
}
