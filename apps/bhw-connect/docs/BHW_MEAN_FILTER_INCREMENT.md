# BHW analytics — mean active-years range filter

Phase 1 increment after #549 under the #525 handoff.
Question: “Which areas have a published mean active-years value in this range?”

BHW Profiles mean active years reuses ValueFilterControls, valueFilterState,
map outlines, the complete table, URL/history and JSON/PNG export paths. Limits
are inclusive nonnegative finite decimals in years; blank leaves a bound unset.
This is a mean, not a percentage, so 100 is not a maximum. Counts retain their
whole-number validation and supported shares retain their 0–100 domain.

The source contract is already established by census-active-years.ts and its tests:
AVG(active_years_count) in ingestion/build_aggregates.sql ignores missing counts.
Total profile count is not the contributing sample size. The published parent
mean is retained; child means are never averaged, and no sample size or confidence
interval is invented. No source or disclosure eligibility changes. Barangay means
remain unsupported.

PNG review exposed floating-point interpolation tails in the existing legend.
Label formatting now uses 12 significant digits, increasing precision as needed
to keep distinct edges distinct. Whole counts retain full precision; numeric
bounds and membership are unchanged.

Filtering preserves all rows and published means. Existing quantiles use every
reported child-area mean, including areas without drawable shapes; filtering does
not recalculate them. Missing values, missing geometry and excluded values remain
distinct. Mean charts gain no count rankings, circles or editable classification
in this increment. Reset and measure/dataset changes clear the range. Shared links
reject invalid, duplicate, reversed and unsupported settings explicitly.

Unit fixtures cover zero, decimals, values above 100, invalid inputs, shared URLs,
unit isolation, unchanged parent mean/legend/denominator, and missing geometry.
Synthetic phone/desktop browser cases cover validation, Enter, Back/Forward,
share/reset and JSON/PNG downloads. Samples do not establish exhaustive geography,
physical-device or representative-reader acceptance.

Editable mean classification, contextual measure contracts and full cross-page
state remain Phase 1 follow-ups. Boundary/license/disclosure audits and reader
acceptance remain separately tracked; synchronized comparison maps are Phase 2.
