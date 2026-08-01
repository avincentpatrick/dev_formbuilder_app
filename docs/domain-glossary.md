# Domain Glossary

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0
**Purpose:** Reconciles three vocabularies this product draws on — KoboToolbox/ODK/XLSForm (research/M&E tradition), Fillout.com (modern business-forms tradition), and the legacy system `dev_pk_new` (this team's own prior implementation) — into one consistent term set, per `docs/PRD.md`'s own note: *"This document (and the product) standardizes on form → section → field → submission... A dedicated Domain Glossary (doc #2) will formalize this further; until then, this PRD is the reference for correct terminology."* This document is now that formalization — every other doc, and the eventual codebase, should use the **Standard Term** column below, not any of the source traditions' own words, except where a term is an external proper noun (§3).

---

## 1. Why Reconciliation Was Necessary — the "indicator" Collision

The most consequential single rename in this project is **"indicator" → "field."** Both KoboToolbox/ODK/XLSForm *and* the legacy system call a single form question an "indicator." That word choice creates a genuine ambiguity this product cannot inherit: in public-health/M&E practice, **"indicator" already has a separate, well-established meaning** — a measured metric or KPI (e.g., "vaccination coverage rate," "percentage of households with improved sanitation"), typically *computed from* survey data, not a single question *on* a survey. Kobo/ODK/legacy's usage overloads the same word for both "a question you ask" and "a metric you compute," relying on context to disambiguate. A hybrid product explicitly serving both M&E researchers (Persona A, `docs/PRD.md` §2.1) and general business users (Persona B, §2.2) cannot afford that overload — a metrics/analytics feature (`docs/PRD.md` Feature #4, Dashboard) will eventually need to talk about real KPIs/indicators in the traditional M&E sense, and reusing the word for "form question" would make that conversation permanently confusing. This product therefore adopts Fillout's word, **"field,"** for the question-level concept, freeing "indicator" for its correct, narrower, future meaning if the product ever needs it (not currently modeled — see §5).

Every other rename below follows the same principle: prefer whichever source tradition's word is least overloaded and most self-explanatory to a mixed technical/non-technical reader (`docs/PRD.md`'s stated audience), and record the mapping so nobody has to guess which word means what in a design conversation, a code review, or a support ticket.

---

## 2. Core Structural Terms

