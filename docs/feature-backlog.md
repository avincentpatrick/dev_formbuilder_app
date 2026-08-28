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
package's own dependency tree, which nothing in the root install provides, plus its own browser:

```
npm --prefix packages/design-system install
npx --prefix packages/design-system playwright install chromium
npm run ds:storybook:build      # 268 modules, preview built in ~7s
npx test-storybook --url ...    # 39 suites / 278 tests
```

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
  single most valuable output of the review: it invalidates a gate this project has been trusting.

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
  Linux font stack. **Live**, and now reproducible locally.

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
  Pint `passed`, `openapi.json` byte-identical, zero `.vue` / `.ts` / `packages/design-system/` / e2e movement.

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
  correctness one, which is why M3 declined to make it while fixing a correctness bug.

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
  sites, and rewriting a working one is its own increment with its own gate run.

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
  discovered by a linter.
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
  119**, `openapi.json` byte-identical, zero `.vue` / `.ts` / e2e movement.

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
  tabular rule (and say why), which lands in `resources/js/Pages/` — **Lane A's column**.

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
  one. Revisit if a tenant reports a missing row on a table fed by two rules.

- **`minor` · A 5xx that arrives AFTER the provider committed is still re-driven.** Filed by **M5
  (2026-08-19)**. M5 treats a received HTTP status as determinate, because both providers' contracts say a
  5xx means the write was not applied, and routing the far more common arm through an extra read to guard the
  exception would cost every transient error a round trip. **Latent, and strictly narrower than what M5
  closed**: it needs the provider to commit and *then* answer 5xx. Revisit if a tenant ever reports a
  duplicate whose delivery row carries a 5xx rather than a `[transport_error]` excerpt.

- **`minor` · `SlackConnector::deliver()` has the same non-idempotent shape and is deliberately not covered.**
  Filed by **M5 (2026-08-19)**, and named in the adapter's own docblock rather than left to be discovered.
  `chat.postMessage` accepts no idempotency key either, so a lost answer followed by a retry posts the
  message twice. Out of scope **on the merits**: a repeated chat message is noise a human dismisses in the
  channel it arrived in, where a repeated spreadsheet row silently biases every count taken over the tenant's
  dataset. And the fix would not be M5's — asking Slack "did my message land?" means reading channel history,
  a scope this connector does not request and should not acquire to dedupe its own retries.
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
  `openapi.json` byte-identical. ADR-0009 **§D6 amended in place** — no new ADR number spent.

- ✅ **CLOSED BY `M6` (2026-08-19), AND IT WAS NEVER FILED — `major` · a delivery against a REVOKED grant was
  re-dispatched every five minutes forever.** Found by following M6's own change through rather than by
  looking for it. `DeliverConnectorMessageJob::handleForTenant()` returned silently for a dead grant, leaving
  the row `failed` with its `next_retry_at` set and `attempt_count` untouched — so `WebhookRetrySweeper`'s
  `attempt_count < max_attempts` predicate stayed true and the sweep re-queued the same delivery every five
  minutes for the life of the row. **Masked, not absent**: the pre-flight refresh used to catch the revocation
  one attempt earlier and dead-letter it, so the loop had never been reachable in practice. Moving that
  refresh into its own job would have made it the only path — which is how a fix for one defect surfaced
  another. Now settled with `[grant_expired]`, asserted by its own case.

- **`minor` · A rotated token can still be lost in the one-UPDATE window M6 left.** Filed by **M6
  (2026-08-19)** at the moment the decision was taken. The gap between the provider committing a rotation and
  us committing the write is now one UPDATE wide instead of a whole batch, but it is not zero: a database
  failure in exactly that gap still leaves Airtable holding a pair we never stored. Closing it entirely needs
  a two-phase protocol **no provider here offers** — a rotation the client can confirm, or a grace period in
  which the previous refresh token still works. **Revisit trigger: the first provider that offers either.**
  Recorded in ADR-0009 §D6's M6 amendment as well, so the residual is visible from the decision and not only
  from the backlog.

- **`minor` · The setup-time directory has no pre-flight refresh**, so an ordinary token expiry tells the
  tenant to reconnect a healthy account — `app/Services/Connectors/TabularDestinationDirectory.php:46,68`,
  the one place H16a's guard was not applied. **Latent** on a missed sweep (H16a's own premise).
- **`minor` · `ConnectorRulePausedNotification` is the only tenant-facing connector email with no brand.**
  `app/Jobs/Connectors/DeliverConnectorMessageJob.php:330` sends it without `->withBrand(...)`, so a
  branded tenant gets one branded and one product-default email from the same job. **Live**, one argument.

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
  owners that never existed.
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
  four lint gates unmoved at 97 · 111 · 31 · 111/119/0, PHPStan 18 = baseline with zero delta by file list.**
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
  `docs/offline-first-sync-design.md` §5 and `docs/security-threat-model.md` §4.
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
  handled four of five causes and silently mis-handled the fifth.
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
- **`minor` · `RuntimeSession.handleDrift()`'s bare `catch {}` collapses every recovery failure into one
  sentence.** `resources/public-runtime/components/RuntimeSession.vue:160-168` binds no error, so a dropped
  connection during `remint()` or `fetchSchema()` reads as *"This form is no longer available."* — a terminal
  claim about the form, made about the network. **Filed by M14 at the moment it decided not to fix it**: it
  is a fourth fold site the two closed rows do not name, and widening past them was declined deliberately.
  **Live.**
