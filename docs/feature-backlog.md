# Feature Backlog — Best-Practice Gaps

**Project:** Form-Builder SaaS (`dev_formbuilder_app`, "Meridian")
**Status:** Living backlog — the output of the Phase-0-readiness best-practices review (a multi-agent audit against 2026 competitors: Typeform, Jotform, Fillout, Google Forms, SurveyMonkey, Tally, Formstack, Cognito Forms, Paperform, KoboToolbox, ODK, SurveyCTO). Each item was verified as genuinely absent from (or under-specified in) the 26 committed docs before being listed here.
**How to read:** Priority — **must** (launch table-stakes), **should** (important soon), **nice** (differentiator). Phase = suggested target. This backlog does **not** change the committed roadmap; items graduate into it (into the PRD/Data Dictionary/etc.) by explicit decision, the same way Features #13/#14 did.

---

## 0. Already adopted from this review (not backlog — done)

These seven were judged table-stakes and folded into the spec during the Phase-0-readiness reconciliation:

| Feature | Where it landed |
|---|---|
| Submission & review notifications (in-app bell + email + per-user prefs) | PRD Feature #13; Data Dictionary §22–§23 (`notifications`, `notification_preferences`) |
| Two-factor auth (TOTP + recovery codes, all roles, org policy, step-up) | PRD Feature #14; RBAC §6 (`users` Fortify columns) |
| Sales-tax / VAT on billing (Stripe Tax) | PRD §6 (Phase 1); Pricing Matrix §5; Data Dictionary §1 (`tenants` tax fields) |
| Builder undo/redo | PRD Feature #8 (Phase 1 acceptance criteria) |
| CI security scanning (SCA/SAST/secret) | Testing Strategy §3/§6; Deployment §4 |
| **Post-submission answer editing** (permissioned, audited) — *fast-follow* | RBAC §5 (`submissions.edit.any/.own`); Audit Spec §1 |
| **Share panel** (copy-link + QR + embed + social) — *fast-follow* | PRD §6 fast-follow note |

---

## 1. Field-type catalog / XLSForm-parity (mostly Phase 2, with the choice/expression catalog)

Several of these are **real XLSForm round-trip import failures today** — a Kobo/ODK/SurveyCTO form using them hard-fails on import (`docs/xlsform-interop-spec.md` §6), denting the bidirectional-XLSForm promise.

| Item | Priority | Phase | Note |
|---|---|---|---|
| `or_other` "please specify" write-in on select fields | should | 2 (or 1 — additive to `config` jsonb, low cost) | XLSForm `or_other` modifier silently dropped on import today |
| Slider / range (bounded numeric) field type | should | 2 | XLSForm `range` type; a Kobo form using it fails import |
| Ranking / ordering (drag-to-rank) field type | should | 2 | XLSForm `rank` type; standard survey question with no workaround |
| Rating / star scale field type | should | 2 | Distinct from `likert_scale`'s radio rows; expected on CSAT/feedback forms |
| Rich-text / media content block (headings, lists, inline image/video, dividers) | should | 2 (basic) → 3 (rich media) | For consent language with links, branded intros — beyond plain `note` |
| Image / picture choice (per-option images) | should | 3 | Fillout-style polish + low-literacy accessibility aid |
| Currency / money field type | should | 3 | Value tied to Phase-3 embedded payments/order forms |
| NPS field type | nice | 3 | Composite over a rating field (0–10 + segmentation + −100..+100) |
| Composite address field (+ optional autocomplete) | nice | 3 (autocomplete) / 2 (plain composite) | Structured street/city/region/postal/country, distinct from geopoint |
| Input masks (guided as-you-type formatting) | nice | 2 | Adjacent to the `pattern` rule but distinct behaviour |
| Question & answer-option randomization (seeded/reproducible) | nice | 2 | XLSForm `randomize` column; research/survey bias control |
| Color-picker field type | nice | permanent non-goal unless demanded | Weakest item; niche for both personas |

## 2. Logic, calculations & workflow

