# Phase 2 paired comparison maps

18 November 2026. Implementation increment; deployment and live observations
must be checked in the implementing PR before treating this as delivered.

From Compare places, choose two of the two-to-four verified same-level places
and open paired maps. Both maps use one approved BHW measure, accepted release,
period and child geographic unit. Counts/means use quantile breaks computed
from both complete child-row sets together; percentages use common fixed
0–100% groups. No parent mean/rate is recomputed from children. A missing shape
does not remove a reported value from classification or the exact table.

The existing DatasetCanvas, map legend, bounded API retry, map activity budget,
classification helpers, source contract and locality-dashboard link builder
are reused. There is no new data source, migration or export path. At most two
map canvases render; a phone renders the active A/B panel only. The shared
measure, geographic pair, both child selections, phone panel and original
comparison return context are URL state. Refresh, Back and shared links retain
them. Pan/zoom is independent and local to each mounted map; switching phone
panels or refreshing fits the geographic area again. Each map retains its own
scale bar, so equal screen sizes do not imply equal geographic sizes.

Select one child on each map to drill both together. Drill is offered only
where both child levels support the next detail; parent navigation uses resolved
parents, never inferred PSGC prefixes. Separate dashboard links use each current
area or selected child. Barangay comparisons or unsupported child-level measures
do not substitute national figures. Failed, unavailable, malformed or
incompatible responses prevent the paired comparison; retry reads both again.
Old-scope results never render under new captions.

Exact values, missing data and missing shapes remain readable in semantic tables
with labeled, keyboard-focusable scroll regions. Sources, dates, units,
denominators and separate boundary/data license notes remain visible. Boundary
availability is not evidence of data availability. This increment does not add
map-only/presentation/export controls to the paired view; existing standalone
workspace controls remain available through the preserved comparison return.

Bounded verification uses synthetic API fixtures for common ranges, zero/null,
missing shapes, selected-child scope, linked drill/parent, phone switching,
refresh/Back, shared measure changes and a failed-side retry at 390/1440px.
Model tests reject ambiguous URLs, incompatible sources/dates/units, invalid
values and unresolved parents. These tests do not certify nationwide data or
boundary coverage. Physical-phone performance and Headcount reuse terms remain
deferred Phase 1 items; owner-reported VoiceOver acceptance is not a new test
of this increment.
