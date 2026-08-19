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

- **`major` · THE END-TO-END HORIZONTAL-OVERFLOW ASSERTION IS STRUCTURALLY INERT ON EVERY `AppLayout`
  PAGE — IT CANNOT FAIL, AND HAS NOT BEEN ABLE TO SINCE THE CLIP LANDED.** `tests/e2e/support/axe.ts:41-44`
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
  where it is still true (`FormOpened`'s `SerializesModels` note and `NotifyOnSubmissionCreated`). (2) Both
  dispatcher docblocks said **"four thin auto-discovered listeners"** where there are **eight** since I3 and
  I9c — the same drift, in the file that defines the behaviour. (3) `TenantContext::restoreMirror()`'s
  docblock named `ScopeTenantContextToJob` as its **sole** caller, which stopped being true here.
  ⛔ **THE LISTENERS ARE DELIBERATELY NOT FLIPPED TO `ShouldQueue`** — that is a behaviour change owed its
  own increment, and it is filed as its own row below rather than left invisible.
  **Gates:** four lint gates unchanged at **97 / 108 / 30 / 119** (M3 adds no controller, migration or job),
  Pint `passed`, `openapi.json` byte-identical, zero `.vue` / `.ts` / `packages/design-system/` / e2e movement.

- **`minor` · The seven synchronous dispatch listeners could now be `ShouldQueue`, and nothing has decided
  whether they should be.** Filed by **M3 (2026-08-19)** at the moment the decision was taken, because a
  deliberately-unfixed finding that lives only in a commit message is invisible to any later backlog search.
  Until M3 the answer was forced: a queued listener found no tenant context and the fan-out silently matched
  nothing. `WebhookEventDispatcher` and `ConnectorEventDispatcher` now establish the event's own context, so
  queueing them is **safe** — the question is whether it is *wanted*. Arguments both ways, neither yet
  weighed: fan-out is two queries and an enqueue, so a synchronous listener costs a submission request very
  little and keeps delivery-row creation inside the request that caused it; against that, `form.opened` and
  `form.closed` fire inside the H12a sweep's per-tenant transaction, where a slow fan-out holds row locks
  taken by `lockForUpdate()`. **Nothing is broken either way** — this is a latency/locking trade, not a
  correctness one, which is why M3 declined to make it while fixing a correctness bug.

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
- **`major` · A successful Airtable delivery writes the respondent's answers into the delivery ledger.**
  `app/Support/Connectors/Providers/AirtableConnector.php:353` passes 2000 characters of the create-record
  response — which echoes the `fields` object just written — as the success excerpt, landing in
  `webhook_deliveries.response_body_excerpt` (`app/Jobs/Connectors/DeliverConnectorMessageJob.php:176`).
  The sibling adapter deliberately sends `'ok'` (`GoogleSheetsConnector.php:270-272`) and says why. There
  is **no `webhook_deliveries` retention job**, and deleting the submission does not touch the row, so an
  erasure request leaves answers behind — falsifying `docs/data-privacy-gdpr-compliance.md:83`
  (*"the delivery ledger is not a second copy"*). **Live**, Owner/Admin-visible only, not cross-tenant.
  One line: `delivered($response->status(), 'ok')`.
- **`major` · Both tabular adapters do a non-idempotent write and the retry ladder re-drives it.**
  `GoogleSheetsConnector.php:252` / `AirtableConnector.php:338` send no idempotency token; a response lost
  *after* the provider committed becomes `ConnectorDeliveryResult::failed()` (`:327-331`), and
  `SweepWebhookRetriesJob` re-appends the same submission. With `max_attempts` = 10
  (`config/webhooks.php:39`) the ceiling is ten identical rows in the tenant's own sheet, nothing marking
  either as a retry. **Live.** Not a blocker: `insertDataOption=INSERT_ROWS` means it is additive, so the
  shape of their data is corrupted, not its content.
- **`major` · An irreversible provider-side token rotation is committed inside a rollback-able
  transaction.** `app/Services/Connectors/ConnectionTokenRefresher.php:127` writes each refreshed grant
  inside the transaction `app/Jobs/TenantAwareJob.php:132-136` opens, and `sweep()` (`:53-72`) batches
  every due connection for a tenant into it with no partial-commit seam. Airtable invalidates the previous
  refresh token on every renewal (`AirtableConnector.php:54-61`), so a 60s job timeout or any later throw
  rolls back the writes while the tokens stay rotated — the next sweep gets `invalid_grant`, which is
  terminal, and `markDead()` clears both tokens, pauses every rule and emails the owner. **Live** and
  Airtable-specific (Google returns no new refresh token; Slack never refreshes). ADR-0009 §D6 named the
  hazard in two halves; the H16a amendment answered only the stampede half.
- **`major` · `ensureFresh()` takes no lock, so two concurrent deliveries destroy a healthy Airtable
  grant.** `app/Services/Connectors/ConnectionTokenRefresher.php:86` is a plain read-check-then-refresh —
  no `Cache::lock`, no `FOR UPDATE`, no `WithoutOverlapping` — so two workers exchange the same rotating
  refresh token, the loser gets `invalid_grant`, and `markFailed()` runs. **Latent, and the precondition is
  undeclared**: `docker-compose.yml:39` and `docs/deployment-infrastructure.md:120` run exactly **one**
  `queue:work`, and that same line names adding worker processes as the scaling path. The scheduled sweep
  *is* protected by `WithoutOverlapping`; the lazy guard H16a added beside it inherited none of it.
- **`minor` · The setup-time directory has no pre-flight refresh**, so an ordinary token expiry tells the
  tenant to reconnect a healthy account — `app/Services/Connectors/TabularDestinationDirectory.php:46,68`,
  the one place H16a's guard was not applied. **Latent** on a missed sweep (H16a's own premise).
