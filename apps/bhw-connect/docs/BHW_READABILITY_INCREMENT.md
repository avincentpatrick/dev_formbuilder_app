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

## Remaining assessment work within existing phases

| Existing phase              | Assessment follow-up / disposition                                                                                                                                                                                                                                              |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 0: source definitions       | Extend the existing inventory to the expanded measures; verify source meanings for registration/accreditation, education-level categories, blood-type codes and Indigenous Peoples categories before renaming them.                                                             |
| 1: overview clarity         | This increment starts duplicate removal and grouping. Still review headline order, repeated accreditation presentation, demographic priority, source-note length, plain navigation/search labels and approved-user messaging. Preserve counts and data access.                  |
| 1: chart accessibility      | Check clipped labels, axis wording, number precision, age/year/payment-band ordering, keyboard/table alternatives and mobile layouts. Do not change calculation precision while formatting.                                                                                     |
| 2: training                 | Reuse the delivered comparison matrix. Consolidate overview topic percentages and training years into a searchable table; review all 44 labels, acronyms and questionnaire wording. Preserve overlap and recorded-training limitations.                                         |
| 2: compensation             | Reuse delivered distributions. Review average/median presentation, source terminology, combined-total population notes, missing-versus-zero labels, historical-reference explanation, and advanced percentile/Gini placement. No new adequacy or legal-standard interpretation. |
| 2: workload/service history | Explain median and middle-half ranges; distinguish assigned-household distribution from aggregate context ratios. Review chronological cohort display and explain why it is not historical workforce size.                                                                      |
| 3: interpretation           | Preserve delivered context contracts. Review wording for association, reporting gaps, peer universes and historical payment references; do not introduce a staffing-need score or unsupported causal claims.                                                                    |
| 4: reusable standard        | Evaluate overview/detail comprehension with general readers, BHW/LGU readers and analysts before generalizing. Localization remains a separately scoped follow-up.                                                                                                              |

## Verification and completion boundary

Regression checks cover one education display with retained destinations, closed detail groups with accessible exact/comparison links, zero and withheld values, and existing scope/map state behavior. Required lint, types, full unit suite, migration lint and prefixed build are run for the application changes. Browser checks must exercise opening/closing groups with keyboard at phone and desktop widths. Record actual outcomes in the PR; do not infer physical-device acceptance or live release from local checks.

This increment does not close an analytics phase or finish the entire readability backlog. Continue with the remaining rows above against the latest main and open PRs.
