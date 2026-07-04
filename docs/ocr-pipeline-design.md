# OCR Pipeline Design Doc

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0 — covers both OCR channels named in the architecture plan's Main Features #1 (single-form) and #2 (linelist/batch), the second of which legacy's own planning docs confirmed as "scoped but never fully completed." This document is this product's chance to actually finish that idea, per the plan's own framing.
**Phase**: 3, per `docs/PRD.md`'s roadmap — an optional channel, not core MVP.

---

## 1. Both Channels, One Underlying Model

Both channels produce a **staged, editable extraction** that a human reviews and corrects **before** anything enters the `SubmissionPipeline` — OCR is a pre-processing/staging layer that ultimately hands the pipeline ordinary submission-shaped input (`source = 'ocr_single'` or `'ocr_linelist'`, per `docs/data-dictionary.md` §7's `SubmissionSource` enum), never a separate persistence path that bypasses validation. This is the single most important architectural fact this document establishes: **OCR extraction confidence is a UX/review concern, not a pipeline-bypass mechanism** — every OCR-sourced submission is validated exactly as rigorously as a manually-encoded one once it's confirmed.

| | Single-form OCR (#1) | Linelist/batch OCR (#2) |
|---|---|---|
| Input | One photo/scan of one filled paper form | One scanned tabular sheet — rows = respondents, columns = fields |
| Output before review | One staged, editable submission draft | N staged, editable submission drafts, correlated by `source_batch_id` |
| Endpoint (`docs/api-specification.md`) | `POST /api/v1/forms/{form}/ocr/single` | `POST /api/v1/forms/{form}/ocr/linelist` |
| Extra step vs. single | — | Column-to-field mapping (§4) |

---

## 2. Form Eligibility — `capability_flags.ocr_compatible`

`docs/data-dictionary.md` §2 already names `capability_flags` as "computed, explicit capability flags derived from form composition... generalizing legacy's OCR-compatibility guard." This document defines the concrete rule, recomputed on every publish (`docs/form-versioning-schema-migration.md` §3.2 step 7):

A form version is `ocr_compatible = true` only if **every** field is one of: `short_text`, `long_text`, `integer`, `decimal`, `date`, `time`, `datetime`, `single_select`, `multi_select`, `dropdown`, `yes_no`, `cascading_select`. **Excluded** (any one present makes the whole version `ocr_compatible = false`): every media-capture type (`file_upload`, `image_capture`, `audio_capture`, `video_capture`, `signature` — nothing to meaningfully OCR from a paper form for these), every geographic type (`geopoint`/`geotrace`/`geoshape` — not paper-representable), `likert_matrix`/`matrix` (grid structures a flat scan/linelist row model can't reliably represent — the same structural limitation `docs/xlsform-interop-spec.md` §3 already identified for these two types in a different context), and any repeat group (`is_repeatable = true` sections — OCR extraction targets one flat set of answers per physical form/row, not nested repeat instances).

`allow_ocr_single`/`allow_ocr_linelist` (`docs/data-dictionary.md` §2, both boolean, both default `false`) remain the tenant's own opt-in on top of this computed eligibility — a form can be `ocr_compatible = true` but still have OCR disabled by tenant choice.

---

## 3. Extraction & Confidence Scoring

- The OCR provider (Google Cloud Vision or equivalent, per the architecture plan's external-system choice) returns per-field extracted text plus a provider-native confidence score, normalized into this product's own `0–100` scale.
- **Confidence thresholds** (a concrete, tunable-later default, not a hard architectural constant):
  - **≥ 90**: auto-filled into the staged draft, not visually flagged — high-confidence enough that flagging every field would create review fatigue defeating the point of automation.
  - **70–89**: auto-filled, but visually highlighted (per the design system's warning-semantic token, `docs/ux/design-system-reference.md`) for the reviewer's attention.
  - **< 70**: left blank in the staged draft, flagged as "needs manual entry" rather than auto-filled with a low-confidence guess — a wrong auto-fill silently accepted by a reviewer clicking through quickly is worse than an empty, obviously-incomplete field.
- `attachments.ocr_confidence_avg` (`docs/data-dictionary.md` §10) stores the average across all fields on the source scan — a per-scan summary metric for dashboards/QA, not the authoritative per-field record (which lives transiently in the staged draft until confirmed, then is simply the submission's own answer — no separate confidence-per-answer column exists or is needed, since confidence is a review-time concern, not a permanent property of the confirmed data).

---

## 4. Review-and-Correct UX

- **Single-form**: a side-by-side view — the original scanned image (zoomable) on one side, the editable extracted form on the other, each field's confidence-based highlight (§3) drawing the reviewer's eye to what needs checking first.
- **Linelist**: the same side-by-side pattern, but paginated across the N staged rows the batch produced — a reviewer works through row 1, row 2, ... row N (or filters to only low-confidence rows first), each one a full review-and-correct pass identical in shape to the single-form case.
- **Column-to-field mapping (linelist-specific, one-time per form, reused thereafter)**: the OCR provider's table/document-structure detection (not plain single-block text OCR) extracts the sheet's row/column grid; the *first* linelist upload against a given form requires the uploader to map each detected column to a target field (a simple "column 1 → `age`, column 2 → `village_name`, ..." picker) — this mapping is cached per form and reused automatically on subsequent linelist uploads. **Drift detection**: if a later upload's detected column headers don't match the cached mapping's expected headers (e.g., the paper template changed), the system flags the mismatch and asks for re-confirmation rather than silently applying a stale mapping to structurally different data.
- Confirming a staged draft (single or one row of a linelist) submits it through the ordinary `SubmissionPipeline` with `source` set appropriately — at that point it is an ordinary submission in every respect, including full expression-engine validation (`relevant`/`constraint`) exactly as if it had been manually encoded.

---

## 5. Source-Scan Attachment Correlation

- **Single-form**: the source scan is a normal polymorphic `attachments` row (`kind = 'ocr_source_scan'`), `attachable_type = 'submission'`, `attachable_id` = the one resulting submission's ID — no new pattern needed.
- **Linelist**: one scan produces **many** submissions, and the polymorphic association only ever points at one row — attaching the scan to an arbitrary "first" submission in the batch would be confusing when browsing any of the other rows' own attachment lists. **Resolution**: `attachable_type = 'submission_batch'`, `attachable_id = source_batch_id` (the same UUID `docs/data-dictionary.md` §7 already uses to group a linelist batch's submissions) — there is no literal `submission_batch` table backing this, which is consistent with `docs/data-dictionary.md` §10's own Design Notes on polymorphic associations already being "no hard FK constraint... the standard, accepted trade-off." `attachments.attachable_type` is a plain `varchar`, not a closed PHP enum, so this is a new valid *value* within an already-open-ended column, not a schema change.

---

## 6. Error Handling

- **OCR provider failure/timeout**: retried per the provider call's own transient-failure policy (a small number of immediate retries); a persistent failure surfaces as a job-status error (`GET /api/v1/ocr/jobs/{job}`, per `docs/api-specification.md`'s resource inventory) with actionable guidance ("the OCR service is temporarily unavailable, try again shortly"), never a silent drop of the uploaded scan (the original attachment is retained regardless of extraction outcome).
- **Poor scan quality** (detected via the provider's own low-aggregate-confidence signal, or an outright unreadable image): the staged draft is created with every field below the 70-point threshold (§3) — i.e., this isn't a distinct error path, it degrades gracefully into "everything needs manual entry," which is the correct behavior rather than a hard failure, since the scan may still be legible to a human even where automated extraction did poorly.
- **Linelist row-count/table-detection failure** (the provider can't confidently detect a tabular structure at all): a hard failure, distinct from per-field low confidence — there's no meaningful way to degrade "we couldn't find a table" into a staged draft, so this surfaces as a job error asking the user to verify the scan is actually a tabular sheet (or to use single-form OCR instead, if that's what was actually uploaded to the wrong endpoint).

---

## 7. Performance

`docs/non-functional-requirements.md` §1 already sets the target: "OCR extraction round-trip (single form): < 15s p95, dominated by the third-party OCR provider's own latency... a target for the product's own orchestration overhead, not a commitment on the provider's SLA." Linelist processing time scales with row count and is not held to the same fixed budget — a large linelist batch is processed asynchronously (a job, polled via `GET /api/v1/ocr/jobs/{job}`), with progress reporting rather than a synchronous request/response, consistent with how large exports are already handled (`docs/architecture/technical-architecture.md` §7.3 async/queued export pattern).

---

## 8. Out of Scope / Deferred

- Auto-detection of *which* channel a scan belongs to (single vs. linelist) — the user explicitly chooses the endpoint/mode upfront; there is no automatic classification of an ambiguous upload.
- Confidence-threshold tuning per tenant or per field (today's thresholds, §3, are global defaults) — a plausible Phase 4+ enhancement if real usage data shows the defaults are miscalibrated for specific use cases, not built speculatively now.
- Handwriting-recognition accuracy improvements beyond what the chosen OCR provider natively offers — this document orchestrates around the provider's capability, it does not attempt to improve the provider's own model.
- OCR-specific field-level audit trail beyond what `docs/audit-compliance-logging-spec.md` already covers for ordinary submission review actions.
