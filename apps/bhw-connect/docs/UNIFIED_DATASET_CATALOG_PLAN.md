# Public unified dataset catalog implementation plan

Status: approved product scope; implementation not started.
Planning date: 2026-11-07.

## Resume instruction

Use this prompt when ready:

> Read apps/bhw-connect/AGENTS.md and follow it. Implement apps/bhw-connect/docs/UNIFIED_DATASET_CATALOG_PLAN.md in phased increments. Start by verifying the current system and completing the first unfinished increment. Preserve the decisions in this plan, update its progress record with evidence, and follow the repository's validation and delivery rules.

This document authorizes no implementation by itself. The planning session requested documentation only. When implementation is requested, use small, reviewable increments in dependency order. Do not reopen settled product decisions unless new evidence reveals a conflict; explain that conflict precisely.

## Goal and agreed scope

Create one public catalog that connects the available datasets through shared PSGC and congressional district browsing. The catalog becomes the main browsing experience. Preserve existing dataset detail pages and links where possible. Keep ingestion and history functions separate from public browsing; do not mistake the existing portal's dataset links for upload records.

| Dataset card | Category | Intended content |
| --- | --- | --- |
| BHW | Health workforce | Workforce counts, accreditation, training, demographics, and honorarium |
| NHFR | Health facilities | Facility counts, type, ownership, beds, and geographic coverage |
| UUC-PHC | Primary care access | Listed barangays, qualifying criteria, and supporting indicators |
| FHSIS | Health services | Immunization, maternal care, water and sanitation, TB services, and non-BHW workforce |
| Population | Population and demographics | Accepted population figures and source information |
| Poverty | Socioeconomic conditions | Published poverty measures and their coverage |

Exclude Profiling Status from this catalog. This is not an instruction to delete its records, routes, or other existing features. Legislative districts are a browsing mode with separately accessible mapping provenance, not a seventh substantive dataset card. Categories organize the six cards; individual indicators belong inside their relevant dataset. Future datasets can extend the catalog without adding a bespoke browsing system.

### Settled interaction decisions

- Start at **All locations**, showing available datasets across the country. This is distinct from **National**, which requires national figures or valid national calculations.
- Provide a global overview with available dataset count, available indicator count, geographic coverage, and freshness. Update it with the selection. Count logical datasets and canonical indicators, not tables, uploads, or repeated source appearances.
- Use two separate modes: **PSGC** and **Congressional district**. Clear geographic selection when switching modes; do not guess equivalent areas.
- PSGC browsing supports national → region → province → city/municipality → barangay. Follow the actual geography hierarchy, including places without an applicable province, rather than forcing artificial parents.
- District browsing begins with congressional district selection, then its constituent municipalities/cities, then barangays. Retain district scope during drill-down, including when only part of a city belongs to it.
- Show a few relevant headline figures on each card for the selected location, alongside coverage and source date. In All locations, show dataset summaries and coverage; do not imply that every preview is a national statistic.
- Clicking a card opens its indicators, data table, coverage, authoritative sources, and update information, preserving the selected scope where supported.
- Hide cards with no available indicators for the selection. Explain omissions below the results, for example, “FHSIS is not available at barangay level.”
- Keep cards with mixed availability and show “2 of 5 indicators available,” using a clearly defined indicator inventory. Identify missing indicators inside the details.
- Use current reconciled data only. No year or period selector. Keep source dates visible; the latest accepted releases can have different reference dates.

### Data rules

1. Preserve one authoritative source per indicator under the established reconciliation rules. New ingestion updates the accepted data; it does not create competing public values or duplicate cards.
2. Distinguish BHW total workforce from validated profiles and other genuinely different measures. Similar names do not make different populations interchangeable.
3. A real zero is available data. Keep zero-facility and zero-listed-barangay results visible.
4. Distinguish unavailable data, unsupported geographic level, suppressed values, partial coverage, and load failures. Do not turn a failed query into a claim that the source did not report data.
5. Permit higher-level calculations only where the measure, geography, source definitions, and constituent records support them. Label the derivation. Prefer the authoritative published figure where reconciliation specifies it.
6. Show incomplete coverage, such as “8 of 10 barangays covered.” Label a calculated sum over those records **Partial total**; never imply full coverage.
7. Never sum percentages or average rates indiscriminately. Recompute a rate only from compatible, authoritative numerators and denominators with adequate coverage. Do not substitute population denominators across datasets without an existing approved rule.
8. Never repeat a municipality's figure at barangay level. Never allocate a whole city's figure to a district containing only part of that city without valid finer-grained data.
9. Separate data completeness from substantive coverage. “Barangays with a facility” measures service presence; it is not itself a measure of missing records.
10. Preserve all public aggregation and small-group suppression rules, including protection against deriving suppressed values by combining cards or totals. Do not publish internal records or document passages.

