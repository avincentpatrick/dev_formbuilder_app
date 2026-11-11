# BHW naming, 2026 data, and one full map per dataset — plan

Status: agreed with avincentpatrick on 2026-11-10, for jongsky25 to review. **Steps 1 and 2 are built** in
the pull request that adds this file; Step 3 is still a plan.

## Why

1. **StepZero is 2025 data labelled 2026.** `docs/DECISIONS.md` (2026-07-20) records the owner's confirmation:
   StepZero is the LGU headcount taken at the start of the 2025 profiling exercise, `as_of_date` `2025-01-01`.
   Only the slug `bhw-stepzero-2026` carries "2026", and the map layer copied it (`lib/maps/definitions.ts`
   labels it "StepZero 2026", period "2026").
2. **"StepZero" and "Census" are the wrong words for users.** The LGU count is the full count; the per-person
   dataset is the profiled subset, not a census.
3. **2026 data is not presented yet**, but the 2026 profiling-status section is still live.
4. **Maps sit beside their dataset instead of inside it.** The home page shows 6 dataset cards (one per
   subject) and, separately, a gallery of map cards (one per release), so BHW appears as two unrelated map
   cards ("BHW Census 2025", "StepZero 2026") next to a BHW card that already combines both.

## Decisions (avincentpatrick, 2026-11-10)

| Item | Decision |
|---|---|
| StepZero / "quick-count" | Renamed **BHW Headcount 2025** — "All BHWs, as reported by each LGU before profiling began" |
| BHW Census 2025 / "validated profiles" | Renamed **BHW Profiles 2025** — "BHWs individually profiled and validated, about 97% of registered BHWs in the headcount" |
| Ratio between the two | **Profiling coverage** = Profiles ÷ registered BHWs in the headcount |
| Profiling status 2026 | **Hidden** from production |
| NHFR September 2026 | **Unchanged** — the rule is about BHW profiling data, not the facility registry |
| Maps | Each dataset card gets an **"Open full map →"** link to a dedicated full-size map page; the home-page map gallery is removed |

The 97% figure, stated the same way everywhere: headcount 306,835 total, of which 278,240 registered;
270,917 profiled = **97.4% of registered BHWs** (88.3% of all counted — unregistered BHWs were not profiled).

## Step 1 — Rename (labels only) — built

- Replace user-facing "StepZero", "quick-count", "BHW Census" and "Census profiles" with the names above:
  page text, glossary (`lib/glossary/terms.ts`), methodology, map titles and periods (`lib/maps/definitions.ts`:
  title **BHW Headcount 2025**, period **2025**), export captions, catalog labels ("LGU-declared" becomes
  "LGU-reported headcount"), and the AI assistant's prompts and tool descriptions (`lib/ai/`).
- **Leave alone:** "census" meaning the PSA population census (POPCEN 2024 / CPH 2020); every internal
  identifier — slugs `bhw-stepzero-2026` and `bhw-2025`, table `agg_bhw_stepzero_counts`, `lib/db/stepzero.ts`.
  Renaming those changes stored records and is a destructive migration for no user benefit.
- Size today: "StepZero" on 88 lines in 27 files, "Census" on 57 lines in 33 files (some of them PSA).
- The `/bhw` dashboard shows both datasets, so its title and breadcrumb read **BHW 2025**.
- Shared map links that still say `period=2026` for the headcount open it as 2025 (`lib/maps/prototype.ts`);
  no other release gains that alias.
- Not changed: the knowledge base and answer-bank text stored in the database, which may still say
  "StepZero"; the AI prompts now tell the model to use the new names.

## Step 2 — Hide Profiling status 2026 — built

One switch, `lib/releases/visibility.ts` (`HIDDEN_RELEASES`), read by:

- `app/profiling-status/layout.tsx` → `notFound()` for the whole section, methodology included;
- `app/api/export/profiling-status/route.ts` → 404;
- `app/sitemap.ts` → drop the section and its per-place URLs;
- `components/home/geo-search.tsx` → no profiling-status result links;
- `lib/maps/prototype.ts` (`parseMapChoice`), the map picker and the gallery → the release leaves the picker and
  the map API refuses it;
- the admin place page → its link to the public section is hidden (the figures on that page stay).

The public chat cannot reach profiling status; only the admin assistant can, so the AI tools are unchanged.
Nothing on the UUC-PHC pages links to the section (an earlier reading of this plan said so; it was a comment).
The two e2e specs that rendered the profiling map were replaced by one proving it is absent; restore them from
git history with the release.

The admin place page (`app/admin/(dashboard)/place/...`) keeps its link, so admins can still check it. The
data and migrations stay; turning the switch on restores everything.

## Step 3 — One full map per dataset, opened from its card

- **Grouping:** add a mapping from each catalog dataset to its map releases (new, in `lib/maps/`):
  BHW → Headcount 2025 + Profiles 2025; NHFR, UUC-PHC, FHSIS, Population → their one release each;
  Poverty → poverty + LGU income class. The per-release definitions in `lib/maps/definitions.ts` stay the
  contract underneath, so each release keeps its own period, denominator, disclosure and legend.
- **Card link:** every card in `components/catalog/catalog-results.tsx` and the dataset view
  (`CatalogDetails`) gets **"Open full map →"**, carrying the location already chosen on the catalog.
- **Map page:** `/catalog/map?dataset=bhw&…` (or `/map?dataset=bhw`, see open question), one full-width map,
  the existing enlarged `DatasetMapCard`. BHW gets a switch between **Headcount** and **Profiles**; switching
  keeps the area and never carries values across releases (the existing `dataset-map-page.tsx` rule).
- **Home page:** remove `<DatasetMapGallery />` from `app/page.tsx`, and the "Dataset maps" link under More
  resources. No live maps on the home page at all.
- **Old links:** `/map?dataset=<release>&…` redirects to its dataset's map page with that release selected.
- **Orphan map cards:** Legislative districts moves to `/districts`; Ways forward (no data) is dropped.
- **Records:** add a `docs/DECISIONS.md` entry that this replaces `DATASET_MAPS_PLAN.md`'s "one card per
  release" layout (its data rules stand); update `e2e/dataset-maps.spec.ts`.

## Order

Step 1 and Step 2 are independent and small; ship them first. Step 3 lands as two PRs: grouping plus the map
page and card links (nothing removed), then the gallery removal, redirects and orphans.

## Open question for jongsky25

The map page address: `/catalog/map?dataset=bhw` (keeps it inside the catalog it belongs to) or reuse `/map`
with subject slugs. Recommendation: reuse `/map`, since shared links already point there and the redirect
becomes a slug translation inside one page.
