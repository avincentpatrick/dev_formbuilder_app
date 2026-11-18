# BHW analytics — Phase 2 acceptance and delivery evidence

18 November 2026. Requirements are the original assessment in
[#525](https://github.com/avincentpatrick/dev_formbuilder_app/pull/525), section 6:
**comparisons and qualification/support depth**. Implementation alone is not
acceptance: use the delivery notes in the linked PRs below for the latest actual
release, checks, live observations and limitations. Later delivery notes supersede
the implementation-only snapshots in this document.

The owner has deferred Phase 1 physical-device performance measurements and
Headcount reuse metadata. They remain open, not passed. VoiceOver is accepted
by owner report on iPhone/Chrome. The bounded engineering/live checks through
#592 do not certify nationwide source coverage.

## Delivery evidence ledger

| Requirement | Delivery record |
| --- | --- |
| Training matrix | [#593](https://github.com/avincentpatrick/dev_formbuilder_app/pull/593): verified live in release `1c4b4884` on 18 November. Both-width browser flows passed. Region 01/02 percentages matched raw approved map observations, and counts/bases matched separate exact-measure views. All 88 cells for two sampled barangays remained unsupported. |
| Compensation story and distributions | [#594](https://github.com/avincentpatrick/dev_formbuilder_app/pull/594): verified in the same actual release. Both-width browser flows passed. One payer’s five quantiles matched exact-measure pages at region 01; province 01028, municipality 0102801 and barangay 0102801001 kept incomplete distributions unplotted. |
| Paired maps and common breaks | [#595](https://github.com/avincentpatrick/dev_formbuilder_app/pull/595): implementation, required checks and merge are recorded there; consult its delivery update for the actual deployed SHA and bounded live samples. |
| Locality position among peers and composition reconciliation | [#596](https://github.com/avincentpatrick/dev_formbuilder_app/pull/596): implementation, required checks and the five-scope qualification evidence are recorded there; consult its delivery update for the peer panel’s actual deployed SHA and live samples. |

The training and compensation release was explicitly assembled and switched live
by [Deploy 44316725433](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/44316725433)
at 13:45:39 UTC. A merge, HTTP 200 or successful older workflow is not sufficient
deployment evidence for later increments. Each PR’s delivery update records its
own evidence; no pending or failed check is treated as passed. These samples are
comparisons between views of the same approved source, not independent source
audits or nationwide certification. Production workforce values are not copied
into this public record.

## Requirement reconciliation

| Requirement | Existing capability and unfinished work |
| --- | --- |
| Synchronized comparison maps, common breaks, phone A/B | Public comparison already validates same-level places, retains two-to-four selections and phone focus. The single map workspace has classification and geographic controls. The paired-map follow-up integrates shared measure/breaks, linked drill/parent and phone A/B with URL scope. See `BHW_PAIRED_MAPS.md`; delivery requires the PR’s checks and live evidence. Independent pan/zoom resets on phone remount/refresh. |
| Qualification/registration composition | Overview already shows a registration mix only when all exclusive parts equal the reported total, plus certification and education charts. The 18 November bounded five-scope live check reconciled the overview composition with 20 exact-measure pages at 390px and 1440px (20 exact-measure pages per width); existing zero/incomplete/withheld tests passed. Details and limits are in `BHW_PEER_DISTRIBUTION.md`. Overlapping certifications remain separate bars, not a 100% stack. |
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