- **`minor` · `replay.ts:223-228` hardcodes `conflict_code = 'form_updated'` on a client-side version
  guard.** Correct today — it really is a form-version drift, decided with no request made — but M14 turned
  `conflict_code` into **user-visible copy input** (`lib/conflict-notice.ts` keys the respondent's sentence
  off it), so this literal is no longer a debug tag. Nothing is wrong now; the hazard is that the next person
  to add a client-side park has to know that. **Not live — a maintenance trap.**
- **`minor` · The authenticated autosave's 409 branch tells a `submission_conflict` caller "already been
  submitted".** `resources/js/composables/useServerAutosave.ts:196-213` splits two ways — `draft_conflict`
  versus everything else — so the entitlement and content causes both get the finalized sentence, which is
  the guest-side defect M14 closed, one channel over. It is a smaller harm (the encode surface is staffed,
  not public) and **`resources/js/composables/` is in NEITHER lane's column under Standing Rule 7(b)**, so
  M14 declined it rather than claiming a directory for a `minor`. **Live.**
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
  trusted**: removing the declaration puts the file straight back in the offender list.
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
  the line numbers were not. Full reasoning in `docs/adr/0021-respondent-scoped-device-outbox.md`, amended.

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
  case that can now reach it, which is the narrower fix the row itself asked for.

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
  this consumed.

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
  of its own. The second is the better shape and is larger than this row. **Live.**

- **`minor` · The storage-quota line counts strangers' submissions.** `useSyncOutbox` computes `queued` from
  the device-wide count and renders *"N responses waiting to send"*, while `mine`, `earlierUnsent` and
  `conflictHere` beside it are all visit-scoped — so a respondent can read three consecutive sentences whose
  numbers only reconcile if they count a stranger's rows. Filed rather than fixed: it discloses a count and
  nothing else, which is exactly the shape ADR-0021 sanctioned for an earlier visit, and touching the
  device-wide count risks the boot drain that ADR-0021 makes load-bearing. **Live.**

- **`minor` · Resume-link shells sit in Cache Storage, and the brand refresh re-fetches them.** A resume
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
  it. **Live.**

- **`minor` · The two `draft_answers` readers disagree about which `form_version_id` they mean.** The
  autosave writes with the **currently published** version; `App.vue`'s resume read fetches with the version
  the **server draft** was pinned to. They coincide only until a republish intervenes, after which the
  resume path probes a key the live session never writes — the orphan slot in the row above. Benign today
  only because `reconcile.ts`'s checksum guard rejects the hit, which means **the checksum guard is the only
  thing standing between the resume path and a pile of pre-republish drafts.** Filed so that whoever tidies
  the mismatch knows what it is load-bearing for. **Live.**
- **`minor` · `useServerAutosave.dispose()` fires without consulting `inFlight`.**
  `resources/js/composables/useServerAutosave.ts:425-431` sends a `keepalive` POST carrying a **stale**
  `base_content_checksum` on an Inertia navigation during a save, so the server refuses it as
  `draft_conflict` and the edits made during that request are silently dropped. **Live.**
- **`minor` · The encode page's conflict refusal remounts and discards the editor's corrections.**
  `resources/js/Pages/submissions/Encode.vue:709` returns the optimistic-concurrency refusal as a flash
  toast rather than a validation error, so `preserveState` evaluates false and the page reloads from the
  stored document. The docblock reasons only about the 422 path and gets the conflict path — the one the
  machinery exists for — backwards. **Live.**
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
  engineered around.
- **`minor` · The `reviewer` role's seeded description and `SubmissionPolicy::create()` contradict each
  other.** Filed 2026-08-25 by M13, which made the contradiction observable on a second surface and
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
  and notes *"no existing test asserted the old behaviour"*, which is why it went unnoticed.
- **`minor` · Neither sync route documents the 403 its in-controller policy gate now returns.** Filed
  2026-08-25 by M13. `openapi.json` lists `200/404/422` for `GET /sync/manifest` and `200/422` for
  `POST /sync/submissions`, while the first can return a `403 forbidden` and the second a per-item
  `error.code: "forbidden"`. Scramble infers a 403 from route **middleware** and does not trace a
  `Gate::forUser()->authorize()` call in a controller body — which is measurable rather than assumed:
  `POST /form-templates` has carried exactly that call since G9a and documents only `200/422` too. **This is
  the same row as the already-open one for `/submissions/{submission}/promote`'s three undocumented 409
  causes**, one layer over, and it is unfixed for the same reason: `openapi.json` is generated and CI diffs
  it against a fresh export, so a hand edit fails the contract job — the honest fix is an annotation
  mechanism Scramble 0.13 does not offer for arbitrary status codes, or moving these gates somewhere the
  generator can see them. **Live**, pre-existing in kind.
- **`minor` · `SyncSubmissionResultResource`'s generated contract types `submission` and `error` as bare
  strings.** Filed 2026-08-25 by M13. Both are object-or-null in every response the controller builds —
  `submission` is `{id, reference, status}`, `error` is `{code, message, details?}` — but Scramble infers a
  `string` for each, so `openapi.json` describes a shape no response has ever had. An integrator generating
  a client from the contract gets types that fail to deserialise on the first item. **Live**, pre-existing
  since G8b, and the reason M13's per-item error codes could be added without moving the document at all:
  they are not enumerated anywhere. Same `openapi.json`-is-generated constraint as the row above.
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
  decision is taken deliberately if a Reviewer-facing encoder client is ever built.
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
  the `submissions` row lock every writer of that document holds.
