# Barangay maps for the BHW Census and StepZero — disclosure proposal

10 November 2026. **Decided by the owner the same day (section 8): counts are mapped at barangay level now;
rates, averages, honorarium and training are not.** BHW Census and StepZero had been excluded from barangay
drill-down (`lib/maps/barangay-support.ts`) because barangay-level numbers about a small group of people can
identify them. The proposal below is kept as written, with the measured numbers that replaced its estimate.

## 1. What the rules already say

- `BUILD_PLAN.md` §4.1: raw tables are service-role only; the public reads only curated `agg_*` tables.
  "Suppression is enforced at aggregate-build time: … any individual-level cell with `n < 5` at barangay level is
  nulled, flagged `is_suppressed = true`, and given a `rollup_geo_code/rollup_geo_level` pointer to the nearest
  safe ancestor. **(Counts of totals — e.g. "this barangay has 3 BHWs" — are not suppressed; only
  person-characteristic breakdowns are.)**"
- Risk P3: "small-cell re-identification (sex skew makes n<5 cells routine)". The release checklist requires that
  no page, API or export can return an unsuppressed individual-level cell with n<5 at barangay level.
- The NHFR and UUC barangay maps (live) are place inventories and follow the "counts of totals" exemption.

## 2. What the map would show, and what the tables hold at barangay level

| Indicator | Kind | Table | Barangay rows? | Suppression flag in the table? |
| --- | --- | --- | --- | --- |
| Validated profiles (BHW Census) | a total | `agg_bhw_counts.n_total` | yes | none |
| Total, registered, non-registered BHWs (StepZero, LGU-declared) | totals / a category split | `agg_bhw_stepzero_counts` | yes | none |
| Source population, households (StepZero) | context, not BHW data | same | yes | none |
| Accreditation %, any-honorarium %, average years active (Census) | a characteristic of the people | `agg_bhw_counts` | yes | **none** |
| Training coverage % by topic (Census, 44 topics) | a characteristic | `agg_training` | **no, by design** | n/a |

Three things the table shows that matter:

1. `agg_bhw_counts` carries barangay rows for the rates but **has no suppression flag** (only the demographics
   and honorarium tables do). A barangay with 2 profiles has a stored accreditation percentage of 0, 50 or 100.
   Today the application never maps it. If it did, the application would have to apply the rule itself.
2. There are **no barangay training rows**: building them was dropped after the disk-cap incident
   (`DECISIONS.md`, 39,276 barangays × 44 topics). Barangay training maps need a new aggregate build and a load by
   the operator, so they are not an application-only change.
3. Raw BHW records cannot be read by the public application, so a barangay reader cannot compute anything new:
   it can only read what the aggregates already hold.

## 3. How much of the map the rule would blank

First an estimate from municipality totals (11 provinces, 251 municipalities): the average barangay has 7.8
validated profiles, and in 27% of municipalities the average barangay has fewer than 5.

Then a **measurement**, replacing the estimate: the public Explore dashboard shows a figure for every barangay,
so 128 barangays were read at random (8 from each of 2 municipalities in each of 10 provinces; not a national
sample). **Validated profiles per barangay: median 7, mean 8.5; 24% have fewer than 5 and 73% have fewer than
10.** Total BHWs (StepZero quick count): median 8, mean 9.3; 19% have fewer than 5 and 64% fewer than 10.

So a rate map under "both groups at least 5" (option C, at least 10 profiles) would be blank for roughly three
barangays in four; under "denominator at least 5" (option B) for about one in four. Counts are never blank.

**What the live dashboards already do.** The Explore page for a barangay shows its total BHWs and validated
profiles, and shows rates with a small-sample warning ("n = 5 (fewer than 30); rates can swing widely"); it
hides a figure when "fewer than 5 BHWs are represented in that cell". A barangay with exactly 5 profiles shows an
accreditation percentage. That is option B's behaviour, looser than option C. It is recorded here for the owner:
the agreed rule for map rates (C) is stricter than what the dashboards show today.