## Existing foundations and evidence limits

The planning inspection reviewed repository code and documentation, not live database records. Recheck these files before implementation because the application may have changed:

- `app/page.tsx`: current public portal with separate dataset links.
- `components/portal/dataset-card.tsx`: existing portal card component.
- `lib/db/dataset.ts`: dataset identity, source dates, and dataset-specific lookups. Several releases use pinned slugs; current-release selection is not automatically implemented by this plan.
- `lib/db/nhfr.ts`: facility measures, meaningful zeros, and facility barangay links. Existing detail navigation stops at municipality/city; some facilities lack barangay attribution.
- `lib/db/uuc-phc.ts` and related helpers: listing counts, criteria, and barangay membership. Existing count/detail navigation does not provide a general barangay dashboard.
- `lib/db/fhsis.ts`: service indicators, numerator/denominator handling, and unavailable-versus-unreported behavior. Documented finest source level is municipality/city.
- `lib/db/districts.ts`: existing district membership, mapping quality, and BHW-oriented district queries. Do not assume these already aggregate all six datasets.
- `lib/db/area-profile.ts` and `area-profile-suppression.ts`: consolidated place data and suppression patterns. The existing consolidated admin page includes internal material and must not simply be made public.
- `docs/FHSIS_2025_PLAN.md`: FHSIS must not supply a competing BHW count; preserve the existing BHW source authority and source-specific denominators.
- `docs/FHSIS_DIAGNOSIS.md`: deployed FHSIS queries returned no figures at several tested locations; root cause remains unconfirmed. A dictionary, dataset registration, or synthetic seed does not prove live availability.
- `lib/db/population.ts`, `lib/db/poverty.ts`, and their underlying schemas: inspect actual supported geography and measures before specifying previews or rollups.

The app runs on the shared Windows server with PostgreSQL and PostgREST. Legacy Supabase names in the code do not imply a live Supabase deployment. Follow the app's instructions and working agreement for access, migrations, and deployment. Do not load synthetic development figures into production or commit real source records.

## Phased increments

Each increment should end with a focused diff, relevant verification, and an updated progress entry. Keep the public portal functional while incomplete features are developed. A blocked dataset should not prevent the other supported datasets from working, but must not be falsely presented as available.

### Increment 1 — Verify the inventory and geographic capabilities

Deliverables:

- Inventory the six datasets, their canonical indicators, accepted sources, current-release rules, units, denominators, suppression requirements, and date metadata.
- Create a capability matrix for national, region, province, city/municipality, barangay, and district: published, safely calculable, unsupported, or awaiting verification.
- Inspect existing reconciliation and PSGC crosswalk rules, independent-city handling, district membership, and unmapped records.
- Check live availability through authorized read-only access where available. Record the FHSIS status separately from the existence of its UI and schema.
- Choose headline indicators from verified measures, keeping BHW universe counts and validated-profile counts clearly named.

Acceptance: every proposed preview and geographic level has a documented source and rule. Unknown live availability is explicitly recorded. No guessed district/ barangay coverage or invented data.

### Increment 2 — Define a shared public catalog data layer

Depends on Increment 1.

Deliverables:

- Define six stable catalog identities independent of individual ingestion runs and release rows, with category, title, source metadata, supported levels, indicator inventory, and detail destination.
- Add adapters over existing accepted data helpers; reuse established reconciliation rather than creating a second numeric source of truth.
- Model data states explicitly: available (including zero), partial, unsupported level, unavailable, suppressed, and failed read. Preserve meaningful reasons for UI rendering.
- Define overview counts and freshness precisely, including how missing/suppressed indicators affect counts. Avoid duplicate indicator counts across datasets.
- Represent All locations separately from a concrete geographic selection.

Acceptance: focused tests cover identity stability, zero values, missing rows, read failures, partial availability, canonical-source selection, and overview counting. No internal-only data enters the public response.

### Increment 3 — Build the overview and PSGC catalog through municipality/city

Depends on Increment 2.

Deliverables:

- Build the public catalog layout with overview, geographic controls, six eligible cards, preview figures, and source/update labels.
- Support All locations and national-to-city/municipality navigation, including valid hierarchy exceptions.
- Make URL state preserve mode and location for refresh, links, and back navigation. Follow the `/bhw-connect` base-path rules.
- Apply location filtering, mixed availability counts, omission explanations, loading/error states, and empty results.
- Connect cards to scoped detail views without breaking existing public dataset URLs.

