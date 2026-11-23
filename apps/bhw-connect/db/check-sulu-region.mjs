#!/usr/bin/env node
/**
 * Checks that Sulu is counted in Region IX everywhere a region is summed (migration
 * 20261123100000_sulu_region_ix.sql moved it; the aggregates it could not recompute are listed here).
 *
 *     BHW_DATABASE_URL=postgres://… node db/check-sulu-region.mjs
 *
 * Three groups of checks, each printed, exit 1 if any FAIL:
 *
 *   geography   every dim_geo row at or under Sulu ('19066…') says region 09, the province's parent is 09,
 *               nothing under 09 or 19 is left orphaned, and Sulu's congressional districts are in 09.
 *   additive    for every aggregate whose columns are counts, each region row must equal the sum of its
 *               province rows under the CURRENT geography. A region row that is off by exactly Sulu's own
 *               province row is reported as "still counts Sulu" (region 19) or "missing Sulu" (region 09)
 *               — that table's region rows were built before the move and need rebuilding.
 *   not-additive  percentiles, rates, confidence intervals and distributions cannot be checked by a sum.
 *               They are listed with whether Sulu has rows in them, because each of those that does must be
 *               rebuilt from its source before its Region IX and BARMM cards are right.
 *
 * Read-only. Safe on the box.
 */
import { pathToFileURL } from "node:url";
import pg from "pg";

export const SULU = "19066";
export const IX = "09";
export const BARMM = "19";

/** Aggregates whose listed columns are plain counts: a region is the sum of its provinces. `keys` are the
 *  columns besides geography that identify a row. Suppressed (null) cells are skipped, never read as 0. */
export const ADDITIVE = [
  { table: "agg_population", keys: ["dataset_id", "census_year"], cols: ["population"] },
  {
    table: "agg_nhfr_counts",
    keys: ["dataset_id"],
    cols: [
      "n_facilities", "n_government", "n_private", "n_barangay_health_station", "n_rural_health_unit",
      "n_hospital", "n_birthing_home", "total_bed_capacity", "n_barangays_with_facility", "n_barangays",
    ],
  },
  { table: "agg_nhfr_by_type", keys: ["dataset_id", "facility_type"], cols: ["n_facilities", "n_government", "n_private"] },
  { table: "agg_uuc_phc_counts", keys: ["dataset_id"], cols: ["n_listed", "n_barangays"] },
  {
    table: "agg_uuc_phc_criteria",
    keys: ["dataset_id"],
    cols: ["n_listed", "n_route_ip", "n_route_conflict", "n_route_four_ps", "n_route_health", "n_health_evaluable"],
  },
  { table: "agg_bhw_counts", keys: ["dataset_id"], cols: ["n_total", "n_accredited"] },
  {
    table: "agg_bhw_profiling_status",
    keys: ["dataset_id"],
    cols: [
      "n_registered", "n_accredited", "n_unregistered", "n_total_bhw", "n_drafted", "n_for_validation",
      "n_back_to_encoder", "n_validated", "n_approved",
    ],
  },
  {
    table: "agg_bhw_stepzero_counts",
    keys: ["dataset_id"],
    cols: ["n_registered", "n_registered_accredited", "n_non_registered", "n_total_bhw", "population", "households"],
  },
  {
    table: "agg_bhw_by_uuc_status",
    keys: ["dataset_id"],
    cols: [
      "n_barangays_listed", "n_barangays_other", "n_listed_with_data", "n_other_with_data", "n_listed_no_bhw",
      "n_other_no_bhw", "unallocated_n_bhw",
    ],
  },
];

/** Aggregates a sum cannot test. */
export const NOT_ADDITIVE = [
  "agg_demographics", "agg_certification", "agg_training", "agg_honorarium", "agg_honorarium_cumulative",
  "agg_honorarium_inequality", "agg_cohorts", "agg_workload", "agg_poverty", "agg_poverty_official",
  "agg_peer_ranks", "agg_by_income_class", "agg_data_completeness", "agg_uuc_phc_indicator_dist",
];