- **`minor` · `ConnectorRulePausedNotification` is the only tenant-facing connector email with no brand.**
  `app/Jobs/Connectors/DeliverConnectorMessageJob.php:330` sends it without `->withBrand(...)`, so a
  branded tenant gets one branded and one product-default email from the same job. **Live**, one argument.

### Submissions, drafts & the guest runtime

- **`major` · `promote()` reads the answer document before it takes the lock, and a concurrent autosave is
  terminally lost.** `app/Services/Submissions/SubmissionDraftService.php:163` reads outside any
  transaction, Stage-3 semantic validation and the DB attachment check run for tens of milliseconds
  (`:167-175`), the lock is taken only at `:182-183`, and `:200` finalizes with the *pre-lock* values —
  `SubmissionFinalizer.php:90` being a whole-document replace. A two-device resume (the flow the resume
  link invites) drops the second device's field, and the row is then `submitted`, so no later save can
  restore it. **Live.** The only in-lock guard is a status re-assert, which a concurrent autosave does not
  move — the sibling `SubmissionAnswerEditService.php:186-203` already carries the two-check shape this
  needs, verbatim. P3a closed the cross-request case and did not touch this path.
- **`major` · Two unscoped copies of `findByClientUuid()` survive the branch that declared the unscoped
  form an authorization defect.** `app/Services/Submissions/SubmissionDraftService.php:414-427` documents
  the invariant and `:429` implements it (uuid + form + respondent), while
  `app/Http/Controllers/Public/GuestSubmissionController.php:112-122` filters on uuid + status only and
  `app/Services/Submissions/SubmissionPipeline.php:216-219` on uuid alone. A guest holding form A's share
  token and supplying a uuid from form B in the same tenant gets either a **repeatable unauthenticated
  500** (the tenant-wide partial unique index raises 23505 that the recovery arm cannot classify) or, for a
  finalized row, **form B's id, reference and status serialized back to them** with their own answers
  silently discarded as an idempotent 200. RLS bounds it to the tenant and no further. **Live.**
