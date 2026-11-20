#!/usr/bin/env node
/** Server operator only. Validate and roll back by default; no existing observations overwritten. */
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";
import pg from "pg";
import { CONTRACT } from "../ingestion/prepare-poverty-official.mjs";
const fields = [
  "geo_code",
  "geo_level",
  "reference_year",
  "poverty_incidence",
  "ci_low",
  "ci_high",
  "cv",
  "is_suppressed",
  "value_status",
  "source_label",
  "source_table",
  "source_row",
  "boundary_note",
  "publication_note",
  "workbook_sha256",
];
function requireThat(condition, message) {
  if (!condition) throw new Error(message);
}
export function validateBundle(bundle) {
  requireThat(
    bundle.format === "bhw-poverty-official-v1" &&
      bundle.dataset_slug === CONTRACT.dataset_slug &&
      bundle.workbook_sha256 === CONTRACT.workbook_sha256 &&
      bundle.source_url === CONTRACT.source_url,
    "Wrong source contract",
  );
  requireThat(
    Array.isArray(bundle.rows) && bundle.rows.length === CONTRACT.mapping.length,
    "Incomplete source mapping",
  );
  const seen = new Set();
  for (const row of bundle.rows) {
    const match = CONTRACT.mapping.find((m) => m.geo_code === row.geo_code);
    requireThat(
      match &&
        Object.keys(match).every((key) => match[key] === row[key]) &&
        !seen.has(row.geo_code),
      "Changed or duplicate geography contract",
    );
    seen.add(row.geo_code);
    requireThat(
      row.reference_year === 2023 &&
        row.workbook_sha256 === CONTRACT.workbook_sha256 &&
        typeof row.publication_note === "string" &&
        row.publication_note.trim(),
      "Missing source lineage",
    );
    requireThat(
      ["available", "unavailable", "not_absolute_zero", "suppressed"].includes(row.value_status) &&
        row.is_suppressed === (row.value_status === "suppressed"),
      "Invalid publication state",
    );
    requireThat(
      row.value_status === "available"
        ? typeof row.poverty_incidence === "number" &&
            Number.isFinite(row.poverty_incidence) &&
            row.poverty_incidence >= 0 &&
            row.poverty_incidence <= 100
        : row.poverty_incidence === null,
      "Invalid estimate",
    );
    requireThat(
      [row.ci_low, row.ci_high, row.cv].every(
        (v) => v === null || (typeof v === "number" && Number.isFinite(v)),
      ) &&
        (row.cv === null || row.cv >= 0) &&
        (row.ci_low === null || row.ci_high === null || row.ci_low <= row.ci_high),
      "Invalid precision",
    );
  }
}
export async function loadBundle(client, bundle, { apply = false } = {}) {
  validateBundle(bundle);
  await client.query("BEGIN");
  try {
    await client.query("SET LOCAL lock_timeout = '10s'");
    await client.query("SET LOCAL statement_timeout = '120s'");
    await client.query("LOCK TABLE public.agg_poverty_official IN SHARE ROW EXCLUSIVE MODE");
    const geos = await client.query(
      "SELECT geo_code, geo_level, geo_name FROM public.dim_geo WHERE geo_code = ANY($1::text[])",
      [bundle.rows.map((r) => r.geo_code)],
    );
    requireThat(
      bundle.rows.every((r) =>
        geos.rows.some(
          (g) =>
            g.geo_code === r.geo_code && g.geo_level === r.geo_level && g.geo_name === r.geo_name,
        ),
      ),
      "Live geography differs: stop and review the mapping",
    );
    const prior = await client.query("SELECT dataset_id FROM public.dim_dataset WHERE slug = $1", [
      bundle.dataset_slug,
    ]);
    requireThat(
      prior.rows.length === 0,
      "This source is already registered; do not overwrite a release",
    );
    const source = await client.query(
      `INSERT INTO public.dim_dataset (slug,name,source_name,source_url,license,methodology_md,geo_join_level,as_of_date,version,status)
      VALUES ($1,$2,$3,$4,$5,$6,'national','2023-12-31','r2','published') RETURNING dataset_id`,
      [
        bundle.dataset_slug,
        "PSA 2023 Full-Year Official Poverty Statistics (r2)",
        "Philippine Statistics Authority — official population poverty, Tables 2 and 2a",
        bundle.source_url,
        "PSA source attribution; retain original workbook and publication terms",
        "Population poverty incidence; published 95% CI. Explicit source reporting geography, no rollups. Audited workbook SHA-256: " +
          bundle.workbook_sha256,
      ],
    );
    for (const row of bundle.rows)
      await client.query(
        `INSERT INTO public.agg_poverty_official (dataset_id,${fields.join(",")}) VALUES (${["dataset_id", ...fields].map((_, i) => `$${i + 1}`).join(",")})`,
        [source.rows[0].dataset_id, ...fields.map((key) => row[key])],
      );
    await client.query(apply ? "COMMIT" : "ROLLBACK");
    return { applied: apply, rows: bundle.rows.length };
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  }
}
if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  requireThat(
    process.env.BHW_LOAD_DATABASE_URL && process.argv[2],
    "Set BHW_LOAD_DATABASE_URL privately and provide the bundle path; optional --apply",
  );
  const client = new pg.Client({ connectionString: process.env.BHW_LOAD_DATABASE_URL });
  try {
    await client.connect();
    console.log(
      await loadBundle(client, JSON.parse(readFileSync(process.argv[2], "utf8")), {
        apply: process.argv.includes("--apply"),
      }),
    );
  } finally {
    await client.end();
  }
}
