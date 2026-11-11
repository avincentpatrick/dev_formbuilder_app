# BHW naming, 2026 data, and one full map per dataset — plan

Status: agreed with avincentpatrick on 2026-11-10. Steps 1 and 2 shipped in #501; **Step 3 is built** in the
pull request that follows it.

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

## Step 3 — One full map per dataset, opened from its card — built

Address decided 2026-11-11 (avincentpatrick): keep `/map`, so every link already shared keeps working with no
redirect.

- **Grouping:** `lib/maps/subjects.ts` files each map release under its catalog dataset: BHW → Headcount 2025,
  Profiles 2025 (and the hidden 2026 profiling status); NHFR, UUC-PHC, FHSIS, Population → their one release;
  Poverty → poverty and LGU income class; Legislative districts on its own. The per-release definitions stay
  the contract underneath.
- **Card link:** every card and the dataset view carry **"Open full map →"**, which opens `/map` on the
  dataset's first release at the place chosen on the catalog (a barangay opens inside its municipality, or its
  province where the indicator has no barangay figures; a district selection opens the national view).
- **Map page:** names the dataset ("BHW map"), links back to its card, and its picker groups releases by dataset,
  so BHW reads Headcount 2025 / Profiles 2025. Switching keeps the area and never carries values across.
  `/map` with no dataset lists one link per dataset.
- **Home page:** the map gallery is gone; no map or map data loads there. "More resources" links to all maps.
- **Removed:** `components/maps/dataset-map-gallery.tsx`, and the empty Ways forward slot from the picker (its
  definition stays). The district map is reachable from the picker; it is still an availability view.
- **Tests:** the e2e specs that proved several maps could share the home page (two-map limit, independent card
  searches, phone and tall-screen paging) went with the gallery. New ones prove the home page draws no map, a
  card opens its map at the chosen place and back, and BHW's two releases sit side by side.