- **`major` · The guest runtime folds P3a's `409 draft_conflict` into the generic `refresh` kind, so the
  refusal becomes a second submission.** `resources/public-runtime/lib/error-normalizer.ts:93` returns
  `'refresh'` for every 409; `components/RuntimeSession.vue:275-282` discards the outbox row and calls
  `handleDrift()`, which re-fetches the **schema** rather than the draft, and `App.vue:284-296` remounts
  with a fresh uuid and `draftBaseline = null`. The respondent is told *"This form was updated"* — false,
  nothing was republished — and the resubmit it invites travels with **no baseline at all**. **Live.**
  ⛔ **DOWNGRADED FROM `blocker` TO `major` ON VERIFICATION, AND THE REASON MATTERS**: the P3a guard is
  **not** undone — the service throws before the write — and "fresh uuid after a 409 → a second row" is the
  pre-existing, documented G8c recovery shape rather than a new failure. The residual harm is a factually
  false cause shown to the respondent and a remedy (*reload the draft*) that the server names and the
  client discards. The authenticated twin already branches correctly
  (`resources/js/composables/useServerAutosave.ts:198-212`). Smallest fix: give `draft_conflict` its own
  `ErrorKind`, as I8b did for `challenge`, and route it to a draft re-read that keeps the uuid.
- **`major` · On the draft-save channel the same 409 is swallowed with no message at all.**
  `resources/public-runtime/components/RuntimeSession.vue:352-358` returns `null` into
  `components/SaveForLater.vue:38-43`, which just closes the panel — so a deliberate "Save and finish
  later" produces **no save, no resume link, no error**, and the remount then mints a *second* server draft
  with a *second* resume link emailed to the same respondent. `GuestDraftRequest.php:113-115` records that
  this channel checks the baseline **unconditionally**, so the 409 is reachable on every save. **Live.**
- **`major` · The device-wide outbox is mounted above the phase machine on an unauthenticated page.**
  `resources/public-runtime/App.vue:382-386` · `components/SyncStatus.vue:104-113` ·
  `components/SubmissionOutbox.vue:96-186`. On the shared-kiosk hardware `lib/outbox.ts:9-18` names as the
  threat, the next respondent sees the previous one's failed row — queue tag, server reference, creation
  time — and, because `lib/outbox-status.ts:123-130` sets `canDiscard` on `needs_attention`, a **Discard**
  button that permanently deletes their unsent response and media. The list is cross-form by design
  (`outbox.ts:180-186`), so `SubmissionOutbox.vue:158-166` also discloses which other forms were answered
  on that device. **Live.** The conflict-Review path into another respondent's answers is pre-existing; the
  always-visible list and the per-row Discard are new here.
- **`minor` · `useServerAutosave.dispose()` fires without consulting `inFlight`.**
  `resources/js/composables/useServerAutosave.ts:425-431` sends a `keepalive` POST carrying a **stale**
  `base_content_checksum` on an Inertia navigation during a save, so the server refuses it as
  `draft_conflict` and the edits made during that request are silently dropped. **Live.**
- **`minor` · The encode page's conflict refusal remounts and discards the editor's corrections.**
  `resources/js/Pages/submissions/Encode.vue:709` returns the optimistic-concurrency refusal as a flash
  toast rather than a validation error, so `preserveState` evaluates false and the page reloads from the
  stored document. The docblock reasons only about the 422 path and gets the conflict path — the one the
  machinery exists for — backwards. **Live.**

### Gamification

- **`major` · The backfill awards review points for two verbs the live engine never scores.**
  `app/Services/Gamification/AuditReplayMap.php:160` maps every `('submission','updated')` audit row
  carrying a `remarks` key to `PointRule::SubmissionReviewed`, but
  `app/Services/Submissions/SubmissionReviewService.php:156-162` writes that row from **four** verbs and
  `snapshot()` (`:189-200`) emits `remarks` unconditionally — so `markUnderReview` and `archive` score too.
  One retention sweep of 400 archived rows hands the actor 1,200 points and the `reviewer` badge for
  archiving. `point_awards` is append-only with **no DELETE policy** (ADR-0020 §D4), so the inflation is
  permanent and feeds `TeamProgress` and the leaderboard for the life of the workspace. **Latent** until
  `gamification:backfill` is run — which is a one-shot operator action nobody repeats. Not a double-award:
  the unique index refuses the later real approval.
- **`major` · `standing.of` discloses the workspace headcount with no permission at all.**
  `app/Services/Gamification/MemberStanding.php:33`, emitted unconditionally at
  `app/Http/Controllers/Tenant/AchievementsController.php:103` and
  `app/Http/Resources/Api/V1/MemberProgressResource.php:41` (route `routes/api.php:440-442`, no `can:`
  gate). The identical integer is `null`ed out of `/dashboard` for readers without `dashboard.org.view`
  (`DashboardMetricsService.php:55,60`) and correctly withheld two fields away in the same payload
  (`AchievementsController.php:115-120`). A Form Editor reads it on the page or via a mintable
  `read:gamification` token. **Live.** ⚠️ **The fix may be a ratification rather than a patch** — ADR-0020
  §D7 approves *"4th of 12"* for every member — but no document reconciles that with the dashboard's
  deliberate withholding, and this increment's own controller argues the opposite principle three fields
  earlier. One of the two has to move, explicitly.

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

