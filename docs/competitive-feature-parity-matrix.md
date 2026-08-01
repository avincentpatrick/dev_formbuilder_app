# Competitive / Feature-Parity Matrix

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0 — a **living checklist**, expected to be revisited every time a phase ships or a competitor materially changes its offering, not a one-time snapshot.
**Purpose:** Maps KoboToolbox/ODK, Fillout.com, and the legacy system `dev_pk_new`'s capabilities against this product's Main Features list and phase roadmap (both in the approved architecture plan and `docs/PRD.md`), so "are we at parity yet" has one authoritative answer instead of being re-litigated in every planning conversation.

**Methodology & confidence note** — this document inherits the same epistemic posture the founding architecture plan stated explicitly: *"exact Fillout.com pricing/limits...should be treated as indicative, not pinned...re-check current figures before quoting them in customer-facing material."* The same applies here to every ✓/partial/✗ judgment about a competitor's current feature set — competitor products change their offerings frequently; treat this matrix as **directionally accurate as of this document's writing (2026-07-03)**, not a verified, current-as-of-today audit. Legend: **✓** = fully supported today, **◐** = partial/limited support, **✗** = not supported, **N/A** = not applicable to that product's positioning.

---

## 1. Core Form Building

| Capability | Kobo/ODK | Fillout | Legacy (`dev_pk_new`) | This Product | Phase | Main Feature / Doc # |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Structured field/section editor (not raw JSON) | ✓ | ✓ | ✓ | ✓ | 1 | #8 |
| Live preview while building | ◐ (Enketo preview, separate step) | ✓ | ✓ | ✓ | 1 | #8 |
| Categorized field-type picker | ✓ | ✓ | ✓ | ✓ | 1 | #8 |
| Field-type catalog breadth (30+ types) | ✓ (extensive) | ◐ (fewer, business-form-oriented types) | ✓ (~30 types) | ✓ (31 types, Phase 1 subset → Phase 2 full) | 1→2 | #8 |
| Sections/groups with reordering | ✓ | ✓ | ✓ | ✓ | 1 | #8 |
| Repeat groups | ✓ | ◐ (workarounds only, no native repeat) | ✓ (separate-table storage) | ✓ | 2 | #8 |
| Matrix/grid questions | ◐ (via repeat + select workaround) | ✓ | ✓ | ✓ | 2 | #8 |
| Answer piping into later question text | ◐ (`${name}` in a `label` cell is the XLSForm idiom, but escaping/output-encoding is the form-renderer's problem, unspecified) | ✓ | ✗ | ✓ (`${key}` holes under a separately-versioned template grammar + a per-surface output-encoding contract) — **backend SHIPPED H6a** (grammar, publish gate, PHP render on the inbox/encode/export surfaces); the reactive guest-runtime render is H6b | 3 | #26 |
| Piping into the confirmation/thank-you screen | ✗ (no author-editable completion text) | ✓ | ✗ | ✓ — **storage + builder editor SHIPPED H6a** (`forms.confirmation_message`/`_translations`, validated at publish, emitted raw to the runtime); the render is H6b | 3 | #26 |
| Hidden fields prefilled from the sharing link (`?promo=…`) | ◐ (`calculate`/`appearance: hidden` exist, but there is no link-parameter concept — XLSForm targets paper/mobile, not shared URLs) | ✓ (its headline "hidden fields" feature) | ✗ | ✓ — **SHIPPED H7**: opt-in per field (`config.prefill_source`), so a field key is never an implicit query parameter | 3 | #5 |
| Hidden fields with a server-authoritative fixed value | ✗ (a `default` is a client-side hint the submitting device supplies) | ◐ (a hidden field is client-supplied whatever its source) | ✗ | ✓ — **SHIPPED H7**: a `fixed` field's value is written server-side from the authored literal and the submitted value is never inspected, on every ingest channel. **This is a deliberate divergence from both competitors**, and it is what lets a later payment amount be bound to form data safely | 3 | #5 |
| Likert-scale questions | ◐ (modeled as select_one rows) | ✓ | ✓ (numeric-score storage, confirmed-good) | ✓ | 2 | #8 |
| Cascading select (N-level) | ✓ | ✗ | ✓ (PSGC-specific) | ✓ (generalized) | 2 | #8 |
| Geopoint/geotrace/geoshape | ✓ | ✗ | ◐ (JSON strings, no spatial query) | ✓ (PostGIS-backed) | 2 | #8 |
| Media capture (image/audio/video/signature) | ✓ | ◐ (file upload, no native capture UX) | ✓ | ✓ | 2 (video/audio) 1 (file/signature) | #8 |
| Simple no-code skip logic | ✓ | ✓ (visual "conditional logic" builder) | ✓ | ✓ | 1 | #8 |
| Full expression engine (`relevant`/`constraint`, cross-repeat functions) | ✓ (XLSForm-native) | ◐ (formula fields, less XLSForm-standard) | ✓ | ✓ | 2 | #8 |
| Calculated fields, no `eval()` | ✓ | ✓ | ✓ (confirmed-good) | ✓ | 2 | #8 |
| Bidirectional XLSForm import/export | ✓ (native) | ✗ | ✓ | ✓ | 2 | #8, #16 |
| Draft/publish versioning model | ✓ (ODK Central: draft vs. deployed) | ✗ (always live-editable) | ✗ (legacy's core gap) | ✓ (immutable `form_versions`) | 1 | #8 |

## 2. Submission Channels & Collection

| Capability | Kobo/ODK | Fillout | Legacy | This Product | Phase | Main Feature / Doc # |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Guest/public link submission | ✓ | ✓ | ✓ | ✓ | 1 | #3 |
| Embeddable form widget | ◐ (iframe only) | ✓ (rich embed options) | ✗ | ◐ (iframe at MVP; richer embed a Phase 3 candidate, not yet committed) | 1 (basic) | #3 |
| Manual/staff encoding | ✓ (via app) | N/A (not this product's use case) | ✓ | ✓ | 1 | #7 |
| Offline data collection | ✓ (native strength — ODK's origin use case) | ✗ | ✗ (print → OCR workaround only) | ✓ (Phase 2, installable PWA) | 2 | #5 |
| OCR — single form | ✗ | ✗ | ◐ (built, unfinished polish) | ✓ | 3 | #1 |
| OCR — linelist/batch | ✗ | ✗ | ✗ (legacy's own docs: "scoped, never implemented") | ✓ | 3 | #2 |
| Partial-save-and-resume | ◐ (session-based, not link-based) | ✓ | ✗ | ✓ | 3 | (Form-Filling UX Flow, doc #20) |
| API/programmatic import (same Phase-1 `POST /submissions` endpoint as manual encoding — not a separate live channel; see PRD §6) | ✓ | ✓ (via integrations) | ◐ (limited) | ✓ | 1 | — |

## 3. Review, Validation & Analytics

| Capability | Kobo/ODK | Fillout | Legacy | This Product | Phase | Main Feature / Doc # |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Submission approve/return review workflow | ◐ (validation status exists, less structured review UX) | ✗ (not this product's core use case) | ✓ | ✓ | 1 | — |
| Basic per-form dashboard | ✓ | ✓ | ✓ | ✓ | 1 | #4 |
| Org-wide cross-form dashboard | ◐ (project-level, not deeply cross-form) | ✓ | ✗ (legacy's own roadmap: "never fully built") | ✓ | 1 (basic) → 3 (advanced) | #4 |
| Streamed/chunked large exports | ✓ | ✓ | ✓ (memory-safe pattern, confirmed-good) | ✓ | 1 | — |
| Per-submission PDF record | ◐ (per-submission print view; no queued artifact, no storage accounting) | ✓ | ✗ | ✓ — **SHIPPED H17**: queued (`GeneratePdfJob` on the exports queue), stored as an `export_artifact` attachment against the tenant's storage quota, emailed to the requester, superseded in place on regenerate | 3 | #26 |
| PDF shows only the questions the respondent was SHOWN | ✗ | ✗ (renders the whole form) | ✗ | ✓ — **SHIPPED H17**, and the differentiator of this row: relevance is re-derived with the clock pinned to the submission's finalisation, so a skipped branch is ABSENT rather than a page of blanks, while a shown-and-skipped question still appears with an em-dash | 3 | #26 |
| Cursor-based paginated API exports | ✓ (v2 API) | ◐ | ✗ | ✓ | 1 | — |

## 4. Platform, Access & Commercial

| Capability | Kobo/ODK | Fillout | Legacy | This Product | Phase | Main Feature / Doc # |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| True shared-schema multi-tenant SaaS | ◐ (account-per-org, not shared-schema RLS-style) | ✓ | ✗ (single-org only) | ✓ (ADR-0002) | 0 | — |
| Fine-grained per-form roles (Owner/Admin/Editor/Reviewer/Viewer) | ◐ (manage/add-submissions/view/validate permissions per form, different shape) | ◐ (workspace-level roles, less per-form granularity) | ✗ (single-org, no tenant-scoped RBAC) | ✓ | 1 | Doc #9 |
| Tenant invite/membership lifecycle | ◐ | ✓ | ✗ | ✓ | 1 | Doc #9 |
| Audit trail — user-facing viewer | ◐ (limited, admin-only in most deployments) | ◐ | ✓ (Auditable trait, not user-facing UI) | ✓ (first-class, user-facing) | 1 | #12 |
| Subscription billing tiers | ✓ (Kobo has both free & enterprise tiers) | ✓ | ✗ (single-org, no billing) | ✓ | 1 | — |
| Webhooks | ◐ (basic REST hook) | ✓ (extensive native integrations) | ✗ | ✓ (queue-first, retry/circuit-breaker) | 1 (first webhook) → 3 (full integration list) | — |
| Native third-party integrations (Zapier, Slack, Sheets) | ✗ | ✓ (extensive) | ✗ | ◐ (short starter list, phased in — explicitly "not 50 at once") | 3 | — |
| Embedded payments/checkout | ✗ | ✓ | ✗ | ✓ | 3 | — |
| Custom domains | ✗ | ✓ (paid tier) | ✗ | ✓ (paid tier) | 3 | — |
| SSO/SAML | ✓ (Enterprise tier) | ✓ (higher tiers) | ✗ | ✓ | 4 | — |
| Data residency options | ✓ (humanitarian/compliance focus) | ◐ | ✗ | ✓ | 4 | — |
| Dedicated-DB tenancy for compliance-driven customers | N/A (different architecture entirely) | N/A | N/A | ✓ (planned escape hatch, ADR-0002) | 4 | — |
| GDPR export/erasure tooling | ◐ | ✓ | ✗ | ✓ | 4 | Doc #12 |
| WCAG AA public-runtime accessibility | ◐ (varies by deployment) | ◐ | ✗ (not audited) | ✓ (CI-enforced from Phase 1) | 1 | — |
| Single, consistent design system across every page | ◐ (Kobo's UI is functional but not described as a unified design system) | ✓ | ✗ (legacy's own stated, unenforced rule) | ✓ (Main Feature #6, this product's single biggest UX differentiator) | 0→1 | #6 |

## 5. User Personalization & Platform Ops (this product's own additions — #9–#12)

These four features were added at the user's explicit request after the original plan's approval and have no close Kobo/Fillout/legacy analogue worth a parity comparison — included here for completeness, not competitive benchmarking:

| Capability | Notes |
|---|---|
| User theme/appearance preferences (#9) | Neither Kobo nor Fillout is known to offer per-user accent/font-size/dyslexia-font personalization layered on a governed design-token system; this is a genuine differentiator, not a parity target. |
| App Settings / toggles / maintenance mode (#10) | Standard SaaS-operations feature; not a competitive differentiator either way. |
| In-app feedback mechanism (#11) | Common SaaS pattern; not benchmarked against competitors. |
| Audit trail user-facing viewer (#12) | Already covered in §4 above as a genuine differentiator versus both Kobo and Fillout's more admin-only/backend-only audit exposure. |

---

## 6. Deliberate Non-Goals — Where We Are *Not* Chasing Parity

A living competitive matrix is dishonest if it only ever shows gaps to close. Some capabilities exist in Kobo or Fillout that this product deliberately does not plan to match, and saying so explicitly prevents a future reader from mistaking silence for an oversight:

- **Kobo's raw XForm-level API surface and self-hosting model** — Kobo/ODK Central is often self-hosted by NGOs directly; this product is SaaS-only by design (architecture plan §1, hosting recommendation). Matching Kobo's self-hosting story is out of scope permanently, not deferred.
- **Fillout's very broad, generic-business-form integration catalog** (50+ integrations) — the architecture plan explicitly commits to "a short native list...phased in — not 50 at once" (§2.5); chasing Fillout's integration breadth for its own sake is not a goal, only integrations with demonstrated tenant demand are.
- **ODK's raw, spreadsheet-first XLSForm-only authoring workflow as the primary builder UX** — XLSForm import/export is a first-class *interoperability* feature (Doc #16), but the primary authoring experience is always this product's own structured builder, never "author a spreadsheet first."

## 7. Where This Product Already Exceeds Both

- **Unified `SubmissionPipeline` across all six submission channels** — neither Kobo nor Fillout is documented as having one single, explicitly named validation/persistence pipeline spanning manual, guest, both OCR channels, offline-sync, and API import; this is an architectural discipline this product's own legacy audit motivated (architecture plan §2.2, §5).
- **Immutable form versioning with submissions FK'd to a specific version, from Phase 1** — ODK Central has a comparable draft/publish model, but Fillout has none, and this is a structural (not bolt-on) commitment here from day one.
- **OCR linelist/batch channel** — explicitly confirmed as unimplemented in the legacy system's own planning docs, and not offered by either Kobo or Fillout; a genuine, real differentiator once built (Phase 3).
- **User-facing audit trail as a first-class product feature** rather than an admin-only backend log.
- **One governed design system spanning every page** (builder, dashboard, submissions, settings, public runtime) — explicitly named as this product's single biggest lever for feeling coherent/intuitive (Main Feature #6), a standard neither Kobo nor legacy is documented as enforcing product-wide.
