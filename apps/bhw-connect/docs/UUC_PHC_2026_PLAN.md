# UUC for PHC 2026 — the second edition, beside the first

The 2026 list of Unserved and Underserved Communities for Primary Health Care joins the 2025 list in
the existing `/uuc-phc` section. Both stay: the section shows one edition at a time behind a
**2026 | 2025 switch**, opening on the newest.

## Decisions (owner, 2026-11-23)

1. **A year switch, 2026 by default.** Every page of the section reads `?edition=<year>`; no parameter
   means the newest edition loaded. The default edition never appears in a URL, so a shared link to
   "the current list" keeps meaning that.
2. **A "what changed" view, in the same release.** `/uuc-phc/changes` lists the barangays that joined
   and left the list between consecutive editions, by region and province, and the barangays on both
   lists whose recorded values differ.
3. **The data ships as committed seed migrations**, as the 2025 list did. This is a deliberate
   exception to `CLAUDE.md`'s "never commit data": the list is a published government issuance of
   places, with no personal data. It is not a precedent for anything personal.
4. **Sulu is under Region IX** in the 2026 list, as in 2025 and everywhere in the app
   (`20261123100000_sulu_region_ix.sql`).

## ⚠ Before merging: the issuance placeholder

The 2026 list's circular number and date were not available when this was built. Two places carry the
marker `DC No. to be confirmed`:

- `lib/uuc-phc/editions.ts` — `ISSUANCE_PLACEHOLDER`, and set `issuanceConfirmed: true`;
- `supabase/migrations/20261124090000_seed_dim_dataset_uuc_phc_2026.sql` — `source_name`.

`grep -rn "to be confirmed" apps/bhw-connect` finds both.

## Sources

- **The 2026 assessment workbook** (sheet `2026`): one row per listed barangay, the same 42 columns as
  2025's `Reconciled` sheet. 6,129 rows, every one `UUA`.
- **The 2026 list as published** (sheet `NATIONAL`, on the 2025 list's template): region, province,
  city/municipality, barangay and PSGC code. Built from the workbook; 5,981 codes carried over from the
  2025 list by name and 148 resolved against PSA's PSGC (six with a spelling difference, recorded in
  the pull request). It is the source for membership, codes and region.

Both stay out of git (`ingestion/data/`, see its README).

## Pipeline

| Step | Artefact |
|---|---|
| Clean | `ingestion/clean_uuc_phc_2026.py` — the 2025 rules imported from `clean_uuc_phc_indicators.py`, unchanged; membership must match the list exactly or the run fails |
| Load | `ingestion/ingest_uuc_phc.py --edition 2026` — per-edition regression figures; 2025's output is byte-identical to before |
| Seed | `20261124090000` dataset row · `…090100` list (6,129) · `…090200` indicators |
| Aggregate | `20261124090300_agg_uuc_phc_2026.sql` — 2025's four statements with the slug changed, assertions scoped to 2026 |
| Views | `20261124090400_uuc_phc_edition_views.sql` — quality and benchmark views per edition, the two change views, and `ref_uuc_phc_provincial` restricted to 2025 (it had no dataset filter) |

Every 2026 migration writes only where `dim_geo` holds barangays. CI and the local copy run new
migrations against empty tables before their made-up seed (`db/load-dev.mjs`), so there they do
nothing and the app shows 2025 only — the edition switch hides itself and the catalog reads 2025. On
the testing server the geography is loaded and everything is written. Refresh the dev copy after
deploy (`bhw-dev-snapshot.yml`) to bring 2026 into CI.

## Figures the load is checked against

National 6,129; BARMM 331; Region IX 613 (Sulu's 87 included); CAR 624; Region V 767 — every region
as the list's own summary prints it. 1,603 values bounded across 1,415 barangays (Water 892, FIC 466,
Pre-natal 211, SBA 30, ABR 2, IMR 1, UFMR 1). Against 2025: 148 joined, 6 left (net +142), 70 on
both lists with a changed value (68 of them the criterion (d) score).

## What reads the edition

- **The section** — every page, the downloads (`?edition=` on the export routes; file names carry the
  slug), the PNG one-pager, the methodology (lists each edition and its total).
- **Elsewhere** — `/explore`, `/place/*`, the area profile, link previews and the sitemap use the
  newest edition loaded (`defaultUucEdition()`).
- **Catalog card** — `ACCEPTED_RELEASES.uuc` is 2026; `lib/catalog/reader.ts` falls back to 2025
  where 2026 is not loaded, and the card reports the source it actually read.
- **Map** — both editions are releases of the UUC-PHC subject; a catalog card opens the edition it
  read.
- **AI assistant** — the prompt names both editions and requires a `dataset_id` filter, answering
  about 2026 unless asked. The AI insight shows only on the default edition. Cached answers expire
  when 2026 loads (the seed bumps 2025's `last_updated_at`).
- **Feedback** — tagged with the edition on screen.
- **Data quality** — recomputed per edition; the cue-card reconciliation is 2025's alone.