- **`minor` · `/api/v1/submissions/{submission}/promote` documents no 409 at all, and three causes reach it.**
  Filed 2026-08-25 by M12. `openapi.json` lists `200/404/403` for that route, while
  `SubmissionDraftService::promote()` can raise `submission_version_superseded` (H9b),
  `max_responses_reached` (H12a, a 403 with a body the document does not describe either) and — since M12 —
  `draft_conflict`. Scramble infers from the CONTROLLER's own returns, which is why a service-thrown
  exception has never appeared there and why M12 could add a cause with the document staying byte-identical.
  So an integrator building against the contract has no reason to handle a refusal that is a normal outcome.
  **Live**, pre-existing, and deliberately not fixed in M12: `openapi.json` is a Standing-Rule-7(b) NEITHER
  artefact, so moving it needs its own claim, and the honest fix is a `@response` annotation per cause rather
  than a hand edit.
- **`minor` · Four P3a refusal cases assert the exception CLASS and never the message.**
  `tests/Feature/Submissions/SubmissionDraftServiceTest.php` — the P3a section's `toThrow(
  SubmissionConflictException::class)` calls. Filed 2026-08-25 by M12, which is the second increment running
  to be bitten by this: M11's mutation pass proved that assertion passes for `contentConflict()` too, and
  `SubmissionConflictException` now carries FOUR causes of which two share the `submission_conflict` code —
  so only the message separates them on the wire. Those four cases are safe **today** for a reason that is
  not written down anywhere near them (the resolve finds the row, so `clientUuidClaimed()` is unreachable on
  that path), which is precisely the shape that stops being true after an unrelated change. Not a live
  defect; a live blind spot. M12's own seven refusal cases all assert the message.

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
  was green before and after.
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
  its card shows points, badges and streak. Both fields are deleted rather than gated.
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
  owns both readings; it moves `AchievementsPageTest`'s dashboard-card case.

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
  `openapi.json` byte-identical, zero `.vue` / `.ts` / e2e-selector movement.

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
  form, this button or `logout`.
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
  job added); `openapi.json` byte-identical; **zero `.vue`, zero `tests/e2e/` selector movement.**
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
  gains a row: it has none for invitation takeover today.
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
  to the three things that genuinely remain, each filed below.

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
  question for custom hosts. Carried as `docs/security-threat-model.md` residual 32.

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
  precisely so it was not a lie before this row lands — update it to name the card once it does.

- **`minor` · `MemberController::invite()` validates `['required', 'email', 'max:255']` and a role, with no
  domain-ownership check.** Filed 2026-08-26 by M18. The same root on the invitation door, and the first link in
  the chain M9's own write-up traces. ⚠️ **NOT the takeover M8 and M9 closed** — both of those are shut, and
  RBAC §7's *"an unaccepted invite grants nothing"* still holds — so what remains is narrower: an admin can
  address an invitation into a domain their workspace has never proven it controls, which sends a real email to
  a real stranger and binds a `tenant_users` row to their existing global identity. M18's own control is the
  obvious shape to reuse (`SsoDomainService::isVerifiedFor()` is already phrased over an address), but applying
  it here is a **product decision, not a cleanup**: today any workspace may invite anyone, including
  contractors and personal addresses, and gating that on DNS would change what invitation means for every
  workspace rather than only for SSO ones. Whoever takes it decides that first.

- **`minor` · Self-registration remains a way to occupy an address in a domain you do not control.** Filed
  2026-08-26 by M18, recorded because §D34's *"an active membership is the grandfather"* reasoning depends on
  knowing exactly which doors mint one, and this is the weakest of the four. `joinOpenTenant()` is reachable on
  any workspace whose `RegistrationGate` is open, so a registrant may take `victim@othercompany.test` and hold
  it. ⚠️ **Materially weaker than what M18 closed, and the difference is what makes it a `minor`**: the
  registrant sets their own password and **nothing forges `email_verified_at`**, so the account squats an
  address without minting a false claim about mailbox control — which is the property `identityIsEstablished()`
  reads. Older than SSO, and any fix touches the ordinary registration path for everybody.
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
  behaviour is judged intentional, an ADR-0016 sub-decision saying so.
- ✅ **CLOSED BY `M9` (2026-08-24) — `minor` · ~~`decline()` asks no identity question at all, so a token holder can destroy an established
  member's pending invitation.** The one invitation door M8 deliberately did not touch.
  `InvitationController::decline()` resolves the invite by token hash and calls
  `TenantMembershipService::decline()` — no `Auth` check, no predicate. Whoever reads the mailbox (or a
  forwarded link) can set the membership to `Declined`, and the invited person then sees nothing at all.
  **Denial rather than takeover**, and re-sending fixes it, which is why M8 left it: the row M8 closed was
  about a credential being overwritten and a session being minted, and neither happens here. Filed because
  it is the same door and a later reader will ask. **Fix if taken:** ask the same predicate, and require an
  established identity to be signed in to decline — the accept arm's hand-off already exists to route them.
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
  *Test suite & CI gates*.~~**

- **`minor` · A self-registered account that was never verified is indistinguishable from an invite
  placeholder, so a token holder can still overwrite its password.** The residual M8 deliberately left,
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
  suite that cannot run on this host. Recorded as residual 30 in `docs/security-threat-model.md`.
- **`minor` · M8's GRANT removed an accidental backstop that a mutation argument was leaning on.**
  `meridian_auth` used to hold `SELECT, UPDATE` on `users` **and nothing else**, and both
  `MemberSearchArm`'s docblock and RBAC §9 cited that as the reason swapping the arm to `pgsql_auth`
  *"fails LOUDLY (11 cases red) instead of silently returning every tenant's members"*. Since
  `2026_08_17_000107` that swap would **succeed quietly**. Both prose sites are corrected rather than
  deleted, and nothing is broken today — `SearchMemberConnectionTest`'s three STRUCTURAL pins never relied
  on the database refusing anything. Filed so that **any future proposal to weaken one of those pins is
  read against this**, not against the older belief that a wrong connection cannot execute the query.
  Recorded as residual 31 in `docs/security-threat-model.md`.
- **`minor` · `users.last_active_tenant_id` has no writer anywhere in `app/`.** Found while surveying
  candidate signals for M8's identity predicate: the column reads exactly like *"this identity has been
  used"* and would have been a fifth arm, but its only three references in the whole application are
  description strings in `TenantExtractColumns`. Nothing sets it, so nothing can read it meaningfully. The
  migration calls it *"UX convenience only (default tenant on next login); NOT authoritative for any
  authorization decision"* — which is a description of a feature that was never wired. **Either wire it
  (one write at session start, and the default-workspace convenience it promises becomes real) or drop the
  column**; leaving it is how a future increment reaches for it as a signal and gets NULL for everybody.
- **`minor` · `EnforceTenantTwoFactor` is absent from the `/api/v1` token-mint group.**
  `routes/api.php:73-89` — an unenrolled member under `security.require_two_factor`, bounced from every
  page, can still `POST /api/v1/auth/tokens` from the same session and use the bearer against Group B,
  which carries no 2FA gate either. **Live.** ⛔ **DOWNGRADED FROM `blocker` TO `minor` ON VERIFICATION,
  AND THE REASON IS THE ROW**: all six links hold, but the middleware is an **enrolment nudge by its own
  docblock** — *"re-challenging per request would be theatre on the doors that already challenge"*,
  and its one escape hatch is a route deliberately left outside its own group — Fortify's own 2FA-enrolment routes
  sit outside the same gate behind `password.confirm` — so the attacker already had a better path — and
  the token's abilities are capped at the issuer's own RBAC. It is a defence-in-depth and consistency gap.
  The code edit and the test edit are the same edit: mount it on Group A, and add a
  `StepUpReauthenticationTest:115`-shaped route manifest so it cannot silently come off again. Group B
  needs no gate — `routes/api.php:80-88`'s "gate the mint, not the bearer" argument applies verbatim.
- **`minor` · Three admin POSTs bind `{tenant}` with no `whereUuid`.** `routes/admin.php:56-63` —
  `suspend`, `reactivate` and `assign-plan`, while the two routes added around them pin the pattern and the
  docblock justifying the omission is now stale. A malformed uuid 500s instead of 404ing. **Live.**

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
  `PROGRESS.md:1436` records a **second** flake in this same file — *"Builder — empty canvas (dark)"* — of
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
  take it: it is Lane A's column and the retryability question is a gate-policy decision, not a colour fix.

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
  `scrollable-region-focusable` never fires and happy-dom computes no layout.

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
  breached is the DSR's own stricter rule, and `docs/ux/exceptions-log.md` carries no entry for it.

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
  `Checklist/Checklist.vue:289-295`, while both docblocks assert the glyph is the signifier. **Live.**

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
  create-destination button at all; its two `busy`-aware `:disabled` bindings are on `MdsSelect`.
- ✅ **CLOSED BY `M23` (2026-08-26) — `minor` · ~~The unearned-badge medallion disappears in dark mode.~~**
  Now `--mds-color-status-neutral-bg`: unchanged in light (`#EEF3FE` on `#FFFFFF`), and `#2c374c` on
  `#1a2130` in dark, which is **1.35:1** against the exactly **1.00:1** it was. ⚠️ **THE ROW UNDERSTATED
  IT IN ONE DIRECTION AND OVERSTATED IT IN ANOTHER.** Understated: in dark the primitive *is*
  `--mds-color-bg-surface` (`theme-overrides.css:113` re-points the surface at `neutral-100`), so the disc
  was painted its own card's colour — not merely low-contrast but mathematically absent. Overstated: it
  called this the only primitive reference under `resources/js/Pages/`; measured, it was the only one in
  the whole of `resources/`, which is what made the gate below cost nothing.
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
  load. The false sentence was the stated justification for the implementation and is gone.
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
  the unfiltered `eventTypes` prop, so rendered already equals sendable there.