| Item | Priority | Phase | Note |
|---|---|---|---|
| Quiz / scoring mode (per-choice weights, score bands, correct-answer marking, pass/fail, result screen) | should | 2 (weights) → 3 (result screens/routing) | Persona A scored health screeners (PHQ-9/GAD-7); Persona B assessments/lead scoring |
| Disqualification / screen-out (early-exit) logic + a `screened_out` submission state | should | 3 (with the visual workflow builder) — **HALF-DISCHARGED by H21, not closed** | Expression engine can hide/validate/compute but cannot terminate a form early. **Now only half-true** (Doc #27 §4.1, §10): relevance *can* empty the remaining step graph, and H21b turns that into a specified terminal screen instead of today's "Step 1 of 0" with a live Submit — so the **mechanism** ships. What remains is the **state**: `screened_out` is a data-model change with knock-ons into `SubmissionStatus`, the capacity guard's non-draft predicate (a screened-out respondent currently burns a `max_responses` slot), the inbox filter, the exporter, the domain-event catalog and the webhook contract. Deferred deliberately, and recorded so a reader does not assume H21 closed the row. |
| Response quotas / close-form-after-N (per-form cap) | should | 3 (reserve a cheap per-form cap flag in Phase 1) | Billing `submissions_count` is deliberately never a data gate — this is a *deliberate* cap |
| Logic testing / preview / logic-map tool | nice | 2–3 — **largely discharged by H21d1** | Authoring-confidence for Persona A's complex forms. H21d1's canvas is read-derived — it parses the existing `relevant_expression` strings and renders the section rail, each node's predicate and the publish-time graph warnings, without writing anything (Doc #27 §8). That *is* a logic map; what it does not yet add is a simulator ("show me the form as a respondent who answered X"), which is the residue this row keeps. |
| Conditional routing of notification emails by answer | should | 3 | Extends Feature #13; core intake-triage for Persona B |

## 3. Respondent experience & completion

| Item | Priority | Phase | Note |
|---|---|---|---|
| Automatic respondent confirmation / autoresponder email (branded, piped answers, optional PDF) | should | 2 | Event-registration & lead-capture templates expect a receipt; PDF aligns with Phase-3 queued PDF |
| Custom redirect on completion (+ optional conditional-by-answer, query passthrough) | should | 2 (unconditional) → 3 (conditional) | Blocks the /thank-you ad-pixel/GA conversion pattern for Persona B funnels |
| Rich / multiple conditional ending screens (CTAs, piped content per outcome) | should | 3 | Builds on Phase-3 answer piping |
| One-question-per-screen "conversational" mode + welcome/cover screen | should | 3 | Cover screen is cheap; full conversational mode is a substantial interaction model |
| Password / access-code protected public forms | should | 2 | Common light-security lever given the sensitive-data positioning |
| Client-side marketing/analytics tracking (GA4, Meta Pixel, GTM, conversion-on-submit) | should | 3 | Server webhooks can't measure views/starts/drop-off; needs CSP allow-listing + consent-gating |
| Kiosk mode (lock to one form, auto-reset, clear PII on timeout) | nice | 3 | Shared field/event-desk devices; niche hardening on the offline story |

## 4. Submission management, review & collaboration

| Item | Priority | Phase | Note |
|---|---|---|---|
| Bulk actions on the inbox (bulk approve/return/delete/status/export-selected) | should | 2 | The shared table renders row-selection checkboxes that lead nowhere today |
| Per-submission tags/labels (orthogonal to approval status) | should | 2–3 | Single linear `SubmissionStatus` + one `remarks` field can't carry multi-valued triage |
| Saved / named views on the inbox (persistent per-user filter + column presets) | should | 3 | Planned saved-views are scoped to the Phase-3 *dashboard*, not the inbox |
| Duplicate / near-duplicate detection (beyond exact offline-replay idempotency) | should | 3 | Catches two records describing the same real-world entity |
| Assignment of individual submissions to specific reviewers (caseload split) | nice | 3–4 | High-volume review only; reference products are weak here (more differentiator than gap) |

## 5. Analytics, reporting & exports

| Item | Priority | Phase | Note |
|---|---|---|---|
| Per-question answer summary / "Results" analytics view | should | 2 | Analytics is form-meta only today; nothing aggregates the actual answers. Analytics-tab shell + chart tokens already exist |
| Geographic map view of submissions (plot/cluster/heatmap geo) | should | 3 | PostGIS geo capture with no visualization is a half-feature for Persona A |
| Researcher/GIS export formats — SPSS (.sav)/Stata (value+variable labels), GeoJSON/KML | should | 3 | Plain CSV loses choice value-labels; a concrete Kobo/ODK migration blocker |
| Cross-tabulation / filter-results-by-answer | should | 3 | Extends the planned answer-index filtering |
| Shareable public / read-only results report or live-dashboard link | should | 3 | Persona A donor reporting is named as a frustration; reuse the guest share-token pattern |
| Scheduled / recurring emailed report digests | nice | 3 | **Enabling infra does NOT exist yet** (corrected 2026-07-21 — this row previously claimed it did): there is no scheduler (`routes/console.php` is stock, no `withSchedule`, no `app/Console/`), no `app/Mail`, and async export is unbuilt. The scheduler + queue substrate is specified by ADR-0007 and built in H2; this item depends on it. |

## 6. Integrations & ecosystem

| Item | Priority | Phase | Note |
|---|---|---|---|
| Native CRM (HubSpot/Salesforce) + email-marketing (Mailchimp) destinations | should | 3 | The committed list (webhook/Zapier/Slack/Sheets/Airtable) has zero lead-destination connectors |
| Export/sync of file-upload attachments to the tenant's own cloud storage (Drive/Dropbox/S3) | nice | 4 | Persona A generates large media volumes for institutional storage |
| Calendar / scheduling / booking field (Calendly/Cal.com/Google Calendar), or generic widget field | nice | 4 | Service-request/appointment intake for Persona B |
| Integration / app marketplace or directory | nice | 4 | Premature for a five-integration launch; per-module toggles partly cover it |

## 7. Security, compliance & enterprise

| Item | Priority | Phase | Note |
|---|---|---|---|
| Auth/session events in the audit trail (login success/failure, logout, reset, token issue/revoke, MFA enrol/challenge) | should | 1–2 | `AuditEvent` covers business mutations only; brute-force/takeover leaves no tenant-visible trace. Laravel already fires these events |
| Breached-password (HIBP) check + admin-configurable password policy | should | 1 | ASVS L1/L2 (NFR §4) mandates a breached-password check; `Password::uncompromised()` is near-free |
| Session lifecycle controls (idle/absolute timeout + step-up re-auth; later: session inventory / "log out everywhere") | should | 1 (timeout+step-up) → 2 (inventory) | Enumerators share field devices; step-up partly delivered with Feature #14 |
| HIPAA / BAA posture — record an explicit scope decision now | nice (decide P0) | 4 (build) | Likely "non-goal until a US covered-entity customer materializes"; cheap one-paragraph decision, not sub-processor contracts today |
| Vulnerability-disclosure contact (`security.txt`) + audit-evidence-from-day-one; SOC 2 Type II / ISO 27001 named as a Phase-4 target | nice (P0/1 for the cheap parts) | 0/1 → 4 | `security.txt` + instrumenting audit evidence early is cheap; formal attestation is a later revenue-justified spend |
| Recurring third-party penetration test | should | 2+ | Companion to the now-adopted CI SCA/SAST |
| Legally-defensible e-signature (signer auth, consent-to-sign, tamper-evident certificate) | nice | 3–4 | Today's signature field stores only an image; a real ESIGN/eIDAS capability is a separate product line — only on demonstrated demand |
| Enterprise identity/network — SCIM auto-provisioning + tenant IP allowlisting | nice | 4 (with the planned SSO/SAML) | SCIM auto-deprovisioning is the standard companion to SAML for large tenants |

## 8. AI & modern 2026 differentiators

No AI appears anywhere in the committed docs; the versioned draft/publish model makes AI-generated content safe (a draft until published).

| Item | Priority | Phase | Note |
|---|---|---|---|
| AI analysis of responses (open-text theme extraction, sentiment, auto-insights) | should | 3 | The most defensible AI gap — thematic coding of qualitative answers is a core, currently-manual Persona-A task; where the product could most out-differentiate Kobo |
| AI form generation from a prompt/description | should | 3 | 2026 competitive-parity for Persona-B fast build; not a must-have |
| AI machine-translation into the existing `{column}_translations` columns (human review before publish) | should | 2 | Sibling `_translations` maps already exist; small marginal build, high value for large multilingual instruments |
| AI-assisted field / choice-list / logic suggestions in the builder | nice | 3 | Lowers the barrier to the "Advanced" expression engine |
| AI/statistical data-quality anomaly & enumerator-fraud detection across submissions | nice | 4 | Credible rigor-tier differentiator toward SurveyCTO territory |

## 9. Commercial, growth & lifecycle

| Item | Priority | Phase | Note |
|---|---|---|---|
| Customer support surface — help center/KB + in-app contact (ticket/chat) with a reply path | should | 1 | Feature #11 feedback is one-way fire-and-forget; a B2B SaaS charging money needs a way to get an answer. A minimal support email is a viable MVP floor |
| In-app product announcements / changelog ("What's new") feeding the notification center | nice | 3 | The bell/center exist as shells; a changelog feed is how you tell tenants a waited-for feature landed |

---

## Notes

- Free-tier / trial mechanics, self-serve signup + email verification, plan upgrade/downgrade/proration, dunning, invoices/receipts, seat-management UX, and account deletion/offboarding export are **partly covered** by the Onboarding (#25), Pricing (#24), and GDPR (#12) docs — audit those three for concrete gaps before Phase-1 billing/onboarding code, rather than treating them as wholly-missing here.
- Items that competitor products treat as table-stakes but this product deliberately declines (self-hosting, SMS/IVR channels, general app-builder scope, real-time co-editing) are **non-goals** in `docs/PRD.md` §7 and are intentionally *not* listed here.
