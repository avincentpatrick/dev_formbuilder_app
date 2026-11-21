# Health facilities visual analysis — Phase 2

Builds on the unified Health facilities card. The accepted source remains the DOH
September 2026 snapshot. No import, schema change or new client dependency.

- Facility mix: all accepted types, descending counts, share of the area's total;
  government/private counts and shares use each type's own denominator.
- Geographic distribution: child-area counts on one common count scale.
- Barangay presence: child-area fractions on a 0–100% scale, ordered by the raw
  numerator/denominator, not the rounded legacy percentage. No-denominator areas
  show an unavailable percentage, not zero.
- Eight initial rows, native keyboard-accessible disclosure for the remainder;
  complete exact tables, local search, map and downloads remain available.
- The headline presence count also has a visual proportion when its complete
  accepted denominator is valid.

Type comparisons require unique categories, nonnegative integer counts, reconciled
ownership partitions and agreement with the accepted area total. Geographic
comparisons require the complete child roster, matching totals and valid presence
numerators/denominators. Failed/incomplete inputs retain exact detail and a status
message; they do not manufacture a ranking. Child geographies share `getNhfrChildGeos`, a request-local cached wrapper
used by both the breakdown and the completeness check. Final review added this
wrapper because the generic geography reader itself is not cached.

Counts are not adjusted for population or need. Registry categories are not proof
of available services. Presence is not travel access, care quality or adequacy.
The source's missing barangay attribution remains explicit.

Local evidence: lint (existing fixture warning only), types, 2,185 unit tests
passed / 56 DB-dependent skips, migration lint, both prefixed production builds
and extractor checks. Six browser cases passed at 390/1440px for the new mix and
existing facility search/history. A separate local synthetic national/18-region
fixture exercised complete child charts and native disclosure; no overflow at
390px. The phone chart was visually inspected. These synthetic records are not
committed and are not evidence of production counts. CI/release receipts follow
in the implementing PR.

React review: charts are server-rendered HTML, real text supplies accessible values,
bar geometry is decorative, native details supply interaction, direct imports,
no new fetch waterfall or client state.