- **`minor` · The delivery-rule modal's channel-refresh button is the same unguarded shape, GET-only.**
  `resources/js/components/integrations/RuleFormModal.vue:350-359` — a `:loading`-bound `MdsButton` whose
  `@click` reaches a raw `fetch`, with the component's own `channelsLoaded && !force` re-entry check
  bypassed by `force = true`. `MdsButton`'s repaired guard now stops the duplicate click, so this is
  **not live**; it is filed because the row above it was closed on the argument that the *side effect* is
  what makes a button dangerous, and the next fetch-backed button written in that file should not be
  written this way. Fix is the same one-line `if (channelsLoading.value) return;`.
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
  it is its own increment, not a rename.
- **`minor` · A semantic token is no guarantee of a visible element, and one more instance is probably out
  there.** M23 added a gate banning *primitive* ramp references in application code, then immediately found
  the identical defect wearing a *semantic* token: `LogicRail.vue`'s `.rail__dot` was
  `--mds-color-bg-sunken` on a `--mds-color-bg-canvas` ground, and in dark **both resolve to
  `--mds-neutral-50`** — 1.000:1, fixed in the same increment. The general check is "does every painted
  element differ from the ground it actually lands on, in both themes", which needs the resolved ancestor
  chain and is not a source-text scan. **Not live** as far as two hand-audits reach; filed because the gate
  that shipped covers the cheap half only and must not be read as closing the class.

