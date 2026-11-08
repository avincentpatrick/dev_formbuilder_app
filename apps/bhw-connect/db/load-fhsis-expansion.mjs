#!/usr/bin/env node
/** Add reviewed FHSIS indicators atomically. No changes to existing facts; dry run by default. */
import { readFileSync } from "node:fs";
import { createHash } from "node:crypto";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";
import pg from "pg";
// Public indicator codes are metadata, not credentials. Map to the database column here.
export const DEFINITIONS = JSON.parse(
  readFileSync(new URL("../ingestion/fhsis-expansion-definitions.json", import.meta.url), "utf8"),
).map(({ code, ...definition }) => ({ indicator_key: code, ...definition }));
export const CHILD_HEALTH_DEFINITIONS = JSON.parse(
  readFileSync(
    new URL("../ingestion/fhsis-child-health-definitions.json", import.meta.url),
    "utf8",
  ),
).map(({ code, ...definition }) => ({ indicator_key: code, ...definition }));
function reviewedDefinitions(bundle) {
  const programme = bundle.programme_set ?? "core";
  check(["core", "child-health"].includes(programme), "Unknown programme set");
  return programme === "core" ? DEFINITIONS : CHILD_HEALTH_DEFINITIONS;
}
const DECIMAL = /^-?\d+(?:\.\d+)?$/;
function check(ok, message) {
  if (!ok) throw new Error(message);
}
export function validateExpansion(bundle) {
  const definitions = reviewedDefinitions(bundle);
  const keys = definitions.map((d) => d.indicator_key);
  check(
    bundle.format === "bhw-fhsis-expansion-v1" && bundle.dataset_slug === "fhsis-2025",
    "Wrong expansion format/dataset",
  );
  check(
    JSON.stringify(bundle.definitions) === JSON.stringify(definitions),
    "Definitions differ from the reviewed mapping",
  );
  check(/^\d{4}-\d{2}-\d{2}$/.test(bundle.retrieved), "Invalid retrieval date");
  const sources = new Map(bundle.sources.map((s) => [s.file, s]));
  const expectedSources = new Set(definitions.map((d) => d.source_workbook));
  check(
    sources.size === expectedSources.size &&
      sources.size === bundle.sources.length &&
      bundle.sources.every((s) => expectedSources.has(s.file) && /^[a-f0-9]{64}$/.test(s.sha256)),
    "Expected the reviewed checksummed workbooks",
  );
  const geos = new Map(bundle.geographies.map((g) => [g.geo_code, g]));
  check(geos.size === bundle.geographies.length, "Duplicate geography");
  const seen = new Set(),
    national = new Set();
  for (const row of bundle.indicators) {
    const definition = definitions.find((d) => d.indicator_key === row.indicator_key);
    check(
      definition && definition.source_workbook === row.source_file && sources.has(row.source_file),
      "Unexpected indicator/source",
    );
    const geo = geos.get(row.geo_code);
    check(
      geo &&
        geo.geo_level === row.geo_level &&
        ["national", "region", "province", "citymun"].includes(row.geo_level),
      "Invalid geography",
    );
    check(
      ["total", "male", "female", "10-14", "15-19", "20-49"].includes(row.breakdown),
      "Unexpected breakdown",
    );
    check(
      typeof row.source_sheet === "string" &&
        Number.isInteger(row.source_row) &&
        row.source_row > 0,
      "Missing source cell location",
    );
    for (const col of ["numerator_exact", "denominator_exact", "rate_pct"])
      check(
        row[col] === null ||
          (typeof row[col] === "string" &&
            DECIMAL.test(row[col]) &&
            Number.isFinite(Number(row[col]))),
        "Invalid numeric source value",
      );
    check(
      row.numerator_exact !== null || row.rate_pct !== null,
      "Denominator alone is not a report",
    );
    check(
      row.over_100 ===
        (definition.unit.startsWith("percent") &&
          row.rate_pct !== null &&
          Number(row.rate_pct) > 100),
      "Incorrect excess-coverage flag",
    );
    const key = JSON.stringify([row.geo_code, row.indicator_key, row.breakdown]);
    check(!seen.has(key), "Duplicate fact key");
    seen.add(key);
    if (row.geo_code === "PH" && row.breakdown === "total") national.add(row.indicator_key);
  }
  check(national.size === keys.length, "Incomplete national checkpoints");
  check(
    bundle.qa.indicator_rows === bundle.indicators.length && bundle.qa.definitions === keys.length,
    "QA counts disagree",
  );
}

