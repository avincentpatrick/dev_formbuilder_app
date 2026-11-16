# BHW analytics — published percentage range filters

Phase 1 non-count range increment after #547 under the #525 handoff.
Question: “Which areas have published shares within the percentage range I choose?”

The existing range control now supports BHW Headcount declared accreditation and
BHW Profiles accreditation, any-honorarium receipt and allowed training shares.
Their existing readers validate the 0–100 share domain; this does not assume that
all ratios or all measures using a percent sign share that domain. Means, source
population/households and contextual datasets remain separate follow-ups.

Limits accept finite nonnegative decimals through 100, inclusive at both ends;
blank leaves that bound unset. Invalid, reversed, duplicated and unsupported URLs
are rejected. Decimal serialization preserves tiny decimal settings without an
exponent mismatch. Count ranges retain their whole-number contract. Percentage
filters are unavailable at barangay level; no source/disclosure eligibility expands.

Reuses ValueFilterControls, valueFilterState, existing map fills/table, history and
JSON/PNG exports. Published parent rates, denominators, source intervals, complete
rows and fixed classification remain unchanged. Child rates are never averaged.
Missing values and missing geometry retain their existing separate meanings.
Switching indicator/dataset resets ranges. Percentage charts do not acquire count
rankings or proportional circles. Export filter metadata states its unit.

Unit fixtures cover endpoints/decimals, domain errors, share round trips,
count-domain isolation, supported measures, missing geometry, unchanged parent
rate and fixed breaks. Synthetic phone/desktop browser fixtures edit invalid/valid
limits, use Enter, Back/Forward, share/reset, JSON and PNG. Exact checks are in the
PR; samples do not establish exhaustive geography or physical-device evaluation.

Editable percentage classification, mean/contextual domains, full cross-page
state and representative-reader acceptance remain Phase 1 work. Boundary,
licensing and broader disclosure prerequisites remain separately tracked.
