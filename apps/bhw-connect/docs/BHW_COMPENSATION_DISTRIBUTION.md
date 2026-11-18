# Compensation story and published distributions

18 November 2026. Phase 2 follow-up to the training comparison in #593.

Question answered: after seeing who reports receiving an allowance and which
government levels pay, how much do recorded amounts vary within each paying
level? The existing locality-scoped Support and compensation section now offers
three explicit steps: receipt/payers, amounts among each payer's recipients,
and combined-profile amounts with their different population.

The existing `RangeChartClient` used by the Explore honorarium distribution is
reused with `FigureCard`, the public catalog reader, approved-value guard and
source display. No legacy reader/export endpoint or additional database read is
introduced. `BhwAnalysisSection` still provides individual exact measures and
comparison links. All new captions receive the same validated locality as the
rest of the dashboard; no national fallback is added.

Each payer's box requires five scalar, public, finite, nonnegative monthly-peso
quantiles from the accepted Profiles 2025 release in nondecreasing order.
Equal endpoints and zero are valid. A missing, failed, unsupported, withheld,
incompatible or inconsistent endpoint prevents that box from being drawn.
The table names the reason; raw rejected amounts never become chart props,
accessible chart labels or table values. Existing independently approved exact
statistics remain available below; no endpoint is reconstructed from them.

The semantic table reports exact published amounts (up to two display decimals)
alongside the existing range chart. Its labeled, focusable scroll region supports
narrow screens and keyboard access. The chart's median labels use the existing
peso formatter; the exact table remains the precise reference. The line shows
minimum–maximum, the box the middle half and the mark the median.

Receipt percentages use all validated profiles; payer amount distributions use
recipients at that payer level. Payers overlap. Payer medians, percentiles and
ranges are not summed into combined pay. The combined figures retain their
existing all-profile or positive-recipient definitions and historical-reference
caveats. Recorded allowances are not current payroll or a legal pay standard.

Phase 2 coverage: reuse of a distribution component, dedicated compensation
navigation/population explanation, honest missingness and disclosure protection.
This increment does not add an all-peer distribution/rank or synchronized maps;
those remain in `BHW_PHASE_TWO_ACCEPTANCE.md`. Source-null quantiles still require
authorized source data. The owner-deferred Phase 1 timing/Headcount terms remain
open and VoiceOver acceptance by owner report is unchanged.

Model/SSR tests cover complete/tied/zero distributions, incompatible releases and
units, missing endpoints, invalid order and rejection of withheld payloads.
Phone/desktop browser checks cover locality retention, story anchors, table access,
refresh/Back and overflow. Full checks and actual deployed observations are
recorded in the implementation PR, not assumed from the presence of these files.

Browser verification also exposed a fragment-navigation defect: framework links
could append `#honorarium-amounts` after an existing `#support` fragment. The
purpose/story links now use native in-page anchors, which preserve the current
locality/query and replace the fragment. Regression checks cover multiple section
changes, methodology, refresh and browser Back at both widths.