const ident = (s) => {
  if (!/^[a-z_][a-z0-9_]*$/.test(s)) throw new Error(`not an identifier: ${s}`);
  return `"${s}"`;
};

async function tableExists(client, table) {
  const { rows } = await client.query("select to_regclass($1) as t", [`public.${table}`]);
  return rows[0].t !== null;
}

export async function checkGeography(client) {
  const results = [];
  const add = (name, ok, detail) => results.push({ group: "geography", name, ok, detail });

  const { rows: wrong } = await client.query(
    `select geo_level::text as geo_level, count(*)::int as n from dim_geo
      where (geo_code = $1 or province_code = $1 or geo_code like $1 || '%') and region_code is distinct from $2
      group by 1`,
    [SULU, IX],
  );
  add("every Sulu geography has region_code 09", wrong.length === 0,
    wrong.length ? wrong.map((r) => `${r.n} ${r.geo_level} row(s) still elsewhere`).join(", ") : "all rows");

  const { rows: parent } = await client.query(`select parent_code from dim_geo where geo_code = $1`, [SULU]);
  add("Sulu province's parent is Region IX", parent.length === 1 && parent[0].parent_code === IX,
    parent.length ? `parent_code = ${parent[0].parent_code}` : "Sulu province row is missing");

  const { rows: members } = await client.query(
    `select
       count(*) filter (where geo_level = 'province')::int as provinces,
       count(*) filter (where geo_level = 'citymun')::int as citymuns,
       count(*) filter (where geo_level = 'barangay')::int as barangays
     from dim_geo where geo_code = $1 or province_code = $1`,
    [SULU],
  );
  const m = members[0];
  add("Sulu is a whole province: 1 province, its cities/municipalities and barangays",
    m.provinces === 1 && m.citymuns > 0 && m.barangays > 0,
    `${m.provinces} province, ${m.citymuns} city/municipalities, ${m.barangays} barangays`);

  const { rows: leaked } = await client.query(
    `select count(*)::int as n from dim_geo
      where region_code = $2 and (geo_code like $1 || '%') is false and province_code = $1`,
    [SULU, BARMM],
  );
  add("no row of Sulu's province remains under BARMM", leaked[0].n === 0, `${leaked[0].n} leftover`);

  const { rows: districts } = await client.query(
    `select district_code, region_code from dim_legislative_district where parent_geo_code = $1`,
    [SULU],
  );
  const badDistricts = districts.filter((d) => d.region_code !== IX);
  add("Sulu's congressional districts are in Region IX", badDistricts.length === 0,
    `${districts.length} district(s)${badDistricts.length ? `, wrong: ${badDistricts.map((d) => d.district_code).join(", ")}` : ""}`);

  return results;
}

