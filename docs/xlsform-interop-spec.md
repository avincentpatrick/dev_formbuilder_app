# XLSForm Interop Spec

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Implemented (Increment G7 — export G7a + import G7b; see §8 as-built) — the column-by-column import/export mapping the architecture plan calls for (§4 item 16), and the concrete Kobo/ODK/SurveyCTO migration path. Targets **XLSForm** by its real, external name (`docs/domain-glossary.md` §3 — never renamed or treated as this product's own vocabulary).
**Resolves an explicitly deferred question**: `docs/form-versioning-schema-migration.md` §11 flagged "does an import always create a new form, or can it target an existing form's draft?" as deferred to this document. **Answer, §5 below**: it targets an existing form's draft, matching the endpoint shape already fixed in `docs/architecture/technical-architecture.md` §7.1 (`POST /api/v1/forms/{form}/draft/xlsform-import`) — a brand-new form is created first via the ordinary builder flow (even an empty one), then populated by import, exactly mirroring how `docs/form-versioning-schema-migration.md` §6 already models "restore an old version" as a draft-repopulation convenience.

---

## 1. XLSForm Structure Recap

XLSForm is a real external standard (not this product's invention): a `.xlsx` workbook with three sheets — **`survey`** (one row per question/group, columns `type`/`name`/`label`/`hint`/`required`/`relevant`/`constraint`/`constraint_message`/`calculation`/`appearance`/`default`/`choice_filter`/etc.), **`choices`** (one row per option, columns `list_name`/`name`/`label`), and **`settings`** (one row, columns `form_title`/`form_id`/`version`/`default_language`). This document maps this product's data model onto that structure in both directions.

---

## 2. Survey Sheet — Column Mapping

| XLSForm column | Maps to | Direction notes |
|---|---|---|
| `type` | `form_fields.field_type` (via §3's type table) | Also drives `begin group`/`end group` → `form_sections`, `begin repeat`/`end repeat` → `form_sections.is_repeatable` |
| `name` | `form_fields.key` / `form_sections.key` | **Import**: an XLSForm `name` becomes the stable `key` for a newly-imported field/section. **Export**: the existing `key` is written back verbatim — round-tripping an exported-then-reimported form preserves `key` continuity, which matters for `docs/form-versioning-schema-migration.md` §5's cross-version analytics alignment. |
| `label` | `form_fields.label`/`label_translations`, `form_sections.label`/`label_translations` | Multi-language XLSForm columns (`label::English`, `label::Français`, etc.) map to the `{column}_translations` JSONB map — a native fit, since both conventions solve the same problem the same shape of way. **`${…}` inside a label cell (Phase 3, Doc #26):** XLSForm's own piping idiom is a `${name}` reference written directly into `label`, and this product's template grammar uses the same lexeme, so the *token* round-trips unchanged in both directions. What does **not** yet round-trip is validity: an imported label's holes are persisted raw and resolved only at publish (the same treatment `relevant`/`constraint` already get, §8), and this product's template grammar is narrower than XLSForm's — no filters, no nesting, and object-valued geo/media/grid types may not be a piping source, so a Kobo form piping a `geopoint` into a label imports but will not publish until the author edits it. `$${` is this product's escape for a literal `${`; XLSForm has no counterpart, so a label legitimately containing `${` cannot be expressed in an exported `.xlsx` without ambiguity — an accepted, documented export limitation. |
| `hint` | `form_fields.hint`/`hint_translations` | Same multi-language handling as `label`. |
| `required` | `form_fields.is_required` | XLSForm's `required` is boolean (`yes`/blank); this product's `RequiredMode` enum has a third state, `conditional` — **export**: `conditional` is written as `required` with the condition folded into the field's own `relevant` expression (XLSForm has no native tri-state required column) — a controlled, documented lossy simplification, not silent data loss, since the condition itself is preserved, just relocated. **Import**: XLSForm `required = yes`/blank maps directly to `required`/`optional`; `conditional` is never produced by import (XLSForm has no source concept for it). |
| `relevant` | `form_fields.relevant_expression`, `form_sections.relevant_expression` | Same expression grammar both directions — `docs/architecture/technical-architecture.md` §5's expression engine is explicitly modeled on XLSForm's `relevant`/`constraint` semantics, so this is closer to a straight pass-through than a translation. |
| `constraint` | `form_field_validations` (an expression-based row, `expression` column populated, `rule_type` left null per the schema's XOR constraint) | A `constraint` column always imports as an **expression-based** validation row, never decomposed into a structured `rule_type`/`operator`/`rule_value` row, even if the expression happens to match a pattern this product's builder UI could represent structurally (e.g., `. > 0`) — decomposing arbitrary XLSForm expressions into this product's structured-rule taxonomy is a pattern-matching problem with no complete solution, so import always takes the safe, always-correct path (store as expression) rather than a best-effort structural guess that could silently misinterpret an edge case. |
| `constraint_message` | `form_field_validations.error_message`/`error_message_translations` | Direct mapping. |
| `calculation` | `form_fields.default_value` (with `default_value_is_expression = true`) | Maps to the `calculated` `FieldType` case (§3) when the field itself has no other visible input widget; a "hidden calculated field" and a "visible field with a calculation default" are distinguished by `field_type = calculated` vs. an ordinary type with `default_value_is_expression = true`. |
| `appearance` | `form_fields.appearance` | Direct pass-through — already a native column (`docs/data-dictionary.md` §5), not translated. |
| `default` | `form_fields.default_value` (`default_value_is_expression = false`) | Literal default value. |
| `choice_filter` | Cascading-select hierarchy data, folded into `form_fields.config` | Powers `cascading_select` (§3) — the filter expression referencing a parent field's selected value is preserved inside `config`, not decomposed into a separate reference table (`docs/data-dictionary.md` §5's Design Notes already state cascading-select hierarchy data is stored inline in `config`, consistent with this). |
| `repeat_count` | `form_sections.max_instances` (when a static, non-expression integer) | XLSForm allows `repeat_count` to itself be an expression (dynamic repeat count) — this product's `min_instances`/`max_instances` are plain integers (`docs/data-dictionary.md` §4), so an **expression-valued** `repeat_count` is a documented import limitation (§6): imported as an unbounded repeat (`max_instances = NULL`) with a warning, since there is no lossless structural home for a dynamic count today. |
| `begin group` / `end group` | `form_sections` (non-repeating) | — |
| `begin repeat` / `end repeat` | `form_sections` (`is_repeatable = true`) | — |

---

## 3. Field-Type Mapping & Round-Trip Fidelity

| This product's `FieldType` | XLSForm `type` | Round-trip fidelity |
|---|---|---|
| `short_text` | `text` | Full |
| `long_text` | `text` (`appearance: multiline`) | Full |
| `email` | `text` (with a `constraint` regex validating email shape) | Full, but the "this is semantically an email field" intent is carried only via the constraint pattern, not a dedicated XLSForm type — XLSForm itself has no native email type, so this is not a gap this product introduces. |
| `phone` | `text` (`appearance: numbers` optionally) | Full, same caveat as `email`. |
| `url` | `text` | Full. |
| `integer` | `integer` | Full. |
| `decimal` | `decimal` | Full. |
| `calculated` | `calculate` | Full. |
| `date` | `date` | Full. |
| `time` | `time` | Full. |
| `datetime` | `dateTime` | Full. |
| `duration` | *(no native XLSForm type)* | **Lossy export**: exported as `decimal` (seconds) with an `appearance` comment noting the original semantic type; **import**: a plain `decimal`/`integer` field is never inferred as `duration` — reimporting an exported "duration" field produces a `decimal`, not a `duration`, unless the user manually re-marks it in the builder. Documented as a known, one-way limitation, not silently rounded off. |
| `single_select` | `select_one` | Full. |
| `multi_select` | `select_multiple` | Full. |
| `dropdown` | `select_one` (`appearance: minimal`) | Full. |
| `yes_no` | `select_one`, referencing a synthesized two-option `list_name` (`yes`/`no`) | Full on export; **import**: any `select_one` referencing a list with exactly two options is a candidate for `yes_no`, but this product does **not** auto-infer `yes_no` from an arbitrary two-option list on import (a two-option list could just as easily be a real `single_select`, e.g., "Male"/"Female") — import always produces `single_select`, never guesses `yes_no`. This is a deliberate, conservative import rule, not an oversight. |
| `cascading_select` | `select_one` + `choice_filter` | Full. |
| `likert_scale` | `select_one` (numeric-labeled choice list) | Full — the numeric-score storage (`docs/data-dictionary.md` §5) is preserved since XLSForm's `select_one` choice values are themselves stored as strings that this product casts to its own scored representation on import. |
| `likert_matrix` | *(no reliable native equivalent — ODK Collect's own matrix-widget support is inconsistent across client versions)* | **Not supported for import.** **Export**: flattened into one `select_one` question per matrix row (each sharing the same numeric-scale choice list), with a comment row noting the original matrix grouping — a lossy but usable export, since the underlying XLSForm ecosystem itself lacks a universal matrix convention to target. |
| `geopoint` | `geopoint` | Full. |
| `geotrace` | `geotrace` | Full. |
| `geoshape` | `geoshape` | Full. |
| `file_upload` | `file` | Full. |
| `image_capture` | `image` | Full. |
| `audio_capture` | `audio` | Full. |
| `video_capture` | `video` | Full. |
| `signature` | `image` (`appearance: signature`, the real ODK Collect convention for this) | Full. |
| `note` | `note` | Full. |
| `page_break` | *(no native XLSForm type)* | **Lossy export**: represented as a zero-width `begin group`/`end group` boundary with an appearance hint (`field-list`), since XLSForm's own multi-screen behavior is conventionally driven by group boundaries, not an explicit page-break marker — this is the closest faithful analogue, not an invented workaround. **Import**: a plain group boundary is never assumed to mean "page break" (it might be a genuine content grouping) — only re-imports of this product's own prior export (carrying a recognizable marker comment) restore it as `page_break`. |
| `hidden` | `calculate` (no visible widget) when the value is computed; a `text` field with `appearance: hidden` when it holds a literal default | Full for the computed case; the literal-default case depends on the target ODK/Kobo client actually honoring `appearance: hidden` (most modern ODK Collect versions do). |
| `matrix` (generic grid) | *(no native equivalent)* | **Not supported for import**, same reasoning as `likert_matrix`. **Export**: flattened into one question per cell, named `{row_key}_{column_key}`, with a comment row documenting the original grid shape. |

**Honest summary**: 27 of 31 field types round-trip with full fidelity; `duration`, `likert_matrix`, `page_break`, and `matrix` have documented, one-directional export-only or lossy behavior, because the underlying XLSForm/ODK ecosystem itself has no universal native representation for them — this is a limitation of the target format, not a gap this product's mapping introduces, and it is stated plainly here rather than glossed over.

### 3.1 Geospatial value serialization (`geopoint` / `geotrace` / `geoshape`)

The "Full" fidelity claim for the three geo rows above depends on a value-serialization mapping the §3 table's `type`-column mapping does not itself define. That mapping is pinned by **ADR-0006 §D2** and repeated here for the import/export implementation (Increment G7):

- **This product's stored shape** (`submission_answers.answers`, ADR-0006 §D2) is a **GeoJSON geometry envelope** in **`[longitude, latitude, (altitude)]` position order** — `geopoint` → `Point`, `geotrace` → `LineString` (≥ 2 positions), `geoshape` → `Polygon` (one **closed** linear ring, first == last, ≥ 4 positions) — with an optional foreign member `accuracy` (metres) on a `geopoint`.
- **The XLSForm/ODK wire form** is a **space-delimited string in `"latitude longitude altitude accuracy"` order** (latitude first) for a `geopoint`; `geotrace`/`geoshape` are a **`;`-delimited list** of such point strings (a `geoshape` list repeats the first point at the end to close the ring).

| Type | XLSForm/ODK string | Meridian stored envelope |
|---|---|---|
| `geopoint` | `"14.6 121.0 32 4.2"` | `{ "type":"Point", "coordinates":[121.0, 14.6, 32], "accuracy":4.2 }` |
| `geotrace` | `"14.6 121.0 0 0; 14.7 121.1 0 0"` | `{ "type":"LineString", "coordinates":[[121.0,14.6],[121.1,14.7]] }` |
| `geoshape` | `"14.6 121.0 0 0; 14.6 121.1 0 0; 14.7 121.1 0 0; 14.6 121.0 0 0"` | `{ "type":"Polygon", "coordinates":[[[121.0,14.6],[121.1,14.6],[121.1,14.7],[121.0,14.6]]] }` |

**The load-bearing detail — the lat/lon order flips between the two conventions** (ODK is latitude-first; GeoJSON/PostGIS are longitude-first). This is the single most likely source of a silent import/export bug, so the swap is confined to exactly one place — the geo converter in the XLSForm import/export path — plus, cosmetically, the manual-entry UI (which shows a human "Lat, then Lon" and swaps on assembly). Everything internal (JSONB, the PHP + TS engines, the PostGIS `geometry(4326)` projection) is longitude-first. Round-trip fidelity is therefore full **provided the converter honors this order flip and the geoshape ring-closure convention**; the converter must be unit-tested in both directions (import string → envelope → export string) to keep the "Full" claim honest.

---

## 4. Choices & Settings Sheets

- **`choices` sheet**: one row per `(list_name, name, label)` triple, generated from each choice-type field's `config.options` on export; imported rows populate the same `config.options` structure, keyed by `list_name` to support multiple fields sharing one reusable choice list (a common XLSForm authoring pattern this product's import preserves rather than duplicating the list per field).
- **`settings` sheet**: `form_title` ↔ `forms.title`; `form_id` ↔ `forms.public_slug` (falling back to a generated slug on import if absent); `version` ↔ the target `form_versions.version_number` **at export time only** (import never trusts a client-supplied `version` value — the product's own versioning model, `docs/form-versioning-schema-migration.md`, is authoritative and assigns version numbers itself, never accepting one from an imported file); `default_language` ↔ `forms.default_locale`.

---

## 5. Import Target: an Existing Form's Draft (resolving the deferred question)

Per `docs/architecture/technical-architecture.md` §7.1's already-fixed endpoint shape (`POST /api/v1/forms/{form}/draft/xlsform-import`), import always targets a **specific, existing** form's current draft — there is no "import creates a brand-new form" code path. The flow:

1. The user first creates a form via the ordinary builder flow (a lightweight action — a title is enough; the form starts with an empty draft per `docs/form-versioning-schema-migration.md` §3.1).
2. The user imports an `.xlsx` file against that form's draft.
3. **Exactly like "Restore version N"** (`docs/form-versioning-schema-migration.md` §6): if the draft already has diverging content, the user is warned that import **replaces the current draft's content**; on confirmation, the draft's `form_sections`/`form_fields`/`form_field_validations` rows are replaced with the imported structure.
4. The user reviews the now-populated draft like any other draft and explicitly hits **Publish**, going through the ordinary publish mechanics (validation gate, classification, checksum) unchanged.

This reuses the exact same "draft-repopulation convenience, not a special-cased publish path" pattern Doc #8 already established for version restore — a second data-population *source* (an XLSForm file, vs. an old version's own content) feeding the same one mechanism, rather than a third, subtly-different code path.

**Migrating an entire existing Kobo/ODK/SurveyCTO account** (the "concrete migration path" the architecture plan calls for) is the same per-form flow run once per form the customer wants to bring over — there is no bulk/multi-form import mechanism in Phase 2 (when XLSForm import ships, per `docs/PRD.md` Feature #8's phase gating). A bulk-import convenience (accepting a zip of `.xlsx` files, or crawling a Kobo account's API directly) is a plausible Phase 3 enhancement if migration volume ever justifies the extra build, not committed here.

---

## 6. Import Validation & Error Handling

- A malformed workbook (missing `survey` sheet, a `type` value with no mapping in §3) fails the import **before** touching the target form's draft at all — the destructive replace-the-draft step (§5.3) never partially executes; validation is fully upfront.
- An unmapped/unknown `type` value produces a specific, actionable error (`docs/api-specification.md` §2.3's error format: `code: "xlsform_unsupported_field_type"`, `details: { row, type }`), not a silent skip.
- A `repeat_count` expression (§2's noted limitation) produces a **warning**, not a hard failure — the import proceeds with an unbounded repeat and the warning is surfaced in the import-result summary the user reviews before publishing.

---

## 7. Out of Scope / Deferred

- Bulk/multi-form migration tooling (§5) — a Phase 3 candidate, not committed.
- A live, ongoing sync between an external Kobo/ODK deployment and this product (this spec covers one-shot import/export, not continuous synchronization) — no existing doc requests this, and it would be a materially larger, separately-scoped feature.
- The XLSForm `settings` sheet's less common columns (e.g., `style`, `public_key` for encrypted forms) — deferred until a specific customer need surfaces one.

---

## 8. Implementation Notes (as-built)

**Export — Increment G7a (`app/Services/Xlsform/`, merged).** Reads the id-free canonical shape from `SchemaSnapshotSerializer::snapshot()`; the FieldType⇄XLSForm table lives in `XlsformTypeMap`; the §3.1 lat/lon flip is confined to `GeoWireConverter` (unit-tested both directions). Two pragmatic deviations from a strict reading of §2–§4, both **fully round-trippable through this product's own import** and documented for honesty:

- **Cascading select** is exported as **one** `select_one <key>` question whose choices list carries extra `level` / `parent` columns (plus a `#meridian: cascading` marker), rather than the canonical *N*-questions-with-`choice_filter` ODK idiom. This preserves the entire hierarchy losslessly for our own re-import (§4); a foreign ODK client renders it as a flat select. Multi-question `choice_filter` cascades authored elsewhere are still recognised on import.
- **Constraints**: expression-based validation rows pass through verbatim; structured rules are rendered to the `constraint` column only where the frozen grammar (v2.0) can express them (`min_value`/`max_value` → `. >= v` / `. <= v`, `greater_than_field`/`less_than_field` → `. > ${k}` / `. < ${k}`). Other structured rule types (`pattern`, `min_length`/`max_length`, `required_if`, …) have no faithful frozen-grammar constraint and are omitted from the `constraint` column — a documented export limitation, not silent corruption.
- A `#meridian` survey column (ignored by pyxform) carries round-trip breadcrumbs XLSForm cannot natively express (`page_break`, `duration`, `email`/`url`/`phone` intent, the two grids). Only `page_break` and the text-intent markers are honoured on import; `duration`/`matrix`/`likert_matrix` are never reconstructed (§3).

Both HTTP surfaces expose export: tenant-web `GET /forms/{form}/versions/{version}/xlsform` (Inertia builder toolbar) and API-v1 `GET /api/v1/forms/{form}/versions/{version}/xlsform` (the pinned Kobo-migration endpoint), over one `XlsformExporter`.

**Import — Increment G7b (`app/Services/Xlsform/`, merged).** A `.xlsx` is read by `XlsformWorkbookReader`
(openspout, sheet/column-name tolerant) into a `RawWorkbook`, then `XlsformImportParser` (pure, DB-free)
turns it into an `ImportPlan`. Validation is **fully upfront** (§6): a missing `survey` sheet or an unmapped
`type` throws `XlsformImportException` (`xlsform_missing_survey_sheet` / `xlsform_unsupported_field_type` with
`details:{row,type}`) **before** any DB write. `XlsformImporter` then destructively replaces the target
form's current draft exactly like `RestoreService` (lock form → delete validations→fields→sections →
recreate sections→fields→validations with **explicit keys** + a key→id FK remap, `created_by` on fields),
maps `settings`→`forms.*` (`public_slug` generated fallback; `version_number` never trusted, §4), and runs
**no publish gates** — the author reviews the draft and publishes through the unchanged `PublishService`.
Reuses G7a's `XlsformTypeMap::toFieldType`/`isImportable` and `GeoWireConverter::wireToGeoJson`.

As-built import decisions (honest deviations from a naive reading of §2–§4):

- **Cascades** are reconstructed in `CascadeResolver` for **both** shapes: our own export (single `select_one`
  + `#meridian:cascading` marker + `level`/`parent` choice columns) rebuilt in place, **and** foreign
  Kobo/ODK multi-question `choice_filter` chains collapsed into one `cascading_select` (levels keyed by the
  chain questions, child questions removed). Non-linear / ambiguous filter chains degrade to independent
  single-selects with a warning, never an error.
- **`constraint`** always imports as one expression-based validation row (§2), never decomposed. **`required`**
  is `yes`→required / blank→optional, never `conditional` (§5). A `constraint`/`relevant`/`calculation` with
  an unsupported function is persisted raw and rejected only at **publish** by `ExpressionValidationGate`.
- **Type narrowing** (data preserved, conservative type, §3): `select_one yes_no` → `single_select` (never
  auto-inferred `yes_no`); a `select_one` with a numeric choice list → `single_select` (never auto-inferred
  `likert_scale`); `duration` → `decimal`; `matrix`/`likert_matrix` markers are **not** rebuilt (their cells
  import as separate single-selects). Each narrowing emits a warning; `calculate`, `page_break`, `email`/
  `url`/`phone` and `hidden` round-trip via their `#meridian` markers.
- **Key sanitization** rewrites an XLSForm `name` to `^[a-z][a-z0-9_]*$`, de-duplicated across the version's
  sections + fields, with a warning on rewrite. **⚠️ Known gap:** it rewrites the `key` columns only — it does
  **not** rewrite `${…}` references inside the imported expressions that point at them, so importing a form
  whose names need sanitizing (or that collide and get a `_2` suffix) leaves those references dangling, to be
  caught at publish rather than at import. Piping inherits this: from Phase 3 a `label` may also carry `${…}`
  holes (§2), widening the same gap to respondent-facing text. Recorded, not fixed here — Doc #26 §8 assigns it
  to whichever increment next touches the importer. A dynamic (expression) `repeat_count` imports as an unbounded
  repeat (`max_instances = null`) + warning; XLSForm has no `min_instances` column, so it does not round-trip.

Both HTTP surfaces expose import: tenant-web `POST /forms/{form}/draft/xlsform-import` (Inertia builder
confirm-modal + a dismissible warnings banner) and API-v1 `POST /api/v1/forms/{form}/draft/xlsform-import`
(`{data:{…counts}, warnings:[…]}`), gated as a form update (`can:update,form` / `WRITE_FORMS`), over one
`XlsformImporter`. The keystone `XlsformRoundTripTest` proves an exported form re-imports by key and then
**publishes** through both gates.

**Feature gating (follow-up).** `xlsform_export`/`xlsform_import` are paid capabilities
(`pricing-feature-gating-matrix.md`, Starter+), but no feature-flag enforcement layer exists yet and G7a
shipped export ungated; G7b import stays consistent (ungated) — gate both when the entitlement layer lands.