export async function loadExpansion(client, bundle, sha256, { apply = false } = {}) {
  validateExpansion(bundle);
  const definitions = reviewedDefinitions(bundle);
  const keys = definitions.map((d) => d.indicator_key);
  await client.query("BEGIN");
  try {
    await client.query("SET LOCAL lock_timeout = '10s'");
    await client.query("SET LOCAL statement_timeout = '120s'");
    await client.query(
      "LOCK TABLE public.fact_fhsis_indicator, public.ref_fhsis_indicator IN SHARE ROW EXCLUSIVE MODE",
    );
    const { rows: datasets } = await client.query(
      "SELECT dataset_id FROM public.dim_dataset WHERE slug=$1 AND status='published' FOR UPDATE",
      [bundle.dataset_slug],
    );
    check(datasets.length === 1, "Expected one published FHSIS dataset");
    const id = datasets[0].dataset_id;
    const { rows: counts } = await client.query(
      `SELECT
      (SELECT count(*)::int FROM public.fact_fhsis_indicator WHERE dataset_id=$1) AS indicators,
      (SELECT count(*)::int FROM public.fact_fhsis_workforce WHERE dataset_id=$1) AS workforce,
      (SELECT count(*)::int FROM public.fact_fhsis_indicator WHERE dataset_id=$1 AND indicator_key=ANY($2::text[])) AS overlap`,
      [id, keys],
    );
    const before = counts[0];
    check(
      before.indicators > 0 && before.workforce > 0,
      "Original FHSIS load must be present first",
    );
    check(
      before.overlap === 0,
      "Expansion facts already exist; refusing overwrite or repeat append",
    );
    const { rows: existingDefs } = await client.query(
      "SELECT indicator_key FROM public.ref_fhsis_indicator WHERE indicator_key=ANY($1::text[])",
      [keys],
    );
    check(
      existingDefs.length === 0,
      "Expansion definitions already exist; inspect prior work before loading",
    );
    await client.query(
      "SELECT numerator_exact,denominator_exact FROM public.fact_fhsis_indicator LIMIT 0",
    );
    const { rows: mismatches } = await client.query(
      `SELECT s.geo_code FROM jsonb_to_recordset($1::jsonb) AS s(geo_code text,geo_level text,geo_name text,parent_code text)
      LEFT JOIN public.dim_geo g USING(geo_code) WHERE g.geo_code IS NULL OR g.geo_level::text IS DISTINCT FROM s.geo_level
      OR g.geo_name IS DISTINCT FROM s.geo_name OR g.parent_code IS DISTINCT FROM s.parent_code`,
      [JSON.stringify(bundle.geographies)],
    );
    check(
      mismatches.length === 0,
      "Server geography differs; rebuild the private package against its lookup",
    );
    await client.query(
      `INSERT INTO public.ref_fhsis_indicator
      SELECT * FROM jsonb_to_recordset($1::jsonb) AS d(indicator_key text,program_area text,label text,numerator_def text,denominator_def text,unit text,source_workbook text,uuc_criterion_d boolean)`,
      [JSON.stringify(definitions)],
    );
    const inserted = await client.query(
      `INSERT INTO public.fact_fhsis_indicator
      (dataset_id,geo_code,geo_level,indicator_key,breakdown,numerator,denominator,numerator_exact,denominator_exact,rate_pct,over_100,source_psgc,source_area_name)
      SELECT $1,geo_code,geo_level::public.geo_level_enum,indicator_key,breakdown,
      CASE WHEN numerator_exact=trunc(numerator_exact) AND numerator_exact BETWEEN -2147483648 AND 2147483647 THEN numerator_exact::integer END,
      CASE WHEN denominator_exact=trunc(denominator_exact) AND denominator_exact BETWEEN -2147483648 AND 2147483647 THEN denominator_exact::integer END,
      numerator_exact,denominator_exact,rate_pct,over_100,source_psgc,source_area_name
      FROM jsonb_to_recordset($2::jsonb) AS s(geo_code text,geo_level text,indicator_key text,breakdown text,numerator_exact numeric,denominator_exact numeric,rate_pct numeric,over_100 boolean,source_psgc text,source_area_name text)`,
      [id, JSON.stringify(bundle.indicators)],
    );
    check(inserted.rowCount === bundle.indicators.length, "Inserted count differs from package");
    await client.query(
      "UPDATE public.dim_dataset SET version=$1,last_updated_at=now() WHERE dataset_id=$2",
      [`expanded-retrieved-${bundle.retrieved}`, id],
    );
    const { rows: checkpoints } = await client.query(
      `SELECT indicator_key,numerator_exact,denominator_exact,rate_pct FROM public.fact_fhsis_indicator
      WHERE dataset_id=$1 AND geo_code='PH' AND breakdown='total' AND indicator_key=ANY($2::text[]) ORDER BY indicator_key`,
      [id, keys],
    );
    check(checkpoints.length === keys.length, "Checkpoint count differs after insert");
    if (apply)
      await client.query(
        `INSERT INTO public.ingestion_batches(finished_at,source_file,row_counts,qa_report) VALUES(now(),$1,$2::jsonb,$3::jsonb)`,
        [
          `fhsis-expansion.json sha256:${sha256}`,
          JSON.stringify({ added_indicators: inserted.rowCount, added_definitions: keys.length }),
          JSON.stringify({
            ...bundle.qa,
            sha256,
            sources: bundle.sources,
            retrieved: bundle.retrieved,
          }),
        ],
      );
    await client.query(apply ? "COMMIT" : "ROLLBACK");
    return {
      mode: apply ? "committed" : "dry-run-rolled-back",
      dataset_id: id,
      sha256,
      before,
      added_indicators: inserted.rowCount,
      added_definitions: keys.length,
      checkpoints,
    };
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const args = process.argv.slice(2),
    apply = args.includes("--apply"),
    files = args.filter((a) => a !== "--apply");
  let client;
  try {
    check(
      files.length === 2 && /^[a-f0-9]{64}$/.test(files[1]) && process.env.BHW_DATABASE_URL,
      "Usage: node --env-file=<env> db/load-fhsis-expansion.mjs <private JSON> <SHA256> [--apply]",
    );
    const bytes = readFileSync(files[0]),
      sha256 = createHash("sha256").update(bytes).digest("hex");
    check(sha256 === files[1], "Checksum mismatch; no database writes attempted");
    const bundle = JSON.parse(bytes);
    validateExpansion(bundle);
    client = new pg.Client({
      connectionString: process.env.BHW_DATABASE_URL,
      connectionTimeoutMillis: 10000,
    });
    await client.connect();
    console.log(JSON.stringify(await loadExpansion(client, bundle, sha256, { apply }), null, 2));
  } catch (error) {
    console.error(
      error.code
        ? `Expansion failed (database code ${error.code}); transaction rolled back.`
        : `Expansion failed: ${error.message}`,
    );
    process.exitCode = 1;
  } finally {
    if (client) await client.end();
  }
}
