# Feature Backlog — Best-Practice Gaps

**Project:** Form-Builder SaaS (`dev_formbuilder_app`, "Meridian")
**Status:** Living backlog — the output of the Phase-0-readiness best-practices review (a multi-agent audit against 2026 competitors: Typeform, Jotform, Fillout, Google Forms, SurveyMonkey, Tally, Formstack, Cognito Forms, Paperform, KoboToolbox, ODK, SurveyCTO). Each item was verified as genuinely absent from (or under-specified in) the 26 committed docs before being listed here.
**How to read:** Priority — **must** (launch table-stakes), **should** (important soon), **nice** (differentiator). Phase = suggested target. This backlog does **not** change the committed roadmap; items graduate into it (into the PRD/Data Dictionary/etc.) by explicit decision, the same way Features #13/#14 did.

---

## 0. Already adopted from this review (not backlog — done)

These were judged table-stakes and folded into the spec during the Phase-0-readiness reconciliation
(seven originally; the last row was added by Increment I1, which built the rest of PRD Feature #3 and
found one of its acceptance criteria unbuilt with no row anywhere):

| Feature | Where it landed |
|---|---|
| Submission & review notifications (in-app bell + email + per-user prefs) | PRD Feature #13; Data Dictionary §22–§23 (`notifications`, `notification_preferences`) |
| Two-factor auth (TOTP + recovery codes, all roles, org policy, step-up) | PRD Feature #14; RBAC §6 (`users` Fortify columns) |
| Sales-tax / VAT on billing (Stripe Tax) | PRD §6 (Phase 1); Pricing Matrix §5; Data Dictionary §1 (`tenants` tax fields) |
| Builder undo/redo | PRD Feature #8 (Phase 1 acceptance criteria) |
| CI security scanning (SCA/SAST/secret) | Testing Strategy §3/§6; Deployment §4 |
| ~~**Post-submission answer editing** (permissioned, audited) — *fast-follow*~~ ✅ **DONE — I9c (2026-08-08)**. `SubmissionPolicy::update()` finally consumes `submissions.edit.any/.own`, seeded since Phase 0 with zero code behind them (the third dormant-key occurrence, after `feedback.view` and `tenant.roles.assign`). Editable in the four finalized non-terminal states only; editing an **approved** row demotes it to `under_review` and clears the approval stamps, as one combined audit row. | RBAC §5 (`submissions.edit.any/.own`); Audit Spec §1 |
| **Post-submission MEDIA editing** — the half I9c deliberately cut | Media and signature answers render read-only on the edit surface, and the server enforces it (`SubmissionAnswerEditService::mergeMedia()` takes media from the STORED document, so a hand-rolled PATCH body cannot re-point an attachment). Building it needs four things I9c declined to put in an increment already at L: re-pointing attachment ownership, a policy for the displaced rows (they are currently left owned by the submission rather than deleted, so a reversal restores a working reference), rewriting `attachment_refs` for ADDED media (removal is already handled), and scan-status gating mid-edit. ⚠️ It must NOT reuse `AttachmentReferenceValidator` — that asserts `attachable_type === 'form_field'`, which is false for every already-finalized submission. One live consequence to fix with it: a relevance flip that makes a previously-irrelevant REQUIRED media field relevant currently cannot be satisfied on this surface; the field renders its error and names the escape, but the escape is to undo the branch answer. | I9c as-built |
| ~~**Share panel** (copy-link + QR + embed + social)~~ — **SHIPPED in I1**, narrowed to the remainder below | PRD §6 fast-follow note |
| ~~**Per-form rate limiting / bot-challenge (CAPTCHA)** on the guest runtime~~ ✅ **DONE — I8b (2026-08-08)**. Was an unbuilt PRD Feature #3 acceptance criterion, not a backlog nicety: only the deployment-wide `throttle:guest*` limiters existed, while the criterion asked for per-form and configurable. Built as `forms.bot_challenge` (a self-hosted proof-of-work check, no npm dependency, no third-party credentials, no CSP change) plus `forms.guest_rate_limit_per_minute` (per-IP **within one form** — a form-wide bucket would be a self-DoS lever, since one attacker at one IP could lock the form for every legitimate respondent). Both default off, per the threat model's own "not enabled by default" requirement. **PRD Feature #3 is now closed end-to-end.** | PRD Feature #3 acceptance criteria; threat-model §4 bot-flooding row |

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
| ~~Disqualification / screen-out (early-exit) logic + a `screened_out` submission state~~ | should | ~~3~~ — **MECHANISM shipped H21b, STATE shipped I9a. Only the event catalog remains (Phase 3).** | H21b shipped the mechanism (a specified terminal screen instead of "Step 1 of 0" over a live Submit). **I9a shipped the state**: `SubmissionStatus::ScreenedOut`, derived server-side by `FinalizedStatus` from `StepProjection::isEmpty()` — so it means "was shown no questions", NOT "was disqualified", which is narrower than this row's title implies and is documented as such. The capacity bug this row named is fixed: the guard and its two display twins moved together onto the new `Submission::scopeConsumesCapacity()`, so a screened-out respondent no longer burns a `max_responses` slot. Inbox filter and exporter needed no code (both `cases()`-derived). The webhook contract moved too, without a new event type: `SubmissionCreated::data()` already carried `status`, so `submission.created` now delivers `"status": "screened_out"` — a widened value domain on an existing field, enumerated in `openapi.json` and `docs/api-specification.md`. **Still open, and the only part: the domain-event CATALOG** — there is no `submission.screened_out` `DomainEventType`, so a tenant can *observe* a screen-out but cannot *route* on it. That is the Phase-3 remainder. |
| Response quotas / close-form-after-N (per-form cap) | should | 3 (reserve a cheap per-form cap flag in Phase 1) | Billing `submissions_count` is deliberately never a data gate — this is a *deliberate* cap |
| Logic testing / preview / logic-map tool | nice | 2–3 — **largely discharged — SHIPPED H21d1 (read) + H21d2 (write)** | Authoring-confidence for Persona A's complex forms. **As built:** the builder's centre pane gained a `Structure ⇄ Logic` toggle; the Logic view draws the form as a vertical rail in authored order, each node showing its `relevant_expression` verbatim AND — where the shape can be expressed in full — a plain-English reading beside it, with the §6 graph notices (forward reference, cycle, empty-at-open) attached to the nodes they name and a per-node syntax error shown live. It writes nothing; selecting a node drives the existing config panel. Two residues this row keeps. **(1) A simulator** — "show me the form as a respondent who answered X" — which the rail deliberately is not: it draws AUTHORED order, not the taken path, because there are no answers while an author is editing. **H21d2 then added the WRITE half**, which this row did not ask for but which changes what is left: conditions are now built from rows and groups in the config panel (`Show this question only when…`), serialized to canonical expression text, and anything the builder cannot represent — arithmetic, `if()`, a negated chain — keeps the author's own text in an editable box that nothing rewrites. **(2) Skip rules** (`skip_if`/`skip_with`) are not rail objects; they are conditional-requiredness edited in `ValidationEditor.vue`, and although they DO participate in the cycle notice the rail shows, the rail does not draw them. |
| Conditional routing of notification emails by answer | should | 3 | Extends Feature #13; core intake-triage for Persona B |

## 3. Respondent experience & completion

| Item | Priority | Phase | Note |
|---|---|---|---|
| Automatic respondent confirmation / autoresponder email (branded, piped answers, optional PDF) | should | 2 | Event-registration & lead-capture templates expect a receipt; PDF aligns with Phase-3 queued PDF |
| Custom redirect on completion (+ optional conditional-by-answer, query passthrough) | should | 2 (unconditional) → 3 (conditional) | Blocks the /thank-you ad-pixel/GA conversion pattern for Persona B funnels |
| Rich / multiple conditional ending screens (CTAs, piped content per outcome) | should | 3 | Builds on Phase-3 answer piping |
| One-question-per-screen "conversational" mode + welcome/cover screen | should | 3 | Cover screen is cheap; full conversational mode is a substantial interaction model |
| Password / access-code protected public forms | should | 2 | Common light-security lever given the sensitive-data positioning |
| Client-side marketing/analytics tracking (GA4, Meta Pixel, GTM, conversion-on-submit) | should | 3 | Server webhooks can't measure views/starts/drop-off; needs CSP allow-listing + consent-gating. Note there is no **first-party** measurement of those three either, and for related reasons: ADR-0011 §D1 defers the form-engagement event stream to Phase 4 rather than opening a fourth unauthenticated ingress class in Phase 3 |
| Kiosk mode (lock to one form, auto-reset, clear PII on timeout) | nice | 3 | Shared field/event-desk devices; niche hardening on the offline story |
| **Builder — auto-switch to the Settings pane when a field is selected at compact widths** | should | 3 | The sharpest cost of JR5's pane switcher (exceptions-log #13 §3): below 60em of the builder's container, tapping a field in the canvas highlights the row while the config panel is off-screen, so the author has to know to tap *Settings*. `watch(selection, …)` fixes it and is harmless at wide widths, but needs **`{ flush: 'post' }` plus a mounted flag** — the page auto-selects the first field in `onMounted`, so a naive watcher fires on load and overrides the default pane. Deferred out of JR5 because pane state driven by store events reorders what eight e2e sequences see, in a suite that cannot run on the dev host |
| ~~**Builder — the save indicator announces "All changes saved" after a save FAILS**~~ ✅ **DONE — J7 (2026-08-18)**. Fixed in the STORE, not the template: `useBuilderStore` now carries an explicit `SaveState` and the toolbar reads it, so success is never inferred from an idle queue. ⚠️ **AND BUILDING IT FOUND THE SAME LIE TWICE MORE, NEITHER OF THEM IN THIS ROW.** (1) Two `saveError` clears sat on the SUCCESS path of `persistField`/`persistSection`, so a later write succeeding erased an EARLIER row's real failure — an indicator-only fix would have shipped it. The error now clears only on positive evidence, a burst where everything attempted landed. (2) A **409 conflict** also read as saved: it set `conflict` without setting `saveError`, so the burst drained clean while the ConflictDialog said otherwise. (3) And `ConfigPanel`'s `role="alert"` lived INSIDE its `v-else`, so it rendered only when something was selected — and selection goes null on exactly the failure-adjacent paths, meaning a failed write with nothing selected was reported **nowhere in the client**. Hoisted to a sibling of both branches. | 
| **Builder — express the pane columns in `em` so they track the font-size axis** | nice | 3 | JR5 left `260px`/`340px` as literals, so at `extra_large` the 60em threshold is conservative relative to the columns it is derived from (1200 − 602 = 598px of canvas where 358 would do — a safe direction, but not the coherent one). `16.25em minmax(0,1fr) 21.25em` makes `16.25 + 21.25 + 22.5 = 60em` exact at every scale and un-cramps the palette at `extra_large`; out of JR5's approved scope, which held the wide layout unchanged |
| **Builder — the save verdict is BATCH-scoped, not per-row** | nice | 3 | Filed 2026-08-18 from J7, and **deliberately not fixed there**. The verdict covers a *burst* (the run of work from the queue going non-empty to draining), so a LATER, unrelated burst that succeeds clears an EARLIER burst's failure even though that row's content still differs from the server. Exactness would mean threading a target identity through all twelve `guard()` call sites and holding a per-`uid` dirty set — a materially larger change to every action, for a case where the author **has already been shown the alert**. J7's own test suite pins the batch behaviour deliberately (`save-state.test.ts`, the recovery case), so whoever takes this changes an assertion on purpose rather than discovering it. |
| **Builder — a repeated IDENTICAL failure does not re-announce** | nice | 3 | Filed 2026-08-18 from J7. `save.error` set to the same string is not a reactive change, so `ConfigPanel`'s `v-if` never unmounts and its `role="alert"` never re-fires: retry the same broken write twice and a screen-reader user hears the problem once. Pre-existing, unchanged by J7. The fix is a per-failure `:key` on the alert, which is a `ConfigPanel` change with its own test. |
| **Builder — the toolbar still says "All changes saved" on a READ-ONLY form** | nice | 3 | Filed 2026-08-18 from J7. With `props.draft === null` the panes are replaced by `MdsEmptyState` and nothing can ever be written, so the string is meaningless rather than false. A `v-if="!readOnly"` on the span is the fix, but it changes the toolbar in a state `builder-axe` does not currently drive. |
| **The builder's failed save indicator is not TONED** | nice | 3 | Filed 2026-08-18 from J7, which deliberately shipped zero CSS. `.builder__save` keeps `--mds-color-text-secondary` in every state, so "Not saved" reads with the same weight as "All changes saved". A danger tone would be a brand-new `color-contrast` surface across two themes × three type scales × three viewports, gated by suites that cannot run on the dev host — which is a real cost to schedule, not a reason never to do it. The failure already carries colour in `ConfigPanel`'s `.config__error`, which those scans have covered since D4b. |
| ~~**TopNav — the theme-toggle labels overlap the Feedback link at 834px with `extra_large`**~~ ✅ **DONE — J8 (2026-08-18), AND THE TITLE IS WRONG IN A WAY WORTH KEEPING: IT WAS NEVER AN `extra_large` DEFECT.** Measured on the running dashboard before the fix, the labels spilled their fieldset at **every** type scale — **8.5px at 834px on the DEFAULT scale**, 4.5px of it across the Feedback trigger, rising to 40.1px at `large` and 66.8px at `extra_large`, and to 139–193px by 601px. 834px is one of the three e2e viewport projects, so this was on screen in every tablet run the suite has ever made. The mechanism: `MdsSegmentedControl` is `inline-flex` with no wrap and no overflow handling, and this instance is the **only** child of `.topnav__right` declaring `min-width: 0`, so it absorbed the entire squeeze while its content refused to reflow — with `.app-shell { overflow-x: clip }` swallowing the evidence. ⚠️ **THE ROW'S OTHER SUGGESTION, "or wrap", IS FORECLOSED**: `.topnav` is a fixed 64px with `flex-shrink: 0`, so wrapping trades a horizontal defect for a vertical one. ⚠️ **AND THE FIX IS SHELL-ONLY ON THE EVIDENCE, WHICH CONTRADICTS THIS ROW'S OWN FRAMING**: the compounding note below claimed the component was at fault for all its consumers, but measured at every width and scale, `forms/Index`'s Layout switcher and `analytics`'s Dashboard-view switcher never spill — only the topnav's instance is a flex item in a `space-between` bar competing against five siblings. Shipped as three measured states: labels where they fit, glyphs where they do not (visually hidden, **never** removed — `MdsIcon` is `aria-hidden`, so the span is each radio's only accessible name), and not rendered below the width where even glyphs stop fitting, because collapsing alone was **not** sufficient and the only remaining source of width was global search, which is a standing product principle. |
| **Two server-paginated tables carry `sortable: true`, so the header announces an ordering that is false** | should | 3 | ⚠️ **FILED 2026-08-18 BY J7, BUT FOUND BY I2 — the second finding that lived in `PROGRESS.md` prose alone.** `MdsDataTable` sorts only the rows it was handed (`DataTable.vue`, a local `computed`), so on a server-paginated ledger a sort header reorders 25 of 4,000 and sets `aria-sort` over a dataset ordering that does not exist. Live at `resources/js/Pages/webhooks/Show.vue` (`created_at`) and `resources/js/Pages/submissions/Inbox.vue` (`form_title`, `submitted_at`); both render `MdsPagination`. I2 chose correctly for the audit ledger and said so in `audit/Index.vue`. ✅ **THE FORK IS RESOLVED — USER DECISION 2026-08-18: DROP `sortable`.** It matches the precedent I2 already argued in `audit/Index.vue:14-19` (the server orders newest-first, fixed, and the page says so in one line of prose), it removes a false accessibility claim rather than building around it, and it costs no new query params and no new e2e locators. **Server-side sorting is explicitly NOT the chosen path** — do not re-open it. ⚠️ **AND THE SCOPE IS VERIFIED EXACTLY RIGHT, WHICH IS RARE ENOUGH HERE TO STATE:** measured 2026-08-18 by J8, those two pages are the ONLY `sortable` tables that paginate. The other five — `admin/Tenants`, `admin/Users`, `forms/Index`, `members/Index`, `webhooks/Index` — are handed their complete set and render no `MdsPagination` at all, so client-side sort is honest there and **must not be swept**. Still unbuilt: this is now a mechanical row awaiting an increment. |
| **The codebase holds TWO contradictory conventions for an unseeded plan catalog** | should | 3 | ⚠️ **REPLACES THE `DestinationCatalog::visibleTo()` ROW BELOW, WHICH WAS MISFRAMED — verified against the code 2026-08-18 by J7 and re-verified by J8.** `RequireFeature` fails **open** (`RequireFeature.php:33` — `currentPlan() !== null && ! feature($key)`) while `EntitlementService::feature()` fails **closed** (`:122` — `currentPlan()?->featureEnabled($key) ?? false`), and both readings are deliberately test-pinned WITH PROSE: `CrumbTrailGateTest` and `DashboardKpisTest` assert fail-OPEN by name, `SearchDestinationArmTest` and `Sidebar.test.ts` assert fail-CLOSED by name. `FeatureAdmission::admits()` exists as the fail-open mirror and has exactly two callers (`DashboardController.php:81`, `CrumbTrail.php:263`). So this is one decision about what a surface means when there is no catalog to gate against — **not** a sign flip in one file. Note the divergence is only reachable with an UNSEEDED `plans` table (dev/test), since `resolvePlan()` falls back to Free in production. ✅ **THE DECISION IS TAKEN — USER RULING 2026-08-18: FAIL OPEN.** "No catalog" means "nothing to gate against", so the surface admits; this is what the routes already do, so nav and search stop hiding destinations the request would have been served — J4b2's stranded-reader defect. Adopting it means widening `FeatureAdmission::admits()` from its two callers to the search/nav surfaces and re-pinning the two tests that assert fail-CLOSED by name. ⛔ **NOT BUILT BY J8, AND DELIBERATELY SO: it lands in `app/Support/Search/`, which is in NEITHER lane's column under Standing Rule 7(b) and needs its own committed claim.** It is now unambiguous and ready for whichever increment claims it. |
| Share panel — the remainder after I1: **branded social links** (X/LinkedIn/Facebook intent URLs) | nice | 3 | I1 shipped `navigator.share` + `mailto:` instead — native share is the real mobile path (it reaches WhatsApp/SMS, which no fixed set of buttons can) and it keeps third-party brand marks out of the builder and three vendor glyphs out of the hand-authored `icons.ts` |
| Share panel — **script-snippet embed** with `postMessage` auto-resize | should | 3 | I1 ships the `<iframe>` only, which is exactly what the parity matrix committed ("iframe at MVP; richer embed a Phase 3 candidate"). Auto-resize needs a message protocol on both sides — a listener in the guest runtime and a loader script the platform serves and versions forever |
| **Per-form embed-origin allowlist** — narrow the guest runtime's `frame-ancestors *` to named hosts | should | 3 | The honest narrowing of the clickjacking risk I1 explicitly ACCEPTED (threat-model §4). Not achievable by editing `PublicRuntimeSecurityHeaders`: the allowed set is per-form data, so it needs a column, a UI and a per-request policy build |

## 4. Submission management, review & collaboration

| Item | Priority | Phase | Note |
|---|---|---|---|
| Bulk actions on the inbox (bulk approve/return/delete/status/export-selected) | should | 2 | The shared table renders row-selection checkboxes that lead nowhere today |
| Per-submission tags/labels (orthogonal to approval status) | should | 2–3 | Single linear `SubmissionStatus` + one `remarks` field can't carry multi-valued triage |
| Saved / named views on the inbox (persistent per-user filter + column presets) | should | 3 | Planned saved-views are scoped to the Phase-3 *dashboard*, not the inbox. ADR-0011 §D8 pins that table as strict-RLS with a `user_id` (never the `belongs_to_user` isolation variant, which carries no tenant predicate) and dashboard-scoped; reusing it for the inbox is a future decision, not an implied one |
| Duplicate / near-duplicate detection (beyond exact offline-replay idempotency) | should | 3 | Catches two records describing the same real-world entity |
| Assignment of individual submissions to specific reviewers (caseload split) | nice | 3–4 | High-volume review only; reference products are weak here (more differentiator than gap) |
| Notification retention / pruning sweep | should | 3–4 | Filed 2026-08-06 from I4. `notifications` has **no retention policy and no sweeper**: `app/Jobs/Maintenance/` holds seven (`PruneFailedJobs`, `ReapExpiredDrafts`, `RefreshConnectorTokens`, `RollUpUsageCounters`, `SweepScheduledForms`, `SweepWebhookRetries`, `VerifyCustomDomains`) and none touches this table, while `submission_received` fans out to owner + admin + every granted form editor on **every** submission of **every** public form. Two consequences to size the job against: the bell's unread `count(*)` runs twice a minute per open tab (index-only on `notifications_tenant_user_read_idx`, so cheap but unbounded), and "Mark all as read" is a single unbounded `UPDATE` — at a few hundred thousand rows that is a synchronous web request holding row locks. Neither is a Phase-1 problem at seeded scale; both are recorded here rather than discovered. A prune of read rows older than N days, plus a badge cap, is the likely shape |
| ~~Respondents may always read their own submission~~ ✅ **DONE — I8a (2026-08-07)** | should | 3 | Filed 2026-08-06 from I4, which observed that `SubmissionPolicy::view()` was `submissions.view && (org-wide \|\| collaborates on the form)` with **no respondent clause**, while `submission_approved`/`submission_returned` are addressed to `respondent_user_id` and `NotificationCopy` tells them to "open it to see what they asked for" — so a form editor whose grant was later revoked kept a notification pointing at a bare 403 outside the Inertia shell. I4 made that honest (`NotificationPresenter` runs the real gate and ships `url: null`); **I8a made it work.** This row said the widening "belongs with I9's review/edit vertical"; the user decided otherwise on 2026-08-07, and the split is cleaner than the original filing suggested: **reading back what you yourself submitted is not a privilege, while EDITING it after review is a genuinely different question** and stays with I9 (`submissions.edit.any/.own`, seeded since Phase 0 with no code behind them). As built: an `isRespondent()` arm on `view()` **only** — never `review()` or `export()` — mirrored in `Submission::scopeVisibleTo()` so the single-row check and the list query still express one rule, with the OR **parenthesised** so it cannot associate against the inbox's status/date/form filters. `NotificationPresenter` needed no change at all: it runs the Gate, so its previously-dead links simply started resolving |

## 5. Analytics, reporting & exports

| Item | Priority | Phase | Note |
|---|---|---|---|
| Per-question answer summary / "Results" analytics view | should | 3 | Analytics is form-meta only today; nothing aggregates the actual answers. **Enabling surface does NOT exist yet** (corrected 2026-08-03 — this row previously claimed "Analytics-tab shell + chart tokens already exist"; both are false): there is no analytics tab, no analytics route, no chart component, no `--mds-chart-*` token, and no charting dependency in either `package.json`. ADR-0011 (H1e) decides the charting approach — in-house design-system SVG primitives — and H24b builds them. **H24a (2026-08-03) shipped the READ side**: `GET /api/v1/analytics/questions` returns every question in scope marked reportable or refused with a machine-readable reason, and `questions/{key}` returns the aggregate, the coverage numbers, or §D4's version-naming refusal. What remains for a "Results" view is the UI. ADR-0011 §D3 also bounds this item: the typed answer index projects only fields an author flagged `is_queryable` (default off) and only top-level scalars, and it is never backfilled, so a "Results" view is opt-in and prospective-only until a backfill job ships |
| Geographic map view of submissions (plot/cluster/heatmap geo) | should | 3 | PostGIS geo capture with no visualization is a half-feature for Persona A |
| Researcher/GIS export formats — SPSS (.sav)/Stata (value+variable labels), GeoJSON/KML | should | 3 | Plain CSV loses choice value-labels; a concrete Kobo/ODK migration blocker |
| Cross-tabulation / filter-results-by-answer | should | 3 | Extends the planned answer-index filtering |
| Shareable public / read-only results report or live-dashboard link | should | 3 | Persona A donor reporting is named as a frustration; reuse the guest share-token pattern |
| Scheduled / recurring emailed report digests | nice | 3 | **Enabling infra does NOT exist yet** (corrected 2026-07-21 — this row previously claimed it did): there is no scheduler (`routes/console.php` is stock, no `withSchedule`, no `app/Console/`), no `app/Mail`, and async export is unbuilt. The scheduler + queue substrate is specified by ADR-0007 and built in H2; this item depends on it. |
| Decouple `openapi.json` from the PHP build's tz database | should | 3 | Filed 2026-08-03 from H24a (PR #86). `AnalyticsReportRequest:52`'s `Rule::in(DateTimeZone::listIdentifiers())` makes Scramble materialize a **419-entry IANA enum × 4 endpoints** — ~1,676 lines, ~20% of the whole spec — and `contract-tests` byte-diffs the committed file against a fresh export. The two PHPs are different builds: local is `php:8.4-fpm-alpine` (8.4.23, tzdata 2026.1), CI is `shivammathur/setup-php@v2` with a **floating** `php-version: "8.4"`. They matched at H24a, so the job is green — but any PHP patch release bumping timelib turns `contract-tests` red on a ~1,676-line diff nobody authored, on whatever PR happens to be open. Fix is ~5 lines: replace the rule with a closure so the enum cannot be statically enumerated (runtime behaviour identical — still 422, and `AnalyticsApiTest:152` asserts status, not message), then regenerate the spec. |

## 6. Integrations & ecosystem

| Item | Priority | Phase | Note |
|---|---|---|---|
| Native CRM (HubSpot/Salesforce) + email-marketing (Mailchimp) destinations | should | 3 | The committed list (webhook/Zapier/Slack/Sheets/Airtable) has zero lead-destination connectors |
| Export/sync of file-upload attachments to the tenant's own cloud storage (Drive/Dropbox/S3) | nice | 4 | Persona A generates large media volumes for institutional storage |
| Calendar / scheduling / booking field (Calendly/Cal.com/Google Calendar), or generic widget field | nice | 4 | Service-request/appointment intake for Persona B |
| Integration / app marketplace or directory | nice | 4 | Premature for a five-integration launch; per-module toggles partly cover it |

## 7. Security, compliance & enterprise

| ~~**`DatabaseWorkerPipelineTest` "records a failed job" is load-dependent and can fail a merge-blocking gate**~~ ✅ **DONE — I10b (2026-08-08)**. Diagnosed to the line in laravel/framework 13.18.1 and fixed in the FIXTURE'S MECHANISM, not by loosening the assertion the row was right to protect. `Worker::process()` calls `markJobAsFailedIfAlreadyExceedsMaxAttempts()` at Worker.php:548 **before** `fire()`, and that method returns early only while `Carbon::now()->getTimestamp() <= $retryUntil` (Worker.php:643). With `retryWindowHours() = 0` stamping `retryUntil` as the DISPATCH second, the green path additionally needed `retryUntil <= now()` to hold at Worker.php:669 — so the test rested on two `<=` comparisons being simultaneously true, i.e. on **exact equality across three separate wall-clock reads**. `retryWindowHours()` is gone; the fixture now sets `$maxExceptions = 1`, so the pre-flight early-returns for the full six-hour production window, `handleForTenant()` GENUINELY throws (which is what §D4/§D12 need), and `markJobAsFailedIfWillExceedMaxExceptions()` (Worker.php:684) fails the job with the fixture's OWN exception on the first throw. Two dependencies verified rather than assumed: `WorkCommand::handle()` binds the cache (WorkCommand.php:148) and `phpunit.xml:25` sets `CACHE_STORE=array`. It is also the production-representative path — grepping the tree, this fixture was the ONLY `retryWindowHours()` override that ever existed, so real jobs fail through `$maxExceptions`, never through an expired window. **The row understated the damage: the flake was also hollowing out a neighbour.** `WorkerContextHygieneTest`'s "leaves the worker clean after a job that THREW" — ADR-0007 §D4's mutant-killer — asserts only that the tenant statics are null afterwards; on the pre-flight path NOTHING throws, no tenant context is ever established, and it passed for entirely the wrong reason with no way to go red and say so. It now asserts, before the real assertion, that the `failed_jobs` row carries the FIXTURE's own exception — the row COUNT would not have done, because the pre-flight path raises `JobFailed` and logs a row just as the throw path does, so only the exception's content distinguishes “handleForTenant() threw” from “the worker failed the job without running it” (caught by this increment's own adversarial review, which found the first version of the precondition satisfied by the vacuous case it was added to exclude). The retry window is also now pinned in `phpunit.xml` (`QUEUE_FAIRNESS_RETRY_WINDOW_HOURS`, forced), because `config/queue-fairness.php` reads it from env with no floor — without the pin, an ops-side `=0` would restore the flake suite-wide with no code change and nothing to catch it. Three mutations confirmed to redden, including a deterministic reproduction of the historical flake (re-add `retryWindowHours() = 0`, `travel(2)->seconds()`). ⚠️ The `$maxExceptions` override MASKS one mutation — `TenantAwareJob::$maxExceptions`'s default moving off 3 — so `DatabaseWorkerPipelineTest`'s `expect($payload['maxExceptions'])->toBe(3)` on `ProbeTenantJob` is now the only guard for it and is annotated as such. **Not closed here, and worth its own row: the production cousin.** `TenantAwareJob.php`'s `retryUntil` is frozen at dispatch and does not slide, so a job born into a backlog deeper than `queue-fairness.retry_window_hours` (6) fails on its FIRST pop having never run — same mechanism, real traffic, and no test covers it. Original filing follows. Filed 2026-08-08 from I8c, which hit it on a tree whose only changes since the previous green Pest run were a Playwright spec and a markdown file — so it is demonstrably not the increment's. **Observed:** the same commit passed the Pest JOB twice (5m39s, 5m07s) and failed once (5m12s job / 277s reported by Pest itself) with `failed_jobs.exception` containing `MaxAttemptsExceededException: … has been attempted too many times` instead of the fixture's own `Deliberate failure from ExplodingTenantJob`. The fixture sets `retryWindowHours() = 0` so `retryUntil` is stamped into the payload at dispatch and compared against wall-clock in `Worker::markJobAsFailedIfAlreadyExceededMaxAttempts()` — which runs BEFORE `handle()`. When the runner is loaded enough that dispatch and pickup fall either side of that comparison, the worker fails the job without ever executing it, and the assertion reads the framework's exception rather than the job's. **Not diagnosed further and deliberately not "fixed" by loosening the assertion** — the assertion is the point (§D4/§D12 distinguish JobAttempted from JobProcessed, and a test that accepts either exception would stop proving that). The honest options are a non-zero retry window with an explicit `travel()` past it, or asserting on `failed_jobs` shape rather than the exception string; both are queue-substrate decisions that want their own increment. Until then a re-run clears it — but **a flaky merge gate teaches people to re-run on red, which is exactly how a real failure gets waved through** |
| ~~**`MdsModal` should mark the rest of the page `inert` while open**~~ ✅ **DONE — I10a (2026-08-08)**. Fixed in the component rather than worked around: `MdsModal` now marks every non-ancestor sibling of the dialog `inert` while it is open (`packages/design-system/src/components/Modal/inert-stack.ts`), so the background leaves both focus order and the accessibility tree. Three things the row did not anticipate, each of which had to be solved for the fix to be real: (1) the walk must be an **ancestor-sibling** walk, never “every `<body>` child except ours” — with `teleport: false` (every Storybook story, four Vitest specs) the naive rule inerts `#storybook-root` itself, and `checkA11y(page, '#storybook-root')` would then scan an EMPTY accessibility tree and pass silently, which is worse than a red; (2) `inert` must be released **before** focus returns to the opener, because `focus()` inside an inert subtree is a silent no-op — the reverse order breaks return-focus in all 29 modal consumers with nothing in the console; (3) `MdsToastHost` teleports to `<body>` DELIBERATELY so a toast is never trapped under a modal, and inerting it would have made every toast raised during a dialog unannounceable and unclickable — it opts out with `data-mds-inert-exempt`. Two latent bugs fell out on the way: the watcher had no `immediate: true`, so a modal MOUNTED already open (`forms/Index.vue`'s AssignScopeModal, `scopes/Index.vue`'s move confirm, and every Storybook story) got no scroll lock, no captured opener and no initial focus; and `document.body.style.overflow` was per-instance, so with two stacked modals the first close unlocked the page under the second — both now driven by one module-scope stack. The proof is `tests/e2e/builder-axe.spec.ts`: its two share-panel scans are **whole-page again** and `scan()`'s `within` parameter is deleted, so if the walk regresses the builder's config panel comes back through the scrim and the merge-blocking gate goes red on real product markup. Nine Vitest cases, seven mutation-checked. Original filing follows — note its “scoped the two share-panel scans to `.mds-modal`” is itself inaccurate: `.mds-modal` is not a class that exists (the element carries `.mds-modal__panel` inside `.mds-modal__backdrop`) and the shipped selector was `[role="dialog"]`, as `builder-axe.spec.ts`'s own docblock records at length. Filed 2026-08-08 from I8c. The component renders a semi-transparent scrim but leaves the background reachable: **focus order still walks it, and a screen reader still announces it**, which is the behaviour `<dialog>`'s native modal mode exists to prevent (WCAG 2.4.3). Surfaced by a merge-blocking axe failure rather than by review — when I8b's "Spam protection" block made the Share modal taller, the 375px layout shifted and axe began BLENDING the scrim into the config panel behind it, reporting a 1.82:1 contrast failure on a segmented-control label nobody can see or reach while the dialog is open. I8c scoped the two share-panel scans to `.mds-modal` (the G9b `.builder__pane--left` precedent) and filed this rather than fixing it inline: `inert` changes every modal in the product — focus restoration, the backdrop click-out, and the four specs that drive modals — so it belongs in a design-system increment with its own Storybook and axe pass, not in a security PR |
| Item | Priority | Phase | Note |
|---|---|---|---|
| **A queued job born into a >6h backlog fails on its first pop, having never run** | should | 1–2 | Filed 2026-08-08 from I10b, which fixed the test-only twin of this. `TenantAwareJob::retryUntil()` returns `now()->addHours(config('queue-fairness.retry_window_hours'))` and Laravel stamps that into the job payload at DISPATCH time, where it never slides. `Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts()` (Worker.php:637) compares it to wall-clock BEFORE `fire()`, so any job that sits in the queue longer than the window — a deploy freeze, a worker outage, a fan-out burst, a paused consumer — is failed with `MaxAttemptsExceededException` **without `handle()` ever being called**, and lands in `failed_jobs` looking like a code fault. `config/queue-fairness.php` and `TenantAwareJob`'s own docblock both already note the freeze; what is missing is any test, any alarm, and any decision about whether the window should be evaluated at pop time instead. Wants a deliberate call (sliding window vs. accepting the cap vs. alerting on the exception class), which is why it is a row rather than a fast-follow |
| **`builder-axe`'s two share-panel scans went flaky after I10a widened them** | should | 1 | Filed 2026-08-09 from I10d's CI. I10a removed `scan()`'s `within` parameter and returned both share-panel cases to whole-page scanning, which is the merge-blocking PROOF that `MdsModal`'s new `inert` walk works on real markup — and they were green on I10a's own run. On the two subsequent runs (I10d's branch, which touches nothing in the builder) they failed once and passed on retry, in both runs, at different viewports each time: `Builder — share panel, live link (dark)` and `Builder — share panel, not yet shared (dark)`. Playwright reports them as FLAKY rather than failed, so the job's verdict is unaffected today — but a merge-blocking gate that recovers on retry is exactly the shape `DatabaseWorkerPipelineTest` had before I10b, and that one taught people to re-run on red. Likely a timing interaction between the dialog's open transition and the whole-page axe pass now that more of the page is evaluated; the honest first step is to capture the failing violation from the artifact rather than to guess |
| **`App.vue`'s conflict-discard still uses `window.confirm`** | should | 1 | Filed 2026-08-09 from I10d. That increment gave the new per-submission list an INLINE two-step confirm and rejected `window.confirm` for it on specific grounds — it blocks the main thread, renders as unstyled OS chrome inside the branded offline shell the H23 brand-cache machinery exists to keep consistent, cannot be asserted by the Playwright gate without a dialog handler (and §7.3 makes the confirmation a REQUIREMENT, so the gate must be able to prove it), and cannot name WHICH response it is about to destroy. Every one of those applies equally to `onDiscard()`'s call in `App.vue`, which is the G8c resolve-mode escape hatch. It was left alone only because its trigger lives in `RuntimeSession`'s notice slot and the e2e already pins that button's visibility, so converting it is a change to that flow rather than to the list's. Two confirm idioms in one runtime is the kind of inconsistency the next review will find |
| **The guest runtime's cross-form “Open this form” link leaves the installed PWA's scope** | nice | 2–3 | Filed 2026-08-09 from I10d. A conflict belonging to a DIFFERENT form cannot be resolved from the open one (the resolver reuses a share-token client bound to one slug), so the row offers `‹a href="/f/{slug}"› Open this form`. But `PwaManifestController` sets both `start_url` AND `scope` to the CURRENT form's `/f/{slug}` — the manifest is per-form by design — so on an installed PWA that link navigates out of scope and the OS opens it in a browser tab, ejecting the respondent from the installed window. The row is still better than the silent no-op it replaced, and the alternative (a broader manifest scope) is a real trade against the per-form install story. Wants a decision, not a patch |
| **A conflict review re-enters the fill session without re-running the H12b schedule gate** | should | 2 | Filed 2026-08-09 from I10d's review. `beginConflictReview()` re-mints, re-fetches the schema and sets `phase = 'ready'` with no call to `scheduleState()`, so a form that has since CLOSED or hit `max_responses` still opens for review and resubmit — where a fresh load would show the unavailable screen. Pre-existing (G8c), but I10d made it reachable from more screens by promoting the surface to app level, and the resubmit is then refused by the server rather than by the runtime, which is a worse experience than the guard exists to give |
| ~~**The mobile nav drawer's scrim has the WCAG 2.4.3 hole `MdsModal` just closed**~~ **— CLOSED IN J4b, AND THE ROW'S STATED BLOCKER WAS WRONG.** It held that `inert-stack.ts` could not be reused because "the drawer is a flyout inside `#app` rather than a body-level portal, so the ancestor-sibling walk would mark its own siblings within the shell rather than the page". Traced element by element, **both halves fail**: `.sidebar-scrim` is a CHILD of the pushed root, so it is on the path and is never visited (a test now asserts this, because a marked scrim would stop taking the clicks that dismiss the drawer), and `.app-shell`'s siblings ARE the page — the top nav, the impersonation banner and the content region. The walk was already correct for this element, unmodified; `inert-stack.ts:22-30` even documents that it exists to work for a non-portalled root. **The row was right that the drawer is not a dialog**, and J4b took that seriously: its root wraps the primary navigation landmark at all three breakpoints, so `role="dialog"` below 480px would make that landmark viewport-dependent. The fix is the seam the row's second option named — `useInertBackground`, extracted from `MdsModal` with `Modal.test.ts` passing byte-unedited as the evidence it is an extraction rather than a change. ⚠️ One of the row's four claims was also overstated: **the scroll lock was never needed on this shell**, since `.app-shell` is `100dvh` and `.app-shell__content` is the scroller, so `<body>` never scrolls. Original text follows.  | should | 1 | Filed 2026-08-08 from I10a's adversarial review. `resources/js/components/shell/Sidebar.vue`'s `.sidebar-scrim` renders a full-viewport `--mds-color-overlay-scrim` backdrop over the page at ≤480px with **no `inert`, no focus trap and no scroll lock** — exactly the defect I10a removed from `MdsModal`, on a surface DSR §3.6 explicitly forbids (“no page builds a modal-like floating panel that skips the focus-trap requirement by not technically being the shared Modal component”). It cannot simply reuse `inert-stack.ts`: the drawer is a flyout inside `#app` rather than a body-level portal, so the ancestor-sibling walk would mark its own siblings within the shell rather than the page, and the drawer is not a dialog. Wants either a shared `useInertBackground(rootRef)` seam extracted from the modal stack, or the drawer converting to a real dialog primitive — a design-system decision, which is why it is a row and not a fast-follow |
| ~~**⌘K is a dead key while the mobile nav drawer holds the page**~~ ✅ **CLOSED IN J6.** Filed 2026-08-13 from J4b1's adversarial pass and traced-but-not-fixed there; recorded only in `PROGRESS.md` until J6, which is why it is being filed and struck in one edit — **a finding whose only home is a session tracker is a finding the next reader will not find.** The blame in the original note was on `preventDefault()` running before the `openModalCount()` guard, and that turned out to be **the wrong culprit**: swallowing the chord while declining over a real dialog is correct, because handing ⌘K back to the browser there opens its find bar on top of a modal. The defect was one level down — `openModalCount()` has documented itself as counting *blocking dialogs* since J1a, and from J4b it also counted the drawer, whose own seam argues at length that making it a dialog would be a regression. Stack entries now declare their kind. **The palette stacking over an open drawer is the intended behaviour** (user decision 2026-08-17). See DSR §3.4.1 | should | 1 | ⚠️ **This row was never in this file until the increment that closed it.** Filed 2026-08-13 from J4b1's adversarial pass into `PROGRESS.md`'s status block and nowhere else, so it was invisible to every search of the backlog — which is why J6 files and strikes it in one edit. **File a deliberately-unfixed finding here at the moment you decide not to fix it** |
| ~~**A stacked dialog over the drawer strands focus on `<body>`**~~ ✅ **CLOSED IN J6, AND IT WAS TWO DEFECTS PLUS A THIRD UNDERNEATH.** Filed 2026-08-13 from J4b1, masked at the time by the dead-key row above, which is why the two had to ship together. (1) The palette's private visibility predicate asked `checkVisibility()`, which answers about **rendering** and knows nothing about `inert` — so with the drawer holding the page it handed back a top-nav control `.focus()` cannot move to, silently. **That is the same class of failure the selector *list* was created to fix in J1a**, blind to `inert` instead of blind to layout; the predicate now lives once in the design system and answers both. (2) `MdsModal.closePage()` **trusted** `opener.focus()` while `takePage()` twelve lines above verifies religiously; it is now tried-then-verified, with a `returnFocus` prop. (3) Strengthening the test for (2) uncovered a **pre-existing** defect neither row named: a modal mounted already-open with nothing focused captured `<body>` as its opener, and `document.body.focus()` is **not** the no-op two docblocks asserted — the body is the document's default focus target, so closing such a modal actively **took** focus, including out of an upper dialog still open. See DSR §4.5 | should | 2 | ⚠️ **This row was never in this file until the increment that closed it.** Filed 2026-08-13 from J4b1's adversarial pass into `PROGRESS.md`'s status block and nowhere else, so it was invisible to every search of the backlog — which is why J6 files and strikes it in one edit. **File a deliberately-unfixed finding here at the moment you decide not to fix it** |
| ~~**`useInertBackground` never re-pushes if `root` changes identity**~~ ✅ **CLOSED IN J6.** Filed 2026-08-13 from J4b1. The only watcher was on `active`, and `ownedRoot` is captured once, so a root replaced while active left the stack holding the element that had gone: the `inert` walk is then computed from a node that may not be in the document, and the live surface — an off-path sibling of the stale one — can be marked inert by its own seam. **Latent rather than live**, since the sidebar's root is stable; filed and fixed anyway because this is **exported API** and the next consumer is where a `v-if`-swapped root would find it. The fix distinguishes three transitions rather than two, and its own adversarial case is that `[active, root]` fires on every patch and must not re-run initial focus on a re-render that keeps the same element | nice | 1 | ⚠️ **This row was never in this file until the increment that closed it.** Filed 2026-08-13 from J4b1's adversarial pass into `PROGRESS.md`'s status block and nowhere else, so it was invisible to every search of the backlog — which is why J6 files and strikes it in one edit. **File a deliberately-unfixed finding here at the moment you decide not to fix it** |
| ~~**The tooltip's capture-phase Escape is page-global**~~ ✅ **CLOSED IN J6, WITH A REPRODUCTION THE FILING DID NOT PREDICT.** Filed 2026-08-13 from J4b1 as "a hovered rail tooltip can eat one Escape aimed at an unrelated open menu" — which understated it. A capture listener on `document` runs before **every** Escape claimant on the page whichever mechanism they chose, and `useDismissable` — whose consumers are **NotificationBell and FeedbackButton**, *not* the account menu, which moved to `MdsMenu` — binds Escape on `document` in the **bubble** phase, so it never ran at all. Measured in the running app at 800px against **both** shell mechanisms: pointer resting on a collapsed rail item with a popover open, Escape → tooltip hides, **popover stays open**. `MdsModal`'s panel handler loses the key the same way. **The rule now is that dismissal is unconditional and consumption is scoped** to wherever focus is; the deliberate trade for a hovered-in-dialog tooltip is recorded in DSR §3.4a, and §3.4.1's "family that must not be aligned" paragraph gains the governing priority rule, because three different binding sites are not a priority scheme | should | 1 | ⚠️ **This row was never in this file until the increment that closed it.** Filed 2026-08-13 from J4b1's adversarial pass into `PROGRESS.md`'s status block and nowhere else, so it was invisible to every search of the backlog — which is why J6 files and strikes it in one edit. **File a deliberately-unfixed finding here at the moment you decide not to fix it** |
| **`analytics-axe` and `scopes-axe` still scope a modal scan to `[role="dialog"]`** | nice | 1–3 | Filed 2026-08-08 from I10a. I10a widened `builder-axe.spec.ts`'s two share-panel scans back to whole-page and deleted `scan()`'s `within` parameter, because `MdsModal` no longer leaks its background into axe. `analytics-axe.spec.ts:150` and `scopes-axe.spec.ts:83` are the same pattern and could now follow — but they were left alone deliberately, because those two files scope **every** scan, modal or not, for the separate G9b reason recorded in their headers (a whole-page scan there re-flags pre-existing violations elsewhere on those pages, which cost G9b three follow-up pushes). Widening only their dialog call is therefore a different change with a different risk, and worth doing only together with an audit of what those pages actually fail whole-page |
| Auth/session events in the audit trail (login success/failure, logout, reset, token issue/revoke, MFA enrol/challenge) | should | 1–2 | `AuditEvent` covers business mutations only; brute-force/takeover leaves no tenant-visible trace. Laravel already fires these events |
| ~~Breached-password (HIBP) check~~ ✅ **ALREADY SHIPPED — Phase 0 B1 (`e4253fa`)** · admin-configurable password policy still open | should | 1 | ASVS L1/L2 (NFR §4) mandates a breached-password check. ⚠️ **THIS ROW WAS STALE AND COST I8 A PLANNED WORK ITEM.** `FortifyServiceProvider:42` has carried `Password::defaults(fn () => Password::min(12)->uncompromised())` since Phase 0, inherited by registration, password reset, profile update and invitation-accept — so I8's scope listed "HIBP" as work that was already done, discovered only by reading the provider. I8a additionally **faked the live HTTPS call to `api.pwnedpasswords.com`** that `AuthenticationTest` was making on every CI run, and added a test that exercises the breach check specifically (the framework early-returns on a length failure, so the pre-existing test never reached the verifier). What genuinely remains is the **admin-configurable** half: no `SettingKey` exists for a per-workspace password policy, and PRD Feature #14 does not ask for one |
| Session lifecycle controls (idle/absolute timeout + step-up re-auth; later: session inventory / "log out everywhere") | should | 1 (timeout+step-up) → 2 (inventory) | Enumerators share field devices; step-up partly delivered with Feature #14 |
| HIPAA / BAA posture — record an explicit scope decision now | nice (decide P0) | 4 (build) | Likely "non-goal until a US covered-entity customer materializes"; cheap one-paragraph decision, not sub-processor contracts today |
| Vulnerability-disclosure contact (`security.txt`) + audit-evidence-from-day-one; SOC 2 Type II / ISO 27001 named as a Phase-4 target | nice (P0/1 for the cheap parts) | 0/1 → 4 | `security.txt` + instrumenting audit evidence early is cheap; formal attestation is a later revenue-justified spend |
| Recurring third-party penetration test | should | 2+ | Companion to the now-adopted CI SCA/SAST |
| Legally-defensible e-signature (signer auth, consent-to-sign, tamper-evident certificate) | nice | 3–4 | Today's signature field stores only an image; a real ESIGN/eIDAS capability is a separate product line — only on demonstrated demand |
| Enterprise identity/network — SCIM auto-provisioning + tenant IP allowlisting | nice | 4 (with the planned SSO/SAML) | SCIM auto-deprovisioning is the standard companion to SAML for large tenants |
| **Playwright a11y coverage for the central-domain admin console** ⚠️ **STILL OPEN — I10e (2026-08-09) DID TWO OF THE SIX PAGES** (`/admin/settings`, `/admin/feedback`), so the title is deliberately NOT struck through. **ALL FOUR BLOCKERS THIS ROW ACCUMULATED WERE FALSE, INCLUDING THE ONE I10e ITSELF FILED**, and the last one is the instructive one. **The fourth blocker was a RACE IN THE TEST SETUP, not an app fault.** Its evidence read: a central-host `POST /login` 302s, the browser stays on `/login` with no validation error and a `meridian-session` cookie present, and `GET /admin/settings` then 302s away — recorded over four CI cycles as `POST 302 /login | GET 302 /admin/settings | GET 200 /login`. Two runs settled it. (a) A Pest test (`tests/Feature/Auth/CentralHostLoginTest.php`) proved the SERVER side clean: a real `POST http://meridian.test/login` authenticates the operator, the session carries into `/admin/settings`, and the console renders once step-up is confirmed. (b) The browser half was then reproduced against the LOCAL docker stack — no CI needed, `E2E_BASE_URL=http://acme.localhost:8080` — where full response logging showed `POST 302 /login → /dashboard` (i.e. **the login had succeeded all along**) and, decisively, the `/admin/settings` document navigation firing BEFORE the login XHR had landed. **The cause: the login form is an Inertia XHR (`form.post`), so the page performs no document navigation and `waitForLoadState('networkidle')` resolves INSTANTLY against the already-idle previous load.** The setup raced ahead and read a guest session. Two fixes, both in `global-setup.ts`: visit `/admin/settings` as a guest FIRST so `redirect()->guest()` plants `url.intended` (otherwise Fortify targets `fortify.home` = `/dashboard`, a TENANT route whose `NotASubdomainException` handler redirects to the absolute `config('app.url')` — which an Inertia XHR cannot follow, since an external redirect needs `Inertia::location()`'s 409); and `waitForURL` on the post-login destination, which is both the sync point and a genuine session assertion because every candidate sits behind `auth`. **TWO REAL DEFECTS FELL OUT, NEITHER OF WHICH THE a11y SCANS WERE LOOKING FOR.** (1) **`E2eSeeder::seedSuperAdmin()` and `DemoSeeder::ensureSuperAdmin()` were promoting the operator over the app connection, affecting ZERO rows, silently.** `users` carries FORCE row-level security, its SELECT policy is join-shaped and fails closed with no context, and PostgreSQL applies SELECT policies to an UPDATE whose WHERE reads a column — and a platform operator has no tenant membership by design, so it is invisible from every context. A freshly seeded demo database had no super-admin at all and no error to say so. Fixed to `pgsql_privileged`; both arms pinned in Pest, the hazard arm included, so a revert reddens. (2) **`AdminLayout` overflows horizontally at 375px** — its first measurement at any viewport, red on both pages in both themes. Measured rather than guessed: `.admin__nav` is 369px of links from x=16, i.e. 385 against a 375px viewport; identical on both pages, which is the tell that it is the shell. `flex-wrap` on the nav inside the existing ≤900px block (where the bar is already `height: auto`) is the fix. **What remains, and why it was not swept in:** `/admin/tenants`, the tenant detail page, `/admin/users` and `/admin/audit-log`. Twelve tests is about two minutes of a ~13-minute job, and four more pages would triple that before anyone knew whether the shell was clean — which was the right call, because it was not. Now that `AdminLayout` is measured clean at all three widths, adding them is cheap. Original filing follows.  (owner: **I10e — two of six pages done**) | should | 1 | Filed by I5, which added `/admin/settings` — the console's first page with real form controls. **I8c closed the sibling half of this row and deliberately did NOT close this one; the reasoning changed, so it is restated rather than left as filed.** I8c added `tests/e2e/auth-axe.spec.ts` for the unauthenticated pages using the proven per-file `test.use({ storageState: { cookies: [], origins: [] } })` idiom — NOT the `playwright.config.ts` restructure this row assumed, because a fourth project would multiply all eight existing specs by a viewport they do not need. **The `otplib` blocker this row names has dissolved**: TOTP is HMAC-SHA1 over a time counter, ~30 lines with node's own `crypto` plus a base32 decoder, so no dependency need enter the `npm audit --omit=dev` gate. **But I8a added a NEW hop that did not exist when this was filed** — every console page now carries `step-up`, so a console `globalSetup` must log in, clear the TOTP challenge, hit a console route, follow the redirect to `/user/confirm-password`, submit, and come back. That is four sequenced redirects in a setup file that cannot be exercised outside CI (Playwright needs the full running stack), and shipping unverified E2E infrastructure into a merge-blocking gate buys CI cycles rather than confidence. Deferred on those grounds and not on effort. Until then the console's primitives stay axe-covered per-story by the Storybook job, and its behaviour by `resources/js/Pages/admin/*.test.ts` |

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

## ⚠️ Discovered defects (found while building)

- **The architecture doc promises RESUMABLE media upload; what shipped is whole-file with per-file retry
  (found by P3a, filed by K1c).** `docs/architecture/technical-architecture.md` says it three times —
  `:306` ("upload queued media attachments *(resumable, per-file retry)*"), `:314` ("resumable multipart
  upload with per-chunk retry"), and `:521`, where R4's mitigation for *"partial media upload"* is "media
  uploads are independent of the submission record with their own resumable retry". G8b built the
  independence and the retry; it did not build **resumption**, so a 40 MB video that fails at 90% on a
  field connection restarts from zero, which is the case the promise exists for.

  **Not a bug in what was built** — the per-file retry works, and an enumerator on a good connection never
  notices. It is a documented capability the as-built does not have, which is the fourth of that shape this
  project has recorded (P2a's `Dedicated db | In effect: Yes`, P2b's `0700`, P2c's Consequences, P3a's §8).
  The honest remedies are opposite in cost and both acceptable: **narrow the three sentences** to what G8b
  ships, or **build chunked upload** (a chunk endpoint, an upload-session row, and a client that tracks
  offsets — genuinely large, and it wants a real measurement of field failure rates first, which is an
  input nobody here can supply). ⚠️ **The narrowing is NOT free**: R4's risk register entry names resumable
  retry as its mitigation, so narrowing the promise re-opens the risk and R4 has to say so.

  **Why it took three increments to land here.** P3a found it and could not file it — `docs/feature-backlog.md`
  was Lane A's live J4c2 claim — and K1a/K1b could not either, for J5. Filed by the first Lane B row that
  could reach the file, which is the protocol working rather than a delay.

- **26 foreign keys can reference across a tenant boundary, and the database will act on them
  (ADR-0002 §D5, measured by P2c).** Not a latent bug — no cross-tenant reference exists in the data,
  because every write path resolves its parent under RLS before writing the child and reaching a
  neighbour's row would additionally need a guessed UUIDv7. It is a missing *structural* guarantee:
  PostgreSQL bypasses row security for referential-integrity checks, so the constraint layer would
  accept one, and **20 of the 26 are `ON DELETE CASCADE`**, which turns a bad reference into a
  cross-tenant delete. Recorded with a per-constraint rationale in
  `App\Support\Tenancy\ConstraintBoundaries::FOREIGN_KEY_EXCEPTIONS`; new ones are blocked by
  `ConstraintBoundaryDriftTest` and `scripts/constraint-boundary-lint.php`.

  **What remediation costs, so the next session does not re-derive it.** The compliant shape is
  `foreign(['tenant_id', 'x_id'])->references(['tenant_id', 'id'])->on('parent')`, which nine
  constraints already use. It needs a `(tenant_id, id)` unique on the PARENT, and eight parents lack
  one: `form_versions`, `form_fields`, `form_sections`, `submissions`, `attachments`, `roles`,
  `permissions`, `subscriptions`. Then 26 drop/recreate pairs preserving each `ON DELETE` action
  exactly. Two complications worth knowing before starting: **`forms` ↔ `form_versions` is circular**
  (`form_versions.form_id` against `forms.draft_version_id`/`current_published_version_id`), so the
  pair has to be handled together or deferred; and **`form_templates.source_form_version_id` legitimately
  points at PLATFORM rows** with a NULL `tenant_id`, so plain equality is wrong for it and it needs the
  widened shape rather than a mechanical rewrite.

- **Three more cross-boundary FKs cannot be fixed this way at all**, because the SOURCE has no
  `tenant_id` column to put in a key: `role_has_permissions`'s two, and `tenants.logo_attachment_id`
  (the workspace branding logo, which points at the tenant-scoped `attachments` from the `tenants`
  table itself — previously unrecorded anywhere). ⚠️ **`role_has_permissions` has a live trigger, not a
  hypothetical one**: ADR-0017's "When to Revisit" records that the pivot leaks cross-tenant the day
  `roles` acquires its first tenant-owned row, which `roles_tenant_insert` permits and nothing
  prevents. Its safety today is a fact about the seeded data — all five roles are platform rows.

- **Ten non-unique indexes on tenant-scoped tables do not lead with `tenant_id`** (ADR-0002 §D1's
  ordering rule): `attachments_attachable_type_attachable_id_index`, `domains_custom_sweep_idx`,
  `feedback_reports_submitted_at_index`, `field_library_usage_count_index`,
  `form_templates_usage_count_index`, `model_has_permissions_model_id_model_type_index`,
  `model_has_roles_model_id_model_type_index`, `personal_access_tokens_expires_at_index`,
  `personal_access_tokens_tokenable_type_tokenable_id_index`, `submission_geo_index_geom_gist`. A
  query-planning matter with **no isolation consequence** — a plain index constrains nothing, so it
  cannot refuse a write or cascade a delete — which is why they are listed here rather than gated by
  `ConstraintBoundaries`. Several are correct as they stand (a GiST geometry index, the polymorphic
  morph lookups); the list is the measurement, not a work item.

- ~~**`PUT /user/profile-information` writes ZERO ROWS, silently — so changing your own name or email in
  Settings does nothing at all.**~~ ✅ **FIXED IN J3b.** `config/fortify.php` now mounts
  `EstablishTenantDatabaseContext` on the Fortify group, so `app.current_user_id` is set for the request
  and the row is visible to its own update. `tests/Feature/Auth/FortifyRouteContextTest.php` pins all
  five write endpoints; every case was verified red against the unfixed code first (5 failed → 5 passed),
  and `EmailVerificationGateTest`'s correction case now asserts the full round trip instead of stopping
  short of it.

  ⚠️ **THE ENTRY BELOW UNDERSTATED THE BLAST RADIUS AND THE SEVERITY, AND THE MISSING ROW WAS THE
  SERIOUS ONE.** `GET /email/verify/{id}/{hash}` is registered inside the same Fortify group
  (`vendor/laravel/fortify/routes/routes.php:90`), so `markEmailAsVerified()` was also writing zero rows
  — while `save()` still returned `true`, so `event(new Verified($user))` fired and the redirect carried
  `?verified=1`. Combined with the `verified` gate J3a mounted, **a newly registered user could follow a
  valid verification link, be told it worked, and be bounced back to the notice page forever** — with
  their only escape being the correction form that posts to the endpoint in the entry's own title. Both
  doors were the same broken door. Measured, not inferred: the test asserting `email_verified_at` is
  stamped failed against the unfixed code.

  The entry's own framing is preserved below, because the reasoning was right about the mechanism and
  the "fix is a decision" call was correct — the decision taken was the user-context middleware, since
  the connection-swap alternative could not have reached the four vendor-owned 2FA/verification
  controllers at all.

  <details><summary>Original entry (J3a)</summary>

  Found in J3a, pre-existing since Phase 0. Fortify registers that route
  with `['web', RequirePlatformHost, AppSecurityHeaders, GateRegistration, Authenticate:web]` and **no
  tenancy middleware**, so `app.current_user_id` is unset for the request. `users_app_update` is permissive
  (`USING (true) WITH CHECK (true)`), but PostgreSQL applies **SELECT** policies to an UPDATE whose `WHERE`
  reads a column, and `users_users_visibility` requires either `id = app.current_user_id` or an ACTIVE
  co-tenant membership. The row is therefore invisible to its own update: zero rows affected, no exception,
  no log line. Measured directly — an update with the GUC cleared reports `0 affected` while the row is
  plainly there.
  **Why it matters more from J3a on:** `UpdateUserProfileInformation` nulls `email_verified_at` on an email
  change, and J3a mounts `verified` on the authenticated tenant group, so this endpoint is now the only
  escape from a verification lockout. Today the two defects cancel — the email never changes, so
  verification is never nulled — which is not a property to ship on. `auth/VerifyEmail.vue` already carries
  the correction form; it needs a write path that works.
  **The fix is a decision, not a patch:** either give Fortify's authenticated routes a user-context
  middleware, or have the action write on a connection that can see the row. Both have blast radius beyond
  one endpoint (`/user/password`, the 2FA endpoints and `/user/confirm-password` share the same stack), which
  is why J3a recorded it here rather than widening its own scope. `EmailVerificationGateTest`'s
  "gives a member behind the gate the values needed to correct their own address" deliberately asserts only
  the half that works, so nothing encodes the defect as the contract.

  </details>

- ~~⚠️ **THE TWO-FACTOR CHALLENGE PAGE IS UNREACHABLE, SO ANYONE WHO ACTUALLY ENROLS IN 2FA IS LOCKED OUT
  AT THEIR NEXT SIGN-IN.**~~ ✅ **FIXED IN J3c1.** `GET /two-factor-challenge` answers 200 and renders;
  the TOTP and recovery-code paths both complete a sign-in. Measured on the running app, the same way the
  defect was.

  **The fix is NOT the one this entry predicted, and the difference is the point.** The entry called for
  giving the mid-login request a user context from `session('login.id')` — "a security-relevant widening
  of when `app.current_user_id` is set". That was considered and **refused**. It redefines what the GUC
  MEANS: every RLS policy in the schema is written against "the authenticated user", while `login.id`
  names somebody who has passed factor ONE. It also cannot be scoped to the page — Fortify registers its
  whole route set from one config-level middleware array with no per-route hook — so the widening would
  land on `/login`, `/register`, `/forgot-password` and `/reset-password` too.

  The actual cause was narrower than "no user context". `EloquentUserProvider::getModel()` returns a
  **class-string**, so Fortify's `$model::find(...)` is a STATIC call that bypasses
  `RlsAwareUserProvider::createModel()` — the class's own "single routing point". The provider was
  correctly configured and simply unreachable from that line. `App\Http\Requests\Auth\RlsAwareTwoFactorLoginRequest`
  resolves through `retrieveById()` instead, which is the pre-auth read path B1 built and the rest of the
  application already used. **`EstablishTenantDatabaseContext` is untouched and this increment widens
  nothing.** (That middleware was never causal, incidentally: for a guest it applies `(null, null)`, the
  value the request already had.)

  ⚠️ **THE BLAST RADIUS WAS ONE ITEM WIDER THAN THIS ENTRY KNEW, AND THE EXTRA ONE FAILS OPEN.** The entry
  named `two-factor.login.store` and the recovery-code path as READS that resolve the same way. The
  recovery path also **writes**: `store()` calls `$user->replaceRecoveryCode($code)` to rotate the code
  just spent, before `Auth::login()`, so that write had no user GUC — the identical zero-row shape PR #147
  fixed on six other endpoints. It affected zero rows, threw nothing, returned `true`, and dispatched
  `RecoveryCodeReplaced`. **A used recovery code was never rotated and stayed valid forever.** Measured on
  the running app with the fix disabled: the sign-in still succeeded and the spent code was still in the
  list. Recovery codes are single-use by construction, so a read-only fix would have shipped a
  credential-reuse defect in place of a lockout. Fixed on `App\Models\User::replaceRecoveryCode()`, which
  performs the write on `pgsql_auth` and restores the connection in a `finally`.

  Covered by `tests/Feature/Auth/TwoFactorChallengeTest.php` (six cases, each mutation-tested against the
  broken code) and scanned in both panel states by `tests/e2e/auth-axe.spec.ts`. The seeded
  `twofactor@meridian.test` identity, kept unused since J3b for exactly this, is what the scan drives.

  <details><summary>Original entry (J3b)</summary>

  ⚠️ **THE TWO-FACTOR CHALLENGE PAGE IS UNREACHABLE, SO ANYONE WHO ACTUALLY ENROLS IN 2FA IS LOCKED OUT
  AT THEIR NEXT SIGN-IN.** Found in J3b while building an accessibility scan for that page; measured on
  the running app, not inferred:

  ```
  POST /login                → 302 → /two-factor-challenge   (correct: credentials pass, challenge issued)
  GET  /two-factor-challenge → 302 → /login                  (the bug)
  ```

  Fortify's `TwoFactorLoginRequest::hasChallengedUser()` resolves the pending user with
  `$model::find($this->session()->get('login.id'))` — on the **default** connection. At that moment the
  user is authenticated for no guard, so `EstablishTenantDatabaseContext` has set `app.current_user_id`
  to NULL, `users_users_visibility` matches nothing, `find()` returns null, and the controller concludes
  there is no challenged user. **It is the same RLS shape J3b's first PR fixed on the WRITE side**
  (`docs/feature-backlog.md`'s closed entry above), arriving on the read side — and `RlsAwareUserProvider`
  exists precisely because pre-auth reads need `pgsql_auth`; this path was never routed through it.

  **Why nothing caught it before:** no seeded identity has ever had a real TOTP secret. The E2E
  super-admin carries `two_factor_confirmed_at` with a NULL `two_factor_secret` **on purpose**, so
  Fortify does not consider 2FA enabled and never issues a challenge. `TwoFactorEnrollmentTest` covers
  enrolment, which is a different route group. The path had no coverage at all until J3b seeded
  `twofactor@meridian.test` — which is kept, unused, because it is what the fix will need.

  **The fix is a decision, not a patch**, and it is deliberately NOT folded into J3b for the same reason
  J3a did not fold in the write-side one: the natural fix is to give the mid-login request a user context
  from `session('login.id')`, which is a security-relevant widening of when `app.current_user_id` is set
  and deserves its own increment and its own tests. Note the blast radius is wider than the one page —
  `two-factor.login.store` and the recovery-code path resolve the same way.

  </details>

- **SIX components hide a node with `position: absolute` + `clip: rect(0 0 0 0)` while positioning
  nothing themselves** (was seven — see below), so that node's containing block is established outside the
  component and no scroll container in between can clip it. This is the defect G11 fixed on `MdsDataTable`,
  JR5 fixed on `MdsSegmentedControl`, and J3b fixed on `MdsSpinner` and `MdsTimeSeriesChart` — found the
  fourth time by scanning for the shape rather than by tripping over it. The remaining six are all in the
  app trees and all sr-only live regions: `Pages/scopes/Index.vue`,
  `components/builder/BuilderCanvas.vue`, `components/shell/FeedbackButton.vue`,
  `components/submissions/GeoInput.vue`, `public-runtime/components/RuntimeShell.vue`,
  `public-runtime/components/SyncStatus.vue`.

  ✅ **`components/shell/CommandPalette.vue` LEFT THE LIST IN J4c, AND IT IS THE FIRST ENTRY EVER REMOVED.**
  Not fixed in place: its two clipped nodes — the combobox's label and its polite live region — **moved into
  `MdsCombobox`**, which positions its own root, so their containing block now resolves inside the component
  that owns them. The app-tree file no longer matches the clip idiom at all. That is the shape the note
  below asks for: **the defect left, rather than the list being edited to stop noticing it.** ⚠️ The design
  system's own count stayed at **zero**, which is the assertion that would have caught the lazy version of
  this move — clipping without positioning, one directory to the left.

  **Not fixed here because the fix is one line and the VERIFICATION is not**: `position: relative` also
  makes the container the containing block for any other absolutely positioned descendant and establishes
  a stacking context, so each needs a look at the running app. **Whether each is a LIVE bug also depends on
  whether an ancestor scroll container exists**, which source text cannot answer — the design-system cases
  were latent for four increments precisely because they only bite where something finally tries to clip.

  They are pinned meanwhile: `packages/design-system/src/theme/__tests__/clipped-node-containment.test.ts`
  asserts the design system has **zero** instances and that the app-tree list is **exactly** these seven,
  so an eighth fails at the moment it is written. ⚠️ The list may only ever shrink — do not add to it.

### Design system — the Storybook axe gate runs locally *(corrected in J4b)*

**It was recorded for several increments as impossible to run on this host. It is not.** The job needs the
package's own dependency tree, which nothing in the root install provides, plus its own browser.

➡️ **THE COMMAND SEQUENCE LIVES IN `README.md` UNDER "Everyday commands", AND IS MAINTAINED THERE.**
This section deliberately no longer prints a second copy — that duplication is what let the README rot
while the working recipe sat in a backlog file no reader opens. `M59` measured the whole field and the
README prescribes the `e2e`-image form, because the host form printed here pays the bind-mount cost
recorded immediately below. `tests/Feature/Docs/DocumentedCommandDriftTest.php` gates the README half.
What stays here is what a command block cannot carry: that cost, the resolution trap, and the catch.

⚠️ **It must be invoked through the root script or from inside the package.** Run from the repo root,
`@storybook/vue3-vite` fails to resolve and the build dies loading a preset — which is the symptom that got
written down as "cannot run here at all". The two are easy to confuse and the difference matters: this gate
is **merge-blocking**, and a gate believed unrunnable is a gate nobody runs. J4b found that out by shipping
a PR that failed on it.

⚠️ **AND IT HAS A COST ON THE OTHER SIDE OF THE BIND MOUNT, MEASURED IN J4c: `ds:install` BREAKS THE NODE
CONTAINER'S VITE, AND THEREFORE THE VISUAL SWEEP.** That install runs on the HOST and writes win32-native
binaries — `@esbuild/win32-x64`, `@rollup/rollup-win32-*` — into `packages/design-system/node_modules`,
which is the **same bind-mounted path `dev_formbuilder_app-node-1` reads**. The container's own `npm install`
then dies trying to unlink one (`EIO … unlink … esbuild.exe`) and the container **exits**; Vite stops
serving and every subsequent screenshot is of nothing at all. **`rm -rf packages/design-system/node_modules`
and restart the container** — the directory is gitignored (`.gitignore:20`), and the Storybook gate simply
re-installs it next time. **Order the two gates so the sweep comes after the reinstall, or expect to pay
this once per increment.** ⚠️ Also note `test-storybook` needs a SERVER: build to `storybook-static`, serve
it, and pass `--url`; there is no default that finds a static directory.

⚠️ **AND IT CATCHES THINGS NOTHING ELSE DOES.** The failure that exposed this was a Vue SFC parse error —
*"Element is missing end tag"*, at a position past the end of the file — raised by `plugin-vue` under the
Storybook build while **the application's own build, `vue-tsc` and Vitest all compiled the same component
without complaint**, and a direct `compiler-sfc` `parse()` reported zero errors. The trigger was
tag-shaped literals in `<script>` comments, and it is **file-dependent rather than per-literal**: three such
tokens failed, the same file with two removed passed, and two injected into a different component passed.
There is no clean per-token rule — which is why the answer is the standing one, *name the thing, never
quote it*, now on its fourth gate.

### Design system — deferred from J4a, each with its preconditions stated

- ~~**`MdsTooltip`**~~ — **CLOSED IN J4b. All three preconditions discharged; the as-built notes are DSR
  §3.4a.** Recorded rather than deleted, because the row's own text said the API "is settled — do not
  re-derive it", and a closed row with no pointer is how a settled design gets re-derived anyway.
  (1) and (2) were the real work and were done as written: it teleports to `<body>` and the teleported root
  carries `data-mds-inert-exempt`, making it the second holder after `MdsToastHost` — which is why
  §3.4.1's "that exemption belongs to the toast host alone" was narrowed in the same PR. **(3) was simply
  wrong by the time it was read, and that is the part worth keeping**: it concluded "zero consumers" from
  the two sites J4a checked, while the collapsed rail — the one place §3.4 and §6 both *require* a tooltip —
  had existed in code all along. A precondition written as a fact about the codebase needs re-checking
  against the codebase before it is believed.
  ⚠️ **Its scope is narrower than §3.4's line implies: the sidebar enables it only across 481–1024px.**
  Below 480px the drawer restores labels and has no hover, but focus still fires and the drawer moves focus
  programmatically — an ungated tooltip appeared unbidden on mobile and ate the drawer's first Escape.

- **`FormRowActions.vue`'s overflow menu** — `MdsMenu` exists as of J4b (DSR §3.4b) and this is still not
  adopted, deliberately. The component's docblock names **two** blockers and J4b cleared only one. The other
  is that **five of its nine buttons are pinned by end-to-end specs**: `templates-axe.spec.ts` clicks
  *Save as template* unscoped, so it must be in the accessibility tree with no menu open, and
  `responsive-axe.spec.ts` navigates by *Response statistics* and *New submission* from four call sites.
  So this is a spec change as much as a UI change — and `tests/e2e/support/navigate.ts` records that one
  change to how that row navigates cost **81 failures across four spec files**, on a suite that cannot run
  on the development host. **Its precondition is therefore a decision about the specs, not a missing
  component**: pick which actions stay visible (the four the specs name, plus Publish), move the spec
  locators for the rest, and do it as its own increment where an e2e run is the point rather than a risk.

- **An `MdsIconButton` prop to suppress its native `title`** — the component sets both `:aria-label` and
  `:title` from one `label`, which is right until something layers a real tooltip on it: two tooltips render
  one under the other. J4b did not add the prop because **nothing in its scope consumes it** — the sidebar
  rail uses a raw `<Link>`, not an icon button — and an unconsumed prop on this component is the exact
  mistake DSR §3.4a already records against shipping an unconsumed primitive. **Owed by the first PR that
  puts a tooltip on an icon button, in that same PR.**

- ~~**`DestinationCatalog::visibleTo()` asks `feature()` directly**~~ ⛔ **SUPERSEDED 2026-08-18 — THE QUESTION WAS RIGHT, THE FRAMING WAS WRONG, AND THE DECISION IS NOW TAKEN.** This row read the divergence as a sign flip in one file. It is not: `RequireFeature` fails OPEN and `EntitlementService::feature()` fails CLOSED, and four test files pin the two readings BY NAME, so it was always one product decision rather than a cleanup. See the *"TWO contradictory conventions for an unseeded plan catalog"* row above, which carries the mechanism and the **user ruling of 2026-08-18: FAIL OPEN**. Kept struck rather than deleted because this row’s own reasoning — that the nav and the routes may differ on purpose, and that `/domains` is a deliberate divergence — is the part a reader still needs, and because a decided question left looking open is how it gets re-litigated. ⚠️ Its parting instruction *“whoever takes it should move both or neither”* is also unsafe as written: `Sidebar.vue` gates client-side off a prop that is `null` in exactly the disputed state, so it is not a second copy of the same switch.

- **`MdsPasswordStrength`'s list carries no `role="list"`** — `list-style: none` strips list semantics in WebKit, so VoiceOver announces the requirement rows with no sense of how many there are. `MdsBreadcrumb`, `forms/Index.vue`'s card grid and (J5b) `MdsChecklist` all carry the attribute; this one predates the rule. One attribute, but it belongs with a look at the running page: this is a live region that re-announces while somebody types, and list semantics change what is read back.

  ⚠️ **MEASURED 2026-08-18 BY J8 WHILE SCOPING THIS ROW, AND THE ROW IS ~22× LARGER THAN IT READS — FILED HERE BECAUSE J8 DELIBERATELY DID NOT TAKE IT.** A sweep of `packages/design-system/src`, `resources/js` and `resources/public-runtime` for `list-style: none` in a file carrying no `role="list"` returns **22 files**, not one: `ChartLegend`, `PasswordStrength`, `AnalyticsFilterBar`, `SavedViewList`, `BuilderCanvas`, `ConditionRows`, `FieldPalette`, `LibraryPicker`, `LogicRail`, `ScopeTree`, `TwoFactorSetup`, `IdpMetadataCard`, `SsoFailuresCard`, `GeoInput`, `MediaInput`, `AuthLayout`, `scopes/Index`, `search/Index`, `Encode`, and three in `resources/public-runtime`. Seven files DO carry the attribute (`Breadcrumb`, `Checklist`, `TabNav`, `NotificationBell`, `Sidebar`, `forms/Index`, `forms/Templates`), so the convention exists and is simply unevenly applied.

  ⛔ **IT IS NOT A SWEEP, AND THAT IS THE POINT OF RECORDING THE NUMBER RATHER THAN THE FIX.** The scan is FILE-level, so a file may carry `list-style: none` on a presentational wrapper and `role="list"` on the real list; and a `<ul>` used purely as a layout container should NOT be given list semantics at all. Each one needs a per-element judgement plus a look at what a screen reader actually announces — which is why `MdsPasswordStrength` is still the right FIRST row (it is a live region that re-announces while somebody types), and why the other 21 are a separate, larger piece of work. Note three of them are in `resources/public-runtime/`, which Standing Rule 7(b) does not put in Lane A's column.

- **`MdsProgress`'s step-count variant** — DSR §3.9 specifies two variants and J4a built the percentage one. ⚠️ **J5b did NOT consume it and was right not to**, though the row that scheduled J5 named a checklist as the shape that would want it: the step-count variant is *"Step X of N"* for multi-step form **navigation**, with a current position and a visited-set rule, and a checklist has neither. `MdsChecklist` uses the percentage variant with a domain-native `valueText`. The precondition below is therefore unchanged and the first consumer is still the one named.
  ⚠️ Its reference implementation, `resources/public-runtime/components/ProgressIndicator.vue`, is **better
  specified than §3.9 itself**: it carries a rule the section does not, that a step is "done" if it is in
  the VISITED SET and never if its index is below the current one (H21b — relevance filtering means a
  respondent can be on step 5 having never seen step 3). Put that rule in §3.9 before generalising.
  Migrating it puts **17 assertions** at risk across `resources/public-runtime/__tests__/components.test.ts`
  and `public-runtime-axe.spec.ts`, inside a separate Vue SPA that imports eight design-system components.
  The second consumer is `Pages/submissions/Encode.vue:897-899` — a bare `p aria-live="polite"` reading
  "Step 3 of 5" with no landmark and no navigable steps, pinned by nine assertions in `encode.test.ts`.

- **A `--mds-person-identity-*` scale** — `MdsAvatar` is monochrome, and DSR §3.12 records why reusing the
  form-identity six is wrong. Four preconditions before a coloured avatar is buildable: a scale of its own
  with a JR3-shaped contrast suite; every hue ≥30° from every **status** hue AND from every **form-identity**
  hue (a person and a form at 0° destroys the mnemonic that scale exists for); a dark re-point, because the
  identity scale carries its hue as TEXT on a 12% tint and measures 2.91:1 as a solid fill under white; and
  a server-side per-user identity integer, which nothing computes today.

- **~20 hand-rolled notices remain** after J4a migrated thirteen. They are **not** a sweep: several carry
  deliberate, individually-argued `role` choices (`Encode.vue` has five, each with its own rationale;
  `SyncStatus.vue` records a deliberate demotion off `role="alert"`), and `resources/public-runtime/` is a
  separate SPA that imports eight design-system components and would need `MdsAlert` added to that set.


## Notes

- Free-tier / trial mechanics, self-serve signup + email verification, plan upgrade/downgrade/proration, dunning, invoices/receipts, seat-management UX, and account deletion/offboarding export are **partly covered** by the Onboarding (#25), Pricing (#24), and GDPR (#12) docs — audit those three for concrete gaps before Phase-1 billing/onboarding code, rather than treating them as wholly-missing here.
- Items that competitor products treat as table-stakes but this product deliberately declines (self-hosting, SMS/IVR channels, general app-builder scope, real-time co-editing) are **non-goals** in `docs/PRD.md` §7 and are intentionally *not* listed here.

## ⚠️ Merge-gate review of `main` → `phase1-completion` (2026-08-18)

**Provenance, so nobody re-derives these.** A full-coverage review of the integration diff on branch
`m1-merge-gate`, run as **18 shards over 1,073 of 1,073 changed files** — every test file, every service,
every migration, the design system, both front-end trees, and the whole doc corpus — with **adversarial
verification on every blocker** (each claim's links re-walked against the code with the goal of falsifying
it; three blockers were downgraded that way and are filed below at their downgraded severity, with the
reason attached). Rows already **fixed on this branch** before filing are deliberately absent: the
`ACCESS-MATRIX` personal-email row, `SsoUserProvisioner`'s adoption of an existing central account
(`SsoFailureReason::ExistingAccountNotMember` + migration `2026_08_17_000104`), `Settings/Sso.vue`'s
`#footer`→`#actions` slot, `NotificationType::BadgeEarned->pathFor()`, and six stale doc blocks
(README's front page and "what's next", the RBAC design's impersonation line, TESTING-GUIDE's step-up box
and sidebar list, ACCESS-MATRIX's viewer sidebar list, `.env.example`'s `CENTRAL_DOMAIN`).

Each row carries its severity as graded, the `file:line`, the concrete failure, and whether it is **live**
(reachable today) or **latent** (needs a stated precondition). Nothing here is a re-statement of a row
already in this document; the one overlap — resumable media upload — is the existing *"architecture doc
promises RESUMABLE media upload"* row above, whose three cited architecture sentences the review confirms
are still in place.

### ⚠️ The gate that is not measuring what three files say it measures

- ✅ **CLOSED BY `M17` (2026-08-26) — `major` · ~~THE END-TO-END HORIZONTAL-OVERFLOW ASSERTION IS
  STRUCTURALLY INERT ON EVERY `AppLayout` PAGE.~~** The row is **correct in its diagnosis and wrong in
  its evidence**, and both halves matter.
  ⛔ **THE PROOF THE ROW OFFERS IS A NO-OP AND PROVES NOTHING.** It says *"delete `min-width: 0` from
  the leaderboard name cell (`achievements/Index.vue:452-459`) and every scan still passes."* That rule
  **also** carries `overflow: hidden`, and per CSS Sizing 3 a flex item whose main-axis overflow is not
  `visible` already resolves `min-width: auto` to **0** — so the declaration is redundant and deleting
  it changes nothing: **343/343 with it and without it**, measured in Chromium. The scan passing was the
  *correct* result. **The gate was inert — but that is proven by the clip, not by this demonstration,
  and for the whole life of the row its evidence was never evidence.**
  ✅ **PROVEN INSTEAD WITH A MUTATION THAT DOES SOMETHING — THREE LEGS, ALL MEASURED.** Deleting the
  load-bearing `overflow-wrap: anywhere` from `.dns__code` (`DnsRecordBlock.vue:127`, whose own comment
  says *"The token has no break opportunities of its own"*) and scanning `/domains` at 375px, where
  `E2eSeeder.php:1017-1047` guarantees a **64-hex** `verification_token` on an unverified domain:

  | leg | mutation | gate | result |
  |---|---|---|---|
  | 1 | applied | **unfixed** | **2 passed** — green over **312px** of real overflow |
  | 2 | applied | **fixed** | **2 failed**, `Received: 312` |
  | 3 | reverted | fixed | **71 passed** across the mobile shard |

  ⛔ **LEG 1 IS THE ROW.** `documentElement.scrollWidth - clientWidth` read **0** while
  `.app-shell__content` was overrun by **312px**, and a test literally named *"— accessible & no
  horizontal overflow"* passed, in both themes.
  **AS FIXED.** `assertNoHorizontalOverflow()` in `support/axe.ts` measures **two boxes**: the document
  (still the only real check where there is no shell — `AuthLayout`, `AdminLayout`, the guest runtime,
  `Welcome.vue`, and anything teleported to `<body>`) **and** `.app-shell__content`, which is
  `overflow-y: auto` (so `overflow-x` computes to `auto`) and `overflow: hidden` in the `--fluid`
  builder variant — **both mint a scroll container, unlike `clip`, so `scrollWidth` genuinely grows.**
  A third assertion fails loudly if `.app-shell` is present but `.app-shell__content` is not, so a
  renamed class cannot silently restore the blindness. `builder-axe.spec.ts`'s twin now calls the same
  helper instead of carrying its own copy — which is how it came to be inert in the first place.
  ⚠️⚠️ **THE ~40 COMMENT LINES WERE NOT MERELY OPTIMISTIC — TWO OF THEM MISATTRIBUTED REAL CATCHES, AND
  THAT IS THE FINDING ABOVE THE FIX.** `responsive-axe.spec.ts` claimed *"the 375px overflow trap that
  has now caught Domains and the Audit log"* and *"a non-wrapping row in a shared primitive has reddened
  this gate three times (H12b, H14, H15b)"*. **Checked: the clip landed 2026-07-21 in G11 (`506ff97`)
  and all three of those merged on 07-26/07-27** — six days later, with the assertion already inert. So
  those increments did go red, but on **axe violations**, credited to the neighbouring check. Corrected
  **in place rather than deleted**, because the misattribution is the more useful record: **a comment
  citing three specific saves is exactly what stops the next reader from testing the claim, and it is
  how a gate comes to be trusted.**
  ✅ **NO FALSE POSITIVES, MEASURED BEFORE THE GATE WAS WRITTEN.** A survey of 11 pages × 3 viewports
  found `.app-shell__content` overflow of **0 everywhere**, including the `Checklist` 44px `::before`
  overhang on `/dashboard` that `Checklist.vue:222-246` warns about by name (the top predicted risk),
  the builder under `--fluid`, and `MdsDataTable`'s desktop scrollers. `.app-shell__content` is present
  on every page, so the drift guard is not vacuous.
  ⚠️ **What it still cannot see, stated in the file rather than discovered later:** a **top-nav** overrun
  clips at `.app-shell` above the content region (`search-nav.spec.ts` measures bounding boxes for that),
  and an element that is its own scroll container legitimately absorbs its own overflow
  (`list-layout.spec.ts` owns that). This is a third instrument beside those two, not a replacement.
  ⛔ **`AuthLayout`, `AdminLayout`, the guest runtime and `Welcome.vue` carry no clip and were NOT
  touched** — the guest public-runtime scan was never affected, so its results across the whole
  thirty-six-day period stand.

  *The original row, preserved:* `tests/e2e/support/axe.ts:41-44`
  (twin at `tests/e2e/builder-axe.spec.ts:88-91`) measures
  `document.documentElement.scrollWidth > clientWidth + 1`, while `resources/js/Layouts/AppLayout.vue:147`
  sets `.app-shell { overflow-x: clip }` and `.app-shell__content` is `overflow-y: auto`, so its
  `overflow-x` computes to `auto`. **Any overrun clips or scrolls inside the content region and the
  document width never moves.** Delete `min-width: 0` from the leaderboard name cell
  (`resources/js/Pages/achievements/Index.vue:452-459` — the exact idiom `responsive-axe.spec.ts:24-28`
  says the scan protects) and every scan still passes.
  ⚠️ **Three files in the suite present it as working** — `support/axe.ts`, `builder-axe.spec.ts`, and
  `responsive-axe.spec.ts`, whose ~40 comment lines name this assertion as the reason those pages are
  scanned at all (`/domains`' 64-hex tokens, `/analytics`' legends, the audit-diff cells, `/feedback`'s
  long URLs). **The repo already knows better everywhere else**: `docs/ux/exceptions-log.md:646-651`,
  `tests/e2e/list-layout.spec.ts:10-17` and `search-nav.spec.ts:6-10` all record that the clip pins it
  flat. Only three element-level replacements exist (`list-layout.spec.ts:45-56`,
  `search-nav.spec.ts:89-100`, `personalization-axe.spec.ts:95-101`), covering three surfaces.
  ⚠️ **`AuthLayout`, `AdminLayout`, the guest runtime and `Welcome.vue` carry no clip**, so
  `auth-axe`, `admin-console-axe` and `public-runtime-*` are unaffected — do not "fix" them. **Live**, and
  the correction owed with it is the ~40 misleading comment lines, in the same row. Filing this is the
  single most valuable output of the review: it invalidates a gate this project has been trusting. Filed by `M1`.

### ✅ Found by the repaired overflow gate (M17) — ALL FOUR FIXED AND ALL FIVE ENTRIES DELETED (M19, 2026-08-26)

**`KNOWN_OVERFLOWING` is empty.** M17 quarantined five scan labels; M19 fixed the four underlying
defects and deleted all five. Three one-line CSS declarations did it, and each one is the escape the
file it lives in already used somewhere else.

| # | scan label | measured | cause | fix |
|---|---|---|---|---|
| 1 | `Submissions at extra_large + dyslexia font + teal` @375 | 17px | `.page-header__title` — a flex item whose `min-width: auto` **is** its min-content, i.e. one unbreakable word | `overflow-wrap: anywhere` (`PageHeader.vue`) |
| 2 | `Builder at extra_large + dyslexia font + teal` @375 | 24px | `.builder__title-row` — **not** the segmented control, see below | `align-self: stretch` (`Builder.vue`) |
| 3 | same, `— Add` @375 | 24px | same element, same cause | same one-line fix |
| 4 | same, `— Form` @375 | 24px | same element, same cause | same one-line fix |
| 5 | `Form hub` @834, both themes | 28px | the single word `"Accepting"` in `.mds-stat-tile__value`, 204px into a 123px column | `overflow-wrap: anywhere` (`StatTile.vue`) |

⛔⛔ **THE REASON THESE WERE QUARANTINED WAS WRONG, AND THAT IS WORTH MORE THAN THE FIXES.** M17 filed
rather than fixed them because *"none of them reproduces on a Windows host … a probe that inlined
OpenDyslexic as a data URI (`document.fonts.check` returned true) measured 0 overflow on all six page ×
viewport combinations."* **Both halves of that were true and the conclusion did not follow: OpenDyslexic
cannot reach the elements that failed.** `theme-overrides.css:404-406` re-points **only**
`--mds-font-family-body`, and its own docblock at `:389-395` says the Display role is untouched — while
`.page-header__title` and `.builder__title` are both `--mds-font-family-display`. The form hub had no
personalization at all. **The face that actually differs is the display stack's fallback**: with
`"Segoe UI Variable Display"` absent, `system-ui` resolves to Segoe UI on Windows and **DejaVu Sans** on
a CI runner, ~27% wider — measured at **256px against 324px** for the word "Submissions" at 48px/750.
**A probe that measures zero has told you nothing until you know it exercised the thing that broke.**

⛔⛔ **TWO OF THE FIVE ENTRIES WERE MISATTRIBUTED BY THE GATE'S OWN OFFENDER HEURISTIC, SO THE ROWS BELOW
INHERITED A DEFECT FROM THE TOOL THAT FILED THEM — AND BOTH BLIND SPOTS ARE NOW CLOSED IN `axe.ts`.**
- **It walked the descendants of scroll containers it skipped.** A box spilling inside an
  `overflow: auto` ancestor is *absorbed* and contributes **nothing** to the number being asserted, so
  naming it hands the reader a culprit that cannot be the cause. The reported *"30px
  `mds-segmented__seg`"* is `ConfigPanel`'s Requiredness control inside `.config`
  (`overflow-y: auto`) — filed on its own below. The 24px was always `.builder__title-row`. The walker
  now stops at a scroll container and reports absorbed spills last, explicitly labelled *not the cause*.
- **It iterated elements only, so an overflowing line box had nothing to report** and the message fell
  back to *"suspect an intrinsic minimum on a grid or flex track"*. On the form hub that was provably
  the wrong suspect: `.hub__tiles` is `repeat(auto-fit, minmax(200px, 1fr))`, and a **fixed** min track
  function resolves each tile's `min-width: auto` to 0, so no tile box can blow out. A `Range` over the
  text node now measures the line box directly and names the word.

✅ **AND THE ENVIRONMENTAL EXCUSE IS GONE: `docker compose run --rm e2e` REPRODUCES CI TO THE PIXEL.**
M19 measured **17 / 24 / 28** locally — CI's three numbers exactly — then **0 / 0 / 0** after the fixes,
from a Linux container with `fonts-dejavu-core` and the app serving **built, same-origin** assets. The
two prerequisites are documented on the compose service and in `README.md`, whose claim that *"Playwright
/ e2e run in Linux … so local and CI results match"* was **false as built** and is what made this class
of defect look unreproducible for a fortnight.

⚠️ **THE PREDICTION FLAGGED AS MOST LIKELY WRONG WAS RIGHT, WHICH IS WORTH RECORDING TOO.** The claim
said the three identical 24px readings *probably* shared one cause but that entries 3 and 4 had never
been measured against a fixed entry 2, because Playwright aborts a test at its first failed assertion.
One `align-self: stretch` retired all three — **and the guard was verified rather than inferred**:
`showBuilderPane` returns `false` if the pane switcher is hidden, which would have made two `assertClean`
calls silently vanish rather than pass. Measured at 375px: `switchVisible=true fields=true canvas=true`.

- **`minor` · `MdsSegmentedControl` spills 30px INSIDE the builder's config pane — a real horizontal
  scrollbar, and not the page-overflow defect it was filed as.** ⛔ **This row replaces the M17 row that
  claimed the control *"spills 30px out of the builder's content region"*, which is falsified**: it
  spills out of `.config` (`ConfigPanel.vue:546-553`, `overflow-y: auto`), which absorbs it, so it
  contributed nothing to the 24px that failed the scan. The offender is the **Requiredness** control
  (`ConfigPanel.vue:307-312`, `Optional / Required / Conditional` — no icons, non-compact, 24px of
  padding per segment) inside `.config__group`, which is a flex column with implicit `align-items:
  stretch`. ⚠️ **The mechanism the old row gave is also wrong and the error propagated by citation**:
  `white-space: nowrap` is **not** on `.mds-segmented__seg` — the only two in `SegmentedControl.vue` are
  the sr-only `legend` and `input`. The real construction is `inline-flex` with no `flex-wrap`, a `__seg`
  carrying neither `min-width: 0` nor `flex-shrink`, and `min-width: 0` on the fieldset, which does not
  mitigate the spill but **enables** it by removing the fieldset's floor. ⚠️ **It also falsifies J8's
  "measured every consumer" claim** (`exceptions-log.md` #13): `.config__group` here and
  `members/Index.vue`'s `MdsFormField` both have the stretch-clamped shape J8 concluded only the topnav
  had — J8 measured on a host where the wide face never loads, which is the same blindness this whole
  section is about. **Deliberately NOT fixed in M19**, filed at the moment that decision was made: the
  honest fix is a wrap or shrink affordance on a design-system component with **nine** consumers, and
  `flex-wrap` is foreclosed for the topnav instance (`.topnav` is a fixed 64px with `flex-shrink: 0`),
  so it needs its own increment with a story, a DSR note and a re-measure of every consumer under the
  Linux font stack. **Live**, and now reproducible locally. Filed by `M19`.

### Connectors & webhooks

- ✅ **CLOSED BY `M3` (2026-08-19) — `major` · ~~THE `webhook_deliveries` FAN-OUT PICKS ITS ENDPOINTS BY
  AMBIENT RLS ALONE.~~** Both dispatchers now take the scope from the event and establish it themselves,
  via a new `TenantContext::runFor()`. ⚠️ **THE ROW NAMED ONE CLASS AND THERE WERE TWO.**
  `ConnectorEventDispatcher` is the identical shape (`:39-63`) and is the twin that actually carries every
  Slack / Sheets / Airtable delivery this row blames on the webhook class — so fixing only the named file
  would have left the row's own sentence false. Both are fixed here (user decision 2026-08-19).
  ⚠️ **REPRODUCED BEFORE IT WAS FIXED, AND THE REPRODUCTION SPLIT THE ROW IN TWO.** Four new cases, two per
  channel, run against the unfixed dispatchers produced **two different failures**: with no ambient context
  (a worker's state exactly — `applyLocal()` dies at its transaction's commit and `ScopeTenantContextToJob`
  flushes the mirror) the fan-out created **zero rows, threw nothing and logged nothing**; with a *different*
  ambient tenant it raised **42501**, because the SELECT returned the other tenant's endpoints and the INSERT
  then stamped the event's `tenant_id`. **So the mismatch case was already loud and needed no fix — it is
  the UNSET case that was silent**, and the fix is aimed there rather than at the shape the row describes.
  Each case asserts a positive control *before* tearing the context down, so a zero-row result cannot be a
  mis-seeded factory.
  ⚠️ **THE OBVIOUS IMPLEMENTATION WOULD HAVE MASKED THE NEXT REAL EXCEPTION.** An *apply → work → restore-in-a-`finally`* shape issues SQL on the way
  out of a **failure**, where the connection may be in Postgres'
  aborted-transaction state and every statement fails — so the restore would throw and REPLACE the exception
  that caused it. `runFor()` separates the three exits instead: on a throw only the PHP mirror is put back
  (the database has already reverted itself); on a **nested** success the GUC is re-issued, because a
  `SET LOCAL` inside a savepoint SURVIVES its release and would otherwise hand the rest of the enclosing
  transaction a tenant it never asked for — the H12a sweep is exactly that; on an outermost success the
  COMMIT has already discarded it. It is the hazard `ScopeTenantContextToJob` avoids by never touching the
  database on the way out, met by a method that has to.
  ➕ **THREE THINGS THE ROW DID NOT NAME, ALL FIXED WITH IT.** (1) **Seven dispatch-listener docblocks gave
  *"a queued listener would run under a null tenant GUC"* as the reason they are not `ShouldQueue`** — a
  sentence this increment falsifies, so it is swept; after it, that phrase survives only at the two sites
  where it is still true (`FormOpened`'s `SerializesModels` note and `NotifyOnSubmissionCreated`).
  ⚠️ **AND A GREP FOR THE PHRASE IS NOT A CENSUS OF THE CLAIM** — an adversarial pass found two further
  listeners (`DispatchConnectorsForSubmissionApproved`, `…Updated`) asserting *"Never `ShouldQueue` — see
  the twin"*, which carries the retired reason by REFERENCE rather than by wording. Both are corrected in
  the same increment. This is the repo's own *name the thing, never quote it* lesson, met from the other
  side: the sweep matched a string and the claim outlived it. (2) Both
  dispatcher docblocks said **"four thin auto-discovered listeners"** where there are **eight** since I3 and
  I9c — the same drift, in the file that defines the behaviour. (3) `TenantContext::restoreMirror()`'s
  docblock named `ScopeTenantContextToJob` as its **sole** caller, which stopped being true here.
  ⛔ **THE LISTENERS ARE DELIBERATELY NOT FLIPPED TO `ShouldQueue`** — that is a behaviour change owed its
  own increment, and it is filed as its own row below rather than left invisible.
  **Gates:** four lint gates unchanged at **97 / 108 / 30 / 119** (M3 adds no controller, migration or job),
  Pint `passed`, `openapi.json` byte-identical, zero `.vue` / `.ts` / `packages/design-system/` / e2e movement. Filed by `M1`.

- ➡️ **MOVED TO `docs/claims/decisions.md` AS `D1` (2026-08-25) — IT IS A DECISION, NOT A DEFECT, AND NO LANE SHOULD TAKE IT AS A ROW.** An audit of all 62 open merge-gate rows confirmed this as the only genuinely cross-cutting one: the fix cannot avoid `scripts/job-payload-lint.php`, whose pass-1 scan of `app/` trips R1 on any listener implementing `ShouldQueue` and whose only escape is an `EXEMPT_JOBS` entry inside that script (a listener cannot extend `TenantAwareJob` — its `handle()` is `final`), and it must re-pin `tests/Feature/Connectors/ConnectorFanOutTest.php:163`, which **hard-asserts** these listeners are not queued. Nothing has decided that they should be. Original filing follows, kept because its reasoning is intact.
- **`minor` · All sixteen synchronous dispatch listeners could now be `ShouldQueue`, and nothing has
  decided whether they should be.** ⚠️ **The count is SIXTEEN, not the seven this row first said** — eight
  per channel, all synchronous; seven is merely how many carried the docblock sentence M3 retired. Filed by **M3 (2026-08-19)** at the moment the decision was taken, because a
  deliberately-unfixed finding that lives only in a commit message is invisible to any later backlog search.
  Until M3 the answer was forced: a queued listener found no tenant context and the fan-out silently matched
  nothing. `WebhookEventDispatcher` and `ConnectorEventDispatcher` now establish the event's own context, so
  queueing any of the sixteen is **safe** — the question is whether it is *wanted*. Arguments both ways, neither yet
  weighed: fan-out is two queries and an enqueue, so a synchronous listener costs a submission request very
  little and keeps delivery-row creation inside the request that caused it; against that, `form.opened` and
  `form.closed` fire inside the H12a sweep's per-tenant transaction, where a slow fan-out holds row locks
  taken by `lockForUpdate()`. **Nothing is broken either way** — this is a latency/locking trade, not a
  correctness one, which is why M3 declined to make it while fixing a correctness bug. Filed by `M3`. **Not live** — the corpus moved this out to a decision and says so in the bullet above it — an undecided question rather than a defect, judged by `M65`.

- **`minor` · Twelve existing tenant-context call sites restore in a `finally` INSIDE their transaction,
  which is the shape `TenantContext::runFor()` was deliberately built to avoid.** Filed by **M3
  (2026-08-19)**, and found by the adversarial pass on M3 rather than by writing it — the increment's own
  docblock had asserted *"each is correct"* about code it had not counted, let alone read. The shape:
  `$saved = currentTenantId()` → `DB::transaction(fn () => { applyLocal($target); try { … } finally {
  applyLocal($saved); } })`. If the work inside raises a **QueryException**, Postgres refuses every further
  statement on that transaction, so the `finally`'s own `set_config` fails **25P02** and becomes the
  exception the caller sees; the real error survives only as `getPrevious()`, and every top-level inspection
  — class, message, SQLSTATE — reads the wrong one. `runFor()` avoids it by restoring the PHP mirror only
  on the throw path, where the database has already reverted itself.
  **The twelve, measured rather than estimated** (`grep -rn "savedTenant = TenantContext::currentTenantId()\|previousTenant = TenantContext::currentTenantId()" app/`):
  `SendWelcomeEmail.php:109` · `ImpersonationService.php:119,241` · `SuperAdminService.php:125,162,257,702,748`
  · `GoogleAuthRequestService.php:82` · `TenantSettingRegistry.php:98` · `TenantMembershipService.php:314` ·
  `TenantExtractService.php:64`. **Latent, not live**: it needs the enclosed work to fail at the database,
  and each site's work is a narrow read or write that does not today. ⚠️ **Two of the twelve cannot simply
  become `runFor()` calls** — `SendWelcomeEmail:109` and `ImpersonationService:119` apply a specific user
  under a switched tenant, which `runFor()` deliberately nulls, so a retrofit needs a user-carrying variant
  or must stay hand-rolled. That is why this is filed rather than swept: it is twelve tenant-boundary call
  sites, and rewriting a working one is its own increment with its own gate run. Filed by `M3`. **Latent** — needs the enclosed work to fail at the database, and each site's work is a narrow read or write that does not today, judged by `M65`.

- ✅ **FIXED ON THIS BRANCH, recorded because it was the review's only surviving non-documentation-hygiene
  `blocker` and because it is the contract an integrator builds against.** The docs described a single
  `sha256=<hex>` in `X-Webhook-Signature` while `WebhookSigner::signatureHeaderFor()` comma-joins the new
  and previous signatures for the whole rotation grace window — so an integrator who implemented
  `hash_equals` straight from the docs would have had **every delivery silently rejected** from the moment
  a tenant first used `POST /api/v1/webhooks/{webhookEndpoint}/rotate-secret`, appearing only in
  production, only after an unrelated admin action, and looking like an attack. Both
  `docs/webhook-integration-design.md` §5 and `docs/architecture/technical-architecture.md` §7.2 now state
  the two-value form, that the current secret is first, and that a receiver must split on `,` and accept if
  any element matches.
- ✅ **CLOSED BY `M4` (2026-08-19) — `major` · ~~A SUCCESSFUL AIRTABLE DELIVERY WRITES THE RESPONDENT'S
  ANSWERS INTO THE DELIVERY LEDGER.~~** The row was right about the fix — `delivered($response->status(),
  'ok')`, matching `GoogleSheetsConnector.php:270-272`, whose class docblock states the property this broke:
  the shared ledger stores only the metadata envelope and never becomes a second copy of answer content.
  ⚠️ **REPRODUCED FIRST, AND THE REPRODUCTION FOUND WHY NO TEST HAD EVER SEEN IT — IT WAS THE STUB, NOT THE
  COVERAGE.** `fakeAirtable()`'s default write response returns a record **id only**, while Airtable's real
  create-record response **echoes the `fields` object just written**, so all twelve existing cases exercised
  a body with nothing to leak. Driven with the provider's real shape, the unfixed adapter wrote
  `{"records":[{"id":"recNEW0000000001","createdTime":"…","fields":{"Full name":"Ana Reyes","Colour":"b",
  "Submission ID":"sub-1"}}]}` straight into `response_body_excerpt`. The new case asserts a **control**
  first — that the response genuinely carries the answer — so a clean excerpt afterwards is the adapter's
  doing and not the stub's.
  ⚠️ **THE GDPR DOC'S CLAIM WAS NARROWER THAN THE PROPERTY IT SELLS, AND THAT IS WHY THE BREACH FITTED
  THROUGH IT.** §7's bullet (a) named **`webhook_deliveries.payload`** — which was always true — while the
  leak was in the sibling column `response_body_excerpt`, so the literal sentence stayed accurate the whole
  time the heading above it (*"the delivery ledger is not a second copy"*) was false. The clause now names
  the whole row rather than one column, which is what makes it checkable.
  ⚠️ **WHAT IS NARROWED RATHER THAN CLOSED, STATED SO NOBODY READS THIS AS FULLY DISCHARGED:** the
  retryable fall-through (`classifyFailure()`'s last line) still stores the provider body verbatim, **on
  purpose** — a 429 or 5xx body is the only diagnostic an operator has for an outage, and those statuses do
  not echo a payload; every arm a TENANT reads already replaces Airtable's copy with ours. **The residual is
  asserted as a PASSING test** in the 429 case, so sanitising that arm wholesale later fails loudly.
  **`excerpt()` is not orphaned** — `:391` and `:431` still call it, checked before the edit rather than
  discovered by a linter. Filed by `M1`.
- ✅ **CLOSED BY `M5` (2026-08-19) — `major` · ~~BOTH TABULAR ADAPTERS DO A NON-IDEMPOTENT WRITE AND THE
  RETRY LADDER RE-DRIVES IT.~~** Every link in the row held. ⚠️ **BUT THE FIX THE ROW NAMED DOES NOT EXIST,
  AND THAT IS THE FIRST THING THIS INCREMENT ESTABLISHED.** *"Send no idempotency token"* presumes a provider
  that accepts one: **neither does** — not Google Sheets `values.append`, not Airtable's create-record
  endpoint. A header both providers ignore would have *looked* like a fix and changed nothing, which is the
  same shape as the e2e overflow assertion three files describe as working. So the row was **rescoped on the
  evidence**: the only thing that can settle *did the first attempt land?* is a read of the destination, and
  the fix is a **read-back reconciliation on the retry** rather than a token on the write.
  ⚠️ **THE TRIGGER IS ALSO NARROWER THAN THE ROW SAYS, AND THAT NARROWNESS IS WHAT MAKES THE FIX SAFE.** A
  duplicate needs an *indeterminate* outcome — the request left, the provider had every chance to commit, no
  answer came back — which is only the `ConnectionException` arm. A 429, 403, 422 or 5xx is a **response**:
  the provider answered rather than silently committing. Until now all of them collapsed into one `failed()`
  and the ladder re-drove them alike. **Ten** duplicates would need ten consecutive committed-but-unanswered
  writes; the realistic count is **two**, because the retry that duplicates is usually the one that succeeds.
  ⚠️ **REPRODUCED BEFORE IT WAS FIXED, WITH THE FIXTURE THAT NOW GUARDS IT.** Driven against the unfixed
  adapter the reconciliation case reads `[$writes, $probes] === [2, 0]` — two identical records in the
  tenant's own base for one submission, no read-back attempted — and against the fixed one, `[1, 1]`. The
  guard is therefore proven by the mutation being the *absence of the fix*, not by an assertion count.
  ⚠️⚠️ **AND THE FIXTURE NEARLY TOLD THE WRONG STORY TWICE, BOTH TIMES IN M4's SHAPE.** (1) `Http::fake()`
  **invokes every stub for every request** and keeps the first non-null answer (`Factory::handler()` maps,
  then filters), so a counter inside one stub counts requests that stub never answered — the schema read
  inflated the probe count until the stub declined `/meta/` explicitly, and a probe count that is really
  "every GET" reads as a reconciliation that never happened. (2) `airtableMapping()` / `sheetsMapping()` both
  bind `__submission_id` by default, so **every** case would have exercised the reconcilable path and none
  the other one; the unmapped case builds its own mapping and asserts the duplicate it still permits.
  ➕ **AND ONE DEFECT IN THE FIX ITSELF, CAUGHT BY ITS OWN LAST TEST.** A probe that fails returned a plain
  `failed()`, which **cleared** `unconfirmed_write_at` — so the attempt *after* that one would have written
  blind, reproducing the very duplicate two attempts later and much harder to see. A failed probe now returns
  `unconfirmed()`, whose meaning is therefore *"the uncertainty persists"* rather than *"a write was just
  issued"* — the reason that factory takes a status at all.
  **Gates:** migration lint **108 → 109** (`2026_08_17_000106`), the other three unchanged at **97 / 30 /
  119**, `openapi.json` byte-identical, zero `.vue` / `.ts` / e2e movement. Filed by `M1`.

- **`minor` · A tabular rule that maps no Submission ID column still duplicates on an unconfirmed retry, and
  the durable fix is in the rule EDITOR rather than the adapter.** Filed by **M5 (2026-08-19)** at the moment
  the decision was taken. `__submission_id` is offered by `MappableColumnCatalog` and is **optional**, so a
  rule that does not bind it writes nothing identifying the submission and there is nothing for M5's probe to
  search for; the write proceeds and can duplicate exactly as before. ⚠️ **AND ONE SUB-CASE THE
  ADVERSARIAL PASS ADDED: the column can be MAPPED and still unfindable on Airtable.** `typecast: true`
  coerces a written value into the destination field’s type, so a tenant who mapped Submission ID onto
  a Number or Date field stores something that is no longer the uuid, and `filterByFormula` then matches
  nothing. Sheets has no symmetric hazard — `valueInputOption=RAW` lands the id verbatim. **Asserted as a passing test in both
  delivery suites**, so narrowing it later shows up as a failing test rather than as a surprise. Matching the
  whole projected row instead was **rejected on the merits**: two respondents answering a short form
  identically is ordinary, and a false match is a row that never arrives and nobody notices — trading a
  visible duplicate for an invisible loss. The fix is to make the editor pre-bind that column for a new
  tabular rule (and say why), which lands in `resources/js/Pages/` — **Lane A's column**. Filed by `M5`. **Live** — reachable today: a rule mapping no Submission ID column has no dedupe key, so an unconfirmed retry appends a second row, judged by `M65`.

- **`minor` · M5's reconciliation asks "is this SUBMISSION in the destination", not "is THIS DELIVERY's row in
  the destination", so two rules writing one submission to one table can collapse to a single row.** Filed by
  **M5 (2026-08-19)**, found by its own adversarial pass rather than by writing it, and **unfixable from the
  adapter rather than overlooked**: nothing we write identifies the DELIVERY — the row carries the mapped
  columns and nothing else — so there is no delivery-shaped thing to search for. Reachable only when two
  rules on one connection target the SAME table with `__submission_id` mapped (a `submission.created` rule
  and a `submission.updated` one, say). That tenant gets two rows by design today; if the second rule's write
  then loses its answer, its retry finds the FIRST rule's row and settles, so the pair collapses to one.
  **Narrow, and in the safe direction** — one row too few beats an unbounded ladder of duplicates — but it is
  a behaviour change beyond the one M5 exists for. The fix would be a column carrying the delivery id, which
  means writing into a column the tenant did not map, so it is a rule-editor question rather than an adapter
  one. Revisit if a tenant reports a missing row on a table fed by two rules. Filed by `M5`. **Latent** — needs the probe to be fired from the one path that can settle a delivery on another row, judged by `M65`.

- **`minor` · A 5xx that arrives AFTER the provider committed is still re-driven.** Filed by **M5
  (2026-08-19)**. M5 treats a received HTTP status as determinate, because both providers' contracts say a
  5xx means the write was not applied, and routing the far more common arm through an extra read to guard the
  exception would cost every transient error a round trip. **Latent, and strictly narrower than what M5
  closed**: it needs the provider to commit and *then* answer 5xx. Revisit if a tenant ever reports a
  duplicate whose delivery row carries a 5xx rather than a `[transport_error]` excerpt. Filed by `M5`. **Latent** — needs the provider to commit the write and then answer 5xx, which nothing in this tree can produce, judged by `M65`.

- **`minor` · `SlackConnector::deliver()` has the same non-idempotent shape and is deliberately not covered.**
  Filed by **M5 (2026-08-19)**, and named in the adapter's own docblock rather than left to be discovered.
  `chat.postMessage` accepts no idempotency key either, so a lost answer followed by a retry posts the
  message twice. Out of scope **on the merits**: a repeated chat message is noise a human dismisses in the
  channel it arrived in, where a repeated spreadsheet row silently biases every count taken over the tenant's
  dataset. And the fix would not be M5's — asking Slack "did my message land?" means reading channel history,
  a scope this connector does not request and should not acquire to dedupe its own retries. Filed by `M5`. **Live** — reachable today and declined on the merits rather than absent: a lost answer followed by a retry posts the message twice, judged by `M65`.
- ✅ **CLOSED BY `M6` (2026-08-19) — `major` · ~~AN IRREVERSIBLE PROVIDER-SIDE TOKEN ROTATION IS COMMITTED
  INSIDE A ROLLBACK-ABLE TRANSACTION~~ AND `major` · ~~`ensureFresh()` TAKES NO LOCK~~.** Taken together
  because they are **one mechanism, not two**: both are answered by making "refresh one grant" a single
  locked, immediately-committed critical section, and shipping them apart would have meant rewriting the same
  code twice. Every cited link held.
  ⚠️ **THE ROW NAMED ONE PATH AND THERE WERE TWO — M3's SHAPE EXACTLY.** It blamed `sweep()`, but
  `ensureFresh()` ran inside **`DeliverConnectorMessageJob`'s** transaction as well, and that one is worse:
  the sweep does nothing after a rotation but rotate the next grant, while the delivery job goes on to make up
  to three more outbound calls, any of which can throw or eat the 60s `$timeout`. Fixing only the named path
  would have left the row's own sentence true and the defect live.
  ⚠️ **AND THE TRANSACTION COULD NOT SIMPLY BE REMOVED**: `TenantContext::applyLocal()` issues `SET LOCAL`,
  so RLS scoping dies with it. The fix is a per-connection commit **seam** — `RefreshOneConnectionJob`, whose
  transaction body is nothing but its own write — rather than "move the write out".
  ⚠️ **REPRODUCED BEFORE IT WAS FIXED, AND THE MUTATION IS THE ABSENCE OF THE FIX.** Two cases drove
  `TenantAwareJob`'s exact transaction shape, rotated two Airtable tokens, threw, and showed the database
  rolled back to credentials Airtable had already destroyed — then showed the next sweep killing the
  connection, clearing its tokens and pausing its rules. Kept and inverted as the guards. `git stash push --
  app/` turns **six** of them red, including all three of M6's claims.
  ➡ **TWO THINGS THE TESTS CAUGHT IN THE FIX ITSELF.** (1) The new dead-connection branch was **unreachable**:
  `markDead()` pauses every rule on the connection, so the paused-rule guard always fired first — the very
  dead-code-that-looks-live shape this increment was replacing. The grant is now checked **before** the rule,
  because they are different kinds of thing: a dead grant is terminal and is settled, a paused rule is
  reversible and stays silent so an un-pause resumes its queued deliveries. (2) That ordering also fixed a
  **latent loop nobody had named** — see the row below.
  **Gates:** CI Pest **4444 / 18,788 → 4446 / 18,822**; the connector suite alone **244 → 246** (930 assertions). ⚠️ **The first draft of this line said 228 → 246, which was wrong: 228 was measured on `origin/main` BEFORE M5 merged, so it credited M6 with M5’s sixteen cases.** Measure against a run of your own base — the delta is small because most of M6’s test work REWROTE existing cases to the new contract rather than adding new ones. PHPStan **18**, delta zero and none of its
  nine files touched here; lint **97 / 109 / 31 / 119** (the new job moves the job count alone);
  `openapi.json` byte-identical. ADR-0009 **§D6 amended in place** — no new ADR number spent. Filed by `M1`.

- ✅ **CLOSED BY `M6` (2026-08-19), AND IT WAS NEVER FILED — `major` · a delivery against a REVOKED grant was
  re-dispatched every five minutes forever.** Found by following M6's own change through rather than by
  looking for it. `DeliverConnectorMessageJob::handleForTenant()` returned silently for a dead grant, leaving
  the row `failed` with its `next_retry_at` set and `attempt_count` untouched — so `WebhookRetrySweeper`'s
  `attempt_count < max_attempts` predicate stayed true and the sweep re-queued the same delivery every five
  minutes for the life of the row. **Masked, not absent**: the pre-flight refresh used to catch the revocation
  one attempt earlier and dead-letter it, so the loop had never been reachable in practice. Moving that
  refresh into its own job would have made it the only path — which is how a fix for one defect surfaced
  another. Now settled with `[grant_expired]`, asserted by its own case. Filed by `M6`.

- **`minor` · A rotated token can still be lost in the one-UPDATE window M6 left.** Filed by **M6
  (2026-08-19)** at the moment the decision was taken. The gap between the provider committing a rotation and
  us committing the write is now one UPDATE wide instead of a whole batch, but it is not zero: a database
  failure in exactly that gap still leaves Airtable holding a pair we never stored. Closing it entirely needs
  a two-phase protocol **no provider here offers** — a rotation the client can confirm, or a grace period in
  which the previous refresh token still works. **Revisit trigger: the first provider that offers either.**
  Recorded in ADR-0009 §D6's M6 amendment as well, so the residual is visible from the decision and not only
  from the backlog. Filed by `M6`. **Latent** — needs a database failure inside the one-UPDATE window between the provider committing a rotation and us storing it, judged by `M65`.

- **`minor` · The setup-time directory has no pre-flight refresh**, so an ordinary token expiry tells the
  tenant to reconnect a healthy account — `app/Services/Connectors/TabularDestinationDirectory.php:46,68`,
  the one place H16a's guard was not applied. **Latent** on a missed sweep (H16a's own premise). Filed by `M1`.
- ✅ **CLOSED BY `M66` (2026-09-03) — `minor` · ~~`ConnectorRulePausedNotification` is the only tenant-facing
  connector email with no brand.~~** The send is at `app/Jobs/Connectors/DeliverConnectorMessageJob.php:382-383`
  and now carries `->withBrand(BrandPalette::forTenantId($this->tenantId))`, matching its branded sibling 23
  lines above. Filed by `M1`.
  ⛔ **THE ROW'S "ONE ARGUMENT" REMEDY WAS FATAL, NOT MERELY INCOMPLETE.** The class did not use
  `CarriesTenantBrand` at all, so the prescribed call was `Call to undefined method`. Four edits: the trait,
  the `branded()` wrap in `toMail()`, the dispatch-site argument, and the fourth below. And the loss was
  template-level rather than colour-level — `branded()` supplies `markdown('mail.notification')` **and**
  `theme('meridian')`, and `config/mail.php` sets no global theme, so this class rendered the framework's
  default shell.
  ⛔ **THE ROOT CAUSE WAS A TWO-LIST DRIFT, AND IT IS THE HALF WORTH REMEMBERING.**
  `scripts/job-payload-lint.php`'s EXEMPT_JOBS had carried this class since H16a; `QueuedMailContractTest`'s
  list never did — and it is that test which asserts the trait is present, so the one gate that would have
  caught this was the one gate blind to the class. Its own docblock already warned that adding a queued mail
  notification means adding it in **both** places *"or two separate gates fail"*. Eleventh entry appended.
  ⚠️ **The citation `:330` was never right in this row's life** — the site was `:325` when introduced
  (`f5ec530`), `:343` after `M5`, `:383` since `M6`. `:330` is a blank line in a different method.
  Proven by `MU3` (the argument removed → the new brand assertion red) and `MU4` (the trait removed →
  `QueuedMailContractTest` red), each reddening only its own arm.
- ~~**`minor` · Two hand-maintained lists of queued mail notifications must agree, and nothing checks that they
  do.**~~ `tests/Feature/Mail/QueuedMailContractTest.php`'s `$queuedMailNotifications` and
  `scripts/job-payload-lint.php`'s `EXEMPT_JOBS` are both edited by hand, and the contract test's docblock
  says in terms that adding a class means adding it to **both** *"or two separate gates fail"*. ⛔ **That is
  a description of what already happened rather than a warning about what might**: `ConnectorRulePausedNotification`
  sat in EXEMPT_JOBS and not in the test list from H16a until `M66`, which is exactly why it reached
  production without `CarriesTenantBrand` — the arm asserting the trait could not see the class. `M66`
  appended the eleventh entry and **did not close the gap**: the lists are still hand-maintained and still
  unproven against each other. The cheap form is an arm in `QueuedMailContractTest` that parses EXEMPT_JOBS
  and asserts the two sets are equal, which turns a silent divergence into a red test naming the missing
  class. ⚠️ **Check the direction of the assertion before writing it** — EXEMPT_JOBS legitimately contains
  non-notification jobs, so it is the notification subset that must match, not the whole constant.
  Filed by `M66`.
  ✅ **DONE — M69 (2026-09-04). THE ROW'S REMEDY WAS SOUND AND SHIPPED AS WRITTEN, AND ITS ONE WARNING
  WAS THE THING WORTH HAVING.** `tests/Feature/Mail/QueuedMailContractTest.php` gains the reverse
  direction: it parses `EXEMPT_JOBS`, filters to the notification subset and compares both sets.
  ⚠️ **The warning about the filter was right for a reason the row did not give.** It says EXEMPT_JOBS
  *"legitimately contains non-notification jobs"* — **today it contains none**, all eleven entries are
  notifications. So whole-set equality would have passed and been wrong in principle, and a filter
  written on that basis alone would have been untestable. It is filtered on the framework's
  `Notification` base class and proved against a REAL job (`SweepWebhookRetriesJob`).
  ⛔ **AND THE DISCRIMINATOR IS THE TRANSFERABLE HALF.** The control that adds a real job and expects
  GREEN proves nothing on its own — a regex that never harvested the new entry produces the same green.
  A second control puts a NON-EXISTENT class in the same slot and expects RED; the pair is what
  separates "the filter excluded it" from "the parser never saw it" (the `M49` shape).
  ⚠️ **Two defects in the arm's own first run, both kept as comments at the site**: the const block is
  more comment than code and one comment quotes `Notification::route('mail', …)`, so harvesting every
  quoted string picked up `'mail'`; and `class_exists` asserted per entry reports *"failed asserting
  that false is true"* and names nothing.

### Submissions, drafts & the guest runtime

- ~~**`major` · `AttachmentPolicy::view()` is flat where `SubmissionPolicy::view()` is scoped, so an id
  defeats every per-form boundary.**~~ ✅ **DONE — M33 (2026-08-26). Every file:line in this row was correct,
  which is the first time in fifteen increments the evidence half needed no correction. Its one-sentence
  REMEDY was wrong, and that is the transferable finding.** `AttachmentPolicy::view()` is now an exhaustive
  `match` over all nine kinds with **no `default` arm** — so PHPStan reports a tenth kind rather than
  absorbing it, which is what absorbed the seventh and produced M29. The five submission-domain kinds require
  `submissions.view` **and** their owner's scope; the submission arm **delegates to `SubmissionPolicy::view()`
  through the Gate** rather than copying its predicate, so a third surface agrees by construction instead of
  by review. ADR-0015 gained **§D10**, continuing §D6/§D9's own thread. **20 passed / 36 assertions** in
  `tests/Feature/Attachments/`.
  ⛔ **THE ROW'S PRESCRIBED FIX — *"resolving each kind's owner through the morph map"* — IS THE ONE MECHANISM
  A TEST EXISTS TO FORBID.** `attachable_type` carries five aliases and only three are registered;
  **`tenant` and `feedback_report` are deliberately absent**, because registering them would change how
  Sanctum's `tokenable_type` and Spatie's `model_type` serialize and split existing rows between alias and
  FQCN — the `enforceMorphMap` break that cost 90 test failures. `tests/Feature/Branding/BrandingMorphAliasTest.php`
  exists solely to pin that absence, **and prescribes this increment's design by name**: *"a LOCAL resolution
  (a match on `kind`, or a dedicated relation), never a global registration."* Nothing in the policy touches
  `$attachment->attachable`. ⚠️ **A ROW'S EVIDENCE AND A ROW'S REMEDY ARE SEPARATELY TRUSTWORTHY, AND THE
  REMEDY HALF IS CHECKED FAR LESS OFTEN** — Lane A found the identical shape in `M32`'s row the same day
  (*"REAL, and the row's own prescribed fix does not work"*), so it is two for two from two lanes on two
  unrelated rows.
  ⚠️ **THE ROW ENUMERATED FOUR REACHABLE KINDS AND THERE ARE SIX.** A census run on the RESOURCE — every
  `AttachmentKind::` producer under `app/` — returns five production writers, and the one the row never names
  is the **brand logo** (`AttachmentStorageService.php:148`, owner alias `tenant`). It is deliberately
  **not** narrowed and **not** scoped: `GET /branding/logo` serves the same bytes *unauthenticated* to email
  clients, so tightening this route would protect nothing already private. `OcrSourceScan` and `Avatar` are
  declared and produced by nothing; `Avatar` **fails closed** rather than inheriting a submission scope that
  is meaningless for a file owned by a user.
  ⚠️ **ONE KIND WAS A PRODUCT QUESTION RATHER THAN A SCOPING — FILED AS `D4` IN `docs/claims/decisions.md`.**
  An archived webhook envelope is owned by a delivery, not a form: it is the full payload of whatever form
  fired it, so it crosses every per-form boundary at once and there is nothing to scope it *to*. It moved
  from `submissions.view` (all five roles) to **`webhooks.manage`** (Owner/Admin) — a NARROWING, which J2d
  says belongs to the user, so it is recommended-and-implemented with the revert named rather than decided
  silently. The row's own note that this kind is written `ScanStatus::Skipped` *"under a comment asserting it
  is never served to a browser"* is what made the kind findable at all.
  ⚠️ **WHAT NO LONGER WORKS, DELIBERATELY.** The *"your PDF is ready"* link (`GeneratePdfJob:175`) points at
  this route. Its recipient necessarily passed `SubmissionPolicy::export()` — the same scope plus
  `submissions.export` — so every legitimate link still resolves. **The links that stop working are exactly
  the ones held by someone since removed from the form**, which is the defect the row calls *revocation does
  not revoke*.
  ⚠️ **AND A FIXTURE THE FIX QUIETLY INVALIDATED, WHICH IS WHY THE SUITE IS THE GATE.** `Attachment::factory()`
  defaults to a `form_field` alias with a **random uuid**; M29's positive control used it, and under a policy
  that resolves the owner it would 403 for every role and prove nothing about permissions. Both M29 cases now
  own real rows. A green suite after a scoping change is not evidence until the fixtures are checked for
  owners that never existed. Filed by `M29`.
- ~~**`major` · `promote()` reads the answer document before it takes the lock, and a concurrent autosave is
  terminally lost.**~~ ✅ **DONE — M12 (2026-08-25). The row was true verbatim in all seven of its claims, and
  the reach it describes is not the reach the fix has.** `SubmissionDraftService::promote()` now captures the
  answer document's `answers_content_checksum` off the SAME row it reads the answers from and re-compares it
  **under the lock**, refusing with the `409 draft_conflict` the save door has raised since P3a — one code,
  one sentence, both write doors, so no client contract moved and `openapi.json` stayed byte-identical.
  It is the sibling's SECOND check (`SubmissionAnswerEditService::edit()`), unconditional because it compares
  two SERVER reads inside one request rather than a client's claim. **The three services now hold one check
  each, and each holds the one its own read/lock ordering makes authoritative.**
  ⚠️ **WHAT THE ROW DID NOT NAME.** (1) **`promote()` has FOUR call sites, not the one the row describes** —
  `GuestSubmissionController:88`, `SubmissionController:131` (draft branch), `SubmissionController:144` (the
  R1 race backstop) and `Api\V1\SubmissionPromoteController:28`. (2) **The damage was never only a missing
  answer.** `FinalizedStatus::for()` is handed the PRE-LOCK `SemanticResult`, and its own docblock demands
  *"this finalize's OWN Stage-3 result — never a cached or borrowed one"*: a racing save that routes the
  respondent into a section is the difference between `submitted` and `screened_out`, and therefore between
  consuming a purchased `max_responses` slot and not — plus a row labelled *"was shown no questions"* about
  somebody who answered some. (3) **Media too** — `attachment_refs` and the attachment ownership re-point
  derive from the same pre-lock `$final`, so a file the second device uploaded stayed owned by its
  `form_field` and unreferenced by the finalized submission. (4) The version-status check has the identical
  pre-lock shape one field over — filed below rather than folded in. (5) A sweep of every `lockForUpdate()`
  in `app/` found **no other pre-lock-read → whole-document-write site**: `SubmissionPipeline::persist()`
  creates its row inside the transaction, and `PublishService`, `RestoreService`, `FormBuilderService`,
  `FormService`, `ScopeNodeService` and `SubmissionReviewService` all read after their lock. `promote()` was
  the last door.
  ⚠️⚠️ **AND THE ADVERSARIAL PASS CORRECTED THE ROW'S OWN FRAMING, WHICH IS THE FINDING WORTH CARRYING.**
  The row motivates the defect with the two-device resume, and **on that flow P3a's baseline check usually
  fires first**: both DRAFT channels set `checkBaseline: true` unconditionally
  (`GuestDraftRequest`/`EncodeDraftRequest` both say so in writing), so a second device saving from a stale
  base is refused before it can move anything. The windows M12 **uniquely** closes are therefore narrower and
  different: **(a) `/api/v1/submissions/{submission}/promote`, which runs no `saveDraft()` at all and so has
  never had a first check** — and is the seam the OCR review-and-confirm flow is documented to reuse;
  **(b) a SUBMIT that sends no baseline**, because the submit requests gate on `claimsBaseline()` rather than
  unconditionally, which is the live shape of the already-filed 409-folding row (`App.vue` remounts with
  `draftBaseline = null`); **(c) `createDraft()`'s 23505 fold**, which passes `checkBaseline: false`
  explicitly and by design; and **(d) the genuine sub-window on any channel** — a device that read AFTER this
  request's own `saveDraft()` holds a current base, so its autosave is accepted and lands in the tens of
  milliseconds Stage 3 and the media check occupy. **A row can be true in every clause and still point at the
  wrong flow.**
  ⚠️ **MUTATION PASS: 7 MUTATIONS, ZERO UNDEFENDED, AND TWO OF THEM CHANGED THE TESTS.** M1 (delete the
  guard — the defect reintroduced) reddens **exactly the seven refusal cases** and leaves both controls
  green. M2 (run the checksum check BEFORE the status re-assert) reddens **exactly one**, the
  idempotent-no-op case — so the ORDER is pinned: a concurrent promote moves the status and the checksum
  alike, and that case is a documented 200 rather than a 409. M3 (compare the recomputed hash instead of the
  stored value — the plausible-wrong token) reddens **two, one of them a PRE-EXISTING test**
  (`EncodePromoteTest`'s R1 race case, whose planted row carries a null checksum). M6 (a semantically
  identical read) survives, as a control must. ⚠️ **M4 (loose `!=`) SURVIVES BY CONSTRUCTION** — the domain
  is 64-hex-or-null, exactly M11's finding, and it stays strict because that equivalence is a property of the
  DOMAIN rather than of the operator. ⛔⛔ **M5 (hoist the guard out of the transaction) INITIALLY REDDENED
  THE SAME SINGLE CASE AS M2, WHICH MEANT "the comparison reads UNDER THE LOCK" WAS ENFORCED BY A COMMENT
  AND NOT BY A TEST** — every existing case stages its write during Stage 3, which a hoisted check still
  sees. A case staging the write AFTER the lock is granted separates them, and it states what it cannot
  measure: its writer takes no `submissions` lock, which no production path does, so it pins a property of
  the code rather than a reachable race; and its write shares promote's own transaction, so the refusal rolls
  it back and the *"the other device's answers survive"* assertion belongs to the pre-lock cases instead.
  ⚠️ **AND ONE ASSERTION WAS WRONG BEFORE IT SHIPPED, IN THE DIRECTION THIS PROJECT KEEPS PAYING FOR**: two
  channel cases compared the surviving document with `toBe()`, which is KEY-ORDER sensitive on arrays, and a
  jsonb round-trip returns the database's order — the identical trap `SubmissionAnswerEditService::sameAnswer()`
  documents for the audit diff. They ksort first and stay strict on the values.
  Recorded in `docs/offline-first-sync-design.md` §8, whose P3a amendment claimed the hazard closed while
  naming only one of the two write doors. **No ADR, no migration, no route: `openapi.json` byte-identical,
  four lint gates unmoved at 97 · 111 · 31 · 111/119/0, PHPStan 18 = baseline with zero delta by file list.** Filed by `M1`.
- ~~**`major` · Two unscoped copies of `findByClientUuid()` survive the branch that declared the unscoped
  form an authorization defect.**~~ ✅ **DONE — M11 (2026-08-24). The row was true verbatim and named four
  fewer things than it should have.** As built there is now exactly ONE implementation —
  `app/Services/Submissions/ClientUuidResolver::resolve()`, scoped to `(tenant_id, form_id, respondent,
  uuid)` — consumed by every channel, and `tests/Feature/Submissions/ClientUuidScopeTest.php` fails the
  build if the predicate is ever written a second time anywhere under `app/`. A uuid spent inside the tenant
  but outside the caller's scope is now an explicit `409 submission_conflict` (*"This submission identifier
  already belongs to another response."*) — the code and message `SubmissionDraftController` has returned
  for this exact cause since I9b, so no client contract moved and `openapi.json` stayed byte-identical.
  ⚠️ **WHAT THE ROW DID NOT NAME.** (1) **The guest DRAFT route carried the identical unauthenticated 500**
  (`GuestDraftController` → `saveDraft()` → `createDraft()` → an unclassifiable 23505 → three wasted
  transactions → an escaped `QueryException`); the row named only the submit route. (2) **The unscoped
  resolve had three consuming channels, not one** — guest submit, the authenticated encode page and
  `/api/v1/sync` — so "three call sites" was a count of FILES. (3) **On the encode channel it was a WRITE:**
  `SubmissionController::store()`'s race backstop promotes whatever Stage 2b returned, so a member entitled
  to form A could FINALIZE form B's draft — audit row, capacity slot, `SubmissionCreated` and all — on a
  form they may hold no grant on, because `promote()` is invoked directly and never re-runs
  `SubmissionPolicy::promote()`. (4) **A soft-deleted row keeps its uuid reserved and is invisible to every
  resolve**, because the partial unique index filters on `client_submission_uuid IS NOT NULL` and not on
  `deleted_at IS NULL` — latent, not live (`ReapTenantDraftsJob` hard-deletes for exactly this reason), and
  now closed by a `withTrashed()` probe whose premise is pinned against the live `pg_indexes` definition.
  ⚠️ **AND TWO TESTS WERE PASSING FOR THE WRONG REASON.** `EncodePromoteTest`'s R1 race case STAGED THE
  DEFECT — it planted the draft under a different user and relied on "the pipeline still resolves it by uuid
  alone", i.e. it asserted that a member may promote a stranger's draft; it now stages the real interleaving
  (the autosave commits between the two reads) via a one-shot `DB::listen`, and asserts the listener fired.
  And the first draft of M11's own new cases asserted only `toThrow(SubmissionConflictException::class)`,
  which the mutation pass proved passes for `contentConflict()` too — every refusal test now asserts the
  MESSAGE, which is the only thing separating the two causes on the wire. Recorded in
  `docs/offline-first-sync-design.md` §5 and `docs/security-threat-model.md` §4. Filed by `M1`.
- ✅ **CLOSED BY `M14` (2026-08-25) — `major` · ~~The guest runtime folds P3a's `409 draft_conflict` into the
  generic `refresh` kind, so the refusal becomes a second submission.~~** AND ~~**`major` · On the draft-save
  channel the same 409 is swallowed with no message at all.**~~ **Taken together because they were one
  mechanism in one component**, and shipping them apart would have written the same fold twice. Every
  `file:line` in both rows verified against the code first; all true in substance, three carrying line drift
  (the classifier's 409 arm is `error-normalizer.ts:101-103`, not `:93`, which is a comment inside the I8b
  `challenge` block; the submit fold is `handleSubmitError()` at `RuntimeSession.vue:172-205`, not the
  `:275-282` call site).
  ⚠️ **THE ROWS NAME TWO FOLD SITES AND A SWEEP OF `resources/public-runtime/` FOUND TWELVE** — nine on the
  409 path (`error-normalizer.ts:101` · `RuntimeSession.vue:160/182/354` · `replay.ts:223/291` ·
  `App.vue:43` · `outbox-status.ts:130` · `outbox.ts:160`) plus three consumers. **Grepping the shape rather
  than trusting the row's count is six-for-six** (M8, M9, M11, M12, M13, M14).
  **As built:** the classifier reads `error.code` on a 409 exactly as it has read it on a 403 since I8b, and
  four causes gained four `ErrorKind`s — `draft_stale`, `conflict`, `uuid_claimed`, `finalized`. `refresh`
  keeps its meaning and stays the default for an unrecognised or bodyless 409. `draft_stale` routes through
  `App.vue`'s existing `loadResume()`, the H10 machinery that already keeps the draft's uuid, reconciles
  under the user-locked newest-wins precedence and always takes the SERVER's checksum — so the remedy the
  server has named since P3a is finally the one the client performs. `RuntimeSession` stopped discarding the
  `resume_token` every save returns, which is what the re-read needs and what nothing had noticed was thrown
  away. Recorded in `docs/offline-first-sync-design.md` §5 and §8.
  ⛔ **`replay.ts` WAS A FORCED NEIGHBOUR AND ITS FIX IS A TYPE, NOT A TEST.** Its `refresh` arm was the only
  site preserving the server's code; minting kinds without it would have dropped `draft_conflict` into
  `backoff()` — five POSTs of the identical stale baseline the server had already refused. The outcome table
  is now a `Record<ErrorKind, …>`, so vue-tsc **refuses to compile** the next kind until that table names
  its outcome; proved live by adding a thirteenth kind and watching `replay.ts:48` go red.
  ⛔ **`outbox-status.ts` WAS THE OTHER FORCED NEIGHBOUR, AND ITS DEFECT WAS ALREADY LIVE.** It hardcoded
  *"the form changed after this was saved"* for every cause while `App.vue`'s review banner — reached by the
  Review button **on that same row** — said something else for a content conflict. Two copies of one
  decision, in two files, disagreeing. Both now read `lib/conflict-notice.ts`.
  ➕ **AND THE EMAIL SWALLOW WAS WORSE THAN "IGNORES `null`", WHICH ONLY MOUNTING THE COMPONENT REVEALED.**
  `onEmail` sets `errorMessage`, but the paragraph rendering it was the `v-else` of `phase === 'ready'` — and
  `onEmail` only ever runs *while* phase is `ready`. **The sentence was unreachable markup**: a failed
  "Email me the link" left the panel looking exactly as it had before the click, and the pre-M14 assignment
  was dead code. `SaveForLater` had never been mounted by any test.
  ⚠️ **THE THREE-WAY SPLIT THE ROW ASKED FOR WENT FOUR WAYS**: `draft_already_finalized` is a fifth
  guest-reachable 409 (`GuestDraftRuntimeTest.php:288` has asserted it since H9a) that also got the false
  republish sentence. Leaving it folded while touching the line above it would have been a classifier that
  handled four of five causes and silently mis-handled the fifth. Filed by `M1`.
- ✅ **CLOSED BY `M14` (2026-08-25) — the parked decision M11 left to this row: `clientUuidClaimed()` now
  carries `409 submission_uuid_claimed`.** M11 kept it sharing `submission_conflict` and wrote its reason
  down — *"the guest runtime folds all 409s alike today (its own filed row), so a new code would buy nothing
  and cost a contract"* — leaving the call to whoever fixed the fold. **M14 is that row, so the premise is
  gone.** Without the split a client must tell two causes apart by string-matching a human-facing sentence,
  which `SubmissionConflictException` itself names as the trap that makes a test pass for the wrong cause.
  **The draft channel is the clearest evidence:** `contentConflict()` is suspended for drafts, so on
  `POST /api/v1/public/f/{shareToken}/draft` the shared code could only ever have meant the entitlement
  cause, and no client had any way to know that. ⚠️ **Two pre-existing assertions were passing for the wrong
  reason and the mint is what exposed them** — `EncodeDraftTest.php:279` and `:307` asserted `error.code`
  alone on the claimed cause, exactly the code-only shape M11's docblock warns about; both now assert the
  code **and** the message. `openapi.json` stayed byte-identical, measured rather than predicted.
- ✅ **CLOSED BY `M66` (2026-09-03) — `minor` · ~~`RuntimeSession.handleDrift()`'s bare `catch {}` collapses
  every recovery failure into one sentence.~~** The error is bound and split three ways, transcribed from
  `App.vue`'s own handling of the same `remint()` → `fetchSchema()` pair. The terminal arm keeps the original
  sentence **byte-identical**, which makes this a narrowing rather than a rewrite. Filed by `M14`.
  ✅ **THE ROW WAS RIGHT AND THE CODEBASE ALREADY AGREED WITH IT.** *"…no longer available"* has three
  first-party sites and the other two are bound to a real 404 — `GuestDraftResumeController.php:48` emits it
  as `404 draft_not_found`, and `App.vue:236-242` emits its variant only behind `kind === 'terminal'`. This
  was the sole site saying it without having established the cause, and the fix needed nothing new:
  `error instanceof ApiError` is the discriminator this component already uses 76 lines below the defect.
  ⚠️ **Its citation had rotted by 33 lines** — `handleDrift` is at `:193-201`; `:160-168` is the `onMounted`
  restore block. The figure was copied from the **closed** row's pre-`M14` sweep list and never re-measured.
  ⚠️ **And *"a fourth fold site"* mixes two taxonomies**: the closed row's fold sites fold *409 causes*; this
  folds *transport vs HTTP*, where it is the **third** unbound `catch` of the `SaveForLater.vue` species.
  ⛔ **THE ROW UNDERSTATED ITSELF, AND THE OBVIOUS COPY WOULD HAVE SHIPPED A SECOND FALSE SENTENCE.**
  `handleSubmitError` discards the durable outbox row **before** calling `handleDrift`, and a resolving
  session has autosave disabled by construction — the parked row *was* its durable copy. So in a conflict
  review the reviewed answers exist in memory only, and *"your answers are saved on this device"* would be
  the same error pointing the other way. The network arm branches on `props.resolving` for exactly that. The
  lifecycle defect itself is filed below rather than fixed here.
  Proven by a hand-rolled control (`scripts/mutate.php` is Pest-only): `MU7` reverting the fix reddens the
  network **and** resolving cases while the terminal case stays green; `MU8` removing the terminal arm
  reddens only the terminal case. Neither can pass for the other's reason.
- ~~**`minor` · A failed conflict-review recovery can strand the respondent's only copy of their answers.**~~
  ✅ **DONE — M72 (2026-09-05). EVIDENCE HELD BYTE-FOR-BYTE; THE PRESCRIBED REMEDY DOES NOT WORK AND
  WAS REJECTED ON THE CODE.** ⛔ *"Keep the queued row until recovery succeeds"* leaves it at
  `status: 'pending'`, which is **exactly** what `listPending` selects for both the in-tab driver and
  `sw.ts`'s background sync — so it is re-POSTed within seconds and re-parked as a **second** `conflict`
  row, or escalated to `needs_attention`. Making it work needs a new held status reaching `db.ts`'s
  `OutboxStatus`, `outbox-status.ts`'s exhaustive descriptor map, `reap.ts`'s media-sparing filter, the
  retry guards and two list components. ✅ **What shipped adds no state at all**: `setAnswers` goes
  through `patchUnsent`, which refuses only a `synced` row, so it writes cleanly onto the parked
  `conflict` row. No driver touches a conflict row, `beginConflictReview` already seeds from
  `row.answers`, and `reap.ts` already spares its media — so a reload re-surfaces the review **with** the
  corrections. `App.vue` has held `resolvingUuid` since `G8c` and only ever passed the boolean down;
  that is the whole reason the component had nowhere to put them. ⛔ **TWO PREMISE CORRECTIONS, BOTH
  RECORDED AT THE SITE.** *"The ordinary (non-resolving) path is NOT affected"* is **false** — a
  null-code `handleDrift` is reached from a republish, autosave's key is pinned to the old
  `form_version_id`, and `reap.ts:8-14` already spells out that the pre-republish row is never written,
  read or deleted again. And *"it reaches the outbox contract `lib/replay.ts` and the background driver
  share"* is **false**: it reaches neither, and that is true only of the remedy that was rejected.
  ⚠️ **THE HEADLINE IS ALSO OVERSTATED**: a review runs under a **fresh** uuid, so the parked row survives
  and it is the review EDITS and any media picked during the review that are lost — narrower than *"the
  only copy"*, and still a respondent losing work they were asked to redo. ✅ Media follows via a new
  `repointToSubmission` filtered on the **source** uuid — deliberately a second, narrower write rather
  than loosening `attachToSubmission`, whose unowned-only filter is what `M21` added after a stranger's
  photo was uploaded as somebody else's attachment. ✅ **Two hand-rolled Vitest controls, both CAUGHT**
  with baselines green first and both files restored by byte comparison (`mutate.php` is Pest-only).
  Found while closing the row directly above, and it is a data question the copy fix could only warn about.
  `RuntimeSession.vue`'s `handleSubmitError` calls `discardRow(db, uuid)` for **every** `ApiError` — deleting
  the parked outbox row — and only then dispatches `handleDrift()`. A conflict-review session
  (`resolving: true`) has autosave disabled by construction, precisely because that parked row *was* its
  durable copy. So if `remint()` or `fetchSchema()` then fails on a dropped connection, the reviewed answers
  exist only in component memory and a reload loses them. ⛔ **`M66` fixed the sentence and deliberately not
  the lifecycle**: the durable fix is to keep the row until recovery succeeds, which reaches the outbox
  contract `lib/replay.ts` and the background sync driver share, needs its own Dexie-level coverage, and was
  out of scope for a batched increment under `D13`. The interim mitigation is in the tree — the network arm
  tells a resolving respondent to keep the page open rather than claiming their answers are saved. ⚠️ **The
  ordinary (non-resolving) path is NOT affected**: autosave still holds those answers. **Live.** Filed by `M66`.
  ⚠️ **PREMISE CORRECTED BY `M70` (2026-09-04) — THE HEADLINE IS OVERSTATED, THE CARVE-OUT IS FALSE, AND
  THE PRESCRIBED FIX HAS AN UNNAMED BLOCKER. The defect is real; all three corrections make it a
  different defect.** (1) *"the respondent's ONLY copy"* — the row `submit()` discards is a **new** row
  under a fresh uuid; the parked `conflict` row is deleted only in `onSubmitted`/`onQueued`/`onDiscard`,
  so after a failed recovery it is still on disk and a reload re-surfaces it. What is stranded is the
  **review edits** and any media picked during the review, not the pre-review answers. (2) ⛔ *"the
  ordinary (non-resolving) path is NOT affected"* — **false on the branch that matters**. `handleDrift`
  is reached from a `refresh`, i.e. a republish; autosave keys `draft_answers` on
  `[form_version_id, slug]` with the schema's version; the remount pins the NEW version, so the draft
  written at the old key is unreachable — and `fresh()` clears it on the checksum mismatch anyway. The
  answers are on disk and unrecoverable for the majority cause. (3) ⛔ *"keep the row until recovery
  succeeds"* — **a retained `pending` row is re-driven within seconds by both the tab driver and
  `sw.ts`**, because `listPending()` is device-wide by explicit contract. A 422 re-POSTs to
  `needs_attention`; a 409 parks a **second** conflict row while the session is already remounting to
  resolve the first; and if the driver drains it, `onSubmitted` never fires and the parked row offers
  Review forever. The fix needs a held state the drivers skip, not merely a deferred delete.
  ⚠️ Also unrecorded: `discardRow` → `deleteRow` deletes the row's `media_queue` entries in the same
  transaction, so a blob picked during the review dies here too, with **no** grace window — the other
  half of the media row below.
- **`minor` · `replay.ts:223-228` hardcodes `conflict_code = 'form_updated'` on a client-side version
  guard.** Correct today — it really is a form-version drift, decided with no request made — but M14 turned
  `conflict_code` into **user-visible copy input** (`lib/conflict-notice.ts` keys the respondent's sentence
  off it), so this literal is no longer a debug tag. Nothing is wrong now; the hazard is that the next person
  to add a client-side park has to know that. **Not live — a maintenance trap.** Filed by `M14`. **Not live** — the hardcoded literal is reached only on an actual version change, which is the one case it names correctly, judged by `M65`.
- ✅ **CLOSED BY `M67` (2026-09-03) — `minor` · ~~The authenticated autosave's 409 branch tells a
  `submission_conflict` caller "already been submitted".~~** The binary split is now a keyed map over the
  three codes this channel can return, with the finalized sentence as an explicit default for a cause the
  build has never heard of.
  ⛔ **THE ROW'S PREMISE WAS FALSE ON THE HALF IT LED WITH, AND IT CHANGED THE FIX.** It named the
  **entitlement and content** causes. `submission_conflict` — the content cause — **cannot reach this
  endpoint at all**: it is raised only by `SubmissionPipeline` and is deliberately suspended for drafts. So
  the map does NOT carry it; adding it would have documented a refusal this channel cannot produce, which is
  the naive fix the row invites. The one real defect was `submission_uuid_claimed`, which the row does not
  name — it was filed before `M14` split that code out, and nothing re-read it afterwards.
  ⚠️ **THE OBLIGATION WAS ALREADY WRITTEN DOWN.** `SubmissionDraftController::store()` says *"THREE CAUSES
  SINCE M11, AND THE COMPOSABLE MUST NOT TREAT THEM ALIKE"* and names all three. A comment is not a gate;
  the arm was missing for two increments with a full sentence beside it saying so.
  ✅ **The sibling channel was checked and is NOT affected**: Submit is a web `router.post`, and
  `bootstrap/app.php`'s non-API arm toasts `$e->getMessage()`, which is per-cause already.
  ⚠️ Proved by mutation, not by green: collapsing the new arm reddens exactly one case, and changing the
  finalized sentence once reddens **four** — which is how the fallback is shown to read the map rather than
  being a second copy of the string. Filed by `M14`.
- ✅ **CLOSED BY `M15` (2026-08-26) — `major` · ~~The device-wide outbox is mounted above the phase machine
  on an unauthenticated page.~~** Filed 2026-08-25. **Every file:line in the row was verified before it was
  planned against, and four of six had drifted** — all of them caused by M14 growing `App.vue`:
  `<SyncStatus />` is at `App.vue:460` inside `.app-shell__banner` (`:458-461`), not `:382-386`; the kiosk
  threat note is `outbox.ts:12`, not `:9-18`; the cross-form rationale is `:175-177`, not `:180-186`;
  `canDiscard` on `needs_attention` is `outbox-status.ts:128`, not `:123-130`. **As built:** a *visit* — a
  UUIDv7 in `sessionStorage`, rotated on "Submit another response" and after ten minutes of idle — is
  stamped on every finalized row as `respondent_session_id`, and every read that **discloses or destroys**
  is scoped to it. An earlier visit's unsent rows collapse to one sentence carrying a count and nothing
  else. Decision, alternatives and the stated cost: `docs/adr/0021-respondent-scoped-device-outbox.md`.
  ⛔ **THE ROW'S DISCLOSURE INVENTORY WAS WRONG IN BOTH DIRECTIONS, AND THE MISS WAS THE WORSE HALF.**
  **Creation time is NOT disclosed** — `createdAt` is computed onto the view model at
  `outbox-status.ts:101` and has **zero consumers**; no template renders it. **"Which other forms" is
  narrower than stated** — the slug is never rendered as text, only as the `href` of *"Open this form"*
  (`SubmissionOutbox.vue:161`), on a cross-form **conflict** row. But the sweep found **two exposures the
  row never named**: (a) `useSyncOutbox.conflictRow()` bypasses the `listSubmissions` answer-scrub
  (`outbox.ts:181-183`, *"nothing that renders a list should be handed answer data at all"*) and hands the
  raw row to `App.vue:384`, which seeds a fill session with `row.answers` — so **Review rendered a
  stranger's answers field by field**, two clicks from an always-visible banner; and (b)
  `useSyncOutbox.ts:127-128` writes *"Response sent — reference …"* into an `aria-live` region, so a
  background replay of an earlier respondent's row **read their server reference aloud**. Eight-for-eight on
  the row's own framing being wrong.
  ⚠️ **THE ROW'S "PRE-EXISTING vs NEW" SPLIT IS PARTLY TRUE, AND VERIFYING IT DECIDED THE SCOPE.** Review
  genuinely is pre-existing (G8c, `6211916`, 2026-07-16, PR #41) and was reachable by a second respondent
  from that day. But **destroying a stranger's parked answers was also already possible pre-I10d**, via
  Review → *"Discard this response instead"* (`App.vue:406-428`); what I10d added is Discard *without*
  entering review. And **the server reference did not persist on the device at all before I10d** —
  `markSynced()` used to *delete* the row — so the single most identifying datum in the list is new. The
  practical consequence: hiding the LIST without scoping `listConflicts`/`conflictRow`/`conflictHere` would
  have left every action reachable.
  ⚠️ **THE OBVIOUS DESIGN — ONE ID PER PAGE LOAD, HELD IN MEMORY — IS WRONG, AND ONLY A GATE THAT CANNOT
  RUN HERE SAYS SO.** It is stricter and simpler and it breaks
  `tests/e2e/public-runtime-offline.spec.ts:186`, which parks a conflict row, **reloads at `:225`**, and only
  then asserts `review-conflicts` (`:233`), `/needs? review/i` (`:237`), `Retry now` count 0 (`:241`) and the
  resolve flow (`:246`). A per-load id makes that row a stranger's after the reload and four assertions die
  at merge. A respondent who refreshes has not become a different person; `sessionStorage` is what encodes
  that, and the two rotations are what stop it becoming "whoever used this tab last".
  ⚠️ **AND ONE ASYMMETRY IS THE DECISION RATHER THAN AN OVERSIGHT: THE DRAIN IS NOT SCOPED.**
  `replay.ts:158` calls `listPending(db)` and `sw.ts:80-92` calls `replayOutbox(openDb(), fetch)` with no
  session — a service worker **has** no `sessionStorage` — so a scoped drain would strand every earlier row
  forever, which is the silent data loss the whole offline architecture exists to prevent. Sending a
  stranger's submission discloses nothing and destroys nothing; showing it and deleting it are the harms.
  `pruneSynced` likewise still **never** touches an unsent row at any age; only a delivered *receipt* from
  an earlier visit is dropped, because it can never again be rendered or counted.
  ⚠️ **PAIRED FILE, BOTH HALVES IN ONE PR (Standing Rule 7(b-bis)'s first exercise).**
  `packages/design-system/src/theme/__tests__/clipped-node-containment.test.ts` listed `SyncStatus.vue` in an
  exact-equality `KNOWN_UNGUARDED`, so `.sync-status` gained the `position: relative` its sr-only region had
  always needed and the entry was deleted in the same commit. **The gate was proven to bite before it was
  trusted**: removing the declaration puts the file straight back in the offender list. Filed by `M1`.
- ~~**`major` · An abandoned local draft is restored into the NEXT respondent's form, silently, with their
  answers on screen.**~~ ✅ **CLOSED — Increment M21 (2026-08-26).** `DraftRow` carries an un-indexed
  `respondent_session_id`; both readers of the table refuse a row the current visit did not write, through
  the one shared predicate `draftBelongsToVisit()` in `lib/db.ts`. The row is **not** deleted — the primary
  key stays shared, so the next respondent's first keystroke collects it, which makes containment and
  collection the same mechanism.
  ⛔ **THIS ROW DEFERRED ITSELF ON TWO CLAIMS AND BOTH WERE FALSE, WHICH IS WHY IT SAT FOR AN INCREMENT.**
  It said *"scoping it to a visit would delete the feature rather than contain it"* — true only of putting
  the visit in the **primary key**, which was never the design and which Dexie **refuses** outright
  (`node_modules/dexie/dist/dexie.js:3832` throws `Upgrade('Not yet support for changing primary key')`, so
  the database fails to open on every device that already has one). And it said the unbuilt kiosk-mode row
  *"is this one's precondition"* — refuted by `docs/adr/0021…` §*C*, which had already deferred the kiosk
  gate **saying in terms that doing so "would leave the defect live until it was built."** The mechanism was
  wired the whole time: the visit id is injected **two lines above** the `createAutosave` call that never
  received it.
  ⚠️ **AND THE ROW UNDERSTATED IT THREE WAYS.** (1) The restore trips the autosave watcher, so the header
  pill read *"Saving…"* then *"Saved"* — the only moving pixel **vouched for** a stranger's answers as the
  new respondent's own work; there was no banner because `WelcomeBackBanner` needs a resume seed. (2) A
  restored draft carries a **fresh** `client_submission_uuid` and a **null** baseline, so "Save and finish
  later" POSTed those answers as a **new server draft** under the next respondent's identity and **emailed
  them a 30-day resume link to it** — the disclosure left the device. (3) `attachToSubmission()` re-pointed
  the previous respondent's queued photo or signature onto this submission, its docblock having claimed
  *"still-unassigned"* since G8b while the `.modify()` never filtered on it; M21 narrows that write too.
  ⚠️ **Six of its ten citations had drifted forward** (`db.ts:127`→`:130`/`:139`; the three `clear()` sites
  `:262/:313/:344`→`:267/:322/:353`; the banner `:471`→`:481`; `App.vue:230`→`:249`). Every claim was true;
  the line numbers were not. Full reasoning in `docs/adr/0021-respondent-scoped-device-outbox.md`, amended. Filed by `M15`.

- ~~**`minor` · `reconcile.ts:43`'s local-wins note tells a respondent a stranger's answers are theirs.**~~
  ✅ **CLOSED — Increment M21, AND RE-CLASSIFIED `minor` → `major` ON THE WAY OUT.** It was filed as a copy
  defect and it was a **durable cross-respondent write into a finalized submission**. `App.vue` seeds the
  resumed session with the **local** tier's answers but keeps the **server's** `client_submission_uuid` and
  the **server's** `content_checksum` — deliberately and correctly, per P3a, for the case it was written
  for. So when a stranger's abandoned draft won `reconcileDraft`'s newest-wins rule, the resuming
  respondent's next save wrote **that stranger's answers over their own server record**, and **passed P3a's
  lost-update guard by construction** — the guard compares the baseline the saving device carries, and the
  baseline genuinely was theirs. A submit then promoted that row. P3a and M12 closed the two-**device**
  lost-update doors; neither had considered two **respondents**.
  **As built:** `App.vue`'s resume read applies the same `draftBelongsToVisit()` predicate, so a foreign row
  degrades to `undefined` — `reconcile.ts`'s first branch, server-wins, silently and without a note.
  `LOCAL_WINS_NOTE` is **untouched and did not need to change**: the sentence is correct English for every
  case that can now reach it, which is the narrower fix the row itself asked for. Filed by `M15`.

- ~~**`major` · The guest device has no enumerator and no reaper for abandoned answer content.**~~
  ✅ **CLOSED — Increment M22 (2026-08-26).** `resources/public-runtime/lib/reap.ts` is the sweeper both
  tables had never had, wired exactly where `pruneSynced()` already is: `useSyncOutbox.refresh()` for the
  boot pass with a tab, and `replay.ts`'s drain for the Background-Sync pass without one. **(a)** A
  `media_queue` row with a null `client_submission_uuid` that no answer document still names, past a
  one-hour in-flight grace, is deleted. **(b)** A `draft_answers` row past `DRAFT_TTL_MS` is deleted over
  `updated_at`'s **v1** index — so the seven-day expiry finally reaches keys no reader holds, which is what
  a republish creates. Reasoning in `docs/adr/0021-respondent-scoped-device-outbox.md`, amended again.
  **Both shapes verified against the code before anything was written, and both were exactly as filed.**
  The two deleters are `lib/outbox.ts:107` and `:159`, both `where('client_submission_uuid').equals(uuid)`
  — and the row understated *why* that can never match: a null is not merely unequal to a uuid string,
  **IndexedDB does not index `null` at all**, so an orphan is absent from the index and no argument to that
  `where()` could ever reach it. `draft_answers` had `.get`, `.put` and `.delete` and nothing else.
  ⛔ **BUT THE ROW WAS WRONG ABOUT ITS OWN COST, WHICH IS ELEVEN FILED ROWS IN A ROW NOW WRONG ABOUT
  THEMSELVES.** It deferred itself as needing *"an enumerating sweeper plus a retention decision (how long,
  on what trigger, and whether it runs in the service worker) … its own increment with its own ADR
  question."* **No decision was filed to `docs/claims/decisions.md` and no ADR number was minted, and
  neither is an omission.** *How long* is `DRAFT_TTL_MS` — the seven-day window `useAutosave.fresh()` has
  gated the restore on since F6b and UX §5.1 specifies; the defect was never that it was unspecified, only
  that nothing could apply it to an unreachable key. *On what trigger* had a precedent in the same runtime:
  `pruneSynced` is called from both places, so this is too. *Whether it runs in the service worker* looked
  like a question and was a **constraint** — `tsconfig.sw.json` re-checks `sw.ts`'s graph with `types: []`,
  so a module it reaches cannot read `sessionStorage` and cannot know whose visit anything is. That is what
  forced the design to test **reachability rather than ownership**, and reachability is the better test
  anyway: it spares an earlier visit's blobs while their queued submission is still draining (the drain is
  device-wide on purpose), and it asks through `collectLocalMediaIds()` — **the same function
  `attachToSubmission()` links with**, so the linker and the sweeper cannot drift.
  ⚠️ **AND IT COST NO SCHEMA CHANGE, WHICH THE ROW ALSO DID NOT KNOW.** `updated_at` on `draft_answers` and
  `status` on `media_queue` were both declared in **v1**, so there is no `db.version()` bump — which
  matters more than convenience here, because `db.test.ts` pins `verno` at 2 and Dexie throws outright on a
  primary-key change (`dexie.js:3832`), failing to open on every device that already has one.
  ⚠️ **Nothing UNSENT is reaped, at any age, by any predicate** — `pruneSynced`'s contract governs here and
  this module is stricter, never writing `outbox` at all. It **reads** it, to learn what to spare: a ref
  that failed to link leaves an orphaned blob while the queued submission still carries its `local:`
  placeholder, and `replay.ts` refuses to POST one (*"queued media is incomplete"* → five attempts →
  `needs_attention`), so reaping that blob would park a real respondent's real submission forever.
  Still true, and still filed separately below: `useSyncOutbox`'s quota line blames the outbox for storage
  this consumed. Filed by `M21`.

- **`minor` · A media pick made during a conflict review is protected only by the reaper's grace window.**
  Filed 2026-08-26 by M22, **the moment M22 decided not to fix it.** `lib/reap.ts` spares an orphaned blob
  while some answer document still names its `local:` ref — but the conflict-review session runs
  `createAutosave` with `enabled: false` (Increment G8c, deliberately: a transient review must not clobber
  a live fill on the shared `[form_version_id+slug]` key), so it writes **no `draft_answers` row at all**.
  A blob picked during a review is therefore referenced by nothing on disk until the resubmit, while
  `refresh()` can fire underneath it on `online` or `visibilitychange`. `MEDIA_ORPHAN_GRACE_MS` is one hour
  **because of this case and not because of the 800 ms autosave debounce**, so a review that outlasts an
  hour loses that pick. It fails safe rather than silently — the resubmit parks as `needs_attention` with
  *"queued media is incomplete"* rather than posting a dead ref — and it needs a respondent to leave a
  review open for an hour on a device something else then syncs from.
  **The fix is to stop the review session being invisible to the mark set, not to lengthen the window**:
  either thread the live `local:` ids from `App.vue`'s `OfflineMediaKey` provider into the sweep as a
  protected set (app-side only; a service worker has no such set), or give the review session durable state
  of its own. The second is the better shape and is larger than this row. **Live.** Filed by `M22`.
  ⚠️ **PREMISE CORRECTED BY `M70` (2026-09-04) — THE EVIDENCE IS EXACT (this row is a faithful
  transcription of `reap.ts`'s own `MEDIA_ORPHAN_GRACE_MS` docblock) AND THE SCOPING OF THE SMALLER FIX
  IS WRONG.** It calls threading the live `local:` ids into the sweep *"app-side only; a service worker
  has no such set"*, which prices it as not touching `lib/replay.ts`. ⛔ **`reapAbandoned()` has TWO call
  sites** — `useSyncOutbox::refresh()` and `replay.ts::run()` — and the trigger this row NAMES reaches
  the second one first: `online`/`visibilitychange` → `syncNow()` → `replayOutbox` → `run()` →
  `reapAbandoned()`, before `refresh()`'s reap in the `finally`. **A fix threaded only into `refresh()`
  leaves the named trigger reaping unprotected**, and a Vitest case that drives only `driver.refresh()`
  stays green under exactly that mutation. So the smaller option also edits `lib/replay.ts`, which is
  re-type-checked under `tsconfig.sw.json` — the blast radius the row assigned to its *larger* option.
  ⚠️ **And protecting the blob from the reaper does not protect it from the discard**: the conflict-review
  row above deletes the same `media_queue` entries outright on any `ApiError`, with no grace window.

- **`minor` · The storage-quota line counts strangers' submissions.** `useSyncOutbox` computes `queued` from
  the device-wide count and renders *"N responses waiting to send"*, while `mine`, `earlierUnsent` and
  `conflictHere` beside it are all visit-scoped — so a respondent can read three consecutive sentences whose
  numbers only reconcile if they count a stranger's rows. Filed rather than fixed: it discloses a count and
  nothing else, which is exactly the shape ADR-0021 sanctioned for an earlier visit, and touching the
  device-wide count risks the boot drain that ADR-0021 makes load-bearing. **Live.** Filed by `M21`.

- ~~**`minor` · Resume-link shells sit in Cache Storage, and the brand refresh re-fetches them.**~~ A resume
  link is a path under `/f/`, and `sw.ts` NetworkFirst-caches every same-origin navigate under `/f/` into
  `guest-shell-html` for seven days; the cached body carries `data-resume-token`, and the resume endpoint
  returns the full answer map to whoever holds that token. `brand-cache.ts` enumerates `cache.keys()` and,
  on a brand change, silently re-fetches and re-`put`s each URL — including a resume link — from the *next*
  respondent's boot. Filed rather than fixed: it is device-local, needs devtools, and the token is already
  in the address bar and browser history, so the cache's marginal contribution is surviving a history clear.
  The fix touches `sw.ts`, which is the second type-check program and a different blast radius from the row
  it was found under. ⚠️ **Worth recording beside it:** `routes/api.php` carries an explicit warning that a
  GET under `/api/v1/public/f/` would be service-worker cached, and the resume read **is** such a GET
  returning full answers — it escapes only because its path prefix is `drafts/`. One route rename re-opens
  it. **Live.** Filed by `M21`.
  ✅ **DONE — M70 (2026-09-04). THE ONE ROW OF ELEVEN THIS INCREMENT VERIFIED WHOSE EVIDENCE *AND*
  PREMISE BOTH HELD IN FULL.** Every clause resolved: the seven-day `NetworkFirst` route, `/f/resume/…`
  being a navigation under it, `data-resume-token` in the cached body, the silent re-`put` sweep, and the
  escape clause — the resume READ is still `/api/v1/public/drafts/…`, so `sw.ts`'s `/api/v1/public/f/`
  predicate still misses it and one route rename still re-opens it. ⚠️ **The row understates itself by
  one item:** the cached shell also carries `data-share-token`, though that is short-lived and re-mintable.
  ⛔ **THE PRESCRIBED REMEDY IS THE ARM THAT CANNOT BE PROVED, AND IT WAS NOT TAKEN.** The row says *"the
  fix touches `sw.ts`"*. There is **no `sw.test.ts`** — `__tests__/register-sw.test.ts` asserts only that
  `/sw.js` is registered with `{ scope: '/f/' }` — so deleting a new predicate clause there leaves the
  whole Vitest suite green; and never caching a resume navigation costs offline resume access outright,
  contradicting `brand-cache.ts`'s own axiom that a respondent *"never loses the form"*. **Taken instead,
  with the user: the brand-refresh arm**, which is what this row's own title complains about.
  `isResumeShell()` skips resume URLs in `refreshCachedShells()`, so the entry ages out on the seven-day
  clock instead of being renewed from every later boot. **Skipped, never purged.** `routes/api.php` gains
  the sibling note that the `drafts/` prefix is load-bearing. Three controls, each firing through exactly
  the branch it names: the skip reverted → the new case only; `isResumeShell` → `true` → **five** cases
  including the pre-existing sweep case, which is the discriminator between *"skips resume links"* and
  *"skips everything"*; and the substring spelling `url.includes('/f/resume/')` → the path-vs-query case
  only. ⚠️ **`mutate.php` could not drive any of them** — it is Pest-in-a-container — so its discipline was
  reimplemented at the call site, and **the harness's own first draft printed no failing-case names at all**
  because vitest opens every one with a colour escape: it reported CAUGHT three times with no evidence of
  *which* assertion caught it. The `sw.ts` arm is filed below.

- ✅ **CLOSED BY `M76` (2026-09-06) — `minor` · ~~The two `draft_answers` readers disagree about which `form_version_id` they mean.~~**
  ⛔ **HALF OF WHAT `M70` LEFT STANDING HAS SINCE DIED, AND THE OTHER HALF WAS UNDERSTATED.** The
  `onRedraft` leg `M70` preserved is now **version-equal by construction** — `SubmissionDraftService`
  re-reads the draft's own pin and refuses a superseded one, so the 409 it describes is unreachable. What
  survives is the single `fetchSchema()` inside `loadResume` on a resume boot whose service-worker-cached
  shell carries an already-expired token: it 401s, `withFreshToken` re-mints through `GET /f/{slug}`, and
  **that** route pins `current_published_version_id`.
  ⛔ **THE HARM IS NOT A REJECTED DRAFT, WHICH IS HOW BOTH THE ROW AND `M70` DESCRIBED IT.** The
  respondent's newer local tier is discarded in `reconcile.ts` **with no note**, a v2 schema renders under
  a *"welcome back"* greeting holding v1 answers, and their first autosave 409s them onto `onReschema` with
  a fresh uuid — **abandoning the server draft and the emailed resume link with it**.
  ✅ **NONE OF THE THREE CANDIDATE REMEDIES WAS TAKEN, BECAUSE THE TREE ALREADY CONTAINED THE FIX.**
  `GuestDraftResumeController` has always minted a share token pinned to the **draft's** version,
  `api-client.ts` has always decoded it into `ResumeDraftResult.shareToken`, and `resumeDraft()`'s own
  docblock says *"the response carries a fresh SHARE token the caller then hands to `createApiClient`"* —
  **a hand-off that was documented and never implemented**. `client.adoptToken(server.shareToken)` before
  the schema fetch costs two production files and about six lines, removes the divergence on every path
  rather than one, and makes an existing false docblock true.
  ✅ **PROVED BY THE FIRST VITEST MOUNT OF `App.vue` IN THE REPOSITORY.** The row warned that *"a naive fix
  ships with a green suite that proves nothing"* and it was right — `App.vue` was mounted by zero tests.
  `resources/public-runtime/__tests__/resume-boot.test.ts` asserts both the token and the **order**, since
  adopting after the fetch would leave the defect intact with the fix apparently present; a permissive
  ordinary-entry control stops it passing against unconditional adoption. Mutation caught.
  ⚠️ **A static import of `App.vue` is required** — a dynamic `import()` inside the test body never
  resolves under this setup, which cost an hour and is recorded in the file. Closed by `M76`.

  **The row as it stood, kept because its correction history is the audit trail.** The
  autosave writes with the **currently published** version; `App.vue`'s resume read fetches with the version
  the **server draft** was pinned to. They coincide only until a republish intervenes, after which the
  resume path probes a key the live session never writes — the orphan slot in the row above. Benign today
  only because `reconcile.ts`'s checksum guard rejects the hit, which means **the checksum guard is the only
  thing standing between the resume path and a pile of pre-republish drafts.** Filed so that whoever tidies
  the mismatch knows what it is load-bearing for. **Live.** Filed by `M21`.
  ⛔ **PREMISE MATERIALLY FALSE — CORRECTED BY `M70` (2026-09-04). THE TWO EXPRESSIONS RESOLVE AND THE
  CONCLUSION DRAWN FROM THEM DOES NOT.** The row assumes `props.schema.version.id` means *currently
  published* in the session where the resume read happens. It does not: `GuestFormController::mint()`
  pins `current_published_version_id` for the plain `/f/{slug}` entry, but `resume()` mints **the resume
  token's pinned version**, and `PublicFormSchemaController::show()` resolves the **token's** version,
  never the published one. So on the resume boot — the only place `loadResume()` runs — the two ids are
  **equal by construction, republish or not**. Three of the row's four claims fall with it: they do not
  *"coincide only until a republish"*; the resumed session's own autosave writes the very key the read
  probed; and the checksum guard is **structurally inert** there, because key and checksum derive from
  one pinned token, so it cannot reject. *"The only thing standing between the resume path and a pile of
  pre-republish drafts"* is false twice over — `App.vue` applies `draftBelongsToVisit()` **before**
  `reconcileDraft`, and `reap.ts`'s `reapExpiredDrafts()` collects the orphans; the row's own pointer,
  *"the orphan slot in the row above"*, names a row `M22` already closed. ✅ **A REAL DEFECT SURVIVES,
  MUCH NARROWER AND IN THE OPPOSITE DIRECTION:** `api-client.ts`'s `withFreshToken` silently re-mints on
  a `401 remint` (which GETs `/f/{slug}` and takes `current_published_version_id`), and `onRedraft()`
  re-enters `loadResume()` mid-session on a client that may already have been re-minted. On **those two
  paths** the versions genuinely differ and the guard genuinely fires. ⚠️ Whoever takes it should note
  no current fixture builds that case, and `App.vue` is mounted by **zero** Vitest tests — so a naive fix
  ships with a green suite that proves nothing.
- ~~**`minor` · `useServerAutosave.dispose()` fires without consulting `inFlight`.**~~
  ✅ **DONE — M62 (2026-09-02). EVIDENCE EXACT; REMEDY SOUND IN INTENT AND UNDER-SPECIFIED WHERE IT MATTERS.**
  Citation rewritten from a by-line range to `dispose()` in `resources/js/composables/useServerAutosave.ts`,
  because M62 moved those lines — a row must not cite line numbers into a file its own remedy edits.
  The mechanism held exactly, and the two facts that make it a data loss rather than a wasted request are
  one screen apart and in neither the row nor the code's own comments: `send()` clears `dirty` at its top,
  and `baseline` advances **only** on a 200.
  ⚠️ ***"Consult `inFlight`"* IS RIGHT AND INCOMPLETE — consulting it and then declining to send drops the
  same edits.** What works is to **chain** on the existing `inFlight` handle, which is possible *only*
  because an Inertia visit unmounts the component but keeps the JS context alive. Shipped with a
  `state !== 'stopped'` re-check inside the continuation, because the save being waited on may itself have
  ended the loop.
  ➕ **THE ROW UNDERSTATED ITSELF: `onBeforeUnload` HAS THE SAME STALE-BASE SHAPE AND IS DELIBERATELY NOT
  CHANGED.** There the browser context is dying, so a continuation would never run and chaining would trade
  a request that fails for a request never made; `event.preventDefault()` — the native leave prompt — is the
  control, on the same argument the 64 KiB `keepalive` cap already makes. Recorded in its docblock as a
  stated limit rather than left to read as an oversight.
  ⛔ **AND THE COMMENT INSIDE `postKeepalive()` ASSERTED THE PROPERTY WHOSE ABSENCE IS THE BUG** — *"the
  single-flight bookkeeping is not bypassed in the one place that would be hardest to notice."* Neither
  branch touches `inFlight` or `pendingWhileInFlight`. That sentence is the most likely reason the race
  survived review, and it is corrected in place rather than deleted.
  ➕ **A SURVIVOR IN THE SAME FUNCTION, FOUND BY MUTATION AND CLOSED HERE.** `dispose()`'s outer gate
  `dirty || debounceTimer !== null` narrowed to `&&` left **all 22 cases green** — every dispose case in the
  file happened to have both true at once. The separating state is a 5xx, which sets `dirty` and
  deliberately does not re-schedule: unsaved work, no armed timer, dropped in silence under `&&`. Pinned
  now; the mutation reddens exactly that case. **Pre-existing, and not something to leave behind in the
  function this row rewrites.** Filed by `M1`.
- ~~**`minor` · The encode page's conflict refusal remounts and discards the editor's corrections.**~~
  ✅ **DONE — M62 (2026-09-02), AND THE ROW'S IMPLIED REMEDY WOULD HAVE SHIPPED A SILENT LOST UPDATE.**
  Citation rewritten from a by-line reference to `submitEdit()` in
  `resources/js/Pages/submissions/Encode.vue`, for the same reason as the row above. Evidence held: the
  refusal arrived as a toast with no errors bag, the predicate answered `false`, and Inertia re-keyed the
  component. *"Remounts"* is literally right — `swapComponent` in the installed `@inertiajs/vue3` re-keys
  with `Date.now()` when `preserveState` is false.
  ⛔ **BUT *"return it as a validation error so `preserveState` holds"* PRESERVES THE TYPED WORK AND SILENTLY
  RE-ARMS THE CONCURRENCY GUARD.** `preserveState` gates the re-key, **never the props** — `swapComponent`
  assigns the new page object unconditionally — and `EncodeFormPresenter::present()` reads the baseline off
  the stored row on every render. So `back()` re-renders carrying the *other* editor's checksum, the
  preserved page adopts it, and the next Save matches and blindly overwrites the change just refused. **A
  visible refusal becomes a silent lost update, which is worse than the defect being closed.**
  **Shipped instead:** the errors bag (which arms the predicate) **plus** a component-local snapshot of the
  render-time baseline, deliberately never re-synced. The second Save is refused too — that is the point:
  the editor keeps every character they typed and the client can never write over a document it has not
  seen. Adopting the newer answers stays a deliberate page reload.
  ⚠️ **The row cites the `preserveState` line; the line that mattered was the one sending
  `props.editing?.baseline`.** Fixing only the cited line ships the lost update. A third cause,
  `illegalState()`, reaches the same catch arm and gets the same treatment deliberately.
  👤 **A "discard my changes and reload" affordance was put to the user and declined for this increment** —
  filed below rather than built.
  **8 mutations: 7 CAUGHT first time; 1 SURVIVED and is closed above. The predicted survivor was WRONG** —
  inverting the `preserveState` predicate was predicted to survive on the strength of the existing 422
  cases, and it was CAUGHT by exactly one test, which is how it was discovered that **no existing case ever
  read that predicate at all.** Filed by `M1`.
- ✅ **CLOSED BY `M68` (2026-09-03) — `minor` · ~~Submit races its own last-chance draft write, and the refusal lands on the Submit.~~**
  `submit()` no longer calls `autosave.dispose()`. The composable gained a pair — `settle()`, which awaits a
  save already in flight without starting one so `baseline` is the ADVANCED value, and `standDown()`, which
  tears the loop down writing nothing — and `Encode.vue` uses both. The row's own cheapest option, and it
  survived inspection.
  ⛔ **THE ROW UNDERSTATED ITSELF: THERE WERE THREE WRITERS ON A SUCCESSFUL SUBMIT, NOT TWO.**
  `postKeepalive()` never touches `dirty`, and `dispose()`'s only condition is `dirty || debounceTimer !== null`
  — so the remount that follows a successful promote ran `onBeforeUnmount(dispose)` and fired the keepalive a
  SECOND time, against a row the promote had just finalized. Clearing `dirty` in `standDown()` is what makes
  that unmount a genuine no-op, and it has its own case.
  ⚠️ **The row's open question is not answered and did not need to be.** Which of the two writers wins is
  timing; removing one removes the race in every ordering, so pinning an order would have been pinning the
  scheduler.
  ⛔ **AND ONE OF THE NEW CASES COULD NOT SEE THE MECHANISM IT NAMED — CAUGHT BY MUTATION, NOT BY READING.**
  The follow-up-save case typed during an open request and asserted no second write, but typing arms only a
  debounce TIMER while `pendingWhileInFlight` is set inside `run()`; the debounce has to actually FIRE. Two
  mutations SURVIVED against it. With the second advance added, dropping `pendingWhileInFlight = false` is
  CAUGHT — and narrowing `settle()`'s `while` to an `if` still survives, which the comment there now states
  rather than implying both halves are proven.
  ➕ **A residual found while scoping this, filed as its own row below**: `dispose()` removes the
  `beforeunload` listener and nothing re-adds it, so after a refused Submit the browser's leave prompt is
  gone for the rest of the page's life. Pre-existing, and untouched here.
  **`minor` · Submit races its own last-chance draft write, and the refusal lands on the Submit.** Filed by
  M62 (2026-09-02), found by opening the row above's citation and reading what sat next to it — **not by
  running it.** `submit()` in `resources/js/Pages/submissions/Encode.vue` calls `autosave.dispose()` and then
  immediately `router.post(…)` carrying `base_content_checksum: autosave.baseline.value`. When the page is
  dirty both requests go out with the **same** base: the keepalive through the draft channel, and the
  Submit's own `saveDraft()` on `SubmissionController::store()`'s promote branch, which passes
  `checkBaseline: $request->claimsBaseline()`. They serialize on `updateDraft()`'s `lockForUpdate`; the
  winner advances the checksum and **the loser is refused `draftConcurrentlyModified()`** — there is no
  idempotency escape for identical content, the guard compares checksums only. If the keepalive wins, and it
  is dispatched first, **the Submit is the one refused**, telling a keyer their draft *"was changed somewhere
  else"* on a page with no somewhere else. ⚠️ **What is NOT settled: which request actually wins.** That is
  timing, and this row was read rather than executed — so treat the direction as the likely case, not a
  measurement. ⚠️ **M62 neither fixes nor worsens it** — the common path (`inFlight === null`) still fires
  the keepalive immediately — **but in the narrow in-flight window M62 now delays the keepalive, which can
  change which of the two is refused.** The remedy worth costing first is the cheapest one: `submit()` may
  not need the last-chance write at all, since the POST carries the full answer map anyway and the promote
  branch re-saves it — the comment defending the write says as much. **Live.** Filed by `M62`.
- ✅ **DONE — M71 (2026-09-05). THE DEFECT WAS REAL, THE TITLE WAS TOO BROAD, AND THERE WERE TWO OF IT.**
  `createServerAutosave()` now arms the `beforeunload` guard **and** the dirty-gated backstop through one
  idempotent `armGuards()`, called at construction and again on every dirty edit, and refusing after
  `dispose()` so a terminal teardown is never resurrected. The composable's own note already claimed the
  loop re-arms on the next keystroke; the **save** loop did, and neither of these did.
  ⛔ **THE SECOND HALF WAS UNNAMED BY THE ROW AND IS THE ONE NOBODY NOTICED.** `clearTimers()` clears the
  backstop interval as well as the debounce timer, and `schedule()` re-creates only the debounce timer —
  the backstop was a single `setInterval` at construction with nothing anywhere able to make another. So
  a keyer who kept typing after a refusal also lost the periodic retry that rescues a save stuck in
  `error`. **Two things failed to recover, not one.**
  ⛔ **AND THE LIVE BRANCH IS NARROWER THAN "after a refused Submit".** `Encode.vue` keeps the component
  mounted only when the refusal carries an errors bag. Of the refusals this Submit can meet, only
  `SubmissionValidationException` does — `SubmissionException`, `SubmissionConflictException` and
  `FormNotAcceptingSubmissionException` all return `back()` with a toast and **no** errors, so Inertia
  takes a fresh key, the component remounts, and a brand-new composable arms its own guards. The row
  generalised past its own evidence; only the 422 was ever live.
  ✅ **Four cases, and the group needs all four**: a positive control that a dirty page warns at all
  (without it every negative below is vacuous), the re-arm after `standDown()`, the discriminating
  negative after `dispose()`, and the backstop measured across a **quiet** interval so the debounce
  cannot pass for it. Two controls: removing the re-arm reddens both re-arm cases and leaves the
  discriminator green; removing the `disposed` guard reddens **only** the discriminator.
  ⚠️ **The test file needed an `afterEach` before any of this could be trusted.** happy-dom gives one
  `window` per FILE and nothing there disposed anything, so roughly thirty-five earlier composables still
  had armed `beforeunload` handlers on it — several deliberately left dirty. A dispatch-based negative
  would have been red for a reason unrelated to the code under test. ⛔ `scripts/mutate.php` could not
  drive any of it (Pest-in-a-container only); its discipline was reimplemented at the call site.

- ~~**`minor` · After a refused Submit the browser's leave prompt is gone for the rest of the page's life.**~~
  Filed 2026-09-03 by `M68`, found while scoping the Submit-race row above and deliberately not fixed
  there. `createServerAutosave()` registers `onBeforeUnload` exactly once at setup, and **both** teardown
  paths remove it — `dispose()` and, since `M68`, `standDown()`. Nothing ever re-adds it. The answer
  watcher *does* re-arm the save loop on the next keystroke (it only returns early on `state === 'stopped'`,
  which neither teardown sets), so autosave itself recovers — but the `beforeunload` handler does not, and
  that handler is the one carrying `event.preventDefault()`. ⚠️ **So a keyer whose Submit comes back 422
  keeps typing into a page that still saves and no longer warns them on close**, and the composable's own
  note calls that prompt *"the guarantee"* to the last-chance POST's *"courtesy"*. **Pre-existing since the
  listener was added; `M68` did not introduce it and did not widen it** — `standDown()` inherits exactly
  `dispose()`'s teardown. Not fixed here because the honest remedy is to re-arm on the next dirty edit,
  which is a lifecycle change to a file whose two teardown paths were the subject of this increment's own
  mutation pass, and because the neighbouring row about a refused correction's escape route is where the UX
  half of this belongs. **Live.** Filed by `M68`.

- ✅ **CLOSED BY `M74` (2026-09-05) — `minor` · ~~Nothing offers a way out of a refused correction except a browser reload.~~** Filed by M62
  (2026-09-02), the moment the scope was decided rather than after. M62 keeps the editor's typed corrections
  on the page and keeps the guard armed, so a second Save is refused too and the only route forward is the
  browser's own refresh. 👤 **The user was asked and chose the snapshot-only fix**, so this is a deferral of
  record, not an oversight. What is missing is a durable conflict notice (the toast fades, leaving a page
  that looks normal and can never be saved) and an explicit *"discard my changes and reload"* action.
  ⛔ **Whatever is built must not become a one-click adopt-the-new-baseline**, which is the silent lost
  update the closed row above exists to prevent. Filed by `M62`.
  ⛔ **AS BUILT BY `M74` (2026-09-05), AND THIS ROW'S STATED HARM IS FALSE.** *"The toast fades"* — it does
  not. `useToast` auto-dismisses only `type !== 'error'` and `SubmissionEditController::update()` sends
  `'error'`, so the toast persists until dismissed. **The real defect is narrower and differently shaped:**
  the notice is **dismissible**, so one click leaves a page that looks normal and can never be saved; and
  the errors bag is keyed `baseline`, which no field renders — the controller says exactly that at its own
  catch site. **As built:** a non-dismissible `MdsAlert` carrying the SERVER's sentence, plus an inline
  two-step discard ending in `window.location.reload()`.
  ⛔ **A REAL BROWSER NAVIGATION, NEVER `router.visit(url, { preserveState: false })`.** The end state is
  identical; the argument is not. `M62`'s whole finding was that `preserveState`'s semantics had been
  misread once, and betting the lost-update guard on a second, opposite reading of the same flag is that
  bet again. ⚠️ **The row's ⛔ constraint is NOT gate-enforceable and the test says so** — happy-dom cannot
  tell a destroyed JS context from a re-key, so asserting the router was unused is a **proxy**, not the
  constraint. ⚠️ **The row's implied lifecycle obstacle does not exist, which made this cheaper:** autosave
  is off in edit mode, so `useServerAutosave` registers `beforeunload` but its body early-returns because
  `dirty` is never set. 👤 **That same fact is a separate data-loss defect; the user was asked and chose to
  file it rather than fold it in.** It is the row below.

- ✅ **CLOSED BY `M13` (2026-08-25) — `major` · ~~`/api/v1/sync/submissions` creates submissions against ANY
  form in the tenant, with no per-form authorization at all.~~** Filed 2026-08-24 by M11's adversarial pass;
  every file:line claim in it verified verbatim against the code before it was planned against. The route
  carried `ability:write:submissions` and nothing else (`routes/api.php`), and
  `SyncSubmissionController::replayOne()` resolved the version from the caller's own `form_version_id` and
  handed it to the pipeline without ever consulting `SubmissionPolicy::create()` — the gate the equivalent
  web route binds with `can:create,Submission,form`. **As built:** a per-ITEM
  `Gate::forUser($user)->allows('create', [Submission::class, $form])` inside `replayOne()`, reported as that
  item's own `error` with code `forbidden` so a partial refusal never discards its siblings.
  `POST /form-templates` was already doing exactly this for the identical asymmetry and nobody had named it
  as the pattern; it is now named, in `routes/api.php`, `docs/api-specification.md` and
  `docs/offline-first-sync-design.md` §2A.
  ⛔ **THE ROW NAMED A PRINCIPAL THAT CANNOT EXIST, AND MISSED THE WORST ONE.** "A Viewer with a
  `write:submissions` token" is impossible: that ability maps to `submissions.create`, a viewer does not hold
  it, and `ApiAbilities::intersect()` drops it at mint time. The row's other example (an Editor collaborating
  on one form) is right. The one it does not name is the **Reviewer** — they *do* hold `submissions.create`,
  so they can mint the token, while `SubmissionPolicy::create()` requires `forms.edit.any` or EDITOR
  capacity since the deliberate G10a tightening and a reviewer's grant is reviewer capacity. **A Reviewer
  was authorized to encode on ZERO forms through the web app and reached EVERY form through this route.**
  Read an ability map before believing a claim about who can exploit an ability gate.
  ⚠️ **AND THE SHAPE HAD TWO ROUTES, NOT ONE.** A sweep of the live route table — every Group-B route
  filtered on the absence of `Illuminate\Auth\Middleware\Authorize` — returned **8 of 62** ungated, six of
  them legitimately (`tenant.show`, both `field-library` routes, `form-templates.index`, `gamification.me`,
  and `form-templates.store` which gates in its controller). The eighth was **`GET /api/v1/sync/manifest`**,
  in the same route comment block and unnamed by the row: it served the COMPLETE `schema_snapshot` of any
  published or superseded version to any holder of `read:forms`, while
  `GET /forms/{form}/versions/{version}` gated the identical payload on `can:view,form`. Closed in the same
  increment with an in-controller `FormPolicy::view`. **This defect class is a property of a route TABLE**,
  so the durable guard is `tests/Feature/Api/GroupBPolicyGateTest.php`, which walks the live table and
  reddens on a route with neither a `can:` middleware nor a written reason it needs none.
  ⚠️ **TWO MORE OUTCOMES ESCAPED `replayOne()` AND ABORTED THE WHOLE BATCH — fixed in the same method
  because the authorization fix forced a decision on the first of them.** (a) `forms` soft-deletes and
  `form_versions` does not, and neither RLS policy filters `deleted_at`, so a deleted form's versions stay
  resolvable; the pipeline's `Form::findOrFail()` then raised a `ModelNotFoundException` nothing caught → a
  top-level **404** after earlier items had committed, re-raised on every retry, so **one poisoned row
  stalled a device's outbox permanently**. Now a per-item `form_not_found`. (b)
  `FormNotAcceptingSubmissionException` is a **sibling** of `SubmissionException`, not a subclass — every
  exception in that directory is `final class … extends RuntimeException` — so a closed, not-yet-open or
  **at-capacity** form rendered as a top-level **403**, losing every item's result. That is the response cap
  the row itself cites as the consequence, arriving as the one refusal the batch could not survive. Now
  per-item `form_not_open` / `form_closed` / `max_responses_reached` with their `details` intact. The
  method's docblock had claimed *"Never throws"* since G8b — an intention read afterwards as a measurement.
  ⚠️ **`openapi.json` MOVED, AND NOT ON THE PREDICTED AXIS.** The measured expectation was byte-identical,
  reasoned from `/form-templates post` documenting only `200`/`422` while carrying the same in-controller
  `Gate::forUser()->authorize()` — Scramble infers a 403 from route MIDDLEWARE, not a controller call. It
  moved anyway: `/sync/manifest` gained a **404**, because the new `Form::findOrFail()` is a shape Scramble
  traces where the pre-existing `firstOrFail()` is not. That 404 has been a real response since G8b and
  `SyncApiTest` has asserted it since G8b; the document had simply never said so.
  ⚠️ **MUTATION MATRIX: 7 mutations, 0 undefended, and the one that mattered added a test rather than
  changing code.** M2 — gate on `FormPolicy::view` instead of `SubmissionPolicy::create` — reddens **exactly
  one** case, and only because that case was added *because the matrix was planned first*: every seeded role
  gives the two policies the same answer on this route, so the wrong policy would have been invisible to the
  other twenty-five cases. It is pinned with a synthetic member (may edit the form, holds no
  `submissions.create`) on the `FormHubGateTest` idiom, labelled unreachable-today. ⛔ **M1 (delete the
  guard) and M3 (throw instead of returning a per-item error) redden the IDENTICAL set, and reordering the
  mixed batch to separate them did not work** — from a client's side "an unauthorized item is its own
  `forbidden` result and the batch continues" is ONE observable that both mutations violate, and nothing can
  observe a guard's absence without observing a refusal. Recorded as what the contract is rather than
  engineered around. Filed by `M11`.
- ✅ **CLOSED BY `M77` (2026-09-06) — `minor` · ~~The `reviewer` role's seeded description and `SubmissionPolicy::create()` contradict each
  other.~~** Filed 2026-08-25 by M13, which made the contradiction observable on a second surface and
  deliberately did not resolve it. `RolePermissionSeeder::MATRIX` documents that role as *"Review submissions
  on forms they collaborate on; **may also encode** + export those forms"*, and it holds `submissions.create`
  accordingly — but `SubmissionPolicy::create()` has required `forms.edit.any` or **EDITOR** capacity since
  the G10a tightening, and a reviewer's grant is reviewer capacity. So a Reviewer can encode on **no form at
  all**, and the comment describing their role has been wrong since G10a. Not live in the sense of a leak —
  the web app has always refused them — but M13 made the API agree with the web app, which turns a dormant
  contradiction into one an integrator will hit. **Deliberately not resolved**: both readings are defensible
  and choosing between them is an authorization decision (widen `create()` to accept reviewer capacity, or
  correct the seeder's sentence and drop `submissions.create` from the role), so it belongs to the user
  rather than to a defect fix. `SubmissionPolicy::create()`'s own docblock argues the tightening at length
  and notes *"no existing test asserted the old behaviour"*, which is why it went unnoticed. Filed by `M13`. **Live** — the seeded role description and the policy still disagree in the tree, so a reader resolving one against the other gets the wrong answer, judged by `M65`.
  ✅ **DONE — `M77` (2026-09-06), and the row's own framing is what turned out to be wrong.** ⛔ **THIS WAS
  NEVER "TWO DEFENSIBLE READINGS": IT WAS ONE CODE BEHAVIOUR AND FIVE DOCUMENTS DESCRIBING IT INCORRECTLY.**
  Corrected: the seeder comment, `docs/multi-tenancy-rbac-design.md`'s §3 role table, its §5 matrix row, its
  §8.3 shape sentence, and `docs/ACCESS-MATRIX.md`'s permission grid. **No access changed.** The narrowed
  product question — should the ROLE gain encoding, or is documenting the gap the whole answer — is `D19`.
  ⛔ **THE ROW'S SECOND REMEDY WOULD HAVE BROKEN A WORKING CONFIGURATION.** It proposed dropping
  `submissions.create` from the role. That permission is the coarse half a **reviewer-role member holding an
  EDITOR grant** needs in order to encode, and it is what entitles the `write:submissions` API ability;
  dropping it breaks the one composition that makes the role usable for a person who does both jobs.
  ⛔ **AND §8.3 WAS THE WORST OF THE FIVE, BECAUSE IT IS THE SECTION A READER TREATS AS AUTHORITATIVE.** It
  described `SubmissionPolicy`'s review check as running against `capacity = 'reviewer'`. `review()` calls
  `collaboratesWith()` → `ResourceGrantResolver::holdsAny()`, which loops `ResourceCapacity::cases()` and
  accepts **either**. Were it capacity-specific, reviewing and encoding would be **mutually exclusive on
  every form**: `capacity` sits outside `resource_grants_target_user_unique` and a grant is replaced rather
  than added, so nobody can hold both capacities on one form.
  ⚠️ **A SIXTH SURFACE WAS FOUND AND IS ALSO CORRECTED**: §8's breadcrumb note said a Reviewer and a Viewer
  can both reach *"the encode screen"*, which is false for **both** — a Viewer holds no `submissions.create`
  at all. The breadcrumb defect that note actually describes is real and untouched.
  ⛔ **AND THE ROW'S "NO TEST" PREMISE IS FALSE — AS WAS THIS INCREMENT'S FIRST DRAFT OF ITS OWN FIX.**
  The G10a case *"requires EDITOR capacity to manually encode"* in
  `tests/Feature/Submissions/SubmissionPolicyTest.php` has pinned the refusal all along; `M13`'s row, and
  **both arms of `M77`'s read-only fan-out**, all missed it, and one arm proposed adding the case that was
  already there. So the defect was **purely documentary**. The new case earns its place on a narrower
  claim: it asserts the coarse permission IS held, and it proves the reviewer-plus-editor-capacity
  composition with **one** member — the G10a case proves the editor half with a *form_editor* user, so
  nothing anywhere covered that configuration or the capacity-insensitivity of `review()`.
- ✅ **CLOSED BY `M68` (2026-09-03) — `minor` · ~~Neither sync route documents the 403 its in-controller policy gate now returns.~~**
  `GET /sync/manifest` now documents its `403` through the shared `AuthorizationException` component.
  ⛔ **THE ROW'S PREMISE IS HALF FALSE, AND THE CORRECTION IS WHAT SET THE SCOPE.** Only the `GET` can answer
  an HTTP 403. `SyncSubmissionController` returns a per-item `error.code: "forbidden"` **inside a 200 body** —
  it is not a 403 at all, and documenting one on `POST /sync/submissions` would have published a status that
  route has never returned, which is the defect the `SyncSubmissionResultResource` row below describes
  pointing the other way. **That half is refined onto that row rather than widened into this one.**
  ✅ **THE ROW'S STATED BLOCKER WAS TRUE WHEN WRITTEN AND STOPPED BEING TRUE IN `M67`.** It says the honest
  fix needs *"an annotation mechanism Scramble 0.13 does not offer for arbitrary status codes"*. `M67` built
  that seam one route over, and half of it already existed for this status:
  `ApiAuthorizationErrorResponse` extends Scramble's own `AuthorizationExceptionToResponseExtension` and has
  been registered since `M56`. **So the whole change is one `@throws` tag and no new class**, and the
  existing `$ref` is reused rather than a second 403 minted.
  ✅ **MEASURED IN THE CORRECT DIRECTION.** With the tag removed the fresh export drops back to
  `200/404/422`. `openapi.json` moved by exactly one operation and zero components, every component group
  byte-identical.
  ⚠️ **THE COVERAGE WAS NEVER THE GAP; THE DOCUMENT WAS.** A behavioural 403 case has existed in
  `SyncApiTest` since `M13`. What was missing was a document assertion, so `M67`'s `@throws` route walk was
  **extracted** rather than copied and now serves both statuses, with each arm keeping its own floor — a
  shared floor would be satisfied by the other arm's exceptions.
  ➕ **A MEASURED LIMIT OF THAT SWEEP, filed as its own row below**: it cannot see the loss of ONE of two
  declared causes, because either tag alone keeps the route in scope and the status documented. Found when
  a control removing a single `@throws` from the promote action SURVIVED.
  **`minor` · Neither sync route documents the 403 its in-controller policy gate now returns.** Filed
  2026-08-25 by M13. `openapi.json` lists `200/404/422` for `GET /sync/manifest` and `200/422` for
  `POST /sync/submissions`, while the first can return a `403 forbidden` and the second a per-item
  `error.code: "forbidden"`. Scramble infers a 403 from route **middleware** and does not trace a
  `Gate::forUser()->authorize()` call in a controller body — which is measurable rather than assumed:
  `POST /form-templates` has carried exactly that call since G9a and documents only `200/422` too. **This is
  the same row as the already-open one for `/submissions/{submission}/promote`'s three undocumented 409
  causes**, one layer over, and it is unfixed for the same reason: `openapi.json` is generated and CI diffs
  it against a fresh export, so a hand edit fails the contract job — the honest fix is an annotation
  mechanism Scramble 0.13 does not offer for arbitrary status codes, or moving these gates somewhere the
  generator can see them. **Live**, pre-existing in kind. Filed by `M13`.
- ~~**`minor` · `SyncSubmissionResultResource`'s generated contract types `submission` and `error` as bare
  strings.**~~ Filed 2026-08-25 by M13. Both are object-or-null in every response the controller builds —
  `submission` is `{id, reference, status}`, `error` is `{code, message, details?}` — but Scramble infers a
  `string` for each, so `openapi.json` describes a shape no response has ever had. An integrator generating
  a client from the contract gets types that fail to deserialise on the first item. **Live**, pre-existing
  since G8b, and the reason M13's per-item error codes could be added without moving the document at all:
  they are not enumerated anywhere. Same `openapi.json`-is-generated constraint as the row above. Filed by `M13`.
  ➕ **WIDENED BY `M68` (2026-09-03), WHICH TOOK THE 403 ROW ABOVE AND FOUND HALF OF IT BELONGED HERE.**
  That row's title claims *neither* sync route documents "the 403". Only the `GET` can answer an HTTP 403:
  `SyncSubmissionController` returns a per-item `error.code: "forbidden"` **inside a 200 body**, and
  documenting a 403 on `POST /sync/submissions` would have published a status that route has never
  returned. **So the POST half is not a missing status — it is this row**, one field deeper: `error` is
  typed as a bare string, so the four codes an integrator must branch on (`forbidden`, `submission_invalid`,
  `submission_conflict`, plus the unknown-version arm) are not enumerated anywhere in the contract.
  ⚠️ **AND THE MECHANISM IS NO LONGER MISSING, WHICH CHANGES THIS ROW'S COST RATHER THAN ITS SHAPE.** The
  reason both rows were parked was *"`openapi.json` is generated and CI diffs it against a fresh export, so
  a hand edit fails the contract job"* — still true, and no longer the end of the argument: `M67` and `M68`
  documented two statuses by teaching the GENERATOR, and `SyncSubmissionResultResource` is a
  `JsonResource` whose `toArray()` Scramble already infers. The candidate is a typed shape on that method
  rather than an annotation, and it is untried. **Whoever takes it should re-read the 403 row's closure
  above first**, because the two were filed as one defect and are not one.
  ✅ **DONE — M69 (2026-09-04). THE EVIDENCE HELD, THE ROW UNDERSTATED ITSELF TWICE, AND THE DIRECTION IT
  PRESCRIBES IS THE ONE THING THAT DOES NOT WORK.** `openapi.json` now publishes `submission` and `error`
  as nullable OBJECTS with their real properties, and `status` as a `$ref` to a new
  `App\Enums\SyncResultStatus` enumerating all five outcomes.
  ⛔ **THE PRESCRIBED REMEDY — *"a typed shape on that method rather than an annotation"* — WAS TRIED
  FIRST AND MOVED THE DOCUMENT BY EXACTLY NOTHING.** A full `@return array{…}` shape on `toArray()` left
  all four properties `type: string`. `dedoc/scramble` infers `toArray()` from the STATEMENTS it can
  trace, and **a docblock is not a statement**. What works is a literal it can trace. Two corollaries,
  both measured: `BackedEnum::from()` returns `static`, which the inference does NOT resolve, so an
  explicit `self` return (`SyncResultStatus::fromWire()`) is what turns the property into a `$ref`; and
  **every comment inside the returned literal is PUBLISHED as that property's `description`** — a draft
  shipped an eight-line note about `when()` into the exported contract.
  ⚠️ **THE ROW UNDERSTATES ITSELF TWICE.** (1) `status` was a bare string too, and it is the FIRST thing
  an integrator branches on. (2) `M68`'s widening says *"the four codes an integrator must branch on"*;
  the controller emits at least **nine**.
  ⚠️ **AND THE OBVIOUS WAY TO WRITE THE FIX CHANGES THE WIRE FORMAT.** `$error['details'] ?? null` would
  have started emitting `details: null` on every refusal that carries none — `ApiErrorEnvelope`'s docblock
  says in terms that `details` is omitted and never nulled. `$this->when()` preserves the omission AND
  marks the property optional in the schema; `filter()` recurses into nested arrays, read in the installed
  framework rather than assumed. **No existing assertion on this surface could have caught that**, because
  Laravel's JSON assertions are all subset checks (`M56`) — which is why the new pins compare `array_keys`.
  ⛔ **ENUMERATING `error.code` IS NOT TAKEN AND IS FILED SEPARATELY** — see the row below.
- **`minor` · The per-item `error.code` is the integrator's real branching key on `/sync/submissions`, and
  the contract still publishes it as an unconstrained string.** Recorded 2026-09-04 **at the moment it was
  decided not to take**, rather than left in the release that found it. `M69` closed the row above by
  teaching the generator a traceable literal, which fixed the SHAPE — `submission`, `error` and `status` —
  and cannot reach this. **Measured: `SyncSubmissionController` emits at least nine codes** —
  `form_version_not_found`, `form_not_found`, `forbidden`, `submission_invalid`, `submission_conflict`,
  `submission_version_superseded`, and `FormNotAcceptingSubmissionException`'s `form_not_open`,
  `form_closed` and `max_responses_reached`. `M68`'s widening of that row says *"the four codes"*, so the
  count in the ledger is itself low.
  ⛔ **WHY IT IS ITS OWN ROW AND NOT A LINE OF THE ONE ABOVE.** The two live fixes are different mechanisms.
  A traced literal can carry a TYPE and cannot carry a DESCRIPTION, and this repository's established
  answer for exactly this field is a description: `ApiErrorEnvelope::schema()` takes a `$codeDescription`
  precisely because *"the codes are the integration-consumer's branching key, so they are named rather than
  left as a string"*. Reaching that from a `JsonResource` needs either a `TypeToSchemaExtension` or a
  second enum, and **which is right is a design decision rather than a fix** — an enum would put the nine
  codes in one place but three of them are owned by an exception that computes its own `code()`.
  ⚠️ **The honest sizing is that this is worth less than it looks** — an integrator can already branch on
  `status`, which IS now enumerated, and `error.code` only narrows the `error` case. **Live.** Filed by `M69`.

- ~~**`minor` · The `@throws` contract sweep cannot see the loss of ONE of two declared causes.**~~
  ⛔ **CLOSED — `M78` (2026-09-06), AND NOT AS SPECIFIED. THE MECHANISM IS REAL, ITS CONSEQUENCE IS ALREADY
  COVERED, AND THE TREE'S ACTUAL HOLE WAS A DIFFERENT AND LARGER ONE.** The mechanism holds exactly:
  `$declared` is filtered and then consumed for EMPTINESS only, so its cardinality is discarded, and both
  arms' floors are `>= 1`. But *"the sweep cannot see it"* is not *"the repo cannot see it"*: case 4
  hardcodes the promote path and both codes, each code occurs in exactly one `anyOf` branch, and CI
  re-exports the contract and byte-diffs it — measured by simulated export, with an unmutated control
  proving the probe clean, a single-tag deletion moves the document by ~3,000 bytes. **No single-tag
  deletion reaches green CI.** ⚠️ Two honest limits on that cover: the branch ruleset has no
  `pull_request` rule, so CI *catches* a direct push rather than *blocking* it; and for a tag that merely
  duplicates what middleware already publishes, the cover degenerates to a four-line JSON key reorder.
  ⛔ **THE FINDING THAT MATTERS: THIS ROW AND ITS SUCCESSOR BOTH ARGUE ABOUT CARDINALITY INSIDE A DECLARED
  SET, AND THE LIVE DEFECT WAS AN UNDECLARED ONE.** `POST /public/f/{shareToken}/submissions` reaches
  `promote()` a frame down — its own body comment says so — and published only `form_updated`. It was
  invisible to case 4, to both sweep arms and to the export diff *simultaneously*, which is why four passes
  over this row could never have found it. ⛔ **And there were TWO**: `GuestDraftController::store()` passes
  `checkBaseline: true`, so `updateDraft()` throws `draftConcurrentlyModified()` a frame below it, and that
  route published only `form_updated` as well. The second was found by CHECKING the correction written for
  the first rather than repeating its census — a correction that reuses the census's method inherits its
  error. Both actions now declare the tag, the contract carries both branches on each, the sweep's scope
  grew from 2 actions to 4, and the contract suite went 113 → 115 assertions.
  ⛔ **`SubmissionRefusalResponseExtension`'s docblock is what made this invisible to reading**: it asserted
  the draft/submit/edit channels *"already document theirs"* because they return their envelopes inline.
  Exactly inverted — an inline envelope is evidence about the refusal the controller returns ITSELF and says
  nothing about one thrown a frame below, which is the whole reason that extension exists. Corrected in place.
  ⚠️ **No new gate was built, deliberately** — see the successor row's closure for why the obvious one
  cannot catch what was actually found here.

  Filed 2026-09-03 by `M68`, and **measured rather than predicted: a control removing a single `@throws`
  tag from `SubmissionPromoteController::store()` SURVIVED with all seven contract cases green.** Both arms
  of the sweep in `tests/Feature/Api/OpenApiContractTest.php` ask *"does this operation document status
  N?"*, and the walk keeps a route in scope if it declares **any** of the exceptions in the arm's list — so
  with `SubmissionConflictException` deleted and `SubmissionException` left in place, the route is still
  enumerated and the 409 is still published by the surviving tag. Removing **both** is CAUGHT.
  ⚠️ **What that leaves undetectable is a real regression shape**: an action that used to declare two causes
  and now declares one still documents its status, so the description Scramble renders narrows silently
  while the gate stays green. **It is not the same as the extension's own stated limit** — that one is about
  a route raising a SUBSET of a family's codes, which is a property of the family; this is about the tag
  list on a single action shrinking. ⛔ **The obvious fix is wrong and is recorded so it is not tried**:
  asserting one status per declared exception would demand a 409 twice on the same operation and pass
  vacuously. What would actually catch it is asserting the rendered `code` DESCRIPTION contains each
  declared cause's code, which means the sweep has to know how `SubmissionRefusalResponseExtension` composes
  that sentence — coupling a gate to a renderer, and a design decision rather than a fix. **Live.**
  Filed by `M68`.
  ⚠️ **PREMISE CORRECTED BY `M70` (2026-09-04) — THE COUPLING THIS ROW CALLS AN UNMADE DECISION IS
  ALREADY IN THE FILE, FOUR CASES ABOVE THE SWEEP.** `it('documents the 409 the promote route can
  actually answer, and names its causes')` flattens the whole 409 sub-document with `json_encode` —
  explicitly because an `anyOf` means a search over `properties` alone reads only the first branch — and
  asserts it contains both `draft_conflict` and `submission_version_superseded`. That IS *"assert the
  rendered description contains each declared cause's code"*, coupled to the renderer, for the promote
  route with the codes hardcoded. **What is genuinely undecided is only the GENERALIZATION**: deriving
  the expected code set from the docblock and applying it to every route the sweep walks.
  ⛔ **AND THE CONTROL SURVIVED FOR A REASON THE ROW DOES NOT GIVE, WHICH NARROWS THE UNDETECTABLE
  WINDOW A LOT.** Every case in that file reads the **committed** `openapi.json` from disk; nothing
  regenerates it. So a code-only mutation — deleting a `@throws` tag without re-exporting — is invisible
  to the arms that read the document, while `ci.yml`'s contract-drift step (`scramble:export` then
  `diff`) would have reddened, and case 4 would have reddened too had the document been regenerated.
  *"All seven contract cases green"* is a Pest-suite result being read as a repo-wide one. ⚠️ **Whoever
  takes this must make the export part of the control**, or it measures the committed file and proves
  nothing — and should note the arm's floor is `>= 1` with exactly one route in scope today, so a
  generalized assertion could be satisfied by that one route forever.
  ⚠️ **RE-VERIFIED AND DELIBERATELY NOT TAKEN BY `M73` (2026-09-05).** The mechanism holds exactly:
  `$declared` is consumed only for its emptiness, HTTP status is a single slot, and the two causes collapse
  onto one `409`. `M70`'s correction above also holds at both ends, and `M73` adds nothing to it — which is
  itself the finding, because it means **the only thing left for a taker is the in-suite gate, and the
  repo-level one is already green-or-red correctly.** Recorded so the next fan-out does not re-derive this
  a third time.
  ⛔ **ONE COST `M70` DID NOT NAME, AND IT IS A PROPERTY CHANGE RATHER THAN A LINE CHANGE.**
  `tests/Feature/Api/OpenApiContractTest.php` advertises *"No DB."* in its own header and applies no
  `RefreshDatabase`; `ci.yml` provisions and migrates a database for the export because it introspects the
  models for response shapes. **So "make the export part of the control" converts a deliberately DB-free
  file into a DB test**, which is a decision about that file rather than a step in this row.

- **`minor` · The sync surface's read and write are gated on different permission families, so no single
  non-admin role can complete the offline loop.** Filed 2026-08-25 by M13. `GET /sync/manifest` needs
  `read:forms`, which maps to `forms.create` / `forms.edit.any` / `forms.edit.own` — form **authoring**
  permissions. `POST /sync/submissions` needs `write:submissions`, which maps to `submissions.create`. A
  **Reviewer** holds the second and none of the first, so they can replay a batch but can never fetch a
  manifest to collect against; a Viewer holds neither. The offline-encoder story therefore works today only
  for Owner, Admin and Form Editor — which is a narrower audience than "authenticated encoder clients that
  collect offline" implies. **Not a defect and deliberately not changed**: widening an ability map is an
  authorization decision, and `ApiAbilities` records four separate refusals to widen an existing ability for
  exactly this reason (a new ability cannot be held retroactively; a widened one is). Recorded so the
  decision is taken deliberately if a Reviewer-facing encoder client is ever built. Filed by `M13`. **Not live** — a recorded authorization decision with five standing refusals to widen beside it, not a reachable defect, judged by `M65`.
- **`minor` · `promote()` re-asserts the version is published BEFORE the lock and never again under it.**
  Filed 2026-08-25 by M12, which closed the identical pre-lock shape one field over and deliberately did not
  fold this in. `SubmissionDraftService::promote()` checks `$version->status !== FormVersionStatus::Published`
  outside any transaction, and the in-lock block re-asserts only the row's own status and (since M12) the
  answer document's checksum — so an admin republishing between that check and the lock lets a draft finalize
  against a version the form has already moved past. **Live**, but narrow on purpose: the draft is pinned to
  that version and its answer row already points at it, so nothing becomes inconsistent — what is lost is the
  loud `409 submission_version_superseded` the check exists to produce, and the keyer re-renders one save
  later instead. The same is true of `assertCanPromote()`'s grace-window check one line below, which the
  grace window makes very nearly a no-op. Smallest fix is to move both re-assertions inside the transaction
  alongside M12's; the reason to weigh it rather than do it is that neither `form_versions` nor `forms` is
  locked there, so a re-read is a narrowing rather than a closure — unlike M12's, whose authority comes from
  the `submissions` row lock every writer of that document holds. Filed by `M12`.
- ✅ **CLOSED BY `M67` (2026-09-03) — `minor` · ~~`/api/v1/submissions/{submission}/promote` documents no 409 at all, and three causes reach it.~~**
  The operation now documents a `409` naming `draft_conflict` and `submission_version_superseded`, and the
  route has its first behavioural `409` case beside the document assertion.
  ⛔ **THE PRESCRIBED REMEDY DID NOT EXIST.** *"A `@response` annotation per cause"* is not a feature of the
  installed Scramble (v0.13.30) — read from the vendor rather than assumed. The real seam has two halves and
  needs both: `Infer\Handler\PhpDocHandler::leave()` collects `@throws` tags on the ACTION into the method
  type's exception list, and an `ExceptionToResponseExtension` renders them. Neither half alone does
  anything, which was **measured in the correct direction before the fix was kept**: with the extension
  registered and no `@throws`, the fresh export is byte-identical to the commit.
  ⚠️ **AND THE ROW UNDERSTATED ITSELF — it names three causes and there are five**, one of them attributed
  to the wrong guard (`assertCanPromote()` raises `closed()`, not `max_responses_reached`, which arrives a
  layer further down through `SubmissionFinalizer`). The two undocumented statuses that remain are filed as
  their own row below rather than widened into this change. `openapi.json` moved by exactly one path and
  zero components; a hand edit was never possible, because the Contract job exports fresh and diffs.
  Filed by `M12`.

- **`minor` · The promote route still documents neither of its two 403 causes nor its 422, and the
  generator cannot narrow a refusal family to one route.**
  Filed 2026-09-03 by `M67`, which documented the 409 beside these and deliberately stopped there.
  `POST /api/v1/submissions/{submission}/promote` can also answer **`403 form_closed`** (via
  `FormAcceptanceGuard::assertCanPromote()`), **`403 max_responses_reached`** (via
  `SubmissionFinalizer::finalize()` → `assertCapacity()`) and **`422 submission_invalid`**
  (`SubmissionValidationException::semantic()`). The document lists a `403` — but it is Scramble's generic
  `can:` inference, whose code description does not name either cause, and no `422` at all.
  **Live.** Two reasons it was not folded into `M67`: the 403 arm would have to merge with an existing
  documented response rather than add one, which is how a documentation fix becomes an unreviewed contract
  change (the guard `ModuleDisabledResponseExtension` exists for); and the deeper limit is structural —
  `SubmissionRefusalResponseExtension` is keyed on the exception CLASS and cannot know which of a family's
  causes a given route raises, so `M67`'s 409 honestly says an operation raises a subset. Narrowing per
  route needs a cause-level seam Scramble does not have; inventing one is a design decision, not a fix.
  Filed by `M67`.

- **`minor` · Four P3a refusal cases assert the exception CLASS and never the message.**
  `tests/Feature/Submissions/SubmissionDraftServiceTest.php` — the P3a section's `toThrow(
  SubmissionConflictException::class)` calls. Filed 2026-08-25 by M12, which is the second increment running
  to be bitten by this: M11's mutation pass proved that assertion passes for `contentConflict()` too, and
  `SubmissionConflictException` now carries FOUR causes of which two share the `submission_conflict` code —
  so only the message separates them on the wire. Those four cases are safe **today** for a reason that is
  not written down anywhere near them (the resolve finds the row, so `clientUuidClaimed()` is unreachable on
  that path), which is precisely the shape that stops being true after an unrelated change. Not a live
  defect; a live blind spot. M12's own seven refusal cases all assert the message. Filed by `M12`. **Not live** — a test-coverage question rather than a defect: each fixture can raise only its intended cause in today's tree, judged by `M65`.

- ✅ **CLOSED BY `M74` (2026-09-05) — `minor` · ~~Every object-valued answer that the piping layer excludes renders as `json_encode` machine
  noise on the inbox, the export and the PDF — because those three surfaces have no exclusion and no
  display arm.~~** ⛔ **FOUND BY `M48` INSIDE THE `## Next Session` BLOCK IT WAS ARCHIVING, RECORDED THERE
  SINCE `G6` AND FILED NOWHERE ELSE** — the J4b1 shape, and it would have become invisible to a *file*
  search the moment that block moved. `SchemaValueFormatter::displayValue()` has arms for `YesNo`, for geo
  (`formatGeo()`) and for option-bearing choice types; everything else falls to `scalar()`, which is
  `is_scalar($value) ? $value : (string) json_encode($value)`. `FieldType::isMedia()` exists and this class
  never calls it.
  ⚠️ **THE RECORD SAID "MEDIA"; THE MEASUREMENT SAYS SEVEN FIELD TYPES.** `App\Enums\PipingEligibility`
  excludes exactly two object-valued families for exactly this reason, in its own words — matrix/likert
  *"would reach displayValue()'s is_array branch and json_encode each row into the middle of a question"*,
  and the attachment envelopes *"would fall through to its json_encode scalar fallback and render machine
  noise into a question"*. So `FileUpload`, `ImageCapture`, `AudioCapture`, `VideoCapture`, `Signature`,
  `Matrix` and `LikertMatrix` all reach the fallback. Geo does not — it has an arm.
  ⛔ **THE PIPING LAYER IS THE ONLY CALLER THAT IS SAFE, AND IT IS SAFE BY EXCLUSION RATHER THAN BY
  RENDERING.** Four call sites reach `displayValue()`: `TemplateRenderer` (guarded by `PipingEligibility`),
  and `SubmissionInboxPresenter`, `SubmissionRowProjector` (the streamed CSV/XLSX) and
  `SubmissionPdfPresenter` — **none of which consults `PipingEligibility` or any equivalent**. A tenant
  reviewing a photo answer, exporting it, or printing it gets a JSON blob where a filename belongs.
  **Pinned by no test.** ⚠️ **The remedy is a display arm, not an exclusion** — a submission export may not
  simply omit an answered question the way a template may decline to pipe one — so it is a decision about
  what a media cell *says* (filename? count? a signed link?), which is why this is filed rather than fixed.
  Cited by symbol throughout, deliberately: the anchors here have moved once already. Filed by `M48`.
  ⛔ **AS BUILT BY `M74` (2026-09-05), AND THE ROW UNDERSTATED ITSELF IN THREE SEPARATE WAYS.**
  (1) **Its stated MECHANISM is wrong**, and the fix depends on the correction: an object-valued answer
  never reaches the trailing `scalar($answer)` fallback. It is an array, so it hits the `is_array` join
  FIRST, which applies `scalar()` **per element**. The arms therefore sit above that join and below the
  empty guard — the position discipline the geo arm had already documented for itself.
  (2) **"Seven field types" is six, and the seventh is worse than the six.** `likert_matrix` stores
  `{row:score}` with SCALAR leaves, so it produced **no JSON at all** — just `4; 5; 3`, every row label
  silently dropped and indistinguishable from a legitimate multi-select. That is plausible **wrong data**,
  not machine noise, and ⛔ **a gate asserting "the output contains no JSON" is GREEN against it** — which
  is why the vectors pin whole strings. No row had ever named this case.
  (3) **"Four call sites" is a floor and the blast radius is six surfaces.**
  `SubmissionRowProjector::answerValues()` has THREE callers — `SubmissionExporter`,
  `GoogleSheetsConnector` and `AirtableConnector` — so the noise was being written into third-party
  **systems of record**, where it cannot be un-pushed without a re-sync.
  ⛔ **AND A SECOND ENGINE EXISTS THAT THE ROW DOES NOT NAME.** `resources/public-runtime/engine/display-value.ts`
  is a byte-parity twin, publicly exported, with the same missing arms; its own header warns that an
  incomplete twin fails invisibly. There is no `display-value.test.ts`, so `tests/golden/templates/formatting.json`
  is not *one* of the ways to redden it — it is the **only** way. Nine vectors added, manifest moved in lockstep.
  **As built:** a fifth no-`default` `match` enum, `App\Enums\AnswerEnvelope`, so an unclassified field type
  is a PHPStan level-8 error. A predicate was rejected deliberately: `isMedia()` carries `default => false`,
  and this row exists *because* two families were added and nobody added an arm.
  ⚠️ **`AnalyticsFieldEligibility` is NOT the same partition** and reusing it ships a regression — it groups
  `MultiSelect` and `CascadingSelect` with the grids, which reddens two existing vectors.
  👤 **The user was asked and chose ONE rendering on every surface, connectors included.** The rendering
  itself was not a new call: `FieldInput.vue` had already decided `name ?? mime ?? 'Attached file'` in terms.

### Gamification

- ✅ **CLOSED BY `M24` (2026-08-26, straight to `main` as `be55d16` + `d1d1d72`, no PR, CI 6/6) — `major` · ~~The backfill awards review points for two
  verbs the live engine never scores.~~** `AuditReplayMap::submissionUpdate()` now requires the `remarks`
  marker **and** a status in `SCORED_REVIEW_STATUSES` — `['approved', 'returned']`, which is exactly the pair
  the live listeners fire on. ADR-0020 gains **§D12**, correcting §D10(a)'s *"a review always carries
  `remarks`"* in place: every clause of that sentence is true, and it treats one act as four, which is where
  the map was built from and where the next reader would have rebuilt it.
  ⚠️ **THE ROW'S MECHANISM WAS EXACT AND THREE OF ITS FOUR CONSEQUENCE CLAIMS WERE NOT** — the twelfth row
  running to be wrong about itself, and the second running to be wrong about *cost* rather than *facts*.
  **(1) The 400-row retention sweep does not exist.** `archive()` has exactly one caller
  (`SubmissionReviewController.php:35`), one submission per HTTP request; 400 archives is 400 human clicks,
  not a sweep. The true floor is far sharper and the row never found it: `BadgeKey::FirstReview` is
  threshold **1**, so a *single* claimed-but-never-reviewed submission already minted a review badge.
  **(2) Badges never read points.** `BadgeAwarder::awardsOf():213-227` counts award ROWS of one rule against
  `BadgeKey::threshold()`; the row's "1,200 points and the `reviewer` badge" invites a reading of the
  mechanism that does not exist. **(3) "Not a double-award" is vacuous for the verb the row leads with** —
  `archived` is terminal and absent from `approve()`'s source states, so there is no later approval for the
  index to refuse. Conversely the row **understates** the blast radius: the command defaults to every active
  workspace, `--dry-run` cannot reveal it (below), and `StreakCalculator`'s day walk has no rule predicate,
  so spurious awards extended **streaks** too.
  ⛔ **AND THE FIX'S REAL COST WAS THE PART THE ROW COULD NOT SEE.** `snapshot()` is one fixed six-key
  literal serving all four verbs and `AuditLogger::record()` stores it without diffing, so `newValueKeys` is
  byte-identical across them: **key shape was structurally incapable of discriminating**, and the map was
  never reasoning badly — it was being handed insufficient evidence. `ReplayableAudit` therefore admits one
  value, `new_values.status`, and **deliberately not** the `LEFT JOIN`ed `s.status` already in scope, which
  is *current* state and would have erased legitimate awards instead of spurious ones.
  ⚠️ **THE NEAR-MISS IS WORTH MORE THAN THE FIX.** `DemoSeeder:1027-1029` and `E2eSeeder` write
  `('submission','updated')` with `['status' => 'approved', 'guest_contact_email' => …]` — status-bearing and
  **marker-less**, fully creditable in a seeded database. So *"two writers of this tuple"*, asserted by both
  the map's docblock and §D10(a), is true of `app/` and **false of the table the backfill actually reads**.
  Had the fix replaced the marker with the status test — the obvious simplification — the first backfill of a
  demo tenant would have minted a **brand-new** false award. The conjunction is now §D12(c-bis) and a unit
  case pins the seeder's exact payload. **The backfill does not read application code; it reads `audits`,
  and a census by grepping `app/` is a floor.**
  **Gates:** AuditReplayMapTest 29 → **36**, BackfillTest 13 → **16**. Mutation harness run twice from a
  committed mechanism with a sha256-verified byte restore: *never refuses* and *never accepts* redden **5
  each**, intersecting in exactly the one call-site test that asserts both directions. **Both call-site
  tests are red under mutation A**, which is what makes the wiring asserted rather than assumed — and their
  absence is why this survived three increments: every prior assertion described a payload somebody typed,
  including `BackfillTest`'s own helper, whose review fixture happened to carry `'status' => 'approved'` and
  was green before and after. Filed by `M1`.
- **`minor` · `gamification:backfill --dry-run` cannot reveal a mis-scoring defect, by construction.**
  Filed by `M24` rather than fixed, because it is a reporting-shape row in `BackfillTally` and the command,
  not a scoring row. `BackfillTally` (`app/Services/Gamification/BackfillTally.php:27-36`) carries
  `scanned / created / existing / unmapped / uncredited` and **no per-rule breakdown**, so the rehearsal the
  command advertises (`BackfillGamificationCommand.php:49` — *"real numbers, no writes"*) prints a clean,
  balanced tally while every spurious `submission.reviewed` row is invisible in it. An operator doing exactly
  the cautious thing learns nothing. **Not live** — this is a blind spot rather than a defect, and the one
  defect it hid is fixed. Cheapest honest shape is a per-rule counter on the tally plus one line in the
  command's output; it moves `BackfillCommandTest` and pairs naturally with the open row below about that
  file's `Queue::assertPushed(…, 2)` asserting job count alone.
- ✅ **CLOSED BY `M26` (2026-08-26) — `major` · ~~`standing.of` discloses the workspace headcount with no permission at all.~~**
  Fixed. `MemberStanding::$of` is now `?int`, withheld by `withoutHeadcount()` for a reader without
  `dashboard.org.view` — the same key `/dashboard`'s Members tile, `/members` and the member search arm
  already answer this question with. No new ability and no thirtieth permission key: the gate reuses
  `can('viewAny', PointAward::class)`, resolved once and read twice, so `standing.of` and
  `scoreboard.team.active_members` cannot disagree again. `rank` deliberately survives — it discloses a
  floor, not the headcount, and §D7's actual grant is a member's own position. Recorded as **ADR-0020 §D13**;
  the product call is `D3` in `docs/claims/decisions.md`, filed with this as the recommendation and proceeded
  on rather than waited for.
  ⚠️ **THE ROW'S ROUTE CITATION WAS WRONG AND ITS HEADLINE EXAMPLE WAS INVERTED.** `routes/api.php:440-442`
  is comment prose; the route is `:456-458`. And the row offered `/dashboard` as the surface that *correctly
  withholds* the integer — **it disclosed it too**, at `DashboardController.php:124`, in the same Inertia
  payload whose `kpis.members` is nulled four lines above for the same reader. That sixth consumer was
  outside the row's census of five, and **nothing rendered it**: `Dashboard.vue` declared `rank` and `of` and
  its card shows points, badges and streak. Both fields are deleted rather than gated. Filed by `M1`.
- **`minor` · the dashboard card ranks the whole tenant to compute three numbers that need no ranking.**
  Filed by `M26` rather than fixed. `DashboardController::gamificationProgress()` calls
  `LeaderboardService::standingFor()`, which is a roster read plus two grouped aggregates over the whole
  workspace, and its own cost paragraph justifies that expense **because the card needed a rank**. §D13
  deleted `rank` and `of` from that payload, so the three surviving fields — points, badges, streak — are
  properties of one member: `SUM(points)` and `COUNT(*)` for one `user_id`, plus the streak walk that already
  runs separately. **Not a defect and not live** — the numbers are correct and the reads are indexed on
  `tenant_id`; it is now-unnecessary work on a page every member loads. Left alone deliberately, because
  `LeaderboardService` is also what makes this card and `/achievements` agree *by construction* rather than by
  two implementations happening to match, and trading that away is a behavioural change that should not ride
  a security fix. Cheapest honest shape is a `pointsAndBadgesFor()` on the same service so one class still
  owns both readings; it moves `AchievementsPageTest`'s dashboard-card case. **Not live** — a ranking shape rather than a defect — the three numbers it produces are correct, only expensively, judged by `M65`.

### SSO, auth & session

- ✅ **CLOSED BY `M2` (2026-08-18, PR #180 `0028ea1`, CI 6/6) — `major` · ~~AN EXPIRED IdP SIGNING CERTIFICATE KEEPS AUTHENTICATING
  ASSERTIONS FOREVER, WHILE THE SETTINGS PAGE RENDERS IT AS EXPIRED.~~** The first row taken from this
  section, and the first increment cut from `main` as trunk. `SsoLoginService::consumeAssertion()` now calls
  `SsoCertificateInspector::signingState()` as **step 0**, ahead of the whole sequence, and refuses with a new
  `SsoFailureReason::IdpCertificateUnusable` when no stored certificate is currently usable — ADR-0016 §D11's
  roll-up rule applied on the login path, exactly the shape `docs/security-threat-model.md` §9 item 18 had
  named and deferred. Both intents inherit it, since `consumeAssertion()` is shared. §8's row and §9 item 18
  move from **Residual** to **Mitigated**, and ADR-0016 gains **§D31**.
  ⚠️ **REPRODUCED BEFORE IT WAS FIXED, AND THE REPRODUCTION IS WHY THE FIX IS TRUSTED.** A throwaway case
  drove the real `GET /sso/saml/login` → `POST /sso/saml/acs` round trip against an anchor whose only
  certificate had expired, with the assertion signed by **that certificate's own private key** — it produced
  a session on `/dashboard`. The same case now answers 404. `FakeIdp` gained a third keypair to make it
  possible; ⚠️ **`openssl_csr_sign($csr, null, $key, 0)` is the only way to mint an already-dead certificate
  here** (a negative day count returns `false`, and PHP's OpenSSL API cannot set `notBefore`), and **not**
  `travelTo()` — php-saml validates timestamps against `time()`, which Carbon does not move.
  ⛔ **THE ROW WAS RIGHT THAT THIS IS "one call from `SsoLoginService`", AND THE CALL DRAGGED A MIGRATION
  BEHIND IT.** `2026_08_15_000002` CHECK-constrains `sso_auth_failures.reason` to `SsoFailureReason::values()`,
  so the new reason needed `2026_08_17_000105` — without it the guard raises a **23514 while being recorded**,
  turning the uniform 404 into a 500 on the one endpoint anyone on the internet can post to. Third instance of
  that shape, after M1's `…000104` and K1b's `…000103`.
  ⚠️ **WHAT IS NARROWED RATHER THAN CLOSED, STATED SO NOBODY READS THE ROW AS FULLY DISCHARGED:** while at
  least one certificate in the set is valid, an **expired sibling can still verify a signature** — php-saml
  receives the whole set, and §D31 records why filtering it was rejected (a successor is legitimately
  not-yet-valid by minutes during a rotation, so filtering on our clock would make skew into an availability
  control). §D10's atomic whole-half import is what stops a set accumulating dead keys. **The residual is
  asserted as a passing sign-in in `SsoAcsWebTest`**, so narrowing it later shows up as a failing test rather
  than as a surprise. Revisit trigger: the first tenant observed holding a mixed set.
  ➕ **THREE THINGS THE ROW DID NOT NAME, ALL FIXED WITH IT.** (1) The `invalid_assertion` hint told admins
  *"this is what an expired or rotated signing certificate looks like"* — half false, because an expired
  anchor produced a **session**, not that row; narrowed to the rotated case. (2) The settings card's warnings
  for the three unusable states read as an errand (*"re-import to pick up the replacement"*) and now open with
  **"Sign-in is refused"**; `expiring_soon` is deliberately untouched, because that tenant can still sign in.
  (3) `docs/data-dictionary.md`'s `subject_email` "populated only by" list had been **stale since M1** —
  `existing_account_not_member` populates it and was not listed.
  **Gates:** SSO suite **180 → 193 (1,110 assertions)**, PHPStan delta **zero** (all 18 are pre-existing
  `property.notFound` phantoms, none in a file M2 touches), four lint gates green (migrations 107 → 108),
  `openapi.json` byte-identical, zero `.vue` / `.ts` / e2e-selector movement. Filed by `M1`.

- ✅ **CLOSED BY `M10` (2026-08-24) — `major` · ~~THE "LOG OUT" CONTROL ON THE EMAIL-VERIFICATION PAGE HAS
  NO CSRF TOKEN, SO IT 419s.~~** `VerifyEmail.vue:97` was a raw `<form method="POST" action="/logout">`;
  a native submission carries no `_token` and no `X-XSRF-TOKEN` (only Inertia's axios layer supplies them),
  and `bootstrap/app.php` exempts exactly one path — the SAML ACS. So the **only exit from an interstitial
  every newly registered account lands on** answered 419, and had since PR #6. It now uses the page's own
  idiom — `useForm({}).post('/logout')` behind `@submit.prevent` — which keeps `MdsButton`, gains a
  `processing` state, and leaves the DOM element-for-element identical apart from two dropped attributes,
  so the axe scan of this exact page could not move.
  ⚠️ **THE ROW WAS RIGHT VERBATIM AND THE SHAPE GREP CAME BACK CLEAN, WHICH HAD NOT HAPPENED IN FIVE
  INCREMENTS.** Sweeping the form-element shape across all **140 `.vue` files** under `resources/` plus
  `packages/design-system/src` found **no second instance** — M8's row named one call site and had two,
  M9's the same. What the sweep did establish is what the gate below is built on: the design system has
  **zero** form elements; `resources/public-runtime/` has two, both `@submit.prevent`; **`TopNav.vue:77`
  is a deliberate `method="GET" action="/search"`** progressive-enhancement form that must keep working;
  and every non-Inertia network call in the tree (`builderClient.ts:41`, `useServerAutosave.ts:107` and
  `:364`, `MediaInput.vue:199`) already reads the `XSRF-TOKEN` cookie. Four of five `/logout` call sites
  were already correct.
  ⛔ **THE REJECTED FIX IS RECORDED IN TWO PLACES BECAUSE IT IS THE TEMPTING ONE: adding `/logout` to
  `validateCsrfTokens(except: …)`.** It resolves the 419 by REMOVING a control from a session-destroying
  endpoint rather than by using it — the `EnforcePlatformMaintenance` path-list lesson, in the direction
  that costs you something. The defect was in the CALLER. `bootstrap/app.php` now says so beside that
  array, where somebody reaching for it would be standing.
  ➕ **THE CLASS GATE IS WHY THIS IS NOT A THREE-LINE ROW — NOTHING IN SIX CI JOBS COULD SEE IT.** The axe
  gate renders this page in both themes and scans it **without clicking**, so a control that 419s scans
  identically to one that works; Pest cannot assert the 419 at all, because `ValidateCsrfToken`
  short-circuits on `runningUnitTests()` and every feature test in the repository therefore posts
  tokenless; `vue-tsc` never reads an attribute. So M10 adds
  `resources/js/__tests__/native-form-submission.test.ts`, a source-text invariant over every `.vue` under
  `resources/`: **a native submission must be a GET.** ⚠️ **It asserts its own non-vacuity** — a floor on
  the files walked and the forms parsed, plus a positive control that it still recognises TopNav's GET
  form — because a scan that silently matched nothing reports `passed` and is indistinguishable from one
  that ran. ⚠️ And it masks everything outside the SFC template block, because
  `ImpersonationBanner.vue:26` *spells* a form element in a script comment while explaining why it did not
  use one; a gate that reports the one file documenting the correct decision gets deleted, so that case is
  pinned too.
  ➕ **AND THE ADVERSARIAL PASS FOUND A THIRD THING THE ROW DOES NOT NAME: the page's PRIMARY control had
  no test anywhere.** Grepping for coverage of the notice page's other two actions turned up the
  correction case and nothing at all naming `/email/verification-notification` — the button nearly
  everybody clicks had never been driven. It works; it is now asserted, with `assertSentOnDemand`, because
  `sendEmailVerificationNotification()` deliberately never makes the User the notifiable (ADR-0007 §D5).
  ✅ **Cleared with evidence rather than left unstated:** `ConfirmPassword.vue` has no exit control and is
  **not** a lockout — `password.confirm` guards individual routes, never a whole group, so ordinary
  navigation still works, unlike `verified` which redirects from everywhere. And `RequirePlatformHost` sits
  on the whole Fortify group, so on a custom domain the notice page and `/logout` **404 together** rather
  than the page rendering with a dead exit.
  **Gates:** two new Vitest files, **8 cases** (`resources/js` chunk **58 → 60** files, total **125 → 127**);
  two new Pest cases in `EmailVerificationGateTest` (**10 → 12**); PHPStan delta **zero**; four lint gates
  unchanged; `openapi.json` byte-identical; and **zero movement in `tests/e2e/`** — no spec selects on this
  form, this button or `logout`. Filed by `M1`.
- ✅ **CLOSED BY `M7` (2026-08-20) — `major` · ~~ADR-0019 §D11 ATTRIBUTES A SAML 2FA DECISION TO ADR-0016
  §D22, WHICH DECIDES THE OPPOSITE POLARITY — AND THE AS-BUILT BEHAVIOUR IS RECORDED IN NO ADR AT ALL.~~**
  **ADR-0016 gains §D32**, which states what P1b built and this repository had never decided: a SAML
  sign-in does not challenge a member's PERSONAL second factor, because the identity provider is the
  authentication authority at that door. **User decision of record, 2026-08-20**, taken on the evidence
  rather than as a new position — §D22 already gives an SSO session's *re-authentication* to the IdP via
  `ForceAuthn` rather than to a local credential, so login is that principle one step earlier in the same
  flow; `docs/security-threat-model.md:193` already called the SAML side *"a deliberate divergence"*; and
  the 2026-08-14 Google decision was itself framed as a divergence **from** this.
  ⚠️ **THE ROW'S OWN EVIDENCE HAD GONE STALE, IN EXACTLY THE WAY THE ROW IS ABOUT.** It cited `0016:168`;
  M2's §D31 had since pushed that sentence down by seventy lines (`git show 0028ea1~1:docs/adr/0016-saml-sso.md`
  puts it back). A row about an unstable citation, carried by an unstable citation — which is the argument
  for the fix that was taken: **the sentence needed its own § heading so it could be cited stably**, which
  is 7(g)'s *"cite the FILENAME, never the bare number"* one level further down.
  ⚠️ **AND A CORRECT § NUMBER ALONE WOULD NOT HAVE FIXED §D11, BECAUSE THE QUOTATION WAS WRONG TOO.** The
  sentence §D11 quoted is a **Consequences** bullet about the **org-level** control (`EnforceTenantTwoFactor`),
  which ADR-0016 pointedly does *not* exempt for SSO — so it argued the opposite of what it was offered
  for. §D11 is requoted as well as repointed, and now records that it was the source of the attribution.
  The bullet itself gains a clause naming which of the two controls it answers.
  ⚠️ **THE ROW NAMED FIVE MIS-CITING PASSAGES AND THERE WERE TWELVE, ACROSS SIX FILES — ONE THE SOURCE
  (§D11 ITSELF) AND ELEVEN THAT INHERITED IT.** The six it did not name, given as
  anchors rather than line numbers because **this diff moved four of them**: ADR-0019's *Related ADRs*
  entry; both second-factor rows in `security-threat-model.md`; `ACCESS-MATRIX.md`'s *"Personal 2FA still
  applies"* bullet; `GoogleSessionStarter`'s *"PERSONAL TWO-FACTOR STILL APPLIES"* docblock; and
  `GoogleSignInWebTest`'s *"THE DELIBERATE DIVERGENCE"* comment. ⛔ **AND EIGHT *CORRECT* §D22 CITATIONS SIT IN THE SAME
  GREP** — `SsoStepUpController`, `SsoStepUpService`, `SsoAuthOutcome`, the step-up migration,
  `routes/tenant.php:357`, `SsoStepUpWebTest` and ADR-0016's own two — so this was decided by **reading
  each line, never by sweeping a pattern**, which is PR #153's 133-reference shape in miniature.
  `PROGRESS_ARCHIVE.md` keeps what was believed at the time.
  ⚠️⚠️ **AND THIS INCREMENT REPRODUCED THE VERY DEFECT CLASS IT CLOSES — FOUR TIMES, IN ITS OWN PROSE.**
  A +59-line §D32 and a +10-line middleware docblock invalidated M7's own `0016:238`, its `0019:37` /
  `ACCESS-MATRIX.md:397` evidence list, **and two LIVE rows in this file that M7 never read** — one of
  them `EnforceTenantTwoFactor.php:33-52`, the sole evidence for the *enrolment-nudge* argument §D32
  leans on twice. M7 had explicitly checked which citations its insertion would move and answered
  *"nothing else moves"* — true of the citations that already existed, and silent about the ones the same
  commit was adding. **"Which citations does my edit invalidate" must include the ones the edit is
  adding**, and the durable fix is the one applied here: **anchor on quoted text and symbol names, not on
  line numbers.**
  ➕ **THREE THINGS THE ROW DID NOT NAME, ALL DEALT WITH.** (1) `EnforceTenantTwoFactor`'s opening docblock stated
  *"Fortify already challenges an enrolled user at login"* — **false for the SSO door**, and load-bearing
  for its own "re-challenging per request would be theatre" argument. Rewritten to name which doors
  challenge and which does not; the middleware's behaviour is untouched, because checking the enrolment
  flag is right regardless. (2) **The SAML polarity was asserted by no test at all** — `tests/Feature/Sso/`
  grepped zero for `two_factor_confirmed_at` while the Google side has been pinned since J3c2, so a later
  "make the doors consistent" change would have flipped a decision of record with the suite green.
  `SsoLoginCompletionWebTest`'s *"signs an enrolled member straight in"* now pins it, **and the pin was
  proved by inverting the code**: adding the `GoogleSessionStarter`-shaped fork turned the case red on
  `/two-factor-challenge`, after which the controller was restored byte-identical to HEAD. (3) The
  shape-grep for `Auth::login(` found two more session-minting doors. `ImpersonationSessionController` is
  correctly exempt (the operator's own unconditional MFA plus step-up is the authority).
  ⛔⛔ **AND `InvitationController` WAS CLEARED AGAINST THE WRONG PREDICATE — THIS INCREMENT'S OWN
  ADVERSARIAL PASS OVERTURNED IT, AND THE CORRECTION IS LEFT VISIBLE RATHER THAN QUIETLY DELETED.** The
  reasoning recorded here was *"an already-verified invitee must already be authenticated; a placeholder
  has no confirmed factor"*. Both halves are true and neither is the branch: `prepareAcceptingUser()`
  forks on **`email_verified_at`**, not on "is a placeholder", and an **enrolled-but-unverified** account
  is an ordinary reachable state. It is a live `major`, filed as its own row below and **reproduced
  before it was believed**. **A door survey is only as good as the predicate it checks each door
  against** — and writing "checked, not a defect" into the permanent record is what would have made the
  wrong predicate durable. The `Auth::login(` grep did its job; the reading of one result did not.
  ⚠️ **WHAT IS RATIFIED RATHER THAN CLOSED, STATED SO NOBODY READS THIS AS "2FA IS FULLY ENFORCED":** in a
  workspace with `security.require_two_factor` **on**, an enrolled member arriving through SAML clears the
  gate on the **enrolment flag alone** and never presents the factor. Not new and not a defect — the
  middleware is an enrolment nudge, the same reasoning that downgraded the `/api/v1` token-mint row below
  from `blocker` to `minor`. §D32 records it as the residual with its revisit trigger (a per-workspace
  *"our IdP already performs MFA"* setting), so a later reader gets it from the decision.
  **Gates:** SSO suite **193 → 194 (1,110 → 1,123 assertions)**, the new case **+1 / +13 in isolation**;
  PHPStan delta **zero**; four lint gates unchanged at **97 / 109 / 31 / 119** (no controller, migration or
  job added); `openapi.json` byte-identical; **zero `.vue`, zero `tests/e2e/` selector movement.** Filed by `M1`.
- ✅ **CLOSED BY `M8` (2026-08-20) — `major` · ~~ACCEPTING AN INVITATION AS AN ENROLLED-BUT-UNVERIFIED
  ACCOUNT MINTS A SESSION WITH NEITHER THE PASSWORD NOR THE SECOND FACTOR, AND SILENTLY OVERWRITES THE
  PASSWORD.~~**
  **The fix is `TenantMembershipService::identityIsEstablished()`, asked at BOTH doors** — the rendered page
  and the accept handler, which had agreed with each other consistently and wrongly. An identity is
  established by any POSITIVE record: a verified address, a confirmed second factor, a linked `google_id`,
  or a `tenant_users` row it actually joined. Anyone established is sent to sign in and returns to the
  invitation; only a never-used placeholder still reaches the password-setting arm.
  ⚠️ **THE PREDICATE THE ROW PROPOSED WAS PARTLY UNAVAILABLE, WHICH IS WHY THE FIX IS NOT WHAT IT SAYS
  BELOW.** *"A set password"* cannot be asked: `resolveOrCreateUser()` writes `Hash::make(Str::random(48))`
  into a NOT NULL column, so a placeholder's hash is indistinguishable from a real one — **the identical
  indistinguishability ADR-0016 §D22 already records, which means this repository has now paid for that
  fact twice.** `tos_accepted_at` is the tempting substitute and is worse: its only writer is
  `InvitationController` itself, so a self-registered member has it NULL and SSO JIT leaves it NULL, and
  using it would refuse exactly the people it appears to admit.
  ⚠️ **AND *"any prior membership"* NEEDED A MIGRATION THE CLAIM SAID IT WOULD NOT NEED.** `tenant_users`
  is the strict shape plus `unique(tenant_id, user_id)`, so inside the invited tenant that query is empty
  **by construction** — the invite row is the only row — and `meridian_auth` held `SELECT, UPDATE` on
  `users` and nothing else. `2026_08_17_000107` grants it `SELECT ON tenant_users` behind a role-scoped
  policy (ADR-0002 §D3's M8 amendment records why that cost was worth paying, and reconciles it with
  ADR-0019 §D1, which declined the same cost for a different benefit). **That clause is load-bearing, not
  decorative:** SSO and Google provisioning both stamp `email_verified_at`, so the column signals already
  cover those doors — what they miss is the ordinary case of a **verified member with no second factor who
  changes their email address**, and only the membership clause catches them.
  ⚠️ **THE CLAUSE CARRIES `joined_at IS NOT NULL`, AND THAT IS CORRECTNESS RATHER THAN CAUTION.** One
  placeholder exists per email address, so somebody genuinely new who is invited to two workspaces holds
  two `Invited` rows; a bare *"another row exists"* test would mark them established and lock them out of
  **both** password-setting arms — a dead end manufactured by the fix. `InvitationIdentityTest` pins that
  case, and pins it with a COMMITTED fixture, because `identityIsEstablished()` reads on `pgsql_auth` and an
  in-transaction row is invisible there — the permissive version of this test would otherwise have passed
  while proving nothing at all.
  **Proved by the committed inverse:** `git stash push -- app/Http/Controllers/Tenant/InvitationController.php`
  turns the five refusal cases red (the takeover POST answers `302 → /dashboard`) while both permissive
  cases stay green. ⚠️ **Scoping that stash to the CONTROLLER is the point:** stashing all of `app/`
  decapitated the migration's call to `TenantIsolation::authRoleRead()` and turned all seven cases into
  setup `Error`s with **zero assertions** — seven reds that proved nothing. A whole-directory stash can
  reproduce a *build* failure and read exactly like a reproduced *defect*.
  **Also shipped:** a `docs/security-threat-model.md` §8 row (it had none for invitation takeover) with two
  new residuals, the RBAC §7 lifecycle sentence that was the doc-level statement of the same bug, and nine
  prose sites corrected because the new GRANT falsified them — including RBAC §9 and `MemberSearchArm`,
  where *"granted `users` and nothing else"* was load-bearing in a mutation argument (see the new row
  below). **The seeded fixtures and `tests/e2e/` did NOT move** — the claim expected them to, and that
  expectation died with the predicate that produced it.
  ── the original row, kept verbatim below ──
  `InvitationController.php`'s
  `prepareAcceptingUser()` forks on **`email_verified_at !== null`**, and only that arm carries the file's
  sole identity check (`abort_unless(Auth::id() === $user->id, 403)`). The other arm validates a name and
  password from an **unauthenticated** request, force-fills them onto the existing `users` row together
  with `email_verified_at => now()`, and `accept()` then calls `Auth::login($user)` with no second-factor
  fork. The route group carries no `auth` and no `verified`, by design.
  ⚠️ **THE STATE THAT MAKES IT REACHABLE IS ORDINARY, NOT EXOTIC — "unverified" IS NOT "never registered".**
  `UpdateUserProfileInformation::updateVerifiedUser()` force-fills `email_verified_at => null` on **any**
  email change and touches nothing 2FA-related, and **no writer anywhere in `app/` ever clears
  `two_factor_confirmed_at`** (all twelve occurrences are reads or the model cast). So a fully enrolled
  member who fixes a typo in their own address is durably enrolled-and-unverified — a state
  `FortifyServiceProvider`'s own docblock already describes as expected. A second path reaches it too:
  Fortify's enrolment routes carry `auth` + `password.confirm` and **not** `verified`, so a never-verified
  account can confirm a TOTP. `TenantMembershipService::resolveOrCreateUser()` then **reuses that existing
  global identity** rather than creating a placeholder, so the invite row points at the real account.
  **Whoever holds the emailed token** — a shared alias, a forwarded message, mailbox read access — gets a
  full authenticated session as that member, across every workspace they belong to, with a password of the
  holder's choosing written over the member's own. **Live.**
  ⛔ **IT IS STRICTLY WEAKER THAN PASSWORD RESET, WHICH IS THE COMPARISON THAT SETTLES THE SEVERITY.**
  Reset is also mailbox-only, but it lands on the login form and an enrolled member is then sent to
  `/two-factor-challenge`. This path skips that entirely. It is therefore **not** an instance of ADR-0016
  §D32 (which decides that an *identity provider* is an authentication authority); nothing here has
  authenticated anybody.
  ⚠️⚠️ **M7 CHECKED THIS DOOR AND CLEARED IT AGAINST THE WRONG PREDICATE — the clearance is corrected in
  the row above rather than quietly deleted.** M7's `Auth::login(` shape-grep found the call site and
  reasoned *"a placeholder has no confirmed factor"*. True of placeholders, and the branch is not keyed on
  placeholders — it is keyed on `email_verified_at`. **A door survey is only as good as the predicate it
  checks each door against, and stating "checked, not a defect" in the permanent record is what made the
  wrong predicate durable.** Found by M7's own adversarial pass, and **reproduced before it was believed**:
  an unauthenticated `POST /invitations/{token}` for a seeded enrolled-and-unverified member answered a
  redirect to `/dashboard` with that member authenticated and no challenge anywhere.
  **The fix is a design decision, not a one-liner**, which is why it is filed rather than folded into a
  documentation increment: the unverified arm exists so a genuinely new invitee can set a password, and it
  has to keep doing that while refusing an account that already has credentials. The obvious shape is to
  fork on *"has this identity ever been used"* — a set password, a confirmed factor, or any prior
  membership — rather than on `email_verified_at`, and to send anyone who has to the sign-in-then-accept
  hand-off the `prepareAcceptingUser()` docblock already calls "Increment C". **Needs a test that a
  password is not overwritten and a second factor is not skipped**, and `docs/security-threat-model.md`
  gains a row: it has none for invitation takeover today. Filed by `M7`.
- ✅ **CLOSED BY `M18` (2026-08-26) — `major` · ~~Nothing verifies that a workspace controls the email domain its
  identity provider asserts.~~** `sso_verified_domains` (one row per workspace × email domain, a 256-bit token,
  a DNS TXT challenge at `_meridian-sso.<domain>`) reusing the `DnsTxtResolver` seam ADR-0012 built for custom
  hosts, and `SsoUserProvisioner::provision()` refusing with a new `SsoFailureReason::DomainNotVerified`.
  ⚠️ **ALL EIGHT OF THIS ROW'S CITATIONS HELD — the first row in nine to be right about its own evidence** —
  and it was still wrong in two directions, both of which are worth keeping.
  **(1) IT UNDERSTATED ITS SEVERITY.** *"NOT LIVE — both known exploits are closed"* was too generous: JIT is
  permitted to CREATE, and `SsoUserProvisioner::createUser()` stamps `email_verified_at` on the strength of the
  assertion. `users` is a **deployment-wide** table, so a paying SSO tenant could mint a global identity for any
  unregistered address carrying a **forged mailbox-control claim** — and
  `TenantMembershipService::identityIsEstablished()` reads that exact column, so the forged stamp fed **M8's own
  predicate** and denied the address's true owner the password-setting arm of their later, genuine invitation.
  **(2) IT MISSED A SECOND DEFECT ENTIRELY, WHICH THE FIX'S ORDERING CLOSES FOR FREE.** The failures panel
  renders `existing_account_not_member` as *"Address already has an account elsewhere"* and `jit_disabled` as
  *"Nobody here matches that address"*, so an SSO-entitled admin could assert **any** address and read back
  whether it has an account anywhere in the deployment. §D19's uniform 404 was intact; the panel was the
  surface that leaked.
  ⛔ **AND THE "grandfathering call" IT CALLS "the part that makes it a decision rather than a feature" IS
  DISSOLVED RATHER THAN ANSWERED.** The check sits AFTER the `Active` early return, so **an active membership
  IS the grandfather**: no live deployment loses a member on deploy, and no mode column, backfill or
  public-mailbox exclusion list exists to be left in the wrong state. That rests on the four writers of
  `TenantUserStatus::Active` being enumerated rather than assumed — none mints one for a stranger's address on
  an assertion alone. ADR-0016 §D34 carries the decision and six rejected alternatives; residual 32 is rewritten
  to the three things that genuinely remain, each filed below. Filed by `M9`.

- **`minor` · A verified SSO email domain is trusted indefinitely — there is no re-verification sweep.**
  Filed 2026-08-26 by M18, **the moment the decision not to build it was taken**, which is the rule the J4b1
  post-mortem produced. `sso_verified_domains.verification_checked_at` exists and is written by
  `SsoDomainService::verify()`, but nothing re-reads a verified domain on a cadence, so a workspace that later
  loses control of a domain keeps the authority it earned. `CustomDomainService::sweep()` is the template and
  `VerifyCustomDomainsJob` the shape. **Two reasons it was not taken:** `routes/console.php` records that
  nothing runs the scheduler on the production box, so the sweep would be a control that exists in the
  repository and not on the machine; and how long a proof of control should outlive the proving is a product
  call rather than a defect. ⚠️ **The lookup is the easy half — the DEMOTION RULE is the hard one.**
  `verified_at` is what stands between an assertion and an account, so an over-eager sweep converts somebody
  else's DNS outage into a sign-in outage for every new joiner at that workspace. `verify()` already refuses to
  demote on a `LookupFailed` (the null-versus-empty-array contract), and that is the floor rather than the whole
  answer: N consecutive definitive `NotFound`s is the shape to consider, and ADR-0012 explicitly defers the same
  question for custom hosts. Carried as `docs/security-threat-model.md` residual 32. Filed by `M18`. **Latent** — needs control of a verified domain to change hands; nothing re-reads a verified domain on a cadence and no scheduler runs on the box, judged by `M65`.

- **`minor` · The tenant-facing SSO domains card on `/settings/sso` does not exist, so verification is
  operator-assisted.** Filed 2026-08-26 by M18. ⚠️ **THIS IS A LANE A ROW AND THAT IS STRUCTURAL, NOT A
  PREFERENCE**: the card is `resources/js/**`, Lane A's outright since the 2026-08-25 widening, and Standing
  Rule 7(b-bis) says a paired change split across two lanes is the one thing that cannot work. The interim
  surface is `php artisan sso:domains <tenant> [--claim|--verify|--release]`, which an operator runs on a
  workspace's behalf — enough to make the control operable from the moment the refusal went live, which is why
  M18 shipped without it rather than shipping enforcement nobody could satisfy. **Everything the card needs
  already exists server-side**: `SsoDomainService` holds the whole lifecycle and every decision, and the
  command's verbs are the right ones. What the card adds is an authenticated **actor** — and that is exactly
  what `SsoDomainService`'s docblock records the missing audit rows as waiting for, so **claim + verify +
  release should each emit a `domain`-style audit row in the same increment**. `SsoConnectionPresenter::page()`
  gains a `domains` key; `SsoFailureRow` needs no change (its `reason` is a plain string with `reason_label`
  composed server-side). Note the refusal's own hint deliberately names the DNS TXT record rather than a screen,
  precisely so it was not a lie before this row lands — update it to name the card once it does. Filed by `M18`. **Live** — the tenant-facing card is still absent, so tenant-side verification stays unreachable without an operator, judged by `M65`.

- **`minor` · `MemberController::invite()` validates `['required', 'email', 'max:255']` and a role, with no
  domain-ownership check.** Filed 2026-08-26 by M18. The same root on the invitation door, and the first link in
  the chain M9's own write-up traces. ⚠️ **NOT the takeover M8 and M9 closed** — both of those are shut, and
  RBAC §7's *"an unaccepted invite grants nothing"* still holds — so what remains is narrower: an admin can
  address an invitation into a domain their workspace has never proven it controls, which sends a real email to
  a real stranger and binds a `tenant_users` row to their existing global identity. M18's own control is the
  obvious shape to reuse (`SsoDomainService::isVerifiedFor()` is already phrased over an address), but applying
  it here is a **product decision, not a cleanup**: today any workspace may invite anyone, including
  contractors and personal addresses, and gating that on DNS would change what invitation means for every
  workspace rather than only for SSO ones. Whoever takes it decides that first. Filed by `M18`. **Live** — reachable today: invite validates address shape only, so a workspace can send a branded invitation to an address it does not control and occupy that identity, judged by `M65`.

- **`minor` · Self-registration remains a way to occupy an address in a domain you do not control.** Filed
  2026-08-26 by M18, recorded because §D34's *"an active membership is the grandfather"* reasoning depends on
  knowing exactly which doors mint one, and this is the weakest of the four. `joinOpenTenant()` is reachable on
  any workspace whose `RegistrationGate` is open, so a registrant may take `victim@othercompany.test` and hold
  it. ⚠️ **Materially weaker than what M18 closed, and the difference is what makes it a `minor`**: the
  registrant sets their own password and **nothing forges `email_verified_at`**, so the account squats an
  address without minting a false claim about mailbox control — which is the property `identityIsEstablished()`
  reads. Older than SSO, and any fix touches the ordinary registration path for everybody. Filed by `M18`. **Live** — reachable today by anyone who can reach the registration form, judged by `M65`.
- ✅ **CLOSED BY `M9` (2026-08-24) — `major` · ~~SSO adopts an existing account whenever a PENDING INVITATION exists, so an SSO-entitled
  admin can be signed in as any stranger they invited — no emailed token required.** Found by M8's
  adversarial pass and **verified against the code by hand before filing**; it is the same conflation M8
  just closed on the invitation door, still live on the SSO one, and **stronger there** because it needs no
  access to anybody's mailbox. `SsoUserProvisioner`'s adoption guard is
  `if ($user !== null && $membership === null) { throw ...existingAccountNotMember(...) }` — so **any**
  membership row disarms it, including an `Invited` one the attacker just created. Its own comment states
  the premise: *"a membership row of ANY status means this workspace has already made a decision about that
  person"*. **That premise is exactly what M8 disproved.** An `Invited` row is a statement a workspace made
  about an ADDRESS; it says nothing about who is behind it — RBAC §7's *"an unaccepted invite grants
  nothing"* is the same point from the other side.
  **The chain, each link read rather than reasoned.** (1) `MemberController::invite()` validates
  `['required', 'email', 'max:255']` and a role — **no domain-ownership check anywhere**, so an admin may
  invite `victim@othercompany.com`. (2) `TenantMembershipService::resolveOrCreateUser()` resolves that
  address on `pgsql_auth`, which sees every account in the deployment, so the invite row binds to the
  victim's **existing global identity** rather than a placeholder. (3) The attacker signs in through the
  IdP **their own workspace configured**, asserting the victim's address. (4) The guard above does not
  fire, because step 1 created the membership row. (5) The JIT toggle is skipped too — *"an invited person
  was authorized by an admin by name"* explicitly exempts `Invited`. (6) `joinViaSso()` activates the
  membership and the completion controller calls `Auth::login($user)`. (7) **ADR-0016 §D32 is decision of
  record that a SAML sign-in does not challenge a member's PERSONAL second factor**, so an enrolled victim
  is not protected either. The attacker holds a session as the victim's global identity. **Live.**
  ⛔ **PRE-EXISTING (P1b, narrowed by M1) AND DELIBERATELY NOT TAKEN BY M8** — `app/Services/Sso/` is Lane
  B's column but was not in M8's claim, and the remedy is a decision rather than a patch, which is why it is
  filed rather than folded in. **The obvious shape** is to apply M8's own predicate here: when
  `resolveUserByEmail()` returns an EXISTING user whose membership is `Invited`/`Declined`/`Removed` and
  `TenantMembershipService::identityIsEstablished()` is true, refuse — an established identity must complete
  an invitation in its own browser. ⚠️ **Verify the alternative before building it:** narrowing the
  exemption to *"a placeholder this workspace actually created"* needs a fact the schema does not record.
  Needs a `docs/security-threat-model.md` §8 row either way — it has none for this today — and, if the
  behaviour is judged intentional, an ADR-0016 sub-decision saying so. Filed by `M8`.
- ✅ **CLOSED BY `M9` (2026-08-24) — `minor` · ~~`decline()` asks no identity question at all, so a token holder can destroy an established
  member's pending invitation.** The one invitation door M8 deliberately did not touch.
  `InvitationController::decline()` resolves the invite by token hash and calls
  `TenantMembershipService::decline()` — no `Auth` check, no predicate. Whoever reads the mailbox (or a
  forwarded link) can set the membership to `Declined`, and the invited person then sees nothing at all.
  **Denial rather than takeover**, and re-sending fixes it, which is why M8 left it: the row M8 closed was
  about a credential being overwritten and a session being minted, and neither happens here. Filed because
  it is the same door and a later reader will ask. **Fix if taken:** ask the same predicate, and require an
  established identity to be signed in to decline — the accept arm's hand-off already exists to route them. Filed by `M8`.
- ✅ **CLOSED BY `M9` (2026-08-24) — `nit` · ~~`invitations/Show.vue` offers an Accept button to an authenticated visitor who is not the
  invitee, and the POST always 403s.** `show()` publishes `needsRegistration`, which after M8 answers *"has
  this identity ever been used"* — it does not answer *"are YOU the invitee"*, which is what `accept()`
  additionally enforces. So a signed-in wrong user gets a button that cannot work. Harmless and pre-dating
  M8 (the old code 403'd the same visitor), but the page could publish a second prop and say *"sign in as
  ‹address› to accept"*. `resources/js/Pages/invitations/Show.vue` is **Lane A's column**, which is the
  other reason it is filed rather than fixed. ⚠️ **And the prop name is now misleading in its own right:**
  `needsRegistration` reads as *"this email is unverified"* and now means *"this identity has never been
  used"*. Renaming it touches the Vue file, so it belongs with this row.
  ✅ **AS BUILT (M9).** `show()` publishes `signedInAs` (the viewer's own address, null for a guest), and the
  page renders a banner naming the account to use plus a **Sign out** button — `POST /logout` was verified
  reachable from this host before it was offered — instead of an Accept button whose POST always 403s. The
  prop is renamed `needsRegistration` → `isUnusedPlaceholder` at all five sites. ⚠️ **AND SCOPING THIS ROW
  FOUND A LIVE DEFECT NOBODY HAD FILED:** the page used `<MdsBanner>` **without importing it**, and
  `resources/js/app.ts` registers no components globally, so the expired/already-used-invitation error banner
  rendered nothing at all between J3b and M9. Measured rather than asserted — with the import deleted again,
  `vue-tsc --noEmit` **and** `vite build` both still exit 0. That gate gap is filed as its own row under
  *Test suite & CI gates*.~~** Filed by `M8`.

- ✅ **CLOSED BY `M76` (2026-09-06) — `minor` · ~~A self-registered account that was never verified is indistinguishable from an invite placeholder, so a token holder can still overwrite its password.~~**
  `users.password_set_at` ships, stamped by all four password writers, and `identityIsEstablished()` gains
  it as a **first, positive arm**. Evidence held at every citation and the exploit was reachable: the
  invitation routes carry neither `auth` nor `verified`, so the predicate is the entire gate.
  ⛔ **THE ROW'S PRESCRIBED WRITE DOES NOT WORK AND WOULD HAVE SHIPPED A COLUMN NOBODY WROTE.** `User`
  declares `#[Fillable([...])]` as a PHP **attribute**, so the prescribed
  `User::create([... 'password_set_at' => now()])` is discarded **in silence**, with no exception and no
  log. The column is deliberately kept OUT of `Fillable` — it is a security signal — and every writer
  stamps it through `forceFill()`. A regression test drives registration end to end and asserts the column
  on a re-read, because the in-memory model carries the attribute either way.
  ✅ **AND `M70`'s "the only honest backfill re-derives the predicate in SQL" IS TOO PESSIMISTIC, WHICH IS
  WHAT MADE THIS SHIPPABLE.** The new arm is **monotonic** — a disjunct can only move an account from *not
  established* to *established*, never the reverse — so **no backfill is needed and no lockout can be
  manufactured**, which was the row's stated blocker. ⚠️ **The residual is narrowed rather than erased**:
  an account that self-registered before the migration and has still never verified, enrolled, linked or
  joined stays in the old state until it next sets a password. Said plainly here so the closed row is not
  read as *"nobody is exposed"*. ✅ `M70`'s other correction is confirmed **mechanically**: the
  `E2eSeeder`/`auth-axe` cost is **zero**, not "probably zero" — the fixture is built by a private helper
  that routes through none of the four writers.
  ⛔ **PREMISE ALSO FALSE: the vulnerable population is larger than "central-host registration".**
  `attachMember()` returns NULL on a full seat quota, landing a **subdomain** registrant in the identical
  state. A second entrance the row never named. Residual 30 in `docs/security-threat-model.md` is closed
  with it. Three mutations caught. Closed by `M76`.

  **The row as it stood, kept because its reasoning is the audit trail.** The residual M8 deliberately left,
  filed here the moment it was decided rather than left in a plan where no backlog search would reach it.
  `CreateNewUser` does not stamp `email_verified_at`, central-host registration creates no membership, and
  `google_id` / `two_factor_confirmed_at` are NULL — so every arm of `identityIsEstablished()` reads false
  and such a person is still handed the password-setting arm. **Strictly narrower than what M8 closed**,
  which also covered every verified-then-email-changed member and every 2FA-enrolled one. **The fix is one
  column**, `users.password_set_at`, stamped wherever a person actually sets their own password
  (`CreateNewUser`, `ResetUserPassword`, `UpdateUserPassword`, `InvitationController`) and NULL for a
  placeholder — which would also retire ADR-0016 §D22's recorded indistinguishability for the whole
  repository. **Priced and not taken in M8**: it edits `app/Actions/Fortify/` (in neither lane's column),
  needs a backfill, and moves `E2eSeeder`'s invitation fixture, which is what `auth-axe.spec.ts` scans on a
  suite that cannot run on this host. Recorded as residual 30 in `docs/security-threat-model.md`. Filed by `M8`. **Live** — the two account states remain indistinguishable in the tree today, judged by `M65`.
  ⚠️ **PREMISE CORRECTED BY `M70` (2026-09-04) — ONE STATED COST IS STALE AND PROBABLY ZERO, AND A REAL
  ONE IS UNNAMED. The evidence holds at every citation** (all five arms of `identityIsEstablished()` read
  false for such an account; none of the four password-setting actions stamps anything; residual 30 is
  quoted correctly; `users` carries no column that could stand in — `tos_accepted_at` is the near-miss
  the service's own docblock already disqualifies). ✅ **STALE: *"moves `E2eSeeder`'s invitation fixture,
  which is what `auth-axe.spec.ts` scans"*.** `PROGRESS_ARCHIVE.md` records `M8` releasing
  `database/seeders/E2eSeeder.php`, `tests/e2e/auth-axe.spec.ts` and `resources/js/Pages/invitations/Show.vue`
  **unedited**, and under this design the fixture still need not move: a NULL-defaulted `password_set_at`
  leaves the seeded placeholder reading as a placeholder, so the invitation page renders the same form and
  the scan's DOM is unchanged. That cost was inherited verbatim from a superseded draft and should be
  re-priced to zero before it is used to size an increment. ⛔ **UNNAMED AND REAL: the backfill is not
  mechanical, and both naive directions cause harm.** Stamp everyone and every live invite placeholder
  becomes *established* and can never accept — a lockout manufactured by the fix. Stamp nobody and every
  real account reads as a placeholder, which is strictly worse than the defect. The only honest backfill
  re-derives `identityIsEstablished()` in SQL, i.e. a fourth copy of the predicate.
  ⚠️ **Two adjacent writers need an explicit decision the row does not make**: `SsoUserProvisioner` and
  `GoogleSignInProvisioner` mint accounts with no password the person chose — leaving them NULL is right,
  but only if stated, since both already stamp `email_verified_at` and would be established anyway.

- ✅ **CLOSED BY `M76` (2026-09-06) — `major` · ~~The invitation accept arm writes ZERO rows and throws nothing, so an invitee's chosen password is silently discarded.~~**
  ⛔ **FILED AND CLOSED IN THE SAME INCREMENT, AND FILED ANYWAY BECAUSE THE RECORD MATTERS MORE THAN THE
  TIDINESS.** Found by the row above's first test run. A genuine newcomer accepting an invitation was
  redirected to `/dashboard`, their membership became `Active`, their role was granted and they were
  logged in — while `registerInvitedPlaceholder()`'s `forceFill(...)->save()` **updated no row at all**.
  Measured through the real HTTP stack: after a successful accept `email_verified_at` was **NULL** on both
  the app and the privileged connection, and that column is set unconditionally. The chosen **password**,
  the **name** and both **consent timestamps** went with it.
  ⛔ **THE OBSTACLE IS THE SELECT POLICY, NOT THE UPDATE POLICY — SO READING THE UPDATE POLICY EXONERATES
  IT.** `users_app_update` is `USING true`. `users_users_visibility` admits a row only for
  `app.current_user_id` or an **active** membership in the current tenant, and PostgreSQL applies SELECT
  policies to an `UPDATE` whose `WHERE` reads a column. At that point the visitor is not yet authenticated
  and the membership is still `Invited`. Measured `rows=0` on the default connection, `rows=1` on
  `pgsql_auth`, which is where the write now goes — the same connection the row was read on.
  ⚠️ **NOT fixed by moving the call below `memberships->accept()`**, which would also make the row visible
  and would leave a rejected password having already activated the membership.
  ⛔ **WHY NOTHING CAUGHT IT, WHICH IS THE TRANSFERABLE HALF.** `MembershipRoutesTest`'s end-to-end case
  asserts the membership status, `joined_at`, the granted role and that the session is authenticated —
  **all four were true while the write was inert** — and never asserts the credential. Confirmed by
  mutation: reverting the fix leaves that suite entirely green and reddens only the new case.
  ⚠️ **The consequence was a lockout with no error to trace**, which is verbatim the failure
  `SsoUserProvisioner::createUser()` defends against one file away and which nobody carried across.
  ⚠️ **It bears on `D5`/`D12`**: the series-exit clause counts open `major` rows, and this one was open and
  invisible for the entire time that count read zero. Closed by `M76`. Filed by `M76`.
- **`minor` · M8's GRANT removed an accidental backstop that a mutation argument was leaning on.**
  `meridian_auth` used to hold `SELECT, UPDATE` on `users` **and nothing else**, and both
  `MemberSearchArm`'s docblock and RBAC §9 cited that as the reason swapping the arm to `pgsql_auth`
  *"fails LOUDLY (11 cases red) instead of silently returning every tenant's members"*. Since
  `2026_08_17_000107` that swap would **succeed quietly**. Both prose sites are corrected rather than
  deleted, and nothing is broken today — `SearchMemberConnectionTest`'s three STRUCTURAL pins never relied
  on the database refusing anything. Filed so that **any future proposal to weaken one of those pins is
  read against this**, not against the older belief that a wrong connection cannot execute the query.
  Recorded as residual 31 in `docs/security-threat-model.md`. Filed by `M8`. **Not live** — a record of a decision, and its own text says nothing is broken today, judged by `M65`.
- **`minor` · `users.last_active_tenant_id` has no writer anywhere in `app/`.** Found while surveying
  candidate signals for M8's identity predicate: the column reads exactly like *"this identity has been
  used"* and would have been a fifth arm, but its only three references in the whole application are
  description strings in `TenantExtractColumns`. Nothing sets it, so nothing can read it meaningfully. The
  migration calls it *"UX convenience only (default tenant on next login); NOT authoritative for any
  authorization decision"* — which is a description of a feature that was never wired. **Either wire it
  (one write at session start, and the default-workspace convenience it promises becomes real) or drop the
  column**; leaving it is how a future increment reaches for it as a signal and gets NULL for everybody. Filed by `M8`. **Latent** — needs a future increment to reach for the column as a signal; today nothing writes it and nothing reads it meaningfully, judged by `M65`.
- ✅ **CLOSED BY `M66` (2026-09-03) — `minor` · ~~`EnforceTenantTwoFactor` is absent from the `/api/v1`
  token-mint group.~~** Mounted on Group A after `verified`, so an unenrolled member under enforcement can no
  longer mint a bearer from a session that is bounced off every page. Group B stays ungated for the reason
  the row itself gives, now pinned with a floor rather than left in prose.
  ⛔ **THE ROW'S REMEDY WAS WRONG AND MOUNTING IT AS WRITTEN WOULD HAVE SHIPPED A DEFECT.** *"The code edit
  and the test edit are the same edit"* — but the middleware ended in a bare
  `redirect()->route('two-factor.required')` with no `expectsJson()` branch, so it would have answered an
  `Accept: application/json` group with a 302 into HTML, precisely what that group's own comment exists to
  prevent. The JSON arm came first, mirroring `EnsureVerifiedEmail`, whose docblock already named this class
  as the one that *"tolerates exactly that"*.
  ⚠️ **AND IT CHANGED THE TENANT SIDECARS, WHICH IS RECORDED RATHER THAN LEFT TO BE FOUND.**
  `GET /notifications` now answers 403 instead of 302; the same client swallows both, and `openapi.json`
  moved by zero as `EnsureVerifiedEmail:45-50` predicts. No exemption was added, deliberately.
  ⚠️ **Two of the row's own claims were false**: there is no alias (so the prescribed
  `StepUpReauthenticationTest` manifest shape does not transfer — the FQCN shape does), and the phrase it
  quotes as repository text, *"gate the mint, not the bearer"*, appears nowhere in the tree.
  Proven by `MU1` (mount commented out → both arms red) and `MU2` (JSON arm removed → the behavioural case
  red, the structural one still green), which is the `M43` pairing demonstrated rather than asserted.
  ➕ **AND THE CENSUS IS LARGER THAN THE ROW'S ONE ROUTE — filed separately, immediately below.**
  The row's original text follows, including the verification that downgraded it.
  ⛔ **DOWNGRADED FROM `blocker` TO `minor` ON VERIFICATION,
  AND THE REASON IS THE ROW**: all six links hold, but the middleware is an **enrolment nudge by its own
  docblock** — *"re-challenging per request would be theatre on the doors that already challenge"*,
  and its one escape hatch is a route deliberately left outside its own group — Fortify's own 2FA-enrolment routes
  sit outside the same gate behind `password.confirm` — so the attacker already had a better path — and
  the token's abilities are capped at the issuer's own RBAC. It is a defence-in-depth and consistency gap.
  The code edit and the test edit are the same edit: mount it on Group A, and add a
  `StepUpReauthenticationTest:115`-shaped route manifest so it cannot silently come off again. Group B
  needs no gate — `routes/api.php:80-88`'s "gate the mint, not the bearer" argument applies verbatim. Filed by `M1`.
- ✅ **CLOSED BY `M68` (2026-09-03) — `minor` · ~~The Fortify group serves tenant subdomains and carries no org-2FA gate, so the mint was not the only way past it.~~**
  `EnforceTenantTwoFactorOnFortify` is mounted on `config/fortify.php` and covers
  `user-profile-information.update` and `user-password.update` — the two routes the row names — by ROUTE
  NAME, falling through for the other twenty-four.
  ⛔⛔ **THE REMEDY THE ROW IMPLIES IS IMPOSSIBLE, AND THAT IS MEASURED RATHER THAN ARGUED. IT WAS BUILT
  FIRST AND THREE BEHAVIOURAL CASES FAILED WITH THE WRITE SUCCEEDING.** Mounting `EnforceTenantTwoFactor`
  here — per route or group-wide — produces a gate that **cannot ever fire**. This group carries no tenancy
  middleware at all, so `EstablishTenantDatabaseContext` resolves a **null** tenant and
  `TenantSettingRegistry::all()` returns `[]` with no ambient tenant: `security.require_two_factor` reads as
  its sparse default, `false`, for every workspace in the deployment. **Mounted, green, permanently blind.**
  ⚠️ **AND FAILING THAT WAY IS NOT AN ACCIDENT OF THIS KEY.** Under `settings`'s nullable_global SELECT
  policy a tenant's own rows are INVISIBLE rather than absent without its context, so the fallback is silent
  by construction. **`RegistrationGate` hit the identical wall on `/register`** and
  `TenantSettingRegistry::forTenant()` exists because of it — so the answer was already in the tree, one
  entry away in the same config array: resolve the workspace from the HOST (`PlatformHost::tenantFor()`) and
  read through `getFor()`. The policy is extracted to `TwoFactorEnforcementGate` so this surface and the
  tenant group cannot answer differently; `EnforceTenantTwoFactor` keeps its docblock and its mount and
  loses only the decision.
  ⛔ **THE ROW NAMES ONE CARVE-OUT AND THERE ARE THREE.** The 2FA enrolment routes (the row's);
  **`POST /logout`**, which `EnforceTenantTwoFactor`'s own docblock names in terms — *"do not 'tidy' it
  inside"* — because "enrol or leave" needs two doors; and **`password.confirm.store`**, because
  `twoFactorAuthentication(['confirmPassword' => true])` routes enrolment through the step-up, measured on
  the live route table where `two-factor.enable` carries `RequirePassword`. A group-level mount passes both
  refusal cases and breaks all three.
  ✅ **ORDERING MEASURED, NOT REASONED (`M43`).** Printed from `Router::gatherRouteMiddleware()` with the
  entry present and then removed: unlisted in `priority()` puts it at position 14, after `Authenticate:web`
  (5) and `EstablishTenantDatabaseContext` (7). **No `priority()` entry is needed** — the prediction that one
  would be was wrong in the other direction.
  ✅ **Four controls, and the arms separate.** Unmounting reddens the three refusals plus the coverage file's
  mount control and leaves the list arms green; swapping `blocksForHost()` for `blocksAmbient()` — the blind
  gate above — reddens the three refusals alone; moving `logout` into the gated list reddens its carve-out
  case and the both-lists arm; removing a live write route from the decision list reddens the accounting arm
  alone.
  ⚠️ **IT DOES NOT CLOSE THE MAIL-CANNON ROW ON THE SAME ROUTE**, which the row itself warns about. That is a
  rate limit and its remedy is a `RateLimiter::for()` plus a `ThrottleFortifyEndpoints::limiters()` entry; it
  stays open.
  **`minor` · The Fortify group serves tenant subdomains and carries no org-2FA gate, so the mint was not the
  only way past it.** Found while closing the row directly above, which named one route. `config/fortify.php`
  registers Fortify's routes in their own group — `web`, `RequirePlatformHost`, `AppSecurityHeaders`,
  `GateRegistration`, `ThrottleFortifyEndpoints`, `EstablishTenantDatabaseContext` — with no
  `EnforceTenantTwoFactor`, and `RequirePlatformHost` admits subdomains of the central domain. So an
  unenrolled member under `security.require_two_factor`, bounced from every page of the workspace, can still
  `PUT /user/profile-information` and `PUT /user/password`. ⚠️ **Two of those routes are deliberately outside
  the gate and must stay outside** — the 2FA enrolment endpoints themselves, which is the structural escape
  hatch `EnforceTenantTwoFactor`'s docblock calls the whole design — so this is a per-route judgement and not
  a group-level mount. It is the same defence-in-depth severity the row above was downgraded to, for the same
  reasons: the writes are scoped to the actor's own account and the password route re-challenges. ⚠️ **A
  neighbour row already covers `PUT /user/profile-information` from the mail-cannon angle; read both before
  taking either.** **Live.** Filed by `M66`.
- **`minor` · Any tenant-scoped policy read mounted on the Fortify group is silently blind, and two increments have now walked into it.**
  Filed 2026-09-03 by `M68` at the moment it walked into it, having cost a full build-and-fail cycle.
  `config/fortify.php`'s group carries no tenancy middleware at all, so `EstablishTenantDatabaseContext`
  resolves a **null** tenant there and `TenantSettingRegistry::all()` returns `[]`. Every key then reads as
  its sparse default — for `security.require_two_factor` that is `false`, so the gate `M68` mounted
  answered "not required" for every workspace in the deployment while being correctly mounted and fully
  green. ⛔ **The failure is silent BY CONSTRUCTION, not by oversight**: under `settings`'s nullable_global
  SELECT policy a tenant's own rows are INVISIBLE rather than absent without its context, so there is no
  error to see and no row count to check. **`RegistrationGate` hit the identical wall on `/register` in I5**
  — its docblock and `TenantSettingRegistry::forTenant()` both record it — and `M68` hit it again anyway,
  which is what makes this a class rather than an incident. ⚠️ **What exists now is two correct examples and
  no mechanism**: `RegistrationGate` and `TwoFactorEnforcementGate` both resolve the workspace from the HOST
  and read through `getFor()`, and each says so in prose that the next author has to happen to read.
  **The candidate remedies, neither obviously right:** have `TenantSettingRegistry::get()`/`all()` refuse
  rather than return `[]` when no tenant is bound — which is fail-loud but would break every legitimate
  central-host caller — or add a `#[RequiresTenantContext]`-style assertion the Fortify-group middlewares
  can carry. **Not live** — no defect is open in the tree today; both known readers are correct. Filed as a
  trap because it has now produced a wrong implementation twice and the second one was green.
  Filed by `M68`.

- ✅ **CLOSED BY `M66` (2026-09-03) — `minor` · ~~Three admin POSTs bind `{tenant}` with no `whereUuid`.~~**
  `suspend`, `reactivate` and `assign-plan` now carry `->whereUuid('tenant')` like the `show` and
  `impersonate` routes around them, so a malformed id 404s instead of raising SQLSTATE 22P02. The row's
  evidence held and its prescribed remedy **worked** — the only one of `M66`'s four that did. Filed by `M1`.
  ⛔ **THE ROW MISCHARACTERISED THE COMMENT IT CITED, AND THE CORRECTION IS THE LESSON.** That comment does
  not *justify* the omission — it **names** it, in a full sentence, as a latent defect the three share. What
  was stale was only its stated reason, *"and have simply never been reachable from a UI"*: `TenantDetail.vue`
  posts to all three and `Tenants.vue` posts two of them from the list page. **A defect deferred on a premise
  nobody re-reads is a defect that ships**; the premise expired silently while the comment went on reassuring
  every reader. Rewritten rather than deleted, because the diagnosis was right and only its expiry was wrong.
  ✅ **The durable half is a sweep, not three more names.** `TenantDetailConsoleTest` has had a malformed-id
  case since I7b — for `admin.tenants.show`, the one route that already carried the constraint. A per-route
  test cannot fail for a route nobody wrote it for. `AdminConsoleGateTest` now discovers every parameterised
  console route from the live table and asserts each constrains its parameter, with its own floor.
  Proven by `MU5` (one constraint dropped → the structural **and** behavioural arms red) and `MU6`, the
  discriminator: dropping the constraint on `admin.feedback.update` — a route this row never touched —
  reddens the sweep too, which is what distinguishes a sweep from three hard-coded names.

### Design system

- ✅ **CLOSED BY `M16` (2026-08-26) — `major` · ~~The builder's share-panel live link fails WCAG AA contrast
  at 4.45, and the e2e gate reports it as FLAKY rather than red.~~**
  ⛔ **THE ROW'S FIRST CLAIM IS FALSIFIED: THERE IS NO CONTRAST DEFECT, AND `#1674e9` IS NOT A TOKEN IN THIS
  REPOSITORY.** The button is `--mds-color-action-primary-bg` → `--mds-primary-600` → **`#0E6FE8`**
  (`theme-overrides.css:125`), which is **4.71:1 against white and passes**. `#1674e9` is what `#0E6FE8`
  *becomes* when composited at ~96.5% opacity over the white page: `0.965·0E + 0.035·FF = 16`,
  `0.965·6F + 0.035·FF = 74`, `0.965·E8 + 0.035·FF = E9` — exact on all three channels. That opacity is
  `.mds-modal-enter-active` (`Modal.vue:481-483`) still running its `--mds-duration-slow` **400ms** fade
  when axe samples. **A scan-timing defect, not a colour one** — and retuning the token, which is what the
  row as written invites, would have darkened a passing colour to hide a broken gate.
  ⚠️ **MEASURED IN THE RUNNING APP RATHER THAN INFERRED, AND IT IS WORSE THAN TWO CI RUNS SUGGESTED.** A
  probe that mirrors the spec step for step and reads the page at the exact instant `assertClean` hands it
  to axe found the backdrop at **opacity 0.5138** with **three animations still running**, compositing the
  button to `#83b5f3` — **2.13:1**, less than half the AA floor. CI caught it at 96.5% (4.45) purely by
  where in the fade its scan happened to land. ⛔ **SO THE DEFECT IS A CONTINUUM, NOT AN INTERMITTENCY:**
  the measured ratio is a function of elapsed fade time, anywhere from 2.13 to 4.71, and the case *passes*
  only when the scan lands after the full 400ms. That is why it read as flaky, and it is why the retry
  "worked".
  ⛔ **AS FIXED.** `playwright.config.ts` sets **`contextOptions: { reducedMotion: 'reduce' }`** — at
  context creation, so `theme-overrides.css:410-417` collapses every `--mds-duration-*` to 1ms from the
  first frame. `forceTheme` has emulated reduced motion since J1e and its docblock names this exact hazard,
  but it runs *after* the test opens what it came to scan, and **a CSS transition keeps the
  `transition-duration` it started with** — so emulating mid-flight cannot collapse one already running.
  `support/axe.ts` gains `settleAnimations()`, which awaits every **finite** in-flight animation (infinite
  ones — spinners, skeletons — are filtered, and the whole wait is capped, so it can never hang) and
  replaces the bare `settlePaint` in both `assertClean` and `builder-axe.spec.ts`'s twin.
  ✅ **PROOF, all three legs measured.** Same probe, `reducedMotion` on: effective opacity **1**, empty
  opacity chain, button reads **`#0e6fe8`** and **4.71:1** — the exact value `theme-overrides.css:125`
  documents. The real spec then ran green locally on **"share panel, live link (light)", the precise case
  that failed CI twice**, in 28.0s — well inside the 60s timeout, so `settleAnimations` demonstrably does
  not stall. ⚠️ **The one animation still running under the fix has an infinite iteration count**, which is
  exactly the hang `settleAnimations`' filter exists to avoid; the filter was designed before the probe
  found it and the probe then justified it.
  ⛔ **THE ROW'S SECOND CLAIM STANDS AND IS ANSWERED SEPARATELY.** "A gate that retries turns a
  deterministic failure into an intermittent one" is correct, and the decision it asked for is **D2 in
  `docs/claims/decisions.md`**: `failOnFlakyTests: !!process.env.CI` alongside the existing `retries: 1`,
  so a result that needed the retry is red while the retry still produces its trace. ⚠️ Note
  `PROGRESS_ARCHIVE.md` records a **second** flake in this same file, under `## Archived status bullets` — *"Builder — empty canvas (dark)"* — of
  the same shape; if it recurs it is now a hard failure rather than a line to skim past.
  ⚠️ **Line numbers moved:** the live-link case was `builder-axe.spec.ts:198` and is `:205` after this
  change. **Cite the test's NAME.** Historical records below preserve `:198` as the incident recorded it,
  the same convention this repo already applies to the incident hexes in `support/axe.ts`.

  *The original row, preserved:* Observed by **M5's final CI run (2026-08-19, run 32250476088)**: the
  `builder-axe.spec.ts` case *"Builder — share panel live link"* (light, mobile) failed axe
  `color-contrast` — *"insufficient color contrast of 4.45 (foreground #ffffff, background #1674e9, font
  size 10.5pt (14px))"* — then passed on Playwright's retry, so the summary read **550 passed + 1 flaky +
  10 skipped** instead of 551 + 10, and the run went green.
  ⚠️ **TWO DEFECTS, AND THE SECOND IS THE WORSE ONE.** (1) 4.45 is below the 4.5 AA floor for normal text,
  so white-on-`#1674e9` at 14px is a real violation wherever it renders. (2) **A gate that retries turns a
  deterministic failure into an intermittent one** — the passed count drops by one while the TOTAL is
  unchanged, which reads exactly like a test having been silently dropped, and a "flaky" line is easy to
  dismiss as noise. Whoever fixes (1) should also decide whether an axe violation may be retryable at all.
  **Not M5's**: that increment changed zero `.vue` / `.ts` / `packages/design-system/` files (checked with
  `git diff --name-only origin/main...HEAD`), so it is pre-existing and sits in **Lane A's column**. Filed
  here rather than left in a CI log, where no later search would find it.
  ✅ **SECOND CONFIRMED SIGHTING — M9's merge run `32711202891` (2026-08-24), the identical case, rule and
  element** (`builder-axe.spec.ts:198` › *Builder — share panel, live link (light)*, `color-contrast` on
  `footer > .mds-button--primary`), failing on the first attempt and passing on retry #1 for **550 passed +
  1 flaky + 10 skipped**. ⚠️ **THAT SETTLES THE ROW'S SECOND CLAIM: THIS IS NOT INTERMITTENT AT ALL.** Two
  runs five days apart, on two unrelated diffs — neither touched a `.vue` or a design-system file — failed
  in exactly the same place. The retry is not smoothing over flakiness; it is **converting a deterministic
  AA violation into a line that reads as noise**, which is the more expensive half of the defect. M9 did not
  take it: it is Lane A's column and the retryability question is a gate-policy decision, not a colour fix. Filed by `M5`.

- ✅ **CLOSED BY `M20` (2026-08-26) — `major` · ~~The combobox highlight leaves the visible box after
  roughly the sixth option and cannot be brought back.~~**
  ⛔ **ALL THREE CITATIONS HELD AND SO DID THE ROW — the first merge-gate row in some time to be right
  about its own evidence end to end.** `Combobox.vue:353-358` is `max-height: 22rem` + `overflow-y: auto`;
  `:267-271` carries the comment saying rows have no `tabindex` by design; `:176-192` `preventDefault`s
  both arrows. A `grep` for `scrollIntoView` across `packages/design-system/src` returned **0**.
  ✅ **AND THE NUMBERS ARE BETTER THAN THE ROW GUESSED — MEASURED IN A REAL BROWSER, NOT ESTIMATED.** The
  row said "22rem shows five or six". At the palette's true worst case (21 rows — `PER_ENTITY_PREVIEW` 5
  × four arms + "See all") the list is **1195px inside a 352px band**; pinned to the top, **exactly five
  of twenty-one rows are visible** and the last option sits **843px** below the box.
  ⛔ **AS FIXED.** `MdsCombobox` reveals the active option itself, on a **`flush: 'post'`** watcher over
  `[activeIndex, options]` — pre-flush measures the row highlighted a moment ago, consistently, which
  reads as "the fix does not work" rather than as a timing bug. The option is looked up **by the id
  `aria-activedescendant` names**, so what is scrolled is provably what is announced. ⚠️ **Not
  `scrollIntoView`:** `block: 'nearest'` is specified to walk *every* scrollable ancestor — the dialog
  and the page included — which is the exact defect class M17 and M19 spent two increments removing.
  The arithmetic is a new sibling module, `Combobox/scroll-reveal.ts`, which returns `null` for "already
  visible" so a reader scrolling by wheel or touch is never fought, and which is a pure function because
  **happy-dom computes no layout** and a mounted assertion would have passed whatever the code did.
  ✅ **PROVEN WITH A POSITIVE CONTROL, WHICH IS THE M19 LESSON APPLIED.** The probe first establishes that
  the fixture *reaches* the defect (1195 > 352; last row 843px out of view), then arrows through all 21
  options asserting the announced row is fully inside the band after every press: **0 out of view**, list
  scrolled to its maximum 843. A probe that measures zero has told you nothing until you know it
  exercised the thing that broke.
  ⚠️ **THE REASON THIS SHIPPED IS A FIXTURE, NOT A SCANNER, AND THAT IS THE TRANSFERABLE PART.** The row
  is right that "the stories seed four options" — so the list never reached `max-height`, axe's
  `scrollable-region-focusable` had no scrollable region to look at, and the unit suite computes no
  layout. Storybook now carries a 21-option `Scrolling` story (light + dark), and `MdsDataTable` gains a
  wrapping sortbar story below: **42 suites / 303 checks** in CI, up from 299 — four new stories, still
  green, and `scrollable-region-focusable` does **not** fire on the managed listbox.
  ⚠️ **AND 303 IS A CORRECTION OF THIS INCREMENT’S OWN NUMBER.** The local scan was run after the two
  combobox stories and *before* the two DataTable ones, and reported 301; it was never re-run, so the
  figure quoted while the work was in flight was stale by exactly the stories added after it. **A gate
  measured mid-increment is a measurement of the tree at that moment, not of the increment.**
  Recorded in DSR §3.4.1 as an as-built amendment.

  *The original row, preserved:*

  **`major` · The combobox highlight leaves the visible box after roughly the sixth option and cannot be
  brought back.** `packages/design-system/src/components/Combobox/Combobox.vue:353-358` —
  `max-height: 22rem` + `overflow-y: auto`, with the highlight moved by `aria-activedescendant` only:
  nothing calls `scrollIntoView`, the rows deliberately carry no `tabindex` (`:267-271`), and arrow keys are
  `preventDefault`ed (`:176-192`) so they cannot scroll the region either. The command palette — the
  component's primary consumer — renders up to 21 two-line options (`SearchService::PER_ENTITY_PREVIEW = 5`
  × four arms + "See all"), and 22rem shows five or six. A sighted keyboard user then presses Enter blind.
  **Live**, WCAG 2.4.7. No gate sees it: the stories seed four options, so axe's
  `scrollable-region-focusable` never fires and happy-dom computes no layout. Filed by `M1`.

- ✅ **CLOSED BY `M20` (2026-08-26) — `major` · ~~The stacked sort chip ships a 32px touch target in the
  one layout that exists only on the touch band.~~**
  ⚠️ **THE ROW IS RIGHT ON THE MERITS AND WRONG ON ONE CITATION.** `DataTable.vue:488-495` is the 32px
  chip, `:472-473` its `display: none` default and `:702` where the container block reveals it — all
  three hold. **The container query is at `:598`, not `:657-659`**; `:657` is the empty-row
  `grid-column` rule *inside* it. Four rows running have now had their own evidence wrong somewhere.
  ⛔ **AS FIXED, AND THE GAP MATTERS AS MUCH AS THE TARGET.** `.mds-table__sortchip` gains
  `position: relative` and a `::before` overlay at `min-width/min-height: 44px` — the identical
  construction `Button.vue:104-114`, `Alert.vue:180-190`, `Checklist.vue` and `Toast.vue` already use,
  and §4.4's own prescribed remedy (expand the hit area, do not inflate the glyph). ⚠️ **The overlay
  alone would have left the row HALF fixed.** §4.4's last bullet asks for 8px between adjacent **hit
  areas**, not visual boxes: a 44px target on a 32px chip overhangs 6px above and below, so two *wrapped*
  rows of chips at the uniform 8px `gap` would have overlapped by **4px of invisible target**. The
  sortbar now splits the gap — `row-gap: var(--mds-space-5)` (20px = 8 required + 2 × 6 overhang) and
  `column-gap: var(--mds-space-2)` — and a source-text gate fails on anyone tidying the two back into one.
  ⛔ **THE HORIZONTAL OVERHANG IS DESIGNED OUT RATHER THAN MEASURED AWAY.** `MdsChecklist`'s own docblock
  records what the alternative costs — a 24px control whose 44px target overhangs 10px each side and
  reports a 10px `scrollWidth` excess that only its *container's* padding absorbs — and
  `.mds-table__frame` has **no padding**. The chips therefore carry `min-width: calc(44px + 2px)`, so the
  overlay's `width: 100%` is never the smaller of the two. ⚠️ **The `+ 2px` is not a fudge:** an
  absolutely positioned child's `width: 100%` resolves against the **padding** box while
  `box-sizing: border-box` folds the rule's 1px borders inward, so a flat `min-width: 44px` yields a 42px
  padding box and overhangs by exactly 1px each side — caught by re-deriving the arithmetic, not by a
  scanner.
  ✅ **MEASURED IN A BROWSER AT 320px, HIT-TESTED RATHER THAN READ OFF THE STYLESHEET.** Overlay
  **44×44 on every chip**; clearances **8px horizontal, 9px vertical**; **0px overhang past the frame**;
  `documentElement.scrollWidth === clientWidth`. The hit walk itself reports 43 because
  `elementFromPoint` treats a box as `[top, bottom)` and loses one boundary pixel — the computed
  pseudo-element box is the authoritative number and the walk is a second, independent check that the
  target is genuinely reachable.
  ⛔ **AND THE REASON IT SURVIVED A GREEN GATE STACK IS THE FIXTURE, AGAIN.** SC 2.5.8's floor is 24×24,
  so **axe passes a 32px control and always will** — what is breached is §4.4's stricter 44×44, which no
  scanner here implements. Worse, all three stacked stories declared **one** sortable column, so the
  sortbar has rendered a single chip for as long as it has existed: it had never wrapped, never had a
  second row to be 8px away from, and never had a short header to overhang. A `StackedSortWrap` story
  (five sortable columns incl. a two-character `ID`, 20em box, light + dark) now reaches all three.
  Recorded in DSR §4.4 as an as-built amendment.

  *The original row, preserved:*

  **`major` · The stacked sort chip ships a 32px touch target in the one layout that exists only on the
  touch band.** `packages/design-system/src/components/DataTable/DataTable.vue:488-495`, rendered only
  below `@container (max-width: 56em)` (`:657-659`) where `thead` is `display: none`, so it is the *only*
  sort affordance on an 834px tablet — 32px tall, 8px apart, wrapping. DSR §4.4 binds 44×44 with ≥8px
  between hit areas, and four siblings in this same package already satisfy it with the prescribed
  `::before` idiom (`Button.vue:102-114`, `Alert.vue:178-190`, `Checklist.vue:222-246`, `Toast.vue`).
  **Live.** ⚠️ It does **not** fail WCAG 2.2 AA (SC 2.5.8's floor is 24×24), so axe stays green — what is
  breached is the DSR's own stricter rule, and `docs/ux/exceptions-log.md` carries no entry for it. Filed by `M1`.

- ✅ **CLOSED BY `M20` (2026-08-26) — `minor` · ~~The pending-state ring measures 2.33:1 (light) /
  2.96:1 (dark) against its own ground.~~**
  ⛔ **HALF THIS ROW IS FALSIFIED, AND THE HALF THAT SURVIVES IS WORSE THAN IT LOOKS.** Both citations
  hold as *text* — `PasswordStrength.vue:212-218` and `Checklist.vue:289-295` carried a
  character-identical 55% alpha on `currentColor`, and a repo-wide grep returned exactly those two lines.
  **They were never the same ring.** `currentColor` is `--mds-color-text-secondary` in one and
  `--mds-color-text-body` in the other, so measured in a browser against the real ground:

  | | as shipped (0.55 alpha) | as fixed (solid) | verdict |
  |---|---|---|---|
  | `MdsPasswordStrength` light | **2.28:1** | 5.55:1 | a real 1.4.11 failure |
  | `MdsPasswordStrength` dark | **3.08:1** | 7.51:1 | already passing, by 0.08 |
  | `MdsChecklist` light | **3.60:1** | 14.84:1 | already passing |
  | `MdsChecklist` dark | **5.37:1** | 15.70:1 | already passing |

  **One of the four cases the row filed was actually below the bar.** The row's own 2.33/2.96 are right
  arithmetic against `--mds-color-bg-surface` and were then applied to a second component that does not
  share the ink and to a story that does not share the ground. ⚠️ **When a defect is filed against two
  files because the text matches, measure both before believing it is one defect.**
  ⛔ **BOTH WERE STILL FIXED, AND NOT TO SPLIT THE DIFFERENCE.** The dark row is the argument: the same
  declaration measures **2.96:1 on `--mds-color-bg-surface` and 3.08:1 on `--mds-color-bg-canvas`** — it
  fails on a card and passes on the page, and nothing in the component decides which one it gets. An
  alpha composite is a function of the ground behind it and a shared component does not own its ground;
  raising the alpha to 0.75 clears the bar today (3.43:1 light) and leaves that fragility exactly where
  it was. The alpha is gone from both; the ring is the same solid ink as the label beside it.
  ⚠️ **THE TWO NOW RENDER AT DIFFERENT WEIGHTS — 5.55:1 AND 14.84:1 — DELIBERATELY.** That is the rule
  being consistent rather than the components disagreeing: in both, the ring is exactly the ink of its
  own label, which is what an empty checkbox should be. Hard-coding one token across both to make the
  numbers match would break that. Guarded in both test files (no alpha in the rule; the ring stays a
  border) and recorded in DSR §4.1 as an as-built amendment.

  *The original row, preserved:*

  **`minor` · The pending-state ring measures 2.33:1 (light) / 2.96:1 (dark) against its own ground** —
  below WCAG 1.4.11's 3:1 for a non-text indicator — at
  `packages/design-system/src/components/PasswordStrength/PasswordStrength.vue:212-218` and
  `Checklist/Checklist.vue:289-295`, while both docblocks assert the glyph is the signifier. **Live.** Filed by `M1`.

### App UI

- ✅ **CLOSED BY `M23` (2026-08-26) — `major` · ~~Double-clicking "Create" provisions two spreadsheets in
  the tenant's Drive.~~** Fixed in BOTH places, because they are two different defects that happen to meet
  at this button. `SheetsRuleFields.vue`'s `create()` now early-returns on `busy`, and `Button.vue`'s guard
  now calls `stopImmediatePropagation()` instead of `stopPropagation()`.
  ⛔ **THE ROW'S DIAGNOSIS OF `Button.vue` WAS EXACTLY RIGHT AND ITS CLOSING JUSTIFICATION WAS EXACTLY
  WRONG.** It says the other `:loading` buttons are safe because Inertia's stream is
  `maxConcurrent: 1, interruptible: true`. That is a property of the constructor, not a dedupe:
  `interruptInFlight()` **aborts the in-flight XHR and sends the second request anyway**, and the first
  POST has already reached the server. What actually protects the rest of the tree is unrelated — they are
  `type="submit"` inside a `@submit.prevent` form, and the guard's own `preventDefault()` suppresses the
  implicit second submission. The conclusion (Sheets is the only irreversible-external one) survives; the
  mechanism does not, so do not reuse the reasoning.
  ⛔ **AND THE COMPONENT-LEVEL FIX ONLY WORKS BECAUSE OF A VUE MERGE ORDER, WHICH WAS MEASURED RATHER THAN
  ASSUMED.** Vue merges a component's own template handler with the parent's fallthrough `@click` into one
  array, the component's own runs **first**, and Vue patches `stopImmediatePropagation` on the event
  specifically so it breaks out of that array. Probe: `stopPropagation()` → consumer handler called once;
  `stopImmediatePropagation()` → called zero times; order came back `["inner","consumer"]`. Had the order
  been reversed, no design-system fix would have existed at all.
  ⚠️ **`as="a"` PLUS `:loading` HAD NO GUARD OF ANY KIND** — an anchor ignores native `disabled`, and
  `pointer-events: none` is keyed on `isLink && disabled`, never on loading. Latent (no call site combines
  them) and now closed by the same one-word change. Pinned by a case in the new `Button.test.ts`.
  ⚠️ **`AirtableRuleFields.vue` WAS MEASURED, NOT ASSUMED, AND DOES NOT SHARE THE DEFECT** — it has no
  create-destination button at all; its two `busy`-aware `:disabled` bindings are on `MdsSelect`. Filed by `M1`.
- ✅ **CLOSED BY `M23` (2026-08-26) — `minor` · ~~The unearned-badge medallion disappears in dark mode.~~**
  Now `--mds-color-status-neutral-bg`: unchanged in light (`#EEF3FE` on `#FFFFFF`), and `#2c374c` on
  `#1a2130` in dark, which is **1.35:1** against the exactly **1.00:1** it was. ⚠️ **THE ROW UNDERSTATED
  IT IN ONE DIRECTION AND OVERSTATED IT IN ANOTHER.** Understated: in dark the primitive *is*
  `--mds-color-bg-surface` (`theme-overrides.css:113` re-points the surface at `neutral-100`), so the disc
  was painted its own card's colour — not merely low-contrast but mathematically absent. Overstated: it
  called this the only primitive reference under `resources/js/Pages/`; measured, it was the only one in
  the whole of `resources/`, which is what made the gate below cost nothing. Filed by `M1`.
- ✅ **CLOSED BY `M23` (2026-08-26) — `minor` · ~~The top-nav search field never shows the active query on
  an Inertia arrival.~~** ⛔ **THE ROW'S FIX WOULD HAVE SHIPPED A REGRESSION, AND THAT IS THE FINDING.**
  Read from `usePage().url` unconditionally, as the row describes, the field displays **any** page's `q` —
  and `q` is the shared filter key on six other list pages (`forms/Index.vue:60`, `audit/Index.vue:59`,
  `feedback/Index.vue:47`, `submissions/Inbox.vue:144`, `members/Index.vue:63`, `webhooks/Index.vue:73`),
  every one committing client-side with `preserveState`. The workspace-search box would have shown the
  audit ledger's filter term, where pressing Enter posts it to `/search` and silently turns "filter this
  list" into "search everything". Shipped gated on `page.component === 'search/Index'`, which also fixes
  the pre-existing full-page-load version of that bleed instead of widening it.
  ⚠️ **THE BROWSER BACK BUTTON WAS BROKEN TOO, AND THE FILE'S OWN DOCBLOCK SAID OTHERWISE** — it asserted
  that Back "arrives as a fresh render"; Inertia intercepts popstate and swaps in place with no document
  load. The false sentence was the stated justification for the implementation and is gone. Filed by `M1`.
- ✅ **CLOSED BY `M23` (2026-08-26) — `minor` · ~~The rule modal filters the rendered checkboxes but
  submits the unfiltered set.~~** Both the seed and the transform now read one shared narrowing, and the
  fieldset names any event it is dropping. ⛔ **"SILENTLY SENDS THE UNFILTERED SET" IS THE MILDER OF THE
  TWO OUTCOMES AND NOT THE COMMON ONE.** On a live tabular grant the server *does* reject it — under
  `event_types.{index}`, a **dotted** key, while this modal renders only the bare `event_types`. So the
  tenant got a 422 with no visible cause on a modal that stays open, and could no longer rename the rule,
  rescope it, or repair its destination. A dead end, not a dirty payload.
  ⚠️ **THE HINT IS NOT POLISH.** Filtering at seed time changes stored data on the next save for any
  reason, and a rule whose only event is undeliverable seeds to an empty array — so without the sentence
  the tenant sees an untouched form and is told "choose at least one event", which is legible and has the
  wrong cause.
  ⚠️ **`WebhookFormModal.vue:110-116` WAS CHECKED INDIVIDUALLY AND IS NOT THE SAME DEFECT** — it iterates
  the unfiltered `eventTypes` prop, so rendered already equals sendable there. Filed by `M1`.

- **`minor` · The delivery-rule modal's channel-refresh button is the same unguarded shape, GET-only.**
  `resources/js/components/integrations/RuleFormModal.vue:350-359` — a `:loading`-bound `MdsButton` whose
  `@click` reaches a raw `fetch`, with the component's own `channelsLoaded && !force` re-entry check
  bypassed by `force = true`. `MdsButton`'s repaired guard now stops the duplicate click, so this is
  **not live**; it is filed because the row above it was closed on the argument that the *side effect* is
  what makes a button dangerous, and the next fetch-backed button written in that file should not be
  written this way. Fix is the same one-line `if (channelsLoading.value) return;`. Filed by `M23`. **Not live** — the repaired button guard already stops the duplicate click, exactly as the row itself says, judged by `M65`.
- **`minor` · Thirteen Vitest stubs across four files are silently inert.**
  `resources/js/Pages/submissions/show.test.ts:109-113,266-270` · `resources/js/components/sso/cards.test.ts:37`
  · `resources/js/components/sso/SsoPolicyCard.test.ts:84` · `resources/js/Layouts/AppLayout.test.ts:47` —
  all key `global.stubs` on the **barrel export alias** (`MdsCard`, `MdsBadge`, `MdsModal`, `MdsTextarea`,
  `MdsFormField`, `MdsToastHost`). Vue Test Utils matches a stub against the component's own inferred name,
  which for a `<script setup>` SFC is its **filename** — `Card.vue` gives `Card`. So none of them matches
  and the real component renders in every case. **MEASURED, NOT INFERRED**, and found the expensive way:
  M23's first draft of `SheetsRuleFields.test.ts` keyed on `MdsButton`, the real button rendered, its own
  guard absorbed the extra clicks, and the spec passed **with the fix reverted** — the same three-click
  case reports 1 call under the `MdsButton` key and 2 under `Button`. **Not live** — no production defect —
  but every one of those four suites is exercising more component than it says it is, and any of them could
  be silently vacuous the way M23's nearly was. ⚠️ Fixing them changes what four suites actually cover, so
  it is its own increment, not a rename. Filed by `M23`.
- **`minor` · A semantic token is no guarantee of a visible element, and one more instance is probably out
  there.** M23 added a gate banning *primitive* ramp references in application code, then immediately found
  the identical defect wearing a *semantic* token: `LogicRail.vue`'s `.rail__dot` was
  `--mds-color-bg-sunken` on a `--mds-color-bg-canvas` ground, and in dark **both resolve to
  `--mds-neutral-50`** — 1.000:1, fixed in the same increment. The general check is "does every painted
  element differ from the ground it actually lands on, in both themes", which needs the resolved ancestor
  chain and is not a source-text scan. **Not live** as far as two hand-audits reach; filed because the gate
  that shipped covers the cheap half only and must not be read as closing the class. Filed by `M23`.

### Test suite & CI gates

- ~~**`minor` · A SUCCESSFUL password confirmation is unreachable from the Pest harness, so nothing asserts one.**~~
  Filed 2026-09-03 by `M68`, found when a case asserting `assertSessionHasNoErrors()` on
  `POST /user/confirm-password` failed with *"The provided password was incorrect"* against a user the
  factory had created with that exact password. **The cause is structural, not a fixture mistake:**
  `config/auth.php`'s provider is `rls_aware`, so `RlsAwareUserProvider` resolves the user on the SEPARATE
  `pgsql_auth` connection — and Fortify's default `ConfirmPassword` action calls `$guard->validate()`, which
  goes through that provider. Under `RefreshDatabase` the whole test is one open transaction on the DEFAULT
  connection, which a second session cannot see, so the lookup finds no row and the answer is always
  "incorrect". It is the same separate-session trap `FortifyRouteContextTest::rereadUser()`'s docblock
  records from the other direction. ⚠️ **AND THE EXISTING COVERAGE READS AS THOUGH IT PROVED OTHERWISE**:
  `FortifyRateLimitTest` posts a correct password and asserts `assertStatus(302)`, which a *failed*
  confirmation also returns — so that file is unaffected for its own purpose (302 vs 429) while looking like
  a positive control for the credential path. **Nothing in the repository asserts that a correct password
  confirms.** `M68` worked around it by asserting the gate rather than the credential, which is the right
  scope for that file and leaves this open. **The remedy is a choice**: commit the fixture user outside the
  transaction, or bind the auth provider to the default connection under test. **Live** — as a coverage
  hole rather than a product defect: the step-up path is exercised in production and by the E2E suite, and
  it is the unit-level positive control that cannot exist. Filed by `M68`.
  ✅ **DONE — M70 (2026-09-04). THE EVIDENCE WAS INTACT AT EVERY CITATION AND THE PREMISE — *"THE REMEDY
  IS A CHOICE"* — WAS STALE, WHICH IS WHAT MADE THIS SMALL.** The row offers committing the fixture
  outside the transaction **or** binding the auth provider to the default connection under test. **The
  repo took the first years ago and wrote it down as a rule**: `docs/testing-strategy.md` requires auth
  fixtures to be seeded with a committed write on the privileged connection, and **six helpers implement
  it**, of which `committedTenantIdentity()` is the promoted global one — shipping a known plaintext
  password. The second arm is not merely unchosen but hostile: `rls_aware` is the explicit **subject** of
  at least six suites, two of which assert `getConnectionName()` and name deleting `retrieveById()`'s
  `setConnection()` as their own mutation check. **A fixture swap, not a fork. No `app/` change at all.**
  `tests/Feature/Auth/PasswordConfirmationTest.php` carries four cases: the credential confirms; a wrong
  password mints nothing; `Fortify::$confirmPasswordsUsingCallback` is null (**the vacuity guard** — the
  success case is otherwise fully satisfied by a permissive `confirmPasswordsUsing()` registered anywhere
  in the application); and the in-transaction identity is **refused**, a negative control on the fixture
  itself that pins the trap which produced this row. ⛔ **NOT ONE OF THEM ASSERTS A STATUS CODE AS
  EVIDENCE, AND THE ROW IS RIGHT ABOUT WHY**: `FailedPasswordConfirmationResponse` returns
  `back()->withErrors(...)` — a 302 — and success returns `redirect()->intended(...)`, also 302. Proved
  with `mutate.php`: the fixture swapped to `User::factory()` reddens the success case **only**,
  reproducing `M68`'s original report; a permissive callback registered in `FortifyServiceProvider`
  reddens **three** — the refusal, the vacuity guard and the negative control — while leaving the success
  case green, which is correct. `FortifyRateLimitTest` is deliberately untouched: it is unaffected for its
  own purpose (302 vs 429), and it is the file whose 302 assertion *looks* like a positive control.
  `TenantTwoFactorEnforcementTest`'s ⛔ block is narrowed rather than deleted — a successful confirmation
  is unreachable from **that file's** factory fixtures, not from the harness.

- ~~**`minor` · `deploy.yml`'s effective trigger CHANGED in `M39`, and nothing says so at the site.**~~
  It fires on `workflow_run` of CI `completed` on `main` gated on `conclusion == 'success'`. Before `M39`
  every merge run on `main` was cancelled, so **the only runs that could ever have reached it were
  docs-only close-out runs** — a deploy path that could only ever have shipped a documentation commit.
  `M39` fixed the cancellation, so it will now fire on **real merge runs**, which is correct and is also a
  material change to a latent production path that `deploy.yml` itself does not mention. **Latent, not
  live:** `DEPLOY_ENABLED` is unset (`gh variable list` is empty), so the job is skipped. ⚠️ **The row
  exists because the day that variable is set is the wrong day to discover that the trigger's meaning
  changed.** Filed by `M39 (2026-08-28)`; not fixed there because `deploy.yml` was outside that claim and
  the remedy is a comment plus a decision about whether `paths-ignore` should also guard deploys. Filed by `M39`.
  ✅ **DONE — M69 (2026-09-04). THE HEADLINE HELD; HALF THE REMEDY WAS ALREADY DISCHARGED SOMEWHERE
  ELSE, AND THE ROW COULD NOT HAVE KNOWN.** `.github/workflows/deploy.yml` now records at the site what
  its `workflow_run` trigger selected before `M39` and what it selects now. Re-verified before writing:
  `gh variable list` is still empty, so `DEPLOY_ENABLED` is unset and the job is still dormant.
  ⛔ **THE `paths-ignore` DECISION THE ROW ASKS FOR IS ALREADY MADE BY THE TREE.** `workflow_run` offers
  no path filter, and it does not need one: `ci.yml`'s `push` filter skips the close-out documentation
  paths, and **a push GitHub filters out produces no run at all** — so there is no `workflow_run` event
  for `deploy.yml` to receive, and `ci.yml`'s own comment says exactly that. No decision was filed in
  `docs/claims/decisions.md`, deliberately: that file's header says a resolved judgement does not belong
  there, and duplicating the filter into `deploy.yml` would be a second description of one fact — the
  `M56` class. What `deploy.yml` carries instead is a pointer plus the warning that the dependency runs
  the opposite way from how it reads: **shortening `ci.yml`'s list re-opens the deploy path.**

- ✅ **CLOSED BY `M42` (2026-08-29) — `minor` · ~~`scripts/preflight.php` derives the next increment
  number from prose, so a FORECAST reads as a SPEND.~~** The number now comes from
  `php scripts/state.php`, which takes it from the `## RELEASED — M<n>` headings of both claim files —
  each truncated at its own `## Template` heading — and cross-checks the maximum against
  `gh pr list --state merged`. `preflight.php` calls that script instead of parsing anything itself:
  one authority, referenced rather than copied, exactly as Rule 7(b) requires of the lane boundary.
  ✅ **THE ROW'S PRESCRIBED REMEDY WAS SOUND AND IS WHAT SHIPPED**, which is the first time in six rows.
  ⚠️ **AND THE ROW UNDERSTATED ITSELF: it names one consumer and there are two.** The same wrong
  number was rendered into every hand-off line, which is the artefact a fresh session actually reads —
  so the fix is only complete because `scripts/next.php` generates that line from the same figures.
  ✅ **MEASURED ON THE TREE THAT REPRODUCES IT.** Before the change, on this branch, `preflight`
  answered *"highest M seen: `M42` → next free is `M43`"* — raised by this increment's own claim naming
  its own number, the compounding the row predicted. After it: *"highest released `M41` → next free is
  `M42`"*, with the merged-PR titles agreeing independently.
  ✅ **Positive controls, both directions.** A prose `M99` appended to `lane-a.md` leaves the answer at
  `M42`; the same text as a real `## RELEASED — M99` heading moves it to `M100`; and a numbered
  RELEASED heading placed *below* the Template heading makes the script exit 2, CANNOT MEASURE, rather
  than silently returning a lower maximum. Every mutation moved the sha256 and every restore was
  byte-exact. Filed by `M38`.
- **`minor` · `baselineOf()` turns "no checksum" into `''`, and only middleware turns it back.**
  `tests/Feature/Submissions/SubmissionEditRoutesTest.php:62` returns `(string) $value`, so a null
  `answers_content_checksum` reaches the request body as an **empty string**, and it is
  `ConvertEmptyStringsToNull` — not the helper, and not anything in the test — that restores the null the
  service actually compares. **The round trip is correct by coincidence of two unrelated behaviours.**
  ⚠️ **Not a bug today and unlikely to become one**, because M31 added cases that drive the real-checksum
  path directly and would redden if the coercion started mattering. Filed because the `''` is a **cast
  artefact rather than a value anybody chose**, and a reader who assumes it is meaningful will mis-model the
  guard. **Deliberately left by M31** — changing the return type touches every case in the file, which is a
  larger diff than the finding justifies. **Latent.** Filed by `M31`.
- ~~**`major` · The 16-page responsive scan asserts nothing about which page it landed on.**~~
  ✅ **DONE — M25 (2026-08-26), AND THE DEFECT WAS MEASURED LIVE RATHER THAN ARGUED.** One assertion per
  *loop* — `expect(page.url(), …).toContain(p.path)`, which is `support/console.ts:34`'s idiom rather
  than a new one — so **two inserted lines cover 42 tests**: the 32 of this file's 16-page loop, and the
  10 of `auth-axe.spec.ts`'s unauthenticated loop, which had the identical hole and whose own header
  already named the hazard without asserting it.
  ⚠️ **PROVEN BOTH WAYS WITH THE ROW'S OWN SCENARIO, WHICH IS WHY THIS ROW IS WORTH ITS LENGTH.** With
  `modules.gamification` set `false` for `acme`, the two Achievements tests fail with
  `expected to be on /achievements, not http://acme.localhost/dashboard`. With the assertion reverted and
  the module still off, the same two report **`2 passed` in 1.1m** — having scanned the dashboard in both
  themes under a test name ending *"Achievements"*. **That green is the defect**, and reproducing it was
  the only way to know the row was describing something real rather than something plausible.
  ⚠️ **BOTH OF THE ROW'S OWN CITATIONS WERE WRONG AND ARE CORRECTED HERE.** The loop is **`:148-156`**,
  not `:124-132` — that is inside the `pages` ARRAY, in the `Two-factor required` entry's comment block.
  The `filteredToZero` idiom is **`:175-188`**, not `:154-163`. `bootstrap/app.php:315-334` and
  `support/console.ts:34` both hold; the former is narrower than the truth, since **seven** handlers in
  that file return the same toast redirect (`:286`, `:297`, `:310`, `:323`, `:336`, `:389`, `:458`), so a
  302 is this application's standard web refusal rather than a special case.
  ⚠️ **AND THE ROW UNDERSTATED ITSELF — FOUR PAGES ARE EXPOSED, NOT ONE.** `/achievements` on
  `module:gamification` (`routes/tenant.php:258-259`; `TenantSettingRegistry.php:145-150` defaults a
  MISSING row to true, which is the only reason it loads), plus `/analytics`, `/webhooks` and
  `/integrations` on `feature:` PLAN gates (`:891-892`, `:762-763`, `:823-824`) whose only guarantee is
  that `E2eSeeder` upserts `acme` onto Business. Verified in the running database rather than assumed:
  `acme` is on `business` and carries **no `modules.*` row at all**. The file's own Analytics comment
  calls that seeding *"a blocking obligation, precisely so this gate cannot stay green over a page it
  never loads"* (ADR-0011 §D9) — the obligation is real, it is discharged, and **nothing checked that it
  was** until this row closed. The other twelve entries are `can:`-gated and raise **403**, a different
  and much weaker exposure; that is named in the spec's own comment so a later reader does not
  "complete" the sweep by adding assertions for a redirect that cannot happen.
  ⚠️ **THREE LOOK-ALIKES WERE ALREADY PASSING AND WERE DELIBERATELY LEFT ALONE** — M20's lesson that a
  character-identical declaration is not an identical defect, re-measured and holding for a second
  increment. `templates-axe.spec.ts:12-17` was the strongest-looking candidate of the five (
  `/forms/templates` genuinely IS `feature:form_templates`-gated, `routes/tenant.php:486-487`) and
  **already asserts `Use this template` is visible**, which no dashboard can satisfy;
  `admin-console-axe.spec.ts:45-52` routes through `openConsole()`, which **ends** in `console.ts:34`'s
  URL assertion; and this file's own `filteredToZero` loop already asserts its `No matching` heading —
  which is precisely why `/webhooks?q=…` was covered while bare `/webhooks` twenty lines above was not.
  **`personalization-axe.spec.ts:36-42` and `:56-68` were checked and left**: `/settings` carries no
  middleware at all (`routes/tenant.php:267`) and `/forms` and `/submissions` are `can:`-gated, so there
  is no 302 there to catch. Original filing follows.
  — *`tests/e2e/responsive-axe.spec.ts:124-132` is `goto` → `forceTheme` → `assertClean` with no
  `waitForURL`, `toHaveURL` or heading check — and every plan/module refusal in this app answers a web
  request with `back()->with('toast')` (`bootstrap/app.php:315-334`), i.e. a 302 the goto follows
  silently. `/achievements` is gated by `module:gamification` and `E2eSeeder` never enables the module —
  it relies entirely on `ToggleableModules`' default — so flipping that default gives six green scans of
  the dashboard. Latent, and the idiom is present everywhere else in the shard, including the
  `filteredToZero` loop twenty lines below (`:154-163`) and `support/console.ts:34`. One line per test.* Filed by `M1`.
- ~~**`major` · The login and 2FA-challenge rate limiters are asserted by no test in the repository.**~~
  ✅ **DONE — M30 (2026-08-26). THE HEADLINE HELD, BOTH STATED MECHANISMS DID NOT, AND VERIFYING THEM FOUND
  A LIVE DEFECT ONE DOOR OVER THAT IS WORTH MORE THAN THE ROW.** `tests/Feature/Auth/RateLimiterBindingTest.php`
  (new, 4 cases / 18 assertions) closes the row; `app/Providers/AppServiceProvider.php` and one case in
  `tests/Feature/Guest/GuestDraftRuntimeTest.php` close what the sweep found.
  ⛔ **THE LIVE DEFECT: ONE GLOBAL RATE-LIMIT BUCKET FOR THE WHOLE DEPLOYMENT.** `RateLimiter::for('guest')`
  keyed its per-token `Limit` on `route('shareToken')`. **MEASURED from the live route table** (matched on
  the resolved `ThrottleRequests` class, because `route:list` prints the class while `gatherMiddleware()`
  returns the alias — M13's lesson, reused): five routes carry `throttle:guest`, four declare
  `{shareToken}`, and `GET /api/v1/public/drafts/{resumeToken}` does not. A parameter a route does not
  declare reads back null, `(string) null` is `''`, and `hash('sha256', '')` is a **constant** — so every
  draft-resume request in the deployment, across every tenant, shared **one** 30/min bucket. The throttle is
  middleware index **0** on that route, ahead of `EstablishGuestDraftContext`, so a **garbage** token spent
  that budget before anything verified it; the per-IP arm allows 60/min, so one unauthenticated IP could
  refuse every legitimate resume link platform-wide for free. `EnforceGuestFormRateLimit` is not on that
  route. Full row in `docs/security-threat-model.md` §4.
  ⛔ **A LIMITER'S KEY IS A CONTRACT WITH THE ROUTE'S PARAMETER *NAMES*, AND NOTHING EXPRESSED IT.** F5's
  comment (*"Keyed on the raw {shareToken} string"*) was accurate when every route on the limiter carried
  that segment. H9b's resume group then reused `throttle:guest` **correctly** — same gate as the draft-save
  channel — and reused a key that had silently stopped applying. **Neither change was wrong on its own.**
  ⚠️ **THE ROW'S TWO MECHANISMS, BOTH CORRECTED AGAINST THE VENDOR SOURCE RATHER THAN ARGUED.**
  **(a)** *"unmetered credential stuffing"* on the login half is **wrong**: nulling `fortify.limiters.login`
  drops `throttle:login`, but vendor `AuthenticatedSessionController.php:86` re-inserts
  `EnsureLoginIsNotThrottled` into the default pipeline on exactly that condition, and this app sets no
  `pipelines` key and never calls `Fortify::authenticateThrough`, so the branch **is** reached — 5 *failed*
  attempts/min on `lower(email)|ip`, the same key `FortifyServiceProvider.php:160` builds. A degradation,
  not an absence. **(b)** The **rename** mutation is **already covered, loudly**: on `laravel/framework`
  v13.18.1 `ThrottleRequests::resolveMaxAttempts()` throws `MissingRateLimiterException` for an unregistered
  name, so a rename 500s every login POST and reddens `AuthenticationTest.php:48` and
  `TwoFactorChallengeTest.php:174` today. **A test written to the row's stated rationale would have been
  aimed at a mutation the suite already catches.** **(c)** What survives is the severe half: nulling
  `fortify.limiters.two-factor` leaves TOTP and recovery-code guessing with **no bound anywhere** — the
  vendor controller counts nothing, the form request counts nothing, no `Lockout` listener exists.
  ⛔ **FOUR POSITIVE CONTROLS, EACH RESTORED BY sha256 BYTE COMPARISON, AND ONE OF THEM FOUND A VACUOUS
  TEST A GREEN RUN WOULD HAVE SHIPPED.** **C1** dropping the `resumeToken` arm reddens the key case *naming
  `api/v1/public/drafts/{resumeToken}`*. **C2** nulling `fortify.limiters.two-factor` reddens the binding
  case and **leaves the registration case green** — proving the two measure genuinely different facts.
  **C3** a mistyped limiter name in the route walk reddens the non-vacuity guard with its own message.
  **C4** the behavioural case fails on the pre-M30 key at exactly the third request, and **only** that case
  in a 31-case file. ⚠️ **C1 IS WHY THE IP-FALLBACK CASE EXISTS AT ALL IN WORKING FORM:** its first draft
  was `->not->toContain($key, $message)` and stayed **green** with the offending key sitting in the array,
  because **Pest's `toContain` takes VARIADIC NEEDLES, not a needle and a message** — the explanatory
  sentence was being asserted as a second thing the array should not contain. Only reading what the control
  PRINTED, rather than its exit code, caught it.
  ⚠️ **THE STRUCTURAL GATE ASKS THE QUESTION INSTEAD OF CHECKING A LIST, WHICH IS THE DESIGN.** It *invokes*
  the limiter for every live route bound to it with two different token values and asserts the two bucket
  keys differ. Holding its own copy of the parameter names would have been the paired-list hazard of
  Standing Rule 7(b-bis) reproduced one file later; as written, a sixth route with a third parameter name
  reddens it **without the test knowing the name**. The fallback arm keys on the IP so a future mismatch is
  per-caller rather than deployment-wide, and a second case fails if any live route ever reaches it — a
  floor that announces itself rather than a silent degradation.
  **GATES.** Local `tests/Feature/Auth` **127 → 131 / 615 → 633** (exactly the four new cases);
  `tests/Feature/Guest` **77 / 319** green. PHPStan local **18 = baseline, zero delta by FILE LIST**
  (`AppServiceProvider.php` appears in none of them). Pint **PASS, 3 files** — the printed count, not the
  word. `openapi.json` **byte-identical**, confirmed by `cmp` against a fresh `scramble:export`; the claim
  predicted this in writing before the file was opened, on the ground that a 429 comes from route middleware
  no controller mentions. Original filing follows.
  — *`config/fortify.php:169-172` maps them by string and `FortifyServiceProvider.php:159,165` registers the
  closures; Fortify `array_filter`s the middleware, so nulling either config value or renaming either
  registration produces a route with **no throttle at all** — an exhaustible 6-digit TOTP and unmetered
  credential stuffing, with nothing red. **Latent.** The project already guards exactly this elsewhere:
  `SsoLoginWebTest.php:285` asserts every SAML limiter it names actually exists, precisely because a
  `throttle:` alias naming an unregistered limiter "resolves to an UNLIMITED PASSTHROUGH".* Filed by `M1`.
- ~~**`major` · `POST /user/confirm-password` carries no rate limit at all.**~~
  ✅ **DONE — M43 (2026-08-29). THE HEADLINE HELD EXACTLY, THE ROW WAS A FLOOR ONE-EIGHTH THE SIZE OF THE
  DEFECT, AND ITS PRESCRIBED REMEDY WAS STRUCTURALLY IMPOSSIBLE RATHER THAN MERELY WRONG.**
  `app/Http/Middleware/ThrottleFortifyEndpoints.php` (new), seven limiters in `FortifyServiceProvider.php`,
  one entry each in `config/fortify.php` and `bootstrap/app.php`, and
  `tests/Feature/Auth/FortifyRateLimitTest.php` (new).
  ⛔ **EIGHT ROUTES, NOT ONE — AND THREE OF THEM NEED NO SESSION AT ALL.** Measured from the live route
  table rather than from the row: **26 Fortify routes, 14 of them writes**, and Fortify ships `throttle:`
  on **four** (`POST /login`, `POST /two-factor-challenge`, and the two verification routes at the
  vendor's literal `6,1` — `config/fortify.php` names no `verification` key, so that number was never a
  decision this project made). Unbounded and credential-bearing: **`POST /forgot-password`** (unlimited
  reset-mail dispatch and account enumeration), **`POST /reset-password`** (reset-token guessing) and
  **`POST /register`** — none of which needs a session — plus `PUT /user/password`, the row's own
  `POST /user/confirm-password`, `POST /user/confirmed-two-factor-authentication` (**an exhaustible
  six-digit TOTP whose only other bound is nothing at all**) and the three 2FA lifecycle verbs.
  ⛔ **THE PRESCRIBED FIX HAS NOWHERE TO LAND, AND THE REPOSITORY ALREADY SAID SO THREE TIMES.** *"One
  `RateLimiter::for()` plus one `->middleware('throttle:…')`"* — **Fortify has no per-route middleware
  hook**; `config/fortify.php` records that at the `GateRegistration` note, at the
  `EstablishTenantDatabaseContext` note, and again where it explains that `bootstrap/app.php`'s
  `priority()` cannot substitute because priority reorders middleware a route already carries and never
  adds one. **Sixth row in seven whose evidence is sound and whose remedy is not.**
  ⚠️ **THE MAP IS KEYED ON ROUTE NAME, AND THE `.store` SUFFIX IS THE TRAP THE PLAN WALKED INTO.** Three
  Fortify GET/POST pairs share a path, so a path map needs a verb table beside it. The write routes are
  `register.store`, `password.confirm.store` and `two-factor.regenerate-recovery-codes`; `register`,
  `password.confirm` and `two-factor.recovery-codes` are the **GET view pages**. A map keyed on the
  obvious-looking name throttles three pages the axe suite scans, leaves all three endpoints open, **and
  every behavioural test still passes** — because the pages are not what anything posts to. The plan for
  this increment named all three wrongly and was corrected against the vendor route file before a line was
  written; a dedicated case now fails on exactly that mutation.
  ⛔ **AND THE PLAN'S ORDERING ARGUMENT WAS WRONG IN THE DIRECTION THAT WOULD HAVE PUT A FALSE CLAIM IN A
  DOCBLOCK.** It said the class must be listed in `bootstrap/app.php`'s `priority()` or `$request->user()`
  would read null, silently degrading every authenticated limiter to its IP arm. **Measured on the live
  route table both ways: with the entry deleted the class still lands last, at index 13, and still after
  `Authenticate` at index 5.** The user resolves either way. The entry is kept for the reason that survived
  measurement — refusal at index 6 rather than 13, i.e. ahead of `EstablishTenantDatabaseContext`'s
  database round trip, which is what bounds the *work* a flood causes rather than only the mail — and all
  three comments that had stated the false reason were rewritten. **A false claim about a control is worse
  than a missing one, because it stops the next reader looking.**
  ⚠️ **`ThrottleRequests::handle()` GATES ITS NAMED-LIMITER BRANCH ON `func_num_args() === 3`.** A fourth
  argument — a decay, a prefix, a tidy-up — silently routes to the numeric path, where
  `resolveMaxAttempts()` finds a non-numeric value and throws `MissingRateLimiterException`: a 500 on every
  guarded route. Read from the installed source and written into the docblock.
  ⚠️ **CEILINGS SIZED AGAINST A MEASURED PROPERTY, NOT INTUITION.** `ThrottleRequests` counts **successes
  as well as failures and never clears the bucket** — the behaviour `docs/security-threat-model.md` §8
  already records for `login`. So `password-confirm` is 10/min per user rather than 6, because
  `tests/e2e/support/console.ts` posts that form on every console visit whose step-up window has expired,
  across three viewport projects, and those successes count. And because 5/min is 300/hour, the two
  guest-reachable reset paths carry an **hourly** arm keyed on the address (30/hour) — the arm an
  enumerating script actually meets — while `registration` is 5/min + 20/hour keyed on address **and
  host**, because `RegistrationGate` resolves per host and one corporate NAT must not be able to exhaust
  another workspace's budget.
  ✅ **THE GATE WALKS THE LIVE TABLE AND ASSERTS THE EQUALITY IN BOTH DIRECTIONS.** Fortify routes are
  discovered by **controller namespace**, so a route added by a vendor upgrade reddens the file without it
  knowing the name; the map is read **from the middleware class**, never mirrored, which is Standing Rule
  7(b-bis) applied one file later. The second direction is what keeps `login.store` from having a second
  bucket stacked on `throttle:login` — the `guest-challenge` defect this repository has already recorded
  once.
  ⛔ **DOCKER DESKTOP WAS DOWN FOR THE WHOLE BUILD, SO `scripts/mutate.php` COULD NOT DRIVE PEST — ITS
  DISCIPLINE WAS REIMPLEMENTED AT THE CALL SITE INSTEAD**, which is M42's recorded lesson for a gate that
  is not Pest-in-a-container. Four mutants, each with its sha256 asserted **moved**, each `php -l`'d, each
  restored by byte comparison and verified identical, each reddening its expected case **and only it**:
  dropping `password.confirm.store` from the map (coverage, naming the route); adding `login.store`
  (the double-throttle direction); pointing the entry at the GET view route (coverage **and** the
  `.store` case); mistyping a limiter name (the registration case). ⚠️ **That is a proof of the structural
  logic, not of the Pest cases** — the behavioural 429s are proven by CI, and saying which is which is the
  point.
  ➕ **FILED RATHER THAN SILENTLY LEFT:** `PUT /user/profile-information` is a fifteenth Fortify write
  route and a real exposure; it is out of this increment's scope by decision, named in the gate's
  decided-unbound list so it cannot read as an oversight, and filed as its own row below.
  Original filing follows.
  **MEASURED (M30) from the live route table:** its middleware is
  `[web, RequirePlatformHost, AppSecurityHeaders, GateRegistration, Authenticate:web, EstablishTenantDatabaseContext]`
  — no `ThrottleRequests`. Vendor `ConfirmablePasswordController::store()` counts nothing and `app/`
  registers no listener or bespoke middleware for it, so it is **unlimited online password guessing against
  an authenticated session**. It is the redemption door for this app's own step-up gate:
  `RequireRecentPassword` extends `Illuminate\Auth\Middleware\RequirePassword`, and
  `StepUpReauthenticationTest.php:135-147` pins `members.role`, `members.remove`, `members.ownership`,
  `settings.sso.metadata`, `admin.tenants.assign-plan`, `admin.tenants.index` and `admin.settings.update`
  behind it; Fortify's 2FA management (`two-factor.confirm`, `two-factor.disable`) sits behind the same
  gate. ⛔ **THE ASYMMETRY IS WHAT MAKES IT A DEFECT RATHER THAN A DECISION:** the SAML step-up path is
  bounded (`throttle:saml-step-up`, 20/min) and `POST /two-factor-challenge` is bounded — the **password**
  step-up path, verifying the same credential to unlock the same actions, is not. **Live.** Fix is one
  `RateLimiter::for()` plus one `->middleware('throttle:…')`, and it should carry a binding assertion in
  `RateLimiterBindingTest.php`, which already has the helper. **Deliberately left by M30** because it adds a
  limiter to a route that increment does not otherwise touch. Filed by `M30`.
- **`minor` · `PUT /user/profile-information` is a second mail cannon, and it is the one Fortify write route left deliberately unbound.**
  `UpdateUserProfileInformation` nulls `email_verified_at` and calls
  `sendEmailVerificationNotification()` on **every** address change, so one authenticated session can
  dispatch unlimited verification mail to **arbitrary recipients** — the same shape as the
  `POST /forgot-password` exposure M43 closed, one door over, and behind a login rather than in front of
  one. **Live.** ⚠️ **Deliberately out of M43's scope, not missed:** that increment's scope was set at the
  eight credential-bearing routes, and this one verifies no credential. It is named in
  `FortifyRateLimitTest`'s `FORTIFY_UNBOUND_BY_DECISION` list beside `logout`, so the coverage equality
  passes *because a decision was recorded*, not because the route was overlooked — remove it from that
  list and the gate goes red naming it. **The remedy is one line in each of two files** and is already
  built either side of it: a `RateLimiter::for()` closure in `FortifyServiceProvider` and a
  `'user-profile-information.update' => …` entry in `ThrottleFortifyEndpoints::limiters()`. ⚠️ **Size it
  against the same measured property as the rest:** `ThrottleRequests` counts successes and never clears,
  and a legitimate profile save is a success, so a per-minute ceiling under about 10 will eventually
  redden `FortifyRouteContextTest` or `EmailVerificationGateTest`. The address-change arm is the one worth
  keying tightly; a name change sends no mail at all, and nothing currently distinguishes them. Filed by `M43`.
- **`minor` · `throttle:saml-acs`'s route BINDING is asserted by nothing, while its registration is.**
  `SsoLoginWebTest.php:285-291` loops six limiter names and asserts each resolves — which stays green when
  the binding at `routes/tenant.php:1172` is deleted, because the registration at `AppServiceProvider.php:421`
  is untouched. The only test inspecting that route's middleware (`SsoAcsWebTest.php:753-758`) asserts only
  **absences**. Its four siblings (`saml-login`, `saml-metadata`, `saml-login-complete`,
  `saml-step-up-complete`) all carry a positive `toContain('throttle:…')` assertion, so the gap is an
  asymmetry **inside the very test family the closed row above held up as the model**. **Latent.**
  `routesThrottledBy()` in `tests/Feature/Auth/RateLimiterBindingTest.php` is the reusable helper.
  **Deliberately left by M30** — `tests/Feature/Sso/` is Lane B's most active subsystem and that increment
  was already crossing the boundary. Filed by `M30`.
- **`minor` · Two SSO test files justify a real assertion with a rationale that is false on this framework version.**
  `SsoLoginWebTest.php:286-287` and `SsoLoginCompletionWebTest.php:466-469` both say a `throttle:` alias
  naming an unregistered limiter *"resolves to an UNLIMITED PASSTHROUGH"*. **MEASURED (M30):** on
  `laravel/framework` v13.18.1 `ThrottleRequests::resolveMaxAttempts()` throws `MissingRateLimiterException`
  instead — true on Laravel ≤ 9, false here. **The tests are still worth having; the stated reason is not
  the true one**, and this project has recorded three times that a false claim about a control is worse than
  a missing one because it stops the next reader looking. **Not live** — a comment. **Deliberately left by
  M30** for the same lane-boundary reason as the row above, and filed so the correction is not lost. Filed by `M30`.
- ~~**`major` · Every accepted write in the answer-edit concurrency suite compares `null === null`.**~~
  ✅ **DONE — M31 (2026-08-27), AND THE ROW'S OWN PRESCRIBED PROBE WAS ALREADY CAUGHT.** The headline held
  exactly: `SubmissionAnswerFactory` stamps no `answers_content_checksum`, so every fixture row is the
  LEGACY row and the only fact the suite pinned was *"a null baseline against a non-null stored checksum is
  refused"* — the guard held from one side only.
  ⛔ **BUT "DROP THE CLIENT TOKEN AND THE SUITE STAYS FULLY GREEN" IS FALSE AS WRITTEN, AND IT WAS MEASURED
  RATHER THAN ARGUED.** Deleting the guard reddens three cases: editor B re-reads the answer row at
  `SubmissionAnswerEditService.php:114`, so the under-lock check at `:202` compares a value against itself
  and B's write is accepted. **The suite was never blind to REMOVING this guard — only to WEAKENING it**, so
  a deletion probe would have measured zero and proved nothing. Same shape as M30's row: right in the
  headline, wrong in the mechanism.
  ⚠️ **WHAT ACTUALLY SURVIVED — TWO MUTATIONS, OPPOSITE DIRECTIONS, BOTH GREEN ACROSS ALL 60 CASES.**
  (1) `$baseline === null && $stored->answers_content_checksum !== null` — the guard reduced to a PRESENCE
  check, so two editors each holding a real, **different** token never conflict and the lost update the
  guard exists to prevent is silently live again. (2) `$baseline !== null || $stored->... !== $baseline` —
  every non-null baseline refused, which is the row's own *"permanently broken for every submission that
  exists in production"* and the direction the row never named. A third mutation (the adjacent-column typo,
  `answers_schema_checksum` for `answers_content_checksum`) was **already caught**, which is why it is worth
  recording that the null-vs-null equality is more protective than it looks.
  **Four cases added**, two per file, through the real submit pipeline. Positive controls: (1) now reddens
  **exactly** the two refusal cases, (2) reddens all four.
  ⚠️ **NOT FIXED IN THE FACTORY, DELIBERATELY.** Stamping a checksum in `SubmissionAnswerFactory` would
  **convert** the legacy rows rather than **add** the production ones — deleting the only coverage the
  nullable path has, which `EditSubmissionAnswersRequest` supports on purpose so pre-checksum submissions
  stay editable — and changing the fixture shape under every caller of `seedInboxSubmission()` in **both**
  lanes' suites. The new cases are an addition; the old ones stay and still pass.
  ⚠️ **AND EACH NEW CASE CARRIES A NON-VACUITY ASSERTION ON THE BASELINE, PROVEN RATHER THAN ASSUMED**:
  nulling `SubmissionFinalizer.php:96`'s stamp fails all four **at that assertion**, printing *"Failed
  asserting that null is of type string"* against the line. Without it each case silently degrades back into
  a fourth `null === null` the moment the pipeline stops stamping.
  `tests/Feature/Submissions` **415 / 1652 → 419 / 1685**. Filed by `M1`.
- ~~**`major` · `GET /feedback/{report}/screenshot` serves PII and has no DENY test at all.**~~
  ✅ **DONE — M29 (2026-08-26), AND THE ROW'S OWN FIX WOULD HAVE LEFT THE HOLE OPEN.** Both assertions the
  row asks for are in `tests/Feature/Tenant/FeedbackTest.php` — a same-tenant Viewer `assertForbidden` and a
  cross-tenant `assertNotFound`. ⚠️ **THEY ARE NOT WORTH THE SAME AND THE FILE NOW SAYS SO.** `bootstrap/app.php`
  runs `SubstituteBindings` **before** `Authorize`, so the cross-tenant case 404s at route-model binding and
  **passes unchanged with `can:feedback.view` deleted** — proven, not argued: mutation 3 of this increment's
  harness removed that middleware and reddened the Viewer case alone. Only the same-tenant Viewer assertion
  pins the gate. The cross-tenant case is kept because it pins RLS at binding, and it carries a comment
  saying in as many words that it is not a substitute.
  ⛔⛔ **AND THE CENSUS FOUND THE GATE IS WALKED AROUND BY A SIBLING ROUTE — LIVE, NOT LATENT.**
  `GET /attachments/{attachment}` (`routes/tenant.php:751-752`) is authorized by `AttachmentPolicy::view()`,
  whose entire body was `$user->can('submissions.view')` — **it never read its `$attachment` argument**.
  ADR-0015 §D6 filed the screenshot into that same shared table, and `RolePermissionSeeder` grants
  `submissions.view` to `viewer`, `reviewer` and `form_editor` while granting `feedback.view` to none of
  them. So the id-addressed route served the PII image to exactly the three roles the dedicated route
  refuses — and `FeedbackController.php:59-65` says in its own words that it declines to route through
  `AttachmentController` **precisely to avoid that coupling**, which was open in the other direction the
  whole time. Fixed: the policy now reads the kind through a `match`, so a feedback screenshot is read under
  `feedback.view` on every route that serves it or on none. **No permission key minted**, so no paired file
  moved. ADR-0015 gains **§D9**.
  ⚠️ **`AttachmentPolicy` HAD NO TEST OF ANY KIND AND NO HTTP TEST ANYWHERE DROVE THAT ROUTE** —
  `AttachmentRlsTest` is four DB-level cases and every other `attachments` mention under `tests/` is
  `TenantUrl` string-building. `tests/Feature/Attachments/AttachmentPolicyTest.php` is its first, with the
  **viewer-200-on-submission-media positive control named rather than assumed** — without it a policy that
  refused everything would have satisfied every other case in the file.
  ⚠️ **CITATION DRIFT IN THE ROW, THE THIRD INCREMENT RUNNING.** `:154` is a `Storage::assertExists` call;
  the `is_pii => true` assertion is `:150` and the "file's own words" comment is `:149`. `:230` and
  `routes/tenant.php:429-430` were exact.
  ⛔ **THE METHOD IS THE TRANSFERABLE PART: A GATE IS ONLY AS NARROW AS THE WIDEST ROUTE THAT REACHES THE
  SAME BYTES.** Walking the surfaces this row names finds nothing; enumerating *every endpoint in the
  repository that serves stored bytes* and asking of each which test asserts a refusal found that **four of
  ten had one**. The unfixed remainder is filed below and under *Submissions, drafts & the guest runtime*. Filed by `M1`.
- ~~**`major` · Three streamed exports of tenant data have no authorization deny test at all.**~~
  ✅ **DONE — M34 (2026-08-27). EVERY CITATION IN THE ROW HELD; ITS PRESCRIBED FIX DID NOT TRANSFER.**
  Two rows running now whose file:line were all exact — and the second one running whose *remedy* was the
  defective half. `AnalyticsPageGateTest.php:110` really is an entitlement redirect driven by an Owner on a
  Professional plan; the suite's only 403 really is on the `/analytics` index; the ability denial really
  does target the twin, because `analyticsUrl()` defaults to the `'report'` suffix; and no test of any kind,
  positive or negative, had ever issued a request to the API xlsform URI.
  ⛔ **THE PRESCRIBED FIX IS HALF-INAPPLICABLE, AND THE HALF THAT FAILS IS THE ONE THE ROW CALLS STRONGEST.**
  `GET /forms/{form}/submissions/export` can assert a scope denial because `can:export,Submission::class,form`
  binds a **Form instance**. Both analytics exports gate on `can:viewAny,SavedReportView`, whose policy method
  (`SavedReportViewPolicy.php:43`) **takes no model** — there is no instance to be out of scope of, so a scope
  denial there is not weak, it is *structurally impossible*. Only the xlsform route takes both arms. The
  per-form narrowing for analytics is `AnalyticsFormSet`'s, applied to the **rows inside the exporter**, and
  it is a row-level concern rather than a 403 one; it is already pinned as a layer by
  `FormAnalyticsPageTest.php:177` and `DashboardMetricsServiceTest.php:154-172`.
  ⚠️ **AND THE ROW'S ROLE HALF NEEDED A FIXTURE THE ROW DOES NOT NAME.** All five seeded roles hold
  `dashboard.form.view` and `viewAny` is `dashboard.org.view || dashboard.form.view`, so **no seeded role can
  be refused**: a role-less active member (`makeActiveMember` then `syncRoles([])`) is the only construction
  that reaches the gate. Copying the row's viewer-based fixture would have produced a 200 and a green test.
  ✅ **MEASURED RATHER THAN ARGUED — `route:list` resolves all three as `ability → Authorize → RequireFeature`.**
  The entitlement answers **last**, so every 403 added here is a token or permission refusal and never a plan
  one; both analytics suites already assign Business in `beforeEach`, which removes the ambiguity entirely.
  This was the increment's largest risk (a deny test that trips the entitlement measures the entitlement) and
  it was refuted by measurement rather than reasoned away.
  ✅ **PROVEN BY MUTATION, NOT BY GREEN.** Three mutations, each one literal token in a unique context, each
  `php -l`'d with its sha256 asserted moved, each restored by byte-comparison rather than `git checkout --`.
  Red sets **disjoint, one test each**: dropping `can:` from `analytics.export` reddened only the web role
  case; dropping `can:view,form` from the API xlsform route reddened only its scope case; dropping
  `ability:` from `analytics.report.export` reddened only its ability case — **and left the twin green**,
  which is the row's own thesis demonstrated from the other direction.
  ⚠️ **THE API PAIR'S STRUCTURAL COVERAGE IS WEAKER THAN THE ROW SAYS, AND THE FILE ADMITS IT.**
  `GroupBPolicyGateTest.php:97` is `explode(':', $middleware, 2)[0]` — everything after the first colon is
  discarded, so a gate naming a permission nobody holds and one naming the wrong subject are
  byte-indistinguishable from a correct one. Its own header at `:33` says it "cannot judge whether an
  allowlisted reason is TRUE".
  ✅ **NO LONGER TRUE AS OF `M63` (2026-09-02), and the citation is annotated rather than rewritten because
  the sentence was correct when written and is the reason the later row existed.** The parse moved to
  `tests/Pest.php` as `policyGates()`, which keeps the payload; `:97` in that file is now something else
  entirely, so read the claim as history and the code at its current home. A gate naming the wrong subject
  is caught by `GroupBGateSubjectTest`; one naming a permission nobody holds, by `DC5`. Two corrections to the row: it walks **Group B only** (`:114-115` filter out
  `api.v1.public.*` and `api.v1.tokens.*`), and it resolves the router alias map rather than matching the
  string `can:` — deliberately, per `:81-86`.
  ⚠️ **THE ROW SAYS "THREE" AND THE RESOURCE CENSUS SAYS SIXTEEN.** Re-running M29's census with the
  **resource** as its unit rather than the feature found **sixteen** byte-returning routes where the
  antecedent counted ten. Fifteen carry tenant data. The six never counted: `/forms/{form}/versions/{version}/print`,
  `/forms/{form}/share/qr.svg`, `/sso/saml/metadata`, `/f/{slug}/manifest.webmanifest`, `/sw.js`, and
  `GET /admin/feedback/{feedback}/screenshot` — the last of which is filed as its own row below.
  ⛔ **ONE CANDIDATE WAS ALREADY PASSING, AND CHECKING IT COST ONE `grep` AND SAVED AN INCREMENT.** The web
  twin `GET /forms/{form}/versions/{version}/xlsform` looked like a fourth uncovered route and is not:
  `XlsformExportTest.php:228` has driven it as a non-collaborating `form_editor` since G7a and asserts
  `assertForbidden()` at `:246`. The row omits it because it is **covered**, not because the row missed it.
  That is M20's *a character-identical declaration is not an identical defect*, holding for a fourth
  increment. What it DID lack was the **role** arm — a `viewer` fails `FormPolicy::canEdit`'s first clause
  where the `form_editor` fails its second — so that one test was added rather than the four assumed.
  **Nine tests: six for the row's three routes, one for the web twin's missing role arm, two for the 409 row
  below.** `AnalyticsPageGateTest:106` gains a comment saying in as many words that it asserts the
  entitlement and not the gate — the M29 precedent, because a test that *looks* like coverage is what filed
  this row. Filed by `M29`.
- ~~**`minor` · The `409` quarantine branch is asserted on no stored-file route in the repository.**~~
  ✅ **DONE — M34 (2026-08-27).** Three tests, both routes: `AttachmentPolicyTest` gets a `pending` and an
  `infected` case on `GET /attachments/{attachment}`, and `FeedbackTest` gets an `infected` case on
  `GET /feedback/{report}/screenshot`. Both controllers carry their own copy of the guard —
  `FeedbackController.php:59-65` says in its own words why it declines to route through
  `AttachmentController` — so asserting one proves nothing about the other and both are pinned.
  ⚠️ **THE ROW'S FIXTURE-COST CLAIM WAS EXACTLY RIGHT, WHICH IS WORTH RECORDING BECAUSE IT USUALLY IS NOT.**
  `AttachmentFactory` really does carry `pending()` (`:158`), `clean()` (`:163`) and `infected()` (`:173`),
  and M29's `memberWithStoredObject()` really does build a stored object with bytes on a fake disk — so the
  attachment half cost one `update()` call per case and no helper change.
  ⛔ **THE CASES USE AN OWNER DELIBERATELY, AND THAT IS THE WHOLE DESIGN OF THE TEST.** `abort_unless` runs
  *inside* the controller, i.e. **after** `can:view,attachment`, so a caller who fails the policy never
  reaches the guard and a 409 test written against a refused principal would pass on the 403 instead. Both
  cases therefore use the principal the policy admits, and their positive controls are the existing 200s on
  the same URI with the same fixture one column apart (`AttachmentPolicyTest:162`, `:184`; `FeedbackTest:256`).
  ⚠️ **`infected` AND `pending` ARE BOTH ASSERTED RATHER THAN ONE STANDING FOR THE OTHER.** `servable()` is
  `Clean || Skipped` (`ScanStatus.php:31`), so the two non-servable states are independent enum values; a
  test covering only `pending` stays green if `infected` is later added to the servable set.
  ⚠️ **CITATION DRIFT, THE FOURTH INCREMENT RUNNING, AND MINOR THIS TIME.** The row cites
  `app/Enums/ScanStatus.php:12` for the *"serving gate the threat model relies on"* docblock; it is at
  `:26-27`. `FeedbackController.php:75` and `AttachmentController.php:43` were both exact. Filed by `M29`.
- ~~**`minor` · `AttachmentController`'s docblock calls `GET /attachments/{attachment}` a "signed read-back", and nothing about it is signed.**~~
  ✅ **DONE — M34 (2026-08-27).** Citation exact: `app/Http/Controllers/Tenant/AttachmentController.php:20`.
  The word is struck, and the docblock now names the controls that **do** exist rather than leaving a hole
  where the false one was — because "strike the word" alone hands the next reader the same question with no
  answer, and a docblock is what they check *instead of* the middleware. The route is session auth, plus
  `can:view,attachment`, plus — since M33 — a policy that resolves the attachment's **kind and owner** and
  not merely a permission. Nothing anywhere signs it: the repository still contains exactly one signed URL
  (`User.php:146`, email verification) and no `temporaryUrl`, `ValidateSignature` or `hasValidSignature` in
  `app/` or `routes/`, re-verified this increment rather than carried over from the row. Filed by `M29`.
- ~~**`major` · `GET /admin/feedback/{feedback}/screenshot` streams cross-tenant PII from the central host and no test asserts a refusal on it.**~~
  ✅ **DONE — M35 (2026-08-27).** Every citation exact, the third row running: `routes/admin.php:83-84`,
  the index's three denials at `FeedbackConsoleTest.php:153`/`:158`/`:162`, and the screenshot's only case at
  `:338-348` whose own comment says the 404 proves the lookup resolved rather than that anyone was refused.
  ⛔ **THE ROW'S STATED WEAKNESS WAS THE PART WORTH BUILDING FOR, AND IT WAS MEASURED RATHER THAN ARGUED.**
  The realistic silent mutation it names is a route declared outside the group, so that mutation was run
  FIRST, against the unchanged tree: moving the screenshot route into the outer group — confirmed at the live
  route table to drop `superadmin.mfa` **and** `step-up` — left `tests/Feature/{Admin,Auth,Feedback}` at
  **238 passed / 1,156 assertions, identical to the baseline in both numbers.** Nothing in this repository
  noticed. So the fix is the route-table walk the row prescribes, as a new
  `tests/Feature/Admin/AdminConsoleGateTest.php`: the console is DISCOVERED from the live table by URI prefix
  and asserted to carry `auth` + `superadmin` everywhere, `superadmin.mfa` + `step-up` everywhere but one
  allowlisted route (the enrollment landing, with its reason written down), and the central-host domain
  constraint everywhere — plus a count floor, two discovery anchors, an allowlist-freshness case, and an
  alias-resolution case, because re-pointing a name at a permissive class leaves every forall green.
  ⛔ **THE SIBLING FINDING IS FIXED IN THE SAME PR AND IT WAS THE LARGER HALF.**
  `StepUpReauthenticationTest.php:135-146`'s hand-maintained manifest is now an ENUMERATION over
  `adminConsoleRoutes()`, so the comment claiming it covers "every page of the console" is true for the first
  time. It named three of the fourteen.
  ⚠️ **THE ROW'S OTHER SUGGESTION — three copied deny assertions — CARRIES A VACUITY TRAP IT DOES NOT NAME,
  AND THIS REPOSITORY HAD ALREADY PAID FOR IT ONCE.** `EnsureSuperAdmin` answers **404** for non-disclosure
  and the controller answers **404** for a report it cannot resolve: the same status from two different
  decisions. A non-super-admin case written against a random id — or against a report with no screenshot, the
  only fixture the console suite could build — **passes with the middleware deleted**.
  `tests/Feature/Tenant/FeedbackTest.php:307-309` states the identical trap on the tenant-side twin. So the
  404 arm runs against a report a super-admin really does get 200 image bytes from, which meant building the
  first committed-screenshot fixture the console suite has ever had (`consoleScreenshot()`); that positive
  control is itself the first test in this repository to drive this route to a success. **Proven, not
  assumed:** deleting `superadmin` from the group turns that case red *because* the request then reaches the
  controller and streams the bytes. The other three arms answer with a redirect, which no 404 can be confused
  with, and say so rather than paying for a fixture they do not need.
  ⛔ **AND ASKING THE KILLER QUESTION OF EVERY GATE ON THE ROUTE — NOT ONLY THE ONES THE ROW NAMES — FOUND A
  THIRD NAKED ONE.** `FeedbackConsoleController.php:78`'s `abort_unless(...->servable(), 409)` was asserted
  by **nothing**: M34 pinned that guard on the two routes it was looking at (`FeedbackController.php:75`,
  `AttachmentController.php:43`) and this console copy is the third, so quarantined bytes could be served to
  the one principal who reads across every tenant with the whole repository green. Covered here, with the
  streaming case one column apart as its positive control.
  ⚠️ **MEASURED COST, RECORDED SO IT IS NOT REDISCOVERED:** a web-route 409 assertion in this suite runs
  **~60–100 s** — the new console case is 59.4 s and the *pre-existing* M34 tenant-side twin is 97.5 s — so
  the cost is the error-page render and not this increment. `withoutVite()` is **not** the cause; adding it
  made the case slower (82 s). The pattern should not be multiplied casually. Filed by `M34`.
- ✅ **CLOSED BY `M67` (2026-09-03) — `minor` · ~~`GET /admin/users` — the cross-tenant user list — has exactly one test, and it is a 200.~~**
  Sixteen behavioural denials added, driven from a dataset over **all four** routes the row named — guest,
  authenticated non-super-admin (404, non-disclosure), super-admin without confirmed 2FA, and a lapsed
  password confirmation.
  ⛔ **THE PAIRING WAS PROVED, NOT ASSERTED, AND THE RESULT IS THE ROW'S WHOLE ARGUMENT.** Emptying
  `EnsureSuperAdmin`'s `abort_unless` reddens **six** cases in `SuperAdminConsoleTest` and **zero** in
  `AdminConsoleGateTest`, which stays fully green with the production gate gone. That is `M43`'s lesson
  measured on this pair: a structural gate cannot see a middleware that stops refusing.
  ⚠️ **MEASURED COST, AGAINST `M34`'S WARNING ON THIS FILE:** sixteen cases add **~4 s** (15.7 s → 20.0 s),
  not the 60–100 s an error-page render costs here. The expensive shape is the rendered error page; a
  middleware refusal never reaches one.
  ⛔ **AND TWO OF THESE CASES PASSED FOR THE WRONG REASON IN THEIR FIRST DRAFT.** With a literal uuid the
  two model-bound tenant routes 404 from `SubstituteBindings` — which is NAMED in the priority array while
  `superadmin` is not — so the refusal came from binding rather than from the gate under test, and the two
  404s are indistinguishable. Caught by printing the status and `Location` per route. The fixture is a real
  committed tenant now; the general hazard is filed as its own row below.
  ⚠️ **The row's citation had drifted** (`M66` grew this file), and a repo-wide grep for the URI returns
  zero hits because the request is built through a closure — which is how the gap survived a census.
  Filed by `M35`.

- **`minor` · Route-model binding resolves BEFORE the three console gates, so a synthetic id 404s from the
  binding rather than from the middleware a test names.**
  Filed 2026-09-03 by `M67`, which hit it while writing the row above. `SubstituteBindings` is named in
  `bootstrap/app.php`'s `$middleware->priority([...])` array; `superadmin`, `superadmin.mfa` and `step-up`
  are not, and `SortedMiddleware` hoists listed classes past unlisted ones — so on any console route with a
  bound `{tenant}`, binding runs first. **Not a production defect**, and that was checked rather than
  assumed: the binding 404 and the gate 404 are the same status and the same non-disclosure answer, and
  `auth` sorts ahead of both, so a guest is still redirected. What it is, is a **test-construction hazard
  that hides itself** — a denial case written with an arbitrary uuid is green whether the gate refuses or
  not. **Latent**: no test in the tree is currently affected (`SuperAdminConsoleTest`'s malformed-id case
  refuses at the route constraint, and its suspend case uses a real tenant), so the precondition is *the
  next denial case written against a model-bound console route with a synthetic id*. The durable fix is
  probably naming the three console aliases in the priority array so declared order is resolved order,
  which is a change to the console's middleware pipeline and wants its own increment. Filed by `M67`.


- ✅ **CLOSED BY `M63` (2026-09-02) — `minor` · ~~The `can:` arm on `GET /api/v1/analytics/report` — the non-export twin — is asserted by nothing.~~**
  Three cases added to `AnalyticsApiTest`, which until now had **no policy-refusal case at all** — its only
  two `error.code` assertions were `insufficient_ability` and `feature_not_available`. The first is M34's
  export fixture aimed at this route (403 + `forbidden`, the code being what proves the ability gate PASSED
  and the policy refused). ➕ **The other two are a different species and are the reason this row closed
  alongside the wrong-subject one:** a principal holding exactly `submissions.view` must be **refused**, and
  one holding only `dashboard.form.view` must be **served** — 200 under the wrong subject, 403 under the
  right one. The second is also the positive control that the refusals are the gate rather than a broken
  fixture, and the only assertion anywhere pinning `viewAny()`'s **second disjunct**: every other analytics
  case uses a Viewer or an Owner, who both hold `dashboard.org.view` as well.
  ⚠️ **Three separate `it()`s, not one**, per this file's own recorded trap: the auth guard caches its
  resolved user for the app instance, so a second `withToken()` in one test keeps acting as the first.
  **THE ROW AS FILED FOLLOWS, KEPT VERBATIM.**
  The mirror image of the row M34 closed, and found while closing it. `AnalyticsApiTest.php:87` pins the twin's
  **ability** arm and `:99` pins a Viewer's intended 200, but nothing anywhere drives that route with a caller
  who carries `read:analytics` and fails `can:viewAny,SavedReportView`. Delete the `can:` middleware from
  `routes/api.php:366` and the suite stays green. **Latent, and cheap**: M34 added exactly this test to the
  export twin (`AnalyticsExportTest.php`), so the fixture — an active member with `syncRoles([])` holding a
  correctly-scoped token — can be copied one file over. ⚠️ **The reason it is not folded in here is the
  M20 rule read forwards**: it is a different route, and this row's whole thesis is that a test aimed at the
  twin is not coverage of its sibling. Fixing the sibling by aiming at the twin would repeat the defect. Filed by `M34`.

- ✅ **CLOSED BY `M63` (2026-09-02) — `minor` · ~~Three saved-view verbs are gated by an entitlement assertion that no permission test backs.~~**
  Three permission arms added at the foot of `AnalyticsPageGateTest`, and the M34 comment that said these
  were unpinned is corrected in the same diff rather than left to rot.
  ⛔ **EVERY NEW CASE RUNS ON BUSINESS, AND THAT IS THE WHOLE DESIGN.** On Professional the `feature:` gate
  redirects first, so a permission failure could never be observed — the refusal would arrive from the wrong
  middleware and the test would pass for the wrong reason. Business is what makes a 403 mean *"the policy
  refused"*, and it is the difference between this row and the case it was filed against.
  ➕ **The PATCH/DELETE case builds the view FOR the caller on purpose.** `update()`/`delete()` are
  `owns($view) && viewAny()`; a view belonging to somebody else would 403 on the **ownership** half and
  prove nothing about the permission half — which `SavedReportViewWebTest` already pins from that side.
  Owned by the caller, ownership passes and the permission is the only thing left to refuse. The read-back
  afterwards is not ceremony: a 403 that had nonetheless mutated the row would be the worse defect.
  ⚠️ **The row said three verbs; the case it cites drives FOUR routes** — `GET /analytics/export` as well —
  and carries a `:137` assertion that neither verb reached the service. The export's own permission arm was
  already pinned elsewhere, so three is the right count for this row and four is the right count for that
  case.
  **THE ROW AS FILED FOLLOWS, KEPT VERBATIM.**
  `AnalyticsPageGateTest.php:106` drives `POST /analytics/views`, `PATCH /analytics/views/{view}` and
  `DELETE /analytics/views/{view}` as an **Owner** on a Professional plan and asserts a redirect from each —
  the `feature:` refusal, exactly the shape that filed the export row. M34 added a comment there saying so in
  as many words, and pinned the export's gate, but left these three: they are writes rather than streamed
  bytes, so they fall outside a stored-bytes census and belong with whoever takes the analytics write surface.
  **Latent.** Filed by `M34`.

- ✅ **CLOSED BY `M63` (2026-09-02) — `minor` · ~~A `can:` gate that names the WRONG SUBJECT is invisible to every test in the repository, including the one written to catch it.~~**
  Closed with a **new species of assertion for this repo**, designed once across the surface exactly as the
  row asked rather than bolted onto the route under repair. `tests/Pest.php` gains `policyGates()`, which
  keeps the payload the old helper discarded and returns a **list** (two `routes/tenant.php` routes carry
  two `can:` middlewares at once, and a helper answering with one of them would silently pick a winner);
  `GroupBPolicyGateTest` gains six derived coherence checks; and a new `GroupBGateSubjectTest` declares the
  **audience** of each eligible gate and computes the actual one from the live policy.
  ⛔ **THE ROW'S EVIDENCE HELD AT EVERY CITATION AND ITS LITERAL MUTATION WOULD HAVE PROVED THE WRONG
  THING.** `Submission` is **not** in `routes/api.php`'s `use App\Models\…` block, so `Submission::class`
  written there resolves to the global `\Submission`, which does not exist — `Gate::getPolicyFor()` returns
  null and the route 403s **everyone**, a different defect that the existing 200s already catch. The
  mutation has to spell the FQCN literally. Same family as M49's shell-eaten `$`, arriving through a
  namespace instead.
  ⛔ **AND THE OBVIOUS STRUCTURAL REMEDY WAS REJECTED AS THE WEAK ARTEFACT.** A route-name →
  middleware-string manifest mirrors the routes file: edit both identically and it asserts nothing. What
  ships declares the **permission keys that open the route** and computes the real set by granting one key
  at a time, so the swap makes the declaration false **in words** — it cannot be kept true by a matching
  edit. It is also a whole-catalog statement, which no behavioural arm is without 29 requests per route.
  ⚠️ **THE ROW'S RECIPE WAS RIGHT AND INCOMPLETE, AND THE MISSING HALF IS WHY THE DEFECT SURVIVED.**
  *"Grant exactly `submissions.view` and neither dashboard key"* cannot be done with a seeded role:
  **measured against `RolePermissionSeeder::MATRIX`, all five roles holding `submissions.view` — owner,
  admin, form_editor, reviewer, viewer — also hold at least `dashboard.form.view`.** It needs a direct
  permission grant (`memberHoldingOnly()`), whose no-synthetic-role discipline is inherited from
  `FormVisibilityScopeTest`'s committed-role leak.
  ➕ **THE SHARPEST CHECK IS ONE THE ROW DOES NOT MENTION, AND IT CAME FROM READING THE INSTALLED VENDOR
  CODE.** `Authorize::isClassName()` is `str_contains($value, '\\')` and `getModel()` returns
  `$request->route($model, null) ?? null`, so **a subject that is not a declared route parameter authorizes
  against `[null]` and silently refuses every principal, forever.** DC3 is that check. All six derived
  checks pass on today's table, so this closes with **no production finding** — which is itself worth
  recording, because it was the outcome the plan said to stop on.
  ⚠️ **STATED LIMIT, in the M20 discipline:** none of DC1–DC6 can see this row's own defect. A
  well-formed gate aimed at the wrong audience passes every one of them identically, which is precisely why
  the declared-audience half exists beside them and why neither is sufficient alone.
  ⚠️ **Scoped to Group B's 51 gates. `routes/tenant.php`'s ~95 are filed below rather than swept in.**
  **THE ROW AS FILED FOLLOWS, KEPT VERBATIM** — its recipe is what made the fixture correct.
  Found by M34's adversarial pass over its own committed diff — the mutation nobody thinks to try, because it
  changes no behaviour for any principal a test happens to use. Swap `can:viewAny,SavedReportView::class` for
  `can:viewAny,Submission::class` at `routes/api.php:369` (**the alternative `routes/api.php:359-362` says in
  its own comment was considered and rejected**) and **every test stays green**: `GroupBPolicyGateTest`
  resolves only that the middleware IS `Authorize::class` (`:97` discards everything after the first colon),
  and M34's two new deny cases both use a role-less member who holds `submissions.view` no more than the
  dashboard keys, so both gates refuse them identically. **The rejection is defended by a prose comment and
  nothing executable.** ⚠️ **It is closeable, and the recipe is the finding**: grant a constructed principal
  **exactly `submissions.view` and neither dashboard key** — they must be **200 under the wrong subject and
  403 under the right one**, which is the only fixture that can tell two gates apart. **Deliberately NOT
  folded into M34**: an assertion about *which permission a gate names* is a new species for this repo, and it
  wants designing once across the whole Group-B surface — where it would strengthen
  `GroupBPolicyGateTest` from "a gate is present" to "the gate names the subject its route intends" — rather
  than being bolted onto the one route that happened to be under repair. **Latent.** Filed by `M34`.

- **`minor` · `routes/tenant.php`'s ~95 `can:` gates get none of M63's checks, and the derived half is expected to FIND something.** Filed by **M63 (2026-09-02)** at the moment the scope was set, because a
  deliberately-unfixed finding that lives only in a commit message is invisible to any later backlog search.
  M63 built `policyGates()` and DC1–DC6 over Group B's 51 gates; the web session surface has roughly twice
  as many and **nothing has ever parsed one of them**. ⛔ **This is not a tidiness row.** A DC3 failure is a
  subject that is not a declared route parameter, which `Authorize::getModel()` resolves to `null` — the
  route then refuses **every** principal, silently and permanently; a DC5 failure is the same outcome by a
  typo'd permission key. Group B came back clean, and that is evidence about Group B only.
  **The derived half is a pure extension** — pass a second enumerator to the same `policyGates()` — and is
  the cheap, valuable part. ⚠️ **The declared-audience half is the expensive one and should not be
  rubber-stamped:** ~66 intent decisions (~19 class-subject, ~26 bare) against M63's 21, and it is the
  first place the two payload shapes Group B does not contain become live — the three-part
  `'can:create,'.Submission::class.',form'` (5 routes) and two `can:` middlewares on one route (2 routes).
  M63 measured those shape counts and reviewed none of them. **Whoever takes it should split it: land the
  derived checks first and decide each finding on its own, then take the manifest as its own increment.** Filed by `M63`. **Not live** — a missing gate rather than a defect, which is this corpus's own not-live shape, judged by `M65`.

- ✅ **CLOSED BY `M64` (2026-09-02) — `minor` · ~~`D5`'s exit bar reads MET but is still not OPERABLE on its own terms, and the gap is provenance.~~** Filed by **M63 (2026-09-02)**, measured rather than asserted, and **carrying a user decision of
  record taken the same day: keep going and make the bar real first.** `state.php` counts **zero open
  `major`**, and no `major` bullet in this file is attributable to `M59`, `M60`, `M61` or `M62` — the
  highest filer that records itself is `M49` — so both of `D5`'s clauses read satisfied, four consecutive
  increments against a bar of three. ⛔ **`D5` set its own precondition and it is half-built:** *"provenance
  normalised to one parseable form across `docs/feature-backlog.md`, with a lint gate holding it there."*
  `state.php` now derives provenance, which is the reading half — but **47 of the 58 bullets carrying the
  `major` marker record no filer in any form the parser recognises**, and 45 of the open rows record none
  either. There is **no gate**, so nothing stops the next row from being filed without one.
  ⚠️ **This is `D5`'s own warning about itself coming true**: *"a bar that cannot be measured is a bar that
  will be declared met by whoever wants to stop."* The measurement above is a floor built on the 11
  attributable bullets plus the absence of a contrary one — good enough to report, **not** good enough to
  end a series on. What lands here: one parseable provenance form, backfilled across every bullet that has
  a knowable filer, `(unattributed)` written explicitly where it does not; a lint gate refusing a new row
  without one; and `state.php` reporting the two clauses directly so the exit is read off the tree rather
  than argued. ➕ **Do the same for liveness while the file is open** — only 24 of the rows carry a
  liveness marker, which is the sibling blind spot `M55` filed. Filed by `M63`.
  ✅ **DONE — `M64` (2026-09-02).** One canonical form, `` Filed by `<increment>` ``, on **all 161**
  severity bullets; `state.php` derives both of `D5`'s clauses and prints them; `loop.php status` reads
  them instead of recomputing; and `tests/Feature/Docs/BacklogProvenanceTest.php` holds the form.
  ⛔ **THE ROW'S SCOPE WAS WRONG AND THE CORRECTION IS THE FINDING.** It is written as though the work
  were about *rows*. `D5`'s second clause asks which increment **filed** each `major`, and **45 of the
  55 `major` bullets are closed** — a `major` filed and closed inside one increment was still filed by
  it. `state.php`'s parser could not see a closed bullet at all, which is why `total_bullets` read 185
  while `open` read 84: **77 bullets existed nowhere.** The scope is 161 bullets, not 84 rows.
  ⚠️ **Its counts were wrong in both directions** — it says 47 of 58 `major` and 45 open rows; the tree
  said **45 of 55** and 41–42 of 84. And only **5** of the 161 carried the strict form against **54**
  carrying some free-text filer, which is the fifteen-shapes problem measured rather than asserted.
  ⛔ **The loose parser was actively mis-attributing, which is why one form matters more than the row
  argues.** The maintenance-fan-out row quotes the row it superseded under a *"THE ROW AS FILED
  FOLLOWS"* heading, so `state.php` read `M32` out of a **quotation** while the row's own first
  paragraph says `M44` filed it. Backticks are what separate a record from prose about a filing.
  ⛔ **NO LINE WAS ADDED, and that was not caution.** 21 line-number citations point into this file
  from 8 others — 9 in `PROGRESS_ARCHIVE.md`, which is never rewritten, and 4 in `lane-b.md`, which is
  never edited — the highest at line 2297. **74 of the 156 lines the backfill changed sit above it**,
  so an insert would have rotted every one, invisibly: `citation-liveness-lint` checks a line is alive,
  not that it still says what the citing sentence claims. Conservation was proved three ways.
  ⚠️ **The `lint gate` half was deliberately changed to a Pest test, with the user's confirmation** —
  `scripts/mutate.php` drives Pest and nothing else, so a lint sibling would hand-roll its discipline
  and add a CI step. The phrase *"lint gate"* is Lane A's own from `M36`, not the user's answer to `D5`.
  ➕ **The liveness half is filed rather than done, and the reason is in the row below.**

- ✅ **CLOSED BY `M65` (2026-09-03) — `minor` · ~~31 of the 84 open rows say nothing about whether they are still live, and the marker is
  reported rather than gated.~~** `M64` normalised provenance and could not normalise this in the same
  pass, so it is filed the moment that was decided rather than left in a commit message. `state.php`
  now counts the marker — **live 39 · latent 4 · not-live 10 · UNMARKED 31** — and
  `tests/Feature/Docs/BacklogProvenanceTest.php` deliberately does **not** require it.
  ⛔ **THE REASON IS THAT LIVENESS IS NOT A TEXT EDIT AND PROVENANCE WAS.** A filer is a fact recorded
  in git: `M64` resolved all 161 against 135 historical versions of this file mechanically. *Is this
  defect still live* is a judgement against the code, one row at a time — it is the `M37` triage job,
  six read-only passes, and `M37`'s own finding was that **65 of 68** rows were still live, so the
  answer is not cheap and is not guessable. Gating a marker nobody has decided would make it a
  formality, which is the decorative-gate mistake `M43` measured.
  ⚠️ **`M55` filed the sibling of this and its wording under-counts today**: it says *"only 24 of 78
  rows carry a liveness marker"*, measured against `**Not live**` and `**Live**` alone. Counting
  `**Latent.**` and the trailing-period forms puts it at **53 of 84**. Re-derive before working it.
  ⚠️ **And `loop assess` is the thing that pays for this**, since silence deliberately does not stop
  it — that is `M55`'s stated limit, and it is a floor rather than a hole precisely because 31 rows
  are silent. **Live.** Filed by `M64`.

- ✅ **CLOSED BY `M65` (2026-09-03) — `minor` · ~~`docs/backlog-triage.md` ranks the queue by a census that is now 107 commits stale, and
  its top three items are all closed.~~** Read at source rather than taken on report: its *"Priority
  queue — what to take next"* opens with three `major` items — the unthrottled Fortify endpoints, the
  four maintenance fan-outs, and five documentation-truth rows — and `state.php` counts **zero open
  `major`**. Anyone following `CLAUDE.md`'s instruction to *"read `docs/backlog-triage.md` first for
  the ranked order"* is handed a ranking whose first three entries no longer exist.
  ⚠️ **The file says of itself that its counts are a dated census and not the tree, and `state.php`
  prints how stale it is on every run**, so this is a stale ranking rather than a false claim — which
  is why it is `minor`. But the staleness signal names commits, not usefulness: nothing says *the
  ranking is spent*, and the number a reader needs is how many of its ranked items are still open.
  ⛔ **DELIBERATELY NOT FIXED IN `M64` AND FILED THE MOMENT THAT WAS DECIDED.** Re-ranking 84 rows is
  a triage pass, not an edit, and it is the same judgement the liveness row above needs — the two
  should be taken together, by whoever takes either. **Live.** Filed by `M64`.

- **`minor` · `scripts/backlog-triage.php --check` is wired into nothing, so the generated triage can drift
  with no gate saying so.** **`M65` decided against wiring it and recorded that the moment it was decided.**
  The generator has a `--check` mode that regenerates into memory and compares the derived body against
  disk, and it was proved both ways rather than asserted — clean on a fresh generation, exit 1 after one
  hand edit to the census, clean again after a byte-comparison restore. ⛔ **What it lacks is a caller.** A
  `scripts/*-lint.php` sibling needs a `composer.json` alias, an entry in the `quality` aggregate and its
  own `ci.yml` step, because no CI job runs the `quality` aggregate — and `ci.yml` is the user's. A Pest arm
  under `tests/Feature/` would need no CI step, but it would have to shell out to `git` from inside the app
  container to resolve the trunk sha. ⚠️ **And wiring it changes what a close-out is OBLIGED to do**, since
  the file goes stale by construction on every merge that touches a row: that is a decision about protocol
  rather than a fix, which is why it is filed instead of taken. **Live** — the drift is reachable the moment
  anyone edits the file by hand or closes a row without regenerating. Filed by `M65`.

- **`minor` · `docs/backlog-triage.md` keeps a tier-1 citation exemption whose stated reason stopped being
  true in the same increment.** **`M65` falsified the reason and did not act on it.**
  `scripts/citation-liveness-lint.php` excludes the file as *"a point-in-time census whose whole value is
  that it records what was true on the day it was measured"* — exactly right of a hand-written census, and
  wrong of a generated one, whose citations are repaired by regenerating rather than destroyed by it. On the
  merits it is now the ideal tier-1 candidate rather than an exemption. ⛔ **IT WAS NOT PROMOTED AND THE
  REASON IS ARITHMETIC:** the ledger tier sits at 18 rotten against a ceiling of 18 with a strict `>`, so
  harvesting a second file's citations with zero headroom risks reddening the gate on a change that fixes
  nothing. Promoting it wants the ceiling brought down first, which is its own row's work. **Not live** — an
  exemption kept for a superseded reason is a stale comment rather than a defect in the gate. Filed by `M65`.

- **`minor` · The liveness marker is gated for presence and nothing checks that a verdict is CORRECT — and
  the error rate of judging one is now measured rather than assumed.** **`M65` produced the backfill and
  measured this while producing it.** `tests/Feature/Docs/BacklogProvenanceTest.php` requires exactly one
  verdict on every open row and says at the site that it never checks the verdict is right; `scripts/state.php`
  and `scripts/loop.php` both consume the marker and neither can either. ⛔ **THE NUMBER IS THE POINT: 5 of
  the 30 verdicts the read-only fan-out returned were changed by hand before any of them was written.**
  Three of the five moved because the judging and refuting passes had split *systematically* — agreeing on
  every fact and disagreeing on whether a reachable mechanism somebody declined to fix is live — and two
  more moved on the corpus's own established usage of the words. A sweep written straight from agent output
  would have recorded five wrong markers, and a wrong `Not live` is the expensive direction, because
  `loop assess` then refuses that row permanently and silently. ⚠️ **So the marker is a floor for scheduling
  and must never be read as a verified fact about the code**; `scripts/mutate.php` is what settles a row,
  and settling it is the job of whichever increment takes it. **Not live** — a stated limit of the gate,
  filed so the next reader does not have to rediscover it. Filed by `M65`.

- ✅ **CLOSED BY `M67` (2026-09-03) — `minor` · ~~`routes/api.php:114-116` describes a middleware ordering the priority sorter does not produce.~~**
  The claim is struck and the real order is now asserted, by execution rather than by reading:
  `TenancyMiddlewarePriorityTest` sorts the RESOLVED stack for `api.v1.submissions.promote` and requires
  `ThrottleRequests` ahead of `RequireFeature`.
  ⛔ **THE ROW POSED A DECISION AND IT HAS ONE DEFENSIBLE ANSWER, so `M67` took it rather than filing it.**
  Making the comment true means hoisting the feature gate ABOVE the limiter, which puts a
  tenancy-resolving, database-backed lookup in front of it — an unauthenticated flood would then pay for
  that lookup before being limited. `bootstrap/app.php`'s own `ThrottleFortifyEndpoints` entry already
  argues the principle (`M43`): what a limiter's slot buys is BOUNDING THE WORK. The claim was the defect.
  ⚠️ **THE ROW'S CITATION HAD DRIFTED** — the comment had moved down the file — which is why it is not
  re-cited by line here. ✅ **Checked for siblings and there are none**: the only other occurrences of the
  phrase are quotations of it (this row, `lane-b.md`'s historical record, and the new test's rationale).
  ⚠️ Proved by mutation: un-listing `ThrottleRequests` from the priority array reddens the new arm and
  leaves the four pre-existing ones green.

  ⓘ The original row, kept for its evidence and for the filer of record below:
  Re-read at source rather than taken from the report: the comment states that `feature:api_access` runs *"before throttle so a no-feature tenant is refused before
  consuming a burst slot"*. **Measured with `route:list`, which prints the SORTED list: `ThrottleRequests:api`
  is hoisted to FIRST**, ahead of tenancy, auth and the feature gate — so a no-feature tenant **does** consume
  a burst slot, and the stated protection does not exist. Same species as the *"signed read-back"* docblock
  M34 struck: **a comment describing a control that is not there is worse than no comment, because it is what
  the next reader checks instead of the middleware.** Harmless today (the slot is a rate-limit bucket, not
  data), so **documentation defect, not a behaviour one** — but the fix is a decision rather than an edit:
  either strike the claim, or hoist `api_access` into the priority list so the comment becomes true. Filed by `M34`. **Live** — the comment still describes an ordering the priority sorter does not produce, and it is what the next reader checks instead of the middleware, judged by `M65`.

- ➡️ **MOVED TO `docs/claims/decisions.md` AS `D11` (2026-09-02, by `M63`) — IT IS A DECISION, NOT A DEFECT, AND NO LANE SHOULD TAKE IT AS A ROW.** Both candidate fixes change **who can do something**, which is a product call; and `M63`'s claim was that it added the first executable assertion about which permission a gate names, so changing a gate inside that diff would have made its own mutation matrix ambiguous about which half caught what. The recommendation on file is **A — leave both and pin the intent** in the `routes/tenant.php` grant manifest when that row is taken.
  ⛔ **THE ROW'S OWN CITATION IS WRONG AND IT CHANGES THE ARGUMENT:** the PDF route is **`POST`**, not
  `GET` — deliberately, because it has side effects (an audit row, a metered export, a queued job). A gate
  on a side-effecting write is a different question from a gate on a read, and the row reasoned about the
  second. This is the third increment running in which verifying a row's **premise** — not merely its
  evidence and its remedy — was where the value was.
  ⚠️ **THIS CONVERSION DELIBERATELY DOES NOT FOLLOW `D1`'s PRECEDENT, AND THE DEPARTURE IS THE POINT.**
  `D1` kept the original bullet in its `- **`minor` · …**` form beneath the moved-to line, so `state.php`
  still counts it as an open row — the exact miscount `D5` records as making its first clause
  *"not cleanly countable"*. Here the severity token is spent on the moved-to line instead and the original
  reasoning is kept below it verbatim, which loses nothing a reader wanted and stops inflating the census.
  **THE ROW AS FILED FOLLOWS, KEPT VERBATIM** — its reasoning is intact and its caution was correct.
  Surfaced by M34's resource census and **not verified beyond the route declaration** — treat each as a lead,
  not a finding, and re-read the file before acting. `GET /submissions/{submission}/pdf` gates `can:view`
  where its sibling export route uses `can:export`, and the route's own comment flags the asymmetry.
  `GET /forms/{form}/share/qr.svg` gates `can:update,form` — an **edit** permission to read a QR code of an
  already-public URL, which is a gate that is too tight rather than too loose, but is still a gate naming a
  subject nobody chose deliberately. Both have deny tests (`GeneratePdfJobTest.php:393`,
  `FormShareTest.php:364`), so neither is a coverage hole; the question is whether the gates name the right
  permission.

- ~~**`major` · The queued half of `gamification:backfill` is asserted by job count alone.**~~
  ✅ **DONE — M32 (2026-08-28), PR #225.** Two test files, no production change. The defect was **measured
  before a line of test was written**, which is the only reason this row closed correctly: **the fix the row
  prescribes below does not work.**
  ⛔ **`Queue::assertPushed($class, $closure)` IS AT-LEAST-ONE-MATCH.** Read from the vendor source for the
  version installed (Laravel 13.18.1, `QueueFake.php:130-134`): the closure form asserts
  `pushed($job, $callback)->count() > 0`. A single closure asking *"is this job's tenantId one of the two?"*
  is satisfied by the **first of two identical jobs** and stays green under precisely the hoist mutation it
  would have been added to catch. `assertPushed($job, 2)` routes to `assertPushedTimes` (`:122-124`) — a pure
  count that never reads a payload.
  ✅ **WHAT WORKS INSTEAD:** `Queue::pushed($job)` returns the job **objects** (`:364-375`, `->pluck('job')`),
  and `Bus::dispatched()` the same (`BusFake.php:564-573`). Comparing the whole pushed set as sorted
  `[tenantId, afterAuditId, limit]` tuples pins the multiset in **both** directions — nothing missing,
  duplicated, or extra — in one assertion, and subsumes the count. The existing count assertions were **kept**
  regardless: a deleted loop was the one mutation they already caught.
  **MEASURED, each mutation printing the line it changed and aborting if the substitution did not land, and
  restoring by saved bytes with a sha256 check** (`tests/Feature/Gamification` baseline **134 / 479**):

  | Mutation | Before | After |
  |---|---|---|
  | hoist the loop variable — every child gets `$targets[0]` | **SURVIVED**, 8 passed / 19, exit 0 | CAUGHT |
  | non-null `afterAuditId` on the fan-out | **SURVIVED** | CAUGHT |
  | `--tenant` resolves to the wrong workspace | **SURVIVED** | CAUGHT |
  | the same hoist in `RefreshConnectorTokensJob::sweep()` | **SURVIVED**, 9 passed / 38, exit 0 | CAUGHT |
  | delete the loop entirely | CAUGHT | CAUGHT (not weakened) |

  ⚠️ **THE SECOND INSTANCE WAS THE SHARPER ONE, AND THE ROW DID NOT NAME IT.**
  `tests/Feature/Connectors/ConnectorTokenRefreshTest.php:188` is the **only place in the repository where
  `RefreshConnectorTokensJob::sweep()`'s loop executes at all** — the file's own `runRefreshSweep()` helper
  (`:74-89`) dispatches the **child** directly, so its other seven tests never reach the parent. Its sole
  assertion was `Bus::assertDispatchedTimes(…, 2)`, and unlike the backfill it has no `--sync` sibling proving
  a usable id ever reaches the child. The failure also **recurs hourly** rather than once: every non-first
  tenant's OAuth grants simply expire at their own TTL, with no failed job and no log line. Fixed in the same
  PR, plus the second half of that test's own name — *"holds no tenant context itself"* — which it had never
  asserted.
  ⚠️ **AND A SILENT MUTATION CLASS THE ROW'S OWN CENSUS MISSED:** a well-formed uuid that is **no tenant at
  all**. `TenantAwareJob`'s guard (`:280-298`) is shape-only, so the job finds no row and **deletes itself**
  with an `info` log — silent in production *and* in the suite, with **zero** workspaces backfilled. The
  census's phrase *"aimed at the wrong workspace"* does not cover it. The set-equality assertion catches it,
  because it compares against the real ids.
  ⛔ **CORRECTION TO ITEM (2) BELOW — "the blast radius is six sites" IS RIGHT, "five siblings have zero
  coverage" IS NOT.** Verified first-hand rather than from the census: `RefreshConnectorTokensJob` **does**
  have a dedicated two-tenant fan-out test, and it is count-only — the row's own defect, in a second command.
  The other four assert real per-tenant effects on a real `database` queue, which is the stronger idiom. The
  honest statement is **"two of the six are asserted by count; the other four are asserted by a fixture too
  small to tell the difference"** — filed as its own row below.
  `tests/Feature/Gamification/BackfillCommandTest.php:80-92` — `Queue::assertPushed(…, 2)` inspects no
  payload, and the queued loop (`BackfillGamificationCommand.php:119`) is the production default while only
  the `--sync` loop (`:142`) is proven to pass a usable id. Dispatch the slug instead of the key, or hoist
  the loop variable, and every workspace's historical points and badges are permanently absent — the
  backfill is a one-shot operator action nobody re-runs — while the operator is told "2 workspace(s)
  queued". **Latent.** Fix is a closure on `assertPushed`.
  ⚠️ **SCOUTED BY M29's CENSUS (2026-08-26) AND NOT FIXED — READ THIS BEFORE PLANNING AGAINST THE ROW.**
  M29 verified this row read-only while taking its neighbour, and the row's **headline holds with its line
  numbers exact and landing on code**. Three corrections and three additions follow. ⛔ **THEY ARE SUBAGENT
  CENSUS OUTPUT, ADVERSARIALLY RE-VERIFIED BUT NOT OPENED BY THE INCREMENT'S OWN AUTHOR — RE-CHECK EACH
  file:line BEFORE ACTING, exactly as this project requires of a row.**
  **(1) THE "DISPATCH THE SLUG" MUTATION IS LOUD, NOT SILENT, ON BOTH PATHS.** `TenantAwareJob.php:295-296`
  regex-checks the uuid shape and throws `InvalidJobPayloadException` as the first statement of `handle()`,
  so a slug fails every queued job by name; and on the inline path `TenantContext.php:215` binds the value
  straight into `set_config` with no validation, so the RLS policy's `current_setting(...)::uuid` cast
  raises Postgres **22P02**. **The only genuinely SILENT identity mutation left in the command is a
  well-formed uuid aimed at the wrong workspace** — i.e. hoisting the loop variable. That is what the
  count-only `assertPushed` cannot see, and it narrows the row rather than weakening it.
  **(2) THE BLAST RADIUS IS SIX SITES, NOT ONE.** Five sibling maintenance fan-outs use the byte-identical
  dispatch expression and have **zero** `assertPushed` coverage of any kind:
  `SweepWebhookRetriesJob.php:26` · `SweepScheduledFormsJob.php:30` · `RollUpUsageCountersJob.php:27` ·
  `RefreshConnectorTokensJob.php:26` · `ReapExpiredDraftsJob.php:25`.
  **(3) THE PROPOSED FIX IS NOT THIS REPOSITORY'S IDIOM.** `UsageRollupTest.php:28`/`:40` and
  `DraftReaperTest.php:38`/`:69` cover a fan-out by enqueueing on the real `database` driver, draining, and
  asserting **per-tenant effects** — neither calls `Queue::fake` at all. `BackfillCommandTest.php:84` is the
  only `Queue::fake` fan-out test in the repo. Asserting effects proves identity **and** the chain at once,
  which a closure on `assertPushed` does not.
  ➕ **THREE FURTHER UNCOVERED THINGS IN THE SAME COMMAND.** A non-null `afterAuditId` on the first dispatch
  silently skips **every membership award** (`ReplayTenantHistoryJob.php:89-90`; asserted only on the
  `--sync` path and on a direct dispatch, never on the fan-out). The `--tenant` path asserts only a count of
  **1** while **two** tenants exist (`BackfillCommandTest.php:113-114`), so a wrong `TenantLocator::find`
  resolution passes — and that one has a live resolver behind it, which makes it the higher-probability
  mutation. And `runInline` returns `self::FAILURE` **after** `DB::commit()` has already run per workspace
  (`:179-182` against `:224`), naming no workspace — while the job side decided the opposite for the
  identical invariant.
  ✅ **AND ONE THING THE ROW IMPLIES THAT IS PROVABLY HARMLESS**: a corrupted non-positive `limit` does not
  loop forever — `GamificationBackfill.php:245` returns a null cursor when `count($rows) < $limit` is false,
  which `0 < 0` makes it, so the chain terminates. Of the three payload fields, only `tenantId` and
  `afterAuditId` carry real uncovered risk. Filed by `M1`.
- ~~**`major` · Four maintenance fan-outs are asserted by a fixture too small to see a wrong tenant id.**~~
  ✅ **DONE — M44 (2026-08-29).** Two new cases per file, **eight in total, and no existing case or
  `beforeEach` was modified**: the second tenant lives inside each new case only. Widening the shared
  fixture would have left every existing case draining just the first of two children and **still
  passing** — because `lazyById()` enumerates by UUIDv7, i.e. creation order, so the child that gets
  worked is always the first tenant's. That is the M31 hazard producing a green suite with its new
  coverage silently deleted, and it is why the row's own warning was followed to the letter.
  **PROVEN BY MUTATION IN BOTH DIRECTIONS, ON THE COMMIT THAT PRECEDED THE TESTS.** The hoist
  (`$first ??= $tenant;` — silent, and the dispatch count unchanged) read **SURVIVED ×4** before and
  **CAUGHT ×4** after, each time reddening exactly the two new cases and leaving all fourteen existing
  ones green. ⚠️ The BEFORE result is a **proof rather than a measurement** and is reported as one: with
  one active tenant the mutant is *semantically identical* to the original, so it cannot fail. Its value
  is narrower — it proves the token matched, the sha256 moved, the mutant parsed and the harness ran.
  ⛔ **`->first()` ON `activeTenants()` WOULD HAVE FATALLED** — it returns a `Generator` — and M32 already
  paid for that: *a control that dies loudly proves nothing about a silent defect*. `iterator_to_array()`
  is unsafe here too, since `yield from` over a chunked `LazyCollection` can repeat keys and collapse
  entries.
  ⛔ **THE TRIAGE'S REMEDY CLAIM WAS FALSE, AND CHECKING IT CHANGED THE BUILD.** `docs/backlog-triage.md`
  said `M32` fixed the sibling *"in exactly the prescribed shape (a drain loop plus a second tenant)"*.
  `M32` added a **`Bus::fake` set-equality case only**; the drain loop in that file is **`M6`'s**, predates
  it, and dispatches the **child** directly so it never reaches the parent's loop at all. Two unrelated
  mechanisms in one file were read as one.
  ⛔ **AND THE ROW'S PRESCRIBED REMEDY IS BLIND ON ONE OF THE FOUR FILES.** `WebhookRetrySweeper` writes
  **no rows**, so under the hoist acme is swept twice and re-dispatches its own due delivery both times:
  the queue depth is **2**, numerically identical to two tenants swept once. A drain loop plus a count is
  not enough there — `WebhookRetrySweepTest` asserts **per-delivery payload containment** instead, so
  each due delivery must have been enqueued exactly once.
  **The drain helpers are now bounded loops with the child count ASSERTED rather than inferred**
  (`$activeTenants = 1` by default, so all fourteen existing call sites are unchanged). Proven
  load-bearing rather than decorative: reverting one loop to the pre-M44 single-child drain reddens
  **only** the two-tenant case. Filed by `M32`.

- **`minor` · Two `WebhookRetrySweepTest` cases were passing for a reason unrelated to their names.**
  **Found and FIXED by M44 (2026-08-29) while taking the row above** — filed here because it is a
  *separate* defect from the tenant-width one and would otherwise be invisible to any later search.
  Measured, not reasoned about: replacing `SweepWebhookRetriesJob::sweep()`'s body with a comment — a
  sweep that dispatches **nothing at all** — left **two of the file's three cases green**, because both
  assert `webhookQueueDepth() === 0` and a dead sweep produces exactly that. They proved nothing about
  the sweep. ⚠️ **`mutate.php` reported `CAUGHT` throughout, which is correct and is the trap**: one case
  did redden, so the aggregate verdict is green-lit while the vacuity is visible *only* in the printed
  RED list. **Read the red set, never just the verdict.** The asserted fan-out count fixes it as a side
  effect — the same mutation now reddens **5 of 5**. Filed by `M44`. **Not live** — the defect it records was found and fixed by the same increment, and the fix is in the tree, judged by `M65`.

- **`minor` · Every `MaintenanceJob` fan-out is proved one file at a time, so a future one inherits no
  coverage.** **Deliberately not built by M44 (2026-08-29)** — filed the moment it was decided. After
  M44 all five fan-outs carry an identically-named `Bus::fake` case, which is exactly the duplication a
  structural gate should own: one test could iterate the `MaintenanceJob` subclasses and assert the
  dispatched-`tenantId` multiset equals the active-tenant set. ⛔ **Two things block the naive form, both
  verified rather than assumed:** a generic loop would invoke `VerifyCustomDomainsJob::handle()`, which
  does real DNS work inline, and `PruneFailedJobsJob::handle()`, which works inline on `failed_jobs` —
  neither fans out. Avoiding them needs a declared fan-out/inline split, and that registry drifts unless
  it is itself set-equality-checked against the concrete subclasses. Left because the row M44 closed was
  already four files wide and `tests/Feature/Queue/JobContractTest.php` is a shared artefact.

  **THE ROW AS FILED FOLLOWS, KEPT VERBATIM RATHER THAN DELETED** — its reasoning is what made the build
  correct, including the two places it was wrong, and a closed row that discards its own argument teaches
  nothing to the next reader.
  ~~`SweepWebhookRetriesJob.php:26` · `SweepScheduledFormsJob.php:30` · `RollUpUsageCountersJob.php:27` ·
  `ReapExpiredDraftsJob.php:25`.~~ **Filed by M32 (2026-08-28), which fixed the other two of the six and
  deliberately did not fix these** — a different failure mode needing a different fix, and a user decision of
  record that it be its own row.
  ⛔ **THIS CORRECTS A HAND-OFF THAT SAID THESE WERE CHECKED AND CLEARED.** They were checked as *production
  code*, and the production code is correct at all six sites. As *coverage* they are blind: each drives its
  parent end-to-end through a real `database` queue and asserts real per-tenant effects — the stronger idiom,
  not the weaker one — but **every fixture holds exactly one active tenant**, so hoisting the loop variable is
  a no-op mutation there and nothing can go red. The helper comments say so themselves:
  *"the sweep enqueues one child (acme is the only active tenant)"* — `ScheduledFormSweepTest.php:42`,
  `UsageRollupTest.php:41`, `DraftReaperTest.php:70`. This is M20's lesson verbatim: **a green gate is often a
  fixture too small to reach the defect.**
  ⛔ **AND THE OBVIOUS FIX PRODUCES A NEW GREEN TEST THAT STILL CANNOT SEE IT.** Each helper's drain is
  hard-coded to exactly two `workOneJob('scheduled-maintenance')` calls — parent, then *one* child. Add a
  second tenant and the second child is simply never worked, so every downstream assertion reads identically.
  **The fix is a drain loop plus per-tenant effects on both tenants**, in four files
  (`WebhookRetrySweepTest.php:33-39`, `ScheduledFormSweepTest.php:39-44`, `UsageRollupTest.php:38-43`,
  `DraftReaperTest.php:68-72`). ⚠️ Changing a shared fixture's width is the hazard M31 names — **add tenants
  and cases, do not convert the existing single-tenant ones**, or the old coverage is deleted rather than
  extended.
  ➕ **A grep-visibility note worth keeping:** `SweepTenantWebhookRetriesJob` appears **nowhere** under
  `tests/` — not once, not even in a comment — and `SweepTenantScheduledFormsJob`, `ReconcileTenantUsageJob`
  and `ReapTenantDraftsJob` appear only inside comment blocks. A child job class being un-greppable is itself
  the tell that no test names it. Filed by `M44`. **Not live** — a coverage question about how the proof is written rather than a defect in the fan-outs themselves, judged by `M65`.

- **`minor` · `gamification:backfill --sync` reports failure after it has already committed every award.**
  `BackfillGamificationCommand.php:179-182` returns `self::FAILURE` on a non-balancing tally, but `:224` has
  already run `DB::commit()` for **every** workspace by then, and the error line names no workspace. **Filed
  by M32 (2026-08-28) and deliberately not fixed** — it is a production-behaviour and operator-signal
  question, not an assertion-strength one, so it sat outside the remit of the row M32 closed. User decision of
  record that it be its own row.
  **Two nuances the original observation omitted, both verified:** (i) on `--dry-run` the same branch returns
  FAILURE with nothing committed (`:221-222` rolls back), so *"after commit"* is true of `--sync` only; and
  (ii) the per-workspace table at `:167` does carry each workspace's five bucket counts, so an operator **can**
  derive the culprit by hand — the attribution is missing from the error line, not from the output.
  ⚠️ **"The job side decided the opposite for the identical invariant" overstates it.** Neither side rolls
  back and neither throws; the job logs a non-balancing tally as a field while the command reports it as a
  non-zero exit status. The divergence is in the **operator signal**, not in two opposite transaction
  postures — worth settling deliberately rather than by drift. Filed by `M32`. **Latent** — needs a rule to fail after the command has already committed every award, judged by `M65`.

- ~~**`minor` · No gate in this repository detects a component used in a template but never imported.**~~
  ✅ **DONE — M28 (2026-08-26).** `scripts/component-import-lint.php`, registered in `composer.json`
  (script **and** the `quality` aggregate) **and as its own `ci.yml` step** — both, because `ci.yml`'s own
  note says a composer-only registration would gate nothing since no CI job runs `composer run quality`.
  **Baseline: 180 SFCs scanned, 0 violations**, so it ships merge-blocking with **no `KNOWN_*` quarantine
  list** — the shape M19 spent a whole increment draining.
  ⚠️ **PROTOTYPED READ-ONLY BEFORE THE CLAIM WAS WRITTEN, WHICH IS THE ONLY REASON IT HAS A CLEAN
  BASELINE.** The row gives no false-positive estimate and that was the real risk. Measured: **180 `.vue`
  files, ALL of them `<script setup>`** (so the rule needs exactly one shape, which the row does not say),
  and a naive rule flags **2** — both real, and **two DIFFERENT bugs in the naive rule**:
  **(a)** `packages/design-system/src/components/Badge/Badge.vue:40` is a tag **quoted inside an HTML
  comment** — the project's standing *NAME THE THING, NEVER QUOTE IT* lesson arriving from a new
  direction: a comment that quotes the construct it discusses booby-traps the tool that reads it. The gate
  strips `<!-- … -->` first. **(b)** `resources/js/components/builder/ConditionRows.vue:85` is a
  legitimate **recursive self-reference**, which Vue resolves by filename with no import. The gate allows
  tag === basename.
  ⛔ **THREE POSITIVE CONTROLS, THREE DISTINCT FAILURE MODES, EACH RESTORED BY sha256 BYTE COMPARISON.**
  **R1** — deleting the `MdsBanner` import from `resources/js/Pages/invitations/Show.vue` (M9's actual
  historical defect) reddens naming that file. **R2a** — a renamed scan root reddens with
  *"scan root is missing"*. **R2b** — roots that exist but yield nothing reddens with *"scanned only 0
  SFC(s), expected at least 100 … a DISCOVERY regression, not a clean run"*.
  ✅ **AND THE GAP IS RE-MEASURED ON THIS TREE RATHER THAN INHERITED FROM M9's NOTE:** with that import
  deleted, **`vue-tsc --noEmit` exits 0 and `vite build` exits 0** while the new gate exits 1. That is
  precisely the hole, confirmed first-hand.
  ⚠️ **THE R2 CONTROL FOUND A DEFECT IN THE GATE ITSELF, WHICH IS WHY IT WAS WORTH RUNNING.** The first
  version printed the *"add the import"* footer for **every** violation — including a missing scan root,
  whose remedy is entirely different. A gate whose failure message points at the wrong fix costs more than
  the bug it caught. The footer is now scoped to R1 violations only.
  ⚠️ **THE HOST LINT-GATE QUARTET IS NOW A QUINTET: `97 · 113 · 31 · 113/121/0 · 180`** — the four
  existing figures re-measured and unmoved. PHPStan **18, this file not among them** (`phpstan.neon`
  covers `app`/`database`/`routes` only). **Pint DOES scan `scripts/`, proven with a deliberate misformat
  probe** rather than inferred from its `passed` line — M9's lesson applied. Original filing follows.
  — *Found by M9 while scoping an unrelated row: `resources/js/Pages/invitations/Show.vue` rendered
  `<MdsBanner>` with no import from J3b until M9, and `resources/js/app.ts:29-32` registers no components
  globally (`.use(plugin)` is Inertia's), so Vue resolved it to nothing and the expired-invitation error
  banner never rendered. ⚠️ **MEASURED, NOT ASSUMED — the mutation was re-applied and both gates stayed
  green**: `vue-tsc --noEmit` exits 0 and `vite build` exits 0 with the import deleted. Vue emits a runtime
  *"Failed to resolve component"* warning, which nothing reads: no Vitest test mounts this page, and the e2e
  console assertion in `tests/e2e/support/console.ts` never visits it in a state where the banner renders.
  **Not live** — this is a missing gate, not a defect; the one instance it hid is fixed. Cheapest honest
  shape is a lint rule over `<script setup>` SFCs comparing PascalCase template tags against the file's
  imports, with an allow-list for the globals (`component`/`template`/`transition`/Inertia's `Link`, `Head`).
  It lands in `scripts/` and moves a gate baseline, which is a tooling row rather than the page row
  that found it — the same reasoning M7 used for the `§D<n>` citation gate directly below.* Filed by `M9`.
- ~~**`minor` · Neither structural lint gate fails on an empty scan.**~~
  ✅ **DONE — M36 (2026-08-28), AND THE ROW UNDERSTATED ITSELF: FOUR GATES, NOT TWO.** The row names
  `constraint-boundary-lint.php` and `migration-lint.php`. `scripts/controller-gate.php:101-102` and
  `scripts/job-payload-lint.php:269-270` carry the identical shape and it names neither;
  *(the second line number was `:246-247` until `M69`, which added a docblock above `EXEMPT_JOBS` and
  shifted everything below it. Re-pointed at the `MIN_EXPECTED_JOBS` floor this row is about — it had
  ALREADY drifted onto two closing braces before that edit, and only stayed green because a brace is
  not a blank line. Repaired because M69 moved it, not as a sweep.)*
  `component-import-lint.php` was the only one of the five with a floor, and it is the gate whose author
  filed the row. All four now carry a named `MIN_EXPECTED_*` constant asserted before the success path,
  in `component-import-lint.php`'s own R2 shape. **Its two citations held** — `:297-304` rather than
  `:296-304` (`:296` is blank) and `:140-141` exactly.
  ⛔ **AND THE UNDER-SCAN IS NOT HYPOTHETICAL ON THIS HOST — IT IS REPRODUCIBLE TODAY, WHICH THE ROW DID
  NOT KNOW.** Run these gates *inside* the app container and `RecursiveDirectoryIterator` descends the
  Windows bind mount only partially, while `find` on the same path sees every file. Measured on the same
  tree, both sides, with CI as the tie-breaker:

  | Gate | Host | Container | CI |
  |---|---|---|---|
  | `controller-gate` | **97** | 49 | **97** |
  | `migration-lint` | **113** | 86 | **113** |
  | `constraint-boundary-lint` | **113 / 121 / 0** | 86 / 81 / 0 | **113 / 121 / 0** |
  | `job-payload-lint` | 31 | 31 | 31 |
  | `component-import-lint` | 180 | 180 | 180 |

  **Every one of those container runs printed "passed" and exited 0.** `controller-gate` scanned 49 of 97
  controllers and called it a clean run. That is this row's defect in its live form — partial rather than
  empty — and it is the measured mechanism behind the project's standing *"lint gates on HOST"* note,
  which until now carried no number. **CI agrees with the host, which is what makes the host
  authoritative rather than merely different.**
  ⚠️ **THE FLOOR'S LIMIT IS STATED RATHER THAN DISCOVERED.** It catches a broken or renamed scan root,
  and it catches the controller case at 49 < 55. It does **not** catch `migration-lint` or
  `constraint-boundary-lint` at 86 > 65, and it was never going to: a floor high enough to catch a 76%
  scan trips on ordinary deletion. The remedy for that case is running the gates where CI runs them,
  which `scripts/preflight.php --with-gates` now asserts and explains.
  **Positive control:** with the floors in place the container run of `controller-gate` exits 1 naming
  the mechanism, while all five still pass on the host at 97 · 113 · 31 · 113/121/0 · 180. Filed by `M1`.

- **`minor` · Every hand-off prescribes a Pint command that scans ~40 fewer files than CI does.**
  Found by M36 while adding files to `scripts/`. Both lanes' hand-offs say
  `vendor/bin/pint --test app tests database` — **1375 files**. CI runs `composer run lint`, which is a
  **bare** `pint --test` with no paths and no `pint.json` in the repository — **1414 files**. The local
  command misses `scripts/`, `config/`, `routes/` and `bootstrap/` entirely, so a style violation in any
  of them passes locally and reddens CI. **Measured, not inferred**: M36's four floor edits are all in
  `scripts/`, all four were flagged by bare Pint, and none of them would have been seen by the
  prescribed command. **Live** — the fix is one word in two hand-off lines, but it is filed here because
  the hand-offs are rewritten every increment and a fix that is not written down does not survive one. Filed by `M36`.

- ~~**`minor` · `fb-lane-c` is an abandoned worktree that every numbering check must now read past.**~~
  ✅ **DONE — M50 (2026-08-31), and the row's prescribed remedy was wrong in two ways.** Closed as part
  of the collapse to a single lane (`docs/adr/0022-single-lane-development.md`), which removed
  `fb-lane-b` too. **The evidence held except for one figure:** the worktree was **177** commits behind
  when it was taken, not the 104 recorded here — a dated measurement, and the drift is exactly why a
  row's number may not be quoted forward. Two things the row did not have: `lane-c-bootstrap` **never
  existed on the remote either**, so Lane C was cut, never used, never published and released nothing;
  and the dirty file was the design-system `package-lock.json`, `+1/−21`, every deletion a bare
  `"peer": true` key — **npm bookkeeping, not work.**
  ⛔ **`git worktree remove` is NOT "the whole fix".** (1) It **refuses** on a worktree holding a
  modified tracked file — measured, exit 128 — and the refusal is the guard working, not an obstacle.
  It was cleared by saving the bytes outside the repository, **proving the saved copy reconstructed
  them byte-for-byte**, and only then restoring the file. `--force` was never used. (2) The real
  coupling is **in code, not in the worktree listing**: `tracker-lint.php` R6 required the Lane B
  hand-off marker exactly once, so retiring the lane without amending R6 in the same commit reddens
  `main` — proven by deliberate defect before the change, failing R6 alone.
  ⚠️ **And one thing following the row literally would have destroyed:** `docs/claims/lane-b.md` is
  KEPT. `state.php` derives the increment from the `## RELEASED` headings of both claim files, and that
  file holds ten releases recorded nowhere else — losing them lowers the maximum **silently**, which is
  a number collision rather than an error. Filed by `M36`.

- **`minor` · A line-splitting regex matches a byte INSIDE a UTF-8 character, and one faker name is
  enough to trigger it.** `tests/Feature/Audit/ImpersonationAttributionTest.php:204` splits the streamed
  audit CSV with `preg_split('/\R/', ...)`. Without the `u` modifier PCRE's `\R` matches the single byte
  `0x85` as a Unicode NEL — and `0x85` is a *continuation* byte in common characters, not only exotic
  ones. **Measured:** the name `Åsa Lindqvist` (`Å` is `C3 85`) splits that CSV into 4 rows where it has
  3, so `str_getcsv` receives a fragment and every subsequent positional index shifts. The test asserts
  `$rows[0][5]` and `$rows[1][4]` by position — deliberately, and correctly — so a shifted array either
  reddens the suite on a dice roll or lands a different value in the asserted slot. ⚠️ **This is the
  `M9` shape exactly**: a fixture that is a random faker name turned one test red once already, and
  re-running would have hidden it forever. **The fix is `explode` on a newline** — the repository is pure
  LF and `tracker-lint` R5 asserts it on the tracker files — or `/\R/u` if a regex is wanted.
  **Found by M42** while writing `scripts/state.php`, whose first draft had the identical defect: it
  split `docs/claims/lane-a.md` into 2,297 lines where the file has 2,273, because that corpus is full of
  check marks (`E2 9C 85`), and every line number it reported after the first one was wrong by a growing
  offset. **Not fixed here — `tests/` is outside this increment's claim.** **Latent.** Filed by `M42`.

- ~~**`minor` · `docs/gate-baselines.md` has no staleness signal, and it is stale right now.**~~ Its
  provenance names run `33175202807` (sha `454d9ba`, `M39`'s merge) while `M40` and `M41` have both
  merged since — the file written to end stale numbers is itself eleven commits behind the trunk, and
  every close-out in between was supposed to regenerate it. Nothing measures or reports that: `preflight`
  prints the provenance line verbatim, which looks measured precisely because it is a real run id.
  `scripts/state.php` now reports the distance (`commits_behind_main`), but reporting is not gating.
  **The remedy is one of two, and they are not equivalent:** have `gate-baselines.php` refuse to leave a
  file whose sha is not `origin/main`'s head, or stamp the distance into the document so a reader sees it
  without running anything. **Found by M42 (2026-08-29)**, not fixed because the generator was outside
  this increment's claim and the choice between the two is a real one.
  ⛔ **AND A THIRD OPTION IS BETTER THAN EITHER, MEASURED ON THIS ROW'S OWN CLOSE-OUT: A RAW COMMIT
  DISTANCE OVER-REPORTS, BECAUSE MOST COMMITS CANNOT PRODUCE A RUN AT ALL.** Immediately after `M42`
  regenerated the file from its own post-merge run, `scripts/state.php` reported it *"1 commits behind
  origin/main — regenerate it"*. The single intervening commit was the close-out, touching
  `PROGRESS.md`, `PROGRESS_ARCHIVE.md` and `docs/claims/**` — every one of them in `ci.yml`'s
  `paths-ignore` set, so **no CI run for it exists or could exist**. The baseline was current and the
  instrument said otherwise. **Count only commits that touch a non-ignored path**, or the signal cries
  wolf on every close-out and gets ignored exactly like the number it replaced. ⚠️ **That is the
  vacuous-success family inverted:** the catalogued members read an absence as a success, and this reads
  a *deliberate* absence as drift. `state.php` already knows the ignore set is the question; it does not
  yet ask it. **Live.** Filed by `M42`.
  ✅ **DONE — M70 (2026-09-04). THE HEADLINE WAS DEAD, THE ADDENDUM WAS LIVE, AND ONLY THE SECOND WAS
  TAKEN.** Every clause of the headline is now false: the file is stamped from `M69`'s own post-merge
  run, `state.php` **and** the generated hand-off both report the distance, and regeneration is
  `CLAUDE.md`'s close-out step 3, honoured across twelve consecutive close-outs. ⚠️ **A taker working
  this row from its headline would have found nothing to fix** — which is the shape worth carrying, not
  the fix. ✅ **The addendum was firing on this tree at the moment it was read:** the bare
  `git rev-list --count` reported **4** where only **one** of the four commits touches a non-`paths-ignore`d
  path. Ground truth, printed rather than reasoned: three of them are `docs/claims/lane-a.md`,
  `PROGRESS.md`, `docs/backlog-triage.md` and `docs/gate-baselines.md`; the fourth is
  `scripts/preflight.php`. ⛔ **`derive_triage()` carried the IDENTICAL defect twenty lines away**, and
  over-reports harder — a close-out regenerates `docs/backlog-triage.md` itself, so the file was counted
  stale **by its own regeneration**. Both fixed; **both numbers are printed**, because silently swapping
  one constant for another would leave a reader unable to tell a quiet trunk from a broken parser.
  ⛔ **THE IGNORE SET IS PARSED FROM `ci.yml`, NEVER COPIED** — it exists in the one place that can
  decide anything, and a literal list in the file whose whole job is to derive would be a third statement
  of one fact. The parser has a floor: an empty harvest returns *cannot measure*, never *nothing is
  ignored*. ⛔ **NEITHER PRESCRIBED REMEDY WAS TAKEN AND BOTH ARE WRONG**: refusing a non-head sha in
  `gate-baselines.php` can never be satisfied, because that file is itself inside `paths-ignore` and its
  own regeneration commit produces no run; stamping the distance into the document makes it wrong the
  instant the next commit lands. Four controls, at the call site because `mutate.php` cannot drive a
  non-Pest gate — the bare count restored; the `paths-ignore:` key renamed (empty harvest **reported**,
  not silently equal to raw); and one harvested pattern dropped, twice, to show both that the number
  moves and that the parser is still harvesting. ⚠️ **The third control's first expectation was WRONG and
  the code was right** — predicted 1→3, measured 1→4, because the close-out commit touches
  `docs/claims/lane-a.md` *in addition to* the other ignored paths. `M60`'s row in miniature: the defect
  in a verification harness presents as a failing surgery, and the tempting response was to "fix" the
  derivation.

- **`minor` · A claim file has no constrained form for a forward declaration, so the one stale
  declaration on the tree cannot be gated.** `docs/claims/lane-b.md:39` states *"`M36` IS THE NEXT FREE
  NUMBER"*, six increments stale. It is inert only because `state.php` no longer reads it — but nothing
  stops the next writer reading it, and **Lane A may not correct it**: one writer per claim file is what
  makes a claim conflict structurally impossible, and reaching across to fix a number would break the
  rule that surfaced it. ⛔ **A gate is not available either, and the reason is the transferable part:
  inside a claim file a DECLARATION and a QUOTATION OF ONE ARE THE SAME BYTES.** `M42` proved that by
  building the gate and watching it go red on its own claim, which quotes lane-b's stale sentence in
  order to file it. **The fix is to give claim files a machine-readable namespace footer** — the shape
  `M42` used for the hand-off, where the token is positional rather than prose — which belongs with the
  Rule 7 rewrite rather than bolted on. **Found by M42 (2026-08-29).** **Live.** Filed by `M42`.

- **`minor` · `tracker-lint` R8 guards `CLAUDE.md` and cannot reach `PROGRESS.md`, which is the half that
  actually rotted.** Standing Rule 7(g) held a stale ADR number for twenty-three increments; R8 would not
  have caught it. It scans `CLAUDE.md` only, because that file's whole contract is that it points rather
  than states, so *any* namespace literal in it is wrong and no judgement is needed. The tracker has no
  such constraint: a live forward claim and a dated `RELEASED` bullet quoting a past one are textually
  identical, so a rule over it is either **red on arrival** — which `M40` established can never merge —
  or vacuous. ✅ **UNBLOCKED BY `M45` (2026-08-29), AND STILL OPEN — THE PRECONDITION IS MET, THE GATE
  IS NOT BUILT.** The dated records now live in `PROGRESS_ARCHIVE.md`; what remains in Standing Rules
  is **46,511 bytes** of rules 1–8 and is current by definition, so a rule over it is no longer red on
  arrival. ⚠️ **Two things the next taker should measure rather than assume.** (1) The exemption
  surface is not zero: `M45`'s own pointer bullet in 7(g) names `M1`, `M14`, `M15` and two dates, and
  Rule 7(g)'s surviving imperatives cite `0010` as a reserved ADR — so a naive "no namespace literal
  in Standing Rules" rule is red on arrival for a *different* reason than before, and the constrained
  form has to be positional, as `M42` established after two failed attempts at prose. (2) It must not
  be pointed at `## Next Session`, which is still 214,073 bytes of dated hand-offs — that section has
  its own `major` row and has to move first. **Filed by M42 (2026-08-29)** at the moment it decided
  not to fake it. **Live.** Filed by `M42`.
  ⚠️ **PREMISE CORRECTED BY `M70` (2026-09-04) — TWO OF THIS ROW'S THREE BELIEFS ABOUT THE TRACKER ARE
  NOW FALSE, AND ONE OF THEM IS ITS STATED BLOCKER.** (1) *"It must not be pointed at `## Next Session`,
  which is still 214,073 bytes … that section has its own `major` row and has to move first"* — that
  section is **5,653 bytes**; `M48` moved it, and its `major` row is closed. The blocker is discharged.
  ⚠️ But a new exemption replaced it: the single generated `LANE A NEXT PROMPT` line is dense with
  namespace literals, all of them self-healing on every regeneration, and a naive rule pointed there is
  red on arrival for that one line alone. (2) *"`## Current Status` is gone"* — it is **38,818 bytes**,
  the second-largest section, holding thirteen dated `IS MERGED` bullets and regrown across nine
  close-outs. **The tracker has TWO live sections full of dated records, not one.** (3) The exemption
  surface inside `## Standing Rules` is understated by about an order of magnitude: **all four of `R8`'s
  existing patterns already fire there** — 24 `M\d+` hits, a migration prefix and a literal `NEXT FREE`,
  the last two inside `7(g)`'s own imperative, where they are worked examples of the collision the rule
  forbids. That is the genuinely hard case, and it is one line. ⛔ **AND A COLLISION THE GENERATED
  TRIAGE CANNOT SEE:** this row and `M60`'s surgery-harness row both land in
  `scripts/tracker-lint-controls.php` — a new rule group needs `$cases` entries and a parameterised
  `write_fixture_files()`, which hard-codes a `## Standing Rules` fixture body — so they must not be
  batched together, though the generator proposes exactly that.

- ~~**`major` · The `[tracker-surgery]` marker cannot survive a squash merge in any form both gates
  accept, so `R7` is unarmable on the trunk.**~~
  ✅ **DONE — M47 (2026-08-29). THE HEADLINE HELD AND WAS PROVEN AGAINST THE REAL TRUNK BYTES, AND THE
  ROW UNDERSTATED ITSELF: THE MARKER WAS THE SMALLER OF TWO INDEPENDENT WAYS R7 COULD NOT FIRE.**
  `scripts/tracker-lint.php` (R7), `scripts/state.php` (one docblock, no behaviour), `CLAUDE.md` (two
  bullets under *Merging*, one rewritten under *The tracker*), `PROGRESS.md` (Standing Rule 7).
  ⛔ **THE VACUOUS SUCCESS WAS CAPTURED LIVE RATHER THAN INFERRED.** The shipped gate was run in a
  detached worktree at `1f966a4` — M45's own merge, the largest surgery since the incident — and
  reported `R7 delta — PROGRESS.md line delta is -133 (583 to 450)`. **The ordinary branch.** A
  161,528-byte removal of the constitution, declared twice in the trunk message, and the only gate this
  repository has against the 2026-08-16 deletion classified it as a routine edit.
  ⛔ **THE SECOND DEFECT IS THE LARGER ONE AND THE ROW TREATS IT AS A FOOTNOTE.** `DROP_LIMIT` is 200
  **lines**, and this file's hand-off and status bullets are single lines thousands of bytes long.
  Measured across **every** commit touching `PROGRESS.md` on `origin/main` — 394 parent/child pairs,
  blob sizes from `git cat-file` — the distribution is bimodal with an order of magnitude between the
  halves: surgeries at 938,007 · **670,409 (the incident)** · 307,867 · 272,006 · **161,528 (M45, at
  133 net lines)**, and everything ordinary at **14,340 or less**. `DROP_BYTE_LIMIT` is 50,000, which
  is 3.5× above the largest ordinary drop and 3.2× below the smallest surgery.
  ⚠️ **AND THE CANONICAL "1,086" IS A `numstat` DELETION COUNT, NOT THIS GATE'S ARITHMETIC.** R7
  computes a **net** drop, so to R7 the incident is **1,085** and M45 is **133**. Both figures are now
  in the constant's comment, because a threshold justified by a number the gate does not itself compute
  is a small instance of the defect the rule exists to catch.
  ✅ **TEN POSITIVE CONTROLS, AND THE PAIRING IS THE POINT — NEITHER HALF IS DECORATION.** Three replay
  the real trunk bytes of `1f966a4` rather than a fixture: with both changes it reports **DECLARED
  SURGERY**; with the marker predicate reverted to bare line start it goes **RED**; with the byte limit
  raised past the drop it falls silently back to the ordinary branch. Five more fix the marker form
  against a synthetic overrun — mid-sentence **FAIL**, indented **FAIL**, bare line start **PASS**,
  `* ` **PASS**, `- ` **PASS**. Two more pin the threshold **at the boundary**: a 50,001-byte removal in
  125 lines fails, a 49,994-byte removal in 67 lines passes.
  ⛔ **`scripts/mutate.php` COULD NOT DRIVE ANY OF THIS, AND THE OPEN ROW PROPOSING A `--command=` MODE
  WOULD NOT HAVE EITHER — R7's INPUT IS THE COMMIT GRAPH, NOT A FILE.** Its discipline was reimplemented
  at the call site instead: baseline first, abort unless the sha256 moved, restore by byte comparison.
  See the refinement filed on that row below.
  ⛔ **THE ROW'S THIRD PRESCRIPTION WAS ACTIVELY WRONG AND HAS BEEN DELETED FROM THE GATE.** R7's own
  failure message said *"or put it in the PR title"*; `state.php`'s `remote_highest()` anchors merged
  titles on the increment-number prefix, so following it would have dropped that pull request out of the
  independent cross-check that exists to prevent a numbering collision. The reason is now written into
  **both** files, in both directions — the condition the row identified was that neither mentioned the
  other.
  ⚠️ **WHAT IS NOT PROVEN, STATED RATHER THAN DISCOVERED:** no real GitHub squash was exercised. M47's
  own drop is far under both limits, so a green R7 on its merge is the vacuous-success family again.
  **The end-to-end proof is owed by the increment that moves `## Next Session`**, which is the next
  surgery and is now gated by the byte limit.
  ➕ **AND THE M46 CITATION GATE FIRED ON THIS DIFF, ON THIS ROW'S OWN CITATION.** Adding the docblock to
  `state.php` pushed line 249 into a comment, so the ledger tier went 19 → 20 and went red. The citation
  below is therefore rewritten to name `remote_highest()` instead of a line — which is what `CLAUDE.md`
  already prescribes, and a gate built one increment ago catching the increment that edits the cited
  file is the strongest evidence available that it works. Original filing follows, with both of its
  coordinates corrected: the predicate is one line below where it says, and `state.php` is cited by
  function.
  **`major` · The `[tracker-surgery]` marker cannot survive a squash merge in any form both gates
  accept, so `R7` is unarmable on the trunk.** ⛔ **MEASURED ON `M45`'s OWN MERGE (PR #235, `1f966a4`),
  which passed no `--body` at all and therefore used GitHub's default.** The marker *is* in the trunk
  message — twice — and `grep '^\[tracker-surgery\]'` still finds **nothing**, because the default
  squash body renders every commit subject as a bullet: `* [tracker-surgery] M45 phase 1: …`.
  `R7` matches `/^\[tracker-surgery\]/m` (in `scripts/tracker-lint.php`, at the `$declared` assignment —
  the row's original line cite was one short), and `* ` in front of it is not a line start.
  **Preserving the text is not preserving the form.**
  ⛔ **`M41` REDDENED `main` BY EMPTYING THE BODY; THIS IS THE SAME OUTCOME REACHED BY ACCEPTING THE
  DEFAULT**, so the rule written in response to that incident — *"never pass an empty `--body`"*, in
  `CLAUDE.md` under Merging — is **necessary and not sufficient**, and following it exactly still
  produces an unarmed marker. `M45` was unaffected only because its drop was 133 lines against
  `DROP_LIMIT`'s 200 and `R7` could not fire either way; **a surgery over 200 lines that obeys every
  written instruction merges RED.**
  ⛔ **AND THE WORKAROUND THE GATE ITSELF SUGGESTS IS CLOSED BY A SECOND GATE.** `R7`'s failure message
  says *"or put it in the PR title"*. **`remote_highest()` in `scripts/state.php` anchors merged
  pull-request titles on the increment-number form** for the independent increment cross-check that
  exists to prevent a numbering collision, so a `[tracker-surgery]` prefix in the title silently drops
  that PR out of the second source. **The two gates want incompatible first characters on the same
  string**, and nothing in either file mentions the other.
  **The remedy is one line and it is a merge instruction, not a code change**: pass an explicit
  `--body` whose **first content line** is the marker, and put it in `CLAUDE.md` under Merging beside
  the empty-body rule. ⚠️ **Verify it the way `M40` verified `R7` in the first place — with a
  deliberately red push** — because this is the third distinct way this marker has failed to arm and
  the first two both looked correct at the moment of writing. Consider also relaxing `R7` to accept
  the marker after a leading `* ` or `- `, which is the shape a squash actually produces; that is a
  smaller change than it looks and closes the case where a future merge is done through the web UI.
  ⚠️ **Sized as `major` because the failure is silent, lands on `main`, and defeats the only gate this
  repository has against the incident that cost it 1,086 lines** (`f565ac9`, 2026-08-16).
  **Filed by M45 (2026-08-29)**, measured on its own merged commit rather than predicted. Filed by `M45`.
- **`minor` · `scripts/mutate.php` cannot drive a positive control for anything that is not Pest in a
  container.** Its `--tests` argument is Pest paths and it execs them via `docker exec`, so a gate
  implemented as a standalone script — `tracker-lint`, `state.php`, the five lint gates — has no harness,
  and the standing instruction to use it *for every positive control* is unfollowable for exactly the
  class of gate this project keeps adding. **Measured by M42**, which proved six R8 cases and four
  `state.php` cases by hand instead: baseline first, tokens read from files, abort unless the sha256
  moves, restore by byte comparison. That is `mutate.php`'s own discipline reimplemented at the call
  site, which is the argument for a `--command=` mode rather than for doing it again. ⚠️ **Docker being
  down on the host made this unavoidable rather than merely inconvenient** — with no container there is
  no harness at all. **Live.**
  ➕ **REFINED BY M47 (2026-08-29), WHICH NEEDED EXACTLY THIS AND FOUND THE PROPOSED MODE WOULD NOT HAVE
  SERVED IT.** A `--command=` mode mutates a **file** and runs something; `R7`'s input is the **commit
  graph** — the thresholds read blob sizes at `HEAD~1` and the declaration is read out of
  `git log --format=%B`. No amount of file mutation reaches it. What that gate actually needs is a
  *history* fixture: a detached throwaway worktree at a chosen ref, a synthetic commit, and the message
  amended per case. M47 built ten controls that way and threw the harness away with the worktree.
  ⚠️ **So the row's scope is right and its shape is one size too small**: `--command=` covers the five
  lint gates and `state.php`, and leaves `tracker-lint`'s only interesting rule uncovered. Worth
  splitting into two rows before either is taken. Filed by `M42`.

- **`minor` · `scripts/next.php` takes each release's LEAD paragraph, and a lead paragraph is often a
  file manifest rather than the lesson.** The generator renders the newest four `## RELEASED` sections
  into the hand-off so the traps are derived rather than retyped, which works: `M39`, `M40` and `M41`
  each open with a substantive sentence and read well. **`M42` does not.** Its lead paragraph is
  *"Every claimed file was edited. `CLAUDE.md` (new, 167 lines) · `scripts/state.php` (new) · …"*, so
  the rendered hand-off spends its whole 220-character budget on a file list and clips before reaching
  a single finding — while the actual lesson, *natural language cannot carry a machine token*, sits two
  paragraphs below under its own `###` heading. ⚠️ **Measured on the very first hand-off the generator
  produced**, which is the good case for catching it: the defect is in what a fresh session reads
  first. **The remedy is a choice, not an obvious fix:** prefer the first paragraph that is not a file
  manifest; or render the first `###` subsection heading, which every release already writes as a
  one-line summary of its own finding; or have the claim template mark one paragraph as the lesson.
  ⛔ **The third is the only one that is not a heuristic** — and this project has now been bitten four
  times by heuristics over prose, which is the same argument that produced the positional `[state …]`
  block in the same increment. **Filed by M42 (2026-08-29)**, not fixed because `scripts/` changes need
  a PR and pushing one straight to `main` would bypass the gate that makes the trunk trustworthy.
  ➕ **RE-VERIFIED AND THEN DEMONSTRATED BY `M72` (2026-09-05), IN THAT ORDER, WHICH IS THE FINDING.**
  A read-only pass marked it **`latent`**: the harm does not reproduce on the four releases in the window,
  and remedy (b) is **falsified** — *"every release already writes [its first `###`] as a one-line summary
  of its own finding"* is true of **zero of the newest nine**; six are administrative headings. ⛔ **Then
  `M72`'s own release put a manifest straight back into the window.** Its first draft opened *"Every
  claimed file was edited, and four were opened that the claim did not name…"*, and the rendered hand-off
  spent its whole budget on a file list and clipped before a single finding — hours after the same
  increment called the row latent. The row's premise had said exactly why that verdict was temporary: the
  lesson-first convention is nowhere written down and `docs/claims/TEMPLATE.md` still **prescribes** the
  manifest form, so it is *"one template-faithful author away from reverting"*. **Back to `live`**, with a
  measurement rather than an argument, and with the lesson that **a `latent` verdict is a claim about the
  world holding still**. ⚠️ **And the bigger waste is in a place the row never looks:** `next.php`
  applies `clip()` to the summary alone and renders the HEADING unbounded — filed separately.
  **Live.** Filed by `M42`.



### Documentation & specs

- ~~**`major` · `npm run build` cannot bootstrap a fresh clone or worktree, and the README is the only
  document that does not say so.**~~ ✅ **DONE — M27 (2026-08-26), PROVEN IN A THROWAWAY WORKTREE IN BOTH
  DIRECTIONS.** `README.md` is the only file that needed changing, which is the row's own finding holding
  up: `ci.yml:208-218` and `docs/deployment-infrastructure.md:39` were already correct.
  ⚠️ **NECESSARY AND SUFFICIENT, EACH HALF MEASURED** — a documentation fix is only worth as much as the
  sequence it prescribes, so the sequence was run. `git worktree add --detach <scratch> origin/main`,
  `dist/` confirmed absent by `ls` rather than inferred:

  | Run | Result |
  |---|---|
  | `build` alone | **exit 1** — `Unable to resolve @import "@meridian/design-system/tokens.css"`, **twice** |
  | `ds:tokens` without `ds:install` | **exit 1** — `Cannot find package 'style-dictionary'` from `build-tokens.mjs` |
  | `ds:install` → `ds:tokens` → `build` | **exit 0** — `✓ built in 13.58s` |

  ⚠️ **THE ROW UNDERSTATED ITSELF THREE TIMES, AND THE THIRD IS THE ONE THAT MATTERS.**
  **(1) TWO entry points fail, not one** — the row names `resources/css/app.css:11`, but
  `resources/public-runtime/public-runtime.css:4` imports the same artifact and `vite.config.ts:26` lists
  both as build inputs; the error names both directories. (That file is Lane B's column — it was evidence
  here, never a target.)
  **(2) THREE README sites, not one.** The row cites `README.md:66-74`; the command is at `:78` (`:51` is
  the section heading), the block listed **`build` BEFORE `ds:tokens`** under a comment calling the latter
  a *"regenerate"* — reading as optional maintenance rather than a prerequisite — **`ds:install` appeared
  nowhere in the file at all**, and a third site at `:97` (the e2e bootstrap) ran `ds:tokens && build`
  correctly ordered while still omitting `ds:install`. So the defect was never a missing note; it was an
  **ordering that actively misleads**, twice, plus an absent step.
  **(3) ⛔ THE FAILURE PRINTS A SUCCESS AFTER IT.** The PWA plugin's service-worker build runs *after* the
  client build fails and succeeds on its own — `✓ built in 329ms`, `public/build/sw.mjs 134.93 kB` — so
  **the last thing on screen is a green tick**. Only the exit code and the eleven lines above it disagree.
  A new contributor reads the tail, concludes the build worked, and then debugs a blank page. The row does
  not mention this and it is the strongest argument for the fix; the README now says *trust the exit code,
  not the tail*.
  ➕ **Filed rather than fixed:** `packages/design-system/package.json`'s `exports` maps **two** `dist/`
  artifacts — `./tokens.css` and `./tokens` → `dist/tokens.ts`. Nothing imports the latter today (grepped
  `resources/` and `packages/design-system/src`), so it is not a live second failure, but it is the same
  generated directory behind a second public export path. Noted in the README rather than changed.
  ⚠️ **MECHANICS, because they cost time and will recur:** `docker run -w` is mangled by MSYS
  (`MSYS_NO_PATHCONV=1` required), and vite writes `node_modules/.vite-temp`, so a `:ro` node_modules
  mount fails with `EROFS` **before reaching the real error** — which looks like a different bug entirely.
  Original filing follows.
  — *`resources/css/app.css:11` imports `@meridian/design-system/tokens.css`, which is a build artifact:
  `.gitignore:21` is `/packages/*/dist`, and `git ls-files packages/design-system/dist` returns nothing,
  so a tree that has just been cloned does not contain it. The true sequence is `ds:install` →
  `ds:tokens` → `build`, which `ci.yml` performs and `docs/deployment-infrastructure.md:39` documents —
  but `README.md:66-74` presents `npm run build` as a first-class command with no prerequisite. Live, and
  it is the first thing a new contributor runs. PROVE IT IN A THROWAWAY `git worktree`, NOT BY MOVING
  `dist/` ASIDE — the local tree has a populated `dist/` from earlier increments, so any test that starts
  from it measures the wrong thing. FILED BY `M23` (2026-08-26) WITHOUT BEING BUILT, AND THE FILING IS THE
  POINT. This row had been carried in Lane A's hand-off prompt alone for two increments and appeared in no
  document a backlog search would reach — the same shape as J4b1's four defects, which were recorded in
  the tracker and nowhere else. Its evidence was re-verified before this bullet was written: both
  citations hold.* Filed by `M23`.
- ~~**`major` · ADR-0001 claims `citext` and `pgcrypto` are enabled by default, covering case-insensitive uniqueness for share slugs and user email.**~~
  ✅ **DONE — M46 (2026-08-29). THE EXTENSIONS HALF HELD AT ALL THREE CITATIONS. THE SECOND CLAUSE IS
  FALSE, AND HOW IT CAME TO BE FALSE IS THE MORE USEFUL HALF OF THIS ROW.**
  `docs/adr/0001-postgresql-over-mysql.md`, three sites — the extensions bullet, the assumptions-section
  `citext` and `pgcrypto` bullets, and the negative-consequences restatement **the row does not name**, which
  was the third place the same promise was made. `CREATE EXTENSION` is issued exactly once in this
  repository, for PostGIS; `pgcrypto` is additionally **unneeded**, since no migration declares a
  database-side UUID default and every primary key is generated in PHP.
  ⛔ **"NO LOWERCASING ANYWHERE ON THE REGISTER/LOGIN PATH" IS FALSE.** `config/fortify.php` enables
  username lowercasing and Fortify canonicalises on **four** paths — registration, login, password-reset
  request and profile update — while the SSO, Google sign-in and invitation paths lowercase independently.
  ⚠️ **AND THE REASON IT READS AS TRUE IS A TRAP WORTH KEEPING: THE MECHANISM LIVES IN `vendor/`, GATED BY A
  CONFIG FLAG.** A grep of first-party code returns nothing and **confirms the false claim**. This is the
  second instance in this same increment — the row above has the other — and together they are the argument
  for why the citation-liveness gate added here refuses to be sold as catching behaviour negatives: an
  artifact negative is mechanisable, a behaviour negative is not.
  ➕ **THE REAL RESIDUAL IS NARROWER THAN THE ROW, AND THERE IS A SECOND ONE THE ROW NEVER FOUND.** (1) The
  guarantee is application-layer only: `users.email` carries a plain case-sensitive unique with no
  `lower(email)` index, so a seeder, factory or raw insert can still create two casings. (2) ~~**Share-slug
  LOOKUP is case-sensitive**~~ — ✅ **CLOSED BY M61 (2026-09-02)**, and this cross-reference is corrected
  rather than deleted because it was half wrong in a way worth keeping: *"storage is lowercase-only by a
  write regex"* was true of the HTTP surface and asserted as an invariant of the COLUMN, which the service
  layer did not hold up. Both public resolvers now lower the incoming segment and canonicalize the URL with
  a 301; the service lowers on write, so the storage half is an invariant now rather than a convention.
  ⚠️ **The `users.email` half in (1) above is UNCHANGED and still live** — M61 fixed the slug and touched
  nothing about email, and the two are the same shape only by analogy.
  Original filing follows.
  **`major` · ADR-0001 claims `citext` and `pgcrypto` are enabled by default, covering case-insensitive
  uniqueness for share slugs and user email.** `docs/adr/0001-postgresql-over-mysql.md:56` (restated `:83`,
  `:127`). Only PostGIS is enabled, and `0001_01_01_000000_create_users_table.php:26` is a plain
  case-sensitive unique with no lowercasing anywhere on the register/login path — so an engineer writing
  auth, invite-dedupe or account-merge builds on a guarantee the database does not give. **Live.** This
  branch corrected the adjacent `pg_trgm` bullet at `:128` and left `:56` asserting the opposite. Filed by `M1`.
- ~~**`major` · Two of the ten rows in ADR-0002 §D3's isolation-control inventory describe unbuilt mechanisms.**~~
  ✅ **DONE — M46 (2026-08-29). BOTH ROWS REWRITTEN TO AS-BUILT, AND THE ROW UNDERSTATED ITSELF BY TWO SITES,
  ONE OF WHICH IS AN OPERATIONAL HAZARD RATHER THAN A DOCUMENTATION ONE.**
  `docs/adr/0002-multi-tenancy-shared-db-rls.md` (the Realtime and Cache rows, in the as-built voice the Jobs
  row already set — design intent kept as what the control must be when the stack lands, rather than deleted),
  `docs/deployment-infrastructure.md` (four sites) and
  `app/Services/Connectors/ConnectorChannelDirectory.php` (docblock only).
  ⛔ **THE PRODUCTION RUNBOOK PRESCRIBED A COMMAND THAT DOES NOT EXIST.** Its provisioning step told an
  operator to install `php artisan reverb:start` as an auto-restarting Windows service. Measured on this
  tree: `laravel/reverb` is absent from `composer.json`, `reverb` appears in no `artisan list`, and the
  command exits **1**. Following the runbook exactly would have produced a service that fails on every start,
  retried forever by the supervisor. **A documentation defect in a runbook is an operational defect**, and it
  is the reason this row's real severity was higher than its filing.
  ⛔ **AND A CODE COMMENT ASSERTED THE SAME CLASS OF FALSE NEGATIVE, ONE LAYER DOWN.**
  `ConnectorChannelDirectory` justified a local decision with a global claim — that grepping the application
  tree for cache-facade calls finds nothing and that this repository had never written to a cache at runtime.
  There are **three** runtime cache writes, and the third reaches the cache through an **injected repository**,
  so the grep the comment prescribed could not have seen it; that grep also matched the comment itself.
  **A grep over first-party code can only ever report absence from first-party code** — the same trap that
  made ADR-0001's clause false in the row below. ⚠️ The row's own Cache citation was dead, pointing at an
  unrelated amendment. Original filing follows.
  **`major` · Two of the ten rows in ADR-0002 §D3's isolation-control inventory describe unbuilt
  mechanisms.** `docs/adr/0002-multi-tenancy-shared-db-rls.md:129` credits Reverb channel-authorization
  callbacks that *"re-verify the requesting user's tenant membership"* — there is no broadcasting config,
  no `routes/channels.php` and no dependency — and `:132` claims `tenant:{id}:…` Redis cache prefixing,
  where `CACHE_STORE=database`, no KPI caching exists and the only `tenant:{…}` key in the tree is a queue
  rate limiter. **Live.** Sharpened because the adjacent Jobs row *was* rewritten to as-built, training a
  reader to treat uncorrected rows as verified. Filed by `M1`.
- ~~**`major` · The audit spec's exhaustive `users` scope row omits the impersonation boundary events.**~~
  ✅ **DONE — M46 (2026-08-29). THE HEADLINE HELD AND THE ROW UNDERSTATED ITSELF BY THREE FURTHER SITES, ALL
  IN A SECOND DOCUMENT IT DOES NOT NAME.** `docs/audit-compliance-logging-spec.md`'s §1 `users` row now
  carries `impersonation_started` and `impersonation_ended`, with the actor direction stated —
  `auditable_id` is the **impersonated user** and `acting_as_user_id` the operator, so the row reads as *what
  was done to this account, and by whom*. ⛔ **THE SAME UNDERCOUNT RECURS THREE TIMES IN
  `docs/data-dictionary.md`**, which called the `AuditEvent` catalog **eight-valued** where the enum has ten,
  said "see the 8-value catalog above" in the `audits.event` column row, and described the domain-specific
  events by a count two short. All three corrected. **The dictionary was internally consistent and externally
  wrong in three places at once, which is the failure mode a catalog exists to prevent** — and it is why
  fixing only the document the row named would have left three quarters of the defect live. ⚠️ **The row's
  own summary of the spec was imprecise in a way worth recording:** it said the `users` row named `updated`
  and `permission_changed`. `permission_changed` against that alias is a **separate preceding row**, so the
  alias's coverage was already two rows, and reading any one row as the whole scope is how the omission
  survived. ➕ **Filed rather than fixed:** the same table **over-claims** in the other direction. Original
  filing follows.
  **`major` · The audit spec's exhaustive `users` scope row omits the impersonation boundary events.**
  `docs/audit-compliance-logging-spec.md` §1 (~`:28`) names `updated` and `permission_changed` only, while
  `app/Services/Admin/ImpersonationService.php:389-406` records `impersonation_started` /
  `impersonation_ended` against that same alias. A SIEM forwarder or retention rule built from the section
  that exists to be exhaustive drops the highest-privilege events in the ledger. **Live.** Filed by `M1`.
- ~~**`major` · The threat model's `Open` row asserts `APP_PREVIOUS_KEYS` "appears in no `.env.example` and in no document".**~~
  ✅ **DONE — M46 (2026-08-29). NARROWED AT FOUR SITES AND DELIBERATELY NOT CLOSED, EXACTLY AS THE ROW'S OWN
  HEDGE INSTRUCTED.** `docs/security-threat-model.md` (the §5 register row and the §9 residual) and
  `docs/adr/0009-oauth-connector-token-custody.md` (§Context 8, §D9 and the See-also list). The variable is
  declared in `.env.example` with an inline warning naming this exact failure and citing the sub-decision, so
  the "appears nowhere" half is retracted; **the rotation PROCEDURE is genuinely still absent**, and the
  document that owns it defers the connector lane in its own key-rotation section, so the item stays `Open`
  and now says which half is missing. ⛔ **THE SHAPE OF THE ERROR IS THE FINDING, NOT ITS CONTENT: A REGISTER
  WHOSE PURPOSE IS TO TELL AN OPERATOR WHERE TO LOOK TOLD THEM THERE WAS NOTHING TO FIND.** That is worse
  than silence, because the natural response to *"undocumented"* is to conclude the capability is absent and
  stop. ⚠️ **FOUR OF THIS ROW'S SIX CITATIONS WERE THEMSELVES DEAD**, including the `.env.example` range that
  is the row's own refutation — and one of them had already been re-anchored once, by the increment whose
  edit displaced it. ➕ **Beyond the row:** the seam's own citation, repeated in three places, pointed at
  `config/app.php`'s **locale block** — roughly twenty lines above the real entry. All three now cite the
  `previous_keys` key by name, because a line number in a config file is a pointer with a shelf life.
  Original filing follows.
  **`major` · The threat model's `Open` row asserts `APP_PREVIOUS_KEYS` "appears in no `.env.example` and
  in no document".** `docs/security-threat-model.md:100` (repeated `:218` — **was `:217` until M9's own §8 row shifted it, which is exactly the hazard the citation-cluster row below records**; duplicated into
  `docs/adr/0009:31,:83,:168,:290`). It is present on this branch at `.env.example:207-209` with an
  ADR-0009 §D9 warning attached, and discussed in two more documents — so the register is wrong at the
  moment it is ratified, and an operator planning an `APP_KEY` rotation is told not to look for the seam
  that exists. **Live.** ⚠️ **Narrow it, do not close it**: the documented rotation *procedure* genuinely
  is still absent. Filed by `M1`.
- ~~**`major` · ACCESS-MATRIX's verification step 4 sends the reader to the platform host, which the same document proves is a dead end.**~~
  ✅ **DONE — M46 (2026-08-29). THE ROW'S HEADLINE HELD, ITS CITATION WAS FALSE, AND THE DOCUMENT IT
  CORRECTS TURNED OUT TO BE WRONG ABOUT ITS OWN MECHANISM.** `docs/ACCESS-MATRIX.md`, two sites: step 4 now
  signs in at the workspace host, matching step 5; and the warning block's explanation is rewritten.
  ⛔ **THE WARNING BLOCK BLAMED THE WRONG MIDDLEWARE, AND THE ROW DID NOT NOTICE — SO THE OBVIOUS ONE-TOKEN
  FIX WOULD HAVE LEFT A FALSE EXPLANATION STANDING.** Measured on the live group: the tenant routes list
  `InitializeTenancyBySubdomain` **before** `PreventAccessFromCentralDomains`. On the platform host there is
  no subdomain to resolve, so the initialiser throws `NotASubdomainException` and `bootstrap/app.php`'s
  renderer for it returns `redirect(config('app.url'))` — that is the 302. The middleware the document
  blamed never executes, and vendor source shows its refusal is `abort(404)`, which is not a redirect at all
  and could not have produced the observed loop. Nothing in first-party code overrides that. **A document
  that gets the observable right and the cause wrong is worse than one that says nothing: it sends the next
  reader to a middleware that is behaving correctly.** ⚠️ The step-4 citation itself pointed at a shell
  comment **inside a fenced block** — which is one of the two cases the citation-liveness gate added by this
  increment detects mechanically. Original filing follows.
  **`major` · ACCESS-MATRIX's verification step 4 sends the reader to the platform host, which the same
  document proves is a dead end.** `docs/ACCESS-MATRIX.md:446` says sign in at
  `http://localhost:8080/login` as `viewer@demo.test` and inspect the sidebar; `:70-92` records the
  measured finding that Fortify lands on `/dashboard` on the central host, `PreventAccessFromCentralDomains`
  302s it to `/`, and walking to the subdomain afterwards does not rescue it. Step 5 two lines below
  correctly uses a workspace host, so the inconsistency reads as intentional. **Live.** Filed by `M1`.
- ~~**`major` · The README's frontend and design-system command blocks are host commands that cannot run on the host.**~~
  ✅ **CLOSED AS ALREADY FIXED — M46 (2026-08-29), AND NO EDIT WAS MADE TO `README.md` BECAUSE THE ROW'S
  REMEDY IS A NO-OP THAT WOULD RE-AFFIRM THE ONE LINE STILL WRONG.** ⛔ **EVERY CITATION IN THIS ROW IS
  FALSE, AND ALL THREE POINT AT BLANK LINES.** The block was corrected by `1261a73` — *M1 — the pre-merge
  remediation the integration review produced*, 2026-08-18 — and today every frontend and design-system
  command in `README.md` already reads `docker compose exec node …`, under an explicit warning that the
  Windows host has no `rolldown` win32 binding. The `docs/TESTING-GUIDE.md` citation lands on a blank line
  too; the sentence it means begins two lines later. ⚠️ **AND THE ROW'S CLAIM IS OVER-BROAD EVEN AS
  HISTORY — MEASURED, NOT ASSUMED.** Of the five commands it names, **three can in fact run on this host**:
  `type-check` is `vue-tsc` (pure JS), `ds:tokens` is style-dictionary (pure JS), and
  `ds:storybook:build` resolves a rollup-based Vite in the design-system package rather than the root's
  rolldown. Only `build` and `ds:test` genuinely cannot. The container-first instruction stays regardless —
  it is right for parity reasons, not only for capability reasons. ⛔ **THE SHARPEST FINDING IS THAT THE ROW
  WAS FILED AGAINST A TREE THAT NO LONGER EXISTED, AND A LATER INCREMENT HAD ALREADY NOTICED.** `M27`
  rewrote the same block for a different row and recorded in its own commit message that these line numbers
  were rotten — and did not close this row. **A stale row survives being read; it only dies when someone
  opens its citations.** ➕ A live residual was found in the corrected block and is filed as its own row
  below. Original filing follows.
  **`major` · The README's frontend and design-system command blocks are host commands that cannot run on
  the host.** `README.md:66-74` — `npm run build`, `type-check`, `ds:tokens`, `ds:storybook:build`, `ds:test`.
  Only `npm run dev` carries the "(or use the `node` compose service)" parenthetical, and `:19` calls host
  Node optional, so the rest read as host commands; `docs/TESTING-GUIDE.md:22-23` states the opposite (no
  `pdo_pgsql`, no rolldown win32 binding). **Live**, on the platform the README explicitly documents. Filed by `M1`.
- ~~**`major` · ADR-0017 says the threat model carries no SSO and no isolation-topology rows; it carries both.**~~
  ✅ **DONE — M46 (2026-08-29). THE ROW IS RIGHT ABOUT TWO THIRDS OF THE SENTENCE, AND ITS OWN REMEDY WOULD
  HAVE DESTROYED THE THIRD.** `docs/adr/0017-tenant-isolation-tiering.md`, one bullet, rewritten as a **split
  verdict** rather than deleted. ⛔ **THE TOPOLOGY CLAUSE IS STILL TRUE.** Searching
  `docs/security-threat-model.md` for *topology*, *dedicated database*, *shared-schema* or *isolation tier*
  returns **nothing**: the five rows the row points at are extraction and cross-tenant-foreign-key rows —
  about getting data out of the shared database, not about the choice of database, which is the thing this
  ADR is actually about. Deleting the sentence would have fixed a false claim by throwing away a true one,
  and would have replaced a reviewer who blocks on shipped work with a reviewer who believes the topology has
  been threat-modelled. ⚠️ **BOTH REFUTING CITATIONS WERE MIS-ANCHORED.** One range opens on three Google
  sign-in rows and reaches only the first SAML row of fourteen; the other names four isolation rows where the
  section carries five. **The row named the right defect through the wrong coordinates** — which is why the
  rewrite cites the SAML table by its lead-in sentence and by its row count instead of by a line range.
  Original filing follows.
  **`major` · ADR-0017 says the threat model carries no SSO and no isolation-topology rows; it carries
  both.** `docs/adr/0017-tenant-isolation-tiering.md:73`, refuted by
  `docs/security-threat-model.md:171-179` (the SAML table) and `:49-52` (four isolation/extraction rows
  that cite this very ADR). A reviewer using the ADRs as the map of what has been threat-modelled blocks
  the merge on, or duplicates, work that already shipped. **Live** — the file was edited after P2b, so this
  bullet was left behind rather than never revisited. Filed by `M1`.
- ~~**`major` · A second raw-HTML sink shipped in this branch, and the escaping contract says there is
  none.**~~ ✅ **DONE — M57 (2026-09-01). THE ROW'S EVIDENCE WAS EXACT AND ITS FRAMING WAS WRONG IN BOTH
  DIRECTIONS, WHICH IS WHY IT IS WORTH RESTATING AS A CLASS.** Every citation held, including the one that
  mattered most: the header's own comment claimed the slot *"arrived already escaped with ENT_QUOTES"*, and
  it does not — **because of a control this application deliberately turned on**. `Markdown::render()`
  *replaces* `EncodedHtmlString`'s encoder for the whole render with a three-character map (`[`, `<`, `>`),
  so `withSecuredEncoding()` — the H23a4 mitigation that closed the markdown-injection row — is what removed
  quote escaping from every echo in a mail view. ⛔ **THE ROW UNDERSTATES ITSELF: `{!!` IS NOT THE HAZARD.**
  `{{ }}` runs the same map, so it is equally unsafe in an attribute; the header held two more (`href`,
  `src`) and the unpublished vendor components hold others. A gate counting `{!!` would have been green
  against this defect, and `scripts/mail-attribute-lint.php` therefore keys on *a Blade echo inside a quoted
  attribute*, with one of its four controls written as `{{ }}` for exactly that reason. ⚠️ **And it
  OVERSTATES itself: the two sinks are not two defects.** The bare `{!! $slot !!}` is text context, where
  the map has already escaped `<` and `>` and re-escaping would double-encode — it is correct and must stay.
  ➕ **Measured, not deduced, and it inverted the expectation recorded in the claim:** the injected attribute
  **survives** `CssToInlineStyles`, which normalises the break-out *into* a live second attribute rather than
  repairing it. ➕ **A consequence for the test, worth more than the fix:** a text assertion cannot
  distinguish fixed from broken here — ` onerror=` appears inside the `alt` value either way, and the
  inliner re-quotes with `'` rather than emitting `&quot;` — so the test asserts the rendered `<img>`'s
  **attribute set** by equality. The first draft asserted the `&quot;` form the PDF surface produces and was
  red against a correct fix. Shipped: `app/Support/Mail/MailAttribute.php`, the header, the linter and its
  `composer.json`/`ci.yml` wiring, `BrandedMailRenderTest` + `tests/Unit/Mail/MailAttributeTest.php`, and
  four documents — Doc #26 §5.2 (new), the threat model's §7 table and §9 item 9, `docs/testing-strategy.md`
  and the `AppServiceProvider` call site that causes it. **Latent when found and recorded as such**: nothing
  in `app/` creates or updates a `Tenant` row's name. Original filing follows.
  **`major` · A second raw-HTML sink shipped in this branch, and the escaping contract says there is
  none.** `docs/piping-output-encoding-design.md:151` asserts *"zero `{!!` exists in application code
  today"*, status "(holds)", and `:180` makes any second sink a contract change. The new
  `resources/views/vendor/mail/html/header.blade.php:31,33` carries two — `:31` interpolating blind into an
  HTML **attribute** (`alt="{!! trim($slot) !!}"`). Its premise that the slot arrived escaped does not hold:
  `Markdown::withSecuredEncoding()` replaces the echo encoder with a three-character map that does not
  include `"`, and the value is `$tenant->name`. **Latent** — no user-facing write route for `tenants.name`
  was found — but the asserted invariant is false either way, and `BrandedMailRenderTest.php:122` only
  pins the unquoted case. Filed by `M1`.
- **`minor` · The framework's own mail components interpolate into attributes the same way, and the M57
  gate cannot reach them.** Filed by M57 (2026-09-01) at the moment the scope was decided, not afterwards.
  We publish exactly **one** override under `resources/views/vendor/mail/`; every other component renders
  from `vendor/laravel/framework`, and `mail/html/button.blade.php` puts `$url` into an `href` with a plain
  Blade echo — which, under `withSecuredEncoding()`, escapes no quote. `scripts/mail-attribute-lint.php`
  scans `resources/views/` only, so it is structurally blind to them. ⚠️ **Not reachable today, and the
  measurement is the reason rather than the assumption**: all six `->action()` call sites in
  `app/Notifications/` pass an application-built URL (`TenantUrl::to()`, signed routes) and none interpolates
  free text; `layout.blade.php`'s only attribute echo is the app locale. **The obvious remedy is worse than
  the defect:** publishing the components to bring them in scope costs a vendor file to keep in sync on
  every Laravel upgrade, which `resources/views/mail/notification.blade.php`'s docblock already argues
  against for exactly this reason — it is why only one file is published. Candidates if it ever becomes
  reachable: publish only the component that takes a free-text URL, or assert the attribute set of the
  rendered button the way `BrandedMailRenderTest` now asserts the header's. **Live, and deliberately not
  fixed.** Filed by `M57`. **Latent** — the row's own measurement says not reachable today — every call site passes an application-built URL; it needs a component taking free text, judged by `M65`.
- ~~**`major` · The data dictionary states "No CHECK pairs the two" for `audits.user_id` / `acting_as_user_id`.**~~
  ✅ **DONE — M46 (2026-08-29). THE ONLY ONE OF THE EIGHT DOCUMENTATION-TRUTH ROWS WHOSE EVERY LINE NUMBER
  WAS STILL INTACT, AND THE ONLY ONE WHOSE PRESCRIBED REMEDY NEEDED NO CORRECTION.** `docs/data-dictionary.md`,
  two sites. The §13 design note now names `audits_acting_as_not_self_check` and its predicate, and keeps the
  `nullOnDelete` reasoning — which is sound, and simply belongs to the **other** constraint. ⚠️ **THE
  DIAGNOSIS WAS EXACT AND IS WORTH RESTATING AS A CLASS:** the document transcribed the migration's argument
  for the constraint that was **rejected** instead of the one that was **added**, and both live in the same
  docblock under adjacent headings. A reader comparing doc to migration would have found matching prose and
  concluded agreement. ➕ **Beyond the row:** §13 omitted `audits_event_check` entirely while the same section
  enumerates CHECK constraints elsewhere, so its absence read as "there is no such constraint" — now named,
  with the note that it is generated from the enum once, at the migration that creates it, so widening it
  needs its own migration. Original filing follows.
  **`major` · The data dictionary states "No CHECK pairs the two" for `audits.user_id` /
  `acting_as_user_id`.** `docs/data-dictionary.md:630`, refuted by
  `2026_08_09_000001_add_acting_as_user_id_to_audits.php:98-101`
  (`audits_acting_as_not_self_check`). The doc recorded the migration's reasoning for the **rejected**
  constraint (`:34`) rather than the one that shipped (`:40`), so a backfill or fixture setting
  `acting_as_user_id = user_id` gets a 23514 from a constraint the canonical schema reference denies.
  **Live**, and the section enumerates CHECKs exhaustively elsewhere, so the negative reads as complete. Filed by `M1`.
- ~~**`major` · The data dictionary states a `uuidv7()` DATABASE-SIDE DEFAULT on thirty table rows, and no migration sets one.**~~
  ✅ **CLOSED — `M58` (2026-09-01). THE EVIDENCE HELD EXACTLY AND THE SCOPE WAS UNDERSTATED BY THREE TIMES,
  IN A DIRECTION THE ROW DOES NOT MENTION.** The row's four assertions were each measured and each held:
  this server is **PostgreSQL 17.5**, and **0 of 37** `uuid` `id` columns carried a `column_default`. But
  the defect is not `uuidv7()` — it is **any function named in the `Default` column**, and the larger half
  is `now()`. Measured against a freshly migrated schema: **93 cells covering 106 columns** — 32 `id` cells
  claiming `uuidv7()`, 74 timestamp cells claiming `now()` — across **two** documents, the second being
  `docs/multi-tenancy-rbac-design.md`, which the row does not name. ⛔ **AND EXACTLY TWO FUNCTION-SHAPED
  CELLS IN THE WHOLE CORPUS ARE TRUE:** `audits.created_at` and `feedback_reports.submitted_at`, both from
  `->useCurrent()`. **A sweep over `now()` would have falsified two correct rows while repairing
  sixty-one** — which is the whole argument for closing this with a gate rather than a replace.
  ⚠️ **THE ROW'S OFFERED FORK IS FALSE, AND THE LOOKUP IT DECLINES TO DO IS WHAT DISSOLVES IT.** *"Either
  the column rows say 'application-generated' or the preamble's conditional is repeated per row; choosing
  which is a documentation decision, not a lookup."* Repeating the conditional would repeat an **unresolved
  choice on a system that resolved it years of increments ago**: `App\Models\Concerns\HasUuidv7` mints the
  key in PHP across 45 models, and reason (b) in the preamble's own rationale — the offline client needs the
  key before the row reaches the server — makes client-side generation correct **independently of the server
  version**, so a DB-side default would never have been the thing that filled the column even on PG 18.
  ➕ **BEYOND THE ROW, IN THE SAME PARAGRAPH:** the preamble's *"Two tables deliberately deviate … and use
  `bigint identity`"* was itself stale — there are **five** (`submission_answer_index`, `usage_counters`,
  `legacy_overrides`, `point_awards`, `badge_awards`). Corrected with it.
  **The gate is `tests/Feature/Migrations/DocumentedDefaultDriftTest.php`**, which reads
  `information_schema` on a `RefreshDatabase` schema — deliberately a test and not a lint script, because
  the defect *is* a document and a static twin would have to infer defaults from `->default()` /
  `->useCurrent()` / raw `DB::statement` against a question the catalog answers exactly. Proved red four
  ways, and **the one that matters is the third**: sweeping the *true* `audits.created_at` cell as well
  turns the discriminator red while the phantom sweep stays green, so the gate is not merely banning the
  word `now()`. Original filing follows.
  Filed by M46 (2026-08-29) while closing the `audits` CHECK row, because it is the same class one order of
  magnitude larger: **the canonical schema reference asserting a schema property the database does not have.**
  `docs/data-dictionary.md` carries `uuidv7()` in the `Default` column of thirty `id` rows. Measured against
  `database/migrations/`: **no migration declares any database-side UUID default at all** — every primary key
  is `$table->uuid('id')->primary()` with the value generated in PHP, which is also what the offline client
  requires, since it mints the key before the server sees it. ⚠️ **The preamble is not wrong, and that is
  what makes this survivable rather than trivial**: the Primary-key-strategy paragraph explicitly conditions
  the native default on PostgreSQL 18+ and says to *"generate UUIDv7 client-side … and remove the DB-side
  default"* otherwise. **The thirty column rows carry no such condition**, and a reader inventorying defaults
  from the per-table rows — which is what those rows are for — gets it wrong thirty times. **The fix is
  mechanical but not one-line**: either the column rows say "application-generated" or the preamble's
  conditional is repeated per row; choosing which is a documentation decision, not a lookup. **Live.** Filed by `M46`.
- **`minor` · The documented-default gate reads FUNCTION-shaped cells only, so a documented LITERAL that
  disagrees with the database is invisible to it.** Filed by `M58` (2026-09-01) at the moment the scope was
  decided, rather than left as a comment inside the test nobody re-reads.
  `tests/Feature/Migrations/DocumentedDefaultDriftTest.php` compares a `Default` cell to the live schema
  only when the cell names a function — `now()`, `uuidv7()`. A cell reading `'{}'::jsonb`, `false`, `0` or
  `'trial'` is skipped. ⚠️ **That is not a small remainder**: it is most of the column, and the corpus
  carries hundreds of them. It was scoped out because the literal comparison is the noisy half — Postgres
  reports `'local'::character varying` where a document reasonably writes `'local'`, `false` where a
  document writes `No`, so the check needs a normalizer per type rather than a presence test, and a first
  draft would fire on formatting instead of on drift. **The honest sizing is "a second gate", not "widen
  the predicate".** ⛔ **And the class is proven live, not hypothetical** — the 106 columns this increment
  repaired were exactly this defect wearing its function-shaped half. **Live.**
- **`minor` · `submission_geo_index` is a real table and the data dictionary does not mention it at all.**
  Found by `M58` (2026-09-01) while reconciling the primary-key-strategy preamble's `bigint identity`
  deviation list against the schema. The table exists in the migrated database with a `bigint` `id`, and
  `docs/data-dictionary.md` — whose own header calls itself *"the source of truth for column-level
  shape"* — carries no section for it, so it is absent from the deviation list, from the table of contents
  and from every enumeration in the document. ⚠️ **Filed rather than fixed deliberately**: documenting a
  table means recovering its real semantics, its PII classification and its RLS shape, which is its own
  row's worth of work and not a line in a preamble. ⚠️ **And the count it distorts is one this increment
  just corrected**, so the deviation sentence is right about the tables it names and still not a census —
  the same failure, one level up, as the row `M58` closed. **Live.** Filed by `M58`.
- ~~**`major` · The README prescribes a design-system command that cannot work in the service it names.**~~
  ✅ **DONE — M59 (2026-09-02). THE ROW'S EVIDENCE HELD AT ALL FIVE CITATIONS AND ITS SEVERITY ARGUMENT
  WAS FALSE.** The block now builds in `node` and scans in the `e2e` glibc image, in `ci.yml`'s shape —
  measured end to end at **42 suites / 303 tests**, which is the `docs/gate-baselines.md` figure exactly.
  ⛔ **THE ROW IS WRONG ABOUT WHAT A READER SEES, AND THAT WAS ITS WHOLE SIZING ARGUMENT.** It predicts
  *"a native-module error from inside a container, not a missing-server message."* Measured: it is
  **exactly a missing-server message**, and a helpful one — `test-storybook` names the `--url` flag and
  links its own docs. Reason (2) fires first and reason (1) is never reached on that path. The row is
  still `major`, for a reason it does not give: the command is the **only** line in the block that cannot
  be made to work by fixing the reader's tree.
  ⚠️ **REASON (1) IS REAL AND HAD TO BE PROVED SEPARATELY, BECAUSE THE OBVIOUS TEST GIVES THE WRONG
  ANSWER.** Run bare, the scan fails on an **absent** browser — which would imply "install it" as the
  fix. So it was installed: `playwright install` inside that container warns it is *"downloading fallback
  build for ubuntu24.04-x64"*, and the scan then reports `spawn ... ENOENT` **with a 189 MB binary present
  and executable**, 0 of 42 suites. **Installing the browser there is not the fix; changing images is.**
  ⛔ **AND THE REMEDY IT GESTURES AT IS WRONG.** *"The working shape … is two steps, not one"* — `ci.yml`
  is **five**, three of them one-time installs, and its fifth is a two-process orchestration. It is also
  not transplantable: every native binding on disk is linux-only, so the host recipe needs a host
  `ds:install` first, which J4c measured as **breaking the node container's Vite**.
  ➕ **THREE THINGS THE ROW DOES NOT MENTION, EACH FOUND BY RUNNING THE BLOCK RATHER THAN READING IT.**
  **(1)** `--maxWorkers` is **local-only and not optional**: jest defaults to one worker per core and the
  container dies `ENOMEM` in **34 of 42 suites** — at *load*, so every test that runs still passes and the
  tail looks survivable. CI needs no cap and therefore never showed this.
  **(2) `ds:storybook:build` FAILS AND EXITS `0`** against an incomplete package tree — Storybook's
  anonymous crash-report prompt runs after the error and swallows the status. Filed as its own row below.
  **(3)** `ds:test` could never have been made to work as a one-liner: the dev-server script that would
  serve `:6006` exists in the package and has **no root alias**. Filed below.
  ⚠️ **The duplicate command block in this file is now a pointer to `README.md`** — that duplication is
  what let the README rot while the working recipe sat here, in a file no reader of the README opens.
  Gated by `tests/Feature/Docs/DocumentedCommandDriftTest.php`, proved red four ways through
  `scripts/mutate.php`; the fence-marker control fired a **floor** at 25 assertions rather than 27.
  ⚠️ **AND THIS BRANCH ROTTED A CITATION AND THE GATE CAUGHT IT** — the README grew 22 lines below the
  block, which pushed a cited line into a fenced block and took the ledger to 19 over its ceiling of 18.
  ⛔ **THAT IS THE OPPOSITE OF WHAT WAS PREDICTED**, and worth keeping: the prediction said
  `citation-liveness-lint` could not see this class, because it checks a cited line is ALIVE and never
  that it still says what the citing sentence claims. Both halves are true and the conclusion did not
  follow — *inside a fence* is an **aliveness** rule, so the gate caught this instance of an accuracy
  defect. It would have missed a line that merely moved and stayed prose. Repaired; only the citation
  this branch moved was touched. Original filing follows.
  **`major` · The README prescribes a design-system command that cannot work in the service it names.**
  Filed by M46 (2026-08-29), found inside the block a now-closed row wrongly claimed was still broken — see
  the README row above, which was stale but whose neighbourhood was not. `README.md`'s design-system block
  runs the axe suite as `docker compose exec node npm run ds:test`. It cannot work, for two independent
  reasons. (1) The `node` service is `node:24-alpine` — **musl** — and `CLAUDE.md`'s own gate table records
  that a glibc Chromium fails `ENOENT` there with the binary present and executable. (2) `ds:test` resolves
  to `test-storybook` with **no `--url`**, so it requires a Storybook server already listening on `:6006`,
  and nothing in the block starts one. `ci.yml`'s axe job does it correctly — glibc runner, static build
  served, `wait-on`, then `test-storybook --url` — so the working shape exists and is two steps, not one.
  ⚠️ **Sized `major` because it is the one line in that block a reader will actually run**, and its failure
  is the confusing kind: a native-module error from inside a container, not a missing-server message. Filed by `M46`.
- ✅ **DONE — M71 (2026-09-05). THE ROW'S EVIDENCE HELD AND ITS STATED CAUSE WAS FALSE, WHICH CHANGED
  THE DOCUMENTATION BUT NOT THE FIX.** `packages/design-system/package.json`'s `build-storybook` now
  carries `--disable-telemetry`, read out of the installed Storybook 8.6.18 bundle rather than assumed,
  and `tests/Feature/Docs/DocumentedCommandDriftTest.php` gained a fifth arm asserting the flag wherever
  `README.md` prescribes a build — with a discovery floor, because the arm could otherwise pass on an
  empty set, and a discriminating pair in the graph-resolution arm so a README sweep cannot silently
  empty it.
  ⛔ **NOTHING SWALLOWS THE STATUS, AND THE README SAID IT DID.** The preset failure is thrown as
  critical and `withTelemetry` rethrows it unconditionally, so the CLI's own `.catch(() => exit(1))`
  would fire. What happens is **abandonment**: the bundled `prompts` base class binds only a `keypress`
  listener and no readline `close`/EOF handler, so on a non-TTY stdin the crash-report confirm never
  settles, the rethrow is never reached, and Node exits on a drained event loop. Answering the prompt is
  not a fix and neither is a retry — removing it from the code path is, which is what the flag does.
  ⚠️ **THE ROW'S CAUTION WAS BACKWARDS.** It asked for the flag to be checked against CI first; CI
  **cannot** reach this defect at all, because `promptCrashReports` returns immediately on
  `if (process.env.CI) return` and the axe job installs the dependencies before building. CI already
  got exit 1. The flag's only effect there is three fewer outbound telemetry POSTs.
  ✅ **Two controls, and neither can pass for the other's reason.** Removing the flag reddens the new
  arm **alone**; breaking the build-invocation selector reddens the arm **and** the discriminator.
  ⚠️ The README warning was rewritten **in place at the same line count** — `docs/feature-backlog.md`
  cites `README.md:169-172` and `citation-liveness-lint` is at its ledger ceiling with zero headroom, so
  an inserted line would have reddened the gate. Measured before the edit, not after.

- ~~**`minor` · A failing `ds:storybook:build` exits `0`, so every check of its status is vacuous.**~~ Filed
  by M59 (2026-09-02) at the moment it was measured, not fixed here because the fix is upstream-shaped.
  Against an incomplete `packages/design-system` tree the build dies with
  `Cannot find module '@storybook/vue3-vite/preset'` and **returns exit code 0**: Storybook's anonymous
  crash-report prompt runs after the error and swallows the status. ⚠️ **This is the M27 class pointing
  the other way** — that row's finding was *"the failure prints a success after it, trust the exit code"*,
  and here the exit code is the liar. Both warnings now sit together in `README.md`, which is a mitigation
  and not a fix. **The real remedy is `STORYBOOK_DISABLE_TELEMETRY=1` or `--disable-telemetry` in the
  package script**, which needs checking against `ci.yml`'s axe job before it is set globally — CI is on a
  clean tree and has never hit this, so the change is untested where it matters most. **Live.** Filed by `M59`.
- ~~**`minor` · The design-system dev server has no root alias, so the one script that would make the axe~~
  ✅ **DONE — M72 (2026-09-05). THE ARTEFACT FACT HELD AND THE REASON TO CARE WAS ALREADY DEAD.**
  `M71` was right that *"which nothing currently documents"* is false — `packages/design-system/README.md`
  has documented `npm run storybook` all along. What survives is that the alias set and the script set
  were not a bijection, and the consequence was larger than either row said: **there was no
  browser-reachable path to the design system in ANY form.** `storybook dev` binds 6006 and
  `docker-compose.yml` published only 5173; `storybook-static` is gitignored **and** outside nginx's
  docroot, so the `web` service cannot serve it either; and a static Storybook does not open usefully
  over `file://`. On a project whose README opens with *"One system, every page"*, **39 components and 42
  story files** sat behind a port nothing forwarded. ✅ Shipped: 6006 published, `ds:storybook` added,
  `--host 0.0.0.0` for consistency with the `node` service's own vite invocation, and the mapping
  sentence replaced by a real table. ⛔ **AND THE GATE IS THE POINT.**
  `tests/Feature/Docs/DocumentedCommandDriftTest.php` reads `README.md` at `base_path()` and **never
  opens the design-system README**, which is why that file's defect was invisible from the day it was
  written; a new arm joins the two facts nobody had joined — *a prescribed command resolving to a
  port-binding dev server must have that host port published by the compose service it runs in* — with
  **both numbers derived**, the port from the package script and the publication from the compose file.
  ✅ **Two controls: removing the alias reddens the alias arm; removing the port reddens the new arm
  ALONE**, which is what says it is not decorative.
  one-liner work is unreachable from the vocabulary every document uses.** Filed by M59 (2026-09-02).
  `packages/design-system/package.json` carries `storybook dev -p 6006 --no-open`; the root `package.json`
  aliases `ds:install`, `ds:tokens`, `ds:storybook:build` and `ds:test` and **not that one**. ⚠️ **This is
  why the closed row above could never have been fixed by "start a server first"** — there was no
  documented way to say it. A `ds:storybook` alias would also give the component library a local preview,
  which nothing currently documents. **Not urgent** — the merge gate scans a static build and should keep
  doing so. **Live.** Filed by `M59`.
- **`minor` · Three gate invocations fetch `http-server` from the network at run time, and nothing
  declares it.** Filed by M59 (2026-09-02). `ci.yml`'s axe job and both halves of the README recipe reach
  it through `npx`, and it appears in no `package.json` — so the merge-blocking accessibility gate has an
  undeclared, unpinned, network-fetched dependency. `concurrently` and `wait-on` are at least present in
  the root tree. **The remedy is one devDependency line**, but it belongs with a decision about which
  package owns it, and the axe job is the wrong place to be experimenting. **Live.** Filed by `M59`.
- **`minor` · The command gate reads `README.md` only, and three other documents carry runnable command
  blocks.** Filed by M59 (2026-09-02) at the moment the gate shipped, so its scope limit is a filed
  constraint rather than a comment nobody re-reads. `docs/TESTING-GUIDE.md`, `docs/ACCESS-MATRIX.md` and
  `docs/deployment-infrastructure.md` all prescribe shell commands and none is scanned. ⚠️ **Widening it is
  a bigger change than it looks**: the `docker compose exec <musl service>` arm is meaningful only where a
  document prescribes *this* stack, and a deployment runbook naming a production host would produce false
  positives on every line. **The corpus needs choosing before the constant is widened. Not live** — a
  stated limit, filed so it cannot be forgotten. Filed by `M59`. **Not live** — a coverage gap that finds nothing today: none of the three documents carries a command any arm of the gate would fail, judged by `M65`.
- ~~**`minor` · Share-slug LOOKUP is case-sensitive while share-slug STORAGE is lowercase-only, so a
  mixed-case share URL 404s instead of resolving.**~~
  ✅ **DONE — M61 (2026-09-02), AND THE ROW'S REMEDY WAS WRONG IN A WAY THAT WOULD HAVE SHIPPED A WORSE
  DEFECT THAN THE ONE IT CLOSED.** Evidence held in substance and was wrong in its vocabulary: **there is
  no `share_slug` anywhere in executable source** — the column is `forms.public_slug`, and `share_slug`
  appears only in this file, `PROGRESS_ARCHIVE.md` and `lane-a.md`. Both named resolvers were unlowered as
  described. ⚠️ *"Writes are constrained by the regex, so no uppercase slug can be stored"* is true of the
  HTTP surface and **stated as if it were an invariant of the column** — `FormService::setShareSettings()`
  wrote the value verbatim, and seeders plus `tests/Pest.php`'s `guestForm()` write through
  `$form->update()`, bypassing validation entirely. A **third** unlowered resolver the row does not name
  (`FormSlug::isTaken()`) is filed below.
  ⛔ **THE PRESCRIBED REMEDY — *"one call at each of the two lookups"* — TURNS THE 404 INTO A 200 AND
  LEAVES THE MIS-CASED URL IN PLACE, AND THAT URL IS A STORAGE KEY IN FOUR CLIENT SYSTEMS:** the service
  worker's `guest-shell-html` Cache Storage entry (Workbox keys by full request URL), the Dexie
  `draft_answers` compound primary key, the outbox row's `slug` column, and the installed PWA's
  `id`/`start_url`/`scope`. The raw casing reached all four because the mint action emitted the **request
  path** as `slug` while the resume action beside it emitted the canonical value — the two arms disagreed
  by construction. Concretely: install from `/f/Clinic-Intake`, the shell caches under that URL,
  `start_url` resolves to `/f/clinic-intake`, and **the installed app is a cache miss offline** — the one
  trade `brand-cache.ts` argues in its own header must never be made.
  **Shipped instead:** a lookup lowered through a new `FormSlug::forLookup()` (lowercase ONLY — deliberately
  not `normalize()`, which is `Str::slug()` and would transliterate `/f/clinic_intake` and `/f/Clinic Intake`
  into aliases the write side never reserved and `isTaken()` never de-duplicated, leaving `->first()` to
  choose between two forms), then a **301 to the canonical URL placed AFTER the existing 404 gates** —
  hoisted above them the redirect becomes a slug-existence oracle, which is exactly what both controller
  docblocks say those gates return 404-never-403 to prevent. The query string is preserved because H7 URL
  prefill rides it, so a query-dropping redirect would silently deliver an un-prefilled form: wrong data
  rather than a visible error. `setShareSettings()` now lowers what it is handed, above the audit arrays,
  which is the precondition that makes the lookup deterministic against a case-sensitive unique index.
  ⚠️ **The manifest route deliberately does NOT redirect** — its `id` is explicit so a mis-cased URL cannot
  fork an installed app, and serving 200 is what makes the canonical `scope` a property a test can pin.
  ✅ **The row was right that no migration is needed, and this fix creates the argument for one** — filed.
  **16 behavioural cases across five suites, including one oracle guard per 404 gate; 9 mutations, 8 CAUGHT
  and 1 SURVIVED as predicted in writing beforehand.** Filed by `M46`.
- **`minor` · The database's uniqueness domain and the runtime's resolution domain now disagree about
  case, and `FormSlug::isTaken()` is the third resolver.** Filed by M61 (2026-09-02) at the moment the
  disagreement was created, not found later. `forms_tenant_id_public_slug_unique`, the `Rule::unique` in
  `UpdateFormShareRequest`, and `FormSlug::isTaken()`'s `where` are all case-SENSITIVE; the runtime lookup
  is not. **Nothing can create the ambiguous pair today** — the share request's regex refuses uppercase and
  `setShareSettings()` lowers what it is handed — so this is `minor` and `isTaken()` is latent rather than
  live. ⚠️ **But the structural fix is a functional unique index on `lower(public_slug)`, which is exactly
  the migration the closed row above said this remedy did not need.** Both statements are true and worth
  keeping side by side: the fix did not need it, and the fix is what makes the case for it. Fold
  `Rule::unique` and `isTaken()` together — they are one finding seen twice. **Live as a divergence, not as
  a reachable defect.** Filed by `M61`. **Latent** — needs a mixed-case slug to exist, and every writer in the tree emits lowercase, judged by `M65`.
- **`minor` · A pre-existing mixed-case `public_slug` row would have been taken dark by M61, and nothing in
  the repository can tell whether one exists.** Filed by M61 (2026-09-02). Before the change such a row was
  reachable at its own casing; after it, `forLookup()` lowers every request and the row matches **nothing**.
  Unlikely — the regex has refused uppercase since I1 and the XLSForm importer normalizes — but the failure
  is silent, and "unlikely" is not "checked". M61 ran the go/no-go
  (`select … from forms where public_slug <> lower(public_slug)`) against this host and it was empty; **this
  host's database is behind the migrations and is not any deployed one**, so the check is owed again per
  environment. The remedy is a lowering data migration guarded by a collision check
  (`group by lower(public_slug) having count(*) > 1`). ⛔ **Reject the code-level alternative** — an
  exact-match-then-lowered two-step lookup costs a second query on every 404 probe, forks the resolution
  rule permanently, and still leaves the legacy row unreachable at its lowercase spelling. **Not live here;
  a deployment obligation.** Filed by `M61`. **Not live** — a deployment obligation rather than a defect here; every writer in the tree emits lowercase, judged by `M65`.
- ~~**`minor` · Nothing proves the offline path M61's redirect exists to protect.** Filed by M61~~
  ✅ **DONE — M72 (2026-09-05), AND BUILDING THE PROOF FALSIFIED WHAT WAS BEING PROTECTED.** ⛔ **MEASURED
  AT THE BROWSER: after a mis-cased entry the shell cache holds TWO keys, not one** —
  `/f/clinic-intake` at `status 200, type 'basic'` and `/f/Clinic-Intake` at `status 0,
  type 'opaqueredirect'`. The belief — the row's, and the reasoning in `sw.ts` — was that
  canonicalization leaves a single key. It does not: the sibling `guest-schema` route filters with
  `CacheableResponsePlugin({ statuses: [200] })` and the `/f/*` navigation route does not, so Workbox's
  status filter never runs there. ✅ **AND THE GUARANTEE HOLDS ANYWAY, BY A MECHANISM NOBODY HAD
  DESCRIBED**: offline, a mis-cased entry still renders, because the browser follows the cached
  opaqueredirect to the canonical entry. So this is a correct outcome nobody had established, plus a junk
  entry consuming one of `maxEntries: 20` — filed as its own row below, since the fix is in
  `resources/public-runtime/sw.ts`, a hub file this batch's single budget had already been spent on.
  ⚠️ **ONE CLAUSE OF THE ROW IS FALSE AND TAKING IT AT FACE VALUE WOULD HAVE PRODUCED A DUPLICATE TEST**:
  *"nor that an installed PWA launched at its `start_url` finds the shell offline"* implies zero
  coverage, and `:78-82` of that spec already proves the offline render for the canonical entry. The
  uncovered thing was the **mis-cased precondition**. ⛔ **THE SEQUENCE IS PART OF THE TEST**: the
  mis-cased navigation happens only after the SW claims the client, because the spec's own comment
  records that the first navigation is uncontrolled — mis-casing first passes on an empty cache exactly
  as it would with the 301 deleted. ✅ Nine tests green across mobile/tablet/desktop; the deliberate
  defect in `GuestFormController::mint`'s 301 turns the new test **red and the other two green**.
  ⚠️ **CONTROL LIMIT, STATED**: Playwright aborts a test at its first failed assertion, so that control
  proves the redirect assertion and not the two cache-shape assertions beneath it; those were measured
  live by probe instead. `scripts/mutate.php` cannot reach a Playwright spec.
  (2026-09-02) at the moment the gate shipped, so the limit is a filed constraint rather than a comment
  nobody re-reads. No suite asserts that after a mis-cased entry `caches.open('guest-shell-html').keys()`
  holds the **canonical** URL and not the mis-cased one, nor that an installed PWA launched at its
  `start_url` finds the shell offline. That is the failure the whole canonicalization exists to prevent,
  and after M61 it is established only by reasoning from `sw.ts`'s navigate-mode route and the manifest's
  `start_url`. ⚠️ **Pest cannot reach it** — Cache Storage is a browser API — and
  `tests/e2e/public-runtime-offline.spec.ts` already carries the offline harness, so the row names the
  assertion rather than the approach. **Live.** Filed by `M61`.
- **`minor` · `docker compose run --rm e2e` does not work on this host as three documents prescribe it,
  and the most likely wrong form EXITS 0.** Filed by M61 (2026-09-02), measured while running the two
  specs its diff reached. `CLAUDE.md`'s gate table, `docs/ux/design-system-reference.md` and
  `docs/ux/exceptions-log.md` all give the bare command. Three prerequisites, none of them stated
  anywhere, and they fail in descending order of honesty:
  ⛔ **(1) The service `entrypoint` is already the Playwright CLI, so the natural
  `docker compose run --rm e2e npx playwright test …` passes `npx` as playwright's SUBCOMMAND. It prints
  `error: unknown command 'npx'` and RETURNS EXIT CODE 0** — the succeeds-on-empty-input class this
  project has now measured four times, and the one that would silently launder a skipped e2e run into a
  claim. The working form is `docker compose run --rm e2e test <spec> …`.
  ⚠️ **(2) Node cannot resolve `acme.localhost` inside the e2e image, and `curl` can** — so a hand check
  says the stack is reachable while Playwright's `webServer` probe decides it is not, tries to boot
  `php artisan serve`, and dies on `php: not found`. The failure names PHP and the cause is DNS.
  ⚠️ **(3) `public/hot` must be removed and `npm run build` run first.** With the Vite dev server up,
  Laravel emits `@vite` pointing at `localhost:5173`, which lives in the `node` container — unreachable
  from the e2e container's `network_mode: service:app` namespace. The symptom is `global-setup` timing
  out on `getByLabel('Email')`, which reads as a broken login page.
  **The remedy is a `docker/e2e` entrypoint wrapper or a documented recipe, plus a non-zero exit on an
  unknown subcommand.** ⚠️ **Sized `minor` only because CI is the authority for e2e** — but (1) is the
  kind of green that gets quoted in a claim. **Live.** Filed by `M61`.
- ~~**`minor` · The audit spec credits the `submission` scope with two events that are emitted nowhere.**~~
  Filed by M46 (2026-08-29) rather than fixed, because the correct direction is a decision this increment
  should not take alone. `docs/audit-compliance-logging-spec.md` §1 lists `deleted` and `restored` for
  `submission`. Measured: the only `('submission', …)` pairs written are `created`, `updated` and
  `exported`; `AuditEvent::Restored` is emitted against `form`, never `submission`. **The `Submission` model
  does use `SoftDeletes`**, so the events are plausible rather than nonsensical — which is exactly why the
  fix is ambiguous. ⛔ **Narrowing a compliance spec's audited-event list has retention and SIEM
  consequences, and the honest answer may be "these are owed, build them" rather than "delete them from the
  document."** The section exists to be exhaustive, so an over-claim is the same defect as the omission
  closed above, pointing the other way. **Live.** Filed by `M46`.
  ✅ **DONE — M70 (2026-09-04). THE MEASUREMENT WAS EXACTLY RIGHT AND THE DECIDING PREMISE WAS FALSE.**
  The row calls the direction ambiguous because *"the honest answer may be 'these are owed, build them'"*,
  which presumes the events are an omission at an existing call site. ⛔ **There is no call site and no
  surface.** Verified twice, independently: `SubmissionPolicy` declares `create`, `viewAny`, `view`,
  `export`, `review`, `update`, `promote` — no `delete`, `restore` or `forceDelete`; `RolePermissionSeeder`
  mints no `submissions.delete`; **zero** routes match such a verb; no controller action and no UI
  affordance. `deleted_at` is dormant, and `ClientUuidResolver::isClaimed()` already says *"Nothing
  soft-deletes a submission today"*. The one path that removes a submission is `ReapTenantDraftsJob`, which
  **hard**-deletes by design and runs where `AuditLogger` is structurally malformed. §1's cell now records
  the two events as **not built** — deliberately not the same claim as *not audited*, since a SIEM
  forwarder built from that section would read a bare removal as a decision that destroying a response
  needs no trail. **`D14` filed** for the build-or-not question, recommending it stay unbuilt: erasure of a
  submitted response is a data-subject request that belongs with the held GDPR work, and it carries an
  unpriced cost the ticket hides — a soft-delete tombstone keeps `client_submission_uuid` reserved against
  the partial unique index, which is `ReapTenantDraftsJob`'s stated reason for hard-deleting.
  ⛔ **WHAT THIS ROW WAS GOING TO SHIP AND DID NOT, because a documentation-only fix here is unprovable by
  construction** — all twelve files in `tests/Feature/Audit/` pin call sites and **none reads the
  document**. The plan was to pair it with the gate that would have caught it. **That gate was measured
  before a line of it was written and the measurement killed it**, for two reasons, and the second is the
  one that matters: it is red on arrival on **ten** aliases rather than one; and **the comparison is not
  statically derivable at all** — a harvest of 34 `record()` call sites is a FLOOR, proven so because three
  services verified as emitters are absent from it entirely (`CustomDomainService` passes the event as a
  variable, `SsoConnectionService` passes **both** through a private helper as `$event` and
  `self::AUDIT_TYPE`, `ImpersonationService` likewise). Roughly half the apparent over-claims were the
  parser, not the tree. Filed below as its own row.
- **`minor` · The data dictionary names nine of the forty-five constraints the migrations declare, while
  enumerating constraints exhaustively in places.** Filed by M46 (2026-08-29) on the measurement that closed
  the `audits` row. `database/migrations/` declares **45** distinct named constraints; `docs/data-dictionary.md`
  names **9**. That is not a defect on its own — a dictionary is not obliged to name every constraint — but
  the section that carried the false `audits` negative *does* enumerate CHECKs elsewhere and *does* state
  negatives deliberately, so a missing constraint there reads as an absent one. **The value is a census, not
  a sweep**: list the 36 unnamed constraints once, and let each table's section decide whether it owes a
  mention. **Not live** — this is a coverage question, not a false statement. Filed by `M46`.
- **`minor` · The citation-liveness gate cannot see a behaviour negative, and its ledger ceiling counts
  deliberately-preserved historical filings.** Filed by M46 (2026-08-29) at the moment the gate shipped, so
  its limits are a filed constraint rather than a comment nobody re-reads. Two of them. ⛔ **(1) It checks
  that a cited line is ALIVE, never that it says what the citing sentence claims.** Of the eight rows it was
  built alongside it fires on three; the other five cite live lines that say the wrong thing. Two measured
  counter-examples sit in this increment's own diff — a docblock asserting that grepping `app/` for cache
  writes finds nothing, and an ADR asserting nothing lowercases email, whose refutation lives in `vendor/`.
  **A gate over first-party code that reports absence is an engine for that error**, and this one must never
  be widened into that shape. ⚠️ **(2) `LEDGER_ROT_CEILING` counts every dead citation in
  `docs/feature-backlog.md`, including those inside CLOSED rows whose preserved filings are dead BY DESIGN**
  — three rows were closed here precisely because their citations were dead, and that evidence is kept. So
  the ceiling can only ratchet down as far as the historical floor. A refinement would exempt struck-through
  rows; it needs a parser that can tell a closed row from an open one, which is more than this gate should
  grow on its first outing. **Not live** — both are stated limits, filed so they cannot be forgotten. Filed by `M46`.
- ~~➡️ **MOVED TO `docs/claims/decisions.md` AS `D6` (2026-08-28) — IT IS A DECISION, NOT A DEFECT.**~~
  ✅ **ANSWERED AND DONE — `M51` (2026-08-31). `D6` is in the `ANSWERED` section of
  `docs/claims/decisions.md`; read the outcome there, not here.** The identification and the published
  audit of the legacy system's weaknesses are out of the tracked files and **every architectural lesson
  is kept**. ⛔ **THE EXPOSURE IS REDUCED, NOT CLOSED — history was NOT rewritten and that is a
  deliberate limit.** The repository is public and its history is readable, so the strings remain in the
  commits that carried them; **whether to rewrite history is filed as its own decision, `D9`, recommended
  against.** Saying the material is gone would repeat this row's original defect in the other direction.
  ⚠️ **WHAT THE ROW AND THE CENSUS BOTH GOT WRONG, AND THE UNIT IS THE FINDING:** the row cited **6**
  sites and `docs/backlog-triage.md` said **"11+"**; `D6`'s own table said **17 across 9 files**;
  `M51` measured **26 occurrences across 11 files — or 20 lines carrying at least one.** `grep -c` counts
  LINES and `grep -o` counts OCCURRENCES, they differ by six here, and none of the earlier figures says
  which it is — so *"the count grew"* was partly drift and partly a change of unit. ⛔ **AND A SEARCH
  SCOPED TO THE TWO NAMES IS THE WRONG SCOPE:** three sites carried no name at all and identified the
  client *by description*, including `docs/backlog-triage.md`, inside its own summary of this decision —
  a name-search reports that file as clean. ⚠️ **One false-positive class would have made a blind
  substitution destructive:** `PROGRESS_ARCHIVE.md` matched an acronym search **55 times** and not one
  was the client — every hit is the developer's own Windows username in a plan-file path.
  ⛔ **AND IT REMAINS AN ITEM AN AUTOMATED LOOP MAY NEVER TOUCH**, for `D9` and for anything like it.
  The original filing follows, kept because its reasoning is intact and because a closed row that
  deletes its own evidence cannot be audited.
- ~~**`major` · The corpus names a real third-party client and publishes an audit of its weaknesses.**~~
  ✅ **CLOSED BY `M51`.** `docs/PRD.md`, `docs/architecture/technical-architecture.md`,
  `docs/adr/0001`, `0002` and `0003`, `docs/domain-glossary.md`,
  `docs/competitive-feature-parity-matrix.md`, `docs/backlog-triage.md` and `PROGRESS_ARCHIVE.md` — the
  system name, the project name, the client's name and a national geography standard, plus the
  exploitation detail beside the `id`-based super-admin convention and the itemised inventory of the
  legacy system's repository and CI posture. **The decisions' rationales are kept in full**, including
  the convention itself, because a decision whose provenance is deleted is a decision nobody can check.
  **Was live** on a public repo; the working tree no longer carries it. ⚠️ **The original text of this
  row is deliberately NOT reproduced**, since quoting it verbatim would republish the identification the
  row exists to remove — which is the same reason `D6`'s answered entry describes the search terms
  instead of printing them. Filed by `M1`.
- ~~**`major` · The pre-push guard REFUSES A NORMAL CLOSE-OUT, and it refused the one that shipped it.**~~
  ✅ **DONE — `M53` (2026-09-01), fixed in BOTH places, and the row's own non-remedy was the load-bearing
  half.** The guard no longer derives its exempt set from `ci.yml`'s `paths-ignore`; it owns
  `PROTOCOL_PATHS` — the files the claim and close-out choreography touches — and the relationship to
  `paths-ignore` is now **asserted rather than assumed**: `PROTOCOL_PATHS` must be a **superset**, checked
  on every run, **failing closed (exit 2)** if `ci.yml` ever grows an entry the guard does not know.
  ⛔ **The row was right that adding the backlog to `paths-ignore` would have been the wrong fix**, and
  it was verified rather than accepted: that block sits under `push:`, so an entry means **no run at
  all** on a backlog edit — a real reduction in gate coverage, in a file on the stop list.
  ⚠️ **The second half needed a different fix and was deliberately not pattern-matched into the first.**
  `loop gates` was not misclassifying paths; it was asking `preflight` a question with no true answer on
  a close-out branch. It gets an explicit `--closeout` mode, propagated to `preflight`, which downgrades
  the claim assertion to a **stated waiver** — ⛔ **explicit and never inferred from the branch name**,
  because a check that relaxes itself whenever a branch is named a certain way is one anyone can switch
  off by renaming a branch.
  **Proven:** the exact commit range `M52`'s close-out was refused for now passes; the superset
  assertion exits 2 when an entry is removed; `preflight` on an unclaimed branch fails without
  `--closeout` and passes with it; and the five original guard controls still hold. **And the acceptance
  test was not a control but this increment's own close-out, which pushed with no `--no-verify`** —
  `M52`'s could not. Filed by `M52`.
- ~~**`minor` · `/gamification/me` documents only `200`.**~~ ✅ **DONE — `M54` (2026-09-01).** The route
  now documents the 403 it can answer. ⚠️ **The citation had drifted**: the row cites
  `routes/api.php:440`, which is the comment block; the route is at `:456-457`. The mechanism was exact
  and was verified in the vendor source for the version INSTALLED — Scramble v0.13.30's
  `ErrorResponsesExtension:60` adds a 403 **only** for middleware starting `can:` or `Authorize::`, so
  `module:` is invisible to it. ⛔ **Two obvious remedies are wrong and both were rejected on evidence:**
  `openapi.json` may not be hand-edited (CI exports fresh and fails on drift), and an `@throws` cannot
  work either, because the exception is thrown by `RequireModule`, which the controller never mentions —
  the gate is on the ROUTE, so the documentation has to be derived from the route. Fixed with a general
  operation transformer (`app/Support/OpenApi/ModuleDisabledResponseExtension.php`), so the next
  `module:`-gated API route documents its 403 without anyone remembering to. **Exactly one operation
  changed**, `/gamification/leaderboard` was untouched — it carries `can:` *and* `module:` and already
  had a 403, which the transformer explicitly refuses to overwrite — and a second export is
  byte-identical, which is what the contract gate requires. Filed by `M1`.
- ~~**`major` · Every error component in `openapi.json` documents a body the `/api/v1` surface does not return.**~~
  ✅ **DONE — `M56` (2026-09-01), and the row was a FLOOR: seven bodies were wrong, not four.** Every
  citation held — the four components, the render closures, §2.3 and the measured `forbidden` body — and
  the scale the row asserted without a number is **113 `$ref`s across 68 operations** (51 → 403, 34 → 404,
  25 → 422, 3 → 401). The remedy it prescribed works, **for a mechanism the row does not state**, read in
  the installed v0.13.30: `TypeTransformer::handleResponseUsingExtensions()` selects with
  `->reverse()->first()`, so **registration order IS precedence read backwards** — the vendor's own order
  had to be matched or the generic HTTP arm would have captured all 34 documented 404s.
  ⛔ **AND THE ROW NAMED FOUR OF FIVE DEFAULTS.** `HttpExceptionToResponseExtension` emits its body
  **inline, with no component to notice**, so three further responses were wrong in exactly the same way
  and were invisible to any fix scoped to `components.responses`: `422 POST /domains/{domain}/primary`,
  `422 DELETE /domains/{domain}` and `409 POST /webhooks/{endpoint}/deliveries/{delivery}/redeliver`, all
  from bare `abort()` calls. Fixed in the same increment.
  ✅ **One apparent eighth case was NOT a defect, and checking it was the point.** `403 POST
  /public/f/{shareToken}/draft` carries no top-level `properties` because it is an `anyOf`; **both
  branches already documented the envelope**. A gate keyed on *"top-level properties must be `[error]`"*
  would have called it broken, so the gate descends `anyOf`/`oneOf`/`allOf` instead.
  **Five classes**, each extending its Scramble counterpart and **overriding `toResponse()` alone** so
  `shouldHandle()` and `reference()` stay the vendor's — which is what held the four component **keys**
  and all 113 `$ref` strings byte-identical. `ApiErrorEnvelope` builds the shape once, because two
  descriptions of one envelope drifting apart is the defect being closed.
  ⚠️ **`code` is a described string, never a `const`, and the generic arm names no code at all** — a
  per-status match there would have been a second copy of `bootstrap/app.php`'s closure chain living in
  the documentation layer.
  **Proven two ways, because one mutation cannot cover both halves**: removing the registration and
  re-exporting reverts all seven bodies (the mechanism), and mutating the committed `openapi.json` turns
  the new census gate red and restores to an exact sha256 (the gate). ⚠️ **The gate's first draft was
  WRONG and went red on the 422**, whose `details` is legitimately required — loosened, and the reason is
  recorded beside it. A fresh export is byte-identical to the commit, so CI's drift diff passes.
  ⚠️ **`M54`'s `/gamification/me` 403 is no longer the only accurate response on the surface** — the
  inconsistency that row stated rather than hid is now closed from the other side.
  ⚠️ **The three "undocumented STATUS" rows below are a different defect and stay open** — the sync 403s,
  `promote`'s three 409 causes and `SyncSubmissionResultResource`'s bare strings are *missing* responses,
  not misdescribed ones, and nothing here documents a status Scramble still cannot infer. Filed by `M54`.
- **`minor` · `loop assess` can only see what a row says about ITSELF, and two blind spots are now measured.**
  Filed by `M55` at the moment both were confirmed, so they are a stated limit rather than a comment
  nobody re-reads. **(1) A row's REMEDY COST is invisible.** `M54` was classified mechanical and its
  evidence was — four checks — but finding a remedy CI would accept took reading three vendor classes and
  ended in a new class plus a registration. *"Mechanical"* means **the row's claim is checkable without
  judgement**, which is not the same as **the fix is small**, and the eligible count should never be read
  as implying the second. **(2) A row that is dead for a reason nobody wrote down still passes.** `M55`
  added a stop on the explicit `**Not live**` marker, which covers the 11 rows that carry it — but only
  24 of 78 rows carry a liveness marker at all, and silence deliberately does not stop, because treating
  an absent marker as dead would stop nearly everything and make the driver useless rather than careful.
  ⚠️ **So this raised a floor rather than closing a hole**, and the eligible count is a shortlist for a
  human, never a work queue. **Not live** — both are stated limits of a tool, not defects in it. ➕ **`M65` CLOSED THE SILENCE HALF OF (2).** Every open row now records a verdict and the marker is gated, so an unmarked row is a failing test rather than something this driver has to be careful around — and `assess` now refuses MORE rows than before, which is the stop rule finally having something to read on every row rather than the driver degrading. The remedy-cost blind spot in (1) is untouched and stands.
- ✅ **CLOSED BY `M68` (2026-09-03) — `minor` · ~~§20's `settings.key` catalog omits `security.require_two_factor`.~~**
  Both §20 passages are corrected and held against the enum by
  `tests/Feature/Docs/DocumentedSettingKeyDriftTest.php`.
  ⛔ **THE ROW UNDERSTATES ITSELF IN TWO DIRECTIONS.** It names one omission in one place. §20 omits the key
  in **two** places, and the second omits a **second** key: the `key` column's *"As built (I5)"* list was
  missing `security.require_two_factor`, and Design Note 2's defaults list was missing **both**
  `security.require_two_factor ⇒ false` **and** `maintenance.message ⇒ ''`. `SettingKey` has five cases and
  §20 named three of the five defaults.
  ⚠️ **AND THE "As built (I5)" LABEL WAS ITSELF PART OF THE DEFECT** — the missing key arrived in I8a, so
  adding it under an I5 heading would have replaced one false statement with another. The label now names
  both increments.
  ✅ **A PEST TEST AND NOT A SIXTH LINT SCRIPT (`M58`).** `php artisan test` already discovers
  `tests/Feature`, so it needs no `composer.json` alias, no `quality` entry and no `ci.yml` step — and
  `scripts/mutate.php` drives Pest in a container and nothing else, so a script would have had to
  reimplement that harness by hand. Three arms, each comparing two sets for EQUALITY in both directions
  (`M56`: a containment check passes over a document naming a key the enum does not have): membership, the
  tenant/platform side, and the resolved defaults.
  ✅ **Five controls, and the arms separate.** Removing the key from the `key` row reddens membership AND
  side; removing it from the defaults paragraph reddens defaults alone; flipping a documented default VALUE
  reddens defaults alone; documenting a key the enum lacks reddens membership alone; and the DISCRIMINATOR —
  moving a **correct** key to the wrong side — reddens the side arm alone, which is what proves that arm is
  not decoration.
  ⚠️ **The gate found its own defect first, which is the `M43` trap arriving through a parser.**
  `toHaveKey`'s second argument is the expected VALUE, not a message, so the first draft asserted that the
  vocabulary mapped a key to a whole sentence and went red against a document that was CORRECT. Both sites
  now use `array_key_exists()` with `toBeTrue()`, and the reason is written beside them.
  ⚠️ **`docs/data-dictionary.md`'s line count is unchanged**, so no citation into it rotted.
  **`minor` · §20's `settings.key` catalog omits `security.require_two_factor`.**
  `docs/data-dictionary.md:838`, rewritten in this branch — the key is live
  (`app/Enums/SettingKey.php:42`, tenant-scoped at `:85`, written by `UpdateAccessSettingsRequest.php:60`,
  enforced by `EnforceTenantTwoFactor`'s `settings->get(SettingKey::SecurityRequireTwoFactor)` read). Anyone inventorying tenant configuration from the
  dictionary omits a tenant-scoped security policy. **Live.** Filed by `M1`.
- ✅ **CLOSED BY `M7` (2026-08-20) — `minor` · ~~ADR-0019 is the sole `Proposed` ADR in the directory, for
  a decision that is ratified and fully built.~~** Now **Accepted**, with the correction stated in the
  Status block rather than silently applied: every decision in it shipped in J3c2,
  `routes/google-auth.php:64,68` have been merged since, and `0018:49` and `0016:133` were already citing
  it as settled precedent. Folded into the row above because it is one word in a file that diff already
  rewrote at `:23` and `:28`, and because it is the same defect one level up — **ADR-0019 did not
  accurately record what had been decided.** Filed by `M1`.
- **`minor` · Nothing checks that a `§D<n>` citation names a section whose text supports it.** The defect
  the row above closed was invisible to every gate: `docs/adr/0019:247` cited `ADR-0016 §D22` for six
  increments, eleven further citations inherited it — one of them a docblock justifying live
  authentication behaviour — and the only thing that surfaced it was a human reading both documents.
  A gate could catch the cheap half mechanically: **a cited `§D<n>` that does not exist in the target
  ADR at all** — worth having, because a renumbering or a deleted section produces exactly that, and
  because it costs one script. ⛔ **BUT IT WOULD HAVE CAUGHT NEITHER KNOWN INSTANCE, AND THAT IS THE
  HONEST ARGUMENT FOR THE SEVERITY**: §D22 exists, and so does ADR-0019 §D7 (the by-line cluster row below
  records `docs/adr/0016` citing it where §D6b is meant). Both fall in the expensive half — **a section
  that exists but says something else** — which is not mechanically checkable and stays a review concern.
  A gate that catches the case nobody has hit is still worth its script, but it must not be sold as the
  answer to the case that keeps happening.
  ⛔ **DELIBERATELY NOT BUILT IN M7 AND FILED HERE THE MOMENT THAT WAS DECIDED**: it lands in `scripts/`,
  adds a fifth lint gate and moves a gate baseline, which is a tooling row rather than the documentation
  row that found it. **Not live** — this is a missing gate, not a defect. Filed by `M7`.
- **`minor` · A cluster of by-line citations went stale, several of them inside this branch.** Cheap
  individually, listed together so one pass closes them: `docs/adr/0007:88,:106,:112` (six citations into
  `TenantIsolation.php`, `Tenant.php`, `migration-lint.php`, `config/queue.php` — every one lands on
  unrelated code, and they are the *only* evidence offered for §D3's load-bearing non-expressibility
  claim); `docs/adr/0007:124,:29,:86,:190` (ADR-0002 §D6 moved to `:248` when this branch inserted +119
  lines above it); `docs/adr/0009:21,:286` (a defect the code already fixed, and the code now points back
  at the ADR as the remaining carrier); `docs/adr/0009:168` + `security-threat-model.md:100`
  (`config/app.php:120-124`, not `:99-106`); `docs/adr/0009:168` again (the "stock `slack` block" pointer
  now lands on the **Google sign-in** block this branch added — the exact conflation the sentence forbids);
  `docs/adr/0016:133,:135,:147` (ADR-0019 §D6b, not §D7); `docs/adr/0020:43`; `docs/adr/0011:8,:111`
  (`MdsTabs` exists as of J4c1); `docs/audit-compliance-logging-spec.md:54`;
  `docs/piping-output-encoding-design.md:181` (was `:180`; **M57 moved it by one line and the citation
  linter caught it in the same run** — the ledger tier went 18 → 19 and named this pointer, which is the
  clearest evidence yet for the rule this very row is about); `docs/offline-first-sync-design.md:128`;
  `docs/data-dictionary.md:62` (§28, not §31 — the pointer currently lands on a note arguing the opposite
  case); `docs/api-specification.md:179` (`read:audit_log` is orphaned four blockquotes below its table
  and renders outside it); `docs/ux/design-system-reference.md:812,:843`;
  `docs/pricing-feature-gating-matrix.md:56` (Business is seeded `unlimited`, not 25 endpoints);
  `docs/PRD.md:13` (the ADR index stops at 0014; 0015–0020 exist); `docs/TESTING-GUIDE.md:57,:639` (three
  forms and five forms, against four and six as seeded); `README.md:169-172` (contract and e2e are real
  merge-blocking gates, not stubs; there is no `deploy` stage in `ci.yml` at all); and this file's own
  `:105` and `:459`. **Live**, all documentary. Filed by `M1`.


- ~~**`major` · Standing Rule 7(g) contains a 163,680-byte claim ledger that duplicates
  `docs/claims/lane-*.md`, and it is why the constitution cannot be read in one call.**~~
  ✅ **DONE — M45 (2026-08-29). THE MEASUREMENT HELD AND THE PREMISE DID NOT, WHICH INVERTED THE
  REMEDY: THE BLOCK IS NOT A SECOND COPY, IT IS THE ONLY ONE.** `PROGRESS.md` **508,441 → 346,913
  bytes** (583 → 450 lines), `## Standing Rules` **208,039 → 46,511**, moved verbatim to
  `PROGRESS_ARCHIVE.md` under `## Archived claim ledger`. `scripts/tracker-lint.php`'s
  `TRACKER_BYTE_CEILING` ratcheted 600,000 → 400,000 in the same commit, which that constant's own
  comment makes the obligation of whichever increment moves one of these sections.
  ⛔ **`docs/claims/` HOLDS `M15`–`M44` AND NOTHING EARLIER; THE BLOCK HOLDS `M1`–`M14` PLUS THE
  J/K/P/I-SERIES. THE SPLIT IS EXACT AND THE OVERLAP IS ZERO** — 0 of its 129 non-blank lines appear
  verbatim in either claim file or in `PROGRESS_ARCHIVE.md`. Had *"the second copy of a record that
  already has a home"* been taken on report, the obvious action — delete the duplicate — would have
  destroyed the only record of every claim before `M15`. **Eighth row in nine whose evidence is sound
  and whose prescribed remedy is not**, and the first where the remedy fails because the row's
  *premise* is false rather than its mechanism.
  ⛔ **AND IT COULD NOT GO WHERE THE ROW POINTS.** The block interleaves 🅰️ and 🅱️ bullets — both
  lanes' claims — and Lane A may never write `lane-b.md`. The destination the row names is closed by
  the one-writer rule the row is filed under, which leaves `PROGRESS_ARCHIVE.md` as the only
  structurally legal home. That was settled before a byte moved, not discovered during the splice.
  ⚠️ **THE ROW'S "ZERO IMPERATIVES" IS FALSE, AND IT IS THE PART WORTH READING.** Four of the moved
  bullets carry **`DO NOT RE-ASK` user decisions of record** — M9's three SSO-adoption decisions
  (2026-08-24), and leaderboard visibility, the ⌘K palette's stacking behaviour and checklist
  dismissibility (2026-08-17). They moved with the block, because hoisting them into
  `docs/claims/decisions.md` would have created the second copy this row exists to end and broken the
  conservation proof besides; both the archive heading and the pointer name them explicitly so the
  record stays discoverable. Filed as its own `minor` below.
  ⚠️ **THE ROW'S BOUNDARY WAS OFF BY THREE LINES AND ITS SIZE BY 1,650 BYTES** — it cites `:123-259`
  / 163,680 and the ledger is `:126-259` / **162,601**, because `:123`–`:125` are live imperatives
  (the ADR-number-is-not-written-here fix, the `0010`-is-reserved trap, cite-by-filename) and the file
  has grown since filing. **A row's line numbers are the first thing to re-measure, not the last.**
  ⛔⛔ **R7 CANNOT FIRE ON THIS SURGERY, AND THAT WAS PROVEN RATHER THAN INFERRED.** `DROP_LIMIT` is
  **200 lines** with a strict `>`; this drop is **133**. The commit was amended to remove
  `[tracker-surgery]` entirely and `tracker-lint` **still passed**, then the message was restored
  byte-exactly. So the marker is carried because the row demands it, **not because any gate enforced
  it** — and `CLAUDE.md`'s own two-hundred-line threshold does not reach this diff either. A green R7
  here is the vacuous-success family again: a rule that passed because it never looked.
  ✅ **PROVED BY 21 ASSERTIONS, ALL EXACT AND NONE WITH A TOLERANCE**: a counted multiset of the 134
  moved line hashes with exact multiplicity (**130 distinct** — five blank lines share one, so a set
  equality would have passed while dropping four lines); **byte conservation exact,
  2,601,691 == 2,601,691**, with the 1,180-byte heading and the 1,073-byte pointer stated as integers
  computed from the literal strings; lines 1–125 and Rule 8 downward byte-identical
  (`82773829…`, `b593cfeb…`); and an **independent git-level proof** — the slice read from the
  pre-surgery commit and the tail of the new archive both hash `868511fd…`.
  ⚠️ **THE PATHS-TOUCHED ASSERTION FAILED TWICE AND THE CHECK WAS WRONG BOTH TIMES, NOT THE SURGERY**
  — first because it compared committed state while phase 2 sat in the working tree, then because its
  expected list omitted the ceiling ratchet the plan had named in scope. `M41` recorded this once
  (*"a failing independent check is not automatically a defect; verify the check before believing
  it"*); it recurred twice inside one increment, in the same assertion, for two unrelated reasons.
  ✅ **AND THE GATE WAS PROVEN ABLE TO FAIL ON THE RULES THAT ACTUALLY BEAR ON THIS DIFF**, since
  `scripts/mutate.php` cannot drive a standalone script: four controls, each with a green baseline
  first, each asserting the sha256 **moved**, each restored by **byte comparison** — a second
  `## Next Session` in the archive reddens **R3**; a `## Current Status` there reddens **R4**; the
  ceiling set below the new size reddens **R1**; one CR byte reddens **R5**. Each named its own rule
  and only it, and the gate was green again afterwards.
  ➕ **Filed rather than fixed:** the `## Next Session` row below, and the decisions-hoist row.
  Original filing follows.
  — *Measured: `## Standing Rules` is 207,468 bytes; **Rule 7 alone is 196,596 of them — 94.8%** —
  and lines 123–259 are per-increment `RELEASED` / `CLAIMED` bullets carrying **zero imperatives**.
  Claims have lived in `docs/claims/` since the 2026-08-25 amendment, so this is the second copy of a
  record that already has a home, which is the defect Rule 7(b) records about the lane boundary and
  `docs/gate-baselines.md` ends for gate numbers. `M41` named this and deliberately did not take it,
  restating its own ~40 KB target as unreachable "while Standing Rules and Next Session live in this
  file" and calling the move "a decision about what the constitution keeps, not a splice." That
  decision was taken with the user on 2026-08-29: move it, as its own increment. It is a tracker
  surgery and must be run as one. It also unblocks the `minor` row about R8 not reaching
  `PROGRESS.md`. Estimated effect: roughly 504 KB down to roughly 340 KB. Filed by M42
  (2026-08-29).* Filed by `M42`.

- ~~**`major` · `## Next Session` is a second historical ledger inside the constitution, and it is now
  62% of the file.**~~
  ✅ **DONE — M48 (2026-08-29). THE DEFECT WAS REAL AND LARGER THAN THE ROW; THREE OF ITS FOUR COUNTS
  WERE WRONG; AND THE INCREMENT'S MOST VALUABLE OUTPUT IS A RECORDING, NOT A DIFF.** `PROGRESS.md`
  **360,207 → 161,298 bytes** (483 → 306 lines), `## Next Session` **214,178 → 15,269** — from **59.5%
  of the file to 9.5%**. 178 lines and 200,625 bytes moved verbatim to `PROGRESS_ARCHIVE.md` under
  `## Archived session hand-offs and per-increment decisions`.
  ⛔ **R7 HAS NOW FIRED, AND THIS IS THE FIRST TIME IN ITS EXISTENCE.** `M47` proved the byte threshold
  against replayed trunk bytes and recorded plainly that no real merge had ever armed it. The gate's own
  words on this diff: *"PROGRESS.md lost 177 line(s) (483 down to 306, limit 200) and 198,909 byte(s)
  (360,207 down to 161,298, limit 50,000), declared with `[tracker-surgery]`"*.
  ⛔ **AND THE ARITHMETIC INVERTS WHICH HALF OF THE GATE DID THE WORK — READ THE LINE FIGURE AGAIN.**
  **177 net lines against `DROP_LIMIT`'s 200. UNDER IT.** A surgery that removed three-fifths of the
  constitution is invisible to the line threshold, exactly as `M45`'s was. `DROP_BYTE_LIMIT` caught it
  at 3.98× over. **The third consecutive surgery the line count could not see, and the first one
  anything did.**
  ⛔ **THE ROW'S COUNTS: three of four refuted, measured before a byte moved.** *"Roughly 19
  superseded-prompt blocks"* is **14** (13 `SUPERSEDED PROMPT` plus one `PRIMARY NEXT PROMPT`, at line
  start). *"`I1`–`I12`, `H1e`–`H24b1`, `J1`–`J5`"* is **28 records** over `I1`–`I4`, `I6`, `I7a`, `I7b`
  and `H5a`–`H24b1` — **no `I5`, no `I8`–`I12`, and no J-series at all**, that last having left with
  `M45`'s own claim ledger, so the row inherited a range that was already gone. `M47`'s **242,873** is
  accurate to 31 bytes but is a **different boundary** — heading-to-EOF, sweeping in four sections this
  increment does not touch; planning against it would have overstated the surgery by 13%. Only the
  ordering claim held.
  ⛔ **THE REMEDY WAS INCOMPLETE IN THREE WAYS THE TREE ENFORCES, AND THE FIRST IS RED ON ARRIVAL.**
  (1) `tracker-lint` **R3 counts `^## Next Session` across BOTH files and expects exactly one**, so the
  obvious archive heading fails the merge gate — the same trap `M45` hit and renamed around. (2)
  `TRACKER_BYTE_CEILING` had to be ratcheted **400,000 → 200,000** in this diff, and **its own comment
  asserted a fact this commit falsified** (*"unreachable while Next Session (214 KB, and now 62% of this
  file) remains in it"*) — the third time a gate constant's comment has had to be corrected by the
  increment that invalidated it. (3) ⛔ **`ci.yml`'s `push` `paths-ignore` means a tracker-only surgery
  produces NO POST-MERGE RUN AT ALL**, so the end-to-end proof this row owed would have been
  unobtainable; it is obtainable only because obligation (2) drags `scripts/` into the same commit.
  Filed as its own row rather than left as luck.
  ✅ **PROVED BEFORE A BYTE WAS WRITTEN, AND THE SET-VERSUS-MULTISET GAP IS AN ORDER OF MAGNITUDE WORSE
  THAN `M45` MEASURED.** Pre-state pinned by sha256; nine anchors asserted at pre-measured indexes and
  never searched for; a counted multiset of **178 line hashes with exact multiplicity — 95 distinct,
  because 84 blank lines share one hash, so a set equality would have passed while dropping 83 lines**
  (`M45`'s equivalent figure was four). Byte conservation exact in both directions and globally,
  **2,618,558 == 2,618,558**, with no tolerance; the three kept regions hash unchanged
  (`9d21756dd2933632`, `dcf5e07a14371f2d`, `5fca4324334d1453`). **The move is two slices, not one** —
  the 2026-08-24 lane-column bulletin sat *above* the hand-off lines — and the proof does not assume
  contiguity.
  ⚠️ **AND THE BOUNDARY JUDGEMENT THE ROW SAID WOULD BE NEEDED WAS NEEDED, IN A DIRECTION IT DID NOT
  NAME.** Sweeping the block's *"recorded, not fixed"* items against the **code** rather than against the
  record found **three already closed** — the `domains.domain` URL pair (H22a), `MdsSwitch`, and
  grant/revoke audit entries — and **one live, unfiled anywhere**, now a `minor` under *Submissions*. A
  record asserting that something is unfixed is not evidence that it is; filing from the record would
  have produced three phantom rows and still missed the real one.
  ➕ **Filed rather than fixed:** the `## Current Status` row below (it is now 42% of the file and its
  largest section), the `paths-ignore` row, and the display-fallthrough row under *Submissions*.
  Original filing follows.
  **`major` · `## Next Session` is a second historical ledger inside the constitution, and it is now
  62% of the file.** Measured after M45's surgery: `PROGRESS.md` is **346,913 bytes** and
  `## Next Session — Resume Here` is **214,073 of them**, against `## Standing Rules`' 46,511 and
  `## Current Status`' 57,283. Roughly **19 superseded-prompt blocks** (each 2.7–7.5 KB of a hand-off
  that was superseded increments ago) plus a long run of *"(do not re-open)"* per-increment decision
  records for `I1`–`I12`, `H1e`–`H24b1`, `J1`–`J5` and the five answered-2026-07-21 questions.
  **Exactly the shape M45 just moved, one section down**, and the same argument applies: a superseded
  hand-off is a dated record, not an instruction, and the archive is where dated records live. What
  must stay is the two live `LANE * NEXT PROMPT` lines — `scripts/next.php --write` refuses unless
  exactly one per lane exists at line start, and `scripts/state.php --check` gates the positional
  `[state …]` block on Lane A's — plus the parallel-lanes protocol header and whichever "do not
  re-open" entries are still load-bearing, which is a judgement per entry rather than a slice.
  ⚠️ **That last part is why this is not a repeat of M45 and must not be planned as one:** M45 moved
  a block that was provably 100% dated record with a clean boundary; this one has live and dead
  material interleaved, so the boundary has to be decided rather than measured. ⛔ **M41 named this
  section by size and NOTHING EVER FILED IT** — it lived only in that release's prose and in M45's
  plan, so a backlog search would never have reached it. That is the J4b1 shape, and it is why this
  bullet exists. **Filed by M45 (2026-08-29). Live.**
  ➕ **RE-MEASURED BY M47 (2026-08-29), AND THE SECTION HAS GROWN SINCE FILING.** `## Next Session` to
  end of file is **242,873 bytes** — not the 214,073 above, which was true on 2026-08-29 at M45's
  close and is a dated figure like any other. **Plan against the measurement, not against this row.**
  ⚠️ **The whole-file size is deliberately NOT stated here.** A first draft of this bullet gave it, and
  M47's own paragraph in Standing Rule 8 — in the same commit — invalidated it before the commit was
  written. `tracker-lint` prints it on every run; the section size is the figure that stays put.
  ✅ **AND THE GATE THAT WOULD HAVE MISSED THIS SURGERY IS NOW ARMED FOR IT.** `R7`'s new
  `DROP_BYTE_LIMIT` is 50,000, so a removal of that size **must** carry the marker or the trunk goes
  red — where the line threshold alone would have waved it through exactly as it waved M45 through.
  ⚠️ **That also makes this increment the one that owes the end-to-end proof M47 could not give:** no
  real GitHub squash has yet been observed arming the relaxed marker, because M47's own drop was far
  too small to make R7 look. Merge this one with an explicit `--body` whose first content line is the
  marker, and read the post-merge run on `main` rather than the PR run. Filed by `M45`.

- **`minor` · Four `DO NOT RE-ASK` user decisions of record now live in `PROGRESS_ARCHIVE.md` rather
  than in `docs/claims/decisions.md`.** M45 moved them with the claim ledger they were embedded in:
  M9's three SSO-adoption decisions (2026-08-24), and leaderboard visibility, the ⌘K palette's
  stacking behaviour and checklist dismissibility (2026-08-17). **Deliberate, and the reasoning is
  the row above's:** hoisting them into `decisions.md` would have created the second copy that row
  exists to end, and would have broken the byte-conservation proof — the move stayed a pure
  permutation precisely because nothing was lifted out of it. Both the archive heading and the
  pointer left in Rule 7(g) name them explicitly, so they are discoverable rather than lost.
  ⚠️ **The residual defect is a FORM mismatch, not a lost record:** `decisions.md`'s `ANSWERED`
  section is D-numbered *questions* (`D2`, `D5`), and these are increment decisions with no question
  attached, so adopting them needs a second form in that file rather than a copy-paste. **Not live**
  — nothing is wrong today; this is a filing question. **Filed by M45 (2026-08-29)** at the moment it
  decided not to do it.
  ➕ **EXTENDED BY `M48` (2026-08-29), WHICH ADDED TO THE SAME PILE FOR THE SAME REASON.** The
  `## Next Session` surgery moved the **2026-08-06 `SEQUENCING LOCKED WITH THE USER` block** (OCR goes
  last; payments cut to Phase 4 and Track B last; the Google credential and publishing-status facts; the
  H16b redirect-URI trap) and the **five questions answered 2026-07-21** into `PROGRESS_ARCHIVE.md`
  alongside M45's four. The reasoning is unchanged and was re-checked rather than inherited: lifting them
  into `docs/claims/decisions.md` creates the second copy that row exists to end, **and it would have
  broken the conservation proof by making the move something other than a pure permutation** — which is a
  stronger argument here than it was for M45, because M48's proof is a counted multiset over two
  non-contiguous slices. Both the tracker pointer and the archive heading name these decisions
  explicitly. ⚠️ **The residual is unchanged and has simply grown**: `decisions.md` has no form for a
  decision with no question attached, and there are now roughly a dozen of them in the archive rather
  than four. **Still not live** — nothing is wrong today; it is still a filing question, and the second
  form belongs with the Rule 7 rewrite. Filed by `M45`.

- ~~**`major` · `## Current Status` is now 42% of the tracker and its largest section.**~~
  ✅ **DONE — `M60` (2026-09-02), merged as PR #251 (`55c6409`, 6/6 green with real step counts —
  Static analysis 23 · E2E 20 · Contract 16 · Frontend 12 · Pest 11 · axe 11).** 36 lines and
  **102,115 bytes** — `M56` down to `M29` — moved verbatim into the head of `PROGRESS_ARCHIVE.md`'s
  existing `## Archived status bullets` section, head-inserted so the section stays newest-first.
  `PROGRESS.md` **196,030 → 94,757 bytes (−51.7%), 323 → 287 lines**; `## Current Status` from
  110,268 bytes to ~8,100. The heading, the newest three bullets and a rewritten pointer survive,
  because `R2` forbids deleting the heading — the choice `M41` made and recorded. Ceiling ratcheted
  **200,000 → 130,000**, its comment re-measured, and the headroom sized from the real growth rate
  (mean 3,460 bytes per close-out across `M46`–`M59`, max 4,892) rather than from the phrase *"roughly
  a dozen"* that had survived four ratchets unexamined.
  ⛔ **EVERY FIGURE THIS ROW STATED WAS STALE, ALL FOUR IN THE SAME DIRECTION, AND THAT INVERTED ITS
  PRIORITY.** It reads as a tidiness row. Measured at the moment it was taken, `## Current Status` was
  **110,268 bytes and 56.3%**, not 67,982 and 42.1%, and the file was 196,030 against `R1`'s 200,000
  ceiling — **3,970 bytes of headroom, about ONE ordinary close-out from a red trunk.** A row whose own
  numbers understate it by 62% cannot be triaged from its text.
  ⛔ **AND ITS CENTRAL PROHIBITION WAS SPENT — THE PREMISE, NOT THE EVIDENCE OR THE REMEDY.** The row
  forbids a slice crossing the lane tags, because Rule 7(b) gives each lane its own status block. The
  row is dated **2026-08-29**; `M50` retired Lane B on **2026-08-31**, two days later
  (`docs/adr/0022-single-lane-development.md`). `lane-b.md` reads *"READ AND NEVER WRITTEN"*, its
  status is `RETIRED`, it holds no forward queue, and Rule 7 itself carries a superseded banner. So the
  slice carries the 🅱️ bullets for `M35`, `M34`, `M33` and `M29` deliberately. `M59` flagged this as
  *"noticed three times and measured never"* and handed it over to be measured; **this is `M45`'s own
  lesson — verify the row's PREMISE — landing on `M45`'s successor.**
  ✅ **AND IT DISCHARGED THE END-TO-END SQUASH PROOF `M47` AND `M48` EACH HANDED FORWARD.** The
  post-merge run on `main` (`33586412469`) reports `DECLARED SURGERY: 36 line(s) and 101,273 byte(s)`
  with `R7 base is 54bb8bd… (github.event.before, via TRACKER_LINT_BASE_SHA)` — a real GitHub squash
  arming the relaxed marker on a real trunk push, which no surgery had yet produced: `M41` reddened
  `main` with an emptied `--body`, `M45` lost the marker to the default `* ` prefix, `M48` pushed the
  branch and forfeited the squash. **The byte arm fired alone** — 36 lines against a 200 limit, 101,273
  bytes against 50,000 — which is exactly the blindness the byte threshold was added for.
  ⚠️ **Proved four ways, 17 checks, 0 failed**, with the slice re-read from git at phase 1's parent
  rather than from anything the splice wrote: a counted multiset of 36 line hashes with exact
  multiplicity, present in the archive **and** decremented in the tracker; exact byte conservation with
  the added bytes stated rather than inferred (`2,653,273 + 2,013 == 2,655,286`); `## Standing Rules`
  byte-identical; and an independent git-level hash. **`M41` predicted byte conservation was the check
  most likely to fail and it failed by exactly one byte at the join seam; this used its corrected
  formula and held on the first run.** Five positive controls — `R7`, `R1`, `R4`, `R5`, `R2` — each red
  when mutated and byte-identical when restored, against a green baseline taken first.
  ➕ **One side effect worth recording:** `state.php`'s count of prose literals in `PROGRESS.md`
  disagreeing with the tree fell from **30 to 6**, because 24 of them lived inside the moved bullets.
  **Original filing follows.**
  **`major` · `## Current Status` is now 42% of the tracker and its largest section.** Measured
  immediately after M48's surgery, on the file as it now stands: `PROGRESS.md` is **161,298 bytes** and
  `## Current Status` is **67,982 of them — 42.1%**, against `## Standing Rules`' 49,001 (30.4%) and
  `## Next Session`'s 15,269 (9.5%). **It is the same shape M45 and M48 have each moved once**, and it is
  the last one: with it gone the constitution is roughly 93 KB and the ~40 KB target is arguable for the
  first time. ⚠️ **IT MUST NOT BE PLANNED AS A REPEAT OF EITHER PREDECESSOR, FOR A REASON NEITHER OF THEM
  HAD.** Rule 7(b) gives each lane **its own status block and only its own**, so the section has two
  writers; a slice that moves both lanes' bullets is a Lane A edit to Lane B's block, which is the one
  thing the boundary forbids. The boundary here is *per lane and then per bullet*, not per line range.
  ⚠️ **And the ceiling ratchet is the taker's obligation**, as it was here — `TRACKER_BYTE_CEILING`'s
  comment names this section by measurement rather than by inheriting a sentence. **Filed by M48
  (2026-08-29) at the moment the scope was decided.** **Live.** Filed by `M48`.

- **`minor` · A tracker surgery whose diff touches only `paths-ignore`d files produces no post-merge run
  at all, so `R7`'s trunk arm is unreachable for exactly the diff shape it guards.** `ci.yml`'s `push`
  filter covers `PROGRESS.md`, `PROGRESS_ARCHIVE.md`, `docs/claims/**`, `docs/gate-baselines.md` and
  `docs/backlog-triage.md`, and GitHub evaluates it over **every** file in the push — so a pure
  permutation of the two tracker files, which is precisely what a well-executed surgery is, **cannot
  trigger CI on `main`.** The PR run still gates the merge, so this is not a hole in the merge gate; what
  it removes is the **post-merge observation on the trunk**, which is the only place the squash body's
  form can be verified and the exact evidence `M47` recorded as owed. ⚠️ **M48 escaped it by accident of
  scope**: the `TRACKER_BYTE_CEILING` ratchet put `scripts/` in the same commit. A surgery that needed no
  ratchet would have merged with the marker unverifiable. ⛔ **The remedy is NOT to delete the filter** —
  it exists because a close-out pushes documentation three times per increment and M39 measured six
  cancelled runs from it. Candidates: exempt a commit whose message carries the marker, or have the
  surgery deliberately touch one non-ignored file. Both are decisions about `ci.yml`, which is a shared
  artefact. **Filed by M48 (2026-08-29)**, found while confirming the run this increment needs would
  exist. **Live.**
  ⛔ **REMEDY CORRECTED BY M49 (2026-08-31), WITHOUT TAKING THE ROW: THE FIRST OF THE TWO CANDIDATES
  CANNOT BE WRITTEN.** A workflow's `paths-ignore` is evaluated by GitHub *before* a run exists, over
  the files in the push and nothing else; it has no access to a commit message, so *"exempt a commit
  whose message carries the marker"* has nowhere to be expressed. That leaves the second candidate — a
  process rule, not a code change — plus two the row did not name: **(a)** drop `PROGRESS.md` and
  `PROGRESS_ARCHIVE.md` from the filter, which restores an ~18-minute pipeline on every close-out and
  is exactly the cost `M39` measured and removed; **(b)** a second, tiny workflow running only
  `tracker-lint` on `push` to `main` with no filter, ~1 minute. **Promoted to `docs/claims/decisions.md`
  as a run-cost decision, with (b) recommended.** The row stays open until that decision is answered. Filed by `M48`.

- ~~**`minor` · Nothing asserts that CI's checkout is deep enough for `R7` to see the commit that
  declares a surgery, and the failure presents as a missing marker rather than as a broken gate.**~~
  ✅ **DONE — M49 (2026-08-31), as a consequence of the base-resolution row below rather than as a
  second effort.** The row asked for an assertion about *the shape of the clone*, and named the honest
  difficulty exactly: **`R7` cannot tell the two apart from inside**, because *"no commit in this range
  carries the marker"* is the same observation whether the commit is missing or the marker is. The
  assertion it now makes is that comparison: on a `pull_request`, `github.event.pull_request.commits`
  is passed in and compared against the commits actually present in `HEAD~1..HEAD`. Fewer in range
  than the pull request has means the clone is grafted, and that **exits 2 with a message naming the
  depth** rather than reporting an absent marker. ⚠️ **`>=` and not `==`, deliberately** — the failure
  guarded is a range holding *fewer* commits, and a strict equality would redden on legal branch
  topologies, which is a false red in the one rule that must never cry wolf. ⚠️ **The `gitleaks` half
  of this row is NOT closed and is not closable here:** nothing pins `fetch-depth` *for the secret
  scan*, whose blindness is silent in the same way and whose stakes are higher. It is re-filed as its
  own `minor` below. Original filing follows.
  **`minor` · Nothing asserts that CI's checkout is deep enough for `R7` to see the commit that
  declares a surgery, and the failure presents as a missing marker rather than as a broken gate.**
  ⛔ **Found by `M48` the hard way: it reddened PR #238.** `ci.yml`'s `static-analysis` checkout used
  `fetch-depth: 2`, chosen by `M40` for this very rule. On a `pull_request` that leaves the merge
  commit and its two parents in the clone and nothing else, so `HEAD~1..HEAD` is **the merge commit
  plus the PR's LAST commit** — every earlier one is grafted away. `M48` put `[tracker-surgery]` on its
  phase-1 commit, which sat at depth 3, and `R7` reported the marker absent while measuring the delta
  perfectly. **Fixed in `M48` (`fetch-depth: 0`), and `tracker-lint`'s docblock — which asserted the
  opposite in writing — corrected with it.** ⚠️ **WHAT IS FILED IS THE ABSENCE OF A GUARD, NOT THE
  DEFECT.** The value is one line of YAML with nothing pinning it; the next person tuning CI clone time
  can restore a bounded depth and the only symptom will be a surgery that cannot declare itself. **And
  the honest difficulty is that `R7` cannot tell the two apart from inside** — "no commit in this range
  carries the marker" is the same observation whether the commit is missing or the marker is. A check
  would have to assert the *shape of the clone* (that the merge commit's second parent's history is
  present), which is a different kind of assertion from anything this gate makes today.
  ⛔ **AND IT WENT UNSEEN FOR EIGHT INCREMENTS FOR THE REASON THIS WHOLE ARC KEEPS PRODUCING: THE RULE
  HAD NEVER FIRED.** `M40` built it, `M47` proved its predicates against replayed bytes, and neither
  could have reached this — the defect only exists on a real `pull_request` checkout with a real
  multi-commit PR. **Filed by M48 (2026-08-29)** at the moment the fix was written. **Not live** — the
  defect is closed; the missing guard is not.
  ⛔ **RAISED IN SEVERITY BY WHAT THE FIX EXPOSED (M48, 2026-08-31): `fetch-depth` GOVERNS THE SECRET
  SCAN TOO, AND THAT IS THE HIGHER-STAKES HALF.** `ci.yml` runs `gitleaks detect --source .`, which
  scans **git history**, not the working tree. At `fetch-depth: 2` the clone held two commits, so **the
  secret scan on a PUBLIC repository was checking two commits at a time for its entire life** — a
  vacuous success of exactly the catalogued kind, in the gate whose failure costs the most. Raising the
  depth to 0 for `R7` made it scan 818 commits on the first run. ✅ **It found three, all the same
  string, all a password-strength test fixture; `.gitleaksignore` records why by fingerprint.** So the
  outcome is reassuring and the *mechanism* is not: **one YAML integer silently governed whether two
  independent gates could see anything**, and nothing anywhere said so. ⚠️ **A future edit that lowers
  the depth to save clone time re-blinds BOTH, and neither reports being blind** — `R7` says the marker
  is missing and the secret scan says no leaks found. That is the argument for the guard this row asks
  for, and it is now a security argument rather than a bookkeeping one. Filed by `M48`.

- ~~**`major` · `R7` measures the tip against its parent, so a large removal that is not in a push's
  LAST commit is invisible — and the constitution reached `main` through exactly that hole.**~~
  ✅ **DONE — M49 (2026-08-31). THE ROW'S EVIDENCE HELD IN FULL AND ITS PRESCRIBED REMEDY WAS HALF
  WRONG, IN THE DIRECTION THAT PRINTS A NUMBER.** `R7` now takes its base from `github.event.before`
  on a `push`, keyed on `GITHUB_EVENT_NAME` so a `ci.yml` edit that drops the variable **exits 2**
  rather than falling back quietly. ⛔ **BUT `github.event.pull_request.base.sha`, which the row
  prescribes for the second arm, IS WRONG HERE.** `base.sha` is the base tip as of the *event*; the
  checkout is `refs/pull/N/merge` as of the *run*. When `main` advances between them — routine with
  two lanes, and already catalogued as *"a gate number moving on a diff that cannot move it is the
  OTHER LANE"* — `base.sha..HEAD` sweeps in the other lane's commits and reports **their**
  `PROGRESS.md` delta as this pull request's. The merge commit's first parent is exact, so the PR arm
  keeps `HEAD~1` for the measurement and spends the payload on the job `base.sha` cannot do: comparing
  `github.event.pull_request.commits` against the commits actually in range, which is the only thing
  that distinguishes a clone too shallow to hold the marker from a commit that never carried one.
  **That closes the `fetch-depth` guard row above as a consequence rather than as a second effort.**
  ➕ **The larger half of the increment is `scripts/tracker-lint-controls.php`**: eleven synthetic git
  histories, the shipped bytes of the gate copied into each, committed **red** against the unfixed
  gate first — seven cases landing elsewhere, and `C2`/`C4` reproducing this row's own defect on the
  same bytes — then green. Registered as its own CI step, because `R7`'s `push` arm cannot execute
  during a PR run and nothing else will ever exercise it. ⚠️ **AND A MUTATION FOUND A CONTROL PASSING
  FOR THE WRONG REASON, INSIDE THE INCREMENT THAT WROTE IT:** disabling the empty-base refusal left
  `C5` green, because an empty sha fell through to the commit-ness check and exited 2 from a different
  branch. All five cannot-measure cases now assert their **own** message, not the shared prefix.
  Original filing follows.
  **`major` · `R7` measures the tip against its parent, so a large removal that is not in a push's LAST
  commit is invisible — and the constitution reached `main` through exactly that hole.** ⛔ **MEASURED
  ON `M48`'s OWN PUSH, NOT PREDICTED.** The push `e82e835..5d4bd79` carried four commits, one of which
  removed **198,909 bytes** of `PROGRESS.md`. The run it produced compared `HEAD~1` (`add6f18`, where
  the file is already 161,298 bytes) against `HEAD` and reported a delta of **zero**. The largest
  removal this repository has seen since 2026-08-16 crossed the merge gate **unmeasured, and green**.
  ⛔ **THIS IS THE SAME ROOT CAUSE AS THE `fetch-depth` DEFECT `M48` FIXED, SEEN FROM THE OTHER SIDE:
  `R7` assumes the unit of change is one commit.** `HEAD~1` is the right base only when a push or a PR
  contains exactly one. The 2026-08-16 incident (`f565ac9`) was a single commit, which is the only
  reason `R7` as written would ever have caught it — **the gate has been sized against a sample of
  one.** ⚠️ **And the two holes compose**: on a `pull_request` the marker must be on the last commit,
  and on a `push` the deletion must be in the last commit, so a surgery split into phases — which is
  what `CLAUDE.md` prescribes and what `M45` and `M48` both did — is the *worst* case for both.
  **The remedy is to take the base from the event payload rather than from the commit graph**:
  `github.event.before` on `push`, `github.event.pull_request.base.sha` on `pull_request`, passed in as
  an environment variable, with `HEAD~1` kept only as a local-run fallback. ⛔ **And when neither is
  reachable it must exit 2, never fall back silently** — a delta measured against the wrong base is
  worse than one not measured at all, because it prints a number. ⚠️ **Sized `major`** because it
  defeats the only gate this repository has against the incident that cost it 1,086 lines, it does so
  silently, and it has now been demonstrated on the trunk rather than argued. ⚠️ **The positive controls
  are the hard part and must not be skipped**: they need synthetic multi-commit `push` and
  `pull_request` fixtures, which is the shape `M47` built for the marker and `scripts/mutate.php`
  cannot drive. **Filed by M48 (2026-08-31)**, which found it by making the mistake the gate exists to
  catch. **Live.** Filed by `M48`.

- ✅ **CLOSED BY `M77` (2026-09-06) — `minor` · ~~Nothing pins `fetch-depth` for the SECRET SCAN, whose blindness is silent and whose
  stakes are the highest of the three gates that integer governs.~~** Split out by `M49` (2026-08-31)
  from the `R7` checkout-depth row it closed, because that row's remedy — comparing the range's
  commit count against the payload's — is available to `R7` and **is not available here.** `ci.yml`
  runs `gitleaks detect --source .`, which scans git **history**: at `fetch-depth: 2` it was checking
  two commits at a time for the repository's whole life on a public repo, and `M48`'s raise to `0`
  made it scan 818. ⛔ **`gitleaks` has no payload number to check itself against.** It cannot know
  how many commits it *should* have seen, so a future reduction of the depth re-blinds it and it
  reports `no leaks found` — the same shape as `R7`'s absent marker, in the gate whose failure costs
  most. Candidates, none costed yet: assert in a CI step that `git rev-list --count HEAD` exceeds a
  floor before the scan runs; or pin the depth with a comment the linter reads; or scan with an
  explicit `--log-opts` range derived from the event payload, as `R7` now does. ⚠️ **The honest note
  is that a floor is a ratchet and will need maintaining**, which is why this is filed rather than
  guessed at. **Live.** Filed by `M49`.
  ✅ **DONE — `M77` (2026-09-06), in the same commit as `M76`'s sharpening of this row below.** ⛔ **THE
  ROW'S THREE CANDIDATES WERE ALL DOMINATED BY A FOURTH IT DID NOT CONSIDER**, and its own stated worry
  — *"a floor is a ratchet and will need maintaining"* — is exactly what that fourth option removes.
  `git rev-parse --is-shallow-repository` is an **exact boolean over the state `actions/checkout`
  produces**: no floor, no ratchet, no payload, and nothing to maintain as the repository grows. A
  commit-count floor would have needed raising forever; an `--log-opts` range is wrong for a scanner
  with no memory of prior runs. ⚠️ **One premise this row states is false and was worth measuring**:
  it says the depth is unpinned, and `R7`'s clone-shape assertion — shipped by `M49` in the very same
  commit that filed this row (`12b0ef5`, confirmed with `git log -S` on both files) — does pin it. Just
  not usefully: it is satisfied by any depth at or above the PR's commit count plus one, and on a
  `push` or `schedule` event it asserts nothing about clone shape at all.

- ~~**`minor` · `npm audit` cannot distinguish "the registry is unreachable" from "your dependencies are~~
  ✅ **DONE — M72 (2026-09-05). THE ROW HELD IN FULL, AND THE ONE THING IT GOT WRONG WAS ITS OWN
  BLOCKER.** Fetching and judging are now two steps. `scripts/npm-audit-judge.php` reads the report
  `npm audit --json --omit=dev` writes and exits **0** judged clean · **1** high/critical in production
  deps · **2** the advisories were never obtained — the three-way contract `tracker-surgery.php` and
  `pre-push-guard.php` already publish, reused rather than invented. ⛔ **THE RECOGNITION TEST IS
  POSITIVE AND THAT IS THE WHOLE DESIGN**: it keys on `metadata.vulnerabilities` being present, never on
  `error` being absent, because the negative form puts every unrecognised shape — a future npm, a
  truncated file, an HTML error page — silently into CLEAN, which is this row's own defect one layer up.
  The `unrecognised-shape` fixture exists to prove exactly that, and it is the only one of the five that
  a wrongly-written judge would fail. ⚠️ **TWO DETAILS SILENTLY REVERT THE DESIGN IF LOST, so both are
  asserted structurally**: `continue-on-error: true` on the fetch, without which npm's own exit 1 kills
  the job before the judge runs; and the `set +e` / `set -e` fence, because GitHub's default shell is
  `bash -e` and the judge's non-zero would otherwise abort the step before `code=$?` is read. ⚠️ The
  threshold moved OFF the npm command line into the judge, which is what makes it drivable by a
  mutation; the `--omit=dev` scope did **not** move — that policy was locked with the user in PR #61.
  ✅ **Four controls, all CAUGHT**, and they are paired rather than redundant: mutating the judge reddens
  the behavioural cases, mutating the workflow reddens the structural one, and neither can pass for the
  other's reason. ⛔ **RESIDUAL LIMIT, FILED RATHER THAN BURIED — exit 2 makes a *required* context green
  while nothing was measured**, annotated with a `::warning::` and a job summary. That is a real member
  of the vacuous-success family; it is `D16` and its own row below.
  vulnerable", and both hard-block a merge.** Measured on `M69`'s own PR run (33818367732), which went
  5/6 with the sixth job dying at step 19 of 23: `npm warn audit network timeout at:
  https://registry.npmjs.org/-/npm/v1/security/advisories/bulk` followed by `npm error audit endpoint
  returned an error` and exit 1. **No advisory was involved** — the same command against the same
  lockfile reports `found 0 vulnerabilities` locally, and the diff touched no JS manifest at all.
  ⛔ **THIS IS THE FALSE-RED TWIN OF A CLASS THIS REPOSITORY HAS ONLY EVER MET AS FALSE GREEN.** `I5`'s
  `steps: []`, Pint before its probe, `M61`'s `e2e` wrong form and `M69`'s own PHPStan-crash-exits-0 are
  all gates that report success without measuring. This one reports FAILURE without measuring, and it is
  worse in one specific way: a false green is discovered eventually, while a false red is re-run until it
  passes and **teaches the operator to re-run a red gate**, which is the habit every one of those other
  rows exists to prevent.
  ⚠️ **The remedy is not "add a retry" and that is the whole difficulty.** A retry loop around a network
  call would fix the flake and preserve the conflation, so a genuine registry outage would still read as
  a clean audit once it stopped erroring. The honest shape separates **fetching** the advisories from
  **judging** them: fail the step only on a parsed high/critical finding, and fail it DIFFERENTLY —
  distinctly, and ideally not as a merge block — when the endpoint could not be reached.
  ⛔ **`ci.yml` IS THE USER'S FILE AND THIS IS ALSO A HUB ROW** (seven open rows cite it). `M69` re-ran
  the job rather than editing the gate, and filed this the moment that was decided rather than leaving it
  in a release nobody greps. **Live.** Filed by `M69`.
  ⚠️ **SECOND MEASURED OCCURRENCE, `M70` (2026-09-04), AND IT IS THE RECURRENCE THAT MAKES THIS WORTH
  TAKING RATHER THAN TOLERATING.** It fired on `M70`'s own **post-merge run on the trunk**
  (`33852073344`, sha `c096b8b`), reddening `main` at step 19 of 23 while the other five jobs went green
  — and the same step had passed on that increment's PR run **twenty minutes earlier**, on the same
  lockfile, with no JS manifest in the diff. ⚠️ **The error text differs from `M69`'s and the conflation
  is identical**, which is the point: `M69` saw `npm warn audit network timeout`, `M70` saw
  `npm warn audit 503 Service Unavailable - POST …/security/advisories/bulk` followed by
  `{ error: 'Service Unavailable' }` and the same `npm error audit endpoint returned an error`. **Two
  different registry failures, one indistinguishable red.** ⛔ **And the cost landed exactly where the row
  predicts:** the operator re-ran a red gate on the trunk for the second increment running — the habit
  this row exists to stop — because the alternative is leaving `main` red on a signal that measured
  nothing. **Twice on consecutive increments means it is not a freak**, and a taker now has two logs to
  build the fetch/judge split against.

- **`minor` · `scripts/tracker-lint-controls.php` proves R7 against synthetic histories, and nothing
  proves it against a REAL GitHub `push` or squash.** Filed by `M49` (2026-08-31) at the moment the
  harness shipped, so its limit is a filed constraint rather than a comment nobody re-reads. The
  eleven cases construct their own commit graphs, which is the only way to exercise the `push` arm at
  all — but a real `push` run differs in one way the fixture cannot reproduce: the payload is written
  by GitHub, not by the harness, and `github.event.before` has never been read by this repository.
  ⚠️ **`M49`'s own merge cannot close this**: a squash merge is ONE commit, so `before..HEAD` and
  `HEAD~1..HEAD` are the same range on it, and the close-out push is `paths-ignore`d and produces no
  run. **The first real exercise is the `## Current Status` surgery**, which is a multi-commit push
  and is the open `major` directly above. Read its **post-merge** run on `main`, not its PR run, and
  check the `R7 base is …` line names `github.event.before` rather than `HEAD~1`. **Not live** — a
  stated limit, filed so it cannot be forgotten.
  ⛔ **THE HAND-FORWARD IN THIS ROW IS UNSATISFIABLE AS WRITTEN, AND `M60` FOUND IT BY BEING THE
  INCREMENT IT NAMES (2026-09-02).** The row says the first real exercise *"is the `## Current Status`
  surgery, **which is a multi-commit push**"*. It is not, and no correctly merged increment ever can
  be. A squash merge puts **exactly one** commit on `main`, so `github.event.before..HEAD` holds one
  commit and is identical to `HEAD~1..HEAD` — precisely the collapse this row already identifies for
  `M49`'s own merge, applied one paragraph later to a case it assumed was different. The only way to
  push several commits to the trunk at once is `git push origin HEAD:main` on a loaded branch, which
  is what `M48` did and what `scripts/pre-push-guard.php` now refuses at
  `MAX_DIRECT_TRUNK_COMMITS = 1`. ⚠️ **So `R7`'s multi-commit `push` range is structurally unreachable
  on `main` by any protocol-compliant push**, and the assertion that range supports — that the marker
  may sit on any commit in the push, not only the last — can only ever be exercised by
  `tracker-lint-controls.php`'s synthetic histories or by a `pull_request` run. ✅ **What `M60` DID
  discharge is the half that was actually owed**: a real GitHub squash of a real surgery, arming `R7`
  on a real `push` run on `main`, with the base taken from `github.event.before`. That is `M47`'s and
  `M48`'s hand-forward and it is now spent. **What remains is narrower than this row states** and
  should be re-read as: nothing proves `R7` against a multi-commit trunk push, and nothing ever will
  without deliberately breaking the merge protocol.

- ✅ **DONE — M71 (2026-09-05). THE HARNESS IS COMMITTED AND IT WAS PROVEN ON A REAL FIFTH SURGERY,
  WHICH THIS INCREMENT WAS FORCED INTO RATHER THAN CHOOSING.** `scripts/tracker-surgery.php` holds the
  four proofs the previous four surgeries each re-derived and discarded — a **counted** multiset of line
  hashes, exact byte conservation with the added bytes **stated rather than inferred**, the paths touched
  read from the working tree, and an independent contiguous slice hash sharing no code with the multiset
  — and it **refuses with a distinct exit status** rather than passing when it cannot measure.
  `tests/Feature/Docs/TrackerSurgeryHarnessTest.php` carries eight controls: a dropped line, a changed
  byte, a multiplicity collision, an off-by-the-seam byte count, empty input, a no-op, an unstated
  added-byte count, and the positive baseline without which every one of those reds is ambiguous.
  ⛔ **THE ROW'S PREMISE WAS FALSE IN TWO PLACES.** (1) It says *"with `## Current Status` gone"*. That
  section was **42,737 bytes and 33.2% of the file**, fourteen dated bullets, regrown +3,919 bytes in a
  single day — and it is the section that had to move. The row aimed its hardest-case argument at
  `## Standing Rules`, which is byte-frozen and has not moved at all. (2) `M70`'s correction claimed this
  row collides with `M42`'s R8 row in `scripts/tracker-lint-controls.php`; it does not — the row's word
  is *"mould"*, a pattern to copy, and that file's fixture writer is private and the wrong shape. **The
  two rows do collide, but on `PROGRESS.md`.**
  ⛔ **THE HARNESS'S OWN FIRST DRAFT WAS WRONG AND THE POSITIVE CONTROL CAUGHT IT — the M41/M45 pattern,
  landing on the increment that was building the cure.** Its slice recovery used running counts, which
  marks the *last* occurrences of a duplicated line rather than the removed ones, so blank lines came
  back scrambled and it failed against a **correct** fixture. Recovered by common prefix/suffix instead.
  ⛔ **AND SO WERE THE CONTROLS, IN A WAY ONLY A MUTATION FOUND.** Weakening the counted multiset to a
  set left every control green: they asserted `toContain('A1 multiset')`, a substring that appears in the
  **pass** note as well as the failure, so they were riding on an exit code two other proofs also drive.
  That is the third occurrence of the `M30` `toContain` family here. Now asserted on text unique to each
  failure, after which the same mutation reddens the multiplicity case **alone**.
  ✅ **The surgery itself:** `M66` down to `M57`, 10 lines and 29,867 bytes, `PROGRESS.md` 128,645 →
  98,778 against a 130,000 ceiling — headroom from **1,355 bytes**, under a single measured close-out, to
  roughly 31,200. Two commits, archive first, so nothing left the tracker before the archive held it.
  All four proofs passed on the first run.

- ~~**`minor` · Four tracker surgeries have now hand-rolled the same verification harness and none of
  them kept it.**~~ `M41`, `M45`, `M48` and `M60` each wrote a throwaway splice-and-verify script in the
  session scratchpad and discarded it. `git log --all --diff-filter=A` finds no file ever added under
  `scripts/` whose name contains *surgery*, *splice* or *verify*, and `M45` recorded the same finding
  about `M41` in almost these words — *"the specification is reusable, the script is not."*
  ⚠️ **THE COST IS MEASURED, NOT HYPOTHETICAL, AND IT IS ALWAYS THE CHECK RATHER THAN THE SURGERY.**
  `M41`'s byte-conservation assertion was **wrong on its first run** — it omitted the join seam's
  newline and failed by exactly one byte, against a correct tree. `M45`'s paths-touched assertion
  failed **twice**, with the check at fault both times. `M60` re-derived `M41`'s corrected formula out
  of release *prose*, because there was no code to inherit it from. Three surgeries, three defective
  first-run checks, and the defect in a verification harness presents as a failing surgery.
  ⛔ **AND `scripts/tracker-lint-controls.php`'s OWN DOCBLOCK ARGUES THE OPPOSITE CASE IN ITS OWN
  WORDS:** *"a control that is not committed is a control that ran once."* It was written about `M47`
  building controls for `R7` in a detached worktree and throwing them away — which is the stated reason
  the `fetch-depth` defect survived eight increments. The tracker gate has a committed control harness;
  the tracker *surgery* does not, and the surgery is the operation with the blast radius.
  ⚠️ **What makes this live rather than tidy:** with `## Current Status` gone, `## Standing Rules` is
  **51,072 bytes and 53.9% of the tracker** — the largest section by a wide margin — so a fifth
  surgery is plausible rather than theoretical. It is also a **different kind of move**: live
  imperatives, not dated records, so its boundary must be *decided* rather than measured, which is
  exactly the case where a proved conservation harness is worth most.
  ⛔ **A kept script needs its own positive controls or it is worse than none** — in the
  `tracker-lint-controls.php` mould, and the three that matter are a dropped line, a changed byte and
  **empty input**, that last being the `M48` failure this class keeps producing (three splices read a
  missing file, wrote a blank line and reported success). **Filed by M60 (2026-09-02)** at the moment
  the decision to keep this increment's harness throwaway was taken with the user, rather than
  discovered later. **Live.** Filed by `M60`.
  ⚠️ **CORRECTED BY `M70` (2026-09-04) — THE BYTE FIGURE IS EXACT AND THE PERCENTAGE IS NOT.**
  `## Standing Rules` is **51,072 bytes** to the byte, as recorded — but that is **41.0%** of the
  tracker, not 53.9%: `PROGRESS.md` has grown to 124,589 bytes since this was filed. It is still the
  largest section, and `## Current Status` at 38,818 bytes / 31.2% is now within twelve points rather
  than *"a wide margin"* behind it. The `git log --all --diff-filter=A` claim holds exactly — eighteen
  files have ever been added under `scripts/` and none is named for surgery, splice or verify.
  ⚠️ **A PRECEDENT THE ROW DOES NOT CREDIT, AND A TAKER SHOULD INHERIT RATHER THAN RE-DERIVE:**
  `scripts/next.php`'s `write_line()` **is** a committed, verified splice — it refuses a file with no
  trailing newline, refuses unless the marker count is exactly one, splices by index rather than by
  search, refuses if the line count moved, and reads the file back to assert. `scripts/preflight.php`
  carries two of those three checks and names the empty-input failure this row calls out. What genuinely
  does not exist is anything verifying a **section move across two files** — byte conservation across
  the seam, a counted multiset of line hashes, paths-touched — which `CLAUDE.md` prescribes in prose and
  nothing implements. ⛔ **Do not batch this with `M42`'s `tracker-lint` R8 row**: both fixes land in
  `scripts/tracker-lint-controls.php`, a collision the generated triage cannot see because it harvests
  citations from row text.

- ~~**`minor` · The claim template has a field for a row's evidence and a field for its remedy, and the
  thing that actually went wrong in `M60` was neither.**~~ `docs/claims/TEMPLATE.md` requires
  `### Evidence verified` and `### Remedy verdict`, added by `M36` on the strength of four consecutive
  rows with sound evidence and a broken remedy. **`M60`'s row had sound evidence in kind, an
  implementable remedy, and a PREMISE that had expired** — its central instruction (*"the section has
  two writers, so a slice that moves both lanes' bullets is the one thing the boundary forbids"*) was
  filed 2026-08-29 and falsified on 2026-08-31 when `M50` retired Lane B. **Both existing fields would
  have been answered "held" without the discrepancy ever surfacing**, because neither asks *why does
  this row believe its scope is what it says*.
  ⚠️ **AND IT IS NOT A ONE-OFF — THE SAME SHAPE IS ALREADY IN THE LEDGER TWICE.** `M45` recorded
  *"verify the row's PREMISE, not only its evidence and its remedy"* after a row framed a file as *"the
  second copy of a record that already has a home"* when the overlap was **zero**, so the deletion its
  framing invited would have destroyed the only copy. `M60` is the second. In both cases the premise
  was a sentence about **the world around the defect** — who owns a file, whether a copy exists —
  rather than about the defect, and it is exactly the class that rots while the code does not.
  ⚠️ **The cheap remedy is one more template field and one more claim heading**, which is what `M36`
  did; the honest objection is that a third mandatory field is a third thing to answer "held" to, and a
  field nobody thinks about is the decorative-gate mistake `M43` measured. A better shape may be to fold
  it into `Evidence verified` as *"including any claim the row makes about the world rather than about
  the code"*, which costs no new heading. **That choice is why this is filed rather than taken** — it
  changes a shared artefact every future claim is written against.
  ⛔ **Filed by M60 (2026-09-02), and filed HERE rather than only in the release that found it.** A
  finding recorded only in claim prose is invisible to a backlog search — `J4b1` traced four live
  defects, wrote them in the tracker and nowhere else, and they stayed unfindable until someone
  re-read the increment. Filed by `M60`.
  ✅ **DONE — M69 (2026-09-04). THE ROW OFFERED TWO SHAPES AND OBJECTED TO BOTH; THE USER CHOSE THE
  THIRD HEADING, AND IT SHIPS GATED SO IT CANNOT BECOME THE THING IT WARNS ABOUT.**
  `docs/claims/TEMPLATE.md` declares `### Premise verified`; `CLAUDE.md`'s claim bullet is now three
  answers rather than one; `scripts/preflight.php` refuses an ACTIVE claim missing any declared field;
  `tests/Feature/Docs/ClaimTemplateFieldsTest.php` pins which fields the template declares.
  ⛔ **THE FIELD LIST IS DERIVED FROM THE TEMPLATE, NOT RESTATED IN THE CHECK** — add a field and
  preflight demands it on the next run with no edit. That is also why the Pest arm is not redundant: a
  derived check inherits its source's failures, so deleting a heading would make preflight quietly stop
  requiring it **while still printing `[ok]`**, which is worse than no gate.
  ⚠️ **The two halves read the artefact from different places, deliberately.** The TEMPLATE comes from
  the working tree — it is the protocol you are building under, and the increment adding a field should
  be the first held to it. The CLAIM comes from `origin/main`, because an unpushed claim does not exist.
  `M69` wrote its own claim with the field before the field existed, for that reason.
  ⚠️ **The parser's first draft returned an EMPTY LIST and every check passed vacuously** — it tested
  for a `## ` heading before the fence, and the template's example opens with `## Status: ACTIVE CLAIM`.
  Caught only by the empty-result floor at the call site (the `M48` class: an operation that succeeds on
  empty input). Both halves now carry that floor.
  ⚠️ **What is gated is that the question is ASKED and ANSWERED, never that the answer is RIGHT** — the
  same limit `BacklogProvenanceTest` carries for the liveness marker. A claim answering "held" under
  every heading passes everything. Recorded rather than papered over.

- **`minor` · The service worker caches a credential-bearing resume shell, and only the RENEWAL of it was
  closed.** `M70` stopped `refreshCachedShells()` re-`put`ing `/f/resume/…` entries, so one ages out on
  `sw.ts`'s seven-day clock instead of being renewed from every later boot. **The initial write is
  untouched, deliberately and with the user**: `sw.ts` still `NetworkFirst`-caches a resume navigation, and
  that HTML carries `data-resume-token`, which is the whole credential for `GET /api/v1/public/drafts/…`
  and its full answer map. ⛔ **Two reasons it was not taken, and the second is the one that would bite a
  taker.** (1) It costs offline resume access outright, contradicting `lib/brand-cache.ts`'s own stated
  axiom that a respondent *"never loses the form"* — a real product trade, not a cleanup. (2) **It is not
  mutation-provable in the suite that exists.** There is no `sw.test.ts`;
  `__tests__/register-sw.test.ts` asserts only that `/sw.js` is registered with `{ scope: '/f/' }`. So a
  new predicate clause in `sw.ts` could be deleted with the entire Vitest suite still green, and only a new
  e2e case in `tests/e2e/public-runtime-offline.spec.ts` — which today asserts offline rendering of
  `/f/{slug}`, not `/f/resume/…` — could see it. **Whoever takes this owes the e2e case first, or the fix
  is unfalsifiable.**
  ⛔ **RE-AIMED BY `M78` (2026-09-06): BOTH STATED BLOCKERS ARE DEAD, AND WHAT REPLACES THEM IS A PRODUCT
  TRADE THAT IS THE USER'S CALL — SEE `D20`. THE ROW STAYS OPEN ON THAT DECISION AND ON NOTHING ELSE.**
  ✅ **Blocker (2) is stale.** *"There is no `sw.test.ts`"* was true when filed; `M77` created it in
  `1685faf`, three increments later. It captures every route's `match` and — until `M78` — never invoked
  one. `M78` made those matchers live and added the assertion this row's *"one route rename re-opens it"*
  warning was asking for: the resume READ is claimed by NO cache, proved CAUGHT by widening the schema
  prefix one path segment, which reddens that arm alone out of eleven.
  ⛔ **Blocker (1) is FALSE, measured.** *"It costs offline resume access outright"* — `App.vue`'s
  `loadResume()` opens with `resumeDraft(resumeToken)`, a bare fetch to a path no service-worker route
  matches; offline it rejects and the IndexedDB read two calls downstream is structurally unreachable.
  **A cached resume shell has never rendered the form offline.** `docs/offline-first-sync-design.md` claimed
  otherwise and has been corrected in place.
  ⛔ **AND THE OBVIOUS FIX IS NOT TWO LINES, FOR THREE SEPARATE REASONS, ALL MEASURED.** (1) Purging the
  entry costs a resume-link-only respondent their ONLY cached navigation — with it goes the offline pill,
  the sync surface and the always-visible *"Sync now"* that `docs/non-functional-requirements.md` §7 makes
  the iOS Background-Sync fallback (corroborated by a stored Playwright accessibility snapshot showing that
  surface rendering on an empty queue). (2) It makes `isResumeShell()` in `lib/brand-cache.ts` guard a
  condition that can no longer arise, turning its three dedicated cases **vacuously green** — the
  succeeds-on-empty-input shape this repo gates against, and the exact predicate `M75` worked to make
  load-bearing. (3) Resolving those cases makes this row cite `brand-cache.test.ts`, **the one non-hub file
  the open second-writer row already cites**, barring the two from one batch under `D13` — which is
  precisely what `M74` refused to do. The triage harvester cannot see (3), because it harvests from prose.
  ⚠️ **The exposure is also LARGER than this row states**: Cache Storage is origin-scoped rather than
  per-document, and the credential IS the cache key — `caches.open('guest-shell-html').keys()` enumerates
  every resume token on the device without reading a body, so stripping `data-resume-token` from the HTML
  would not close it. ✅ Sharpened, not just re-scoped: the resume response also returns a freshly minted
  share token, so the URL segment is a WRITE credential, not only a read.
 ⚠️ **And the cheaper half of the exposure is not in `sw.ts` at all:** the resume READ
  escapes caching only because its path prefix is `drafts/` rather than `f/`; `routes/api.php` now says so
  at the site, and one route rename or a consolidation of the two public groups re-opens it. **Live.**
  Filed by `M70`.

- **`minor` · The audit spec's §1 table is asserted by nothing, and a static sweep cannot be the thing that
  asserts it.** `docs/audit-compliance-logging-spec.md` §1 calls itself *"a definitive, checkable list"*;
  all twelve files in `tests/Feature/Audit/` pin call sites and **none of them reads the document**, so the
  section has never been compared to the code. `M70` closed one instance of the resulting drift and
  measured the class before trying to gate it. ⛔ **The measurement is why this is filed rather than built,
  and it has two halves.** (1) **Red on arrival on ten aliases, not one** — `connection`, `domain`, `form`,
  `settings`, `sso_connection`, `submission`, `tenant_users` and `users` all appear to credit events a
  harvest cannot find, and each is a separate judgement about whether the document or the code is wrong.
  (2) ⛔ **The comparison is NOT statically derivable, which is the harder half.** A harvest keyed on
  `record(AuditEvent::X, 'alias'` finds 34 call sites and is a **floor**: `CustomDomainService` passes the
  event as a variable (`record($event, 'domain', …)`), `SsoConnectionService` passes **both** through a
  private helper as `$event` and `self::AUDIT_TYPE`, and `ImpersonationService` is the same shape — so
  three services verified as emitters are absent from the harvest entirely, and roughly half the apparent
  over-claims are the parser rather than the tree. ⚠️ **A gate built on that parser would report confident
  nonsense**, which is worse than no gate. The honest forms are runtime observation (drive each action and
  read the `audits` table) or a per-service registry the emitters declare; both are their own increment.
  ⚠️ **The document side needs a discriminator too** — a naive scan of backticked tokens in the events
  column harvests `owner_user_id`, `is_super_admin` and `status`, which are column names in prose. **Live.**
  Filed by `M70`.

- ✅ **DONE — M79 (2026-09-06). BOTH HALVES OF THE PRESCRIBED REMEDY SHIPPED AND EACH WAS PROVED BY RUNNING THE ROW'S OWN SCENARIO, NOT BY READING.** `--jsonn` now refuses with exit 1 and leaves `docs/backlog-triage.md` byte-identical; `--help` prints usage, exits 0, and leaves it byte-identical. The row was right that the refusal is the half that matters, and right that this is the only script in `scripts/` whose default action is a write — the fence reads `$argv` directly, because `getopt()` discards what it does not know and there is no other way to see what it threw away. ⚠️ **Taken as a side-effect rather than as a claimed row:** M79 was adding `--json` for `scripts/pipeline.php` and had to touch this exact argument handling, so fixing it was cheaper than working around it. Original filing follows. **`minor` · `scripts/backlog-triage.php` accepts no arguments and its default action is destructive, so
  any unrecognised flag rewrites the file.** `getopt('', ['dry-run', 'check'])` silently discards every
  long option not in that allowlist, and there is no `--help` handler, no usage printer and no rejection of
  an unknown flag — so `php scripts/backlog-triage.php --help` falls straight through to the write path,
  regenerates `docs/backlog-triage.md` and exits **0** with a success message. ⚠️ **Measured twice
  independently in one session** (`M70`), by the session and by a read-only agent, each expecting usage
  text and each dirtying the tree instead. ⛔ **It is the only script in `scripts/` whose DEFAULT action is
  a write.** `scripts/next.php` and `scripts/gate-baselines.php` share the permissive `getopt` shape and are
  harmless because they default to rendering to STDOUT; this one does not. ⚠️ **It is also the only script
  in `scripts/` with no `composer.json` alias**, which is a separate row's subject and is noted here only
  because the two together mean the usual way to discover how to run it is the way that overwrites a file.
  The remedy is small — a `--help` arm and a refusal on any unrecognised option — and the refusal is the
  half that matters, because the succeeds-on-empty-input family this project has now measured five times is
  exactly a wrong invocation that reports success. **Live.** Filed by `M70`.

- **`minor` · The triage generator's collision check harvests citations from row TEXT, so it proposes
  batches that collide.** `scripts/backlog-triage.php` builds its *"Suggested next batch"* by comparing the
  files each row **names**, and `D13`'s selection rule is *"no two rows citing the same non-hub file"* — so
  a row whose fix cannot avoid a file it never mentions is scored as touching nothing. ⛔ **Measured on the
  generator's own output at `M70`:** it proposed `M42`'s *"`tracker-lint` R8 guards `CLAUDE.md`"* and
  `M60`'s *"Four tracker surgeries have now hand-rolled the same verification harness"* in one batch. Both
  fixes land in `scripts/tracker-lint-controls.php` — the first because a new rule group needs `$cases`
  entries and a parameterised `write_fixture_files()`, which hard-codes a `## Standing Rules` fixture body
  and would therefore execute the new rule inside all eleven existing `R7` fixtures; the second because
  that file is the row's explicitly named mould. The first row is scored *"hub files only"*. ⚠️ **The
  failure is silent and points the wrong way**: an unharvestable citation makes a row look MORE separable,
  not less, so the generator's confidence is highest exactly where it is least earned. **Not live** — the
  file already states that it cannot check a row whose files were not harvested, and the proposal is
  labelled *"a proposal to check, not a schedule"*; what is missing is that a row with **partial** harvest
  is indistinguishable from a fully-harvested one. The cheap improvement is to mark rows whose harvest is
  incomplete rather than to guess their footprints. Filed by `M70`.


- **`minor` · `scripts/loop.php --assess` does not read `docs/pipeline.md`, so it cannot refuse a row on its PIPELINE state.** Filed 2026-09-06 by `M79`, deliberately unfixed there. `HELD_TOPICS` refuses the five held topics by substring match on the row text, which is a coarser mechanism that happens to cover the same ground today — so this is a redundancy gap rather than a live hole. ⚠️ **The reason to wait is not effort:** making a STOP-LIST depend on a generated file means an incomplete generation makes the stop-list under-refuse, and an under-refusing stop list is unsafe where an over-refusing one is merely annoying. It belongs with the gate that guarantees the pipeline is complete, not before it. **Live.** Filed by `M79`.

- **`minor` · A pipeline marker placed mid-document SILENTLY INVALIDATES every `path:N` citation beneath it, and only the ones that land on a blank line are caught.** Filed 2026-09-06 by `M79`, found by its own gate going red. Markers were first placed next to the sentence they govern, as the design said. `citation-liveness-lint` then failed on two citations in `docs/adr/0009-…` pointing at `0008-entitlement-and-metering.md:8`, which a two-line insertion had pushed into a blank. ⛔ **THE CAUGHT CASE IS THE LUCKY ONE.** That gate only sees a citation landing on a blank, a rule, a fence or past EOF — a citation shifted onto a DIFFERENT REAL LINE resolves happily and is wrong, and nothing in the repository can see it. **25 line-numbered citations point into the six files that carry markers**, so the exposure was measured rather than guessed. ✅ **Fixed in the same increment by moving every marker to END OF FILE, which shifts nothing**, with the reason written beside them so the next author does not helpfully move them back. ⚠️ **What is still open, and it is the reason this is a row rather than a closed note:** end-of-file placement costs the adjacency the design wanted — `Source` now names the marker's line, not the obligation's, so a citation can point a hundred lines from the sentence it governs. Two honest repairs exist — an `anchor=` key naming the governed line, or attributing a marker to the nearest preceding heading — and neither belongs in an increment already building the spine. ⚠️ A marker also cannot sit inside a markdown table or list at all, since an HTML comment at column 0 terminates both; that is why the tracker's held-list line could take none. **Live.** Filed by `M79`.

- **`minor` · `R7`'s byte threshold was calibrated on surgeries that were all left too late, so acting
  EARLY lands in its dead zone.** Measured on `M71`'s own surgery, which is the first evidence of this
  and is why it is filed rather than argued: the move dropped **29,867 bytes** in 10 lines — deliberate,
  declared, and **under both** `DROP_BYTE_LIMIT` and `DROP_LIMIT` — so `R7` classified it as an ordinary
  edit and printed *"under both limits"*. ⛔ **The calibration is sound and its input was biased.** The
  constant's own comment derives 50,000 from a bimodal history: ordinary drops at 14,340 or less,
  surgeries at 161,528 and up. But every surgery in that history was performed at the last moment, when
  the ceiling forced it — `M60` acted at 3,970 bytes of headroom and `M45` at less. **The size of a
  surgery in that sample is a function of how long it was deferred, not of what a surgery is.** `M71`
  acted at 1,355 bytes of headroom by choice, and the reward for the better practice is a gate that
  cannot see the operation. ⚠️ **The remedy is not obviously "lower the limit"**: 29,867 and the largest
  ordinary drop of 14,340 are only a factor of two apart, so a threshold between them has far less room
  than the current one, and `R7`'s whole value is that it does not cry wolf on close-outs. Candidates,
  none costed: key the arm on the marker's PRESENCE rather than on a size (a declared surgery is checked
  whatever its size, and an undeclared large drop still fails); or gate on the fraction of the file
  removed rather than an absolute count; or accept that `R7` guards only catastrophes and let
  `scripts/tracker-surgery.php` be the instrument for a deliberate move, which is what actually happened
  here. ⚠️ **Note the interaction before taking it:** the third option is already the de-facto state, and
  it means the `[tracker-surgery]` marker is currently unverifiable for any well-timed surgery — which is
  the `M47`/`M48` hand-forward re-opening in a new form. **Live.** Filed by `M71`.

- ✅ **CLOSED BY `M73` (2026-09-05), TOGETHER WITH THE `M72` ROW BELOW — `minor` · ~~`scripts/mutate.php`'s concurrent-suite guard passes VACUOUSLY when Docker is unreachable.~~**
  The probe now captures the exit status and tests `is_numeric()`, matching `preflight.php`.
  ⚠️ **THE ROW NAMED ONLY HALF THE FIX, AND THAT HALF ALONE IS NOT SUFFICIENT.** *"The hardened form
  already exists one file away"* is true as far as it goes, but `preflight.php`'s probe also carries
  **`2>&1`** and this one did not — so porting `is_numeric` alone would have refused while printing an
  EMPTY diagnostic, naming nothing at the moment a reader most needs the cause. Both halves are the guard.
  ✅ **And the `git status` sibling is closed with it.** git is ABSENT from the app container while
  `/var/www/html/.git` is visible over the bind mount, so BOTH `git status --porcelain` checks passed
  vacuously in any in-container invocation — `trim('') !== ''` is false whether the tree is clean or git
  could not run at all. Both now capture the status, including the post-restore one whose message is *"Do
  not trust this tree."*
  ⚠️ **The two rows could not be batched as two rows** — one hub file, `D13` — and merging them into one
  cost a single hub slot instead of two increments. Closed by `M73`.
  Found by a read-only agent during `M71`'s fan-out, in the file this project wrote to end vacuous
  successes. Its `R1` probe shells a `docker exec` and **does not capture the exit status**: when the
  daemon is down, the container is renamed, or `--container` is mistyped, `exec` returns empty output,
  `(int) trim('')` is `0`, the `!== 0` comparison is false, and the guard reports that no suite is
  running. ⛔ *"Refuse to run beside a live suite"* silently becomes *"assume no suite is running"* in
  **precisely the situation that makes it likely** — a broken or restarting Docker. The failure it exists
  to prevent is `M34`'s, where a concurrent `php artisan test` dropped the schema under a live mutation
  run and produced three phantom failures that read as real ones. ✅ **The hardened form already exists
  one file away**: `scripts/preflight.php` tests `is_numeric()` on the same probe and warns *"could not
  probe"* on anything else, so this is a two-line change with a written precedent rather than a design
  question. **Live.** Filed by `M71`.

- **`minor` · Two more scripts have `backlog-triage.php`'s destructive default, and the row that filed
  that defect names one of them as its harmless counter-example.** Measured by a read-only agent across
  all eighteen files in `scripts/` during `M71`'s fan-out. ⛔ **`scripts/gate-baselines.php` has the
  identical shape** — `--dry-run` prints to STDOUT and the **default path writes** `docs/gate-baselines.md`
  — while the open row asserts it is *"harmless because [it] default[s] to rendering to STDOUT"*. That
  sentence is false, and it is load-bearing, because it is what makes the original row look like a
  one-file fix. ⛔ **And `scripts/regenerate-brand-ramp-fixture.php` is worse than either**: no `getopt`,
  no `$argv` inspection, no `exit`, no conditional of any kind — every invocation overwrites
  `tests/fixtures/brand-ramp.json`, which is asserted hex-for-hex by a Pest suite **and** by the
  design-system's TypeScript mirror. Its own docblock says running it *"turns [a bug] into [a deliberate
  engine change] without anyone noticing"*. ⚠️ **The alias claim is wrong in the same direction**: three
  scripts have no `composer.json` alias, not one — `backlog-triage.php`, `pre-push-guard.php` (driven by
  the hook) and `regenerate-brand-ramp-fixture.php` (driven by nothing). **The class has three members
  and should be fixed as a class**, with a `--help` arm and a refusal on any unrecognised option; the
  refusal is the half that matters, and it must read `$argv`, because `getopt()` cannot report what it
  discarded. **Live.** Filed by `M71`.
  ⚠️ **A THIRD INDEPENDENT OCCURRENCE, `M73` (2026-09-05), AND THE FIRST AGAINST `gate-baselines.php`
  RATHER THAN `backlog-triage.php`.** Closing out `M73`, `php scripts/gate-baselines.php --help` was run to
  read the usage before regenerating — there is no help arm, so it fell straight through to the write path,
  rewrote `docs/gate-baselines.md` and reported success. ⛔ **What makes this worth adding rather than
  merely tallying: it wrote the CORRECT content.** The script's no-`--run` branch finds the latest
  successful run on `main`, which happened to be the very run the close-out wanted — so the accident was
  invisible in the diff and would have been invisible in review. **A destructive default that usually
  produces the right answer is harder to notice than one that corrupts**, and it is why the remedy's
  refusal half matters more than its `--help` half. ⚠️ Note also that all three occurrences so far were an
  operator asking the script how to run it; none was a typo.
  ➕ **CENSUS RE-MEASURED BY `M74` (2026-09-05), WHICH DELIBERATELY DID NOT TAKE THIS ROW.** It would have been
  a second hub-touching row under `D13`, and taking only its `gate-baselines.php` third would have falsified
  this row's own census a fourth time — so the census is corrected here instead. ⛔ **Three numbers moved:**
  `scripts/` holds **twenty** files, not eighteen (`npm-audit-judge.php` and `tracker-surgery.php` both landed
  after this row was written); **four** scripts have no `composer.json` alias, not three — `npm-audit-judge.php`
  is the fourth and is invoked directly from `ci.yml`; and `scripts/gate-baselines.php` **does** have an alias,
  so the "usual way to discover how to run it is the way that overwrites a file" argument applies to
  `backlog-triage.php` and `regenerate-brand-ramp-fixture.php` but not to it.
  ✅ **What the class HAS is now exact: three members** — `backlog-triage.php`, `gate-baselines.php` and
  `regenerate-brand-ramp-fixture.php` are the only scripts whose DEFAULT action writes a tracked file. The
  permissive-`getopt` property is a much larger floor (nine), and the two must not be conflated.
  ⛔ **AND THE REMEDY IS NOW A TRANSPLANT RATHER THAN A DESIGN.** `scripts/npm-audit-judge.php` implements the
  exact refusal this row prescribes — reading `$argv`, splitting on `=` so a valued option survives, and
  putting the `--help` arm AFTER the refusal so `--help --bogus` still refuses — and its own comment cites
  this row by description. ⚠️ **A value-taking option is the trap**: `gate-baselines.php` accepts `--run=`, so
  a naive `in_array($argument, …)` refuses a correct invocation.
  ⚠️ **`regenerate-brand-ramp-fixture.php` needs more than the others and it is worth pricing before starting**:
  its write target is hard-coded, so it cannot be driven by a control at all without an `--out=` seam, and a
  `--help`-only fix leaves a bare accidental invocation still overwriting the fixture.

- **`minor` · `docs/backlog-triage.md` is generated stale by its own close-out, and the drift is on the
  trunk now.** Measured during `M71`'s fan-out: `69eaaf2` added 13 lines to `docs/feature-backlog.md` in
  **the same commit** that regenerated the triage from the pre-addendum tree, so every generated line
  anchor below that insertion point is 13 too low — including the ones in *"Suggested next batch"*, which
  is the section a reader is meant to act on. `php scripts/backlog-triage.php --check` would exit 1 today
  and **nothing runs it**. ⛔ **The one instrument that watches this file is tuned to be silent about
  exactly this**: `scripts/state.php` reports staleness in *commits*, and excludes commits inside
  `ci.yml`'s `paths-ignore` — which a close-out's triage regeneration always is. So the gap is not
  "unobserved", it is "observed for staleness and blind to content". ⚠️ **The cheapest wiring is one line
  and the open row does not consider it**: `scripts/loop.php`'s `$gates` array already runs `state --check`
  on the host and already distinguishes exit 2 from exit 1, which is the three-way contract this script
  publishes. ⛔ **What genuinely needs deciding first** is that wiring it changes what a close-out is
  *obliged* to do, since the file goes stale by construction on every merge that touches a row — and the
  increment that closes this row would red its own new gate on its own close-out, which is the trap
  `pre-push-guard.php` records `M52` walking into. **Live.** Filed by `M71`.

- ~~**`minor` · *"`ci.yml` is the USER'S FILE"* is asserted by one backlog row and by nothing else.**~~
  ✅ **DONE — M72 (2026-09-05), BY TAKING THE ROW IT WAS BLOCKING, WHICH IS THE ONLY ONE OF ITS TWO ARMS
  THAT SURVIVES.** Re-verified independently: the phrase appears **exactly once** in this repository, in
  the row asserting it; `CLAUDE.md` does not mention `.github/` at all; no ADR covers ownership; and
  `git log --follow` returns **24** commits of which **seven** are M-series increments inside their own
  pull requests (`M28`, `M39`, `M40`, `M46`, `M48`, `M49`, `M57`). ⛔ **AND THE ROW UNDERCOUNTS ITSELF**:
  under any reading wider than the M-series, `I0`, `I5`, `I11b`, `H2`, `G5b1`, `F6a`, `E`, `C3`, `B2c`,
  `B1` and `A` edited it too — roughly nineteen increment-authored commits. The error runs in the safe
  direction. ⛔ **A THING THE ROW MISSES AND IT CUTS ITS OWN WAY: `docs/claims/decisions.md` IS NOT SILENT
  ON THIS FILE.** `D8` asks how `ci.yml` should regain the trunk observation and **recommends an option**
  — so the standing decision record already treats it as editable, which corroborates the row from a
  direction it never looked. ⛔ **ITS OTHER ARM IS REJECTED, NOT DEFERRED:** writing *"`ci.yml` is out of
  bounds"* into `CLAUDE.md` would enshrine a claim seven increments' history refutes. ⚠️ **ONE CAVEAT THE
  ROW OMITS AND WHICH CHANGED WHAT WAS BUILT:** `PROGRESS_ARCHIVE.md:544` records `7154d5f` as carrying
  **two decisions locked with the user** — the agent wrote the `--omit=dev` flag, the *policy* was
  user-ratified. So `M72` fixed the conflation and moved neither the scope nor the threshold. **Closed
  with the `npm audit` row above rather than as a row of its own, because its entire remaining diff is a
  sentence in this file** — which the batching contract excludes from a footprint.
  Checked across the whole repository during `M71`'s fan-out because it was the stated reason a row was
  filed rather than fixed: the phrase appears **exactly once**, in the row that asserts it. ⛔ It is in no
  standing rule, no ADR and no decision. `CLAUDE.md` does not mention `.github/` at all; its only
  ownership rule routes *decisions* to `docs/claims/decisions.md`. `D13` treats `ci.yml` as a **hub file**
  and explicitly budgets one hub-touching row per batch — which is a budget, not a fence. ⛔ **And the
  file's own history refutes it flatly**: seven increments have edited it inside their own pull requests,
  its inline comments are written in increment voice, and the `--omit=dev` flag on the very `npm audit`
  line in question **was itself written by an increment**. ⚠️ **This matters beyond tidiness** — it is the
  premise on which the `npm audit` false-red row was deferred, and that row has now reddened the trunk
  twice. A constraint nobody wrote down is not a constraint; if `ci.yml` really is out of bounds, it
  belongs in `CLAUDE.md`, and if it is not, the rows deferring on it should be re-costed. **Live.**
  Filed by `M71`.

- **`minor` · `M59`'s undeclared-`http-server` row undercounts on both of its numbers.** Re-measured
  during `M71`'s fan-out, before the row was considered for a batch. ⛔ **`wait-on` is equally
  undeclared**, and the row says the opposite — *"`concurrently` and `wait-on` are at least present in the
  root tree"*. It is present only as a **fourth-level transitive** of `@storybook/test-runner`, pinned by
  `jest-process-manager`, reachable purely by hoisting, and declared by nothing in this repository. That
  is the more insidious of the two failure modes, because it disappears the day the test runner reshuffles
  its dependency tree. ⚠️ **And the count is two invocations, not three** — one in `ci.yml`'s axe job and
  one in `README.md`; the phrase *"both halves of the README recipe"* counts `http-server` and `wait-on`,
  which are different packages. ⛔ **No existing gate can prove a fix**: `DocumentedCommandDriftTest`
  reads the `scripts` map of the two `package.json` files and **never** their dependency blocks, so
  deleting a declared devDependency would turn nothing red. The remedy is therefore not *"one
  devDependency line"* — it is two packages, two regenerated lockfiles, and a new gate. ✅ Ownership is
  settled rather than open: the axe step runs with `working-directory: packages/design-system`, so that
  package owns them. **Live.** Filed by `M71`.

- ~~**`minor` · `M59`'s `ds:storybook` alias row rests on a false premise and the alias alone would not~~
  ✅ **DONE — M72 (2026-09-05), AND THIS ROW'S OWN MECHANISM IS FALSE IN THE HALF IT USED FOR SIZING.**
  Its conclusion is right — a compose-file change is required — and its reason is not. It asserts *"the
  package script binds without `--host`"*; read out of the installed Storybook 8.6, `-h, --host` is
  declared with **no default value**, and Node's `listen({port, host: undefined})` binds the
  **unspecified** address, i.e. every interface. **Publishing 6006 is sufficient on its own**;
  `--host 0.0.0.0` was added for consistency, not necessity. ⚠️ **AND IT MISATTRIBUTES A CITATION**: the
  *"Blank page / Vite HMR under Docker"* note it invokes two sentences after citing the design-system
  README lives at **root** `README.md:134`, and it says the opposite of what the row uses it for — a
  client-side URL problem on a server that binds correctly. ⚠️ **BOTH ROWS ALSO INHERITED A STALE FRAMING
  NEITHER CHECKED**: that README still called itself a *"Phase 0 seed"* whose components were
  *"(Phase 0: `Button`)"*, six lines above the block they were arguing about. ⛔ **AND THE MEASUREMENT
  THAT ONLY STARTING THE SERVER COULD PRODUCE: publishing the port is NECESSARY AND NOT SUFFICIENT.**
  A Windows-host `npm install` here runs `--no-bin-links`, leaving a **split tree** — the `storybook` CLI
  hoisted to the root `node_modules`, `@storybook/vue3-vite` only in the package's, and no `storybook`
  shim in the package's `.bin` — so the root CLI resolves presets from the root tree and dies on
  `Cannot find module '@storybook/vue3-vite/preset'`, naming a package that **is** installed one
  directory away. `docker compose exec node npm run ds:install` produces a coherent install; the preview
  then answered **HTTP 200 from the host in ~25s**. Recorded in that README, with the honest note that
  **nothing gates it and nothing can** — it is a property of a gitignored `node_modules`.
  work.** Re-measured during `M71`'s fan-out. ⛔ The row's argument is that the alias *"would also give
  the component library a local preview, **which nothing currently documents**"*.
  `packages/design-system/README.md` documents `npm run storybook` in a fenced block — and, worse for the
  repo and better for the row, the very next line is a mapping table from package scripts to root aliases
  that lists three of the four and **silently omits the one with no alias**. The defect is not an
  undocumented preview; it is a document that maps its own scripts to root aliases and quietly drops the
  unmapped one. ⛔ **And the one-line remedy does not deliver a preview**: `docker-compose.yml`'s `node`
  service publishes `5173` and **not** `6006`, and the package script binds without `--host`, so
  `storybook dev` inside that container answers nothing a host browser can reach — the same shape as the
  README's own *"Blank page / Vite HMR under Docker"* note. A working preview needs the port published and
  a host bind, in a live-stack file the row never mentions. **Live.** Filed by `M71`.

- ✅ **CLOSED BY `M73` (2026-09-05) — `minor` · ~~The `/f/*` navigation route caches an opaqueredirect, because it is the one route whose sibling filters for `200` and it does not.~~**
  The `/f/*` route now carries the sibling `guest-schema` route's own
  `CacheableResponsePlugin({ statuses: [200] })`. **Measured at the browser, both arms, entries printed per
  arm**: without it the shell cache holds `/f/Clinic-Intake` at `status 0, opaqueredirect` beside the
  canonical `status 200, basic`; with it, one key.
  ⛔ **THE ROW'S MECHANISM WAS FALSE, AND ITS EXCULPATION WAS FALSE IN THE DIRECTION THAT MATTERED.**
  *"Workbox's status filter never runs"* — it does. `NetworkFirst`'s constructor tests
  `'cacheWillUpdate' in p`, **not** whether any plugin is present, and `ExpirationPlugin` declares only
  `cachedResponseWillBeUsed` and `cacheDidUpdate` — so `cacheOkAndOpaquePlugin` **is** prepended and it
  admits `status === 200 || status === 0` **by design**. The stub was cached because a filter ALLOWED it,
  not because none ran. Verified in the shipped `public/build/sw.js`, not only in `node_modules`.
  ⛔ **And the row credited the offline mis-cased render to the browser following the cached stub.** If
  that were the mechanism, dropping the stub would break the offline path. It does not: with no stub at all
  the render still passes. **What actually delivers the guarantee is NOT established** — filed below rather
  than guessed at a third time on the one route that has already produced two confident wrong models.
  ⚠️ **The premise that deferred it was also false**: *"the fix is in `sw.ts`, a hub file"*. `sw.ts` is
  cited by **two** open rows against a `HUB_THRESHOLD` of 3, and the triage `M72` regenerated in its own
  close-out lists it as a NON-hub cite. The `D13` budget never bound. Closed by `M73`. Measured by `M72` (2026-09-05) at the browser while
  building the proof `M61` asked for, and it is the reason that proof came back saying something other
  than what the row it closed expected. After a mis-cased entry, `guest-shell-html` holds **two** keys:
  `/f/clinic-intake` at `status 200, type 'basic'` and `/f/Clinic-Intake` at `status 0,
  type 'opaqueredirect'`. ⛔ **The asymmetry is one line.** `resources/public-runtime/sw.ts`'s
  `guest-schema` route carries `new CacheableResponsePlugin({ statuses: [200] })`; the `/f/*` navigation
  route carries only `ExpirationPlugin`, so Workbox's status filter never runs and the 301 stub is
  stored. ✅ **This is NOT a broken offline path, and saying so is the point of filing it rather than
  calling it a bug**: the browser follows a cached opaqueredirect to the canonical entry, which is
  cached correctly, so a mis-cased entry still renders offline — measured, not argued. ⚠️ **What it costs
  is a slot.** `maxEntries: 20` is shared, so enough mis-cased variants evict the canonical shell, and
  *that* is the failure — silent, device-local, and reached only by a respondent who was already using a
  wrong-cased link. ⚠️ **Filed rather than fixed for a stated reason**: the fix is in `sw.ts`, a hub file,
  and `D13` allows one hub-touching row per batch, which `M72` spent on `ci.yml`. The remedy is the
  sibling route's own line. **Live.** Filed by `M72`.

- ✅ **CLOSED BY `M73` (2026-09-05), TOGETHER WITH THE `M71` ROW IT SHARES A FILE AND A SHAPE WITH — `minor` · ~~`scripts/mutate.php`'s `run_pest()` MANUFACTURES a `SURVIVED` verdict when Docker is unreachable, which is worse than the guard defect filed beside it.~~**
  `run_pest()` now returns `measured` — the presence of a `Tests:` summary line, which is the only positive
  evidence that Pest itself spoke — and both call sites refuse an unmeasured run. The exit code stays a
  **did-it-run** signal and never a **failure** signal, which is the distinction this file's own comment
  already drew and which the row quoted correctly.
  ⛔ **THE MUTANT ARM RESTORES BEFORE IT REFUSES, AND NEITHER ROW NAMED THIS.** `run_pest()` is called a
  second time AFTER the mutant is on disk, so the obvious refusal — written inside `run_pest()` — would
  abort between the write and the restore and leave the mutant behind, which is exactly how `M62` corrupted
  a tree. The refusal therefore lives at the CALL SITE, with the original bytes in scope.
  ⚠️ **One correction**: `run_pest`'s command ends in `2>&1`, so its capture is NON-empty — the daemon
  error IS caught. The outcome was unchanged, but the mechanism differs from the concurrent-suite probe,
  which had no `2>&1`, **so the two sites needed different guards** and neither row said so.
  Six controls in `tests/Feature/Docs/MutateHarnessTest.php`, driven through a `MUTATE_DOCKER` stub seam
  because the discriminator needs a reachable php-less runtime and none exists on a CI runner. Four
  mutations, all CAUGHT, each reddening a DIFFERENT case. Closed by `M73`. Found by a read-only agent during
  `M72`'s fan-out, in the same file and the same shape as the concurrent-suite row above. `run_pest()`
  calls `shell_out()` without the status argument, so a failed `docker exec` returns `''`; no line starts
  with `Tests:`, `$failed` stays `0`, and the baseline gate `if ($baseline['failed'] > 0)` **passes an
  unmeasured baseline** — defeating this file's own headline rule, *a red proves nothing if you cannot
  show it was green*. The verdict `$mutant['failed'] > $baseline['failed']` is then `0 > 0`, false, and
  the harness prints *"SURVIVED — the mutant changed NOTHING… That is the finding — file it rather than
  explaining it away"* and exits `EXIT_SURVIVED`. ⛔ **A Docker outage does not merely skip a check: it
  fabricates a finding that reads as a measured result and invites a backlog row.** ⚠️ The existing
  comment (*"the exit code cannot be trusted — pest has returned 0 alongside `Tests: 5 failed`"*)
  justifies ignoring the code as a **failure** signal and not as a **did-it-run** signal. ⚠️ **Both
  `git status --porcelain` checks have the same shape**, including the post-restore one whose message is
  *"is STILL DIRTY after the restore. Do not trust this tree."* — it cannot fire if git is what broke,
  and git is **absent inside the app container**, so any in-container invocation has a permanently
  vacuous `R2`. ✅ The correct pattern is 60 lines away in the same file: the `php -l` probe captures
  `$lintStatus` through `shell_out`'s existing by-ref third parameter and checks it.
  **Live.** Filed by `M72`.

- **`minor` · `lib/brand-cache.ts` is a SECOND writer to `guest-shell-html`, and it renews a mis-cased
  key with a response a navigation cannot use.** Found during `M72`'s fan-out; `M61`'s docblock
  enumerates four storage systems and treats the service worker as the sole author of this cache's keys.
  `refreshCachedShells()` iterates `cache.keys()` and does
  `doFetch(request.url, { credentials: 'omit' })` — default `redirect: 'follow'` — then re-`put`s under
  the **original** request. ⛔ **So a device primed before `M61` has its legacy `/f/Clinic-Intake` entry
  re-fetched, redirected, and written back under the MIS-CASED key**, renewed on every brand change
  rather than ageing out, and the canonical key is still never created. ⛔ **And the response it stores
  has `redirected === true`, which a browser REFUSES to serve for a navigation request** — so the renewed
  entry is not merely mis-keyed, it is unusable offline. That is a dead navigation rather than a stale
  colour, which is the exact trade that file's own header says it exists to refuse. Workbox ships
  `copyRedirectedCacheableResponsesPlugin` for this class and nothing here uses it. ⚠️ **Latent — it
  needs a device primed before `M61`**, and this repository cannot tell whether one exists, which is the
  same shape as the mixed-case `public_slug` row. But it means canonicalizing the origin response does
  not heal already-cached shells, and that is a premise `M61` recorded as settled.
  ➕ **`M74` MOVED HALF OF ANOTHER ROW'S REMEDY IN HERE, WHERE IT BELONGS.** The `brand-cache.test.ts`
  coverage row prescribed *"one case asserting which request `put` was called with, **and** a `redirected: true`
  field on the fake response"*. ⛔ **The second half is INERT as a coverage change and is really a fix to
  THIS row**: nothing anywhere reads `redirected`, and `refreshCachedShells()` gates on `response.status === 200`
  while a followed redirect **is** 200 — so adding the field asserts nothing until `refreshCachedShells()`
  itself changes. `M74` shipped the key assertion alone and left this half here rather than editing
  `lib/brand-cache.ts`, which would have put two open rows on one non-hub file under `D13`.
  ⚠️ **Whoever takes this owes the interaction, not just the fix:** `M74`'s new key assertion pins TODAY's
  behaviour — that `put` is called with the original request — and a repair that skips redirected responses
  changes that to *not called at all*. The assertion is expected to move with the fix; it is not a regression
  when it does.
  **Latent.** Filed by `M72`.

- ✅ **CLOSED BY `M73` (2026-09-05) — `minor` · ~~A draft is pinned to TWO different form versions in two tables after a silent share-token re-mint.~~**
  `saveDraft()` now re-reads the pin from the EXISTING draft before Stage-2a, so an existing draft is saved
  against its own version and never the caller's — which is what the staff channel already does, making
  this a no-op there. `SubmissionController::store()` had already recorded that *"the guest channel avoids
  this only by accident of its version coming from the share token, which happens to be the draft's"*; that
  accident is now removed rather than relied on.
  ⛔ **THE ROW'S STATED DETERRENT WAS FALSE, WHICH IS WORSE THAN IT BEING RIGHT.** It warned that
  `GuestDraftRuntimeTest`'s *"refuses loudly…"* case depends on the draft staying pinned. That case uses the
  ORIGINAL token, never re-minted, with no clock travel, and `saveDraft`'s Stage-2a throws on the TOKEN's
  version before `updateDraft()` is entered — it never reads the parent's pin at all. **The obvious repair
  was UNGATED rather than forbidden**, and nothing in the suite would have gone red either way.
  ⛔ **And the premise was materially false.** *"Absorbed by the visit guard and the checksum guard"* — both
  run only on the resume boot, while the divergence is written on a live autosave tick inside an
  already-mounted session, where neither is reachable. What absorbed it was `promote()`'s server-side
  re-assert, and it absorbed it **by refusing the RESPONDENT a 409 at Submit**, after which the client mints
  a fresh uuid and abandons the draft. The row called itself `latent`; the outcome is live and
  respondent-facing, and the fix only makes the refusal EARLY.
  ✅ **It also understated itself — three live copies, not two.** The third is the resume token's `vid`
  claim: signed, 30-day, and emailed to the respondent. Closed by `M73`; 510 passed across
  `tests/Feature/Submissions` and `tests/Feature/Guest`, and the mutation was CAUGHT. Found during `M72`'s fan-out while verifying the `draft_answers` key row, and it is the
  server-side root of that client-side symptom rather than a restatement of it.
  `SubmissionDraftService` moves `submission_answers.form_version_id` and `answers_schema_checksum` to
  the saving version, while the `forceFill` on the parent leaves `submissions.form_version_id` at its
  create-time value. ⛔ **`withFreshToken` re-mints silently on the 24-hour share-token expiry**, so a
  long-lived session that spans a republish saves against v2 while its `submissions` row still says v1,
  and `GuestDraftResumeController` then reports the v1 value to a client whose schema is v2. ⚠️ **The
  row this was found under is `latent` and this one is the reason it is only latent** — the divergence
  is real and its visible consequence is absorbed by the visit guard and the checksum guard, which is
  luck rather than design. ⚠️ **Do not take the obvious repair casually**: making `updateDraft` move the
  parent's version is a behaviour change to a shipped guest path, and
  `tests/Feature/Guest/GuestDraftRuntimeTest.php` has a case (*"refuses loudly, rather than promoting
  against a different graph, when the branch is republished"*) that depends on the draft staying pinned.
  **Live.** Filed by `M72`.

- **`minor` · `docs/data-dictionary.md` documents nine `tenants` columns that exist in no migration, and
  its enum catalog contradicts the enum.** Measured by `M72`'s fan-out while sizing the documented-literal
  gate, and filed rather than folded in because it is a documentation increment rather than a cell
  repair. `tenants.timezone`, `settings`, `is_tax_exempt`, `billing_email`, `billing_country`, `tax_id`,
  `trial_ends_at`, `suspended_at` and `deleted_at` are all documented with a Default and none of them
  exists — `Tenant::getCustomColumns()` is the authoritative list — while the real `data` json column
  stancl spills into is documented nowhere. ⛔ **And the enum catalog is wrong about the same column the
  literal-default drift names**: it lists `trial`, `active`, `suspended`, `cancelled` for `TenantStatus`
  where the enum declares exactly **two** cases, and the table's own preamble claims every enum matches
  *"each enum's Postgres CHECK constraint"* — `tenants.status` has no CHECK constraint in any migration.
  ⚠️ **Three of these are reachable for free by the documented-LITERAL gate** already filed (they document
  a default on a column that does not exist, which that test's `$unknown` arm already catches and cannot
  reach today only because the cells are literal-shaped). The other six are not, and need the census this
  row is for. **Live.** Filed by `M72`.

- **`minor` · `DROP_BYTE_LIMIT` is an absolute constant unindexed to a ceiling `R1`'s own discipline keeps
  ratcheting, so sampling bias is the symptom and the missing index is the cause.** Re-measured by `M72`
  before the `R7` dead-zone row above was considered for a batch, and it strengthens that row rather than
  restating it. ⛔ **That row says *"surgeries at 161,528 and up"* and the gap had ALREADY CLOSED before
  `M71`**: `M60`'s declared surgery dropped **101,273** bytes and armed `R7` only because that happens to
  clear 50,000 twice over. ⛔ **And *"the size of a surgery is a function of how long it was deferred"* is
  false as stated** — headroom-at-surgery against drop is `M41` 48,137→938,007 · `M45` 91,559→161,528 ·
  `M48` 39,793→198,909 · `M60` 3,970→101,273 · `M71` 1,355→29,200. No monotone relationship; the *most*
  deferred produced the *smallest* drop. ✅ **What IS monotone is the fraction**: every surgery removes
  22–65% of the file, and `TRACKER_BYTE_CEILING` has been ratcheted 1,500,000 → 600,000 → 400,000 →
  200,000 → 130,000. So 50,000 was *"3.2× below the smallest surgery"* in a 400,000-byte world and is
  today **38% of the entire ceiling** — every future ratchet widens the dead zone regardless of how early
  anyone acts. ✅ **The fractional threshold has room the absolute one does not**: ordinary drops top out
  at **4.78%** and surgeries start at **22.70%**, a **4.75×** gap against the 2.03× the row rightly calls
  too tight. A `C12` case at `keepFiller = 705` reproduces `M71`'s shape at 21.6% and needs no change to
  `write_fixture_files()`. **Live.** Filed by `M72`.

- **`minor` · `scripts/next.php` never clips the release HEADING, so a third of the hand-off is
  byte-identical noise.** Measured by `M72` while verifying the lead-paragraph row above, whose stated
  harm does **not** reproduce today. `clip()` is applied to the summary alone; the heading is rendered
  whole. The four current headings are 279, 279, 260 and 278 characters, of which roughly **130 each** is
  the identical tail *"(merged as PR #NNN, `<sha>`, 6/6 green with real step counts — Static analysis 23
  · E2E 20 · Contract 16 · Frontend 12 · Pest 11 · axe 11)"* — about **520 characters of verbatim
  repetition inside a 3,594-byte hand-off**, which is more budget than the 220-character summary clip the
  other row is about. ⚠️ **And every lead opens with two invariant sentences** — *"Shipped `<date>`.
  Branch `<slug>`."* — spending ~45 of the 220-character summary budget on a date and a branch name in
  every one of the four slots. Both trims are deterministic rather than heuristic, and stripping the
  boilerplate would have fixed `M42`'s own manifest case too. ⛔ **No gate exists**: nothing in `tests/`,
  `.github/` or `composer.json`'s `quality` list executes or asserts anything about `scripts/next.php`,
  so a fix must create its first control — and `next.php` runs top-to-bottom and shells out to
  `state.php`, so a Pest test cannot `require` it without a `PHP_SAPI`/`realpath($argv[0])` guard or a
  `--claim=` seam. Without one, the control is unfalsifiable and the increment ships a decorative gate.
  **Live.** Filed by `M72`.

- **`minor` · The `npm audit` judge makes a REQUIRED context green while nothing was measured, and that
  is a deliberate trade rather than an oversight.** Recorded by `M72` (2026-09-05) at the moment the
  decision was taken, rather than left as a comment in the workflow. When the advisory endpoint is
  unreachable the judge exits `2`, `ci.yml` renders a `::warning::` and a job summary, and the step exits
  `0` — so `Static analysis, style & security` is green having judged **no dependency at all**. ⛔ **That
  is a member of the same vacuous-success family this repository catalogues** (`I5`'s `steps: []`, Pint
  before its probe, `M61`'s `e2e` wrong form, `M69`'s PHPStan-crash-exits-0), and it is being accepted
  knowingly because the alternative it replaces is worse: a false red that gets re-run until it passes,
  which reddened `main` twice on consecutive increments. ⚠️ **The costed alternative is a separate
  non-required job**, which buys a visible pending state at the price of a runner and a second
  `npm install`; it was not taken because `D7` fixes the six required contexts by job name and adding one
  is a branch-protection change. ⚠️ **The honest gap is that nobody is obliged to read the annotation.**
  A stronger form would fail the step on N consecutive unreachable runs, which needs state the workflow
  does not have today. Recorded as `D16`. **Live.** Filed by `M72`.
- **`minor` · What actually delivers the offline mis-cased render is unknown, and TWO confident models of
  it have now been wrong.** Measured by `M73` (2026-09-05) while closing the `/f/*` opaqueredirect row, and
  filed rather than guessed at because this exact route has already produced two wrong answers that were
  each good enough to write a test from. `M72` recorded that offline entry at a mis-cased url survives
  *"because the browser follows a cached opaqueredirect to the canonical entry"*. ⛔ **That is refuted:
  with the strict status filter in place the stub is never cached at all, the shell cache holds exactly one
  key, and the offline render still passes.** So the guarantee does not depend on the cached stub, and
  nothing in this repository now describes what it does depend on. ⚠️ **The candidates are not equivalent
  and the difference is testable**: Chromium's own HTTP cache may hold the 301 and redirect before the SW
  handler's rejection matters, or the navigation may be re-driven after `respondWith` rejects. ⛔ **Why it
  matters rather than being trivia**: the surviving mechanism decides whether the guarantee is robust to a
  cache eviction, to a `Cache-Control` change on the 301, or to the seven-day expiry — and a mechanism
  nobody has named cannot be protected by a gate. The instrument already exists
  (`tests/e2e/public-runtime-offline.spec.ts` reads cached responses by `status` and `type`); what is
  missing is a probe of the REDIRECT path with the SW's own cache emptied. **Live.** Filed by `M73`.

- ✅ **CLOSED BY `M77` (2026-09-06) — `minor` · ~~`guest-shell-assets` has the identical missing status filter, and no row has ever named it.~~**
  Found by `M73`'s fan-out while verifying the `/f/*` row, which framed itself as *"the one route whose
  sibling filters for `200`"*. ⛔ **There are THREE routes and TWO of them lacked the strict filter** — the
  headline was a 1-of-2 framing of a 1-of-3 fact, and closing `/f/*` leaves `guest-shell-assets` as the
  only unfiltered one. It is a `StaleWhileRevalidate` over `/build/`, whose constructor prepends the same
  permissive `cacheOkAndOpaquePlugin`, so it too stores `status === 0`. ⚠️ **Sized `minor` and NOT folded
  in, for a stated reason**: `/build/` is same-origin and hashed, so a status-0 response there needs a
  redirect or an opaque response that this route's matcher makes hard to reach — the exposure is far
  narrower than `/f/*`'s and it shares `maxEntries: 80` rather than 20. ⚠️ **What is NOT known is whether
  it is reachable at all**, and that is the row: either it is, and it is the same one-line fix, or it is
  not, and the route should say so where the next reader will look. **Live.** Filed by `M73`.
  ➕ **`M74` ANSWERED THE ROW'S OWN OPEN QUESTION READ-ONLY, WITHOUT TAKING THE ROW.** It was not in the batch
  (a fifth row under `D13`), but the reachability verdict is the expensive half and is recorded here so the
  taker does not re-derive it. ⛔ **STATUS 0 IS UNREACHABLE ON THIS ROUTE, closed three independent ways.**
  (1) An `opaque` response requires a cross-origin `no-cors` request; Workbox's `Router` computes `sameOrigin`
  from the REQUEST url and this matcher demands it, so no match can produce one — and `resources/` contains
  zero `no-cors` and zero `redirect:` call sites. (2) An `opaqueredirect` requires `redirect: 'manual'`, which
  only NAVIGATION requests carry, and the service worker registers with `{ scope: '/f/' }`, so a `/build/`
  URL is never a navigation; subresources use `redirect: 'follow'` and resolve to a `basic` 200.
  (3) The one residual path — a same-origin `/build/` request redirected cross-origin — has no producer: no
  `ASSET_URL`, no `Route::fallback`, no scheme/host canonicalizing middleware, and nginx's `try_files` sends a
  missing asset to Laravel, which 404s. A 404 is already refused by `cacheOkAndOpaquePlugin`.
  ⚠️ **CANNOT VERIFY a production edge** (a CDN or WAF in front of the app) — nothing in this repository can
  settle that, and the repo's own webserver config is what was read.
  ⚠️ **SO THE ROW'S SECOND BRANCH IS THE LIVE ONE, and the shape of the close changes with it.** An e2e
  assertion that no `/build/` entry has `status 0` would be **vacuously green by construction**, because no
  such traffic exists — the failure mode this spec's own comments already name. The honest close is the
  one-line plugin *plus* a comment recording WHY status 0 cannot arrive; a non-vacuous measurement would need
  a temporary route that 302s cross-origin, which is a probe, not a gate.
  ➕ **And the census is a floor:** `sw.ts` registers three routes explicitly and a FOURTH implicitly via
  `precacheAndRoute()`, whose `PrecacheStrategy` admits anything with `status < 400` — more permissive than
  either rule. It is inert only because `vite.config.ts` sets `injectManifest.globPatterns: []`, so the
  shipped bundle precaches an empty list. *"Three routes, two rules"* is really *four routes, three rules,
  one dead by configuration.*
  ✅ **DONE — `M77` (2026-09-06), and it took `M74`'s second branch, softened by one degree.** The plugin
  is added and the comment beside it is written as a **precondition, not an all-clear**: it names the three
  things that would make status 0 arrive (an edge CDN or WAF in front of `/build/`, an `ASSET_URL` pointing
  the bundle at another origin, a canonicalising redirect), none of which exist here. `M74`'s own
  *"CANNOT VERIFY a production edge"* is precisely why an all-clear would have been the wrong artefact.
  ⛔ **THE PROOF `M74` DID NOT KNOW WAS BUILDABLE IS BUILT, AND THE OBVIOUS FORM OF IT DOES NOT WORK.**
  `resources/public-runtime/__tests__/sw.test.ts` is the **first mount of `sw.ts` in this repository**. The
  natural probe — asserting `cacheWillUpdate({ response: { status: 0 } })` returns null — **throws**:
  `CacheableResponse.isResponseCacheable()` opens with `assert.isInstance(response, Response)` in every
  non-production build, three lines above the status comparison, so a plain object literal reddens all three
  routes together rather than only the unfiltered one. `new Response(null, { status: 0 })` is unavailable too
  (`RangeError`, status must be 200..599). **`Response.error()` is the only status-0 `Response` the platform
  offers**, and happy-dom supplies it.
  ✅ **Proved by deliberate defect, with the discriminator the project requires**: stripping the plugin back
  out turns **exactly one** arm red — `guest-shell-assets` — while the two sibling routes stay green.
  ⚠️ **Also silenced Workbox's dev-logger output**, which dumps the whole `Response` for every rejection and
  put six screens of noise on a fully passing run. Vite strips that branch from the shipped worker, so it
  changes nothing about production — but a green run that prints walls of text is what teaches a reader to
  skip output, which is the argument `M76` closed the `AbortError` row on.
  ➕ **The four-routes-three-rules census above is confirmed and unchanged**; the precache route stays inert
  by configuration and was not touched.

- **`minor` · The triage generator silently drops any citation written as a PARTIAL path, and a partial
  path is strictly worse than a bare filename.** Found by `M73`'s fan-out. `resolve_token()` in
  `scripts/backlog-triage.php` branches on whether the token contains a `/`: if it does, the ONLY
  resolution attempted is `is_file($token)` against the literal string, so a row citing
  `` `lib/brand-cache.ts` `` resolves to nothing, because the real path is
  `resources/public-runtime/lib/brand-cache.ts`. ⛔ **The bare-basename index — which WOULD have resolved
  `` `brand-cache.ts` `` unambiguously — is skipped precisely BECAUSE the citation was more specific.**
  ⛔ **The consequence is not cosmetic.** Such a row is reported as *"no file harvested"*, can never be
  collision-checked into a batch (`render_batch` skips it by construction), and **contributes nothing to
  any file's hub degree** — so it silently biases the hub set that `D13` and `D15` both turn on. At least
  one open row is in this state today. ✅ **The remedy is small and has a decision in it**: try the
  basename index as a fallback when a slashed token does not resolve, or resolve slashed tokens as a
  SUFFIX match and refuse an ambiguous one — the second is stricter and matches the file's existing
  *"more than one file is UNRESOLVED, never resolved to the first hit"* rule. **Live.** Filed by `M73`.

- ✅ **CLOSED BY `M74` (2026-09-05) — `minor` · ~~`brand-cache.test.ts` asserts `put` by CALL COUNT and never by key, so its central defect is
  invisible to it.~~** Found by `M73`'s fan-out. `refreshCachedShells()` re-`put`s under the ORIGINAL request
  object, which is what the open `lib/brand-cache.ts` row says renews a mis-cased key. The suite that
  covers this file is otherwise thorough — ten cases, a resume-skip with a non-vacuity partner, a
  404-not-cached rule, an offline defer — but its `fakeCaches()` models a key as `{ url } as Request` and a
  response as `({ status }) as Response`. ⛔ **So it has no notion of `redirected`, no notion of a redirect
  being followed, and no notion of the response url differing from the key it is stored under** — every
  case passes under a mutant that writes the right response to the WRONG key. ✅ **This is the cheapest
  control found in that whole investigation**: one case asserting which request `put` was called with, and
  a `redirected: true` field on the fake response. It needs no browser, unlike everything else in this
  area. ⚠️ Filed separately from the `brand-cache.ts` row because that one is `latent` behind a device
  primed before `M61`, and **this one is not** — the coverage gap is real today whatever the defect's
  reachability. Filed by `M73`.
  ✅ **AS BUILT BY `M74` (2026-09-05): three key assertions, one per case, each chosen because `entries[0]`
  is a DIFFERENT wrong key in each** — the current shell the sweep skips, then the token-bearing resume key
  (so the mutant renews on disk exactly the credential-bearing document the skip exists to expire), then the
  URL whose fetch REJECTED, which pins that the sweep cached the entry that succeeded. Nothing asserted that
  last property before. **Measured both ways:** the wrong-key mutant reddens the three new assertions while
  all seven count-based ones stay green, and the resume-skip mutant reddens the COUNT while the new assertion
  is insensitive to it — the correct division of labour, not double coverage.
  ⛔ **THE ROW'S REMEDY IS TWO THINGS WEARING ONE HAT, AND ONLY ONE OF THEM IS TEST-ONLY.** The proposed
  `redirected: true` fake field is **inert alone**: nothing reads `redirected`, and `brand-cache.ts` gates on
  `status === 200` while a followed redirect *is* 200. Making it bite means editing `refreshCachedShells()`,
  which belongs to the `latent` `lib/brand-cache.ts` row and would have put two open rows on one non-hub
  file. **That half is moved to that row rather than dropped.**

- **`minor` · `SubmissionFinalizer` is a SECOND writer of `submission_answers.form_version_id`, and it
  would erase the evidence of a divergence rather than record it.** Found by `M73`'s fan-out while closing
  the draft-pin row, whose premise was that one class owns that column. `finalize()` writes both
  `form_version_id` and `answers_schema_checksum` onto the answer row from the `$version` `promote()`
  resolved — which is read from the PARENT's pin. ⛔ **So on a successful promotion of a diverged draft it
  would rewrite the answer row BACK to the parent's version, over answers normalized against a different
  graph, converging the two columns onto the wrong one and destroying the only trace that they ever
  disagreed.** ✅ **It is unreachable today and `M73` did not make it reachable** — `promote()`'s own
  re-assert refuses first, because a re-mint implies a republish implies the pinned version is
  `Superseded`, and `PublishService` never re-publishes an old version. ⚠️ **It is filed because it is the
  reason the rejected repair was dangerous**: moving the parent forward would have made this path
  reachable and self-consistent at the same time. The row is a request for an assertion that the two
  writers cannot disagree, not for a change to either. **Latent** — it needs a promote of a diverged
  draft, which no current path can produce. Filed by `M73`.
- ✅ **CLOSED BY `M74` (2026-09-05) — `minor` · ~~`gate-baselines.php` trusts `gh run list --limit 1` to mean "newest", and it does not — the
  file written to end stale numbers was stamped from an EIGHT-DAY-OLD run, and its own guard cannot see
  it.~~** Measured by `M73` (2026-09-05) during its own close-out, which is the only reason it was caught.
  The no-`--run` branch takes `gh run list --branch main --workflow CI --status success --limit 1` and uses
  `[0]` as the newest run. ⛔ **On one invocation it returned `33184885256` — a real, successful, `push`
  run on `main` from 2026-08-28** — while the intended run `33958516257` had already concluded `success`
  and appears first on every subsequent invocation of the identical command. The file was written, reported
  success, and carried baselines from a tree eight days behind.
  ⛔ **`M39`'s GUARD CANNOT CATCH THIS, AND THE REASON IS THE POINT.** That guard was added after this file
  was stamped from a `pull_request` run on a feature branch, so it validates the SELECTED run's
  `conclusion`, `event` and `headBranch` — and a stale run on `main` satisfies **all three**. The guard
  checks *what kind of run this is* and never *whether it is the run we meant*. **Recency is the one
  property it does not assert, and it is the one that failed.**
  ✅ **There WAS a tell, and it is worth keeping**: the run also reported *"1 metric(s) NOT FOUND — patterns
  need fixing"*, because a log that old predates a pattern the script now expects. So the only signal was a
  line that reads like a scraper bug rather than like a wrong run — and on a run that happened to be recent
  enough, there would have been no signal at all.
  ⚠️ **The remedy is small and should not be `--run=` discipline**, because the default path is what every
  close-out uses: assert the selected run's `headSha` is an ancestor of `origin/main` **and** within a
  stated age or commit distance of it, and refuse rather than warn. `state.php` already computes exactly
  this staleness for the file it writes, so the measurement exists and is not wired to the writer.
  ⚠️ **Whoever takes it should also decide what a NOT FOUND row means for the write**: today the script
  writes the file anyway and reports the count afterwards, which is the same accept-then-mention shape as
  the `npm audit` judge row. Filed by `M73`.
  ✅ **AS BUILT BY `M74` (2026-09-05) — three arms, all exiting 1 with their OWN sentence, inserted after the
  `pull_request` refusal and above both expensive network calls.** The head sha must be readable; it must be
  an **ancestor** of `origin/main`, judged **THREE-WAY** (0 yes, 1 no, anything else *"could not decide"* with
  a distinct message and its own refusal, because collapsing the third into either verdict is the
  succeeds-on-empty-input family this repository has now measured six times); and it must be within
  `MAX_COMMITS_BEHIND` of the trunk.
  ⛔ **DISTANCE, NOT WALL-CLOCK AGE, AND THAT IS WHAT PRESERVES THE CARVE-OUT.** The nightly `schedule` run
  this script is *required* to accept is hours old by construction and zero commits behind, so an age check
  would refuse the one run added as insurance against an outage. Proven by mutation: replacing distance with
  a six-hour age check reddens the `nightly-schedule` case and nothing else.
  ⛔ **THE ROW IS HALF WRONG ABOUT ITS OWN REMEDY, AND THE FALSE HALF IS THE REUSE.** `state.php`'s
  `commits_behind_trunk()` computes **distance only, never ancestry** — measured, a non-ancestor sha yields a
  happy finite count — `state.php` executes at file scope so it cannot be `require`d, and shelling to it is
  **circular**, because `derive_baselines()` parses `docs/gate-baselines.md` to find the sha it measures and
  this script is that file's writer. The two `git` calls are inlined.
  ⚠️ **The row also misquotes its own command:** it already carried `--json databaseId,headSha,createdAt`, so
  the recency data was fetched and thrown away — which made the fix smaller than the row implies.
  ⚠️ **CANNOT VERIFY the mis-ordering by reproduction** — five invocations today were strictly descending by
  `createdAt`. What is verified: the stale run is real with every claimed property (ancestor, 141 commits
  behind), `gh` documents no ordering guarantee and offers no sort flag, and `8abe432` records it
  contemporaneously. **The guard does not depend on reproducing it.**
  ⚠️ **The NOT-FOUND-row question this row raises is deliberately NOT answered here** and stays open below.

- ✅ **CLOSED BY `M75` (2026-09-06) — `minor` · ~~The correction page has no autosave and no armed leave
  prompt, so an editor who navigates away loses every character they typed, silently.~~** ✅ **Every citation
  held byte for byte — the only row of the four whose evidence needed no correction** — and its warning was
  worth the words: the obvious remedy really is already done and really would not have helped.
  ✅ **CLOSED: the leave-prompt half.** A guard local to `Encode.vue`, armed in edit mode only, covering all
  three escape routes the row names — a real browser navigation via `beforeunload`, and every Inertia visit
  (Cancel, a breadcrumb, the sidebar, the palette) via `router.on('before')`, which fires for none of them
  otherwise. Cancelling offers **Stay on this page** / **Leave and discard**, and leaving re-issues the visit
  rather than making the keyer click twice.
  ⛔ **AND THE OBVIOUS SHAPE OF THAT FIX SHIPS A DEFECT OF ITS OWN, WHICH IS THE FINDING WORTH KEEPING.** A
  bare visit hook fires on the **dark-mode toggle**: `ThemeQuickToggle` sits in `TopNav` sits in `AppLayout`,
  which this page uses, and it persists through `router.patch('/settings/appearance', …)` — a visit that
  never leaves the page. So a keyer switching to dark mode is asked whether to discard their corrections, and
  **declining it silently drops the theme preference**, which has no error path. Excluding non-GET visits
  closes it and exempts `submitEdit()`'s own PATCH for free. ⛔ **A second exclusion nobody would have
  noticed**: `Router.prefetch()` fires the same cancelable `before` event, so without a `prefetch` clause the
  first `<Link prefetch>` in the shell pops this dialog **on hover** — armed and invisible, since nothing
  passes `prefetch` today.
  ⚠️ **`confirmDiscard()` now disarms the guard before reloading**, or `M74`'s two-click confirm gains a
  browser dialog on top of it — asking twice for one decision. The docblock that argued `location.reload()`
  "costs nothing in lifecycle" is corrected in the same commit, since this is precisely what made it false.
  ⛔ **NOT CLOSED — the "no autosave" half, and it is not a gap so much as a different row.** It needs an
  endpoint that does not exist: `draft_url` is null in edit mode on purpose, and the only other write channel
  demotes Approved → UnderReview and writes an audit row **per save**. That is a product decision, filed below.
  ⚠️ **A test-file correctness fix came out of this and is worth knowing about**: nothing in `encode.test.ts`
  ever unmounted, so every case left a live component holding a `beforeunload` listener on the shared window.
  Measured: with the new unmount loop disabled, two of the new cases fail.
  ⬇️ **The row as filed:** Found by `M74` while building the escape route beside it, and
  👤 **the user was asked and chose to file it rather than fold it in.** `Encode.vue`'s autosave is
  `enabled: … && !isEditing.value`, so on the edit channel it never runs; `useServerAutosave` DOES register a
  `beforeunload` listener at construction, but its handler early-returns because `dirty` is only ever set
  inside the `enabled` guard. ⛔ **SO THE OBVIOUS REMEDY — "arm the listener in edit mode" — IS ALREADY DONE
  AND WOULD NOT HELP**, and stating that is the point of this row: the mechanism is the DIRTY FLAG, not the
  listener. A taker who reads the symptom and reaches for `addEventListener` will find it already there and
  conclude the row is stale. ⚠️ **The harm is exactly the value the concurrency machinery claims for itself.**
  `Encode.vue` argues that a refused correction is survivable because *"the editor keeps every character they
  typed and can copy it out"* — which depends entirely on the page staying put, and nothing makes it stay put.
  Cancel, a breadcrumb, or a closed tab all discard it with no prompt and no trace. ⚠️ **And `M74` shipped a
  deliberate "discard my changes and reload" button onto this page**, which makes the asymmetry worse rather
  than better: the destructive action is now two-click confirmed while the accidental one is free.
  ⚠️ **It is NOT the `M68` row about `dispose()`'s teardown** — that row's `preventDefault()` path is
  unreachable here for this same reason. The two share only a file. **Live.** Filed by `M74`.

- **`minor` · What a media cell should SAY beyond a filename, and what shape a grid answer takes in a
  spreadsheet, are two product questions `M74` deliberately did not answer.** Recorded 2026-09-05 at the
  moment the scope was decided rather than after. `M74` shipped the non-product core — never emit raw
  `json_encode`, preserve row identity, name the files — and two questions survive it, each of which changes
  what a customer sees rather than whether the output is machine noise. **(1) A signed link.** An export cell
  a reviewer can click through to the attachment needs a URL policy, an expiry, and a decision about whether
  an XLSX may carry a credentialed link off the platform at all; `AttachmentPolicy` and the metered-export
  audit row both bear on it. **(2) The grid's column shape.** `M74` renders a matrix into ONE cell as
  `q1: c1=v1; q2: c1=v3`. A spreadsheet consumer may want one column per row-and-column pair instead, which
  is a change to `SubmissionRowProjector`'s header union and therefore to the `MappableColumnCatalog` a
  tenant has already mapped in Google Sheets and Airtable. ⚠️ **The second is the one with a migration-shaped
  cost hiding in it** — a mapped column that changes identity is a broken sync, not a re-render, and that is
  why it is a row rather than a refinement. ⚠️ **Also unresolved and cheaper:** the grid arms render row and
  column VALUES, not their author-defined LABELS, because resolving those needs the field's `config` threaded
  into `displayValue()`. **Live.** Filed by `M74`.

- ✅ **CLOSED BY `M75` (2026-09-06) — `minor` · ~~`gate-baselines.php` writes the file BEFORE it judges
  whether every metric was found, so a NOT FOUND row ships and is reported afterwards.~~** ✅ **The mechanism
  held to the line** — the write sat three lines above the `$missing > 0` judgement.
  ⛔ **AND THE THING THE ROW DID NOT NAME IS WORSE THAN THE THING IT DID.** `gate-baselines: wrote
  docs/gate-baselines.md from run N.` printed **unconditionally**, before the judgement — so the script
  announced success and then contradicted itself on stderr. That sentence is now printed only when nothing
  is missing, and the count is reported on **both** exits, on stderr, where `--dry-run`'s document on stdout
  cannot be corrupted by it.
  ⛔ **TWO OF THE ROW'S OWN CLAIMS ARE FALSE AND ARE NOW PINNED AS FALSE BY A TEST.** (1) *"Under `--dry-run`
  the NOT FOUND message never prints at all … the only signal is an exit code."* It is not: the **document**
  carries a row **naming** the failing metric and `--dry-run` writes that document to stdout, which is
  strictly more actionable than a count. What was genuinely missing was the count. (2) *"It flips
  `--dry-run`'s exit semantics."* Both paths already exited `1` on a missing metric — measured.
  ⛔ **THE PRESCRIBED REMEDY WAS NOT TAKEN, DELIBERATELY.** *"Move the write below the judgement"* is a
  refusal-to-write; `M70` already adjudicated a refuse-instead-of-write remedy **for this same script** and
  rejected it, and it strands close-out step 3 — `scripts/next.php` stamps *"regenerate it"* until the file
  moves, so a refusal nags forever with nothing able to satisfy it. **A NOT FOUND row in the file names the
  unscraped metric; an absent file names nothing.**
  ⛔ **THE BRANCH HAD ZERO COVERAGE AND COULD NOT BE GIVEN ANY**, which is the part worth carrying forward.
  All six scenarios shared one `ci-log.txt` satisfying all twelve patterns, and every control ran
  `--dry-run` because the default action writes the repository's own tracked baselines. Both ends are fixed:
  `gh.php` serves `ci-log-<scenario>.txt` when one exists, and `GATE_BASELINES_OUT` redirects the
  destination — the same shape as the `GH`/`GIT` seams, a destination replaced and no guard weakened. The
  sha256-invariance case still passes, which is what shows the seam did not cost that invariant.
  ✅ **The helper moved from `exec(… 2>&1)` to `proc_open`**, because merged streams made "the diagnostic is
  on stderr" structurally unprovable — and that retired the `putenv()` dance and the cmd.exe note behind it.
  ⚠️ **Sizing correction for the record:** the row's urgency is inherited from an incident `M74` already
  closed by a louder guard — `MAX_COMMITS_BEHIND` refuses that 141-behind run about a hundred lines before
  the metric loop — so the scenario the row narrates cannot recur. The accept-then-announce shape was still
  real, and `docs/gate-baselines.md` sits inside `ci.yml`'s `paths-ignore`, so nothing in CI would ever have
  seen a bad file reach the trunk.
  ⬇️ **The row as filed:** Split out of the `gh run list` row `M74` closed, which
  raised it and deliberately did not answer it. `file_put_contents()` runs three lines ahead of the
  `$missing > 0` check, so a run with a broken pattern still rewrites `docs/gate-baselines.md` and *then*
  exits 1 — the accept-then-mention shape the `npm audit` judge row also names. ⛔ **And under `--dry-run` the
  NOT FOUND message never prints at all**: that branch exits on the count without writing the diagnostic, so
  the only signal is an exit code. ⚠️ **This was the ONLY tell that the eight-day-old run was wrong** — a log
  that old predated a pattern the script now expects, so it reported *"1 metric(s) NOT FOUND"*, which reads
  like a scraper bug rather than like a wrong run. The remedy is to move the write below the judgement and to
  print the diagnostic on both paths; ⚠️ **note it flips `--dry-run`'s exit semantics**, which is why it was
  not folded into a guard change that had its own controls to prove. **Live.** Filed by `M74`.

- ✅ **CLOSED BY `M75` (2026-09-06) — `minor` · ~~`brand-cache.ts` re-`put`s through the raw Cache API rather
  than a Workbox strategy, so the bytes are renewed and the seven-day expiry clock is not.~~** ✅ **Every fact
  the row states held**, and `brand-cache.ts:168` really was the only raw Cache API write in the repository.
  ⛔ **THE TITLE'S REMEDY CANNOT RUN WHERE THIS CODE RUNS, THREE INDEPENDENT WAYS.** `brand-cache.ts` executes
  in the WINDOW: `Strategy.handleAll()` needs a `FetchEvent` and throws there; `sw.ts`'s shell route matches
  `request.mode === 'navigate'`, and navigate mode is not constructible from script; so the strategy is
  unreachable. The working seam is `CacheExpiration.updateTimestamp()` — the same bookkeeping the plugin
  drives, minus the deletion — and it was proved to run under happy-dom before a line of the fix was written.
  ⛔ **`expireEntries()` IS DELIBERATELY NOT IMPORTED.** It deletes, and this module's axiom is *re-prime,
  never purge*.
  ⚠️ **THE ROW DESCRIBES ONE CLOCK AND WORKBOX HAS TWO, WHICH IS WHY NOTHING NOTICED FOR SO LONG.** Deletion
  is driven by the IndexedDB timestamp, which a raw `put` never touches; read-freshness is decided from the
  cached response's own `Date` header, which a raw `put` **does** renew. So the defect was invisible to
  anything that merely read an entry back, and it bit hardest on exactly the shells this module exists for —
  the ones nobody navigates to, where nothing else ever stamps the entry.
  ⚠️ **AND THE ROW'S SEVERITY FRAMING OVERSTATED THE TRIGGER**: the sweep runs once per **brand change**, not
  per boot, so "refreshed on day six" needs a tenant ramp edit on day six.
  ⛔ **THE ROW'S OWN SECOND-ORDER NOTE IS BACKWARDS, AND THE CORRECTION IS NOW AT THE CALL SITE.** It says
  `maxEntries` bookkeeping is "unaffected". `maxEntries` is enforced by walking the `timestamp` index
  newest-first, so it is a recency-of-USE order — and renewing the swept entries replaces that with sweep
  order for one boot, leaving the resume shell (the one entry deliberately not renewed) sorting oldest.
  ⚠️ **The prior state was the anomaly**: a fresh body with a stale timestamp is a combination Workbox's own
  model cannot produce, so there was no correct ordering to preserve — only a different wrong one. **Whether
  the sweep should be narrower is a design question the row did not know it was asking, and it is filed below
  rather than decided inside a `minor` row.**
  ✅ **Four docblock sentences that stated the false model are corrected**, including `isResumeShell()`'s
  SECURITY rationale — which argued the skip stops a re-`put` "RENEWING a token-bearing document
  indefinitely", a renewal that until now did not happen. **That predicate was belt-and-braces; it is
  load-bearing now.** `SHELL_CACHE` and the expiry config moved to `lib/shell-cache.ts`, ending a
  "must match `sw.ts`" comment that was a duplicate with a note attached.
  ⬇️ **The row as filed:** Found by `M74` while closing the coverage row
  beside it. `refreshCachedShells()` calls `cache.put()` directly on the `Cache` object; `ExpirationPlugin`'s
  IndexedDB timestamp for that entry is therefore never touched, so a shell refreshed on day six is still
  evicted on day seven as though it had not been. ⚠️ **The module's own docblock reads as though the entry is
  renewed in every sense** — *"the stale entries are REFRESHED — fetched and re-`put` — and the cache is
  never emptied"* — which is true of the bytes and false of the clock, and that sentence is what a reader
  will act on. ⚠️ **Sized `minor` because the failure is benign today**: an evicted shell is re-fetched when
  online and the brand sync re-runs. It stops being benign if anything starts relying on the refresh to
  EXTEND offline availability. ⚠️ **A second-order note for whoever takes it:** `maxEntries` bookkeeping is
  unaffected only because these keys already exist — a variant that ADDS a key this way would leave an entry
  Workbox cannot see at all. **Live.** Filed by `M74`.

- ✅ **CLOSED BY `M75` (2026-09-06) — `minor` · ~~A Vitest case asserts that a `setTimeout(…, 0)` fired, so
  its green depends on machine load rather than on the code.~~** ⛔ **THE ROW'S EVIDENCE HELD AND ITS CENTRAL
  DIAGNOSIS WAS WRONG, IN THE DIRECTION THAT MATTERS: THE CASE WAS NOT FLAKY, IT WAS VACUOUS.** happy-dom
  supplies `crypto.subtle`, so `solveChallenge()` awaited a real WebCrypto digest on **every** candidate and
  the test's zero-delay timer fired after candidate **zero** — roughly 10 ms in, thousands of iterations
  before the first `yieldToEventLoop()` at `n = 4999`. The assertion never reached the yield at all.
  ⛔ **MEASURED, NOT REASONED: with `challenge.ts`'s yield line deleted outright the case still passed
  11/11.** A row filed as *"this sometimes goes red for the wrong reason"* was in fact *"this can never go
  red for the right one"*.
  ⛔ **BOTH PRESCRIBED REMEDIES FAIL.** Fake timers **deadlock** — `vi.runAllTimersAsync()` resolves as soon
  as no fake timer is pending and the solver schedules none until `n = 4999`. The injected scheduler seam
  removes the race but not the cost, cannot assert the default hook at all (a counting stub replaces the one
  thing that makes `yieldToEventLoop` a macrotask), and would pin a cadence that is fiction: `config/guest.php`
  sets `max_number` to 120000, so production yields **24** times, not the 2 a 20000-space fixture would fix.
  ✅ **What works was already in the file ten lines above** — run the case on the pure-JS fallback with
  `vi.stubGlobal('crypto', {})`, where `sha256Hex()` is synchronous so the timer can only fire if the solver
  yielded. Deterministic, mutation-sensitive (the same deletion now reddens exactly this case), and
  **78 ms against 747 ms**. A test-only edit; the production-code change the row asked for was not needed.
  ⚠️ **AND THE LOAD ARTEFACT `M74` SAW WAS REAL, WITH A DIFFERENT CAUSE**: 747 ms against Vitest's 5000 ms
  default `testTimeout`. The cost was the flake; the vacuity was the defect.
  ⚠️ **What is NOT closed, and is filed below**: nothing pins the yield on the `crypto.subtle` path, where it
  is unobservable by this technique, and nothing anywhere asserts the yield CADENCE.
  ⬇️ **The row as filed:** Found by `M74` during a full local suite run, and filed rather than fixed
  because it is not this increment's row and the fix is a real design choice.
  `resources/public-runtime/__tests__/challenge.test.ts`'s *"yields to the event loop on a long search"*
  sets a zero-delay timer, awaits `solveChallenge()` over a deliberately long search, and asserts the timer
  ran. ⛔ **Measured both ways**: it FAILED in a full 134-file run on a loaded host and PASSED 11/11 when the
  file was run alone, and `lib/challenge.ts` has **no imports at all**, so nothing in that increment's diff
  could reach it. **It is a load artifact, not a regression** — and that is exactly what makes it worth a
  row: the case cannot distinguish *"the solver never yielded"* from *"the host was too busy to run a
  macrotask in time"*, so it will go red on a slow CI runner and be re-run until it passes, which is the
  habit every other control here exists to prevent. ⚠️ **The property under test is real and worth keeping**:
  the solver runs inside `ApiClient.submit()`, which the service worker also calls, and a SW cannot spawn a
  Worker — so blocking there stalls every other fetch it handles. The case documents that at its own site.
  ✅ **The remedy is to assert the MECHANISM rather than a race**: drive the yield through an injected
  scheduler seam and count the yields, or fake the timers so the assertion is deterministic. Both make the
  test say *"the solver called its yield hook N times"*, which is the claim, instead of *"the event loop
  happened to be free"*, which is the weather. **Live.** Filed by `M74`.

- ✅ **CLOSED BY `M76` (2026-09-06) — `minor` · ~~PHPStan run where `CLAUDE.md` says to run it reports 18 errors, and `docs/gate-baselines.md` records the CI figure as "OK, no errors".~~**
  ⛔ **THE ROW ASKS THE TAKER TO CHOOSE BETWEEN THREE REMEDIES AND DOES NOT KNOW THE CAUSE. ALL THREE ARE
  WRONG.** The cause is this repository's own most-documented trap reaching inside a vendor library.
  Measured in the app container, same directory and same process: `RecursiveDirectoryIterator` returns
  **86** of `database/migrations`, while `glob`, `scandir`, a recursive `scandir` walk and
  `Symfony\Component\Finder` each return the true **113**. Larastan's migration reader uses the blind one,
  and the 27 files it drops own every one of the flagged properties. Hand-written `@property` lines would
  paper over a vendor enumeration bug.
  ✅ **AND THE DIVERGENCE IS NOT HOST-VERSUS-CI — IT IS CONTAINER-VERSUS-EVERYTHING-ELSE.** The **host**
  reports **zero**, matching CI. Proved rather than assumed: a deliberately broken file under `app/` turns
  the host run red with file, line, message and identifier, and removing it returns it to zero.
  ⛔ **SO THE HONEST REMEDY IS TO STOP SENDING PHPStan TO THE CONTAINER — `CLAUDE.md`'s gate table is wrong
  in exactly the way and for exactly the reason it already sends the five lint gates to the host two rows
  above. THAT EDIT IS FILED, NOT TAKEN**, because `CLAUDE.md` is a second hub file and `D13` allows one;
  `D15`, which asks for that cap to be relaxed, is open, and an increment does not relax a user decision on
  its own judgement while the request to relax it is pending. See the row below.
  ⛔ **WHAT THE ROW IS A FLOOR FOR, BY A FACTOR OF THREE.** `CLAUDE.md` scopes this trap to the lint gates.
  Measured across the container's whole tree: **app 719 of 814 · tests 472 of 512 · database 130 of 157**,
  `routes` and `resources` clean. Whole directories vanish — `app/Enums`, and all **52** files under
  `app/Http/Controllers/Tenant`. **Three `tests/Feature/` sweeps used the blind iterator** and now use
  `Tests\Support\SourceTree`, which cross-checks two independent enumerators; `ClientUuidScopeTest`'s floor
  was `toBeGreaterThan(200)` against a tree of 814 and slept through the loss of 95 files, and
  `SubmissionReferenceDisclosureTest` had **no floor at all**. Both are security sweeps, and the directory
  they were blind to is where their offenders would live. Mutation caught. Closed by `M76`.

  **The row as it stood.** Found by `M75` (2026-09-06) while measuring a gate it had
  predicted could not move. `CLAUDE.md`'s gate table says PHPStan is **container only**; run there —
  `vendor/bin/phpstan analyse --no-progress`, the exact command `composer run analyse` wraps — it reports
  **18 errors**, every one of them `Access to an undefined property` on an Eloquent model
  (`FormField::$default_value`, `$appearance`, `$created_by`; `User::$two_factor_secret`;
  `FormSection::$icon`, `$color`; `FormVersionResource::$description`). ⛔ **The columns are real** —
  `default_value` is declared in `2026_07_06_000205_create_form_fields_table.php` — and the models carry
  `@property` blocks that do not cover these, so this is Larastan resolving model properties **without a
  schema it can read**, not a code defect. ⚠️ **The divergence is the defect, not the 18.** A local run of a
  merge gate that disagrees with CI in the direction of MORE errors trains the reader to ignore it, and the
  failure mode is not the wasted hunt — it is the nineteenth error, which is real and invisible in a wall of
  eighteen that are not. ⚠️ **`M75` could prove its own diff was unaffected only because it touched no file
  under `app`, `database` or `routes` at all**, so the analysis input was byte-identical to the trunk; an
  increment that touches one of those three has no such argument available and nothing to compare against.
  ⚠️ **Two migrations are also `Pending` locally** (`…000109_create_sso_verified_domains_table` and
  `…000110`), which is a separate observation and does NOT explain these errors — none of the flagged columns
  belongs to those migrations. **Whoever takes it should decide between three things, and they are not
  equivalent**: make the local run reproduce CI (a documented pre-step), record the local figure alongside
  the CI one in `docs/gate-baselines.md` so the gap is stated rather than discovered, or add the missing
  `@property` lines so both environments agree. **Live.** Filed by `M75`.

- ✅ **CLOSED BY `M76` (2026-09-06) — `minor` · ~~Six `DOMException [AbortError]` stack traces print during a fully green Vitest run.~~**
  ⛔ **"THE REMAINING SIX ARE IN OTHER SUITES" IS FALSE. ALL OF THEM WERE IN `encode.test.ts` — THE FILE
  `M75`'s RELEASE RECORDS AS HAVING BEEN TAKEN TO ZERO.** Proved by subtraction: the other 61
  `resources/js` suites and all 84 public-runtime and design-system suites emit **zero**, and neither of
  those trees contains a real `fetch` call site.
  ⛔ **AND THE REMEDY WAS IN THE WRONG PLACE.** `M75` read them as teardown artefacts and stubbed `fetch`
  in `afterEach`. That is real and it is not all of them: the autosave composable also flushes on a step
  change, **un-debounced and mid-case**, long before any teardown, so those requests met the real `fetch`.
  The stub belongs in `beforeEach` as well — the `afterEach` one is still needed, because that hook's own
  `vi.unstubAllGlobals()` restores the real `fetch` before the unmount runs.
  ⚠️ **THE COUNT IS WRONG TOO, AND COUNTING THE SYMPTOM IS WHY.** The invariant is **ten** escaped
  requests, not six, and what they print is not stable: run alone this file emitted **ten**
  `AggregateError`/`ECONNREFUSED` traces and **zero** `AbortError`, because nothing was tearing a window
  down in time to abort them first. In a full run the teardown wins some of those races and the same
  requests surface as `AbortError`. Ten deterministic escapes looked like six flaky traces.
  ✅ **Measured after: 60 matching lines from that file → 0, and a full 135-file run prints none at all.**
  ⚠️ **It is not gateable as an assertion** — the run exits 0 with the traces printed — so this is closed
  on a measurement, not on a new gate, and that is stated rather than smuggled past. Closed by `M76`.

  **The row as it stood.** Found by `M75` (2026-09-06)
  while fixing two of them. A full `npx vitest run --pool=forks` exits **0** with 134 files and 2343 tests
  passing, and prints six `AbortError` traces from `happy-dom`'s `teardownWindow` aborting requests still in
  flight when a suite's window is torn down. ⛔ **`M75` measured the attribution rather than guessing it**:
  `encode.test.ts` accounted for two of them before this increment and would have contributed two more once
  it began unmounting components, and stubbing `fetch` for the teardown itself took that file to **zero**.
  The remaining six are in other suites and were not investigated. ⚠️ **The remedy transfers exactly and is
  one line** — stub `fetch` around whatever tears the component down — but ⛔ **`globalThis.fetch = …` does
  NOT work and was tried first**: it changes nothing under happy-dom, and `vi.stubGlobal` is what reaches the
  binding the code calls. That is the whole trap, and it is the reason this is worth a row rather than a
  passing note. ⚠️ **Sized `minor` because nothing is failing** — which is also precisely why it persists.
  **Live.** Filed by `M75`.

- **`minor` · The brand sweep now renews every entry it touches, which resets `maxEntries` eviction from
  recency-of-use to sweep order — and nobody has decided whether the sweep should be narrower.** Found by
  `M75` (2026-09-06) as a direct consequence of the clock fix it shipped, and recorded at the moment the
  scope was decided rather than after. `workbox-expiration` enforces `maxEntries` by walking the `timestamp`
  index newest-first and deleting past the 20th survivor, so that index is a recency-of-**use** order —
  `ExpirationPlugin` stamps an entry on every read as well as every write. `refreshCachedShells()` renews
  nearly every entry in one pass, so on the boots where it runs the order becomes `cache.keys()` order
  instead. ⚠️ **It re-establishes itself as entries are read again, and the writes are staggered rather than
  identical** (each waits on its own fetch — 8 ms apart in the probe that established this), so this is a
  one-off reordering per brand change and not a permanent degradation. ⛔ **But two consequences deserve a
  decision rather than a docblock.** (1) The resume shell, alone in not being renewed, now sorts **oldest**,
  so on a device holding more than twenty shells it is the first eviction — consistent with why
  `isResumeShell()` exists, and the opposite of what its *"SKIPPED, NEVER PURGED"* docblock implies. (2)
  `isResumeShell()` **fails open** on an unparseable URL, and that used to cost a token-bearing shell nothing
  but rewritten bytes; it now costs it a renewed lifetime. ⚠️ **The prior state was the anomaly** — a fresh
  body with a stale timestamp is a combination Workbox's own model cannot produce — so there is no
  "restore the old ordering" option; the real choices are to narrow the sweep, to stagger deliberately, or to
  accept it and say so. **Live.** Filed by `M75`.

- ✅ **CLOSED BY `M77` (2026-09-06) — `minor` · ~~The proof-of-work yield is pinned on the fallback path only, and its CADENCE is asserted
  nowhere at all.~~** Found by `M75` (2026-09-06) while replacing the vacuous assertion that preceded it, and
  filed rather than folded in because the second half needs a decision. ⛔ **The honest limit of the new
  case**: under `crypto.subtle` — which happy-dom supplies and which every https respondent gets — the
  awaited native digest turns the event loop on **every** candidate, so `yieldToEventLoop()` being called or
  not is unobservable by a timer. The property is real on that path and nothing pins it there. ⛔ **And the
  cadence is worse than unpinned, it is unexamined.** `challenge.ts` yields at `n % 5000 === 4999`;
  `config/guest.php` sets `max_number` to **120000**, so a worst-case production solve yields **24** times
  and no test, comment or document anywhere states that number or why 5000 is the right interval. ⚠️ **A
  seam-and-count rewrite would pin a cadence derived from a 20000-space fixture — two yields — which is
  fiction against the real search space**, so the fix is not simply "inject the hook". ⚠️ **Why it matters
  beyond tidiness**: the solver runs inside `ApiClient.submit()`, which the service worker also calls while
  draining the outbox, and a SW cannot spawn a Worker — so the interval is the only thing standing between a
  long solve and every other fetch that worker is handling. **Live.** Filed by `M75`.
  ✅ **DONE — `M77` (2026-09-06). The cadence is pinned; the interval's VALUE is `D18` and deliberately is
  not.** `challenge.ts` now exports `YIELD_EVERY` and `shouldYieldAt(n)` and takes an `onYield` seam with
  the real function as its default, so no call site changed.
  ⛔ **THE ROW'S CLOSING ARGUMENT IS INVERTED, AND THE INVERSION CAME FROM A DOCBLOCK THAT WAS ALSO WRONG.**
  The row says the interval is *"the only thing standing between a long solve and every other fetch that
  worker is handling"*. A service worker is **always** a secure context, therefore always takes the
  `crypto.subtle` branch, and an awaited native digest already turns the event loop on every candidate —
  measured in this project's node container: a `setTimeout(…, 0)` fires during 200 awaited
  `crypto.subtle.digest()` calls and does **not** fire during 200 awaited already-resolved promises. **So
  the yield does nothing in the service worker.** What it protects is the **insecure-embed tab**, which has
  the synchronous fallback hash and — for the same secure-context reason — no service worker at all.
  `challenge.ts`'s *"NO WEB WORKER"* paragraph asserted the opposite and is corrected in place.
  ⛔ **AND THE OBVIOUS INVARIANT IS ARITHMETICALLY FALSE, WHICH IS THE HALF THAT WOULD HAVE SHIPPED A
  GREEN LIE.** `yields === floor(candidatesTried / YIELD_EVERY)` is wrong for **24 of the 120,001** answers
  in the real search space — every answer at `n ≡ YIELD_EVERY - 1 (mod YIELD_EVERY)`, because the match
  RETURNS before the yield check is reached. The true form is `floor(answer / YIELD_EVERY)`, wrong for
  none, and both were swept exhaustively rather than reasoned about. ⚠️ **`M75`'s own existing fixture
  `challengeFor(12000, 20000)` is one of the answers where the wrong formula coincidentally agrees**, so a
  test written to the plausible invariant would have passed green while encoding a falsehood.
  ✅ **Proved by deliberate defect**: `shouldYieldAt` changed to the plausible wrong offset
  (`n % YIELD_EVERY === 0`) turns **8** cases red across both new tables.
  ⚠️ **STATED LIMIT, because it is the shape that makes gates vacuous**: both tables derive their
  expectations FROM `YIELD_EVERY`, so re-tuning the interval moves them and stays green. That is
  deliberate — pinning the value would be a gate asserting this repository's own open question — and it
  means these cases see a mis-COUNTING, never a re-tuning.

- **`minor` · A correction still cannot be autosaved, and the reason is an endpoint that does not exist plus
  a product decision nobody has taken.** Split out by `M75` (2026-09-06) from the leave-prompt row it closed,
  which named both halves and could only build one. `Encode.vue`'s autosave is off in edit mode by design and
  the presenter sends a null `draft_url` there, both deliberately: an edit autosaved down the DRAFT channel
  overwrites a respondent's answers with no `update` policy check and no audit row, and
  `SubmissionEditRoutesTest` pins `draft` and `draft_url` null in edit mode precisely so that cannot drift.
  ⛔ **So the missing piece is not a flag, it is a channel.** The only existing write path for a correction is
  `PATCH /submissions/{submission}/answers`, and `SubmissionAnswerEditService` sends an Approved response back
  to **UnderReview** and writes an audit ledger row on every call — so a debounced autosave on that channel
  means one demotion and one audit row **per tick**, which is not a smaller version of the current behaviour
  but a different product. ⚠️ **The leave guard makes this less urgent and not less real**: an editor is now
  warned rather than silently losing work, but an hour of transcription still lives only in the tab.
  👤 **The decision is the user's**: a draft-shaped side table for in-progress corrections, an explicit
  "save a working copy" action, or a documented statement that corrections are not resumable. **Live.**
  Filed by `M75`.

- **`minor` · `CLAUDE.md`'s gate table sends PHPStan to the container, one row below the rule that explains
  why the container is wrong.** Measured by `M76` (2026-09-06) while closing the 18-phantom-errors row.
  The table says *"PHPStan — Container only"*, and two rows above it says the five lint gates must run on
  the **host** because *"inside the app container `RecursiveDirectoryIterator` descends the Windows bind
  mount only partially"*. That is not a property of those five scripts — it is a property of PHP's SPL
  directory iterators on this mount, and it reaches Larastan, which is the entire cause of the 18
  `Access to an undefined property` errors a container run reports against CI's zero. ✅ **Measured both
  ways rather than argued**: the host reports **zero**, matching CI, and a deliberately broken file under
  `app/` turns the host run red with file, line, message and identifier before being removed.
  ⛔ **NOT TAKEN FOR A REASON THAT IS ABOUT PROCESS, NOT DIFFICULTY — IT IS A ONE-LINE EDIT.** `CLAUDE.md`
  is a hub file, `M76` had already spent its one `D13` hub slot on `docs/security-threat-model.md`, and
  `D15` — which asks for exactly that cap to be relaxed — is **open**. An increment does not relax a user
  decision on its own judgement while the request to relax it is pending. ⚠️ **Whoever takes it should fix
  the generator prose in `scripts/gate-baselines.php` in the same PR**, since `docs/gate-baselines.md`
  repeats the lint-gate-only framing and is regenerated from that script. **Live.** Filed by `M76`.
  ➕ **`M77` (2026-09-06) RE-MEASURED THIS INDEPENDENTLY AND ADDS THE MECHANISM AT FILE GRANULARITY**,
  because `M76` established the cause and not the specific link, and the specific link is what makes the
  row impossible to misread. In `dev_formbuilder_app-app-1`, over `database/migrations`: the SPL iterator
  enumerates **87 of 114** files while `scandir` and `glob` both return all 114 — **27 missing**, and
  `2026_07_06_000205_create_form_fields_table.php` is one of them. That file declares `default_value`,
  which is exactly the property the first reported error calls undefined on `App\Models\FormField`.
  Larastan derives model property types from the migrations, so the phantom errors are not a vague
  side-effect of the blindness: **each one is a column whose declaring migration the iterator cannot
  see.** ⚠️ Confirmed on the host in the same session — `phpstan` reports **0 errors** there, against
  **18** in the container on the identical tree, which is the divergence a reader will otherwise spend an
  hour on. ⚠️ **A second, unrelated container-only obstacle worth naming beside it**: a full
  `php artisan test` run dies at PHP's default 128 MB, and `-d memory_limit` on the `artisan` process
  does not reach the Pest child — invoke `vendor/bin/pest` directly.

- **`minor` · PHPUnit's own collector loses 40 test files in the container, and a local run reports green
  without them.** Measured by `M76` (2026-09-06). `phpunit.xml` declares its suites as `<directory>`
  entries, which PHPUnit expands through `SebastianBergmann\FileIterator\Facade` — an SPL directory
  iterator, and therefore blind on this bind mount. Measured: **385 of 425** `*Test.php` files collected,
  and the 40 missing were the **whole of `tests/Feature/Forms`** — every form lifecycle, policy, publish,
  schedule and RLS test in the repository, never loaded, never run, and never reported absent.
  ✅ **`tests/Feature/Docs/SuiteCollectionFloorTest.php` now makes it loud**, comparing the collector
  against `Tests\Support\SourceTree` and naming the missing files. It is **green on the host and in CI**
  (neither truncates) and **red in the container**, which is the truth in both places.
  ⛔ **THE OBVIOUS THEORY IS FALSE AND WAS TESTED: there is no entry-count threshold to document.**
  Synthetic directories of up to sixty files, created from inside the container on the same mount, do not
  truncate at all; `tests/Feature` holds 41 entries and enumerates perfectly while `tests/Feature/Forms`
  holds 46 and collapses to 6. The next directory to go blind cannot be predicted, which is why this is a
  gate and not a list. 👤 **What is left for the user is `D17`**: whether that permanent local red is
  wanted, or whether it should be softened. **Live** until `D17` is answered. Filed by `M76`.

- ✅ **CLOSED BY `M77` (2026-09-06) — `minor` · ~~`R7` pins the checkout depth to `PR commits + 1`, so a depth of 50 keeps every gate green
  while blinding the secret scan to 1,100 of 1,181 commits.~~** Measured by `M76`'s read-only fan-out
  (2026-09-06) while verifying `M49`'s open `fetch-depth` row, and recorded here so the expensive half is
  not re-derived. ✅ **Two of that row's premises are stale**: the depth IS pinned — by `R7`'s clone-shape
  assertion in `scripts/tracker-lint.php`, which `M49` shipped in the same increment that filed the row —
  and the integer governs **two** steps rather than three, because `tracker-lint-controls` builds its
  fixtures in `sys_get_temp_dir()`. ✅ **And `detect --source .` really does scan history**, proved from
  `.gitleaksignore`'s commit-scoped fingerprints, one of which resolves to a `PROGRESS.md` line that
  exists only in history. ⛔ **What remains is sharper than the row states**: `R7`'s pin is satisfied by any
  depth at or above the PR's commit count, so the range between that and full history is entirely
  unguarded, and `ci.yml`'s own comment claiming a reduction would *"fail LOUDLY"* is false across it.
  ⚠️ **The remedy that dominates all three of the row's uncosted candidates** is a
  `git rev-parse --is-shallow-repository` fence folded into the gitleaks step — an exact boolean needing no
  floor, no ratchet and no payload — plus a `tests/Feature/Docs/` assertion on the workflow file, built on
  the `NpmAuditJudgeTest` precedent, which is mutation-drivable because `fetch-depth: 0` occurs exactly
  once in `ci.yml`. The row's third candidate (`--log-opts`) is outright wrong for a scan with no memory.
  **Not taken**: `ci.yml` is a hub file and `M76`'s slot was spent. **Live.** Filed by `M76`.
  ✅ **DONE — `M77` (2026-09-06), spending its one hub slot here, and CLOSING `M49`'s row above in the
  same commit.** The fence is `git rev-parse --is-shallow-repository` folded into the gitleaks step, and
  `tests/Feature/Docs/SecretScanDepthTest.php` is its control. ⛔ **THE ROW'S REMEDY WAS RIGHT AND ITS
  ARITHMETIC WAS WRONG.** `git rev-list --count HEAD` is **987**, not 1,181 — the latter was the local
  clone's `--all` (1,187), and `actions/checkout` fetches one refspec, so a CI clone never holds the local
  ref set. Neither figure yields *"1,100"*. ⛔ **AND THE PIN IS EXACT, MEASURED RATHER THAN INFERRED**: a
  scratch bare mirror shallow-cloned at depths 1–10 against a real 7-commit merge puts R7 red at 6 and
  green at 7, with the clone holding 17 of 815 commits.
  ⛔ **TWO TRAPS THE ROW DID NOT NAME, AND EITHER WOULD HAVE SHIPPED A DECORATIVE GATE.**
  (1) `git rev-parse --is-shallow-repository` **exits 0 in both states** — it prints the answer — so an
  exit-status fence is either always red or never red; the string comparison is the whole gate.
  (2) The obvious error message *"must check out with <the key>: 0"* spells the exact token
  `scripts/mutate.php` mutates, which would make the token occur twice and turn the proof from CAUGHT
  into an abort with no verdict. That is this repository's token-in-prose trap, and `ci.yml` documents an
  earlier instance of it fourteen lines above the step being edited.
  ⚠️ **A THIRD instance bit the new gate on its first run** and is recorded in the test file: an ordering
  assertion over the step's raw body found `gitleaks detect` inside the explanatory comment and concluded
  the fence ran after the scan. The gate reads executable lines only, as `NpmAuditJudgeTest` already
  prescribes for the same reason.
  ✅ **Proved by deliberate defect**: `mutate.php` swapping the depth to a bounded value reports CAUGHT.

- **`minor` · The partial-path citation row's consequences are mostly false, and the measurement that
  settles `D15`'s dependency is done.** Measured by `M76`'s fan-out (2026-09-06). The mechanism in
  `scripts/backlog-triage.php` is exactly as filed — a slashed token gets one `is_file()` attempt and never
  falls back. ⛔ **But `M73` overstated what it costs, and `D15` was waiting on this.** Measured over all 91
  open rows: **8 slashed tokens across 6 rows** fail to resolve, and fixing them moves **no file across the
  hub threshold of 3** (largest gain 0→2), changes **nothing** in the suggested batch, and moves **not one**
  row out of *"no file harvested"* (27 before and after). ⛔ *"`render_batch` skips it by construction"* is
  **false** — that function has no such filter, and the exclusion is an accident of the citation-health
  tiebreak. ✅ **The remedy's stated decision is settled by the tree, not by the user**: the unambiguous-
  **suffix** variant resolves 6 of 8 where the basename fallback resolves 5, and is the only one that
  handles `members/Index.vue`, whose basename is 12-way ambiguous. ⚠️ **What will bite the taker is the
  control, not the fix**: the script has no test seam and calls `trunk_sha()` above both `--check` and
  `--dry-run`, so with git absent from the app container a Pest control exits 2 before reaching the
  resolver. ⚠️ **And it should be taken together with the two destructive-default rows**, which collide on
  the same hub file. **Live.** Filed by `M76`.

- ~~**`minor` · Neither Fortify form on `/settings` can render a validation error, and the mechanism is one
  missing `errorBag`.**~~
  ✅ **CLOSED — `M78` (2026-09-06). EVERY CITATION HELD, THE REMEDY WORKED, AND THE ROW UNDERSTATED ITSELF
  BY ONE FORM AND TWO PAGES.** The mechanism is exactly as filed and was proved by RENDERING it rather than
  by reading: driving `/settings` under Playwright and submitting the profile form with a duplicate address
  left the DOM **byte-identical before and after**, all eight `.mds-field__error` nodes empty. The control
  that settles the remedy is `auth/VerifyEmail.vue` — same endpoint, same bag, same duplicate address, same
  session — which renders the message, so the only variable is the visit option.
  ⛔ **THE ROW'S CENSUS WAS `grep validateWithBag app/`, AND FORTIFY ALSO BAGS ON THE EXCEPTION, IN VENDOR.**
  `ConfirmTwoFactorAuthentication` throws `->errorBag('confirmTwoFactorAuthentication')`, so
  `components/settings/TwoFactorSetup.vue` had the identical defect — and it is not a third form on this
  page, it is ONE component mounted on THREE pages, two of them lockout gates: `Pages/admin/TwoFactorSetup.vue`
  (super-admin MFA, where `superadmin.mfa` allows no other console route) and `Pages/auth/TwoFactorRequired.vue`
  (tenant enforcement, where the only other affordance is sign out). **A mistyped TOTP on either rendered
  nothing, so a person could not tell a wrong code from a broken page.** There is no fourth bag — measured
  exhaustively across `vendor/` and `app/`: three bags, four consumers, three broken.
  ⚠️ **THE `X-Inertia-Error-Bag` HEADER IS INERT AND THAT MATTERS FOR THE NEXT READER**: the middleware
  guards it with `$bags->has('default') && header`, which is false in exactly the case a bag is used — the
  two responses are byte-identical. The unwrap is client-side, in `getScopedErrors()`.
  ✅ **Gated at BOTH ends, deliberately.** A Vitest pins the literal the page sends (`Pages/Settings/Index.test.ts`,
  the page's first test ever, plus a case in `TwoFactorSetup.test.ts`); `tests/Feature/Settings/FortifyErrorBagTest.php`
  drives the real routes, reads the bag off the session and compares it to that same literal, so a rename on
  either side is red. Positive control: renaming the client bag by one character.
  ⚠️ **The deferred rate-limit note stays true and is unaffected** — a `ThrottleRequestsException` is a 429,
  not a bagged validation response, so it still lands nowhere in these forms. Filed by `M77`.


- **`minor` · Two user-supplied predicates now run on the `pgsql_auth` connection, against a standing rule
  that says none may.** Found by `M77`'s fan-out (2026-09-06). `docs/multi-tenancy-rbac-design.md` states
  the rule in terms — *"no user-supplied predicate may ever run on `pgsql_auth`"* — and it is restated in
  `docs/adr/0002-multi-tenancy-shared-db-rls.md` and in `docs/security-threat-model.md`, whose residual
  entry defends the grant on the grounds that it is *"consumed by exactly one server-derived equality
  predicate"* and names **a second consumer of the grant as its explicit revisit trigger**.
  `UpdateUserProfileInformation` runs `Rule::unique('pgsql_auth.users', 'email')` on an attacker-supplied
  value, and `CreateNewUser` does the same. ⚠️ **Two consumers, both user-supplied, so the stated trigger
  has fired.** ⚠️ **Sized `minor` deliberately and NOT as a live injection**: `Rule::unique` binds its value
  as a parameter, so this is a widening of a deliberately-narrow blast radius rather than a reachable
  exploit — the row is that the documented invariant and the tree now disagree, and three documents defend
  a property that no longer holds. ⚠️ **Whoever takes it must decide the direction**: narrow the code back
  onto the default connection, or amend all three documents to describe the rule that is actually being
  kept. Do not amend one and not the others. **Live.** Filed by `M77`.

- ~~**`minor` · The `@throws` sweep row's prescribed remedy is SELF-NULLIFYING, which no pass has said in
  three re-derivations.**~~
  ✅ **CLOSED — `M78` (2026-09-06). THE ARGUMENT IS CORRECT, UNBREAKABLE AND BANKED — AND IT STOPPED ONE
  SHAPE TOO EARLY.** Two agents attacked the self-nullification and neither could break it: an expectation
  derived from the docblock moves with the docblock, and a hardcoded class-to-marker map iterated over the
  declared set shrinks identically. ⚠️ **But it enumerated two shapes and concluded "cannot be built".**
  A committed FLOOR fixture — `route => list<class-string>`, asserted `fixture ⊆ harvested` — does not move
  when the docblock moves, reddens in an ordinary Pest run with no DB and no export, and has precedent in
  this very file (case 1 pins ~20 literal paths with `toContain`) and across the tree (`tests/fixtures/*.json`,
  the golden-vector suites). **It was still not built, for a better reason than the row gives**: it is
  `⊆`-shaped, so it is structurally blind to a route carrying NO tag — which is exactly the live defect this
  pair turned out to be hiding. See the parent row's closure.
  ⛔ **AND THIS ROW'S OWN CONSOLATION IS HALF FALSE, WHICH IS THE PART WORTH KEEPING.** It says an FQ-spelled
  `@throws` empties the walk so the `>= 1` floor fires *"loudly"*. True of the **403 arm** (one route, one
  tag). **False of the 409 arm**: FQ-spell one of promote's two and the sibling keeps `$declared` non-empty,
  the route stays in scope, the floor is satisfied, and the arm goes SILENTLY GREEN. ✅ The underlying
  contradiction is fixed: `OpenApiContractTest`'s helper docblock claimed the needle matches a
  fully-qualified `@throws`; the needle is `'@throws '.class_basename($e)`, which returns `false` for the FQ
  form — measured in PHP, not reasoned. Zero live instances today (all 95 `@throws` across `app/` are
  imported), so the defect was that the comment told a future author the opposite.
 Measured by `M77`'s fan-out (2026-09-06) and recorded here rather than closing the
  row, because the row was not in this increment's batch. The open row above prescribes asserting that the
  rendered `code` description contains each **declared** cause, and `M70` narrowed that to *"derive the
  expected code set FROM THE DOCBLOCK"*. ⛔ **An expectation derived from the docblock moves with the
  docblock.** Delete `@throws SubmissionConflictException` from `SubmissionPromoteController::store()`: the
  derived expectation shrinks to the surviving code, the re-exported 409 shrinks to exactly the branch that
  satisfies it, and the arm goes **green**. The regression the row exists to catch is a docblock LOSING a
  tag, so the remedy cannot catch it — for any route, ever. ⚠️ **It kills the obvious fallback too**: a
  hardcoded class→marker map is still iterated over the declared set, so it shrinks the same way. **The only
  shape that can detect docblock incompleteness is a per-route expectation INDEPENDENT of the docblock**,
  which is what the existing case 4 already is. ⚠️ **So the honest close is probably "cannot be built as
  specified, and the existing case is the answer"**, not a new gate — but that is the taker's call and the
  argument above is the expensive half, banked so a fourth fan-out is not spent on it. ⚠️ **One real defect
  found beside it and NOT settled**: the sweep's helper matches `'@throws '.class_basename($exception)`, so a
  fully-qualified `@throws` tag matches nothing; on today's tree each arm has exactly one route in scope, so
  an FQ-spelled tag EMPTIES the walk and the `>= 1` floor fires — meaning it is currently caught, loudly, by
  accident of there being no second route to hold the floor up. **Live.** Filed by `M77`.

- **`minor` · The data-dictionary row's CHECK-constraint census is wrong, and the correction is the opposite
  of a tidy-up.** Measured by `M77`'s fan-out (2026-09-06) directly against `pg_constraint` on the running
  database, and recorded so the next taker does not repeat a survey that has now been got wrong once.
  A grep-based census over `database/migrations/` concluded that **3 of 16** enum-catalog rows carry a
  Postgres CHECK. Measured live: the schema holds **44** CHECK constraints, the catalog has **28** rows, not
  16, and **11** of them are backed by one. ⛔ **`form_versions_status_chk` is the case that explains the
  miss**: it is live, it constrains a column the grep census listed as unconstrained, and it is created by
  first-party code in `app/Support/Migrations/PublishedVersionGuard.php` — **outside `database/migrations/`**
  and named with a `_chk` suffix the pattern did not match. ⚠️ **That is this repository's two-enumerators-
  disagreeing failure, committed by a survey of the document that exists to prevent it.** ⚠️ **Acting on the
  wrong number would have been worse than the vague preamble it replaced**: it would tell a reader that
  seven database-enforced columns are unenforced. Whoever takes the row should census from `pg_constraint`,
  not from the migrations directory. **Live.** Filed by `M77`.

- ~~**`minor` · `bootstrap/app.php` and `FortifyServiceProvider` both record a middleware index that is off by
  one.**~~
  ⛔ **CLOSED — `M78` (2026-09-06). THE ROW IS REFUTED: ALL THREE NUMBERS ARE CORRECT, AND THE INSTRUMENT
  THAT SAID OTHERWISE CANNOT MEASURE AN EXECUTION ORDER.** `route:list --path=... --json` reproduces the
  row's 4/5 — and its own output carries the literal STRING `"web"` at index 0, unexpanded. Nothing
  executes `"web"`. Run what the framework runs (`Router::gatherRouteMiddleware($route)`, after the HTTP
  kernel syncs its groups) and `Authenticate:web` is at **5** and `ThrottleFortifyEndpoints` at **6** on
  every authenticated route the comment is about; the `13` sub-claim reproduces too, by filtering the class
  out of `getMiddlewarePriority()` and re-sorting. ⚠️ **The index legitimately DIFFERS per route** — it is
  5 on the ten guest routes, which carry no `Authenticate` at all — so a test asserting an ordinal would be
  a liability rather than a gate. Nothing in the code depends on the number: `ThrottleFortifyEndpoints`
  reads `$route->getName()` and indexes nothing.
  ⛔ **AND THE FILE THAT WOULD HAVE HOSTED THE FIX ALREADY CARRIED THE WARNING THE ROW WALKED INTO** —
  `tests/Feature/Tenancy/TenancyMiddlewarePriorityTest.php:140`: *"`gatherRouteMiddleware()`, NOT
  `$route->gatherMiddleware()`, AND THE FIRST DRAFT OF THIS TEST WENT RED ON THE DIFFERENCE."*
  ✅ **THE ROW WAS WRONG AND THE INCREMENT STILL SHIPPED A REAL FIX, ONE FILE AWAY.**
  `app/Http/Middleware/EnforceGuestFormRateLimit.php:21` said the priority list puts `ThrottleRequests` at
  index 6. True when written (2026-08-08); `M43` — **the very commit this row was filed against** — inserted
  `ThrottleFortifyEndpoints` into that slot and moved it to 7. The row inspected two comments and never
  opened the third. Corrected to state the RELATION (throttle before tenancy), which is what the argument
  actually rests on and which never moved, following `config/fortify.php`'s prose-only precedent.
 Found by `M77`'s fan-out (2026-09-06) and reproduced on the host with `php artisan route:list
  --path=user/profile-information --json`. Both files carry the comment *"Authenticate runs at index 5 and
  this middleware at 6"*; the live route table puts `Illuminate\Auth\Middleware\Authenticate:web` at **4**
  and `ThrottleFortifyEndpoints` at **5**, with `web` unexpanded at 0. ⚠️ **Filed rather than fixed because
  it is two copies of a fact in two files** — the same shape those files exist to guard — and the durable
  question is whether an ordering that load-bearing should be asserted by a test rather than described in a
  comment that drifts. ⚠️ **It is a comment, so nothing is broken**; it is filed because the next reader
  reasoning about middleware order from either file will be wrong by one, and two increments have now
  written middleware-order code near it. **Live.** Filed by `M77`.

- **`minor` · The storage-quota row's stated blocker is not real, and its re-aim needs a copy decision
  nobody has made.** Measured by `M77`'s fan-out (2026-09-06); the row was verified and deliberately NOT
  taken, and this records both halves so the next attempt starts from the measurement. ✅ **The blocker is
  false**: the row says touching the device-wide count *"risks the boot drain that ADR-0021 makes
  load-bearing"*. The boot trigger reads `pending.value` alone, `queued` is a local const escaping into one
  string, and no candidate remedy goes near `listPending`/`retryAll` — so the drain cannot be reached from
  this fix. ⛔ **But the obvious re-aim is a trap.** The panel's region is titled *"My submissions on this
  device"*, which is its accessible name, so *"on this device"* is already the LOCATION word and *"My"* the
  ownership word; read in situ the three sentences reconcile. Relabelling the device-wide `queued` as
  *"N responses on this device"* would place a device-wide number under an ownership heading — plausibly
  manufacturing the very contradiction `M15` recorded fixing. ⚠️ **So the remaining work is a copy call**
  (*"across all sessions on this device"*, a visit-scoped count, or no number at all), it is genuinely the
  user's, and it changes a string another increment deliberately pinned in `sync-status.test.ts`. Whoever
  takes it should render the panel before rewording it — `M15`'s note says that is what caught it last
  time. **Live.** Filed by `M77`.

- **`minor` · `public-runtime-offline.spec.ts`'s parked-conflict case is FLAKY across viewport projects,
  and `D2` makes that a merge-blocking property.** Measured by `M77` (2026-09-06) while running the specs
  its own diff reached, and isolated with a control rather than assumed. Two consecutive runs of
  *"reviews & resolves a parked conflict (Increment G8c)"* failed on **different projects each time** —
  first `mobile` + `desktop`, then `tablet` — always at the same line, `expect(reviewButton).toBeVisible()`
  on `getByTestId('review-conflicts')` with a 15 s timeout. ✅ **It is NOT caused by the `sw.ts` change
  that increment shipped**: reverting `resources/public-runtime/sw.ts` to its pre-`M77` bytes, rebuilding
  the worker and re-running the same case reproduced the failure, so it predates the status filter. ⚠️
  **Why it matters rather than being ordinary e2e noise**: `D2` is answered — *"a flaky e2e result now
  fails CI"* — so a case that fails on a rotating third of its projects is a merge blocker that will
  redden unrelated pull requests, and the rotation is exactly what makes it read as somebody else's fault.
  ⚠️ **What is NOT known** is whether it flakes in CI or only on this host; a local run is one worker
  against a stack that is also serving a dev Vite, and the timeout is a visibility wait rather than a
  network wait. Whoever takes it should get a CI failure on record before tuning anything, because the
  obvious remedy — raising the timeout — is the one that converts a flake into a slow pass without
  learning why. ⚠️ **Two neighbours worth reading first**: the open row about what actually delivers the
  offline mis-cased render (two confident models of that spec have already been wrong), and the fact that
  running this spec at all needs `public/hot` moved aside — with a dev Vite running, Laravel emits
  dev-server asset URLs the e2e container cannot reach and **`global-setup` dies at the login form**,
  which looks like a broken fixture and is not. **Live.** Filed by `M77`.

- **`minor` · `preserveReviewedAnswers()` writes a media reference it has already deleted, in seconds, with
  no grace window.** Measured by `M78`'s adversarial fan-out (2026-09-06) while attacking a different row,
  and filed rather than fixed because the remedy is the same product decision that row is parked on.
  `RuntimeSession.vue`'s `handleSubmitError` opens, unconditionally for every `ApiError`, with
  `discardRow(db, uuid)` — and `deleteRow` removes that uuid's `media_queue` rows. Only afterwards does
  `handleDrift`'s catch reach `preserveReviewedAnswers()`, which writes an answers map **still containing
  `local:<id>`** onto the parked conflict row and then calls `repointToSubmission` against a table that no
  longer holds the row; `.modify()` on zero matches is silent. ⛔ **So the parked row is left carrying an
  unresolvable media ref — the failure mode the reaper's one-hour grace exists to bound, reached by a second
  route that needs no waiting at all.** If that row is ever replayed, `replay.ts` finds `local:` ids,
  `listForSubmission` returns nothing, and it parks as `needs_attention` with *"queued media is incomplete"*.
  ⚠️ **Contained, not harmless**: a `conflict` row is never picked by `listPending` and `retryRow` refuses
  one, so today it surfaces as a re-opened review whose media is gone rather than an immediate failure.
  ⚠️ **`repointToSubmission` is a SECOND ownership writer that post-dates the reaper row** (`M72`), which is
  why no earlier pass saw it. **Live.** Filed by `M78`.

- **`minor` · `reap.test.ts` leaves the mark set's status list unpinned, so a third of it can be deleted
  with all 14 cases green.** Measured by `M78` (2026-09-06). `liveLocalMediaIds` spares blobs referenced by
  outbox rows with status `pending`, `needs_attention` or `conflict` — but **every** outbox row in
  `reap.test.ts` is created by `enqueue`, which writes `status: 'pending'`. Mutating the `anyOf` to
  `.anyOf('pending')` leaves the whole file green. ⚠️ The `conflict` arm is exactly what the open
  conflict-review row's cheapest remedy leans on, so a fix built on it would rest on an unpinned line.
  Two cases (one `conflict`, one `needs_attention`) close it. **Live.** Filed by `M78`.

- **`minor` · The public runtime's media pick path has ZERO test coverage of any kind, and no e2e seeded
  form has a media field at all.** Measured by `M78` (2026-09-06). `grep -rn "stashOffline|OfflineMediaKey"`
  over the test trees returns **zero** hits: `stash()` is reached in tests only by direct
  `db.media_queue.put()`, never through the real provider at `App.vue`. And `E2eSeeder` publishes five
  slugs, **none with a media field** — the only media form it builds is left a DRAFT with no public slug,
  so no e2e run has ever exercised guest media end to end. ⚠️ **This is why the conflict-review media row
  has stayed theoretical through three passes**: the combination it describes cannot be reproduced by any
  fixture in the repository. Whoever takes it needs a seeded media form first. **Live.** Filed by `M78`.

- **`minor` · The data dictionary documents `tenants.status` defaulting to a value that is not a legal case
  of the enum it names.** Measured by `M78`'s fan-out (2026-09-06) against the live database. The row
  documents `'trial'`; the live default is `'active'`, set in the create-table migration. ⛔ **And
  `TenantStatus` declares exactly two cases — `Active` and `Suspended`** — so `'trial'` is not merely the
  wrong default, it is unrepresentable. ⚠️ The dictionary's enum catalog separately lists four values
  (`trial, active, suspended, cancelled`) for the same column; **that half is already filed** under the
  `M72` tenants-column row, and this is the DEFAULT half, which is not. ⚠️ It is also the single live drift
  the deferred documented-literal gate would find, so the two are worth taking together. **Live.** Filed by `M78`.

- **`minor` · `forms.timezone` is documented as defaulting to NULL and the database defaults it to `'UTC'`.**
  Measured by `M78`'s fan-out (2026-09-06) against the live schema; the default is set in the
  add-schedule-to-forms migration. ⚠️ **Filed separately from the `tenants.status` row because it hides in a
  different bucket**: the documented cell reads `NULL`, so a literal-vs-literal comparison skips it entirely
  and only a NULL-policy rule can see it. A gate built for the literal half alone would still miss this
  one. **Live.** Filed by `M78`.

- **`minor` · Two columns are documented under the wrong table in the data dictionary.** Measured by `M78`'s
  fan-out (2026-09-06). `draft_expires_at` and `draft_current_step` are documented inside the
  `submission_answers` section; **both columns exist on `submissions`**. ⚠️ Not covered by the open `M72`
  row, which is `tenants`-only, and not reachable by any default-drift gate, which compares a documented
  default to a column it looks up **by the section it is written under** — so a misplaced row is either
  skipped as unknown or compared against the wrong table. **Live.** Filed by `M78`.

- **`minor` · Nine live tables have no dictionary section, and they are not all framework scaffolding.**
  Measured by `M78`'s fan-out (2026-09-06): 20 live base tables have no column table, of which most are
  Laravel/Sanctum/PostGIS scaffolding and one (`submission_geo_index`) is already filed. The remainder are
  first-party or security-relevant and are documented nowhere: `impersonation_tokens`, `probes`,
  `global_probes`, `skeleton_probes`, and the whole spatie-permission set (`roles`, `permissions`,
  `model_has_roles`, `model_has_permissions`, `role_has_permissions`). ⚠️ `impersonation_tokens` and the
  permission tables are the ones that matter — the RBAC design document has no column table for any of them,
  so the schema of record for this application's authorization model is undocumented. **Live.** Filed by `M78`.

- **`minor` · The documented-literal drift gate needs three normalizer rules, not "a normalizer per type",
  and one of the row's two justifications is fabricated.** Measured by `M78`'s fan-out (2026-09-06) over all
  86 comparable pairs. ⛔ **The row's *"`false` where a document writes `No`"* example is WRONG: zero
  `Default` cells in either document contain `No` or `Yes` — that is the **PII?** column.** All 21 boolean
  defaults compare byte-identical with no normalization at all. The other justification
  (`'local'::character varying` vs `'local'`) holds exactly, with 43 siblings. Measured ablation: stripping
  the `::type` cast is needed by 44 pairs, stripping quotes by 3, canonicalizing JSON whitespace by 1, and
  **lowercasing by zero — a dead rule.** Three rules cover 85 of 86. ⚠️ **And the sizing is inverted**: a
  naive draft fires on **1** formatting difference and **2** real drifts, not the other way round.
  ⛔ **Two dependencies the row does not carry**: building it turns the open `M72` tenants-column row red
  unless taken with it (its three phantom columns are literal-shaped and land in this gate's `$unknown`
  arm), and although the row cites only the test file, the drift is IN `docs/data-dictionary.md`, so the
  work spends the batch's one hub slot. The triage's harvest is optimistic here. **Live.** Filed by `M78`.

- **`minor` · `MdsSegmentedControl`'s stretch-clamp census is short by two consumers, one candidate fix is a
  no-op, and the 30px figure cannot be reproduced.** Measured by `M78`'s fan-out (2026-09-06) against the
  built `dist/tokens.css`, not only the token source. The open spill row names two stretch-clamped hosts;
  there are **four** — `.sheets-fields` and `.encode-field` are the identical flex-column-implicit-stretch
  shape and are named nowhere, the latter on the submission encode path. ⛔ **`flex-shrink: 1` on `__seg` is
  a no-op** — it is the initial value — so one of the candidate remedies is dead on arrival and the row's
  phrasing is what makes it look live. ⛔ **The 30px is asserted, not measured**: its only provenance is a
  CI failure message computing spill past `.app-shell__content`, a different box from the scrollbar the row's
  title names, and that code path is now unreachable because the underlying defects are fixed — no test,
  fixture or snapshot records the number. ⚠️ It is also token- and font-stack-dependent. ⚠️ **And it cannot
  be proven by Vitest**: happy-dom lays nothing out, so a unit test can only pin the declaration's source
  text; the vehicle is a ~6-line element-level Playwright assertion already used twice in the e2e tree.
  ⚠️ The row's *"this falsifies J8"* clause is **already landed history**, not outstanding work. **Live.**
  Filed by `M78`.

- **`minor` · The standing `pgsql_auth` rule was never literally true, and its stated revisit trigger names a
  different grant.** Measured by `M78`'s fan-out (2026-09-06); filed rather than taken because the open row
  that prompted it prescribes a direction that is a live regression. ⛔ **Direction (a), "narrow back onto
  the default connection", BREAKS PRODUCTION AND NO TEST WOULD CATCH IT**: `users` is under FORCE RLS, so
  the default connection sees **0** rows for a taken address where the auth connection sees **1** — not only
  cross-tenant but for an invited-but-not-yet-active member of the same tenant — and the unique index then
  rejects the write as a raw 23505 → 500 instead of a validation error. Under `RefreshDatabase` the auth
  connection cannot see the test transaction, so every registration test uses a fresh address and passes
  either way. ⛔ **The rule as worded has never held**: the login form's email has always run as a predicate
  on `pgsql_auth` (`retrieveByCredentials()` is deliberately not overridden), as has the invite form's. The
  invariant actually kept is the one `GoogleSignInProvisioner` states — *no user-supplied predicate beyond
  exact equality*. ⛔ **And the threat model's revisit trigger names "a second consumer of the NEW grant"**
  (`tenant_users`), which `Rule::unique('pgsql_auth.users')` does not consume — so the stated trigger has
  not fired. ⚠️ **A real stale count exists in the other direction**: `docs/security-threat-model.md`
  residual 31 still says *"exactly one server-derived equality predicate"* while `ADR-0002` was amended
  twelve days earlier to record **two** consumers. ⚠️ The rule is restated in **13 files / 15 occurrences**,
  not the three the open row names, so "amend all three documents" undercounts by ten. **Live.** Filed by `M78`.

- **`minor` · `forms.single_page_mode` has no write surface outside the seeders, so single-page mode is
  unreachable for a real tenant — and its documented default disagrees across four documents.** Measured by
  `M79`'s sweeps (2026-09-06), brought here by `M80` through the three-term join — absent in code **and**
  unfiled **and** undecided — then attacked by a refuter that did not overturn it. ⛔ **NO WRITER OUTSIDE
  SEEDERS.** Declared at `app/Models/Form.php:41`, `:88` and `:120`, read at
  `app/Services/Submissions/EncodeFormPresenter.php:210` and
  `app/Services/Submissions/PublicFormPresenter.php:39` — and every assignment in the tree is a seeder or a
  test (`database/seeders/DemoSeeder.php:535`, `database/seeders/E2eSeeder.php:372`, `:474` and `:534`,
  `tests/Feature/Submissions/EncodeStepPayloadTest.php:76`). The sole creation path,
  `app/Services/Forms/FormService.php:67`, opens a `Form::create([` whose six explicit keys on the lines
  below it omit the column, so every real form takes the database default. No `FormRequest` names it, and
  `resources/js/Pages/forms/` has no toggle. ⚠️ **The sibling establishes this is a gap and not a house
  style**: `save_and_resume` carries a dedicated writer stack —
  `app/Http/Requests/Forms/UpdateSaveResumeRequest.php` and
  `app/Http/Controllers/Tenant/FormSaveResumeController.php`. ⛔ **THE DEFAULT HALF IS NARROWER THAN THE
  SWEEP OFFERED AND WORSE THAN IT LOOKS.** It is not doc-vs-code; it is four documents holding two
  incompatible values. `database/migrations/2026_07_06_000201_create_forms_table.php:46` and
  `docs/data-dictionary.md:221` say `false`. `docs/ux/form-filling-ux-flow.md:337` calls `true` "the literal
  default for a new form" and `docs/PRD.md:103` agrees. So repairing one pair does not settle it. ⚠️ The
  cost is already on the record: `PROGRESS_ARCHIVE.md:297` logs an E2E timeout caused by the seeded form
  defaulting to multi-step. **Live.** Filed by `M80`.

- **`minor` · `forms.allow_manual_encoding` is documented as Feature #7's capability flag and has neither a
  reader nor a writer — the only one of five inert `allow_*` flags whose feature actually shipped.**
  Measured by `M79`'s sweeps (2026-09-06), joined and refuted by `M80`. Five sites hold the name and none
  consults it: `app/Models/Form.php:83` (`$fillable`), `app/Models/Form.php:115` (cast),
  `database/migrations/2026_07_06_000201_create_forms_table.php:41` (default `true`),
  `tests/Feature/Tenancy/TenantExtractColumnDriftTest.php:53` (a column-name census) and the documenting
  row at `docs/data-dictionary.md:216`. The two places that would consult it gate on something else —
  `app/Policies/SubmissionPolicy.php:42` returns `submissions.create` with the published-version and
  Editor-grant conjuncts on the lines below, summarised at `app/Policies/SubmissionPolicy.php:33`, and no
  conjunct is this flag; the encode routes at `routes/tenant.php:656` reason explicitly about two *other*
  omitted gates without mentioning it. ⚠️ **TWO CORRECTIONS THE JOIN MADE TO THE FINDING, AND BOTH CHANGE
  THE ROW.** (a) It is one of **five** unread flags — `allow_api_import`, `allow_offline_sync`,
  `allow_ocr_single` and `allow_ocr_linelist` are equally reader-less. The other four sit ahead of unbuilt
  or held features and `docs/data-dictionary.md:217` carries that rationale explicitly; this one's feature
  is built, which is what makes it filable rather than a ratified absence. (b) The `$fillable` entry is
  **not** a mass-assignment risk: `app/Services/Forms/FormService.php:67` opens an explicit six-key array,
  so nothing can set it either. ⚠️ **The remedy is genuinely two-directional** — `docs/PRD.md:252` opens
  Feature #7 and its acceptance criteria never ask for a per-form off switch, so narrowing
  `docs/data-dictionary.md:216` is as defensible as wiring the gate, and the row should be taken with both
  priced. Precedent for the shape: `users.last_active_tenant_id`, already in this file. **Live.**
  Filed by `M80`.

- **`minor` · `forms.allow_offline_sync` has no reader anywhere — neither the sync manifest nor the PWA
  install entry honours the per-form offline gate that two documents describe, and it defaults to `true`.**
  Measured by `M79`'s sweeps (2026-09-06), joined and refuted by `M80`. Seven hits, all declaration:
  `database/migrations/2026_07_06_000201_create_forms_table.php:45`, `app/Models/Form.php:87` and `:119`,
  `tests/Feature/Tenancy/TenantExtractColumnDriftTest.php:53`, and the three documenting lines
  `docs/data-dictionary.md:220`, `docs/erd.md` (inside the `forms` entity block) and
  `docs/ux/form-filling-ux-flow.md:49`. ⛔ **THE SWEEP NAMED THE MANIFEST AND MISSED THE LOAD-BEARING
  HALF.** Both surfaces gate on the **plan** entitlement instead: `routes/api.php:293` carries
  `feature:offline_sync` and `app/Http/Controllers/Api/V1/SyncManifestController.php:69` never touches the
  column — but the installed PWA entry at `app/Http/Controllers/Public/PwaManifestController.php:66` is the
  one `docs/ux/form-filling-ux-flow.md:49` explicitly conditions on the per-form flag, and it gates
  `allow_guest_submissions` there, the published version at `:67` and the tenant-level module at `:68` —
  never the per-form column. ⚠️ **The AND-both contract is implemented for the sibling**, which is why the
  omission reads as a gap: `app/Http/Controllers/Tenant/SubmissionDraftController.php:41` states the
  doctrine, `app/Http/Controllers/Public/GuestDraftController.php:73` enforces the per-form half and
  `app/Services/Submissions/PublicFormPresenter.php:44` ANDs the two. ⚠️ **Calibration, so this is not
  over-filed:** it is one of the family of **five** inert flags the row above enumerates. It earns its own
  row only because offline sync **shipped** — the sync routes, the PWA manifest,
  `tests/Feature/Entitlements/OfflineSyncGateTest.php` — whereas the OCR flags are parked ahead of held
  work. **Live.** Filed by `M80`.

- **`minor` · The documented async export API — `POST /api/v1/forms/{form}/exports` and
  `GET /api/v1/exports/{export}` — has zero routes and no `exports` job row, while the substrate §7.3 names
  ships almost entirely, with only the submission PDF using it.** Measured by `M79`'s sweeps (2026-09-06),
  joined and refuted by `M80`. `routes/api.php` registers 68 routes and none matches `exports`; the
  nearest, `routes/api.php:402`, is a synchronous analytics stream. There is no `exports` table, no
  `Export` model, no `openapi.json` path. ⛔ **BUT "THE ENTIRE LIFECYCLE IS MISSING" IS WRONG, AND THE
  CORRECTION IS THE USEFUL PART.** Nearly every named need already exists under other names:
  `app/Enums/QueueName.php:40` (`Exports`), `app/Jobs/Submissions/GeneratePdfJob.php:59` which carries that
  queue and whose docblock at `:30` records the queue "has existed with none since H2", the dispatcher at
  `routes/tenant.php:723`, `AttachmentKind::export_artifact` at `docs/data-dictionary.md:41` and
  `NotificationType::export_ready` at `docs/data-dictionary.md:55`, the `exports_count` meter, and a 12/min
  fairness ceiling at `config/queue-fairness.php:68`. ⚠️ **One named piece does NOT ship, and the row would
  overstate itself by claiming otherwise:** §7.3 also says completion is signalled by a realtime Reverb
  push, and there is no broadcasting config in the tree at all —
  `app/Jobs/Submissions/GeneratePdfJob.php:40` says so itself. What is genuinely absent is the job row, the
  two endpoints and any status read. ⚠️ **It is also not specific to exports** — `PATCH /tenant`, form
  write CRUD, the draft group, `GET/PATCH/DELETE /submissions/{submission}`, attachments, `users`/`roles`
  and `subscription` are absent from `routes/api.php` the same way, and the sibling rows in this block name
  them. **Live.** Filed by `M80`.

- **`minor` · The documented `Users & roles` API resource group (`GET/POST /api/v1/users`, `/api/v1/roles`)
  has zero routes, and a shipped schema decision was already paid for it.** Measured by `M79`'s sweeps
  (2026-09-06), joined and refuted by `M80`. Not one of the 68 route registrations in `routes/api.php`
  contains `users` or `roles`; `app/Http/Controllers/Api/V1/` holds 22 controllers and none is a user, role
  or member controller; no `read:users` / `write:roles` Sanctum ability exists. The capability ships on the
  web surface only — `routes/tenant.php:409`, `:411`, `:417`, `:419`, `:421`. ⛔ **THE CODE ALREADY NAMES
  THE CONSEQUENCE:** `app/Http/Resources/Api/V1/AuditResource.php:28` says in as many words that there is
  no `/api/v1/users` endpoint, so an API-only consumer of `GET /api/v1/audits` receives `user_id` and
  `acting_as_user_id` UUIDs it cannot resolve. ⛔ **AND THE ENDPOINT WAS NEVER RETRACTED — IT SHAPED THE
  SCHEMA.** `docs/multi-tenancy-rbac-design.md:56` gives the forward reference to `GET /api/v1/roles` as
  the reason roles use UUIDv7 rather than Spatie's bigint PKs, echoed at `app/Models/Role.php:13` and in
  the comment opening at `config/permission.php:20`. ⚠️ **The one near-miss is a circular pointer, not a
  deferral**: `docs/multi-tenancy-rbac-design.md:711` defers the request/response *shapes* to Doc #14, and
  `docs/api-specification.md:13` points straight back at §7.1 as the authoritative inventory. Neither
  defers the build. This repository builds `/api/v1` twins deliberately — `routes/tenant.php:755` says
  so — so the web surface does not discharge it. **Live.** Filed by `M80`.

- **`minor` · §7.1's `Form draft` row pins four `/api/v1` builder endpoints registered nowhere, and the
  `validations` sub-resource exists on neither surface.** Measured by `M79`'s sweeps (2026-09-06), joined
  and refuted by `M80`. `docs/architecture/technical-architecture.md:443` pins
  `GET/PATCH /api/v1/forms/{form}/draft`, `.../sections`, `.../fields` and `.../fields/{field}/validations`.
  The forms family in `routes/api.php` is `:168`, `:172`, `:176`, `:180`, `:187`, `:194` and `:200` — read,
  publish and xlsform-import only. ⛔ **"THE ENTIRE BUILDER CRUD API IS UNREGISTERED" IS FALSE AS WORDED
  AND THE ROW MUST NOT REPEAT IT.** The builder ships on the tenant-web surface — `routes/tenant.php:553`
  through `:587`, all `can:update,form`, all on `FormBuilderController`. What is absent is the `/api/v1`
  **twin**, which this repository treats as a separate obligation:
  `docs/xlsform-interop-spec.md:138` records both surfaces for the adjacent export, and
  `routes/tenant.php:518` calls its own route "the browser-facing twin of the doc-pinned /api/v1 endpoint".
  ⚠️ **Two of the four pinned groups are twinned outright, one only partly, one not at all** — sections and
  fields have full web twins, the draft READ exists as `GET /forms/{form}/builder` at
  `routes/tenant.php:552` with **no** PATCH-the-draft twin anywhere in that block, and:
  ⛔ **THE SHARPER FINDING THE SWEEP MISSED:** `.../fields/{field}/validations` exists on **neither**
  surface. Validations are written inline from the field payload —
  `app/Services/Forms/FormBuilderService.php:157`, `:322` and `:331` — so nothing in the tree implements
  them as an addressable resource at all. ⚠️ Adjacent and deliberately in scope of the same repair:
  `docs/architecture/technical-architecture.md:441` also pins `POST /api/v1/forms` and
  `PATCH/DELETE /api/v1/forms/{form}`, and only the two GETs exist. **Live.** Filed by `M80`.

- **`minor` · The parity matrix scores API/programmatic import as Phase-1 shipped, and the code's own enum
  docblock calls it a later channel.** Measured by `M79`'s sweeps (2026-09-06), joined and refuted by
  `M80`. `docs/competitive-feature-parity-matrix.md:58` scores the row ✓ for this product, and the legend
  at `docs/competitive-feature-parity-matrix.md:7` defines ✓ as "fully supported today";
  `docs/competitive-feature-parity-matrix.md:118` repeats the claim. ⛔ **THE PREMISE UNDER THE ✓ IS
  FALSE.** The row leans on "the same Phase-1 `POST /submissions` endpoint as manual encoding" — and that
  endpoint is `routes/tenant.php:666`, a session-authenticated Inertia **web** route no token-holding
  caller can reach. `routes/api.php` has four submission-writing routes (`:300` sync, `:308` promote,
  `:531` the public guest post and `:565` the guest draft store, which reaches
  `app/Services/Submissions/SubmissionDraftService.php:409` and creates a `submissions` row) and
  `openapi.json` carries 51 paths, none of them `/forms/{form}/submissions`. ⛔ **AND NO APPLICATION CODE
  PATH WRITES THE CHANNEL.** The only `SubmissionSource::` writers under `app/` are
  `app/Http/Controllers/Api/V1/SyncSubmissionController.php:140`,
  `app/Http/Controllers/Public/GuestDraftController.php:93`,
  `app/Http/Controllers/Public/GuestSubmissionController.php:170`,
  `app/Http/Controllers/Tenant/SubmissionController.php:117` and
  `app/Http/Controllers/Tenant/SubmissionDraftController.php:97` — never `ApiImport`. ⚠️ **Scope that
  precisely, because the pipeline DOES accept the value**: `database/seeders/DemoSeeder.php:923` and
  `tests/Feature/Submissions/SubmissionPipelineTest.php` pass it in directly, so what is missing is an HTTP
  ingress, not pipeline support. The contradiction is in the code itself:
  `app/Enums/SubmissionSource.php:9` lists `api_import` among "the later channels". ⚠️ **Documentary, not
  live for an integrator**: the generated contract they build a client from never serves the phantom path.
  ⚠️ Stale premise found in passing — `routes/api.php:285` asserts a bound `forms/{form}/submissions` route
  exists in the Group-B surface, and it does not. **Live.** Filed by `M80`.

- **`minor` · §7.1's flat webhook-delivery paths and §7.4's per-tenant delivery-log UI both contradict the
  same document's per-endpoint spec, and only the per-endpoint form was built.** Measured by `M79`'s sweeps
  (2026-09-06), joined and refuted by `M80`. Every delivery listing in the tree is parent-scoped —
  `routes/api.php:323`, `routes/api.php:328`, `routes/tenant.php:770`, and the query chains opening at
  `app/Http/Controllers/Api/V1/WebhookDeliveryController.php:31` and
  `app/Services/Webhooks/WebhookEndpointPresenter.php:105`, whose `webhook_endpoint_id` predicates sit on
  the following line in each — and `openapi.json` carries the nested pair only. ⛔ **DO NOT TAKE THIS AS
  "BUILD `GET /api/v1/webhooks/deliveries`".** The sweep missed that the same document contradicts its own
  cited line 34 lines later: `docs/architecture/technical-architecture.md:487` specifies a **per-endpoint**
  delivery log, `docs/webhook-integration-design.md:104` says the same and is the spec
  `app/Http/Controllers/Api/V1/WebhookDeliveryController.php:16` cites, and the §7.1 column is headed
  `Representative endpoints` at `docs/architecture/technical-architecture.md:437`. The as-built follows the
  later, more specific document. ⚠️ **So the row splits in two and only the second half is a capability
  question.** (a) Two stale flat paths — `docs/architecture/technical-architecture.md:453` and
  `docs/webhook-integration-design.md:105` — want narrowing to the nested form. (b)
  `docs/architecture/technical-architecture.md:486` promises the dead-letter queue is visible in a
  per-tenant delivery-log UI, and no such view exists: `resources/js/Pages/webhooks/` holds only
  `Index.vue` and `Show.vue`, and `resources/js/Pages/webhooks/Index.vue:125` surfaces a delivery **count**
  as a stat tile, not a log. ⚠️ **The gap is narrower than "the DLQ is unsurfaced"**, and saying so keeps
  the row honest: `app/Enums/WebhookDeliveryStatus.php:33` is a real `dead_lettered` case and
  `resources/js/Pages/webhooks/Show.vue:266` renders delivery status generically, so the state is visible
  per endpoint — what is missing is the cross-endpoint view and any explicit dead-letter labelling.
  **Live.** Filed by `M80`.

- **`minor` · §7.1 names a `/api/v1/webhooks/endpoints` path segment that exists nowhere in the tree, and
  it is a floor rather than a census.** Measured by `M79`'s sweeps (2026-09-06), joined and refuted by
  `M80`; the join rated this one `medium` confidence where its neighbours were `high`, and the reason is
  recorded below. Built is `/api/v1/webhooks` — `routes/api.php:317`, `:320`, `:339`, `:342`, `:345` — with
  no `endpoints` segment anywhere; `openapi.json` exports exactly six `/webhooks*` paths and none carries
  it; outside this ledger the literal `webhooks/endpoints` returns **one** hit in the whole repository, the
  documenting line `docs/architecture/technical-architecture.md:452` itself. Tests corroborate the built
  shape — `tests/Feature/Webhooks/WebhookEndpointApiTest.php:50`. ⚠️ **A ROW IS A FLOOR:** the same table's
  next line, `docs/architecture/technical-architecture.md:453`, carries two more wrong paths, repeated a
  third time at `docs/webhook-integration-design.md:105` — which is the sibling row above, and the two want
  taking together. ⛔ **The header's "authored before any migration or application code exists" does not
  make §7.1 a frozen artifact**, which is the objection this row expects: the very same table is maintained
  against as-built at `docs/architecture/technical-architecture.md:445` and `:446`, so §7.1's paths were
  left behind by maintenance that reached their immediate neighbours. ⚠️ Severity is `minor` because
  `openapi.json` is drift-gated in CI and correct, so nobody generating a client is misled; the exposure is
  a reader planning against §7.1. **Live.** Filed by `M80`.

- **`minor` · NFR §8 sets a 30-day soft-delete grace period before a hard-deletion job, and for the three
  entities it names there is no purge job, no grace-period config value, and nothing that soft-deletes.**
  Measured by `M79`'s sweeps (2026-09-06), joined and refuted by `M80`.
  `docs/non-functional-requirements.md:105` states the target for forms, submissions and attachments.
  `app/Jobs/Maintenance/` holds seven jobs, scheduled in the block opening at `routes/console.php:47`, and
  none reads `deleted_at`. The two that do hard-delete are keyed on something else —
  `app/Jobs/Maintenance/PruneFailedJobsJob.php:52` prunes `failed_jobs`, and
  `app/Jobs/Submissions/ReapTenantDraftsJob.php:37` terminates a chain selecting on `draft_expires_at` and
  force-deletes *precisely because* a tombstone would reserve `client_submission_uuid`, which is the
  opposite mechanism from the one §8 describes. No grace-period knob exists in `config/`, and nothing under
  `app/` calls `onlyTrashed()`. ⛔ **THE JOIN FOUND IT MORE ABSENT THAN THE SWEEP DID, AND THE SCOPE MATTERS
  OR THE CLAIM IS FALSE: for forms, submissions and attachments there is no soft-delete WRITER either**, so
  the promised job would have nothing of §8's own subjects to purge.
  `app/Services/Submissions/ClientUuidResolver.php:75` says as much, `app/Policies/FormPolicy.php:120`
  declares `delete()` and the permission is seeded at `database/seeders/RolePermissionSeeder.php:54`, yet
  not one of the 22 `Route::delete` registrations across `routes/` is for a form, submission or attachment.
  ⚠️ **Three soft-delete writers DO exist elsewhere and §8's "etc." arguably reaches them** —
  `app/Services/Webhooks/WebhookEndpointService.php:178`,
  `app/Services/Connectors/ConnectionService.php:164` and
  `app/Services/Connectors/ConnectionSubscriptionService.php:114` each soft-delete and say so in a trailing
  comment — so a taker should settle whether §8 is scoped to its three named entities before sizing the
  job. ⚠️ **Two more places in one further document promise the same unbuilt jobs** —
  `docs/data-dictionary.md:131` and `docs/data-dictionary.md:542`, the latter an S3 object-deletion job
  that does not exist, which is the half carrying real future cost since orphaned objects outlive their
  rows. ⚠️ **Take it with `D14`, not independently**: `D14` recommends leaving the submission
  delete/restore surface unbuilt, decides the surface rather than the grace period, and says nothing about
  forms or attachments. `docs/non-functional-requirements.md:115` is §10 Out of Scope and does not list
  this. **Live.** Filed by `M80`.

- **`minor` · `docs/api-specification.md:63` states in the present tense that every unsafe request is
  deduplicated against a 24-hour Redis cache keyed on `(tenant_id, endpoint, Idempotency-Key)`, and no
  header, middleware or cache exists.** Measured by `M79`'s sweeps (2026-09-06), joined and refuted by
  `M80`. The literal `Idempotency-Key` returns exactly two hits repository-wide: the documenting line and
  one archive note. `ls app/Http/Middleware/` holds 29 entries and none is an idempotency middleware.
  `openapi.json` carries no such parameter object on any operation. ⚠️ **Eighty `idempoten*` hits exist
  under `app/` and `resources/` — state the scope, because repository-wide the figure is several hundred —
  and every one is a different, domain-specific mechanism**: `client_submission_uuid` offline replay
  (`app/Services/Submissions/SubmissionPipeline.php:92`), webhook delivery keyed on `(endpoint, event_id)`
  (`app/Services/Webhooks/WebhookEventDispatcher.php:23`), gamification award keys
  (`app/Services/Gamification/PointsRecorder.php:155`). None is Redis-backed, none has a 24-hour TTL, none
  replays a stored response. ⛔ **THE ODD-ONE-OUT ARGUMENT IS WHAT MAKES THIS FILABLE:** §2.4's immediate
  neighbour §2.5 **is** built, and the honest citation for that is the limiter registrations rather than
  the quota middleware — `app/Providers/AppServiceProvider.php:374` names §2.5 by name in its comment and
  `:377` registers the 600/min `api` limiter it pins, with the guest arm at `:400` and
  `EnforceGuestFormRateLimit` for the per-form dial. (`EnforceApiRequestQuota` enforces the *monthly*
  ADR-0008 quota and `MeterApiUsage` explicitly disclaims enforcement, so neither is a §2.5 implementation
  — a point worth having straight before quoting them.) ⛔ **AND A DEFERRAL WAS MADE ONCE AND LAPSED, WHICH
  THE JOIN SURFACED RATHER THAN BURIED.** `PROGRESS_ARCHIVE.md:322` records "Idempotency-Key (§2.4)
  deferred" inside Increment E's documented-not-fixed list. It names no reason, it targets **Phase 1** —
  long closed — and it was never converted into a queue row, nor were its three siblings from the same
  sentence. `docs/api-specification.md:304` is §4 Out of Scope and does not carry it. That archive note is
  the record of a deferral nobody filed, which is exactly what this row corrects. **Live.**
  Filed by `M80`.

- **`minor` · The per-endpoint `include_answers: true` webhook payload opt-in has no key anywhere — and
  four other files appear to record it as a deferral already taken.** Measured by `M79`'s sweeps
  (2026-09-06). ⛔ **UNJUDGED, AND THAT IS THE STATED PRECONDITION: the three-term join has not been run on
  this row.** Its join agent died on a usage limit mid-run; `M80` filed it rather than hold it until the
  limit reset, because withholding measured findings is the defect this increment exists to correct. ⚠️
  **The prior is unkind — 73% of the judged cohort was rejected.** Of the 41 findings from the same sweeps
  that did return a verdict, 30 were rejected: 17 were decisions already taken, 6 were duplicates and 7 did
  not survive re-derivation. ⛔ **AND THIS ROW LOOKS LIKE THAT LARGEST BUCKET, SO CHECK IT FIRST.** `M80`'s
  own citation pass found the deferral stated four times outside the cited design doc:
  `docs/data-privacy-gdpr-compliance.md:77` and `docs/piping-output-encoding-design.md:279` each say the
  opt-in "stays deferred", as do `PROGRESS_ARCHIVE.md:6859`, `:6864` and `:6865`. ⚠️ **What keeps the row
  fair rather than already-answered:** `docs/webhook-integration-design.md:171` is that document's own
  §6 Out of Scope and lists three deferrals, none of them this — so the design doc read alone genuinely
  presents it as an available tenant choice, which is what `docs/webhook-integration-design.md:41` does.
  The sweep's remaining evidence: `grep` over `app/`, `database/`, `config/` and `routes/` returns one
  hit — `config/webhooks.php:79`, a comment calling it forward infrastructure. No column on
  `webhook_endpoints`, no validation rule, no branch in the payload builders. **Latent.** Filed by `M80`.

- **`minor` · Structured JSON application logs are documented in the present tense and `config/logging.php`
  has no JSON formatter on any channel.** Measured by `M79`'s sweeps (2026-09-06). ⛔ **UNJUDGED — the
  three-term join has not been run on this row**, its agent having died on a usage limit; filed anyway
  rather than held. ⚠️ **Prior: 73% of the judged cohort from these same sweeps was rejected** (17
  already-decided, 6 duplicates, 7 not re-derivable), so verify before taking. The sweep's evidence:
  `docs/observability-incident-response.md:19` asserts "**Structured (JSON) logs**, not free-text — every
  log line is machine-parseable", while `config/logging.php:61` (`single`) and `config/logging.php:68`
  (`daily`) declare only driver, path, level and placeholder replacement, so both use Monolog's default
  free-text `LineFormatter`. The only `formatter` key in the file is `config/logging.php:104`, on the
  `stderr` channel, reading an env var with no default. `JsonFormatter` appears nowhere in `app/` or
  `config/`. ⚠️ **Premise a taker needs before choosing a remedy:** that document carries
  `**Status:** Draft v1.0` at `docs/observability-incident-response.md:4` and its §1 is headed "What's
  Already Decided", so it is a design artifact — yet the cited sentence is written in the present
  indicative. The repair may be a Monolog formatter or a tense correction, and which one is the actual
  question. **Latent.** Filed by `M80`.

- **`minor` · Correlation IDs threaded through every log line — `request_id` and `job_chain_id` — have no
  mechanism at all.** Measured by `M79`'s sweeps (2026-09-06). ⛔ **UNJUDGED — the three-term join has not
  been run on this row**, its agent having died on a usage limit; filed anyway rather than held. ⚠️
  **Prior: 73% of the judged cohort from these same sweeps was rejected**, so verify before taking. The
  sweep's evidence: `docs/observability-incident-response.md:20` describes the threading as live, and
  outside this ledger `job_chain_id` returns exactly one hit repository-wide — that documenting line
  itself. There is no `Log::withContext` and no use of Laravel's `Context` facade anywhere in `app/`,
  `config/` or `routes/`; every `TenantContext::` hit is the unrelated RLS helper. Every `request_id` in
  `app/` is a SAML column — `app/Models/SsoAuthRequest.php:30` and
  `app/Services/Sso/SsoAuthRequestService.php:70` on `sso_auth_requests`, and
  `app/Models/SsoAuthFailure.php:34` on `sso_auth_failures` — an IdP-minted request identifier, not a log
  correlation id. No middleware attaches one and no job propagates one. ⚠️ Pairs with the JSON-logs row
  above: the two cite adjacent lines of the same section and share one remedy surface — a Monolog formatter
  plus context processors — so check for overlap before taking either. **Latent.** Filed by `M80`.

- **`minor` · The API rate-limit table promises 300 requests/minute per authenticated user and no such
  limiter is defined.** Measured by `M79`'s sweeps (2026-09-06). ⛔ **UNJUDGED — the three-term join has
  not been run on this row**, its agent having died on a usage limit; filed anyway rather than held. ⚠️
  **Prior: 73% of the judged cohort from these same sweeps was rejected**, so verify before taking. The
  sweep's evidence: `docs/api-specification.md:73` pins the figure, and the literal returns one hit — that
  row. ⛔ **THE ENUMERATION MUST NOT BE READ AS A CENSUS, AND `M80` CORRECTED IT BEFORE FILING:** the sweep
  named eight limiter sites; the tree actually holds **24** `RateLimiter::for()` registrations across three
  providers — 14 in `app/Providers/AppServiceProvider.php`, 9 in `app/Providers/FortifyServiceProvider.php`
  and one job limiter at `app/Providers/QueueServiceProvider.php:64` that the sweep never named. The
  representative case is `app/Providers/AppServiceProvider.php:377` (`api`, 600/min); every other
  registration was read for its per-minute value and none is 300, none is user-keyed at that figure.
  ⚠️ **"The only throttle on an authenticated tenant route" is likewise too strong** —
  `routes/tenant.php:952` (`throttle:120,1`) is the only *numeric-literal* throttle inside the `auth` group,
  while `routes/tenant.php:385` and `:395` carry named limiters in the same group, both 20/min. Neither is
  300, so the conclusion survives; a taker grepping `throttle:` will get three hits and should not read
  that as the row being overtaken. ⚠️ **Premise for whoever writes the remedy:**
  `app/Providers/AppServiceProvider.php:374` records that `throttle:api` is priority-sorted *ahead of*
  authentication, so `$request->user()` is unresolved inside that closure and it keys on the token hash — a
  per-user 300/min limiter has to solve that ordering rather than copy the `api` shape. **Latent.**
  Filed by `M80`.

- **`minor` · `export_artifact` objects are documented as auto-deleted seven days after generation, and no
  scheduled cleanup task is declared.** Measured by `M79`'s sweeps (2026-09-06). ⛔ **UNJUDGED — the
  three-term join has not been run on this row**, its agent having died on a usage limit; filed anyway
  rather than held. ⚠️ **Prior: 73% of the judged cohort from these same sweeps was rejected**, so verify
  before taking. The sweep's evidence: `docs/deployment-infrastructure.md:129` states it operatively and
  names a scheduled cleanup task for local disk. The scheduled block opening at `routes/console.php:47`
  declares exactly seven entries — failed-jobs prune, usage roll-up, draft reaping, scheduled-form sweep,
  webhook retry sweep, connector-token refresh and custom-domain verification — and none touches export
  artifacts. The identifier resolves only to the enum case `app/Enums/AttachmentKind.php:31` and a
  storage-key comment at `app/Services/Submissions/SubmissionPdfStorage.php:30`. There is no seven-day
  constant and no `App\Jobs\Maintenance` class for it. ⚠️ Adjacent to the NFR §8 purge row above; both are
  unbuilt retention jobs and a single sweep design would serve them. **Latent.** Filed by `M80`.

- **`minor` · ADR-0007 §D11 describes three queue connections that `config/queue.php` does not define, and
  asserts they are annotated as forbidden when they were deleted.** Measured by `M79`'s sweeps
  (2026-09-06). ⛔ **UNJUDGED — the three-term join has not been run on this row**, its agent having died
  on a usage limit; filed anyway rather than held. ⚠️ **Prior: 73% of the judged cohort from these same
  sweeps was rejected**, so verify before taking. The sweep's evidence:
  `docs/adr/0007-async-execution-substrate.md:114` asserts both halves in the present tense — that
  `deferred`, `background` and `failover` shipped in `config/queue.php`, and that they "are annotated as
  forbidden in config". `config/queue.php:40` opens the connections array and defines only `sync` (`:42`),
  `database` (`:48`), `beanstalkd` (`:69`), `sqs` (`:78`) and `redis` (`:89`). The config's own header at
  `config/queue.php:29` records that the three were **removed**, deliberately, so that
  `QUEUE_CONNECTION=deferred` throws loudly at boot. ⚠️ **`M80` read the whole of line 114 and the row is
  weaker than the sweep made it sound**, which is worth knowing before taking it: the same sentence
  continues "deleting them outright is the cleaner option and is left to H2's judgment" — so deletion was
  an anticipated outcome delegated to H2, and H2 did exactly that. The stale present-tense assertion is
  real; the reader-harm argument is not. ⚠️ The cited line also carries its own stale citation, naming
  `config/queue.php:76-90` as where the three shipped; those lines are now the `sqs` block and the opening
  of `redis`. **Latent.** Filed by `M80`.

- **`minor` · The documented guest per-IP rate limit of 100/min has no definition — the ceiling on the
  surface that row describes is 60.** Measured by `M79`'s sweeps (2026-09-06). ⛔ **UNJUDGED — the
  three-term join has not been run on this row**, its agent having died on a usage limit; filed anyway
  rather than held. ⚠️ **Prior: 73% of the judged cohort from these same sweeps was rejected**, so verify
  before taking. The sweep's evidence: under the heading `### 2.5 Rate Limits` at
  `docs/api-specification.md:65`, a table introduced as "Concrete numbers" at
  `docs/api-specification.md:67` pins "Guest respondent (per IP, across all tokens) | 100 requests/minute"
  at `docs/api-specification.md:72`. The guest submit surface's per-IP arm resolves through
  `app/Providers/AppServiceProvider.php:408` to `config/guest.php:40`, which is 60. ⚠️ **Say "the surface
  that row describes", not "the only per-IP ceiling"** — `config/guest.php` defines three:
  `submit_per_ip` 60 (`:40`), `mint_per_ip` 30 (`:41`) and `challenge_per_ip` 90 (`:49`). None is 100.
  ⚠️ **And they are env-overridable defaults, not fixed definitions** — each is written
  `(int) env('GUEST_*', N)`, and no file in the repo sets those variables, so 60 is what a default
  deployment gets while a deployment could set 100 with no code change. That narrows the claim from "has no
  definition" to "has no definition at the default". ⛔ **What makes this a defect rather than an
  approximation:** the per-token figure in the same table matches `config/guest.php:39` exactly, so the
  table reads as authoritative. **Latent.** Filed by `M80`.

- **`minor` · "1 concurrent sync export per form, additional requests 429" — no concurrency guard exists on
  any export path.** Measured by `M79`'s sweeps (2026-09-06). ⛔ **UNJUDGED — the three-term join has not
  been run on this row**, its agent having died on a usage limit; filed anyway rather than held. ⚠️
  **Prior: 73% of the judged cohort from these same sweeps was rejected**, so verify before taking. The
  sweep's evidence: `docs/api-specification.md:75` promises the behaviour. ⛔ **`M80` NARROWED TWO
  ABSOLUTES THAT WERE FALSE AS WORDED, AND BOTH MATTER TO WHOEVER TAKES IT.** (a) The only *cache-backed or
  distributed* locks in `app/` are `app/Jobs/Connectors/RefreshOneConnectionJob.php:77`, a per-connection
  OAuth refresh lock, and `app/Jobs/MaintenanceJob.php:105`, the maintenance overlap guard — but row-level
  `lockForUpdate()` appears about twenty times, and one is a pointed counterexample:
  `app/Services/Submissions/FormAcceptanceGuard.php:89` is a per-form lock that already serialises
  concurrent finalizers on the same form, on the submit path rather than the export path. (b) The export
  routes are **not** unthrottled: `routes/api.php:187` and `routes/api.php:402` sit in the group whose
  middleware array carries `throttle:api` at `routes/api.php:152`, so they inherit the 600/min burst
  limiter. What is genuinely absent is an export-scoped or per-form guard — and a per-minute limiter cannot
  express a concurrency ceiling at all, which is the row's real point. ⚠️ Note the shape difference from
  its table neighbours: this is a **concurrency** promise, so a limiter audit that checks `RateLimiter::for`
  definitions passes straight over it. ⚠️ Pairs with the async-export row above —
  `docs/architecture/technical-architecture.md:469` opens §7.3 and defines both export modes, and neither
  is built. **Latent.** Filed by `M80`.

- **`minor` · `PROGRESS.md` is within roughly one status bullet of its `tracker-lint` R1 byte ceiling, and
  the two surfaces a session actually reads before pushing both stay silent about it.** Found by `M80`
  during its own close-out (2026-09-07), filed rather than taken because the remedy is a tracker surgery
  and this increment's subject was the backlog. `scripts/tracker-lint.php:76` sets the ceiling at 130,000
  bytes and the file stands at roughly 126.3 KB after `M80`'s bullet — under **4 KB of headroom**, against
  a status bullet that has cost between two and three KB in each of the last several increments. So the
  next increment fits and the one after it does not. ⛔ **THE TRAP IS NOT THE CEILING, IT IS WHERE THE
  SIGNAL ARRIVES.** `scripts/next.php` generates the hand-off line the next session reads first and never
  consults the ceiling — grepping it for the constant, for `ceiling` or for `headroom` returns nothing —
  and `scripts/preflight.php:274` reports the tracker's **line** count under its structural section but not
  its byte count, which is the half that binds; `CLAUDE.md` records that the byte half is the one that
  catches this file, because its bullets are single lines thousands of bytes long. The first signal is
  therefore `tracker-lint` reddening in CI on a push that has already happened, at close-out, with a PR
  open — the worst available moment. ⚠️ **Two honest remedies, and they are not exclusive.** (a) A tracker
  surgery moving the oldest status bullets to `PROGRESS_ARCHIVE.md`, proven with
  `scripts/tracker-surgery.php` rather than by hand and landed with the surgery marker at line start —
  which buys headroom but does not move the signal. (b) Teach `next.php` and `preflight` to report the
  remaining headroom the way `state.php` already reports how stale the gate baselines are, so the warning
  precedes the push instead of following it. ⚠️ Note the shape: this is a self-announcing constraint that
  is nonetheless invisible **where it matters**, which is the same defect class `M80` itself was filed to
  correct. **Live.** Filed by `M80`.