- **`major` · The "Log out" control on the email-verification page has no CSRF token, so it 419s.**
  `resources/js/Pages/auth/VerifyEmail.vue:97-99` is a raw `<form method="POST" action="/logout">`; a
  native submission carries no `_token` and no XSRF header (only Inertia's axios layer supplies them), and
  `bootstrap/app.php:122` does not except `/logout`. Every newly registered account — and, since J3a
  mounted `verified`, anyone who changes their email — is stranded on an interstitial whose only exit is
  broken. **Live.** ⚠️ **Pre-existing since PR #6 and present on `main`**, but the correct twin ships one
  file over (`auth/TwoFactorRequired.vue:42`, `<Link method="post" as="button">`) and this diff rewrites
  the page immediately above it, explicitly to remove a lockout.
- **`major` · ADR-0019 §D11 attributes a SAML 2FA decision to ADR-0016 §D22, which decides the opposite
  polarity — and the as-built behaviour is recorded in no ADR at all.**
  `docs/adr/0019-first-party-google-sign-in.md:247` (also `:28`, `:86`, `:320`, `:334`) quotes a sentence
  that lives at `0016:168` under a different control; `0016:101` §D22 is about step-up and says it *"is
  never exempted"*. The as-built bypass is real: `SsoLoginCompletionController.php:134` calls
  `Auth::login($user)` with no `hasEnabledTwoFactorAuthentication()` fork, where
  `app/Services/Auth/GoogleSessionStarter.php:57-68` does fork — and
  `app/Http/Middleware/EnforceTenantTwoFactor.php:20-22` asserts *"Fortify already challenges an enrolled
  user at login"*, which is **untrue for the SSO door**. **Live** as a documentation defect; the SAML
  behaviour may well be the intended design, but it is currently recorded nowhere and mis-cited into code
  at `SsoLoginCompletionController.php:52-54`.
- **`minor` · `EnforceTenantTwoFactor` is absent from the `/api/v1` token-mint group.**
  `routes/api.php:73-89` — an unenrolled member under `security.require_two_factor`, bounced from every
  page, can still `POST /api/v1/auth/tokens` from the same session and use the bearer against Group B,
  which carries no 2FA gate either. **Live.** ⛔ **DOWNGRADED FROM `blocker` TO `minor` ON VERIFICATION,
  AND THE REASON IS THE ROW**: all six links hold, but the middleware is an **enrolment nudge by its own
  docblock** (`app/Http/Middleware/EnforceTenantTwoFactor.php:33-52`), Fortify's own 2FA-enrolment routes
  sit outside the same gate behind `password.confirm` — so the attacker already had a better path — and
  the token's abilities are capped at the issuer's own RBAC. It is a defence-in-depth and consistency gap.
  The code edit and the test edit are the same edit: mount it on Group A, and add a
  `StepUpReauthenticationTest:115`-shaped route manifest so it cannot silently come off again. Group B
  needs no gate — `routes/api.php:80-88`'s "gate the mint, not the bearer" argument applies verbatim.
- **`minor` · Three admin POSTs bind `{tenant}` with no `whereUuid`.** `routes/admin.php:56-63` —
  `suspend`, `reactivate` and `assign-plan`, while the two routes added around them pin the pattern and the
  docblock justifying the omission is now stale. A malformed uuid 500s instead of 404ing. **Live.**

### Design system

- **`major` · The combobox highlight leaves the visible box after roughly the sixth option and cannot be
  brought back.** `packages/design-system/src/components/Combobox/Combobox.vue:353-358` —
  `max-height: 22rem` + `overflow-y: auto`, with the highlight moved by `aria-activedescendant` only:
  nothing calls `scrollIntoView`, the rows deliberately carry no `tabindex` (`:267-271`), and arrow keys are
  `preventDefault`ed (`:176-192`) so they cannot scroll the region either. The command palette — the
  component's primary consumer — renders up to 21 two-line options (`SearchService::PER_ENTITY_PREVIEW = 5`
  × four arms + "See all"), and 22rem shows five or six. A sighted keyboard user then presses Enter blind.
  **Live**, WCAG 2.4.7. No gate sees it: the stories seed four options, so axe's
  `scrollable-region-focusable` never fires and happy-dom computes no layout.