| Standard Term | Kobo / ODK / XLSForm | Fillout | Legacy (`dev_pk_new`) | Definition |
|---|---|---|---|---|
| **Form** | Form / Survey | Form | Form | The durable, logical container a tenant builds and publishes. See `docs/data-dictionary.md` §2. |
| **Form version** | Form version (ODK Central: draft vs. deployed version) | *(no direct equivalent — Fillout forms are always live-editable)* | *(no equivalent — legacy's core gap, see ADR-driving discussion in `docs/adr/0002-multi-tenancy-shared-db-rls.md`'s sibling architecture plan §2.3)* | An immutable snapshot of a form at one publish. `docs/data-dictionary.md` §3. |
| **Section** | Group (`begin group`/`end group` in XLSForm) | Page / Section | `indicator_groups` | Organizes fields, optionally as a repeat group. Renamed from legacy's `indicator_groups` — see `docs/data-dictionary.md` §4. |
| **Field** | Question / **Indicator** (see §1 for why we don't use this word) | Field / Question | `indicators` | A single answerable question within a form version. Renamed from legacy's `indicators` — see `docs/data-dictionary.md` §5. |
| **Field type** | Question type (`select_one`, `integer`, `geopoint`, etc. — XLSForm's `type` column) | Field type | `input_types` (lookup table) | The 31-value, 8-category closed vocabulary — `docs/data-dictionary.md`'s `FieldType` enum. |
| **Validation rule** | `constraint` column (XLSForm) | Validation rule | `indicator_validations` | A structured rule or expression attached to a field. Renamed from legacy's `indicator_validations` — see `docs/data-dictionary.md` §6. |
| **Relevant expression / skip logic** | `relevant` column (XLSForm) — the standard term "skip logic" is also common in the wider Kobo/ODK community | Conditional logic / "logic jumps" | Skip logic (informal) | A condition controlling whether a field or section is shown/asked. |
| **Constraint** | `constraint` column (XLSForm) | Validation rule | Validation rule | A condition an answer must satisfy to be accepted (distinct from `relevant`, which controls visibility, not acceptance). |
| **Calculated field** | `calculate` column / "calculate" question type (XLSForm) | Calculated field / formula field | Calculated field (confirmed-good, no-`eval()` pattern carried forward) | A field whose value is derived by the expression engine rather than entered directly. |
| **Repeat group** | `begin repeat`/`end repeat` (XLSForm); "repeat group" | Not a native concept (closest: "matrix"/"repeating section" workarounds) | `indicator_repeat_responses` (separate table) | A section instantiable multiple times per submission — see `docs/data-dictionary.md` §4's `is_repeatable`. |
| **Submission** | Submission (Kobo); **Instance** (raw ODK/XForms term) | Response | `form_submissions` | One respondent's completed (or in-progress) answer set against one specific form version. Renamed from legacy's `form_submissions` — see `docs/data-dictionary.md` §7. |
| **Respondent** | Respondent | Respondent / "submitter" | Implicit (no first-class concept — see `submissions.respondent_user_id`, nullable for guests) | The person who filled out a submission — may be an authenticated user or a guest. |
| **Manual encoding** | *(no equivalent term — Kobo/ODK simply call this "filling the form")* | *(no equivalent term)* | **Encoding** — Philippine public-health/DOH-specific terminology for staff directly keying in data on behalf of a program, as opposed to a respondent self-reporting. | Direct digital data entry against a form's current published version by an authenticated staff user — `docs/PRD.md` Feature #7. Retained as-is because it names a real, distinct operational pattern this product's DOH-adjacent lineage requires, not a generic synonym for "submission." |
| **Guest submission** | Public form link (no account required) | Public form / share link / embed | `/f/{slug}` public route pattern | A submission from an unauthenticated respondent via a signed, form-version-scoped share link — `docs/PRD.md` Feature #3. |
| **Linelist** | *(not a Kobo/ODK/Fillout term)* — a public-health/M&E field term for a tabular line-listing sheet | *(no equivalent)* | Used in legacy's own planning docs (`docs/ocr_submission_feature.md`-equivalent notes) | A single scanned sheet whose rows each represent one respondent's submission, split into multiple individual submissions on ingest — `docs/PRD.md` Feature #2. |
| **Field library** | Library (Kobo's own "Question Library" feature) | *(closest: reusable "form templates," not question-level)* | `field_library` | Reusable single-question blueprints — `docs/data-dictionary.md` §11. |
| **Form template** | Template (Kobo's public template gallery) | Template | `form_templates` | Whole-form blueprints, cloned (not live-referenced) into a new form on use — `docs/data-dictionary.md` §12. |
| **Appearance** | `appearance` column (XLSForm — a UI rendering hint, e.g. `vertical`, `minimal`) | *(no direct equivalent — Fillout's field styling is implicit in its builder UI)* | Not modeled | Carried forward as-is; `docs/data-dictionary.md` §5's `form_fields.appearance`. |
| **Cascading select** | Cascading select (Kobo/XLSForm — hierarchical `select_one` chains) | *(no native equivalent)* | `reference_categories`/`references`/`psgc_level_id` (PSGC-specific hierarchy) | An N-level dependent-choice field type; the legacy PSGC-specific hierarchy is generalized, not carried forward verbatim (`docs/data-dictionary.md` §5's Design Notes). |
| **Piping** | *(no standard term — the idiom is writing `${name}` directly into a `label` cell)* | Piping | `App\Services\Templates\TemplateRenderer` | Substituting a **respondent's own earlier answer** into later respondent-facing text (a question, a hint, a confirmation screen). Distinct from **Template** (the text containing the hole) and from **Expression** (which yields a scalar and drives logic, never text) — see Doc #26. |
| **Template** | *(no equivalent term)* | *(no equivalent term)* | `App\Services\Templates\TemplateParser` (`TEMPLATE_VERSION` `1.0`) | A respondent-facing text value that may contain `${key}` **holes** — i.e. the *medium* piping operates on, in one of the columns Doc #26 §6 lists. Note the collision this row exists to prevent: `form_templates` (whole-form blueprints) is an unrelated concept, and so is a Blade/Vue view template. Doc #26 always means "text with holes". |
| **Hole** | *(no equivalent term)* | *(no equivalent term)* | `TemplateParser::holeKeys()`; resolved by `App\Services\Forms\TemplateValidationGate` + `TemplateScopeResolver`; source eligibility is `App\Enums\PipingEligibility` | One `${key}` reference inside a template — the unit a template parser emits and the publish gate resolves. Named separately from "reference" because an expression's `${key}` and a template's `${key}` share a lexeme but obey different rules (a section key is a legal `count()` operand and an illegal hole — Doc #26 §3.3). |
| **Prefill** | *(no standard term — `default` is the nearest, and it is a client-side hint)* | Hidden-field prefill (its "hidden fields" feature) | Not modeled | Supplying a `hidden` field's value from somewhere other than a respondent, since by definition nobody fills one in — `App\Enums\PrefillSource`, two sources: **fixed** (the authored `default_value`, written server-side) and **url** (a query parameter on the sharing link). Deliberately NOT a synonym for "default": a default is a starting value a respondent may overwrite, while a `fixed` prefill is one no client can influence at all. That distinction is the whole point of the H7 increment, so the two words are kept apart here. |
| **Capability flag** | *(no equivalent — closest is Kobo's per-deployment feature toggles, which are platform-level, not per-form)* | *(no equivalent)* | OCR-compatibility guard (informal) | A computed, per-form boolean describing what that specific form's composition allows (e.g. `ocr_compatible`) — `docs/data-dictionary.md` §2's `forms.capability_flags`, generalizing the legacy OCR guard. |

---

## 3. External Standards & Proper Nouns — Not Renamed

These are names of real external formats, standards, or third-party products this product *interoperates with* or *draws inspiration from*. They are never renamed, reworded, or brought into the Standard Term column above — doing so would misrepresent an external interoperability target as this product's own invented vocabulary:

- **XLSForm** — the actual `.xlsx`-based spreadsheet standard for authoring ODK/Kobo-compatible forms; this product's bidirectional import/export (`docs/PRD.md` Feature #8, Phase 2) targets this format by its real name, on purpose (Doc #16, XLSForm Interop Spec, formalizes the column-by-column mapping).
- **ODK** (Open Data Kit) / **ODK Central** — the open-source project and its server component; referenced for its draft/publish model precedent (architecture plan §2.3) and general ecosystem context, not a component of this product.
- **KoboToolbox** ("Kobo") — the specific humanitarian/research-oriented product built on ODK; referred to by name when a comparison or feature-parity claim is specifically about Kobo's own product decisions (see Doc #3, Competitive/Feature-Parity Matrix), as distinct from the underlying ODK/XLSForm standard.
- **Fillout** (Fillout.com) — the specific modern business-forms SaaS product referenced for UX/feature inspiration; likewise never renamed.
- **PostGIS** — the Postgres geospatial extension backing `geopoint`/`geotrace`/`geoshape` field types (Phase 2); a real piece of infrastructure, not product vocabulary.
- **Likert scale** — a genuine, standard survey-methodology term (a bounded ordinal agreement/frequency scale), not a Kobo/Fillout/legacy-specific coinage — used as-is.

---

## 4. RBAC & Platform Terms (from `docs/multi-tenancy-rbac-design.md`)

| Standard Term | Notes |
|---|---|
| **Tenant** | One customer organization — the top-level isolation and billing unit. Not a Kobo or Fillout term as such; Kobo's closest analogue is an individual KoboToolbox account/organization, Fillout's is a workspace. |
| **Role** | One of the five fixed catalog entries (Owner, Admin, Form Editor, Reviewer, Viewer) — `docs/multi-tenancy-rbac-design.md` §3. |
| **Role family** | A *group* of related roles discussed collectively before this project formalized the individual roles — introduced informally in `docs/architecture/technical-architecture.md` ("Tenant Admin is a role family, not a single role"), now superseded by the concrete catalog in §3 above; retained here only so a reader encountering the older phrase in that doc knows it's not a sixth, undefined role. |
| **Capability class** | A role's tenant-wide standing to *potentially* hold a given permission (e.g., Form Editor "can, in principle, edit forms") as distinct from actually holding access to a *specific* resource instance, which requires a `resource_grants` row covering it — `docs/multi-tenancy-rbac-design.md` §8. |
| **Team** (Spatie Laravel-Permission's own internal term) | The RBAC package's generic name for its multi-tenancy scoping key. This product configures Spatie's `team_foreign_key` to literally be `tenant_id` (`docs/multi-tenancy-rbac-design.md` §4) — "team" is the *package's* internal vocabulary, never this product's own; do not use "team" in product-facing copy or other docs to mean "tenant." |
| **Super-admin** | The platform-wide (not tenant-scoped) operator flag, `users.is_super_admin` — replaces legacy's fragile `id === 1` convention. |
| **Manual/guest/OCR-single/OCR-linelist/offline-sync/API-import** (submission channels) | The six values of `SubmissionSource` — all funneled through the one `SubmissionPipeline`, this product's unifying architectural concept with no equivalent named this way in Kobo or Fillout (both have channel-equivalents but no single named unifying pipeline concept in their own public documentation). |

---

## 5. Deliberately Not Modeled (yet)

Flagged here, not silently omitted, so a future reader doesn't assume an absence is an oversight:

- **"Indicator" in its correct M&E sense** (a computed metric/KPI, §1) — no table or concept in `docs/data-dictionary.md` currently represents this; if a future analytics feature needs it, it should be named `metric` or `kpi`, never `indicator`, to keep the distinction this glossary exists to protect.
- **XLSForm's `note`, `hint`, `label` distinction** — fully carried forward as column names (`form_fields.hint`, `.label`) rather than glossary entries, since they map 1:1 with no rename.