## 4. Issues to decide knowingly

- **Rates and the cells behind them.** "n<5 at barangay level" is written for a *cell*. A rate hides two cells:
  the people with the characteristic and the people without. A barangay with 6 profiles of whom 1 is accredited
  has a 1-person cell, so the rate shows one person's status. Strictly, both cells need at least 5, which means
  at least 10 profiles.
- **Recovering a suppressed barangay by subtraction.** Municipal numerators are already on the dashboards. If
  exactly one barangay in a municipality is suppressed and the others are visible, the hidden one can be
  recovered by subtracting. The usual remedy is complementary suppression (hide a second cell). Counts of totals
  are not affected.
- **Sensitivity differs.** Accreditation status is administrative; honorarium receipt is financial.
- **StepZero is LGU-declared aggregate counts**, not individual records, and is at lower risk. Whether its
  registered / non-registered split at barangay level counts as a "person-characteristic breakdown" is for the
  owner to say.

## 5. Options

| | What is mapped at barangay level | Cost |
| --- | --- | --- |
| **A. Counts only** | Validated profiles; StepZero total and registered counts; population and households as context. No rates, no means. | Smallest. Matches the written rule exactly ("counts of totals are not suppressed"). No new data. |
| **B. Counts, plus rates where the denominator is 5 or more** | A, plus accreditation %, average years, honorarium %, with n<5 barangays shown as "suppressed, see the municipality". | Weaker than the written cell rule (a 1-person cell can show). Mostly coloured. |
| **C. Counts, plus rates only where both cells are 5 or more** | A, plus the rates when at least 10 profiles and each side of the split has 5 or more; otherwise "suppressed" with the municipality's value shown as context. | Follows the written rule. By section 3, large parts of the map are blank; honest about it. |

## 6. Recommendation

1. **Do A now** (one small application change): the policy already allows it, it needs no new data, and it
   shows where BHWs are.
2. **Rates under C, later, after the owner confirms the threshold**: C is the written rule. Show suppressed
   barangays as "suppressed (fewer than N people)" with the municipal value as context, never as blank or zero.
3. **Leave honorarium percentages and average years at municipality level**: they are the most sensitive, and
   the map gains little.
4. **Leave barangay training maps** until someone decides they are worth a new aggregate build.
5. **Decide the subtraction risk consciously**: accept it with a stated reason, or add complementary suppression.

## 7. Questions for the owner

1. May BHW counts per barangay be mapped (option A)? Does the StepZero registered / non-registered split count
   as a total?
2. For rates, is the rule "both cells at least 5" (C), "denominator at least 5" (B), or no barangay rates?
3. Should honorarium and average-years stay at municipality level?
4. Is barangay training coverage wanted enough to build its aggregates?
5. Who signs off on the privacy side (the data owner or the privacy contact named in the privacy notice)?

## 8. Decisions (owner, 10 November 2026)

1. **Counts per barangay: yes.** BHW Census validated profiles and the StepZero counts (total, registered,
   registered and accredited, non-registered) are mapped per barangay, with StepZero's source population and
   households as context. The registered / non-registered split is treated as a total.
2. **Rates: the rule is "both groups behind a rate have at least 5 people" (option C), accepted for when
   barangay rates are built. None are built now.** (The owner's reply was "okay" to the recommendation; this
   reading is recorded so it can be corrected.)
3. **Honorarium and average years of service stay at municipality level.**
4. **Barangay training coverage: no new aggregate build.**
5. **Privacy sign-off: the owner.**

Built: counts only, read from the barangay aggregate rows the dashboards already use; no new data, schema or
suppression logic. A test keeps every barangay-mapped BHW indicator a count, so a rate cannot be added by
accident. The map says beside a barangay count map that percentages, averages, honorarium and training are
shown for municipalities and above.