- **`major` · The stacked sort chip ships a 32px touch target in the one layout that exists only on the
  touch band.** `packages/design-system/src/components/DataTable/DataTable.vue:488-495`, rendered only
  below `@container (max-width: 56em)` (`:657-659`) where `thead` is `display: none`, so it is the *only*
  sort affordance on an 834px tablet — 32px tall, 8px apart, wrapping. DSR §4.4 binds 44×44 with ≥8px
  between hit areas, and four siblings in this same package already satisfy it with the prescribed
  `::before` idiom (`Button.vue:102-114`, `Alert.vue:178-190`, `Checklist.vue:222-246`, `Toast.vue`).
  **Live.** ⚠️ It does **not** fail WCAG 2.2 AA (SC 2.5.8's floor is 24×24), so axe stays green — what is
  breached is the DSR's own stricter rule, and `docs/ux/exceptions-log.md` carries no entry for it.
- **`minor` · The pending-state ring measures 2.33:1 (light) / 2.96:1 (dark) against its own ground** —
  below WCAG 1.4.11's 3:1 for a non-text indicator — at
  `packages/design-system/src/components/PasswordStrength/PasswordStrength.vue:212-218` and
  `Checklist/Checklist.vue:289-295`, while both docblocks assert the glyph is the signifier. **Live.**

### App UI

- **`major` · Double-clicking "Create" provisions two spreadsheets in the tenant's Drive.**
  `resources/js/components/integrations/SheetsRuleFields.vue:168,276-278` — `create()` has no `inFlight`
  guard and `:disabled` is `destination !== null`, which stays false for the whole request, so `MdsButton`
  renders no native `disabled`. **`Button.vue`'s own click guard does not cover this**: it calls
  `stopPropagation()`, not `stopImmediatePropagation()`, and the consumer's `@click` falls through onto the
  same element, so Vue runs both handlers. The second file is an orphan Meridian will never write to and
  only the tenant can delete. **Live**, and the only `:loading` button in the tree that reaches a raw
  `fetch` with an irreversible external side effect — every other one is Inertia, whose stream is
  `maxConcurrent: 1, interruptible: true`.
- **`minor` · The unearned-badge medallion disappears in dark mode.**
  `resources/js/Pages/achievements/Index.vue:391` paints it with the *primitive* `--mds-neutral-100`, which
  resolves to the card's own colour in dark — the only primitive-token reference in the whole of
  `resources/js/Pages/`. **Live**, one token.
- **`minor` · The top-nav search field never shows the active query on an Inertia arrival.**
  `resources/js/components/shell/TopNav.vue:39` — `initialQuery` is a `computed` with no reactive
  dependencies, so inside the persistent layout it evaluates once per full page load. **Live.**
- **`minor` · The rule modal filters the rendered checkboxes but submits the unfiltered set.**
  `resources/js/components/integrations/RuleFormModal.vue:114,194` — `availableEvents` narrows to
  `submission.*` for a tabular grant while `submit()` sends `form.event_types` whole, so a pre-existing
  non-submission event is unremovable from the UI and still sent. **Live.**

### Test suite & CI gates

- **`major` · The 16-page responsive scan asserts nothing about which page it landed on.**
  `tests/e2e/responsive-axe.spec.ts:124-132` is `goto` → `forceTheme` → `assertClean` with no
  `waitForURL`, `toHaveURL` or heading check — and every plan/module refusal in this app answers a web
  request with `back()->with('toast')` (`bootstrap/app.php:315-334`), i.e. a 302 the goto follows
  silently. `/achievements` is gated by `module:gamification` and `E2eSeeder` never enables the module —
  it relies entirely on `ToggleableModules`' default — so flipping that default gives **six green scans of
  the dashboard**. **Latent**, and the idiom is present everywhere else in the shard, including the
  `filteredToZero` loop twenty lines below (`:154-163`) and `support/console.ts:34`. One line per test.
- **`major` · The login and 2FA-challenge rate limiters are asserted by no test in the repository.**
  `config/fortify.php:169-172` maps them by string and `FortifyServiceProvider.php:159,165` registers the
  closures; Fortify `array_filter`s the middleware, so nulling either config value or renaming either
  registration produces a route with **no throttle at all** — an exhaustible 6-digit TOTP and unmetered
  credential stuffing, with nothing red. **Latent.** The project already guards exactly this elsewhere:
  `SsoLoginWebTest.php:285` asserts every SAML limiter it names actually exists, precisely because a
  `throttle:` alias naming an unregistered limiter *"resolves to an UNLIMITED PASSTHROUGH"*.
- **`major` · Every accepted write in the answer-edit concurrency suite compares `null === null`.**
  `tests/Feature/Submissions/SubmissionEditRoutesTest.php:62` ·
  `tests/Feature/Submissions/SubmissionAnswerEditTest.php:579` — `SubmissionAnswerFactory` never stamps
  `answers_content_checksum` while `SubmissionFinalizer.php:96` stamps it on every real submission, so
  `baselineOf()` yields `''` → `null`. Drop the client token from the guard at
  `SubmissionAnswerEditService.php:135` — the single most natural simplification of an optimistic-
  concurrency check — and the suite stays fully green while post-submission editing is **permanently broken
  for every submission that exists in production**. **Latent.** The file's own `submitForEdit()` helper
  (`:750`) already produces production-shaped rows and is used by none of the concurrency cases.
- **`major` · `GET /feedback/{report}/screenshot` serves PII and has no DENY test at all.**
  `tests/Feature/Tenant/FeedbackTest.php:230` drives it as Owner only (200 with an image, 404 without);
  `:154` establishes the sensitivity in the file's own words and asserts `is_pii => true`. The gate is real
  today (`routes/tenant.php:429-430`, `can:feedback.view`) but it is a **separate** `Route::get` from the
  index, whose refusal is the only one asserted. Drop or mistype that one middleware call and every member
  can enumerate colleagues' screen captures. **Latent.** One `assertForbidden()` as a Viewer plus one
  cross-tenant `assertNotFound` closes it.
- **`major` · The queued half of `gamification:backfill` is asserted by job count alone.**
  `tests/Feature/Gamification/BackfillCommandTest.php:80-92` — `Queue::assertPushed(…, 2)` inspects no
  payload, and the queued loop (`BackfillGamificationCommand.php:119`) is the production default while only
  the `--sync` loop (`:142`) is proven to pass a usable id. Dispatch the slug instead of the key, or hoist
  the loop variable, and every workspace's historical points and badges are permanently absent — the
  backfill is a one-shot operator action nobody re-runs — while the operator is told "2 workspace(s)
  queued". **Latent.** Fix is a closure on `assertPushed`.
- **`minor` · Neither structural lint gate fails on an empty scan.**
  `scripts/constraint-boundary-lint.php:296-304` and `scripts/migration-lint.php:140` print the file count
  and `exit(0)` regardless, so a discovery regression — a moved directory, a mistyped iterator root — is
  indistinguishable from a clean run. **Live**, and it belongs with this project's standing lesson that a
  gate nobody can tell is blind is a gate nobody is running.

### Documentation & specs

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
  in no document".** `docs/security-threat-model.md:100` (repeated `:216`; duplicated into
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
  `docs/security-threat-model.md:170-178` (the SAML table) and `:49-52` (four isolation/extraction rows
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
  enforced by `EnforceTenantTwoFactor.php:91`). Anyone inventorying tenant configuration from the
  dictionary omits a tenant-scoped security policy. **Live.**
- **`minor` · ADR-0019 is the sole `Proposed` ADR in the directory, for a decision that is ratified and
  fully built.** `docs/adr/0019-first-party-google-sign-in.md:23` against its own `:271`/`:282` and the
  merged routes at `routes/google-auth.php:64,68`. 0014–0018 and 0020 are all `Accepted`, and both
  `0018:49` and `0016:133` already treat 0019 as settled precedent. **Live**, one word.
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
