# BHW analytics: public overview increment

Continues #525 and the Phase 0 audit in #527. Owner decision, 2026-11-15: **apply the stricter catalog-wide withholding rule to demographic and payer-specific charts throughout the new journey**. A protected cell anywhere in the accepted dimension/payer source withholds that measure across locations; a failed check fails closed. This decision does not authorize publishing withheld data through legacy readers.

## This review slice

Question answered: “What do the national BHW figures show, and where can I go next?”

- `/bhw` now reads the accepted national records through `getPublicDataset`, which executes the existing catalog disclosure/validation path for BHW only. It never sums overlapping geography records or falls back to legacy readers, AI caches or profile counts for missing headcount.
- Existing `StatHero`, `StatTile`, `Donut`, `FigureCard`, `FigureView`, `SourceDates` and presentation components render the result. The registration mix appears only when all three nonnegative categories are present and reconcile with the published total. Zero remains zero.
- Charts, table toggles, enlarged views and presentation data receive only cleared values. The presentation adapter also refuses values attached to a suppressed/failed/unsupported/unavailable state. The new overview does not attach legacy export links with a different disclosure contract.
- Headcount and validated-profile accreditation are separately named. Qualification and payer percentages are explicitly non-additive. Any-honorarium receipt is distinct from payer receipt and amount/sufficiency.
- Certification denominator provenance was checked in `ingestion/build_aggregates.sql`, section 5: each percentage divides the relevant flag count by all profiles in the selected geography. The overview uses the published national percentage, never a mean of local rates.
- The all-location BHW card title opens the visual overview. Its indicator link still opens inventory. Scoped card titles retain the original geography rather than sending readers to the national overview.
- Purpose anchors organize workforce, qualifications, support and methodology. Existing map/compare routes remain reachable. Source details are supporting information; warnings beside affected figures remain visible.

Legacy richer figures remain implemented in Explore/place/comparison. This increment does not certify their disclosure parity or use them as a workaround for withheld overview values. Old AI commentary, amount/sufficiency charts and regional insights are not fed into this new overview without an adapter audit. They remain named follow-up work below, not deleted capabilities.

## Acceptance covered and remaining

Covered here: national visuals without choosing a region, distinct populations, honest zero/unavailable/failed/withheld states, existing chart/table/presentation reuse, source-aware default entry and no new disclosure through this overview. No AI request blocks first useful content.

Still open in Phase 1: global place/indicator/action search, two-to-four-place comparison controls, shared cross-page analysis state, overview distribution map preview and linked rankings, proportional-circle default, labels/locator/scale/map controls, missing-geometry summary, filter/classification editing and full-state exports. The map link still opens the existing workspace; it is not presented as satisfying the new map contract.

Follow-up disclosure audit must cover legacy place/Explore/compare/export and cached narratives before their demographic/payer values are integrated into the new shared journey. Amount distributions/sufficiency require separate source-wide contracts. Phase 2's deeper charts and Phase 3 contextual interpretations retain their original prerequisites. Physical devices, representative performance and historical HUC boundary/license acceptance remain open in #527.

## Validation

Synthetic tests cover privacy failure/withholding before value reads, national-only queries, no unrelated-source probes, malicious values attached to withheld states, zero/missing headcount, incomplete or inconsistent registration mixes, guarded chart props and scope-preserving entry links. Browser cases cover desktop/phone navigation, chart/table switching and keyboard methodology expansion. Record actual command/browser results in the PR; do not infer live completeness from synthetic coverage.

Local verification on 2026-11-15: lint and types passed; 1,891 unit tests passed, 55 skipped without the required database/environment; migration lint and the prefixed no-data production build passed, including standalone extractor verification. Two Playwright overview flows passed against an ephemeral synthetic PostgREST fixture at 390×844 and 1440×1000. Six additional page checks (clear, withheld and failed source × both sizes) had no page errors, ordinary overflow or axe violations. These are bounded synthetic checks, not live release validation or a physical-device result. The full seeded-database browser suite remains a CI check.

Phone visual inspection found clipped qualification labels and excessive space before the first figure. Compact tick labels now retain full names in table views, accessible descriptions and tooltips; the first headline/chart appears within the tested 844-pixel viewport. A follow-up check confirmed a cleared education category's full name in the accessible chart description and table, plus presentation entry/exit. Synthetic fixture counts and screenshots are not committed.