### Test suite & CI gates

- **`minor` · `baselineOf()` turns "no checksum" into `''`, and only middleware turns it back.**
  `tests/Feature/Submissions/SubmissionEditRoutesTest.php:62` returns `(string) $value`, so a null
  `answers_content_checksum` reaches the request body as an **empty string**, and it is
  `ConvertEmptyStringsToNull` — not the helper, and not anything in the test — that restores the null the
  service actually compares. **The round trip is correct by coincidence of two unrelated behaviours.**
  ⚠️ **Not a bug today and unlikely to become one**, because M31 added cases that drive the real-checksum
  path directly and would redden if the coercion started mattering. Filed because the `''` is a **cast
  artefact rather than a value anybody chose**, and a reader who assumes it is meaningful will mis-model the
  guard. **Deliberately left by M31** — changing the return type touches every case in the file, which is a
  larger diff than the finding justifies. **Latent.**
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
  `filteredToZero` loop twenty lines below (`:154-163`) and `support/console.ts:34`. One line per test.*
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
  `throttle:` alias naming an unregistered limiter "resolves to an UNLIMITED PASSTHROUGH".*
- **`major` · `POST /user/confirm-password` carries no rate limit at all.**
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
  limiter to a route that increment does not otherwise touch.
- **`minor` · `throttle:saml-acs`'s route BINDING is asserted by nothing, while its registration is.**
  `SsoLoginWebTest.php:285-291` loops six limiter names and asserts each resolves — which stays green when
  the binding at `routes/tenant.php:1172` is deleted, because the registration at `AppServiceProvider.php:421`
  is untouched. The only test inspecting that route's middleware (`SsoAcsWebTest.php:753-758`) asserts only
  **absences**. Its four siblings (`saml-login`, `saml-metadata`, `saml-login-complete`,
  `saml-step-up-complete`) all carry a positive `toContain('throttle:…')` assertion, so the gap is an
  asymmetry **inside the very test family the closed row above held up as the model**. **Latent.**
  `routesThrottledBy()` in `tests/Feature/Auth/RateLimiterBindingTest.php` is the reusable helper.
  **Deliberately left by M30** — `tests/Feature/Sso/` is Lane B's most active subsystem and that increment
  was already crossing the boundary.
- **`minor` · Two SSO test files justify a real assertion with a rationale that is false on this framework version.**
  `SsoLoginWebTest.php:286-287` and `SsoLoginCompletionWebTest.php:466-469` both say a `throttle:` alias
  naming an unregistered limiter *"resolves to an UNLIMITED PASSTHROUGH"*. **MEASURED (M30):** on
  `laravel/framework` v13.18.1 `ThrottleRequests::resolveMaxAttempts()` throws `MissingRateLimiterException`
  instead — true on Laravel ≤ 9, false here. **The tests are still worth having; the stated reason is not
  the true one**, and this project has recorded three times that a false claim about a control is worse than
  a missing one because it stops the next reader looking. **Not live** — a comment. **Deliberately left by
  M30** for the same lane-boundary reason as the row above, and filed so the correction is not lost.
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
  `tests/Feature/Submissions` **415 / 1652 → 419 / 1685**.
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
  ten had one**. The unfixed remainder is filed below and under *Submissions, drafts & the guest runtime*.
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
  allowlisted reason is TRUE". Two corrections to the row: it walks **Group B only** (`:114-115` filter out
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
  this row.
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
  `:26-27`. `FeedbackController.php:75` and `AttachmentController.php:43` were both exact.
- ~~**`minor` · `AttachmentController`'s docblock calls `GET /attachments/{attachment}` a "signed read-back", and nothing about it is signed.**~~
  ✅ **DONE — M34 (2026-08-27).** Citation exact: `app/Http/Controllers/Tenant/AttachmentController.php:20`.
  The word is struck, and the docblock now names the controls that **do** exist rather than leaving a hole
  where the false one was — because "strike the word" alone hands the next reader the same question with no
  answer, and a docblock is what they check *instead of* the middleware. The route is session auth, plus
  `can:view,attachment`, plus — since M33 — a policy that resolves the attachment's **kind and owner** and
  not merely a permission. Nothing anywhere signs it: the repository still contains exactly one signed URL
  (`User.php:146`, email verification) and no `temporaryUrl`, `ValidateSignature` or `hasValidSignature` in
  `app/` or `routes/`, re-verified this increment rather than carried over from the row.
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
  made the case slower (82 s). The pattern should not be multiplied casually.
