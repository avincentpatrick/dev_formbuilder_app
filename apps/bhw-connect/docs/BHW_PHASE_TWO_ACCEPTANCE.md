# BHW analytics — Phase 2 acceptance and first increment

18 November 2026. Requirements are the original assessment in
[#525](https://github.com/avincentpatrick/dev_formbuilder_app/pull/525), section 6:
**comparisons and qualification/support depth**. Phase 2 is not signed off.

The owner has deferred Phase 1 physical-device performance measurements and
Headcount reuse metadata. They remain open, not passed. VoiceOver is accepted
by owner report on iPhone/Chrome. The bounded engineering/live checks through
#592 do not certify nationwide source coverage.

## Reconciliation before implementation

| Requirement | Existing capability and unfinished work |
| --- | --- |
| Synchronized comparison maps, common breaks, phone A/B | Public comparison already validates same-level places, retains two-to-four selections and phone focus. The single map workspace has classification and geographic controls. Synchronized maps/common breaks are not integrated. |
| Qualification/registration composition | Overview already shows a registration mix only when all exclusive parts equal the reported total, plus certification and education charts. The 18 November bounded five-scope live check reconciled the overview composition with 20 exact-measure pages at 390px; existing zero/incomplete/withheld tests passed. Details and limits are in `BHW_PEER_DISTRIBUTION.md`. Overlapping certifications remain separate bars, not a 100% stack. |
| Places × training-topics heatmap | Approved topic percentages/counts already exist in the catalog and single-measure comparison. This increment adds a cross-topic matrix to that comparison. |
| Distributions and locality position among peers | The compensation follow-up reuses the existing range chart for approved payer quantiles. The peer-position follow-up integrates the existing dot strip into the public BHW map workspace with explicit child-area universe, exclusions and ties; see `BHW_PEER_DISTRIBUTION.md`. Delivery still requires its PR checks and live evidence. |
| Dedicated compensation story | Overview already includes receipt, payer amounts, combined-payment highlights and exact measures. The follow-up connects receipt → payer amounts → combined populations and adds a public distribution chart/table; see `BHW_COMPENSATION_DISTRIBUTION.md`. Delivery evidence belongs in its PR. This is not evidence that every amount is available. |

## Training comparison increment

User question: which recorded training topics have higher or lower coverage in
the selected places? Open a training measure in Compare places. The existing
place controls select two to four same-level areas; the matrix shows all 44
topics for those areas, alongside the existing selected-topic detail/source view.

Reuse: `PublicComparison`, its one request-local catalog reader and loaded
datasets, `publicValues`, `measureStatus`, `percentageFormatter`, existing topic
definitions and comparison URL builder. No additional database request, data
source, migration or legacy-reader access is introduced.

Cells retain exact percentages, supplied numerators and separate denominators.
Color intensity uses one 0–100% scale, with visible numeric/text alternatives.
The semantic table has row/column headers and a labeled keyboard-focusable
horizontal scroll region on narrow screens. Sort alphabetically or by one
place's descending coverage; ties sort by name and unavailable values remain
last. Sorting is a local viewing preference; shared links restore places,
measure and phone focus, with alphabetical order on a fresh visit.

Only available/partial scalar percentages from the accepted Profiles release
enter the client props. Missing, failed, unsupported and withheld values remain
explicit; rejected counts do not enter props or hidden markup. Out-of-range or
incompatible source/unit observations are unavailable. Zero remains zero.
Topics overlap and are never summed or averaged across places. This is a
selected-place comparison, not an all-peer ranking or a time-change claim.

Data prerequisites: approved topic observations for each selected area under
the existing Profiles 2025 contract. Barangay training remains unsupported;
the matrix must not replace it with municipal or national figures. Source-null
values need authorized source inputs, not a display workaround.

Verification: targeted model/integration tests cover zero, unequal denominators,
rejected payloads, source/unit compatibility, sorting/ties and request reuse.
Phone/desktop browser cases cover table access, sorting, topic navigation,
refresh/Back, retained selections/focus and page overflow. Required full checks,
deployment and bounded live observations are recorded in the implementation PR;
they must not be inferred from this implementation description.
