# BHW readability — integrated analytics backlog

19 November 2026. Continues the analytics roadmap in [#525](https://github.com/avincentpatrick/dev_formbuilder_app/pull/525), not a second numbered delivery plan. The 17 November usability assessment is a checklist across the existing phases. Rechecked against main `2fba84ff` before implementation.

## Current baseline and decisions

- Phase 2 training matrix, compensation distributions, paired maps and peer positions have merged (#593–596). Phase 3 relationships, review lists and context options have merged (#598–600). See `BHW_PHASE_TWO_ACCEPTANCE.md` and `BHW_PHASE_THREE_ACCEPTANCE.md` and their PR delivery notes for actual evidence; merge alone is not deployment or acceptance.
- The later acceptance records retain owner-deferred physical-device performance and Headcount reuse metadata as open, and owner-reported iPhone/Chrome VoiceOver acceptance. Do not reinstate an already superseded blocker or claim deferred work passed.
- Counts beside percentages and explicitly approximate derived counts (#578/#579) remain. The aggregate publication decision in `BHW_AGGREGATE_PUBLICATION.md` supersedes older blanket withholding for its named measures; payment amount and payment-band rules remain distinct.
- Section navigation already uses native fragment anchors. The earlier malformed-link finding is not a reason to replace the repaired navigation. Preserve locality, map snapshots, Back/Forward and exact-measure destinations.
- English-only initial delivery, accepted periods, populations, geographic eligibility, data readers and source-null handling remain unchanged.

## First implementation: overview readability

Question: can readers reach the main story without scanning every detailed training and payment statistic?

- Keep the education chart once under qualifications; retain exact-data and comparison links there and explain its location in demographics.
- Keep payment charts, distributions and combined-payment highlights visible. Group the long training list, payer-specific statistic list and combined-payment detail list behind three named native disclosure controls with measure counts.
- Retain every grouped indicator, its exact-data/comparison links and its unavailable/withheld status. Grouping changes default presentation, not publication rules or measure availability.
- Remove the repeated title and nested border inside each expanded measure. Name its article from the measure label; the parent summary remains the visible label.
- Explain local-government counts versus checked profiles next to the summary figures, including the limitation of their cross-source percentage. Keep the established count-and-percentage display.
- Explain overlapping training topics and what a median training year does not establish. Replace the duplicated education disclosure boilerplate with a plain description of highest recorded education and missing information.

Reuse: server-rendered overview/analysis components, native details/summary, existing chart and distribution components, approved catalog observations and existing URL builders. No new hooks, requests, dependencies, routes, migrations or analytics calculations. Native disclosure changes visibility; it is not claimed as a bundle-size or data-loading optimization.

## Assessment disposition within existing phases

| Existing phase | Implementation disposition | Remaining evidence |
| --- | --- | --- |
| 0: source definitions | Expanded-family inventory audited against catalog and aggregate contracts; source populations explained consistently. | Source-owner dictionary for ambiguous blood, education and IP codes and unverified course abbreviations. Original labels retained. |
| 1: overview clarity | Duplicate education removed; accreditation duplication disclosed; age/sex prioritized; extra characteristics grouped; plain search/map wording and access explanation supplied. Headline counts and percentages preserved. | Representative-reader comprehension. |
| 1: chart accessibility | Natural age/year/band ordering, wrapped labels, plain axes, retained full tables and keyboard controls. Existing centralized percentage precision retained. | Existing owner-deferred physical-device performance; broader assistive-technology evaluation is not inferred from browser tests. |
| 2: training | 44 searchable topic/year pairs replace 88 entries; original labels, independent statuses and all destinations retained. Delivered comparison matrix reused. | Confirm unverified program terminology with source owner. |
| 2: compensation | Existing distribution reused; 24 statistics consolidated; average/median explained; advanced ratios grouped; missing/zero and historical-reference caveats retained. | Reader interpretation of receipt versus amount, payer overlap and reference values. |
| 2: workload/service history | Median and validated middle half lead; technical values remain accessible; chronological cohorts explicitly describe 2025 profiles. | Reader distinction between assignments and aggregate area ratios. |
| 3: interpretation | Existing context/peer/relationship contracts preserved; glossary reconciled with their population and causal limitations. | Existing acceptance records remain authoritative; Headcount reuse metadata stays owner-deferred. |
| 4: reusable standard | A concrete comprehension protocol is prepared below. | Run it with general, BHW/LGU and analyst readers before claiming acceptance or generalizing. Localization remains separately scoped. |

These are implementation dispositions, not a claim that every change is deployed
or that human acceptance has passed. Use each PR's checks and release evidence for
delivery status. The implementation closes the actionable software checklist;
source clarification and reader acceptance remain explicitly open.

## Verification and completion boundary

Regression checks cover one education display with retained destinations, closed detail groups with accessible exact/comparison links, zero and withheld values, and existing scope/map state behavior. Required lint, types, full unit suite, migration lint and prefixed build are run for the application changes. Browser checks must exercise opening/closing groups with keyboard at phone and desktop widths. Record actual outcomes in the PR; do not infer physical-device acceptance or live release from local checks.

This increment does not close an analytics phase or finish the entire readability backlog. Continue with the remaining rows above against the latest main and open PRs.

## Follow-up: explain measures in ordinary language

Continues the same backlog after #601. Reuse `measureDefinition`, so overview details,
exact-measure pages and comparison descriptions receive consistent wording.

- Explain median, mean, percentile thresholds, minimum/maximum, Gini and the payment
  ratio alongside the affected measure. Percentile descriptions acknowledge ties.
- Explain the workload population (positive recorded assignments only), the
  all-profile combined-payment population, and the positive-total population used
  for inequality separately. Do not silently use one denominator for all three.
- Check against the existing aggregate definitions: the busiest group uses rank
  below 0.10, so ties can include more than exactly a tenth of workers. Cohorts
  describe the 2025 profiles, not today's workforce or a historical time series.
- Replace database-oriented missing-value wording and arithmetic-only percentage
  definitions with plain explanations. Preserve missing-versus-zero, overlapping
  sources/topics, monthly conversion, historical reference and non-current-payroll
  qualifications.

This edits interpretation text only. It does not change labels, source records,
calculations, acceptance rules or data access. No production values are copied into
this record. Existing local full checks and bounded rendered-page verification are
reported with the implementing PR; representative-reader evaluation remains open.

## Follow-up: natural category order

After #602, address the chart-order finding within the same analytics backlog.
Age bands run youngest to oldest; cohort years run chronologically; combined
payment bands run from the source's no-positive-payment band through its highest
amount band. Only existing public observations are sorted, without changing
values, inventing missing categories or recovering withheld cells. Unknown source
labels remain visible after recognized categories. The original observations are
not mutated.

Reuse `publicValues` at the presentation boundary so overview, exact-value pages
and comparison details agree. Pass an explicit input-order option through the
existing FigureView, BarChartClient and shared horizontal bar spec for ordered
measures only. Both inline and enlarged charts retain the same category sequence.
Their tables start in that sequence, retain numeric sorting and provide a category
header button to restore the original order. Other charts/tables keep their
existing descending-value defaults. Name the relevant axes Age group, Year and
Monthly allowance band instead of Category.

Verification covers unequal values that would otherwise reorder categories,
real zero, omitted categories, unknown labels, immutable inputs, rejected payloads,
actual Plot scale order and table defaults. Required full checks and browser
interaction evidence belong in the implementing PR. This does not establish a
historical workforce series or change the existing cohort interpretation.

## Follow-up: searchable training overview

Replace the 88 separate topic/year entries with one 44-topic table behind the
existing training disclosure. Pair the approved percentage with its separately
validated median training year by topic key. Reuse the delivered training matrix's
safe coverage/count/base preparation, without introducing a new database read.
Search accepts both simpler display labels and original source labels; original
wording remains expandable. Topic and year exact-data links and comparison links
retain the selected locality and map-return snapshot. The separate multi-place
training matrix remains available and unchanged.

The table explains the two populations, topic overlap and the limits of recorded
training. Sort by topic or descending coverage, preserving zero before unavailable
values. All 44 topics remain accessible, including unsupported and missing ones.
Technical program titles and disease names are retained where simplifying them
would require an unverified interpretation of course content. No training record
is reclassified as current competence or current certification.

Regression evidence includes incompatible year sources/units, rejected year
payloads independent of valid zero coverage, alias search, sorting, all-topic
retention, keyboard expansion, empty searches and phone table scrolling. Browser
verification distinguishes unavailable-data UI from populated source verification.

## Follow-up: concise summaries and consistent terminology

- Replace repetitive mean/median payer charts with the existing distribution chart
  and a compact 24-cell statistics table. The chart's exact values remain in a
  disclosure; each independently public statistic remains accessible even if the
  complete distribution cannot be plotted. Keep exact/comparison links and statuses.
- Lead workload with the median and an ordered, compatible public middle-half
  range. Keep service years, cohort series and technical statistics in details.
  Move the combined-payment percentile ratio out of the default headline.
- Prioritize age and sex; group other demographic characteristics. Keep education
  only under qualifications. Disclose the repeated headcount accreditation share.
- Wrap long chart labels within their gutters, allowing extra row height; preserve
  full source labels in scales, tables, tooltips and downloads. Use plain map/search
  labels and explain approved-user access beside the AI entry point.
- Reconcile glossary wording with the distinct headcount/profile populations,
  applicable publication policy, historical payment reference and area ratios.
  Preserve the existing source, period and geographic-context contracts.

The expanded-family source audit is recorded in
`UNIFIED_DATASET_CATALOG_INVENTORY.md`. Unverified source codes retain their original
labels. Source notes are already in a named disclosure; critical population,
missing-value and interpretation notes stay beside their figures.

## Completion checklist and human acceptance

Implementation evidence is recorded per PR. Software verification does not close
representative-reader acceptance. Before claiming the interface works for every
level of reader, use the following short protocol with a general reader, a BHW/LGU
reader and an analyst; include a phone user and a keyboard/screen-reader user.
Do not record personal details or production data in this public repository.

1. Find a place's workforce count. Explain why its checked-profile count can differ.
   Expected: two sources/populations; no addition or assumed person matching.
2. Find one training topic, its percentage and recorded year. Explain what the year
   does and does not mean. Expected: median among trained profiles with a year;
   neither everyone trained that year nor proof of current skills.
3. Compare payer amounts. Explain median versus average and whether the four payer
   medians can be added. Expected: middle versus arithmetic mean; do not add them.
4. Interpret no-positive-payment and the historical reference. Expected: missing
   is not proof of non-payment; the reference is not a legal minimum or enough pay.
5. Explain the household middle-half range, its excluded records and the separate
   area ratio. Expected: positive individual assignments; not visits or staffing need.
6. Find a full source label, an unavailable value, exact data and comparison links;
   return to the chosen locality/map. Record confusion, assistance and navigation
   failures before asking the reader to suggest wording.

Status: this protocol is prepared, **not performed**. Correct observed confusion
before adopting a generalized readability standard. Existing owner-deferred
physical-device performance and Headcount reuse metadata remain governed by the
Phase 2/3 acceptance records. Localization is outside the accepted English-only
scope. Source dictionary confirmation and representative-reader evaluation require
external evidence; neither can be substituted with automated tests.