- **`minor` · `GET /admin/users` — the cross-tenant user list — has exactly one test, and it is a 200.**
  Found by M35's census of what the console's fourteen routes are actually driven by.
  `SuperAdminConsoleTest.php:100` requests it as an enrolled super-admin and asserts `assertOk()`; **no
  request to that URI is ever refused, by any caller, in any suite** — no guest, no non-super-admin, no
  un-enrolled operator, no stale confirmation. It reads every user in the deployment through the
  `superadmin_bypass` RLS carve-out, which is a wider read than the feedback screenshot M35 closed.
  ⚠️ **STATED WEAKNESS, in the M20 discipline and for the same reason the row above carried one:** since M35
  the four gates on this route are pinned STRUCTURALLY by `AdminConsoleGateTest`, and six sibling pages carry
  behavioural denials against the same group — so this is a missing behavioural arm on a route whose
  middleware is now enumerated, not an open door. Filed `minor` for that reason and not lower, because a
  structural gate cannot see a middleware that stops refusing.
  **Left unfixed by M35 deliberately**: it is `tests/Feature/Admin/SuperAdminConsoleTest.php`, which that
  increment's diff does not touch, and the same is true of the three other console routes that have positive
  requests and no denials of their own — `admin.tenants.reactivate`, `admin.tenants.assign-plan` and
  `admin.feedback.update`. One increment, one file of behavioural arms, with M35's fixture already in place.

- **`minor` · The `can:` arm on `GET /api/v1/analytics/report` — the non-export twin — is asserted by nothing.**
  The mirror image of the row M34 closed, and found while closing it. `AnalyticsApiTest.php:87` pins the twin's
  **ability** arm and `:99` pins a Viewer's intended 200, but nothing anywhere drives that route with a caller
  who carries `read:analytics` and fails `can:viewAny,SavedReportView`. Delete the `can:` middleware from
  `routes/api.php:366` and the suite stays green. **Latent, and cheap**: M34 added exactly this test to the
  export twin (`AnalyticsExportTest.php`), so the fixture — an active member with `syncRoles([])` holding a
  correctly-scoped token — can be copied one file over. ⚠️ **The reason it is not folded in here is the
  M20 rule read forwards**: it is a different route, and this row's whole thesis is that a test aimed at the
  twin is not coverage of its sibling. Fixing the sibling by aiming at the twin would repeat the defect.

- **`minor` · Three saved-view verbs are gated by an entitlement assertion that no permission test backs.**
  `AnalyticsPageGateTest.php:106` drives `POST /analytics/views`, `PATCH /analytics/views/{view}` and
  `DELETE /analytics/views/{view}` as an **Owner** on a Professional plan and asserts a redirect from each —
  the `feature:` refusal, exactly the shape that filed the export row. M34 added a comment there saying so in
  as many words, and pinned the export's gate, but left these three: they are writes rather than streamed
  bytes, so they fall outside a stored-bytes census and belong with whoever takes the analytics write surface.
  **Latent.**

- **`minor` · A `can:` gate that names the WRONG SUBJECT is invisible to every test in the repository, including the one written to catch it.**
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
  than being bolted onto the one route that happened to be under repair. **Latent.**

- **`minor` · `routes/api.php:114-116` describes a middleware ordering the priority sorter does not produce.**
  Re-read at source rather than taken from the report: the comment states that `feature:api_access` runs *"before throttle so a no-feature tenant is refused before
  consuming a burst slot"*. **Measured with `route:list`, which prints the SORTED list: `ThrottleRequests:api`
  is hoisted to FIRST**, ahead of tenancy, auth and the feature gate — so a no-feature tenant **does** consume
  a burst slot, and the stated protection does not exist. Same species as the *"signed read-back"* docblock
  M34 struck: **a comment describing a control that is not there is worse than no comment, because it is what
  the next reader checks instead of the middleware.** Harmless today (the slot is a rate-limit bucket, not
  data), so **documentation defect, not a behaviour one** — but the fix is a decision rather than an edit:
  either strike the claim, or hoist `api_access` into the priority list so the comment becomes true.

- **`minor` · Two byte-serving routes gate on a subject their own comments question.**
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
  `afterAuditId` carry real uncovered risk.
- **`major` · Four maintenance fan-outs are asserted by a fixture too small to see a wrong tenant id.**
  `SweepWebhookRetriesJob.php:26` · `SweepScheduledFormsJob.php:30` · `RollUpUsageCountersJob.php:27` ·
  `ReapExpiredDraftsJob.php:25`. **Filed by M32 (2026-08-28), which fixed the other two of the six and
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
  the tell that no test names it.

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
  postures — worth settling deliberately rather than by drift.

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
  that found it — the same reasoning M7 used for the `§D<n>` citation gate directly below.*
- ~~**`minor` · Neither structural lint gate fails on an empty scan.**~~
  ✅ **DONE — M36 (2026-08-28), AND THE ROW UNDERSTATED ITSELF: FOUR GATES, NOT TWO.** The row names
  `constraint-boundary-lint.php` and `migration-lint.php`. `scripts/controller-gate.php:101-102` and
  `scripts/job-payload-lint.php:246-247` carry the identical shape and it names neither;
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
  the mechanism, while all five still pass on the host at 97 · 113 · 31 · 113/121/0 · 180.

