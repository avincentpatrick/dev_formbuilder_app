import { readFileSync, readdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

/**
 * `20261111090000_bhw_dataset_display_names.sql` rewrites the assistant's dataset dictionary with
 * case-sensitive replace() pairs (docs/DECISIONS.md, 2026-11-11). Two things must hold, and both are
 * checked here against the committed files, the same way `dataset-registry-seed.test.ts` checks the
 * seed: no old dataset name survives in the dictionary text the seeds wrote, and no identifier the
 * prose quotes — table, slug, script — is changed by a pair that happens to match inside it.
 */

const MIGRATIONS = fileURLToPath(new URL("../../supabase/migrations", import.meta.url));
const FILE = "20261111090000_bhw_dataset_display_names.sql";
const sql = readFileSync(`${MIGRATIONS}/${FILE}`, "utf8");

/** The `['from', 'to']` pairs of the DO block, in order. */
function pairs(): [string, string][] {
  const block = sql.slice(sql.indexOf("pairs text[][] := array["), sql.indexOf("];"));
  return [...block.matchAll(/\['((?:[^']|'')*)', '((?:[^']|'')*)'\]/g)].map((m) => [
    m[1].replaceAll("''", "'"),
    m[2].replaceAll("''", "'"),
  ]);
}

function rename(text: string): string {
  return pairs().reduce((acc, [from, to]) => acc.split(from).join(to), text);
}

const OLD_NAMES =
  /StepZero|quick[- ]count|BHW census|profiled census|per-person census|LGU-declared/i;

/** Every non-comment line of every migration that seeds the dictionary. */
function seededDictionaryLines(): string[] {
  return readdirSync(MIGRATIONS)
    .filter((f) => /seed_(dataset_)?registry/.test(f))
    .flatMap((f) => readFileSync(`${MIGRATIONS}/${f}`, "utf8").split("\n"))
    .filter((line) => !line.trim().startsWith("--"));
}

describe(FILE, () => {
  it("is additive: UPDATE only, no destructive marker", () => {
    expect(sql.startsWith("-- bhw:destructive")).toBe(false);
    const statements = sql
      .split("\n")
      .filter((line) => !line.trim().startsWith("--"))
      .join("\n");
    expect(statements).not.toMatch(/\b(delete\s+from|truncate|drop\s|rename\b|insert\s+into)/i);
  });

  it("parses into ordered pairs, most specific phrase before the bare-word fallback", () => {
    const list = pairs();
    expect(list.length).toBeGreaterThan(10);
    expect(list.at(-1)).toEqual(["StepZero", "BHW Headcount 2025"]);
    const fallback = list.findIndex(([from]) => from === "StepZero");
    list.slice(0, fallback).forEach(([from]) => expect(from).not.toBe("StepZero"));
  });

  it("leaves no old dataset name in any dictionary text the seeds wrote", () => {
    const lines = seededDictionaryLines().filter((line) => OLD_NAMES.test(line));
    expect(lines.length).toBeGreaterThan(10); // the seeds really do carry the old names
    const survivors = lines
      .map(rename)
      // Lowercase identifiers keep their names on purpose (agg_bhw_stepzero_counts …).
      .map((line) => line.replace(/[a-z0-9_./-]*stepzero[a-z0-9_./-]*/g, ""))
      .filter((line) => OLD_NAMES.test(line));
    expect(survivors).toEqual([]);
  });

  it("never changes an identifier quoted in the prose", () => {
    const identifiers = [
      "agg_bhw_stepzero_counts",
      "agg_bhw_stepzero_counts.n_total_bhw",
      "bhw-stepzero-2026",
      "dataset:bhw-stepzero-2026",
      "ingestion/ingest_stepzero.py",
      "ingestion/patch_dim_geo_stepzero_gap.py",
      "stepzero_only_v1",
      "census_year",
    ];
    for (const id of identifiers) expect(rename(id)).toBe(id);
    // The only pairs that could reach into a slug or table name are the ones naming StepZero, and
    // every identifier spells it in lowercase.
    for (const [from] of pairs()) if (/stepzero/i.test(from)) expect(from).toMatch(/StepZero/);
  });

  it("reads as plain English on the sentences users and the model meet", () => {
    expect(rename("StepZero quick-count by geography")).toBe("BHW Headcount 2025 by geography");
    expect(rename("Total BHWs in the DOH StepZero universe for this geography")).toBe(
      "Total BHWs in the BHW Headcount 2025 universe for this geography",
    );
    expect(
      rename(
        "which is why this table splits the StepZero headcount rather than the per-person census.",
      ),
    ).toBe(
      "which is why this table splits BHW Headcount 2025 rather than the per-person BHW Profiles 2025.",
    );
    expect(rename("Population reported alongside the quick count for this geography.")).toBe(
      "Population reported alongside the headcount for this geography.",
    );
    expect(rename("DERIVED FROM THE DISTRICT MAPPING AND THE PROFILED CENSUS TOGETHER")).toBe(
      "DERIVED FROM THE DISTRICT MAPPING AND BHW PROFILES 2025 TOGETHER",
    );
  });

  it("renames both dataset records by slug and keeps the slugs", () => {
    expect(sql).toMatch(/name = 'BHW Headcount 2025',[\s\S]*?where slug = 'bhw-stepzero-2026';/);
    expect(sql).toMatch(/set name = 'BHW Profiles 2025'\s+where slug = 'bhw-2025';/);
    expect(sql).not.toMatch(/set\s+slug\b/i);
  });

  it("expires cached answers by the data-version stamp of the two assistant scopes only", () => {
    expect(sql).toMatch(
      /update dim_dataset\s+set last_updated_at = now\(\)\s+where slug in \('bhw-2025', 'uuc-phc-2025'\);/,
    );
    expect(sql).not.toMatch(/ai_ask_cache|ai_narrative_cache|ai_ask_log|doc_chunk/);
  });
});