Acceptance: selecting a location updates overview and cards together; zero remains visible; no card silently retains the previous location's figures. Verify mobile layout, keyboard access, and shared links.

### Increment 4 — Add barangay support and safe calculations

Depends on Increments 1–3.

Deliverables:

- Add barangay selection and dataset-specific displays where underlying evidence supports it, including UUC membership and NHFR attribution as appropriate.
- Implement only calculations approved by the capability matrix, with source lineage and coverage labels.
- Preserve unassigned records as an explicit attribution limitation; do not infer their barangay or silently treat barangay sums as a complete municipal total.
- Keep FHSIS municipality figures out of barangay results unless a subsequently verified source genuinely provides barangay data.
- Apply public suppression to preview figures, details, and any derived totals.

Acceptance: verify complete and partial coverage, valid zeros, unsupported levels, unassigned geography, rate calculation rules, and suppression. “Partial total” and coverage denominators are accurate.

### Increment 5 — Add congressional district browsing

Depends on Increments 1–4.

Deliverables:

- Reuse the accepted district membership map and expose mapping provenance and known gaps.
- Implement district → constituent municipality/city → constituent barangay navigation, with the geographic selection cleared when modes switch.
- Aggregate each dataset only over compatible, non-overlapping members. Prevent double counting when mappings mix municipality and barangay membership.
- Keep partial-city scope throughout drill-down. Do not lose district scope when linking to details; provide an appropriate scoped detail presentation if existing routes cannot express it.
- For municipal-grain datasets, derive district values only when complete constituent units and measure rules allow it. Explain unsupported split-city results.

Acceptance: verify a district made of whole municipalities, a split-city district, mixed membership, an unresolved mapping, mode switching, and district-scoped detail links. No whole-city leakage or duplicate counting.

### Increment 6 — Complete detail views and portal integration

Depends on Increments 3–5.

Deliverables:

- Complete consistent details for all six cards: indicator definitions, values/tables, coverage, unavailable reasons, authoritative source, derivation where relevant, source date, and update date.
- Make the catalog the main public browsing experience while preserving existing deep links and keeping ingestion/history separate.
- Exclude Profiling Status from catalog cards and overview counts without deleting the underlying feature.
- Keep district mapping documentation accessible without counting it as a seventh substantive dataset.
- Confirm that new ingestion can refresh the accepted release and catalog metadata without generating duplicate cards or conflicting indicator values.

Acceptance: users can start at the overview, choose either geographic mode, open each available card, inspect its source, and return without losing context. No time filter is introduced.

### Increment 7 — Verify the complete public flow and deliver

Depends on all prior increments.

Deliverables:

- Run repository-required lint, type checking, tests, migration checks if applicable, and production-style build under the base path.
- Exercise browser flows for All locations, national, region, province, municipality/city, barangay, whole-municipality district, split-city district, empty results, partial coverage, zero values, and failed reads.
- Verify public access without admin data, retained suppression across combined cards, mobile usability, keyboard navigation, and reasonable query volume.
- Validate source figures against authorized data where accessible; distinguish synthetic fixture verification from live-data verification in the report.
- Follow repository PR and deployment rules. Check deployed public flows after an authorized deployment and record any remaining data/operator blockers, particularly FHSIS.

Acceptance: the agreed six-card catalog and both browsing modes work end to end for supported data. Unsupported or unavailable data is explained truthfully. No application outside BHW Connect is changed incidentally.

## Out of scope

- Year/period filtering, historical comparison, and new unrelated datasets.
- Replacing existing reconciliation rules or silently changing authoritative sources.
- Estimating unavailable barangay/district figures from population shares.
- Rebuilding ingestion administration, deleting Profiling Status, or exposing the admin consolidated profile publicly.
- New infrastructure or a migration back to Supabase/Vercel.

## Progress record

Update this table when implementation begins. Record relevant commits/PRs, tests, evidence, and blockers; do not mark an increment complete solely because its UI exists.

| Increment | Status | Evidence and remaining work |
| --- | --- | --- |
| 1. Inventory and capabilities | Not started | Planning inspected repository foundations; live availability not verified |
| 2. Public catalog data layer | Not started | |
| 3. Overview and PSGC through municipality/city | Not started | |
| 4. Barangay support and calculations | Not started | |
| 5. Congressional districts | Not started | |
| 6. Details and portal integration | Not started | |
| 7. End-to-end verification and delivery | Not started | |