export async function checkAdditive(client, spec = ADDITIVE) {
  const results = [];
  for (const { table, keys, cols } of spec) {
    if (!(await tableExists(client, table))) {
      results.push({ group: "additive", name: table, ok: true, detail: "table not present — skipped" });
      continue;
    }
    const keyList = keys.map(ident).join(", ");
    const sums = cols.map((c) => `sum(${ident(c)}) as ${ident(c)}`).join(", ");
    const sql = `
      with prov as (
        select a.*, g.region_code as _region
          from ${ident(table)} a join dim_geo g on g.geo_code = a.geo_code
         where a.geo_level = 'province' and g.geo_level = 'province'
      ),
      summed as (
        select _region as region, ${keyList}, ${sums} from prov
         where _region in ('${IX}', '${BARMM}') group by _region, ${keyList}
      ),
      sulu as (
        select ${keyList}, ${cols.map((c) => `${ident(c)} as ${ident("s_" + c)}`).join(", ")}
          from ${ident(table)} where geo_code = '${SULU}' and geo_level = 'province'
      ),
      reg as (
        select geo_code as region, ${keyList}, ${cols.map(ident).join(", ")}
          from ${ident(table)} where geo_level = 'region' and geo_code in ('${IX}', '${BARMM}')
      )
      select reg.region, ${keys.map((k) => `reg.${ident(k)}`).join(", ")},
             ${cols.map((c) => `reg.${ident(c)} as ${ident("r_" + c)}, summed.${ident(c)} as ${ident("p_" + c)}, sulu.${ident("s_" + c)}`).join(", ")}
        from reg
        left join summed on summed.region = reg.region and ${keys.map((k) => `summed.${ident(k)} is not distinct from reg.${ident(k)}`).join(" and ")}
        left join sulu on ${keys.map((k) => `sulu.${ident(k)} is not distinct from reg.${ident(k)}`).join(" and ")}`;
    const { rows } = await client.query(sql);

    let compared = 0;
    const problems = [];
    for (const r of rows) {
      for (const c of cols) {
        const region = r[`r_${c}`];
        const prov = r[`p_${c}`];
        const sulu = r[`s_${c}`];
        if (region === null || prov === null) continue; // suppressed, or no province rows to sum
        compared++;
        const diff = Number(region) - Number(prov);
        if (diff === 0) continue;
        const suluN = sulu === null ? 0 : Number(sulu);
        let why = `region ${region} vs provinces ${prov} (diff ${diff})`;
        if (suluN !== 0 && r.region === BARMM && diff === suluN) why = `still counts Sulu (${suluN}) — rebuild`;
        else if (suluN !== 0 && r.region === IX && diff === -suluN) why = `missing Sulu (${suluN}) — rebuild`;
        problems.push(`${r.region} ${keys.map((k) => `${k}=${r[k]}`).join(",")} ${c}: ${why}`);
      }
    }
    results.push({
      group: "additive", name: table, ok: problems.length === 0,
      detail: problems.length ? `${problems.length} stale of ${compared} compared: ${problems.slice(0, 4).join("; ")}${problems.length > 4 ? "; …" : ""}` : `${compared} region totals match their provinces`,
    });
  }
  return results;
}

export async function listNotAdditive(client, tables = NOT_ADDITIVE) {
  const results = [];
  for (const table of tables) {
    if (!(await tableExists(client, table))) continue;
    const { rows: has } = await client.query(
      `select count(*)::int as n from information_schema.columns
        where table_schema = 'public' and table_name = $1 and column_name in ('geo_code', 'geo_level')`,
      [table],
    );
    if (has[0].n < 2) {
      results.push({ group: "not-additive", name: table, ok: true, detail: "not keyed by geography — unaffected" });
      continue;
    }
    const { rows } = await client.query(
      `select count(*) filter (where geo_code = $1 or geo_code like $1 || '%')::int as sulu_rows,
              count(*) filter (where geo_level = 'region' and geo_code in ($2, $3))::int as region_rows
         from ${ident(table)}`,
      [SULU, IX, BARMM],
    );
    const { sulu_rows, region_rows } = rows[0];
    results.push({
      group: "not-additive", name: table, ok: true,
      detail: sulu_rows === 0 ? "no Sulu rows — nothing to move" : `${sulu_rows} Sulu row(s), ${region_rows} IX/BARMM region row(s): REBUILD from source so Sulu counts in Region IX`,
      needsRebuild: sulu_rows > 0 && region_rows > 0,
    });
  }
  return results;
}

export async function run(client) {
  const results = [...(await checkGeography(client)), ...(await checkAdditive(client)), ...(await listNotAdditive(client))];
  return results;
}

async function main() {
  const url = process.env.BHW_DATABASE_URL ?? process.env.BHW_DEV_DATABASE_URL;
  if (!url) {
    console.error("check-sulu-region: set BHW_DATABASE_URL (or BHW_DEV_DATABASE_URL)");
    process.exit(2);
  }
  const client = new pg.Client({ connectionString: url });
  await client.connect();
  try {
    const results = await run(client);
    for (const r of results) console.log(`${r.ok ? (r.needsRebuild ? "TODO" : "ok  ") : "FAIL"}  [${r.group}] ${r.name} — ${r.detail}`);
    const failed = results.filter((r) => !r.ok).length;
    const todo = results.filter((r) => r.needsRebuild).length;
    console.log(`\n${failed} failing, ${todo} not-additive aggregate(s) to rebuild.`);
    process.exit(failed ? 1 : 0);
  } finally {
    await client.end();
  }
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? "").href) await main();