- **`minor` · Every hand-off prescribes a Pint command that scans ~40 fewer files than CI does.**
  Found by M36 while adding files to `scripts/`. Both lanes' hand-offs say
  `vendor/bin/pint --test app tests database` — **1375 files**. CI runs `composer run lint`, which is a
  **bare** `pint --test` with no paths and no `pint.json` in the repository — **1414 files**. The local
  command misses `scripts/`, `config/`, `routes/` and `bootstrap/` entirely, so a style violation in any
  of them passes locally and reddens CI. **Measured, not inferred**: M36's four floor edits are all in
  `scripts/`, all four were flagged by bare Pint, and none of them would have been seen by the
  prescribed command. **Live** — the fix is one word in two hand-off lines, but it is filed here because
  the hand-offs are rewritten every increment and a fix that is not written down does not survive one.

- **`minor` · `fb-lane-c` is an abandoned worktree that every numbering check must now read past.**
  `git worktree list` reports three worktrees; `c:\laragon\www\fb-lane-c` sits on `lane-c-bootstrap`, an
  M14-era merge commit **104 commits behind `origin/main`**, with one dirty file and no
  `docs/claims/lane-c.md` anywhere. Standing Rule 7 describes exactly two lanes, so a third entry is
  noise in the one command the protocol tells every session to run before numbering — and that command
  has decided the increment number three times running. `git worktree remove` is the whole fix.
  **Live**, and deliberately not taken by M36, which had no reason to touch another lane's checkout.

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
  **(2) THREE README sites, not one.** The row cites `README.md:51-59`; the command is at `:63` (`:51` is
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
  but `README.md:51-59` presents `npm run build` as a first-class command with no prerequisite. Live, and
  it is the first thing a new contributor runs. PROVE IT IN A THROWAWAY `git worktree`, NOT BY MOVING
  `dist/` ASIDE — the local tree has a populated `dist/` from earlier increments, so any test that starts
  from it measures the wrong thing. FILED BY `M23` (2026-08-26) WITHOUT BEING BUILT, AND THE FILING IS THE
  POINT. This row had been carried in Lane A's hand-off prompt alone for two increments and appeared in no
  document a backlog search would reach — the same shape as J4b1's four defects, which were recorded in
  the tracker and nowhere else. Its evidence was re-verified before this bullet was written: both
  citations hold.*
- **`major` · ADR-0001 claims `citext` and `pgcrypto` are enabled by default, covering case-insensitive
  uniqueness for share slugs and user email.** `docs/adr/0001-postgresql-over-mysql.md:56` (restated `:83`,
  `:127`). Only PostGIS is enabled, and `0001_01_01_000000_create_users_table.php:26` is a plain
  case-sensitive unique with no lowercasing anywhere on the register/login path — so an engineer writing
  auth, invite-dedupe or account-merge builds on a guarantee the database does not give. **Live.** This
  branch corrected the adjacent `pg_trgm` bullet at `:128` and left `:56` asserting the opposite.
- **`major` · Two of the ten rows in ADR-0002 §D3's isolation-control inventory describe unbuilt
  mechanisms.** `docs/adr/0002-multi-tenancy-shared-db-rls.md:129` credits Reverb channel-authorization
  callbacks that *"re-verify the requesting user's tenant membership"* — there is no broadcasting config,
  no `routes/channels.php` and no dependency — and `:132` claims `tenant:{id}:…` Redis cache prefixing,
  where `CACHE_STORE=database`, no KPI caching exists and the only `tenant:{…}` key in the tree is a queue
  rate limiter. **Live.** Sharpened because the adjacent Jobs row *was* rewritten to as-built, training a
  reader to treat uncorrected rows as verified.
- **`major` · The audit spec's exhaustive `users` scope row omits the impersonation boundary events.**
  `docs/audit-compliance-logging-spec.md` §1 (~`:28`) names `updated` and `permission_changed` only, while
  `app/Services/Admin/ImpersonationService.php:389-406` records `impersonation_started` /
  `impersonation_ended` against that same alias. A SIEM forwarder or retention rule built from the section
  that exists to be exhaustive drops the highest-privilege events in the ledger. **Live.**
- **`major` · The threat model's `Open` row asserts `APP_PREVIOUS_KEYS` "appears in no `.env.example` and
  in no document".** `docs/security-threat-model.md:100` (repeated `:218` — **was `:217` until M9's own §8 row shifted it, which is exactly the hazard the citation-cluster row below records**; duplicated into
  `docs/adr/0009:31,:83,:168,:290`). It is present on this branch at `.env.example:207-209` with an
  ADR-0009 §D9 warning attached, and discussed in two more documents — so the register is wrong at the
  moment it is ratified, and an operator planning an `APP_KEY` rotation is told not to look for the seam
  that exists. **Live.** ⚠️ **Narrow it, do not close it**: the documented rotation *procedure* genuinely
  is still absent.
- **`major` · ACCESS-MATRIX's verification step 4 sends the reader to the platform host, which the same
  document proves is a dead end.** `docs/ACCESS-MATRIX.md:446` says sign in at
  `http://localhost:8080/login` as `viewer@demo.test` and inspect the sidebar; `:70-92` records the
  measured finding that Fortify lands on `/dashboard` on the central host, `PreventAccessFromCentralDomains`
  302s it to `/`, and walking to the subdomain afterwards does not rescue it. Step 5 two lines below
  correctly uses a workspace host, so the inconsistency reads as intentional. **Live.**
- **`major` · The README's frontend and design-system command blocks are host commands that cannot run on
  the host.** `README.md:51-59` — `npm run build`, `type-check`, `ds:tokens`, `ds:storybook:build`, `ds:test`.
  Only `npm run dev` carries the "(or use the `node` compose service)" parenthetical, and `:19` calls host
  Node optional, so the rest read as host commands; `docs/TESTING-GUIDE.md:22-23` states the opposite (no
  `pdo_pgsql`, no rolldown win32 binding). **Live**, on the platform the README explicitly documents.
