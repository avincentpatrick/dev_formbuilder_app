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
| **Builder — the save indicator announces "All changes saved" after a save FAILS** | should | 3 | Found by JR5's adversarial pass and **deliberately not fixed there, because it predates JR5 and is not a layout bug**: `.builder__save` is `role="status" aria-live="polite"` driven by `saving = pending.count > 0`, and `guard()` catches the throw so `pending.count` returns to zero on the failure path too. The page therefore politely announces success at the moment the write failed — at **every** width, including 1440px on `phase1-completion` today. JR5 fixed the half it caused (the error alert being in a hidden pane) with a `watch(saveError, …)`; the honest fix here is for the indicator to read an explicit store state rather than infer success from "nothing in flight". WCAG 4.1.3 |
| **Builder — express the pane columns in `em` so they track the font-size axis** | nice | 3 | JR5 left `260px`/`340px` as literals, so at `extra_large` the 60em threshold is conservative relative to the columns it is derived from (1200 − 602 = 598px of canvas where 358 would do — a safe direction, but not the coherent one). `16.25em minmax(0,1fr) 21.25em` makes `16.25 + 21.25 + 22.5 = 60em` exact at every scale and un-cramps the palette at `extra_large`; out of JR5's approved scope, which held the wide layout unchanged |
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

- **`DestinationCatalog::visibleTo()` asks `feature()` directly** (`app/Support/Search/DestinationCatalog.php:84`), so on an **unseeded plan catalog** — a deploy that migrates before it seeds, a restored database, any test with no plan — global search and ⌘K omit Analytics, Webhooks, Integrations and Domains while the routes themselves **admit** the request. Same sign flip J4b2 found on a breadcrumb and J5c avoided on the dashboard, and `App\Support\Entitlements\FeatureAdmission` is the one-line mirror. **Found in J5c and deliberately NOT fixed**, because unlike those two this one is not obviously a defect: that file's docblock argues *on purpose* that its feature gates follow the **nav**, not the route (`nav-model.ts` hides the same items), and `/domains` is called out as a row where the two intentionally differ. Changing it is a decision about what the nav means in an unseeded state, not a cleanup — and the same question applies to `Sidebar.vue`, which reads the client entitlement snapshot for the identical set. Whoever takes it should move both or neither.

- **`MdsPasswordStrength`'s list carries no `role="list"`** — `list-style: none` strips list semantics in WebKit, so VoiceOver announces the requirement rows with no sense of how many there are. `MdsBreadcrumb`, `forms/Index.vue`'s card grid and (J5b) `MdsChecklist` all carry the attribute; this one predates the rule. One attribute, but it belongs with a look at the running page: this is a live region that re-announces while somebody types, and list semantics change what is read back.

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
