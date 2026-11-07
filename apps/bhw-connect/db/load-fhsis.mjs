#!/usr/bin/env node
/** Private FHSIS first load. Default: validate and roll back. See docs/FHSIS_LOAD.md. */
import { createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";
import pg from "pg";

export const INDICATORS = [
  "anc4",
  "anc8",
  "cic",
  "fic",
  "sanitation_basic",
  "sanitation_safely_managed",
  "sanitation_septic",
  "sanitation_sewer",
  "sanitation_vip",
  "tb_dr_notified",
  "tb_notified",
  "tb_presumptive_tested",
  "tb_tpt",
  "tb_tsr_dstb",
  "tb_tsr_mdrtb",
  "water_basic",
  "water_level1",
  "water_level2",
  "water_level3",
  "water_safely_managed",
];
export const CADRES = [
  "physician",
  "nurse",
  "midwife",
  "dentist",
  "med_tech",
  "nutritionist",
  "sanitary_engineer",
  "sanitary_inspector",
];
const LEVELS = ["national", "region", "province", "citymun"];
const DECIMAL = /^-?\d+(?:\.\d+)?$/;
function requireThat(condition, message) {
  if (!condition) throw new Error(message);
}
export function validateBundle(bundle) {
  requireThat(
    bundle.format === "bhw-fhsis-2025-v1" && bundle.dataset_slug === "fhsis-2025",
    "Wrong bundle format/dataset",
  );
  requireThat(/^\d{4}-\d{2}-\d{2}$/.test(bundle.retrieved), "Missing retrieval date");
  requireThat(
    Array.isArray(bundle.sources) &&
      bundle.sources.length === 7 &&
      bundle.sources.every((s) => /^[a-f0-9]{64}$/.test(s.sha256)),
    "Expected seven checksummed source workbooks",
  );
  const geos = new Map(bundle.geographies.map((g) => [g.geo_code, g]));
  requireThat(geos.size === bundle.geographies.length, "Duplicate geography reference");
  for (const [rows, key, allowed] of [
    [bundle.indicators, "indicator_key", INDICATORS],
    [bundle.workforce, "cadre", CADRES],
  ]) {
    requireThat(Array.isArray(rows) && rows.length > 0, "Empty facts");
    const seen = new Set();
    for (const row of rows) {
      const geo = geos.get(row.geo_code);
      requireThat(
        geo && geo.geo_level === row.geo_level && LEVELS.includes(row.geo_level),
        "Invalid geography/level",
      );
      requireThat(allowed.includes(row[key]), "Unexpected indicator/cadre (BHW is excluded)");
      requireThat(
        typeof row.source_file === "string" &&
          typeof row.source_sheet === "string" &&
          Number.isInteger(row.source_row) &&
          row.source_row > 0,
        "Missing source location",
      );
      const unique = JSON.stringify([row.geo_code, row[key], row.breakdown]);
      requireThat(!seen.has(unique), "Duplicate fact key");
      seen.add(unique);
      if (key === "indicator_key") {
        requireThat(
          ["total", "male", "female", "10-14", "15-19", "20-49", "0-14", "15+", "unknown"].includes(
            row.breakdown,
          ),
          "Unknown breakdown",
        );
        for (const col of ["numerator_exact", "denominator_exact", "rate_pct"]) {
          requireThat(
            row[col] === null ||
              (typeof row[col] === "string" &&
                DECIMAL.test(row[col]) &&
                Number.isFinite(Number(row[col]))),
            "Invalid source decimal",
          );
        }
        requireThat(
          row.numerator_exact !== null || row.rate_pct !== null,
          "Denominator-only row is not a service report",
        );
        const excess =
          !["tb_notified", "tb_dr_notified"].includes(row.indicator_key) &&
          row.rate_pct !== null &&
          Number(row.rate_pct) > 100;
        requireThat(row.over_100 === excess, "Incorrect percentage flag");
      } else {
        for (const col of [
          "lgu_hired",
          "doh_hired",
          "total",
          "population_2025",
          "households_2025",
        ]) {
          requireThat(
            row[col] === null || (Number.isSafeInteger(row[col]) && row[col] >= 0),
            "Invalid workforce count",
          );
        }
      }
    }
    const national = rows.filter(
      (r) => r.geo_code === "PH" && (key === "cadre" || r.breakdown === "total"),
    );
    requireThat(
      national.length === allowed.length &&
        allowed.every((k) => national.some((r) => r[key] === k)),
      "Incomplete national checkpoints",
    );
  }
  requireThat(
    bundle.qa.indicator_rows === bundle.indicators.length &&
      bundle.qa.workforce_rows === bundle.workforce.length,
    "QA row counts differ",
  );
}

export async function loadBundle(client, bundle, sha256, { apply = false } = {}) {
  validateBundle(bundle);
  await client.query("BEGIN");
  try {
    await client.query("SET LOCAL lock_timeout = '10s'");
    await client.query("SET LOCAL statement_timeout = '120s'");
    // Serialise first loads without preventing readers from seeing the last committed state.
    await client.query(
      "LOCK TABLE public.fact_fhsis_indicator, public.fact_fhsis_workforce IN SHARE ROW EXCLUSIVE MODE",
    );
    const { rows: datasets } = await client.query(
      "SELECT dataset_id, status FROM public.dim_dataset WHERE slug = $1 FOR UPDATE",
      [bundle.dataset_slug],
    );
    requireThat(
      datasets.length === 1 && datasets[0].status === "published",
      "Expected one published FHSIS dataset",
    );
    const id = datasets[0].dataset_id;
    const { rows: existing } = await client.query(
      `SELECT
      (SELECT count(*) FROM public.fact_fhsis_indicator WHERE dataset_id = $1)::int AS indicators,
      (SELECT count(*) FROM public.fact_fhsis_workforce WHERE dataset_id = $1)::int AS workforce`,
      [id],
    );
    requireThat(
      existing[0].indicators === 0 && existing[0].workforce === 0,
      "FHSIS already contains facts; refusing to overwrite or append. Inspect the previous load.",
    );
    await client.query(
      "SELECT numerator_exact, denominator_exact FROM public.fact_fhsis_indicator LIMIT 0",
    );
    await client.query("SELECT * FROM public.ref_fhsis_reconciliation_exact LIMIT 0");
    const { rows: definitions } = await client.query(
      "SELECT indicator_key FROM public.ref_fhsis_indicator WHERE indicator_key = ANY($1::text[])",
      [INDICATORS],
    );
    requireThat(definitions.length === INDICATORS.length, "FHSIS dictionary is incomplete");
    const { rows: mismatch } = await client.query(
      `SELECT s.geo_code FROM jsonb_to_recordset($1::jsonb)
      AS s(geo_code text, geo_level text, geo_name text, parent_code text)
      LEFT JOIN public.dim_geo g USING (geo_code)
      WHERE g.geo_code IS NULL OR g.geo_level::text IS DISTINCT FROM s.geo_level
      OR g.geo_name IS DISTINCT FROM s.geo_name OR g.parent_code IS DISTINCT FROM s.parent_code`,
      [JSON.stringify(bundle.geographies)],
    );
    requireThat(
      mismatch.length === 0,
      `Server geography differs from the prepared mapping (${mismatch.length} rows); rebuild against the server geography`,
    );
    // Numeric JSON values are decimal strings, so PostgreSQL parses them without JS float rounding.
    const indicators = await client.query(
      `INSERT INTO public.fact_fhsis_indicator
      (dataset_id, geo_code, geo_level, indicator_key, breakdown, numerator, denominator,
       numerator_exact, denominator_exact, rate_pct, over_100, source_psgc, source_area_name)
      SELECT $1, geo_code, geo_level::public.geo_level_enum, indicator_key, breakdown,
        CASE WHEN numerator_exact = trunc(numerator_exact) AND numerator_exact BETWEEN -2147483648 AND 2147483647 THEN numerator_exact::integer END,
        CASE WHEN denominator_exact = trunc(denominator_exact) AND denominator_exact BETWEEN -2147483648 AND 2147483647 THEN denominator_exact::integer END,
        numerator_exact, denominator_exact, rate_pct, over_100, source_psgc, source_area_name
      FROM jsonb_to_recordset($2::jsonb) AS s(geo_code text, geo_level text, indicator_key text, breakdown text,
        numerator_exact numeric, denominator_exact numeric, rate_pct numeric, over_100 boolean, source_psgc text, source_area_name text)`,
      [id, JSON.stringify(bundle.indicators)],
    );
    const workforce = await client.query(
      `INSERT INTO public.fact_fhsis_workforce
      (dataset_id, geo_code, geo_level, cadre, lgu_hired, doh_hired, total, population_2025, households_2025, source_psgc, source_area_name)
      SELECT $1, geo_code, geo_level::public.geo_level_enum, cadre, lgu_hired, doh_hired, total, population_2025, households_2025, source_psgc, source_area_name
      FROM jsonb_to_recordset($2::jsonb) AS s(geo_code text, geo_level text, cadre text, lgu_hired integer, doh_hired integer,
        total integer, population_2025 integer, households_2025 integer, source_psgc text, source_area_name text)`,
      [id, JSON.stringify(bundle.workforce)],
    );
    requireThat(
      indicators.rowCount === bundle.indicators.length &&
        workforce.rowCount === bundle.workforce.length,
      "Inserted counts differ",
    );
    for (const [prefix, definition] of [
      ["water", "Households with basic drinking-water services (Q4 2025)"],
      ["sanitation", "Households with basic sanitation services (Q4 2025)"],
    ]) {
      const keys =
        prefix === "water"
          ? ["water_level1", "water_level2", "water_level3"]
          : ["sanitation_septic", "sanitation_sewer", "sanitation_vip"];
      await client.query(
        "UPDATE public.ref_fhsis_indicator SET denominator_def = $1 WHERE indicator_key = ANY($2::text[])",
        [definition, keys],
      );
    }
    for (const key of INDICATORS) {
      const row = bundle.indicators.find((r) => r.indicator_key === key);
      await client.query(
        "UPDATE public.ref_fhsis_indicator SET source_workbook = $1 WHERE indicator_key = $2",
        [row.source_file, key],
      );
    }
    await client.query(
      `UPDATE public.dim_dataset SET source_name = 'DOH FHSIS 2025 — selected programme workbooks',
      source_url = 'https://bit.ly/FHSISPHSannualreports', version = $1, last_updated_at = now() WHERE dataset_id = $2`,
      [`retrieved-${bundle.retrieved}`, id],
    );
    const { rows: checkpoints } = await client.query(
      `SELECT indicator_key, numerator_exact, denominator_exact, rate_pct
      FROM public.fact_fhsis_indicator WHERE dataset_id = $1 AND geo_code = 'PH' AND breakdown = 'total' ORDER BY indicator_key`,
      [id],
    );
    const counts = { indicators: indicators.rowCount, workforce: workforce.rowCount };
    if (apply) {
      await client.query(
        `INSERT INTO public.ingestion_batches (finished_at, source_file, row_counts, qa_report)
        VALUES (now(), $1, $2::jsonb, $3::jsonb)`,
        [
          `fhsis-load.json sha256:${sha256}`,
          JSON.stringify(counts),
          JSON.stringify({
            ...bundle.qa,
            sha256,
            sources: bundle.sources,
            retrieved: bundle.retrieved,
          }),
        ],
      );
    }
    await client.query(apply ? "COMMIT" : "ROLLBACK");
    return {
      mode: apply ? "committed" : "dry-run-rolled-back",
      dataset_id: id,
      sha256,
      counts,
      checkpoints,
    };
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const args = process.argv.slice(2);
  const apply = args.includes("--apply");
  const files = args.filter((a) => a !== "--apply");
  if (files.length !== 2 || !/^[a-f0-9]{64}$/.test(files[1]) || !process.env.BHW_DATABASE_URL) {
    console.error(
      "Usage: node --env-file=<server env> db/load-fhsis.mjs <private fhsis-load.json> <SHA256> [--apply]",
    );
    process.exitCode = 2;
  } else {
    let client;
    try {
      const bytes = readFileSync(files[0]);
      const hash = createHash("sha256").update(bytes).digest("hex");
      requireThat(hash === files[1], "Checksum mismatch; no database writes attempted");
      const bundle = JSON.parse(bytes);
      validateBundle(bundle);
      client = new pg.Client({
        connectionString: process.env.BHW_DATABASE_URL,
        connectionTimeoutMillis: 10000,
      });
      await client.connect();
      console.log(JSON.stringify(await loadBundle(client, bundle, hash, { apply }), null, 2));
    } catch (error) {
      // Never print the connection string or a pg error's detail (which may include records).
      console.error(
        error.code
          ? `FHSIS load failed (database code ${error.code}); transaction rolled back.`
          : `FHSIS load failed: ${error.message}`,
      );
      process.exitCode = 1;
    } finally {
      if (client) await client.end();
    }
  }
}