- **`major` · ADR-0017 says the threat model carries no SSO and no isolation-topology rows; it carries
  both.** `docs/adr/0017-tenant-isolation-tiering.md:73`, refuted by
  `docs/security-threat-model.md:171-179` (the SAML table) and `:49-52` (four isolation/extraction rows
  that cite this very ADR). A reviewer using the ADRs as the map of what has been threat-modelled blocks
  the merge on, or duplicates, work that already shipped. **Live** — the file was edited after P2b, so this
  bullet was left behind rather than never revisited.
- **`major` · A second raw-HTML sink shipped in this branch, and the escaping contract says there is
  none.** `docs/piping-output-encoding-design.md:151` asserts *"zero `{!!` exists in application code
  today"*, status "(holds)", and `:180` makes any second sink a contract change. The new
  `resources/views/vendor/mail/html/header.blade.php:31,33` carries two — `:31` interpolating blind into an
  HTML **attribute** (`alt="{!! trim($slot) !!}"`). Its premise that the slot arrived escaped does not hold:
  `Markdown::withSecuredEncoding()` replaces the echo encoder with a three-character map that does not
  include `"`, and the value is `$tenant->name`. **Latent** — no user-facing write route for `tenants.name`
  was found — but the asserted invariant is false either way, and `BrandedMailRenderTest.php:122` only
  pins the unquoted case.
- **`major` · The data dictionary states "No CHECK pairs the two" for `audits.user_id` /
  `acting_as_user_id`.** `docs/data-dictionary.md:630`, refuted by
  `2026_08_09_000001_add_acting_as_user_id_to_audits.php:98-101`
  (`audits_acting_as_not_self_check`). The doc recorded the migration's reasoning for the **rejected**
  constraint (`:34`) rather than the one that shipped (`:40`), so a backfill or fixture setting
  `acting_as_user_id = user_id` gets a 23514 from a constraint the canonical schema reference denies.
  **Live**, and the section enumerates CHECKs exhaustively elsewhere, so the negative reads as complete.
- **`major` · The corpus names a real third-party client and publishes an audit of its weaknesses.**
  `docs/PRD.md:35`, `:39`; `docs/architecture/technical-architecture.md:376`; two hits each in
  `docs/adr/0001` and `0002`; three in `PROGRESS_ARCHIVE.md` — naming `dev_pk_new` / "Purok Kalusugan",
  built for the Philippine Department of Health, and describing its missing form versioning and its
  `users.id === 1` god-mode. **Live** on a public repo. ⚠️ **Pre-existing on `main` and not introduced by
  this diff — this merge does not change its exposure, which is why it is not a blocker.** Filed because
  the merge is the natural last moment to make redaction a conscious decision rather than a default.
- **`minor` · `/gamification/me` documents only `200`.** `openapi.json` — the route carries
  `module:gamification` (`routes/api.php:440`), whose `ModuleDisabledException` answers **403** on a
  supported user action (an owner switching the module off), and nothing inferred it because the endpoint
  deliberately has no `can:` gate. Its sibling `/gamification/leaderboard` documents both. **Live.**
- **`minor` · §20's `settings.key` catalog omits `security.require_two_factor`.**
  `docs/data-dictionary.md:838`, rewritten in this branch — the key is live
  (`app/Enums/SettingKey.php:42`, tenant-scoped at `:85`, written by `UpdateAccessSettingsRequest.php:60`,
  enforced by `EnforceTenantTwoFactor`'s `settings->get(SettingKey::SecurityRequireTwoFactor)` read). Anyone inventorying tenant configuration from the
  dictionary omits a tenant-scoped security policy. **Live.**
- ✅ **CLOSED BY `M7` (2026-08-20) — `minor` · ~~ADR-0019 is the sole `Proposed` ADR in the directory, for
  a decision that is ratified and fully built.~~** Now **Accepted**, with the correction stated in the
  Status block rather than silently applied: every decision in it shipped in J3c2,
  `routes/google-auth.php:64,68` have been merged since, and `0018:49` and `0016:133` were already citing
  it as settled precedent. Folded into the row above because it is one word in a file that diff already
  rewrote at `:23` and `:28`, and because it is the same defect one level up — **ADR-0019 did not
  accurately record what had been decided.**
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
  row that found it. **Not live** — this is a missing gate, not a defect.
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
  `docs/piping-output-encoding-design.md:180`; `docs/offline-first-sync-design.md:128`;
  `docs/data-dictionary.md:62` (§28, not §31 — the pointer currently lands on a note arguing the opposite
  case); `docs/api-specification.md:179` (`read:audit_log` is orphaned four blockquotes below its table
  and renders outside it); `docs/ux/design-system-reference.md:812,:843`;
  `docs/pricing-feature-gating-matrix.md:56` (Business is seeded `unlimited`, not 25 endpoints);
  `docs/PRD.md:13` (the ADR index stops at 0014; 0015–0020 exist); `docs/TESTING-GUIDE.md:57,:639` (three
  forms and five forms, against four and six as seeded); `README.md:85-86` (contract and e2e are real
  merge-blocking gates, not stubs; there is no `deploy` stage in `ci.yml` at all); and this file's own
  `:105` and `:459`. **Live**, all documentary.
