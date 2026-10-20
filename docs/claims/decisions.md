# Decisions queue

**This file exists so that no lane ever idles on a question.** Standing Rule 5 already says a
design decision the user has not made is not automatically a blocker — propose one, recommend,
and proceed. This is where that becomes mechanical for the cases that genuinely *are* the user's.

**How a lane uses it.** On reaching a real product call: append the question, the two or three
**real** options, and **your own recommendation** — then take the next row **in the same turn**.
Never wait — and **ask it by name before the session ends** (`D102`, which replaced `D66`'s
*file silently*).

**What does NOT belong here.** A residual you simply chose not to fix goes in
`docs/feature-backlog.md`, filed **the moment you decide not to fix it** — not here, and not in
`PROGRESS.md` prose only, which is how four live defects stayed invisible from J4b1 until J6.

**Decisions already taken are decisions of record — do not re-ask them:** drop `sortable` on the
two server-paginated tables (2026-08-18) · fail **open** on an unseeded plan catalog (2026-08-18) ·
password policy min-12 + HIBP + classes (2026-08-09) · Google-only social login (2026-08-09) ·
gamification last (2026-08-09) · the held list stays held until the user signals, and they said
*"not yet, ask again later"* on 2026-08-18 · **a flaky e2e result fails CI** (2026-08-26, D2 below) · **the M-series ends at zero open
`major` rows plus three consecutive increments filing none** (2026-08-28, D5 below) · **the batch series ends and the tiered pipeline succeeds it** (2026-09-14, D12 below) · **the testing server is invitation-only** (2026-09-14, D31 below) · **the guest per-address limits are raised on the testing server only** (2026-09-14, D32 below) · **the Windows Server 2016 testing site runs PostgreSQL 15** (2026-09-14, D43 below) · **the testing site is one workspace at the root of `staging.pitahc.gov.ph`, served by Apache** (2026-09-15, D46 below) · **automatic deploys to the testing server are on** (2026-09-17, D47 below) · **the repository stays public with fake data only through testing, and goes private before any real data** (2026-09-17, D48 below) · **inbound TCP 443 is open and `mod_md` renews the testing site's certificate over `tls-alpn-01`, with a scheduled task to activate it** (2026-09-18, D49 below) · **the API documentation marks its six unbuilt promises as not built, in place, rather than deleting them** (2026-09-20, D38 below) · **operators create every workspace, and `tenants:create` stays the only path** (2026-09-20, D44 below) · **the central-host sign-in loop is retiered `before-launch`** (2026-09-20, D51 below) · **the central-host welcome-email copy stays `early-testing`** (2026-09-20, D52 below) · **the deploy that deletes the previous build's chunks is retiered `early-testing`** (2026-09-20, D53 below) · **the offline panel's quota line says what it counts — *"across all sessions on this device"*** (2026-09-20, D26 below) · **the resume shell is cached under a token-free key, closing the enumeration primitive** (2026-09-20, D20 below) · **`MdsSegmentedControl` is left alone and its four stretch-clamped hosts guard themselves** (2026-09-20, D28 below) · **a workspace admin may invite any address** (2026-09-20, D33 below) · **a new account confirms its email address before that address counts as its own** (2026-09-20, D34 below) · **single-page mode becomes an author setting, defaulting to step by step** (2026-09-20, D35 below) · **corrections save on the button, and the documents say they are not resumable** (2026-09-20, D36 below) · **a lost second factor is cleared by an audited admin reset** (2026-09-20, D37 below) · **the testing site sends `Strict-Transport-Security: max-age=300` from the vhost** (2026-09-20, D54 below) · **a form presents its sections in the two modes the boolean already expresses, and "per page" becomes real by honouring `page_break` next** (2026-09-26, D57 below) · **one `ComparisonOperator` enum in PHP carries both a rule-row label and a sentence label** (2026-09-26, D59 below) · **form settings are reachable from both a hub tab and a builder modal, mounting the same components** (2026-09-26, D63 below) · **a field converts only within its own value shape, plus `note` and `hidden`** (2026-09-26, D64 below) · **a filed decision is put to the user only when the tier being worked holds a row blocked on it** (2026-09-26, D66 below) · **a guest whose device cannot run the form's rules is warned and can still submit** (2026-10-01, D67 below) · **the collapsed sidebar is remembered per browser** (2026-10-01, D68 below) · **a note carrying content blocks hides its label from respondents** (2026-10-01, D69 below) · **page breaks paginate automatically in every stepped form, and staff manual entry stays one step per section for now** (2026-10-02, the D57 amendment) · **open rows are batched three or four to an increment by file overlap, and the one-hub cap reads the files a remedy edits** (2026-09-02 and 2026-09-26, D13 and D15 below) · **`main` is branch-protected with the owner as the bypass actor** (2026-08-31, D7 below) · **the named client is redacted from the working tree and history is not rewritten** (2026-08-31, D6 below) · **the Oct 12 testing comes first: the 19 builder comments and single-form OCR, in one lane** (2026-10-03, D72 below).

---

## OPEN

_None open._ `D157`, the last one filed, was asked and answered on 2026-10-20 while `M181` (UI5) was planned, and recorded in its claim. A new one is appended here **and asked by name before its session ends** (`D102`).


## ANSWERED
### D157 — Nobody has seen a What's new yet, and at merge point 4 three releases go live together (UI5, UI6, then T2 + Likert). The first time someone sees the prompt, what should it list? **A — every release since the person's account was created; a brand-new user still sees only the newest one.**

**Asked and answered 2026-10-20 (user decision, in chat), while `M181` (UI5) was planned; recorded in its claim.** `D126` recorded "a brand-new user sees only the latest entry", and the plan of record (`~/.claude/plans/hi-let-s-conduct-a-mutable-goose.md` §C) applied it to every user whose `users.whats_new_seen_version` is NULL — which on the day the prompt ships is every tester, so they would have seen only T2's notes and missed UI5's and UI6's.

- **A — all since they joined (recommended, chosen).** A person who has never seen a release gets every release whose `released_at` is on or after their `users.created_at`, and at least the newest.
- **B — only the newest,** as first planned; the older notes stay on the What's new page.

**What the answer files, in the same push:** no new row — it refines `R-c7247dbf`'s remedy, taken by `M181`.

### D156 — A calculation's Type will include Text, but the formulas cannot join answers, so a Text calculation could only choose between fixed words with `if()`. Add a way to join answers? **A — yes: `concat()` in both formula engines and a "Join answers" preset with an optional separator (a full name from a first and a last name).**

**Asked and answered 2026-10-20 (user decision, in chat), while `M180` (R4) was planned; recorded in its claim.** Found by R4's planning pass: `R-f77aa45d` gives a calculation a Text type, and the function list (`app/Services/Expressions/FunctionRegistry.php`) has nothing that builds text from answers.

- **A — add Join answers (recommended, chosen).** `concat(…)` takes any number of values and joins them as text; the preset writes it with a separator.
- **B — Text means `if()` only.** Keep the six planned functions; a Text calculation picks between fixed words.

**What the answer files, in the same push:** no new row — it widens `R-da02fe03` (the function) and `R-f77aa45d` (the preset), both taken by `M180`.

### D155 — A duration or an age between two dates: how does a part-unit count? (8:30 a.m. to 5:00 p.m. in hours.) **A — whole completed units, like an age: 8 h 30 min is 8 hours, and a person is 34 until their birthday; a smaller unit gives more detail.**

**Asked and answered 2026-10-20 (user decision, in chat), while `M180` (R4) was planned; recorded in its claim.** `R-da02fe03` adds `date_diff(start, end, unit)` and `age(date)`; the row did not say whether a part of a unit counts.

- **A — whole units, like an age (recommended, chosen).** Only complete years, months, weeks, days, hours or minutes count — the way KoboToolbox, Fillout and a spreadsheet's DATEDIF count.
- **B — exact, with decimals.** 8.5 hours, rounded to the decimal places the author picks; years and months stay whole.

**What the answer files, in the same push:** no new row — it settles `R-da02fe03`'s remedy, taken by `M180`.

### D154 — A Default value can be a formula, such as `today()` or another question's answer. When is it worked out? **A — once, when the form opens; after that it is the respondent's answer to keep or change. A value that keeps updating is what a calculation is for.**

**Asked and answered 2026-10-20 (user decision, in chat), while `M180` (R4) was planned; recorded in its claim.** `D148` makes the Default value work and says a formula default is "worked out like a calculation"; a calculation updates as the answers change, which would overwrite what the respondent typed.

- **A — once, when the form opens (recommended, chosen).** ODK's dynamic default: worked out at the start, then left alone.
- **B — live until the respondent edits it.** Keeps updating, then stops at the first edit.

**What the answer files, in the same push:** it settles `R-20784770`'s remedy (taken by `M180`) and files the row *"An exported formula Default value becomes an XLSForm `calculation`, which ODK recalculates live, while this app works it out once when the form opens"* (`during-testing`).

### D153 — The app's clock runs on UTC, so before 8 a.m. in the Philippines "Today" in a formula is still yesterday. R4 adds Age, Yesterday and Tomorrow, and Today as a default answer, so the testers would see it. What does "Today" mean? **A — the date in the form's own time zone (Settings → Schedule → Time zone): new forms start on Asia/Manila, and existing forms still on UTC are switched to Asia/Manila — their schedule times stay the same moments, shown in Manila time. No data is removed.**

**Asked and answered 2026-10-20 (user decision, in chat), while `M180` (R4) was planned; recorded in its claim.** Both engines read `today()` off a clock the browser (`isoClock()`) and the server (`SemanticValidator::validate()`) stamp in UTC, deliberately, so that the two agree; `forms.timezone` exists (default `'UTC'`) but only the schedule's display reads it. `R-9fb95b45` (`M134`, `D89`) filed the defect as a missing workspace time zone.

- **A — the form's time zone, existing forms switched (recommended, chosen).** Both clocks stamp the form's zone, so they still agree; an additive migration changes the column default and switches the forms still on UTC.
- **B — the form's time zone, new forms only.** Existing forms stay on UTC until an author changes them.
- **C — keep UTC for now.** Leave Today as the UTC date and file the fix for later.

**What the answer files, in the same push:** `R-9fb95b45`'s clock half is taken by `M180`; its other half — comparing a date or time answer with `now()` — is filed as the row *"A date or time answer cannot be compared with `now()`, so publish refuses that comparison as not supported yet"* (`during-testing`).

### D152 — When should V (the testers following and checking every relayed comment inside Feedback) go live? **A — next, released alone as soon as it is green, after the user's backup step: a one-time exception to `D137`'s "no small merges".**

**Asked and answered 2026-10-19 (user decision, in chat), while V was planned; recorded in `M179`'s claim.** The user had relayed 31 comments by hand (their UI comments of 2026-10-14, the testers' round 1 of 2026-10-15, round 2 and the testers' PDF of 2026-10-16) and asked for their progress to be visible on staging, with a way for testers to mark a fix as working. The user then added: plan only, without disturbing the session already running — so V is built after that session's increment (R3, `M178`, held green for merge point 3), branched from `origin/main` beside it.

- **A — next, released alone (recommended, chosen).** Built before R4 and released on its own at its green, after the user's backup step; the testers can check the waiting fixes at once. A one-time exception to `D137`, needing the user's word — given.
- **B — next, released with batch 3.** Built before R4 but released with R3 and R4 at merge point 3; keeps the no-small-merges rule, and the testers wait.
- **C — after batch 3.** Built after R3 and R4, released with batch 4.

**What the answer files, in the same push:** nothing new — it amends `D138`'s order (V between R3 and R4) and is the reason `M179` merges alone while `M178` waits. Its release follows the trunk's merge rule as it stands then (the user's backup block, until R3 retires it).

### D151 — What can a tester do on a report we marked as fixed? **A — confirm it, reopen it, or comment: "It works" marks it Confirmed with the tester's name; "Still a problem" reopens it to Ongoing and needs a comment (a screenshot optional), and we see it in the next session's pull; anyone who can see a report may comment at any time; only we mark a report fixed.**

**Asked and answered 2026-10-19 (user decision, in chat), while V was planned; recorded in `M179`'s claim.** The user (2026-10-19): *"the feedback feture of our app must be like that so users can check if it is really applied of fixed. we can only tag those as resolved on our end but users can comment or feedback again about the output of our fix"*. Today a tester can only read their own reports and our one reply (`resources/js/Pages/feedback/Mine.vue:57-86`); no comment, confirm or reopen exists, and the only transition writer checks the status before its transaction and takes no lock (`app/Services/Admin/SuperAdminService.php:929-1007`).

- **A — confirm, reopen, comment (recommended, chosen).** A new status, Confirmed ("Confirmed working"), reachable only by a tester from Resolved; "Still a problem" goes back to Ongoing with a required comment; a comment thread on every report; support alone marks Resolved and can never mark Confirmed.
- **B — comment only.** Testers add comments; we read them and decide whether to reopen. No confirm button.

**What the answer files, in the same push:** the row *"A tester cannot confirm a fix, say it is still a problem, or comment on a report; our reply is the only message, and the Owner/Admin list never shows it"* (`early-testing`, taken by `M179`), and with `D150` the row *"The support API and the pull script cannot create a relayed report, post a reply, or see a reopen or a tester's comment"* (`early-testing`, taken by `M179`).

### D150 — Where should the testers see the progress of the comments relayed outside the Feedback button, and mark them as working? **A — inside Feedback: each comment is loaded as a relayed report every member of the workspace can see, under a "Testing-round comments" view that hides itself once every relayed report is Confirmed or Declined; no separate page.**

**Asked and answered 2026-10-19 (user decision, in chat), while V was planned; recorded in `M179`'s claim.** The user had asked for *"a temporary page for the monitoring of this comments in the staging site … so the users are aware of the progress and they can also click the resolve if they tested it"*, to be removed once the batch is done. Staging data is never deleted (`D137`), so a temporary page's records would stay in the database either way, and the confirm and reopen buttons would have to be built twice.

- **A — inside Feedback (recommended, chosen).** The comments become reports with a source (`relayed`), a reference (e.g. `R1-7a`), a title and a source line; the confirm and reopen buttons are built once, in Feedback, which is the end state wanted anyway.
- **B — a separate temporary page.** A "Comment tracker" page with a "Tested — it works" button, hidden once every line is done; Feedback's own buttons built a second time.

**A follow-up, asked and answered 2026-10-19 while `M179` was planned (the user, in chat):** eleven of the 31 items are fixes the user already checked on staging ("All good", 2026-10-19). **They load as Resolved, as the approved list says** — the testers who raised them press "It works" themselves (recommended, chosen); the alternative was to load them as Confirmed by the user on Oct 19.

**What the answer files, in the same push:** the row *"A comment that reached us outside the Feedback button cannot be seen, followed or checked by the testers in the app"* (`early-testing`, taken by `M179`).

### D149 — A condition or a rule can name a calculated question, but both engines work out calculations last and read it as blank. What should happen? **A — let conditions and rules read the calculated value, in R4, which already orders calculations so one can use another.**

**Asked and answered 2026-10-19 (user decision, in chat), while `M178` (R3) was planned; recorded in its claim.** Found by R3's planning pass: computed values never feed back into relevance or constraints (`app/Services/Validation/SemanticValidator.php:153`), yet the condition editor and the compared-question lists offer every calculated question, so such a condition never shows and such a rule never checks.

- **A — let them read it, in R4 (recommended, chosen).** Calculations worked out in dependency order before the conditions and rules that read them; cycles refused at publish.
- **B — stop offering them.** Calculated questions leave the condition and rule lists; only labels and messages show them.
- **C — file it for later.** Leave the lists as they are.

**What the answer files, in the same push:** the row *"A condition or a rule can name a calculated question, but always reads it as blank"* (`early-testing`, taken by R4). Until R4, R3's new lists leave calculations out.

### D148 — A question's "Default value" is saved but never filled in for a respondent, though the Help page says it is. What should happen? **A — make it work, in R4: the default is filled in when the form opens, and the respondent can change it.**

**Asked and answered 2026-10-19 (user decision, in chat), while `M178` (R3) was planned; recorded in its claim.** Found by R3's planning pass: nothing reads `default_value` when a form opens (`resources/public-runtime/lib/prefill.ts` seeds only a hidden field's fixed value), and `resources/js/components/help/manual.ts:814` promises it.

- **A — make it work, in R4 (recommended, chosen).** R4 already reworks that panel and both formula engines, and goes live with R3 at merge point 3.
- **B — make it work, later.** Its own row in the testing tier, after R4.
- **C — hide the box for now.** Remove the box and the Help sentence until it is built.

**What the answer files, in the same push:** the row *"A question's Default value is saved but never filled in for a respondent, though the Help page says it is"* (`early-testing`, taken by R4). R3's picker leaves the box out until then.

### D147 — On the printed blank paper form, a question whose label shows another answer (`${name}`) prints the code as typed. What should the paper show? **A — a blank line: "How old is ________?", for the person filling the paper to write in.**

**Asked and answered 2026-10-19 (user decision, in chat), while `M178` (R3) was planned; recorded in its claim.** Every other channel pipes the answer in; the blank paper form printed the label, hint and description raw (`app/Services/Forms/BlankFormPrintPresenter.php`, line 385 at `617f12d0`), and R3's picker makes such a label one click away.

- **A — a blank line (recommended, chosen).** Built in R3.
- **B — the other question's label in brackets.** "How old is [Name]?"
- **C — leave it for later.** Its own row; paper keeps the code until then.

**What the answer files, in the same push:** the row *"The blank paper form prints a reference inside a label, hint or section text as typed"* (`early-testing`), filed and closed by `M178`.

### D146 — Bulk move (`R-4a6f004f`): how does an author pick several questions in the Structure view? **A — a tick box on every question row; when one is ticked, a bar appears above the list ("N selected · Move to [section] · Move · Clear"); shift-click ticks a range.**

**Asked and answered 2026-10-18 (user decision, in chat, with mock-ups), while `M177` (R2) was planned; recorded in its claim.** The testers' comment (2026-10-11, comment 4): *"Form builder does not all[ow] bulk movement of questions to a section"*. `MdsMenu` may not be mounted inside the scrolling canvas (DSR §3.4b), so the target is chosen in the bar.

- **A — a tick box on every row (recommended, chosen).** One click to start; the bar sits outside the scrolling list.
- **B — a "Select questions" button first.** Rows look as they do today until it is pressed; Done hides the tick boxes again.

**What the answer files, in the same push:** nothing new — it shapes `R-4a6f004f`'s remedy, taken by `M177`.

### D145 — Retiring Yes/No (`R-0b7db001`, `D140`): its note was to offer "Change type → Single choice", but `D64` refuses converting a Yes/No into a Single choice. Allow that one change? **A — allow it: Change type turns a Yes/No into a Single choice with the choices Yes and No; earlier answers stay as they were, and the dialog warns that new answers are stored as a choice. `D64` gains this one exception.**

**Asked and answered 2026-10-18 (user decision, in chat), while `M177` (R2) was planned; recorded in its claim.** `D64` puts `yes_no` in the Isolated family (`app/Support/Forms/FieldTypeConversion.php`), converting only to `note` or `hidden`. The builder's undo reverses a conversion through the same endpoint, so the reverse edge (`single_select → yes_no`) exists for undo only and the dialog never offers it (a retired type is never a target an author picks).

- **A — allow that one change (recommended, chosen).** The retired note can point at a working button; the conversion plan, census and warnings already exist.
- **B — keep `D64` as it is.** The note only says the type is retired and still works; new questions use Single choice's Yes / No preset.

**What the answer files, in the same push:** nothing new — it shapes `R-0b7db001`'s remedy, taken by `M177`; `D64` carries a pointer here.

### D144 — The deploy will count every table's rows before and after it migrates (`R-a11cc78a`). If a table holds fewer rows after the migration than before it, what should the deploy do? **A — stop with the site left down on purpose, the backup's path in the log, so no tester writes on top of lost data before a restore is decided.**

**Asked and answered 2026-10-18 (user decision, in chat), while `M176` (R1) was planned; recorded in its claim.** The window in `deploy.ps1` already leaves the site down on purpose when a step inside it fails; a count that drops after `migrate` is data already gone, and bringing the site back up would let new answers land on top of it before anyone has decided whether to restore the backup.

- **A — stop, the site stays down (recommended, chosen).** Maintenance mode holds; the run goes red naming each table that shrank and the backup to restore from.
- **B — bring the site back up and warn.** The run goes red with the same list, and the testers keep working while it is looked at.

**What the answer files, in the same push:** nothing new — it is `R-a11cc78a`'s remedy, taken by `M176`.

### D143 — `deploy.ps1` runs the copy already in the server's live checkout, so a change to it takes effect one deploy late: R1's own deploy runs the old script, and R2's deploy at merge point 2 is the first to take the deploy's own backup and count the rows (`R-a11cc78a`). Whether the runner's account can run `pg_dump` and write the backup folder has never been measured. When is the guard proven on the testing server? **A — at merge point 2: the user's backup block still runs first; if the guard cannot back up, it brings the site back up on R1's code and stops, and the server's rights are fixed together then; the block is retired only after a guarded deploy has passed.**

**Asked and answered 2026-10-18 (user decision, in chat), while `M176` (R1) was planned; recorded in its claim.** The runner is the GitHub Actions service running as NETWORK SERVICE; the nightly backup task runs as SYSTEM; the `pg_dump` path on the box is inferred, not read. `D137` retires the per-merge backup block once the guard is proven.

- **A — at merge point 2 (recommended, chosen).** No extra time on the server now; a failure is safe (nothing migrated, the site back up) and is fixed step by step in that session.
- **B — now, on the server.** One guided block (RDP as Administrator, about ten minutes) runs `pg_dump` and a test write as the runner's account before the step is written.

**What the answer files, in the same push:** nothing new — R2's hand-off carries the merge-point-2 steps (read the run log for the backup path and both counts; fix the runner's rights if the guard refused).

### D142 — "Add a question here" must add a question (`R-5354b4b9`). The plan said a menu at the button, but `MdsMenu` may not be mounted inside a scroll container (DSR §3.4b) and both the Structure canvas and the Preview pane scroll. How does the list of question types appear? **A — an inline list: the button opens the types right there in the section as a grid of buttons; the chosen type lands in that spot; Cancel folds it away — the same in Structure and Preview.**

**Asked and answered 2026-10-18 (user decision, in chat, with mock-ups), while `M176` (R1) was planned; recorded in its claim.** The testers' comment (2026-10-11, comment 3): *"“+ Add question here” button not working"*. Today the button only selects the section and moves focus to the palette, which refuses focus while a save runs.

- **A — an inline list (recommended, chosen).** Nothing floats over a scrolling pane, so nothing is cut off; it reuses the palette's own grouping.
- **B — a small window** titled "Add a question to <section>" listing the types.
- **C — a pop-up menu at the button**, after teaching the design system's menu to float above a scrolling pane — a larger design-system change.

**What the answer files, in the same push:** nothing new — it reshapes `R-5354b4b9`'s remedy, taken by `M176`.

### D141 — `D139` put the platform support API on the central host, but on the testing server the central host (`pitahc.gov.ph`) is the agency's website: only the box itself reaches the console there, through its hosts file, so this machine could never pull the testers' reports from it. Where does the feedback API answer? **A — on the workspace host too: `https://staging.pitahc.gov.ph/api/platform/v1/…` (any platform host, a custom domain refused), opened only by the platform support token — a super-admin's, with confirmed 2FA, one ability, an expiry of at most 90 days, revocable, every call audited; the console itself stays on the central host.**

**Asked and answered 2026-10-16 (user decision, in chat), while `M175` (F) was planned; recorded in its claim.** Measured: `pitahc.gov.ph` resolves to 121.58.210.229 (the agency's website), `staging.pitahc.gov.ph` to 121.58.210.237 (the testing server); `docs/deployment-infrastructure.md` step 7 reaches the console through a hosts entry on the server itself. The token is the whole authorization: it carries no session, so the console's step-up never applies to it — which is why it expires, is re-checked against the account on every call, and leaves an audit row per call.

- **A — the workspace host, token only (recommended, chosen).** Reachable from this machine; the console's reach is unchanged.
- **B — A, plus an IP lock**: the token also works only from one internet address; safer if it leaks, but the pull stops whenever the office address changes until it is updated in the console.
- **C — off the internet**: an artisan export on the server, copied to this machine by hand each session (the shape ADR-0018 §D1 chose for extraction); nothing new exposed, but nothing automatic either, which `D139` asked for.

**What the answer files, in the same push:** `R-78f43f26`'s remedy re-hosted (its tail says so); ADR-0023 records the surface.

### D140 — The testers' second round asks to remove the Yes/No and Duration question types: Yes/No is covered by a Single choice, and a duration should be worked out by a calculation. Staging holds forms and answers that use Yes/No, and nothing may be removed from it. How do the two types go? **A — both leave the palette and can no longer be created; every existing question keeps working and no data is touched. `D134` changes: Duration is not built; Signature still is.**

**Instructed and answered 2026-10-16 (user decision, in chat), recorded by the round-2 planning session (plan `~/.claude/plans/here-are-added-comments-peppy-quill.md`); its filing is adopted into `M174`'s claim.** The user: *"remove the yes/no question. it is already covered by the options"*, *"also remove the duration question type, that can also be covered in the calculate"*, and *"always make sure that the staging has data, do not remove data"*. The palette lists every field type (`app/Services/Forms/BuilderPresenter.php`), the catalog seeds create Yes/No questions, and a Duration question cannot be answered on any surface.

- **A — retire from the palette (the shape the never-remove-data rule leaves, chosen).** A palette flag, a refusal to create, a "retired — change it to Single choice" note on existing questions, a Yes / No choices preset on Single choice; existing questions, answers and exports unchanged.
- **B — convert every existing question** to a Single choice: rewrites drafts and leaves published versions mixed; refused by the data rule.
- **C — delete the types**: every form holding one breaks; refused by the data rule.

**What the answer files, in the same push:** `R-0b7db001` (Yes/No retired) and `R-22011569` (Duration retired). **Owed by the next increment's first commit (the filing touches only the end of the ledger):** `R-af2d278b`'s tail — Signature only, Duration withdrawn; `R-5033e87e` and `R-0a7275ce` — no yes/no column or table type.

### D139 — The testers' feedback is the fastest way to find what to fix, but a report stays in the console until someone copies it into the dev session, and the tester is never told what became of it. How should feedback reach the dev session, and what is the tester told? **A — the dev session pulls every report automatically at the start of each session and triages it against the line; the statuses read New, Ongoing, Resolved and Declined; the tester is notified on Resolved and Declined, and the session sends only "fixed and live" by itself.**

**Asked and answered 2026-10-16 (user decision, in chat), recorded by the round-2 planning session; its filing is adopted into `M174`'s claim.** The user: *"pull it automatically, every start of a session, we will include always to check the feedback record if there is any and automatically insert it in the pipeline. but review it if its already fixed or ongoing"*, and earlier *"there must be a tagging for if its a bug, a question or a suggestion/recommendation, get url, tagging if its resolved declined new and ongoing, get user, also update user if its fixed/resolved"*. Two follow-ups were answered the same way: the session sends a tester only "Fixed in version X, live on staging" on its own — an answer to a question and every Declined go to the user first; a pulled bug is filed `early-testing` and a suggestion `during-testing`.

- **A — pull automatically (recommended, chosen).** A platform support API on the central host, a token kept on the dev machine only, `scripts/feedback-pull.php`, and a triage rule at session start: already fixed → Resolved; already queued → Ongoing, cited on its row; new → a row and Ongoing.
- **B — export from the console**: a download the user drops into the chat each time; no token, one manual step per pull.
- **C — both**: the pull, with the download kept as a fallback.

**What the answer files, in the same push:** `R-9bfd832e` (statuses, the note, the tester told, own reports), `R-78f43f26` (the support API and the pull script), `R-f3fb2522` (the session-start routine). With `R-2ce2f274` (`D127`: the capture, the type, the page address) they are the increment F of `D138`.

### D138 — The testers' second round (seven comments of 2026-10-11 and eleven items of 2026-10-16) lands while `M174` builds UI3, with UI4–UI6 and T2–T6 still ordered by `D128`. The user asks to put the feedback mechanism first. What is the order of record now? **A — `M174` → F (feedback) → merge 1 → R1 (builder fixes) → R2 (types, visibility, moving) → merge 2 → R3 (references) → R4 (calculations) → merge 3 → UI5 → UI6 → T2 with the Likert row → merge 4 → T3 → T4 → merge 5 → T5 → T6 → L (change history) → merge 6.**

**Asked and answered 2026-10-16 (user decision, in chat), recorded by the round-2 planning session; its filing is adopted into `M174`'s claim.** The user: *"kindly prioritize the issue on the feedback mechanism so users can use that to directly pinpoint the problem and directly fix it here"*. The line orders by tier and cannot sequence one row after another (`R-6175d935`), so this order lives here, in the plan (`~/.claude/plans/here-are-added-comments-peppy-quill.md` §E) and in each hand-off; every round-2 row is `early-testing` except the change history (`during-testing`). The first merge carries UI2 (`M173`), UI3 (`M174`) and F, as the user chose.

- **A — the testers' fixes next (recommended, chosen).** Feedback, then the round-2 builder fixes, types and visibility, references and calculations; then UI5–UI6; then the grid and Likert (T2–T6); the change history last. `D128`'s UI4 becomes F.
- **B — finish the UI programme first**: F, UI5, UI6, then the round-2 items, then T2–T6.
- **C — the grid and Likert early**: F, the round-2 fixes, T2, then references, UI5–UI6 and the rest.

**What the answer files, in the same push:** the increments' rows — F: `R-2ce2f274`, `R-9bfd832e`, `R-78f43f26`, `R-f3fb2522`; R1: `R-c4802b60` (`major`), `R-164579e8`, `R-5354b4b9`, `R-5554c526`, `R-61d43278`, `R-923c5041`, `R-36e6f557`, `R-a11cc78a`; R2: `R-0b7db001`, `R-22011569`, `R-71509975`, `R-f44e9d00`, `R-9bbaa97b`, `R-4a6f004f`, `R-b05fbaae`; R3: `R-75719ac4`, `R-917496f9`; R4: `R-f77aa45d`, `R-da02fe03`, `R-f7a18b7a`; T2: `R-f03cf999` beside `R-1c1d7266`; L: `R-3894369d`. **Owed by F's first commit:** `R-2ce2f274`'s tail names F and this decision; the gap table's `or_other` line is tagged with `R-71509975`.

### D137 — The testing freeze held every PR at green until the user's word (`D123`, `D128`), so finished work waited and each merge was a separate deploy under the testers. The user lifts the hold but wants no small merges, and staging must never lose data. How do merges work now? **A — PRs merge in consolidated batches at the plan's merge points, never a lone small PR; at a merge point the session merges on its own after the user runs the staging backup block; migrations are additive only; a defect that blocks testers may go out alone on the user's word.**

**Asked and answered 2026-10-16 (user decision, in chat), recorded by the round-2 planning session; its filing is adopted into `M174`'s claim.** The user: *"remove the rule on holding the merge but do not merge on small merges, make a strategic consolidated merge so we wont merge small fixes or changes. always make sure that the staging has data, do not remove data"*. Every green CI run on `main` deploys (`.github/workflows/deploy.yml`), so a lone small merge is a deploy under the testers; the before-merge backup is a block the user runs on the testing server.

- **A — the session merges at a merge point after the backup (recommended, chosen).** The plan names the merge points; between them a PR waits green; a merge point merges its batch in order, each with an explicit body, then checks the site and that the data survived; once `R-a11cc78a` (a deploy that backs itself up and counts its rows) is proven, the backup block is retired.
- **B — the user's word at every merge point**: the session stops at green and asks, as under the freeze.
- **C — merge each PR when green**: the pre-freeze rule; a deploy per increment.

**What the answer files, in the same push:** `R-a11cc78a`. **Owed by F's first commit (the imperative layer, outside this docs-only filing):** `CLAUDE.md` "The trunk" and the generated hand-off (`scripts/next.php`) say merge only at a planned merge point, after the user's staging backup, additive migrations only; `PROGRESS.md` Standing Rule 5 records why.

### D136 — The staff manual-entry page keeps its questions in a reading-width column (`D121`), with a side panel carrying form facts, progress and reference files; but the page is sent no form status and no reference files — showing those needs new server data and a staff download, not layout. What goes in the side panel in UI3? **A — facts and steps: the form's name and version, the open or closed notice, and the form's sections with the current one marked; the version's reference files filed as their own row.**

**Asked and answered 2026-10-16 (user decision, in chat), recorded by Lane A in `M174`'s claim.** Found while verifying `R-66952d33`'s premise: `app/Services/Submissions/EncodeFormPresenter.php` sends the version number and nothing about the form's status or its reference files (those reach the builder only, through `forms.reference-files.index`), and the autosave label must stay in the header beside Cancel, where a keyer looks before leaving.

- **A — facts and steps (recommended, chosen).** The panel shows what the page already has; `R-66952d33` stays a layout row; the reference files become their own row.
- **B — also the reference files**, as download links for staff: new server data through the encode presenter (a hub) and a permission check; a bigger increment and a later merge.
- **C — the column only for now**: the questions keep a 45rem column and the panel is filed as its own row; the right side of a wide screen stays empty on this page.

**What the answer files, in the same push:** `R-66952d33`'s remedy amended to A; a new `during-testing` row for the version's reference files on the manual-entry page.

### D135 — The form's Settings tab shows one section at a time (a rail switches thirteen sections), so `R-14971e37`'s remedy — the sections laid two-up — has nothing to pair. Once the page fills the width, how should the tab use a wide screen? **A — keep the rail: one section at a time, as today; every labelled box in a section keeps a reading width (48rem) while the lists and tables inside a section fill the width.**

**Asked and answered 2026-10-15 (user decision, in chat), recorded by Lane A in `M173`'s claim.** Found while verifying `R-14971e37`'s premise: `resources/js/components/forms/FormSettingsSections.vue` mounts each section once and shows only the active one (`v-show`), the same component the builder's Settings pop-up mounts, so two sections are never on screen together. Without a remedy, removing the shell's cap (`R-fd7b8879`) would stretch a section's boxes to about 2,000px on a 2560px screen.

- **A — keep the rail (recommended, chosen).** One rule holds every `MdsFormField` inside a section at 48rem; lists, tables and grids inside a section (choice lists, automations, data sharing, theme presets) fill; the pop-up looks the same.
- **B — every section as a card** in a two- or three-across grid on the Settings tab page only, the rail a jump-to list; all thirteen sections mount at once and the page and the pop-up stop looking alike.
- **C — leave the tab alone** in this increment, the row kept open.

**What the answer files, in the same push:** `R-14971e37`'s remedy amended to A (no new row).

### D134 — Duration and Signature questions can be placed on a form and answered on neither the web form nor manual entry (`R-af2d278b`). Build the controls now, or hide the two types until after testing? **A — build both controls, in the testers' programme's last increment.**

**Asked and answered 2026-10-15 (user decision, in chat), recorded by Lane A in `M172`'s claim.** The testers asked whether the "Adv" question types are ready (item 6 of their first round); the eleven badged types are, and the two that are not carry no badge.

- **A — build both (recommended, chosen).** Hours/minutes/seconds boxes for Duration; a draw-to-sign pad for Signature saved as a picture through the attachment path, shown in the PDF and on the response page, a signature box on paper.
- **B — hide both from the palette** until after testing.
- **C — build Signature, hide Duration.**

**What the answer files, in the same push:** `R-af2d278b` retiered `early-testing`, and R-96127b20, R-3e6358ed (`early-testing`).

### D133 — A question used by a published version that already holds responses can be deleted from the draft; nothing warns, and nothing on a response or an export says the question was removed. What should a delete do? **A — the user's own design: the delete works in the draft; with no responses nothing more is said; with responses the dialog says how many hold answers and that the question is retired — kept on every response and export, marked "removed from the form in version N" — and the hub's Versions panel shows what changed in each version and opens an old version read-only.**

**Recorded 2026-10-15 from the user's message (item 5 of the testers' first round), by Lane A in `M172`'s claim.** The user: *"there must be a way to delete that if the published form doesnt have a submission or if there is already submitted record, ask and notify the user that it will be removed from the form builder but not totally delete it, its like tagging the item to obsolete (soft delete to retain the submitted responses still available with a codicil this item/question is already removed …) but there must also a way to check the older version for history."* Measured: a delete already touches only the draft, and every response keeps its answers under its own version; what is missing is the warning, the note and the history view.

- **A — the user's design (chosen).**
- **B — refuse the delete while responses exist** (today's behaviour, with a clearer message).
- **C — A, plus hard-delete the old version's answers when the author confirms.**

**What the answer files, in the same push:** R-a44638b6 and R-52f5ad93 (`early-testing`); `R-9ea69e29` retiered `early-testing`.

### D132 — When a question's key collides with another question's key — an author typed one in use, or an undo or a journal replay brought one back — what should the builder do? **A — avoid it, then auto-adjust, never refuse: the builder warns as the author types a key in use and offers the free form; a clash that still reaches the server takes a numbered suffix, the panel says so under Field key, and every rule naming the old key follows.**

**Asked and answered 2026-10-15 (user decision, in chat), recorded by Lane A in `M172`'s claim.** The user: *"if we can already help the user avoid the errors, notify them or the app auto adjust the key naming to avoid conflicts"*. The main cause the testers hit — a builder tab left open across a publish — is a separate row with its own remedy (a 409 that reloads and replays the unsaved edits by key).

- **A — avoid, then rename with a numbered suffix and say so (recommended, chosen).**
- **B — refuse with a plain message that opens the Advanced tab on the key box.**
- **C — suffix for keys the app generated, refuse for keys the author typed.**

**What the answer files, in the same push:** R-733eff09 (`early-testing`); `R-4c4e35ae`, `R-31ff7ade` and `R-d85d51d2` retiered `early-testing` as folded or taken by it.

### D131 — Philippine places: an author must upload one CSV per level, and the PSA publishes the official PSGC every quarter as an Excel file. Where should the data come from? **A — built in, from the PSA file, free: the quarterly Publication Datafile, downloaded in a browser, is built into a repository CSV and loaded by an operator command on every deploy; one preset in the cascade's Levels tab gives Region → Province → City/Municipality → Barangay; a published form keeps the quarter it was published with.**

**Asked and answered 2026-10-15 (user decision, in chat), recorded by Lane A in `M172`'s claim.** The testers asked whether the platform supports PSGC-linked cascading options (item 2). `M141` made a PSGC-sized cascade work from uploaded files; the data itself was never shipped, because `docs/PRD.md:442` had declined a built-in national geography — this answer reverses that line for a platform-wide read-only list under the shared-lookup exemption of `docs/adr/0002-multi-tenancy-shared-db-rls.md:73`. The PSA site blocks scripted reads, so the file is fetched by hand; PSA is credited (`D112`: the free option first).

- **A — built in, from the PSA file, free (recommended, chosen).**
- **B — keep per-form uploads and hand testers four prepared PSGC CSV files.**
- **C — read from a free third-party PSGC web service** — an outside dependency that respondents' browsers and the server would call.

**What the answer files, in the same push:** R-70c7673a and R-ed7f3127 (`early-testing`); `R-7a2f4a13` retiered `early-testing`; R-bffbaa51 (`after-launch`).

### D130 — A whole grid reaches a spreadsheet as one cell (`row: col=cell; …`), and Sheets and Airtable can map only that cell; Kobo gives every cell its own column. What should exports do? **A — one column per cell beside today's summary column, so no existing Sheets or Airtable mapping changes identity.**

**Asked and answered 2026-10-15 (user decision, in chat), recorded by Lane A in `M172`'s claim.** It answers the second of the two questions `R-c64d0afd` recorded in `M74`; the XLSForm export already flattens a grid to one question per cell, so the spreadsheet shape follows the one the ecosystem has.

- **A — one column per cell, keep the summary (recommended, chosen).**
- **B — one column per cell only;** any existing mapping of the summary breaks.
- **C — keep the summary only;** no change.

**What the answer files, in the same push:** R-51e044a6 (`early-testing`); `R-c64d0afd` retiered `early-testing` with its first question left open.

### D129 — The testers want a grid whose cells can be configured separately with different question types and option sets, rows taken from lists or shared responses, and rows and columns added in the Preview; KoboToolbox's matrix has fixed rows and typed columns, Fillout's table has typed columns and the respondent adds rows. Which do we build? **A — both: the Matrix gains typed columns, each with its own choices (rows fixed, or from a list or another form's answers); a repeatable section gains a Table layout where respondents add rows; both are drawn as real tables on wide screens.**

**Asked and answered 2026-10-15 (user decision, in chat), recorded by Lane A in `M172`'s claim.** The user called the grid crucial and asked that both approaches be captured. The Table half is the description `D57` asked for before a tabular presentation could be built (`R-0a7275ce`).

- **A — both: a typed Matrix plus a Table layout on repeatable sections (recommended, chosen).**
- **B — a typed Matrix plus a separate new Table question type** — more code, and two places to answer the same thing.
- **C — the typed Matrix only;** no respondent-added rows.

**What the answer files, in the same push:** R-5033e87e, R-1c1d7266, R-bb79aec0, R-7418e6bd (`early-testing`); `R-0a7275ce` retiered `early-testing`; R-ffd10da2, R-6e807a0e, R-d713c3bf (`during-testing`), R-9ce9ca4b (`after-launch`).

### D128 — The testers' first-round items (seven, 2026-10-15) and the UI programme's five remaining increments (`D123`) both want the front of the line, and `D13`'s batch and one-hub rules cannot hold a grid rebuilt across both engines. In what order are the two programmes built, and how are they batched? **A — the two errors that block the testers first (one small increment), then the UI programme's five increments, then the rest of the testers' items in five themed increments (the grid in two parts, options from anywhere, Philippine places, every type ready); both programmes in the `early-testing` tier; inside each increment every row its own commits in a stated order (`D75`), file overlap allowed, every hub named at the claim; every PR held at 6/6 green until the user says merge, after a fresh staging backup.**

**Asked and answered 2026-10-15 (user decision, in chat), recorded by Lane A in `M172`'s claim.** Asked first as "the testers' programme ahead of the UI programme, in six themed increments"; the user answered *"insert the ui. complete it then this"*, and then, asked whether the two blocking errors should wait behind five UI increments, chose the blockers first. The tier is the only lever the generated line has for priority and it cannot sequence one row after another (`R-6175d935`), so the order of increments lives in the plan (`~/.claude/plans/i-have-intial-comments-quirky-pascal.md`, section E) and in every hand-off, and the tier carries the priority: the UI programme's sixteen open rows move up to `early-testing` with this entry as their reason.

- **A — blockers, then the UI programme, then the rest (chosen).**
- **B — the whole testers' programme ahead of the UI programme** (the recommendation as first put).
- **C — strict `D13` batches** of three or four rows, about twelve increments.

**What the answer files, in the same push:** R-81756e95 (`early-testing`); the sixteen UI rows retiered `early-testing`; the plan's increments T1–T6 and UI2–UI6 in the order above.

### D127 — The feedback panel's "Capture screen" takes the frame while the panel is open, so every screenshot carries the panel. How should the capture leave it out? **A — the panel steps aside: it closes for the capture, the browser's tab picker opens with this tab first, the frame is taken, and the panel returns with the text intact and the picture attached.**

**Asked and answered 2026-10-14 (user decision, in chat), recorded by Lane A in `M171`'s claim.** The comment: *"when i click capture screen, the modal for the feedback is always included. i think there is a better way of doing that."* `resources/js/components/shell/FeedbackButton.vue` captures through the native Screen Capture API (`docs/adr/0015-feedback-screenshot-capture.md`) with the `MdsModal` still open.

- **A — the panel steps aside (recommended, chosen).** The smallest change; the native-capture decision of ADR-0015 stands and gains a sub-decision recording this sequence (close, wait for the leave transition, capture, reopen).
- **B — capture first, then the panel.** The Feedback button offers "Report a bug with a screenshot"; the capture happens before any panel exists. Two entry points to maintain.
- **C — draw the page without a picker.** A DOM-to-image render; re-opens ADR-0015 (the map and signature fields come out blank) and adds a dependency.

**What the answer files, in the same push:** `R-2ce2f274` (`during-testing`): the capture, the type tag and the page URL, one row.

### D126 — When does the What's new prompt stop showing? **A — any close marks it seen: Got it, the close control, Escape or a click outside all record the version; it never nags, and the notes stay a click away in the footer.**

**Asked and answered 2026-10-14 (user decision, in chat), recorded by Lane A in `M171`'s claim.** The prompt opens on the first page after sign-in when the user's last-seen version is older than the current one; a brand-new user sees only the latest entry.

- **A — any close marks it seen (recommended, chosen).**
- **B — only Got it marks it seen;** any other close shows it again on the next page load.
- **C — once per sign-in until Got it.**

**What the answer files, in the same push:** nothing new — it shapes `R-c7247dbf`.

### D125 — Where do the What's new notes live, and when is the prompt shown? **C — written with each change in a repository file and copied into a `releases` table by every deploy, so the database holds every release's version, date and changes; and the prompt opens right after logging in, on the first page the user lands on.**

**Asked and answered 2026-10-14 (user decision, in chat), recorded by Lane A in `M171`'s claim.** The user answered the source question with the trigger: *"i want the whats new to be seen right after logging in."* The recommended option already opened the prompt on the first page after sign-in, so that is recorded as the user's firm requirement — the post-login landing, before anything else — and the source stays the recommended repository file, which the user did not object to. ⚠️ If the user asks for an admin-editable screen instead, the build session records the change here before touching `R-c7247dbf`.

- **A — a file in the repository (recommended, chosen by default).** No admin screen, no table; a test refuses a release whose notes and version disagree.
- **B — an admin-editable table.** Platform admins write entries in the console; a new table and screens, and entries can drift from what shipped.
- **C — written with the change, saved by every deploy (chosen 2026-10-15).** Each change brings its notes in one repository file; an idempotent deploy step upserts them into a `releases` table by version and never deletes, so the database is the release history and what the app reads, and the test that refuses notes disagreeing with `VERSION` stays.

**Changed 2026-10-15 (user decision, in chat, at `M171`'s build start), when the build session confirmed the source as the entry above required.** The user: *"actually, i want it saved in the databse. so everybrelease, we know what changed, qhqt version it is etc"* — and, asked who writes each release's notes (written with the change and saved by every deploy / typed on a console screen / both), *"Written with the change"*. That is neither A (no table) nor B (typed by an admin), so it is recorded as C. The trigger half is unchanged: the prompt opens on the first page after sign-in.

**What the answer files, in the same push:** nothing new — it amends `R-c7247dbf`'s remedy (the table, the sync step in the deploy, the page reading the table); the half not built (the notification-centre feed) is `R-3f887956`.

### D124 — Which version scheme, and what is the first number shown to users? **A — a committed `VERSION` file, semantic: 0.9.0 now, a user-visible fix bumps the last number, a new feature the middle one, and launch is 1.0.0.**

**Asked and answered 2026-10-14 (user decision, in chat), recorded by Lane A in `M171`'s claim.** The request: *"versioning must also be included. lets put the current version in the app, footer and login so users are aware."* Today `config/app.php` reads `APP_VERSION` with the default `dev`, which is what the testing server's About panel shows.

- **A — 0.9.0 now, 1.0.0 at launch (recommended, chosen).** The version shows in the sidebar foot, a slim footer under every page, the sign-in card, the console bar and the landing page; not on respondents' form pages. A test refuses a release whose notes and version disagree.
- **B — 1.0.0 now.**
- **C — date-based (2026.10.14).** Two deploys on one day collide and nothing says how big a change was.

**What the answer files, in the same push:** `R-c7247dbf` (`during-testing`) and `R-3f887956` (`after-launch`).

### D123 — The UI programme is some twenty rows; `D13` batches three or four rows per increment, and the testing freeze says nothing merges without the user's word. How is it batched and merged? **A — six themed increments (the three complaints; full width — shell and settings; full width — detail pages, stacks and sign-in; feedback; versioning and What's new; consistency), each row its own commit, every PR held at 6/6 green until the user says merge, after a fresh staging backup.**

**Asked and answered 2026-10-14 (user decision, in chat), recorded by Lane A in `M171`'s claim.** A one-off widening of `D13`'s three-or-four-row batch for this programme only; `D15`'s file-overlap and one-hub rules hold inside each increment, and `D75`'s one-row-per-commit shape makes a red run bisect to one row. The freeze of 2026-10-13 is unchanged: a PR stops at green and waits.

- **A — themed increments, merge on the user's word (recommended, chosen).**
- **B — strict `D13` batches,** about ten increments.
- **C — merge each when green,** lifting the freeze for this work.

**What the answer files, in the same push:** nothing new — it orders the twenty rows filed by `M171`, in the plan at `~/.claude/plans/hi-let-s-conduct-a-mutable-goose.md`.

### D122 — The sign-in and register pages centre a 400px card and a pitch panel. How should they use a wide screen? **A — a two-pane sign-in: the pitch panel fills the left half on a sunken surface and the card sits centred in the right half; the seven utility pages keep the plain card; the landing page's feature grid fills.**

**Asked and answered 2026-10-14 (user decision, in chat), recorded by Lane A in `M171`'s claim.** `resources/js/Layouts/AuthLayout.vue` centres the frame and gives the split variant a 26rem panel beside the card.

- **A — two-pane sign-in (recommended, chosen).** The whole width is used without stretching a form.
- **B — keep the centred card.**
- **C — stretch the card** with the window.

**What the answer files, in the same push:** `R-3d51f0ae` (`during-testing`).

### D121 — The staff encode page, the OCR pages and the public form hold inputs; a 2,000px-wide answer box is hard to use. Do form-filling pages keep a reading-width column? **A — yes: the inputs stay in a comfortable column and the rest of the width carries the page's own side panel (form facts, progress, reference files, the scanned paper where there is one); respondents' public form pages are left as they are.**

**Asked and answered 2026-10-14 (user decision, in chat), recorded by Lane A in `M171`'s claim.**

- **A — keep a column, fill the rest (recommended, chosen).**
- **B — full width everywhere** on the encode and OCR pages; the public form unchanged.

**What the answer files, in the same push:** `R-66952d33` (`during-testing`); it also shapes `R-8ee7d2dc` and `R-14971e37`.

### D120 — Pages sit in a centred 1200px column (1600px for the list pages) with dead space either side of a wide screen. How far does "every page occupies the whole width" go — to reading text and short forms too? **A — fill with layout: no centred column anywhere; settings cards two or three across, detail pages gain a side column, stacks become card grids; only reading text keeps a measure, left-aligned beside its contents column, never centred.**

**Asked and answered 2026-10-14 (user decision, in chat), recorded by Lane A in `M171`'s claim.** The request: *"some pages only occupies the middle part of the page specially when screen is wide. can we make every page occupy the whole width? no content on the middle only. so no blank spaces on the left and right but still make it mobile responsive."* `resources/js/Layouts/AppLayout.vue`'s JR4 comment argues against an unbounded column because a row's title and its date drift to opposite edges of a 32-inch panel; the answer keeps that objection by changing the pages' own layouts rather than only the cap.

- **A — fill with layout (recommended, chosen).**
- **B — stretch everything,** text and single-input forms edge to edge.
- **C — lists and grids only;** settings, detail and prose pages keep the centred column.

**What the answer files, in the same push:** `R-fd7b8879`, `R-ba9d6349`, `R-8ee7d2dc`, `R-14971e37`, `R-348f997c`, `R-7b8a74ae`, `R-fb647b5e`, `R-10b38d6d` (all `during-testing`); `R-0a504679`, `R-88a6c81a` and `R-61992abf` are the user's three named complaints, taken by `M171`.

### D119 — A staff member who opens a colleague's saved draft on the encoding page ("Continue this draft") has every save refused. Should they be able to keep editing and saving it? **A — yes: anyone allowed to continue a draft can save into it; the draft keeps its original author and records who saved it last.**

**Asked and answered 2026-10-14 (user decision, in chat), filed and recorded by Lane A in `M170`**, while checking every autosave for the user's report (`R-ae57cf6f`). The inbox offers "Continue this draft" to anyone allowed to submit the draft (`SubmissionPolicy::promote()`, which has no author check), but the draft save looks the draft up by its author (`ClientUuidResolver::resolve()`, scoped that way since `M11`), so for anyone else every autosave is refused and saving stops with a red message.

- **A — yes: anyone allowed to continue a draft can save into it (recommended, chosen).** The draft keeps its original author and records who last saved it. It matches what the resume page was built for: a supervisor or a co-encoder finishing someone else's entry.
- **B — no: only the author continues a draft.** "Continue this draft" is hidden from everyone else, who can still open it read-only and submit it as it stands.

**What the answer files, in the same push:** nothing new — `R-ae57cf6f` (filed in the same increment) is the work, and its Awaits token is stripped.

### D118 — Someone filling a public form closes it, then opens the link again on the same phone or computer. It starts blank, because the form keeps answers only for the same tab. Should their answers come back? **A — yes, after asking: "Continue where you left off" or "Start over", shown before any answer appears.**

**Asked and answered 2026-10-14 (user decision, in chat), recorded by Lane A in `M170`.** Asked while checking every autosave for the user's report *"the autosave feature is not working on other clients"*. Since `M21` the guest autosave restores answers only in the same tab and visit (`resources/public-runtime/composables/useAutosave.ts`), on purpose, so the next person at a shared device never sees them.

- **A — yes, ask first (recommended, chosen).** The cost, accepted: on a shared phone or a kiosk, the next person could press Continue and see the answers. A kiosk mode is where to switch it off.
- **B — no, same tab only (as built).** Only the defect where a long pause loses the answers on a reload is fixed.
- **C — only through "Save and finish later".** The emailed link, which each form has to switch on.

**What the answer files, in the same push:** a `during-testing` row in `docs/feature-backlog.md` (`R-8881210a`, *reopening a public form's link on the same device starts a blank form*).
### D117 — A scan saw writing it could not turn into the question's type — a weight written "72.", a date whose year was read as "190" — and the review screens left the box empty with "Needs manual entry … It saw …". Should the box be filled with the nearest answer it can hold? **A — yes: every question the scan saw anything in is filled with the nearest answer its box can hold, and marked that it may be wrong.**

**Asked and answered 2026-10-13 (user decision, in chat), recorded by Lane A in `M167`'s claim.** The comment, with a screenshot of the staff review of Round 1's sheet 01: *"previously, i commented on the ocr reader that it whenever a value is read even if the confidence is low, it must be reflected in the field and just notify that it may be wrong. and i also tested that and it is functioning well before. but now i tried testing an upload now and its back to not reflecting the read value and just telling what is read."* Not a regression of `D108`: in the same screenshot Full name (66%) and Email (46%) are filled in and marked. Weight and Date of visit took the other path — the reader could not make the text into a number or a date, so it returned `unreadable` with no value, which `D108` never touched. The question put: the date boxes read 12 / 11 / 190 — fill it as read (11/12/0190) and mark it, or leave it empty with the note. The answer widened it to every type: *"fill as read and let the user correct it. its better than the user will type everyhting. also for the decimal. fill it in and let the user correct it. actually all the fields must be fields based on what is read and let the user correct it. its always better to have an initial value rather than user will type everything from 0."*

- **A — fill in the nearest answer the box can hold, marked (recommended for the date, chosen, and widened by the user to every type).** A number keeps the number it found ("72 ." → 72, "50 kg" → 50); a date or a time keeps what was read and is brought to the nearest one that exists (a year read short is kept as read, 0190); a word or a choice takes the nearest option. The note quotes what was seen and says it may be wrong. Only a question where nothing usable was seen stays empty. It extends `D108` to the `unreadable` path and reverses `M148`'s "refused for a person to read, never guessed" for a comb group read with one character too many.
- **B — leave a value the type cannot hold empty and quote what was seen (as built).**

**What the answer files, in the same push:** a `during-testing` row in `docs/feature-backlog.md` (*a scanned answer the reader could not turn into its question's type is left empty*), taken at once by `M167`.

### D116 — `M165` lets a guest send a photo or scan of a filled paper copy from the public link, and the guest never sees what was read: staff check every answer against the photo. Should the guest be able to check and correct what was read? **A — yes: the guest checks the read answers in the form, corrects them, and submits them as their own response, with the photos attached.**

**Asked and answered 2026-10-13 (user decision, in chat), recorded by Lane A after `M165`'s close-out.** The user asked: *"when the guest user uploads the scanned copy, there is no way for them to validate and correct incase there is discrepancy in the results?"* — correct as built, by `D106`'s second answer (*the guest never sees what was read*). Round 1 measured about a third of fields needing a correction (`docs/ocr-pipeline-design.md` §9), and the person who wrote the paper knows the answers best.

- **A — the guest checks, then submits (recommended, chosen).** After sending, the public page waits while the pages are read (usually under a minute), then shows the form already filled in, each unsure answer marked to be checked. The guest corrects what is wrong and presses Submit, and it is saved as their response, with the scanned pages attached. Staff no longer key it in. It amends `D106`'s second answer: the guest now sees what was read — their own paper — before submitting.
- **B — the guest checks, staff still confirm.** The same screen, but the corrected answers wait in Recent scans for a staff member to approve.
- **C — staff only, as built.**

**What the answer files, in the same push:** a `during-testing` row in `docs/feature-backlog.md` (*a guest who sends a paper copy cannot check or correct what was read*), which owns the build questions it answers at claim time: what the guest sees while the pages are read or if reading fails, how an answer the scan could not read is shown, and what the staff scans list shows once a guest has submitted.

### D115 — The user saw `M162`'s Help page and asked for more: "a user manual for the form builder, each input type and its settings/configurations. the left and right panels". Where does that reference go, does it show the panels, and when does it go live? **A, A, A — a second part of the same Help page ("Builder reference"), with one picture of each panel and numbered callouts, live as soon as it is ready.**

**Asked and answered 2026-10-12 (user decision, in chat), recorded by Lane A in `M163`'s claim.** The comment: *"i already saw the help page. but i am expecting a user manual for the form builder, each input type and its settings/configurations. the left and right panels"*. Three questions were put together, each with a recommendation, and all three were answered as recommended.

- **Where — A, a second part of the Help page (recommended, chosen).** The contents split into "Step by step" (what `M162` shipped) and "Builder reference" (the left panel, the right panel, then one entry per question type); one page, searched with the browser's find. **B** — its own page linked from Help.
- **Pictures — A, one of each panel with numbered callouts (recommended, chosen),** captured by a Playwright script from the seeded E2E stack so retaking them is one command. **B** — text only. This narrows `D114`'s "no screenshots" to the two panels; `R-5cde8465` keeps the rest.
- **When — A, as soon as it is ready (recommended, chosen).** It only adds to the Help page, so it cannot break what testers use; it goes live in or out of order with `M158`–`M161`. **B** — after those four.
- **When — AMENDED 2026-10-12 (user, in chat): after today's building session, merged tonight.** Asked again once it was ready, with what a merge costs stated: every merge deploys, and a deploy's maintenance window (about 8 seconds) can fail a tester's builder save. Testers build forms on staging on Oct 12, so nothing merges while they do; `M163` merges tonight after the OCR session's `M158`–`M161`, which the user answered the same way in that session ("Tonight, after building").
- **When — AMENDED AGAIN 2026-10-12 (user, in chat, after nobody was editing built forms): M163 first, now.** The user told the OCR session to wait for this one and answered "Yes, M163 first, now" here; `M163` merged after a fresh staging backup (`meridian-test-db-backup`, `exit=0`) and before `M158`–`M161`, each merge asked of the user first and no two deploys overlapping.

**What the answer files, in the same push:** `R-ccc0be00` (`early-testing`), which `M163` takes. `R-5cde8465` is narrowed in place, not taken. `D109`'s freeze, if the Oct 12 session has not started when `M163` is ready, takes this one exception.

### D114 — Testers start on Oct 12 and the app has no guide to creating a form; the user asked for a user manual inside the app, on a page of its own. `D109` holds every merge until the Oct 12 session. When does it go live, and what does it cover? **A — tonight, before the session, as a one-off exception to `D109`: a Help page on creating a form from start to finish, with no screenshots.**

**Asked and answered 2026-10-11 (user decision, in chat), recorded by Lane A in `M162`'s claim.** The request: *"create a user manual for the form builder to help our user/tester undrsdatnd how to create form. if you can include it in the app itself a separate page to access the manual"*. Three questions were put together, each with a recommendation, and all three were answered as recommended.

- **When — A, tonight, a one-off exception to `D109` (recommended, chosen).** A new page and one sidebar item; nothing existing changes. **B** — first thing once the session starts, before the OCR fixes. **C** — after `M158` to `M161`, a few hours into testing.
- **Scope — creating a form, start to finish (recommended, chosen):** starting a form, the builder, a question's settings, logic, the preview, form settings, publishing, sharing, and seeing and exporting responses, with a "who can do what" chapter and a problems chapter. The other options were the builder alone, or everything a tester might touch; review, paper scanning, members and automations get a pointer each.
- **Screenshots — none in v1 (recommended, chosen).** Each step names its on-screen label in bold, and a test checks each label against the file that draws it. The other option was real screenshots, retaken whenever a screen moves; filed as `R-5cde8465`.

**A recorded deviation, told to the user in plain words before the build:** `M162` is built in a second worktree (`C:\laragon\www\fb-help`, with its own Docker stack `fb-help`) while the OCR session holds the main checkout — against `docs/adr/0022-single-lane-development.md`'s one worktree and one writer, for this one increment. Both sessions write `lane-a.md`, this file, `PROGRESS.md` and the end of `docs/feature-backlog.md`; each rebases before it pushes, keeps the other's prose, and regenerates the generated files rather than merging them.

**What the answer files, in the same push:** `R-f9211765` (`early-testing`), which `M162` takes; `R-5cde8465`, `R-248174d6` and `R-f7366ac8` (`during-testing`). `D109` is otherwise unchanged: nothing else merges until the Oct 12 session starts.

### D113 — `M159` measured the ink reader on Round 1 — all 21 tick questions Vision left blank read right, with the settings tuned on those same fifteen forms — and `D112` put the server change to the user before it is built. Should testers' scans be read with ink during the testing session, which adds `gd` to the testing server's PHP? **A — yes: `M160` puts the ink on the reading path, merged after `M158` and `M159`, and the user enables `gd` on the server, guided in chat.**

**Asked and answered 2026-10-11 (user decision, in chat), recorded by Lane A in `M160`'s claim extension.** Asked with the measurement and its caveat stated: tuned and scored on the same forms. The testing server runs PHP for Windows directly (`C:\php`), so the change is one line in its `php.ini` (`extension=gd`, a file the PHP build already ships) and a restart of its php-cgi services and its worker; `docs/deployment-infrastructure.md` §8 step 1 said "not `gd`", which `M160` amends.

- **A — yes, turn it on (recommended, chosen).** Every ink answer is filled in and marked to check against the paper, so a wrong one is visible, never silent; testers stop typing about half of all ticks.
- **B — test on fresh sheets first.** The user photographs three to five new filled sheets, the harness scores them, then this is asked again.
- **C — not during testing.** The ink stays in the harness until the session ends.

**What the answer files, in the same push:** nothing new — `ocr-ink-live` (`during-testing`) is taken as `M160`; its marker closes on `M160`'s branch.

**Amended 2026-10-12 (`M160`'s close-out, after an outage on the testing day):** `gd` does NOT go in the testing server's `php.ini`. With opcache on, it made every php-cgi request a PHP fatal from the restart until the file was restored from its backup. It is the OCR worker's own flag instead — NSSM `AppParameters` start `-d extension=gd` — and the website never loads it. The answer itself, ink on during testing, stands.

### D112 — Vision returns no mark at all for most ticks standing alone in a box (20 of the 22 answered tick questions Round 1 reads blank, measured by `M158`), and `D104` A queued Claude through the Anthropic API to read them, which is billed apart from a Claude subscription. How are the ticks Vision never returns read? **B — measure the ink inside each printed box: free, no second provider, scored in the bake-off before it goes live.**

**Asked and answered 2026-10-11 (user decision, in chat), recorded by Lane A in `M158`'s claim.** The user asked why the ticks on sample sheet 08 are not read *"even though it has value in the paper"*, then whether the Claude arm is covered by their subscription (it is not), then chose: *"as much as possible, we will choose the free one. the measure ink in the box. is that reliable?"*. Measured the same session from Round 1's cached reads and photos, read-only: a crude window before each option's label, darkest box wins, picks the right box on 25 of 30 single-answer and yes/no questions and on 16 of the 17 that Vision left blank (sheet 08's Male and Yes among them); every miss had its window on bare paper, which a box-finder can detect and leave blank. A multi-select is harder (57 of 76 boxes in the crude form). The agency's consent to send the fifteen made-up samples to Anthropic was given in the same session and is unused. `D104` is amended, not reversed: the vision-language arm is set aside, kept as the fallback.

- **A — Claude reads the page (`D104` A as answered).** Likely the most accurate on phone photos; needs API credit paid separately (about US$2–4 a Round 1 run, about US$0.10 a two-page form live) and the agency's consent before a real scan is sent.
- **B — measure the ink in each printed box (chosen).** Free, and no page leaves for a second provider. The box is found from where Vision places the option's label; the server image needs `gd` to run it live.
- **C — neither for now.** Reviewers key every blank tick, as today.

**The bar, part of the answer:** the ink reader goes live only when, on Round 1's fifteen forms, it answers at least 9 in 10 of the tick questions Vision left blank correctly, and every ink answer lands in the review band (never auto). Adding `gd` to the app image is put to the user in chat before it is built.

**What the answer files, in the same push:** `ocr-ink-ticks` (`during-testing`, ready — the arm in `ocr:bakeoff`, `M159`) and `ocr-ink-live` (`during-testing`, blocked on that measurement), both at the end of `docs/ocr-pipeline-design.md`; `ocr-vlm-arm`'s blocker now names this decision; `R-a21fa6e8`'s remedy is retargeted to the ink rows.

### D111 — Sample sheet 08 scanned on staging showed Sex and Vaccinated empty though both were ticked: Vision never returned those two marks, and the review screen calls such a question "Left blank on the paper". `D109` holds every merge until the Oct 12 session. When does the note's fix go live? **A — today, before the session, as a one-off exception to `D109`.**

**Asked and answered 2026-10-11 (user decision, in chat), recorded by Lane A in `M157`'s claim.** The user's report: *"i uploaded the no 8 oct. and the sex and vaccinated is not read even though it has a response on the paper"*. Measured from the same photos' cached Vision answer: no mark symbol was returned for either box, and Round 1 reads 21 of 45 tick questions as blank though answered. Reading those ticks is `ocr-vlm-arm`'s (`D104` A, not re-asked); the false note is `R-d2467239`.

- **A — today, a one-off exception to the freeze (recommended, chosen).** One note on the review screen; testers otherwise meet a false "Left blank on the paper" on about half of their tick questions. The freeze resumes once it is deployed.
- **B — build it today, merge once the session starts.** The freeze holds; testers see the false note until the fix lands mid-session.
- **C — later in testing.** Filed with the during-testing tier.

**What the answer files, in the same push:** `R-d2467239` (`early-testing`), which `M157` takes. `D109` is otherwise unchanged: nothing else merges until the Oct 12 session starts.

### D110 — The PRD says a low-confidence scan answer "must be individually confirmed", and the review screen has never asked for that: Save confirms the whole form. Now that such answers are filled in and marked "may be wrong" (`D108`), does each one need its own confirmation? **A — no: Save confirms the whole form, and the PRD line is amended to say so.**

**Filed 2026-10-10 by Lane A in `M156`, found while amending the documents `D108` changes; asked and answered in the same session (user decision, in chat — A, the recommendation).** `docs/PRD.md`'s Feature #1 review-screen line asks for *"a visible low-confidence indicator (default threshold: below 70%) that must be individually confirmed"*. Since `M129` the review screen (`Pages/submissions/Encode.vue` in scan mode) shows every note and saves the whole form on one Save; nothing confirms a field on its own, and no row recorded the gap. Under `D108` a wrong value below 70 sits in its box, marked, until someone saves.

- **A — No: Save confirms the whole form (recommended).** The PRD line is amended to say so; each "may be wrong" mark stays beside its answer until the form is saved. Matches the user's reason for `D108`: *"users dont want so much clicks"*.
- **B — Yes: a tick per marked answer.** Each answer marked "may be wrong" needs an "I checked this" tick before Save is enabled. Guards against clicking through; one more click per marked answer (Round 1: about six per form).
- **C — One question at Save.** Save says how many answers are still marked "may be wrong" and asks once to save anyway. One click, and the count is named.

**What the answer files, in the same push:** nothing new. `docs/PRD.md`'s review-screen line is amended in `M156` itself, line for line: a low-confidence value is filled in and marked, and the one Save confirms the whole form.

### D109 — `M156` puts the OCR fill-in on the testing server two days before the Oct 12 session, and every merge deploys there. Does anything else merge before the session? **A — no: freeze once `M156` is merged and deployed, until the Oct 12 session.**

**Asked and answered 2026-10-10 (user decision, in chat), recorded by Lane A in `M156`'s claim.** `D90` froze everything but fixes until the session; `D94` lifted that for non-OCR work from 2026-10-06, so without a new answer the during-testing tier would keep merging — and deploying — up to and through the session. The user had said "then freeze" earlier the same day, before `M155`'s batch was chosen, so it was asked again rather than assumed.

- **A — freeze after `M156` (recommended, chosen).** Nothing else merges until the Oct 12 session starts; testers meet the app `M156` leaves on the testing server. The next session after it takes the during-testing tier from `docs/pipeline.md` as usual.
- **B — keep merging fixes.** The during-testing tier continues; each fix deploys to the testing server as it lands, before and during the session.

**What the answer files:** nothing new. It is a pause, not work: no row is held by it, and it expires when the Oct 12 session starts.

### D108 — The review screen leaves an answer read below the review threshold (70) empty and quotes what was read in a note under the box. The user asks for the text in the box. How is a low-confidence answer shown? **B, widened to every type — fill it in, with its confidence and a line that it may be wrong; before the Oct 12 session.**

**Asked and answered 2026-10-10 (user decision, in chat, in a status-only session that made no repo edits), recorded by Lane A in `M156`'s claim.** The user's comment on the staging scan: *"on the text box, it can read properly the remarks but did not put it in the text area. it is just below the text area. can we put the actual text that has been read since it is too close and user can still edit the form and can correct them."* `PrintedFormMatcher::result()` nulls the value of a `manual`-tier read; that is `docs/ocr-pipeline-design.md` §3's rule (*"a wrong auto-fill silently accepted by a reviewer clicking through quickly is worse than an empty, obviously-incomplete field"*), confirmed by `docs/adr/0010-ocr-provider-cloud-vision.md` §D3. Round 1 read 35 right and 25 wrong below 70.

- **A — a "Use this text" button beside the note (recommended).** The box stays empty; one click puts the read text in it. Keeps §3's rule that nothing below 70 is pre-filled.
- **B — fill it in, marked, for written answers only.** A text answer below 70 goes in the box with a note that it may be wrong; choices and numbers stay empty.
- **C — keep it as it is.**

**The answer: B, widened to every type.** The user's words: *"just put the value and allow them to know that it may not be accurate … but still put the value in the field"*, and on A, *"users dont want so much clicks"*. Every answer read below the review threshold is filled in with its confidence and a line saying it may be wrong; an unreadable answer (nothing valid to fill, such as letters in a number) keeps its note. The review screen stays mandatory before anything is saved (§3). Corrected by the user the same day: it lands **before** the Oct 12 session (*"my oct request must be before the oct 12"*). Guest paper-copy upload (`D106`) stays during-testing.

**What the answer files, in the same push:** `R-51366b2e` (`early-testing`), which `M156` takes: the matcher keeps the value, the review note says it may be wrong, the bake-off scorer stops counting a withheld value (a wrong one is `wrong_flagged`), and §3 and ADR-0010 §D3 are amended by this decision.

### D107 — An archived form's own page is made read-only (`R-aaf36122`). A field device may still hold responses it took offline before the archive. Are those accepted when it syncs? **A — yes: an archived form keeps accepting them; only the page stops offering ways to add a response.**

**Asked and answered 2026-10-10 (user decision, in chat), recorded by Lane A in `M155`'s claim.** `R-aaf36122`'s row asks it first at claim time. One permission, `SubmissionPolicy::create()`, admits an archived form (it checks only for a published version), and the same check gates the offline sync API (`SyncSubmissionController`), staff drafts and attachment uploads — so refusing an archived form there would refuse a tablet's queued responses too. `M154` masked the Forms list's row for the same reason.

- **A — keep accepting (recommended, chosen).** `SubmissionPolicy::create()` is unchanged; queued offline responses still arrive and nothing collected in the field is lost. The form's page, its tab strip and its Responses page stop offering New response, Scan paper forms, Edit form, Share, Builder and Settings.
- **B — refuse everything.** An archived form takes no response from anywhere; a tablet's queued responses are refused and stay on the device.
- **C — accept sync, refuse staff entry.** Split the one permission so the offline sync path admits an archived form and the staff paths do not — more work and more risk two days before testing.

**What the answer files:** nothing new — it is the shape of `R-aaf36122`'s remedy, which `M155` takes.

### D106 — Can a guest respondent upload a photo or scan of a paper form they filled in, rather than only staff? **A — yes, during testing: an "upload a filled paper copy" option on the form's public link, switched on per form, landing in the staff scans list for the mandatory review.**

**Asked and answered 2026-10-09 (user decision, in chat), recorded by Lane A in `M152`'s close-out.** The user asked, during the staging scan, *"is there a page where guest respondents can upload their scanned copy or photos of the form?"* There is not: "Scan paper forms" is on a form's Responses tab behind the manual-encoding gate (`can:create` a submission, `module:ocr_single`, `feature:ocr_single`, `routes/tenant.php`), and neither the PRD nor `docs/ocr-pipeline-design.md` plans a guest path.

- **A — yes, during testing (recommended, chosen).** The public link offers "Upload a filled paper copy" where the form's Scanning switch and a new per-form guest-upload switch are both on. The pages go through the existing upload checks (type, size, virus scan, storage quota) under a guest rate limit, are read by the same job, and wait in the staff scans list; a scan becomes a response only when a staff reviewer saves it (`docs/ocr-pipeline-design.md` §3's mandatory review, unchanged). Built after the Oct 12 session starts (`D90`).
- **B — yes, before launch.** The same shape, queued after the testing period.
- **C — no.** Scanning stays staff-only.

**What the answer files, in the same push:** a `during-testing` row in `docs/feature-backlog.md` (*a guest respondent cannot upload a photo or scan of a filled paper form*), which owns the open questions the build answers first: who sees an unsaved guest scan, what the respondent is told after uploading, and how the public endpoint is kept from spending the provider's pages.

**The three build questions, answered 2026-10-12 (user decision, in chat), recorded by Lane A in `M165`'s claim:**
1. **Who sees an unsaved guest scan — the same staff as now.** Anyone who may scan for the form (`can:create` a submission for it) sees it in "Recent scans", marked "From the public link". No new notification.
2. **What the guest is told — a thank-you and a reference.** *"Your paper form was received. Staff will check it and enter your answers."* with a reference code. The guest never sees what was read.
3. **The spend guard — the anti-bot check plus limits the form's owner can set.** The proof-of-work challenge on every guest upload, whatever the form's own spam-check setting; a per-device window (3 uploads per device per window; 10 minutes by default, settable shorter); and a daily cap of guest scans per form (20 by default, settable). Over a limit the upload is refused politely. The user's words: *"anti-bot + limits but limits can be configurable. default is 10 minutes. it can be configured shorter time"*.

### D105 — In Preview a stepped form shows one section's page at a time, so the sections a dragged question could join are off screen. How does a drag in Preview reach them? **A — while a question is dragged, the page strip's tabs are drop targets: a drop on one moves it to the end of that page, and the preview stays on the page it was on.**

**Asked and answered 2026-10-09 (user decision, in chat), recorded by Lane A in `M151`'s claim.** `R-2baef8ea` (*"please include the preview section to be draggable. i mean, questions or indicators must be draggable to sections, sequencing, etc."*) asks for a question to be dragged into a section, and in a stepped form every section but the one on screen is behind the strip.

- **A — the strip's tabs are drop targets (recommended, chosen).** Within the page shown a drag reorders; while a drag is on, the strip offers each page as a target, and a drop puts the question at the end of that page — the end of its section unless page breaks split it — without leaving the page the author was on. In the single-page view every visible section, its *Add a question to …* area and an empty section's placeholder are targets.
- **B — reorder within the page shown only.** Moving to another section stays in Structure or the single-page view. The smallest and safest for Oct 12.
- **C — a tab flips the page while a question hovers over it,** so the question can be dropped at an exact place. The most natural and the most likely to slip past Oct 12.

Whole sections are still moved in Structure only. **What the answer files:** nothing new — it is the shape of `R-2baef8ea`'s remedy, which `M151` takes.

### D104 — Cloud Vision alone misses G9 on the first real round of samples, even with the reader's three defects fixed (`M149`), and `D97` says a vision-language arm comes next. What does single-form OCR do before and after the Oct 12 testing? **A — Cloud Vision as it is for the Oct 12 testing; the vision-language arm is built and measured during testing, and reaches staging only if it meets G9.**

**Answered 2026-10-08 (user decision, in chat) — A, the recommendation; recorded by Lane A in `M150`'s claim.** The `M149` session asked it before it closed; the answer arrived in the next session, before any row it blocked was opened. **What the answer files, in the same push:** `ocr-provider-bakeoff` drops `decision=D104` and is `ready` — its remaining work is ADR-0010 recording A (Cloud Vision for the testing, 90/70 confirmed by Round 1, the arm measured during testing), taken with `R-39a5388f`; a new `during-testing` marker `ocr-vlm-arm` (`docs/ocr-pipeline-design.md`, end of file) holds the arm itself, blocked on the user for an Anthropic API key and the agency's consent to send scanned pages to a second provider; and `R-a21fa6e8` (ticks Vision never returns) is retiered `during-testing`, because its remedy is that arm. Until it ships, reviewers key a blank tick on the mandatory review screen.

*Filed 2026-10-08 by `M149`, and asked in the same session (`D102`), from `ocr-provider-bakeoff`, whose remaining work is this choice.* Fifteen hand-filled layout-4 forms, both pages photographed with a phone: with the three `major`s fixed and the handwriting hint on, 92 of 180 fields (51.1%) still need correction against G9's bar of 15%, with no silent error. Ticks are 39 of the 92 — Vision returns no character for half of them (22 of 45 answered choices read blank) and a low confidence for most of the rest; the other 53 are handwriting misreads and right answers withheld in names, emails, phones and remarks. The thresholds hold: at confidence 90 and above, 57 right and none wrong. A vision-language model reads ticks and handwriting together and can be held to the printed options, but it is a second provider that receives every scanned page.

- **A — Cloud Vision as it is for the Oct 12 testing; the vision-language arm during testing.** The `M149` fixes ship; reviewers key what is withheld (the review screen is mandatory in any case). The arm — Claude through the Anthropic API, its output held to the printed options, behind the reviewer — is built into the harness and measured on these same fifteen forms, and reaches staging only if it meets G9. It needs an Anthropic API key and the agency's consent to send scans to a second provider.
- **B — tick detection first, with no new provider.** Measure the ink inside each printed box from the photo itself, and keep Vision for the writing. If every tick were read, about 29% would still need correction, so G9 stays missed on handwriting and a vision-language arm likely follows anyway.
- **C — the vision-language arm before Oct 12.** Four days for a new provider, a new key and consent: the most G9 can gain, at the most risk to the testing date.

**Recommendation: A.** Testers get a reader that works on phone photos now, every withheld field is keyed by a person anyway, and the arm is measured on paper that already exists before anything reaches staging. B is the fallback if a second provider is not allowed.

### D103 — The staging smoke test's Round 2 passed seven of ten, and four builder passes came with a request for something new. Which go in before the Oct 12 testing, and what happens to the respondent email and the archived forms? **All four builder requests before Oct 12; the respondent email stays after Oct 12; the Forms list gains an Archived filter during testing.**

**Asked and answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M148`.** The user marked all ten Round 2 checks on the smoke-test page. `r2-print` passed with a comment on the date's separators, which `M148` fixes as layout 4 (the user left the glyph to Claude: *"which ever will be applicable and readable for the OCR"*). Four builder notes asked for something new, and two questions asked whether something exists.

- **Before Oct 12 — the options were each request on its own; the recommendation was the two small ones (the handle with a smooth drag, and label editing in Structure), with label editing and dragging in Preview left for during testing. The user chose all four.** Filed `early-testing`: Structure's drag handle, a drag that glides, a label edited in Structure, a label edited in Preview, and a question dragged in Preview — plus the handle's screen-reader text, which rides with them by file. `M149` takes the Structure rows and `M150` the Preview rows; dragging in Preview is the one most at risk, and the Oct 9 checkpoint names it if it slips.
- **The respondent email — A, keep it after Oct 12 (recommended), or B, build it now.** A: `R-1ceb198f` stays `during-testing` under `D93`'s order, and a note at the row says so. The user's question (*"what if i want to automate that i will send an email to the responder of the form?"*) was answered in chat: it cannot be done yet; *Send to a web address* into Zapier or Power Automate is the workaround.
- **Archived forms — A, a read-only Archived filter on the Forms list (recommended); B, leave it, since the responses are reachable through Submissions; C, a filter and a Restore button.** A, filed `during-testing`. Still no un-archive — `D101` stands. The user's question (*"how can i retrieve the responses from that archived form?"*) was answered in chat: Submissions → Form → the archived form → Export.

### D102 — Should a decision be filed silently and asked only when the tier being worked is blocked on it (`D66`), or asked in the session that files it? **Asked in the session that files it — by name, in plain words, with its options and a recommendation; one left unanswered is asked first by the next session.**

**Answered 2026-10-08 (user direction, in chat), recorded by Lane A during `M147`; it supersedes `D66`.** The user, after an interrupted session: *"all open decision that may block the upcoming tasks must be addressed already, no task will be left behind so we will not produce more blocked tasks and more tasks to be left behine. we are going forward. we dont want to work backward here."*

**What was measured first.** Thirty open — eleven `during-testing`, eight `before-launch`, eleven `after-launch`; fourteen internal and sixteen product. Three blocked `during-testing` work rows (`D27` twice, `D29`) and four blocked `after-launch` rows; none blocked the OCR bake-off, the testing server or a builder comment. Under `D66` none of them had been asked, because none blocked the tier being worked. Offered three ways to clear them, the user chose to accept every recommendation in one answer, and answered `D39` and `D40` — which carry none — on their own.

- **What changes:** `CLAUDE.md`'s *Taking a row* imperative, the sentence `scripts/next.php` writes into the hand-off, and PROGRESS.md Standing Rule 7. Recording an answer files the work its option creates, with a tier, in the same push — "accept the recommendation" created eleven rows, markers or notes here.
- **What does not:** never stall on a question; never re-ask an answered one; never read the board at the user — ask the questions themselves, a few at a time.
- **The companion rule, from the same session:** a fix to a user's comment is announced by name with the clicks to check it (`CLAUDE.md`, *Closing out*). Layout 3 fixed the user's Print blank comment on 2026-10-07 and three increments closed without saying so.

### D55 — `D31`'s invitation-only stance is enforced by one runtime toggle. Should it also be lockable from configuration? **A — leave it as a runtime toggle.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — A — leave it as a runtime toggle, the recommendation.** Nothing further is filed, as the entry says.

**Filed 2026-09-19 by `M103`, which closed the accidental path and will not decide the deliberate one.**
`D31` is recorded as **applied, not built** — *"`SettingKey::RegistrationOpenSignup` defaults to `true` …
The switch lives in the super-admin console; nothing here needs code."* So the whole of invitation-only is
one row in `settings`, and `M103` found that a console tab opened before that row existed silently wrote the
default back, re-opening public registration with a success toast. ⚠️ **That accidental path is now closed**
by an optimistic-concurrency token, so this decision blocks nothing and is not urgent. What remains is the
deliberate path and the fresh-install path: the key still defaults to `true`, so any new deployment is open
until somebody remembers, and any operator can still turn it on in two clicks with no second factor beyond
the console's own.

- **A — leave it as a runtime toggle, now that the accidental revert is fixed.** The token makes a stale tab
  refuse rather than overwrite, the audit log records every deliberate change, and `staging.pitahc.gov.ph`
  keeps one source of truth for the setting. Costs nothing and adds no second place to look.
- **B — add a configuration lock for the testing site.** An env key that makes Open signup un-settable, with
  the console rendering the switch disabled and explaining why. Genuinely closes the deliberate path, but it
  is a SECOND source of truth for one setting — the shape `docs/gate-baselines.md` exists to prevent
  elsewhere in this repository — and an operator who needs it off in a hurry now has to edit `.env` and
  `config:cache` on the box.
- **C — invert the default.** Ship `RegistrationOpenSignup` defaulting to `false`, so a fresh install is
  closed until somebody opens it. ⚠️ This is the one option with a blast radius beyond the testing server:
  the table is SPARSE, so "absent" is the state of every existing deployment, and flipping the default
  changes their behaviour on deploy — precisely the argument `SettingKey::default()`'s own docblock makes
  for why `SecurityRequireTwoFactor` must NOT default true.

**Recommendation: A, and file nothing further.** The measured defect was the accidental revert, and it is
fixed; B trades a real single source of truth for a hypothetical operator error that the audit log already
attributes; C is the fail-safe reading in the abstract but the enum's own docblock argues persuasively
against changing a sparse-table default under existing installs. ⚠️ If the answer is B, scope it to a
deployment-level lock and say in `docs/deployment-infrastructure.md` §8.2 that the console switch is
advisory on that host — a disabled control with no stated reason is worse than no control.

### D50 — Should DICT be asked to publish DKIM and DMARC records for `pitahc.gov.ph`? **B, then A if the headers show alignment is the cause.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — B, then A if the headers show alignment is the cause, the recommendation.** The DMARC row in `docs/feature-backlog.md` already prescribes the header read first and now records the answer; telling testers to look in spam is filed as its own `during-testing` row.

**Filed 2026-09-17 by `M98`, while checking why an invitation was spam-foldered.** An emailed invitation is the only
door to an account while the testing server is invitation-only (D31), and the operator reports invitations arriving
in the spam folder. What is measured, read with `nslookup`: `pitahc.gov.ph` publishes an SPF record ending `~all`
and **no `_dmarc` record at all**, so there is no DMARC policy and no aligned DKIM signature for one to point at.
The same gap lets anyone send invitation-looking mail *"from"* the domain without it being rejected. What is not
measured: which `From` address the invitation actually used, and what the receiving side's own authentication
results said. D46 records that the user will request no new records from DICT; INFERRED from its context, that was
said about the site's address records, so this is a separate question rather than one already refused. The tier is
before-launch because what this decision buys is a DICT request and a Google Workspace change rather than anything
a tester touches — the tester-facing half is a checklist line, and it needs no answer.

- **A — ask now.** Turn DKIM on in Google Admin for `pitahc.gov.ph` and have DICT publish the `google._domainkey`
  TXT record, plus `_dmarc.pitahc.gov.ph` as `v=DMARC1; p=none; rua=mailto:<agency mailbox>`, tightening to
  quarantine later. It is the durable fix and it also ends the spoofing exposure; it costs a DICT request and a
  Workspace change before anyone knows whether authentication is what moved the mail.
- **B — read one spam-foldered invitation's headers first.** Open the message, use Show original, and read
  `Authentication-Results` for spf, dkim and dmarc together with the real `From` and the envelope sender. Free, and
  it separates a checklist problem — sending from a personal Gmail address, or a `From` that differs from the
  authenticated account — from a DNS problem, before anyone asks DICT for anything.
- **C — no, not through testing.** Accept spam placement, tell testers in the checklist to look in spam and mark
  the invitation *Not spam*, and revisit the question before launch.

**Recommendation: B, then A if the headers show that alignment is the cause.** One header read costs nothing and
decides which of the other two options is right. INFERRED, from mail-standards knowledge rather than a tested
receiver: Google's sender rules make DMARC mandatory only for bulk senders, so a missing DMARC record alone may not
be what moved a low-volume invitation to spam — which is precisely why the free measurement comes first. Either way
the checklist should already tell testers to look in the spam folder, and that does not wait on this answer.

### D39 — What will the product be called? "Meridian" is a working codename. **B — choose a name before launch, then rename everywhere in one pass.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — B — choose a name before launch, then rename everywhere in one pass.** Asked on its own, because the entry carries no recommendation. "Meridian" stays through testing. The choice is the user's, queued as the `product-name` marker in `docs/PRD.md` (blocked on the user, `before-launch`), and the PRD's note and §9.4 now say so.

**Filed 2026-09-14 by `M93`, from Decision Board card `product-name` and `docs/PRD.md` §9.4.** The name appears
across the app, its mail and its documents, and testers will see "Meridian" until this is settled.

- **A — keep Meridian as the real name**, after checking it is free to use for this kind of product.
- **B — choose a name before launch**, then rename everywhere in one pass.

**No recommendation** — a product's name is its owner's call.

### D40 — Who is the first pilot customer? **B — decide after internal testing.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — B — decide after internal testing.** Asked on its own, because the recommendation presumes a candidate. Queued as the `pilot-customer` marker in `docs/PRD.md` (blocked on the user, `before-launch`); the no-name rule below holds until the repository is private.

**Filed 2026-09-14 by `M93`, from Decision Board card `pilot-customer` and `docs/PRD.md` §9.4.** No pilot is
named. One early real user makes testing and launch planning far more reliable, and the PRD says so.

- **A — name one now**, so testing can focus on their forms and workflows.
- **B — decide after internal testing.**

**Recommendation: A**, if a candidate exists. ⛔ **Record the answer here WITHOUT the customer's name.** This
repository is public, and `D6` already decided that a named client does not belong in it; the name stays in the
Board's note.
⚠️ **Annotated 2026-09-17 during `M98`, after D48.** The no-name rule holds and now has an end condition rather
than an open end: the repository stays public with fake data only through testing and goes private before any real
data, so the customer's name may be written here only after that flip, and not one commit before it.

### D41 — Should collecting data by text message, voice call or USSD ever be planned? **A — keep it a non-goal.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — A — keep it a non-goal, the recommendation.** PRD §9.4 now says so. Nothing is filed.

**Filed 2026-09-14 by `M93`, from Decision Board card `sms-channels` and `docs/PRD.md` §9.4.** A firm non-goal
today, worth revisiting only for a customer working in very low-connectivity areas.

- **A — keep it a non-goal**, and revisit if a customer asks.
- **B — plan it for after launch.**

**Recommendation: A** — no customer has asked, and it would be a large separate product.

### D42 — Should a native mobile app ever be built, instead of the installable web app? **A — keep the web app only.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — A — keep the web app only, the recommendation.** PRD §9.4 now says so. Nothing is filed.

**Filed 2026-09-14 by `M93`, from Decision Board card `native-app` and `docs/PRD.md` §9.4.** Deferred
indefinitely, and needed only for something the web app cannot do, such as background location.

- **A — keep the web app only**, and revisit if a customer needs what it cannot deliver.
- **B — plan a native app for after launch.**

**Recommendation: A** — the installable web app already works offline, and no customer has asked for more.

### D45 — Should `deploy.ps1` get a committed Windows CI job, so its proof runs on every change? **C until a production host exists, then B.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — C until a production host exists, then B, the recommendation.** C is filed as a `before-launch` row (*"`deploy.ps1`'s test harness lives in no committed file"*); B joins when `track-b-deployment` stands up a production host.

**Filed 2026-09-15 by `M96`, while proving the staging-build deploy.** `deploy.ps1` has no committed test. `M95`
and `M96` each proved it with a PowerShell 5.1 harness in a session scratchpad, with the trunk script as the negative
control, and neither committed the harness. CI runs six jobs, all on Linux, and the merge rule is six of six green, so
a committed Windows job changes that rule.

- **A — keep the scratch harness**, rebuilt and run by whichever increment changes the script, and recorded in its
  claim.
- **B — commit the harness and add a seventh CI job on a Windows runner.** Every change to the script is proved, and
  every merge then needs seven checks and pays for Windows runner minutes.
- **C — commit the harness without a CI job**, so the next author starts from it rather than from a scratchpad.

**Recommendation: C until a production host exists, then B.** The harness is the expensive part to rebuild, and the
script changes rarely; a Windows job earns its cost once a failed deploy takes down a site people rely on.
⚠️ **Annotated 2026-09-17 during `M98`, after D47 and D48.** Two premises here have moved. Option B's Windows
runner costs no money today, because standard runners are free on a public repository, but it bills $0.010 a
minute against the Linux job's $0.006 once D48's flip happens, so its cost is deferred rather than absent. And
automatic deploys have been on since 2026-09-17, so a `deploy.ps1` regression now takes down the site the testers
use: not the production host this recommendation named, but the closest thing to it that exists. The scratch
harness also still exists, pinned to a worktree that no longer does. The tier and the recommendation remain the
user's to move.

### D27 — Should a form republishing mid-request refuse the write under the lock, on the save door, on both doors, or on neither? **Option 1 — the save door only.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — option 1 — the save door only, the recommendation.** The save-door row (*"`promote()`'s pre-lock shape has two more instances"*) is unblocked with that remedy. The `forceFill` row — the schedule-window surface `M88` added — is closed as declined, and the submit door and `AttachmentReferenceValidator` are declined with it.

**Filed 2026-09-08 by `M87`, as the residue of the pre-lock row it corrected.** `M85` closed the promote
door by re-reading the `FormVersion` under the existing row lock; the same shape is open on two more doors
and **the two are one decision, not two**, because refusing under the lock is the same product statement
in both places.

**What is measured, so the decision is not taken on the row's framing:**

- **The save door (`SubmissionDraftService::updateDraft()`) is mechanically a copy of the promote fix** —
  the lock already exists, `$version` is a parameter, every symbol is imported, four executable lines. The
  row that filed it says *"neither is a straight copy"*; that is wrong for this half, and the half it is
  right about is the consequence rather than the code.
- ⛔ **But it is not four lines end to end.** `SubmissionDraftController::store()` does not catch
  `SubmissionException`, whose global web arm is a `back()` redirect the autosave `fetch` cannot read — so
  without a typed catch the composable would retry forever against a version that will never be published
  again. The controller change is load-bearing, not optional.
- **The submit door (`SubmissionPipeline::submit()`) has a lock, conditionally.** `assertCapacity()` takes
  `Form::lockForUpdate()` on the same `forms` row `PublishService` holds — but only when
  `max_responses !== null`. An unconditional `Form::lockForUpdate()` on every submit is the throughput
  trade `SubmissionDraftService` already declined **in writing** for promote.
- **Respondent cost is smaller than the row implies.** On the guest channel there is no server autosave at
  all — the only server draft write is the explicit "Save and finish later" click, and a refusal already
  renders a banner with the Dexie draft intact. On encode, the cost is at most one 1500 ms debounce of
  keystrokes, because the next tick is refused anyway.
- **Nothing stages it.** The suite's only concurrent-republish helper is hard-wired to `promote()`'s
  window, and its `skip` parameter exists specifically to step past the save.

**The options:**

1. ✅ **The save door only. Recommended.** It is where the lock already is, where the cost is one autosave
   tick, and where the pre-lock read is currently used to WRITE `form_version_id` and
   `answers_schema_checksum` onto the answer row — so it is the one door where the stale read can make
   stored data lie rather than merely lose a refusal.
2. **Both doors, with `pg_advisory_xact_lock` on submit.** The machinery and its trap note already exist in
   `ScopeNodeService`. Closes the window everywhere; costs a lock acquisition on the hottest write path.
3. **Neither — close it as recorded-and-declined.** Defensible: Stage 2a already refuses at the FIRST
   autosave after a republish, and both tables still agree afterwards because `updateDraft()` writes both
   version columns from the same object it read. ⚠️ This is the option the existing rows lean toward and
   nobody has stated it as a decision, which is what makes it a decision rather than a backlog item.

⚠️ **AMENDED BY `M88` (2026-09-08) — `assertCanStart()` WAS CARVED OUT ON A PREMISE THAT IS FALSE, AND IS
NOW THE FOURTH SURFACE OF THIS QUESTION.** This paragraph read that the schedule window *"is re-asserted
under the lock on promote and on no other door"*, and that the asymmetry was *"a defect with an obvious
answer, so it is a queue row rather than a decision"*. ⛔ **Measured against the code: `M85` re-asserted
`assertCanPromote()` — which refuses only a draft created at or after `closes_at` — and NOT
`assertCanStart()`, which is the `now()`-window; and it took NO LOCK, only a re-read.** So there is no
promote-side re-assertion of the schedule window, no asymmetry to restore, and nothing obvious to do.
⛔ **The remedy the carve-out assumed would contradict H12a rather than restore symmetry**: the grace window
exists so a respondent who STARTED inside the window is not stranded, the promote door admits a close moved
to `now()` by design and a shipped test says so, and a fresh submit that passed `assertCanStart()` is in
exactly that position. ⚠️ **Option 3's warning therefore applies to this surface unchanged** — declining to
act is a decision nobody has stated, not a backlog item — which is why the ledger row is closed as
no-code-change and the question lives here instead. ⚠️ **The paragraph above on the submit door's conditional
lock is NOT affected**: it says the throughput trade was declined *for promote*, which is exactly what the
code comment says, and it applies it to `submit()`'s republish question rather than to the schedule window.

### D25 — `P2c`, the deferral-phrase arm, measures 5% precision and ~2% recall. Keep it, drop it, or re-aim it as a staleness lint? **Option 1 — keep it as shipped, sold as bookkeeping.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — option 1 — keep it as shipped, sold as bookkeeping, the recommendation.** The re-aim the entry recommends as a follow-up (option 3, a staleness lint) is filed as an `after-launch` row.

**Filed 2026-09-07 by Lane A, during `M82`, at the moment the arm was written rather than after.**
The approved design named this arm as one of the things that would make an unqueued obligation
impossible. It is not that, and the honest thing is to say so before it is inherited as though it were.

**The measurements, three independent passes, all agreeing.** The approved design's own verification
run classified twenty vocabulary hits and found **one** genuine unscheduled obligation — precision 5%.
Its seven blind sweeps found fifty-four unscheduled items and reported, independently each time, that
**zero** of them were reachable by any phrase — recall ~2%. This increment re-measured the vocabulary
against the live corpus: the original seven phrases match 25 lines, and the design's proposed
**replacement** six match **114**, one entry of which (`does not exist|has no writer`) alone matches 45
including `CLAUDE.md`'s own *"an unpushed claim does not exist"* and a line of a **generated** file.

⛔ **AND THE DESIGN'S OWN PROPOSED IMPROVEMENT IS WORSE THAN THE THING IT IMPROVES.** It records a
nuance it calls *"worth more than the whole phrase list"* — a deferral whose destination phase has
already closed. Measured: 26 lines name a destination phase, 10 of them target a phase the roadmap
reads `COMPLETE`, and **all 10 are records of a discharge or of a superseded statement**, e.g.
*"~~deferred to a Phase 0 spike~~ — RESOLVED"*. Zero precision, on the predicate the design ranked
highest. That is not an argument against measuring; it is the fourth time in this project that a
predicate over prose has turned out to be reading the record of a repair.

**What shipped, and what it is actually worth.** Four phrases, each anchored to a line that exists
today, matching **eight** sites; three of the original seven are gone because a rule that governs no
line cannot be reddened. Of the eight, roughly half are visibly **stale sentences** — documents still
calling built things unbuilt — and the rest are genuine standing statements. It costs nothing to run
and it pins those eight against a count and a digest, so a ninth cannot appear unnoticed.

**The options.**

1. **Keep it as shipped, sold as bookkeeping and not as prevention (RECOMMENDED).** It is nine lines
   of vocabulary plus a shared pin, it caught two live false-positive classes during construction that
   are now controls, and its yield — stale sentences — is a real rot class this project keeps finding
   by hand. Its header already says in capitals what it is not. Cost: one more constant pair to move
   when a document gains or loses a sentence, which is the same cost every other pinned corpus carries.
2. **Drop the arm entirely.** Defensible on the numbers alone, and it would remove four phrases, two
   constants and three controls. ⛔ The cost is that the eight sites stop being counted at all, and an
   uncounted site is exactly the state five realignments were spent correcting — the arm's *precision*
   is bad, but its *pinning* is not the thing that is bad.
3. **Re-aim it as a staleness lint** — fire only where a not-built sentence sits in a document that
   also claims the thing shipped. That is where its real yield is, and it is a genuinely better rule.
   ⚠️ It is also a different increment: the join needs a build-evidence term, which is `P2d`'s
   five-term machinery pointed at prose, and prose has no join key. Recommend this as a follow-up to
   option 1 rather than instead of it.

**Recommendation: option 1**, with the header wording kept exactly as harsh as it is.

### D24 — The coverage rules pin a residue of 134 undischarged obligation sites. Schedule the sweep, or leave the residue pinned indefinitely? **Option 1 — work the two rows as ordinary increments, `deferral-site-disposition` first.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — option 1 — work the two rows as ordinary increments, `deferral-site-disposition` first, the recommendation.** Both markers exist. `deferral-site-disposition` is retiered `after-launch` → `during-testing` so it can be taken first beside `prd-feature-disposition`, and the indented-continuation row this answer unblocks is marked ready.

**Filed 2026-09-07 by Lane A, during `M82`.** This is the question `P2e` was designed to make
answerable rather than to answer, and it is a product call rather than an engineering one.

**The situation, measured.** Four corpora are now enumerated and pinned: **14** PRD feature headings,
**23** sections declaring a disposition, **8** deferral sentences, and **93** PRD acceptance criteria
of which **89** carry no disposition. Nothing discharges any of them today. Requiring a disposition on
each would have been red on arrival by 134 failures, which `M40` established is a gate that gets
deleted rather than satisfied — so the residue is pinned instead, and it is visible in the line as two
rows: `prd-feature-disposition` (XL) and `deferral-site-disposition` (L).

⛔ **THE PINNING IS NOT THE ANSWER, IT IS THE QUESTION MADE VISIBLE.** What the gate now guarantees is
that the residue cannot GROW unnoticed. It guarantees nothing about the residue shrinking, and a
constant that never moves is indistinguishable, in five years, from a fact nobody ever intended to act
on. The five realignments this whole design exists to prevent were each about work that was documented
and unscheduled; 89 acceptance criteria with no recorded outcome is that condition, written down.

**The options.**

1. **Work the two rows as ordinary increments when they reach the front of the line (RECOMMENDED).**
   `prd-feature-disposition` is genuinely large — 89 criteria each needing a verdict measured against
   the code, which is the discipline that found `Phase-1 COMPLETE` to be false — but it is exactly the
   audit this project has repeatedly paid for by hand. `deferral-site-disposition` is much smaller and
   could be taken first. Cost: two increments, one of them XL.
2. **Disposition only at the FEATURE level and retire `P2e`'s bullet arm.** Fourteen verdicts instead
   of 89, and it is where most of the value is. ⚠️ The approved design measured this option and
   rejected it: a feature-level arm *"would not have caught item 4 alone"* — the specific unscheduled
   obligation that motivated the sweep sat in a bullet, not in a heading.
3. **Leave the residue pinned and act only opportunistically** — whenever an increment touches a
   feature, disposition its criteria and lower the constant. ⚠️ Honest, cheap, and it is what will
   happen by default if nothing is decided; the risk is that it is also what "we will get to it"
   looked like in each of the five realignments.

**Recommendation: option 1, with `deferral-site-disposition` taken first** as the smaller of the two
and the one whose eight sentences are already known to be half stale.

### D23 — `scripts/loop.php` refuses held work by a hand-written keyword list, and there is now a gate proving the pipeline holds every held row. Keep the list, derive it, or cross-check it? **Option 1 — keep the literal list, cross-checked both ways by `P4`.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — option 1 — keep the literal list, cross-checked both ways by `P4`, the recommendation.** The row awaiting it (*"`scripts/loop.php --assess` does not read `docs/pipeline.md`"*) is closed as decided, with no code change.

**Filed 2026-09-07 by Lane A, during `M81`, at the moment `P4` was written.** The row that asks for
this (`R-3401f9b1`, `docs/feature-backlog.md:5969`) explicitly defers itself *to this gate*, so the
question is now answerable and was not before.

**The situation, measured.** `scripts/loop.php` carries `HELD_TOPICS`, twelve keywords matched as
substrings against a candidate row's text. `docs/pipeline.md` carries five `state=held` rows, each
with a named blocker. The two are **many-to-one** — `payment`, `payments`, `stripe` and `billing` all
reach the one payments row — which is why `M81`'s `P4` asserts bidirectional **coverage** rather than
the set equality the approved design specified, a shape that cannot be written against these two.

⛔ **THE ASYMMETRY IS THE WHOLE DECISION, AND IT IS NOT AESTHETIC.** An **over**-refusing stop-list is
annoying: an unattended run declines a row it could have taken, and a human notices. An
**under**-refusing one is unsafe: an unattended run **starts held work**, which is the one thing the
user has repeatedly and explicitly forbidden. So the two directions of error are not equally priced,
and any option that makes the stop-list depend on something that could be incomplete is buying tidiness
with the expensive kind of failure.

**The options:**

1. ✅ **Keep the literal list, cross-checked both ways by `P4`. (RECOMMENDED.)** This is what
   `M81` shipped. The list stays a hand-written stop-list that cannot be made incomplete by a
   generation failure, and the gate refuses any drift between it and the line — in both directions, so
   neither a dropped keyword nor a dropped row can pass. It is the `ADR_RESERVED` precedent exactly: a
   fact that cannot be derived safely is written down once and then machine-checked against everything
   that would otherwise duplicate it. ⚠️ Its honest cost is that the list is still a second artefact,
   and someone adding a held row must add a keyword too — but the gate now tells them so, immediately.
2. **Derive `HELD_TOPICS` from `docs/pipeline.md` and delete the literal.** One artefact instead of
   two, and the duplication disappears. ⛔ **It buys that with the expensive direction of failure**:
   the stop-list would then be exactly as complete as the last generation, and a generation that went
   blind — the failure `P5`'s floors exist for — would silently produce an *empty* stop-list, which
   refuses nothing at all. A gate that fails safe cannot depend on a file that can fail short.
3. **Keep both and drop the cross-check**, on the grounds that the coarse substring match happens to
   cover the same ground today. This is the state before `M81` and it is listed to be refused
   explicitly: "happens to cover the same ground today" is a measurement with no gate behind it, and
   the whole increment exists because five realignments were caught by audits rather than by gates.

### D22 — The pipeline generator's own discovery floor is 40 against a live scan of 869. Ratchet it, leave it, or let the gate carry the only binding floor? **Option 1 — leave the generator's floor where it is; the gate carries the binding one.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — option 1 — leave the generator's floor where it is; the gate carries the binding one, the recommendation.** Nothing is filed.

**Filed 2026-09-07 by Lane A, during `M81`, while sizing `P5`.** Not fixed in the increment that found
it, deliberately — see the last option.

**What was measured.** `scripts/pipeline.php` sets `MIN_SCANNED_FILES = 40` and its comment cites the
two real blindness events this project has recorded: `controller-gate` reporting `passed` while seeing
49 of 97 files, and the container's iterator pinned at 87 of 114 migrations. The live scan reaches
**869**. ⛔ **So the floor carries 22x slack, and neither cited event would have tripped it** — a walk
losing half the corpus returns 434 and passes comfortably. `M81`'s `P5` therefore carries its own floor
at **600**, which is the one that now binds, and the gate refuses rather than ruling over a short list.

**The options:**

1. ✅ **Leave the generator's floor where it is; the gate carries the binding one. (RECOMMENDED.)**
   The two floors are not duplicates — they answer different questions. The generator's protects
   anyone running it standalone and has to survive a corpus that legitimately shrinks; the gate's
   protects the merge and can be tight because it is re-measured every time the corpus is. ⚠️ The cost
   is that a bare `php scripts/pipeline.php` can still write a pipeline from a half-blind scan, and
   only the gate afterwards would say so.
2. **Ratchet `MIN_SCANNED_FILES` to a measured value the way `TRACKER_BYTE_CEILING` has been ratcheted
   four times.** Closes the standalone hole. ⛔ **The reason it is not simply done here** is the lesson
   that constant's own comment records: re-cutting a threshold from a single new data point, inside the
   increment that produced it, is the move the threshold exists to prevent. A ratchet also has to be
   maintained, and this corpus grows every increment.
3. **Delete the generator's floor entirely and let the gate own it.** One floor, no drift between two
   numbers. ⛔ Refused unless the user prefers it: it makes a standalone generation silently
   unprotected, and `docs/pipeline.md` is regenerated by hand at every close-out.

### D21 — `docs/pipeline.md` is merge-gated but sits in no `paths-ignore`, so every close-out now triggers a full CI run. Accept the cost, exempt it, or split the file? **Option 1 — leave it outside `paths-ignore` and pay the run.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — option 1 — leave it outside `paths-ignore` and pay the run, the recommendation.** Nothing is filed; the close-out keeps paying the run.

**Filed 2026-09-06 by Lane A, during `M79`, at the moment the file was created.** Recorded here rather
than decided in the increment because it changes what a close-out costs on every future increment, and
because the neighbouring territory is already an open question (`D8`).

⛔ **THE TRADE, STATED PLAINLY.** `docs/backlog-triage.md` and `docs/gate-baselines.md` are both inside
`ci.yml`'s `paths-ignore`, and both are *advisory* — nothing fails when they drift, `state.php` merely
reports how stale they are. `docs/pipeline.md` is different in kind: it is the **single queue**, and the
gate `M80` builds makes its drift a **merge failure**. A file whose freshness is merge-gated cannot be
in `paths-ignore`, because a push touching only that file would produce **no run at all** — and this
project has already established that a skipped run is not a pending one, so the trunk would carry drift
with nothing able to say so.

⚠️ **WHAT IT COSTS, MEASURED RATHER THAN ESTIMATED.** A close-out is four or five commits, and today
every one of them is inside `paths-ignore` and produces no run. With `docs/pipeline.md` outside it,
each close-out that regenerates the pipeline triggers the full six-job pipeline — roughly eighteen
minutes of runner time for a diff that is one generated markdown file.

**The options:**

1. ✅ **Leave it outside `paths-ignore` and pay the run.** The gate is only worth having if it can
   actually fire on the trunk, and correctness on the single queue is worth eighteen minutes.
   **Recommended.** ⚠️ Its honest cost is that the close-out choreography gets slower for everyone, on
   every increment, forever.
2. **Add it to `paths-ignore` and accept that `P1` can only fire on a pull request.** Cheaper, and the
   PR arm still catches the ordinary case, since a human regenerating by hand does it on a branch. ⛔
   The hole it leaves is the one `M71` walked into from the other side: a close-out push straight to
   the trunk is exactly the shape that produces no run, so the trunk could carry a drifted queue until
   the next PR — red on arrival, which `M40` established can never merge.
3. **Split the file — a small gated index plus an ungated body.** The index carries the counts and the
   provenance and is merge-gated; the long table is regenerated freely. ⛔ Listed to be refused unless
   the cost in option 1 actually bites: it is two files where the whole point of this increment was to
   have one, and a second copy of the counts is precisely the defect the pipeline exists to end.

⚠️ **DO NOT SETTLE `D8` HERE.** `D8` asks how `ci.yml` should regain the trunk observation that a
tracker surgery loses, and it is adjacent enough to look like the same question. It is not: `D8` is
about a *diff shape* that produces no run, this is about *one file's* membership. Answering this one
does not answer that one, and an increment that quietly did both would be spending a user decision it
was not given.
⚠️ **Annotated 2026-09-17 during `M98`, after D48.** The eighteen minutes above reads as wall clock rather than as
billed time — INFERRED, since this entry calls it runner time and a full run measures 19 minutes of wall clock —
and the billable figure is larger: about 38 minutes, six jobs each rounded up to the minute. It is free only while
the repository is public, so option 1's price becomes about $0.23 a close-out run at the 2-core Linux rate of
$0.006 a minute once D48's flip happens — which is the moment to re-read this question.

### D19 — A Reviewer holds `submissions.create` and can encode on no form. `M77` made every document say so. Should the ROLE now gain encoding, or is documenting the gap the whole answer? **Option 1 — leave the behaviour exactly as it is.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — option 1 — leave the behaviour exactly as it is, the recommendation.** The documentation fix `M77` shipped was the whole answer. Nothing is filed.

**Filed 2026-09-06 by Lane A, during `M77`, at the moment the documentation was corrected.** `M13`
filed this as *"both readings are defensible and choosing between them is an authorization
decision"*. ⛔ **That framing is now measurably wrong in its first half, and the entry says so rather
than reproducing it.** There were not two readings of one fact; there was **one code behaviour and
five documents describing it incorrectly** — the seeder comment, `docs/multi-tenancy-rbac-design.md`'s
§3 role table, its §5 matrix row, its §8.3 shape sentence and `docs/ACCESS-MATRIX.md`. All five are
corrected and no access changed. What is left is genuinely a product question, and it is narrower.

⚠️ **WHAT IS TRUE IN THE TREE, MEASURED.** `RolePermissionSeeder` grants the role
`submissions.create`. `SubmissionPolicy::create()` requires that permission **and** a published
version **and** (`forms.edit.any` **or** `ResourceCapacity::Editor` on the form). A reviewer's grant
is reviewer capacity, so **a plain Reviewer can manual-encode on no form at all.** The behaviour was
already correct and already covered — the G10a case
`tests/Feature/Submissions/SubmissionPolicyTest.php` *"requires EDITOR capacity to manually encode"*
has pinned it since G10a, which `M13`'s row and both arms of `M77`'s fan-out all missed.

⚠️ **AND THE PERMISSION IS LOAD-BEARING, SO `M13`'s SECOND OPTION WOULD HAVE BROKEN SOMETHING.** That
option was *"correct the sentence and drop `submissions.create` from the role"*. Dropping it breaks
the one configuration that makes the role composable: a **reviewer-role member holding an editor
grant**, who may both review and encode. `review()` resolves through
`ResourceGrantResolver::holdsAny()`, which accepts either capacity, so that member keeps reviewing;
`create()` passes on the editor capacity; and `submissions.create` is the coarse half both need.
Nothing asserted that configuration before `M77`; a case now does.

**The options:**

1. ✅ **Leave the behaviour exactly as it is — the documentation was the entire defect.**
   A Reviewer reviews; encoding is an authoring act and needs an editor grant, which G10a decided
   deliberately and for a stated reason (at subtree scale a reviewer grant on an interior node would
   otherwise confer write access to every form beneath it). The composable path already exists for
   the *"this person does both"* case. **Recommended.** ⚠️ Its cost is that
   `docs/ACCESS-MATRIX.md`'s grid now needs a warning footnote to be read correctly, because a
   permission a role holds but can never exercise alone is a genuinely confusing thing to publish.
2. **Widen `create()` to accept reviewer capacity** — i.e. make the five documents' original claim
   true instead of correcting them. ⛔ This reverses G10a on the merits, not on a technicality, and
   the subtree argument is the reason to expect it to be wrong: `includes_descendants` grants exist,
   and a reviewer grant on a region node would hand out encoding across every form in that region.
   If this is chosen it should be scoped to **direct form grants only**, never node grants, which is
   a third behaviour neither document describes today.
3. **Drop `submissions.create` from the role and give the composable case its own mechanism.**
   Honest about the role being review-only, but it deletes the working reviewer-plus-editor path and
   replaces it with nothing; a second grant type or a role change would have to be designed. Listed
   because it is `M13`'s stated option and should be refused explicitly rather than ignored.

### D18 — The proof-of-work solver yields every 5000 candidates against a 120000 search space, and nothing has ever decided that number. Keep 5000, derive it, or make it configurable? **Option 1 — keep 5000.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — option 1 — keep 5000, the recommendation.** Nothing is filed.

**Filed 2026-09-06 by Lane A, during `M77`, alongside the cadence gate that pins everything EXCEPT
the value.** The row asked for the cadence; the cadence is now asserted
(`resources/public-runtime/__tests__/challenge.test.ts`, offset within a block and number of blocks,
proved by deliberate defect). ⛔ **The interval's VALUE is the half a test must not decide, because
a test whose expectation derives from the constant it guards cannot see the constant change** — so
pinning 5000 there would have been a gate asserting this project's own undecided question.

⚠️ **WHAT IS MEASURED AND NOT IN DISPUTE.** `challenge.ts` yields at `n % 5000 === 4999`;
`config/guest.php` sets `max_number` to `120000`; so a worst-case solve yields **24** times, and the
count is `floor(answer / 5000)` — the final partial block never yields, because the match returns
before the check. 5000 has been the value since I8b with **no stated basis anywhere** — no comment,
no test, no document.

⛔ **AND THE REASON THE YIELD EXISTS WAS RECORDED BACKWARDS UNTIL `M77`, WHICH IS WHY THE NUMBER
MATTERS LESS THAN IT LOOKS.** `challenge.ts`'s docblock said the yield keeps *"both the tab and the
SW responsive"*. A service worker is always a secure context, always takes the `crypto.subtle`
branch, and an awaited native digest already turns the event loop on every candidate — measured in
this project's node container: a `setTimeout(…, 0)` fires during 200 awaited
`crypto.subtle.digest()` calls and does **not** fire during 200 awaited resolved promises. **So the
yield does nothing in the service worker.** It serves the **insecure-embed tab** — an `http://` host
page, where `crypto.subtle` is undefined, the solver falls back to the synchronous `sha256Hex()`,
and that context has no service worker either. The interval is therefore a *paint-responsiveness*
knob for one deployment shape, not a *fetch-starvation* knob for the outbox drain.

**The options:**

1. ✅ **Keep 5000 and let the corrected docblock be the record.** It is now documented what the yield
   is for, what it costs (24 yields worst case) and why the SW is unaffected — which is everything a
   future reader needs, and the value has caused no measured problem in the one context that uses
   it. **Recommended**, on the grounds that nobody has reported a janky embedded solve and inventing
   a target for a knob nobody is pulling is how a decorative constant becomes a decorative gate.
2. **Derive it from a target frame budget** — e.g. yield roughly every 16 ms of fallback hashing,
   measured once and turned into a constant with the measurement written beside it. Principled, and
   it would replace an unexplained number with an explained one. ⚠️ The honest cost: the pure-JS
   hash rate varies by an order of magnitude across the low-end Android devices this fallback exists
   for, so a single derived constant is only better than 5000 if the measurement names the device it
   was taken on — otherwise it is the same arbitrary number with a more confident comment.
3. **Move it to `config/guest.php` beside `max_number`.** The two numbers genuinely are related (the
   yield count is a function of both) and an operator could then tune it per deployment. ⛔ Listed to
   be refused unless option 2 is also taken: it is a client-side constant that would have to be
   serialised into the challenge payload or the bundle, which adds a wire field and a second copy of
   a fact to solve a problem nobody has reported.

### D17 — A local container Pest run silently omits 40 test files. `M76` made that loud, which makes every local run RED. Keep it, soften it, or change how the suite is run? **Option 1 — keep it as shipped.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — option 1 — keep it as shipped, the recommendation.** The row awaiting it is closed as decided, with no code change; the `mutate.php` row that re-priced this now records that its condition is permanent.

**Filed 2026-09-06 by Lane A, during `M76`, at the moment the gate was written.** Recorded here rather than
decided in the increment because it changes the user's daily development loop, which is not an increment's
call to make on its own judgement.

⛔ **WHAT WAS MEASURED, AND IT IS NOT IN DISPUTE.** `phpunit.xml` declares its suites as `<directory>`
entries, which PHPUnit expands through `SebastianBergmann\FileIterator\Facade` — an SPL directory iterator,
and therefore subject to this project's bind-mount truncation. In `dev_formbuilder_app-app-1`: **385 of 425**
`*Test.php` files are collected, and the 40 missing are the **whole of `tests/Feature/Forms`** — every form
lifecycle, policy, publish, schedule and RLS test in the repository. They are never loaded, never run, and
never reported absent. A local full-suite run has been printing a green summary for a suite missing 9% of
its files, including the directory covering the product's central object.

⚠️ **THE HOST AND CI ARE BOTH FINE**, which is what makes this hard to see and easy to under-rate: the
blindness exists only where a human reads the result, and never where the merge gate does.

**What `M76` shipped**, because a gate that reports green while blind is the defect this repository is built
around: `tests/Feature/Docs/SuiteCollectionFloorTest.php` compares the collector against a reliable
enumerator and fails, naming the missing files. It is **green on the host and in CI** and **red in the
container**. It blocks no merge.

⛔ **THE HONEST COST, STATED BECAUSE IT CUTS AGAINST THE FIX.** A container Pest run is now permanently red
until the mount is worked around — and **a permanently-red test teaches a reader to skip red**, which is
this project's own argument, made verbatim in the `AbortError` row `M76` closed in the same increment
(*"a stack trace on a passing run is what teaches a reader to skip stack traces"*). That argument does not
stop applying because the signal is one we like.

**The options:**

1. ✅ **Keep it as shipped.** The suite really is incomplete and the gate says so with the file list. The
   remedy is available and cheap — `pest tests/Feature/Forms` collects those files correctly when the
   directory is named explicitly — so the red is *actionable* rather than merely true. **Recommended**,
   on the grounds that the alternative is a known 40-file hole nobody is reminded of. ⚠️ Its weakness is
   the one above: it is red on every local run, forever, until something outside this repository changes.
2. **Soften it to a warning the run prints without failing.** Keeps the information, removes the fatigue —
   and is precisely the decorative-gate shape `M43` measured and this project rejects, so it is listed to
   be refused explicitly rather than left as an unexamined middle.
3. **Change how the suite is run, and let the gate stay strict.** Give `composer` a test script that
   enumerates the leaf directories reliably and passes them explicitly, so the local run actually collects
   all 425 and the gate goes green honestly. **This is the only option that fixes the defect rather than
   reporting it**, and it is the most work: it needs a wrapper that is itself proved, and it changes the
   documented way to run tests. If the answer is 3, the gate from option 1 stays exactly as it is — it
   becomes the thing that proves the wrapper works.

⚠️ **Whatever is chosen, the measurement stands and the trap is not this repository's to fix at source:**
every SPL directory iterator truncates on this mount under every flag combination, and the next directory to
go blind **cannot be predicted** — synthetic directories of up to sixty files do not truncate, while a real
46-entry directory collapses to 6. That is why `M76` shipped a comparison rather than a documented list.

### D16 — The `npm audit` judge makes a required status check green when the registry is unreachable. Accept it, isolate it, or keep the hard block? **Option 1 — as built: green with an annotation.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — option 1 — as built: green with an annotation, the recommendation.** The row awaiting it is closed as decided, with no code change.

**Filed 2026-09-05 by Lane A, during `M72`, at the moment the trade was taken rather than after.** It is
here and not only in the backlog because it deliberately weakens a **merge gate**, and the class it joins
is one this repository has spent four increments learning to refuse.

**What was fixed.** `npm audit --omit=dev --audit-level=high` exits `1` both when a high advisory exists
and when the advisory endpoint cannot be reached. That single indistinguishable red hit `main` twice on
consecutive increments — `M69`'s PR run and `M70`'s **post-merge run on the trunk** — and both times the
remedy was to re-run a red gate, which is the habit every other control here exists to prevent. Fetching
and judging are now separate, and the judge exits `0` clean · `1` blocked · `2` never measured.

⛔ **WHAT IT COSTS, STATED PLAINLY.** On exit `2` the workflow emits a `::warning::` and a job summary and
**exits 0**, so `Static analysis, style & security` — one of `D7`'s six required contexts — reports green
having judged no dependency at all. **That is a vacuous success**, the same family as `I5`'s `steps: []`,
Pint before its probe, `M61`'s `e2e` wrong form and `M69`'s PHPStan-crash-exits-0. It is being accepted
knowingly, which is the only honest way to accept one.

**The options:**

1. ✅ **As built: green with an annotation.** The failure it replaces is worse — a false red teaches the
   operator to re-run a red gate, and a false green here is bounded (it cannot hide a *known* advisory,
   only the absence of a measurement). **Recommended**, because the alternative was measured on the
   trunk twice and this has not yet been observed at all. ⚠️ Its real weakness is that nobody is obliged
   to read the annotation.
2. **A separate, non-required job.** An unreachable registry then shows as a genuinely failed check that
   does not block, which is the most truthful rendering. Costs a runner and a second `npm install` per
   run, and adding a job means touching the branch-protection ruleset `D7` fixed by name — the class of
   change that stays with the user.
3. **Keep the hard block.** Truthful about having measured nothing, and it reddens `main` on somebody
   else's outage. This is the status quo the row was filed against.

⚠️ **A fourth shape exists and is not offered, because it needs state the workflow does not have:** fail
after *N consecutive* unreachable runs. That distinguishes a blip from an outage and is the right answer
if this recurs; it wants a cache key or a repository variable, and guessing at one inside this increment
is how a gate acquires a second thing to get wrong.

### D14 — The compliance spec promised audit events for deleting and restoring a submission, and there is no delete or restore surface at all. Build it, or record it as not built? **A — record it as not built, and leave the surface unbuilt.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — A — record it as not built, and leave the surface unbuilt, the recommendation.** Nothing is built. The purge row that was to be taken with this one now records that its submissions third is settled.

**Filed 2026-09-04 by Lane A, during `M70`, at the moment the row's deciding premise was falsified.**
Promoted out of `docs/feature-backlog.md` rather than taken as a row, because the row `M46` filed asks
which of two directions is right and **both of them are product calls**, not cleanups.

⛔ **THE ROW'S DECIDING PREMISE IS FALSE, AND THAT IS WHAT MAKES THIS A DECISION RATHER THAN A FIX.**
`M46` filed it as *"the honest answer may be 'these are owed, build them' rather than 'delete them from
the document'"*, which presumes the events are an omission at an existing call site. **There is no call
site and no surface.** Verified twice, independently: `SubmissionPolicy` declares `create`, `viewAny`,
`view`, `export`, `review`, `update`, `promote` — no `delete`, no `restore`, no `forceDelete`;
`RolePermissionSeeder` mints no `submissions.delete`; **zero** routes in `routes/` match such a verb;
there is no controller action and no UI affordance under `resources/js/Pages/submissions/`. `deleted_at`
exists on the table and is dormant, and `ClientUuidResolver::isClaimed()` already says so in terms:
*"Nothing soft-deletes a submission today"*.

⚠️ **The one path that does remove a submission cannot write these rows and must not be made to.**
`ReapTenantDraftsJob` **hard**-deletes abandoned drafts, deliberately — a soft-delete tombstone would
keep `client_submission_uuid` reserved against the partial unique index, which its own docblock explains
— and it runs as a queue job, where `AuditLogger` hard-codes `is_system_action = false` and would
resolve a null actor off a worker. That is the same malformed shape the `domain` row's *"deliberate
gaps"* note already refuses `activate`/`deactivate` for.

- **A — record it as not built, and leave the surface unbuilt.** `M70` has already narrowed §1's
  `submission` row this way, saying *why* in the cell rather than going quiet, because a downstream SIEM
  forwarder or retention rule built from that section would otherwise read a bare removal as a decision
  that destroying a response needs no trail. Costs nothing further. **Against it:** a compliance
  document that describes a product with no way to delete a response is only honest for as long as that
  stays true, and the first customer asking for erasure changes it.
- **B — build the surface, and owe the two events.** Policy methods, a `submissions.delete` permission
  across six roles, routes, a controller, a trash view, and the audit calls inside the same transaction
  as the state change. ⚠️ **The unpriced part is not the CRUD**: soft-deleting a submission makes
  `ReapTenantDraftsJob`'s stated reason for hard-deleting a live conflict — a tombstone keeps the
  client uuid reserved, so a respondent's retry against a deleted submission meets a unique-index
  violation rather than a clean claim. `ClientUuidResolver::withTrashed()` becomes reachable behaviour
  rather than a guard. That is a correctness surface, not a screen.
- **C — restore only, for the reaper.** Rejected before it is proposed, and recorded so it is not: the
  reaper hard-deletes, so there is nothing to restore, and giving it a soft-delete to undo re-opens B's
  uniqueness problem with none of B's user-facing value.

**Recommendation: A**, and not merely as the cheap option. Erasure of a submitted response is a data
subject's request in GDPR terms, not a tidy-up — it interacts with retention, with export, with the
`audits` ledger's own append-only guarantee, and with whatever the platform promises a form's
respondents. That belongs with the held GDPR/legal work and to a deliberate design, not to a batched
row closing a documentation over-claim. ⚠️ **If B is ever taken, the uniqueness interaction is the part
to settle first** — it is the half that is invisible in the ticket and expensive in the code.

### D11 — Two byte-serving routes gate on a subject their own comments question. Leave them, or move one? **A — leave both, and pin the intent instead.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — A — leave both, and pin the intent instead, the recommendation.** No route changes. The grant-manifest row (*"`routes/tenant.php`'s ~95 `can:` gates get none of M63's checks"*) now carries both gates as intended, and the moved-to line records the answer.

**Filed 2026-09-02 by Lane A, during `M63`, at the moment the scope was decided.** Promoted out of
`docs/feature-backlog.md` rather than taken as a row, because both candidate fixes change **who can do
something**, and that is a product call rather than a cleanup. The row that raised it called itself *"a
lead, not a finding"* and said to re-read the file before acting — which is what produced the correction
below.

⛔ **THE ROW'S OWN CITATION IS WRONG, AND THE ERROR MATTERS TO THE ANSWER.** It describes
`GET /submissions/{submission}/pdf`. The route is **`POST`** (`routes/tenant.php`, `submissions.pdf`), and
it is POST deliberately — the route's own comment records that it has side effects: an audit row, a
metered export, and a queued job. A gate on a side-effecting write is a different argument from a gate on
a read, and the row was reasoning about the second.

**The two gates, and what each already says for itself.** `submissions.pdf` gates `can:view` where its
streamed-export sibling gates `can:export`, and the route comment flags the asymmetry itself.
`forms.share.qr` gates `can:update,form` — an **edit** permission to read a QR code of a URL that is
public once guest access is on. Neither is a coverage hole: both carry deny tests already.

- **A — leave both, and pin the intent instead.** No route changes. Both enter the `routes/tenant.php`
  grant manifest when that row is taken, so the asymmetry becomes something *asserted* rather than
  defended in a comment — which is the row's actual complaint. Costs one line each, changes nobody's
  access, and removes the "nobody chose this deliberately" objection permanently.
- **B — align the PDF with its export sibling** (`can:export`). ⚠️ **The reason not to, and it is
  concrete:** a Viewer holds `submissions.view` and **not** `submissions.export`, so *"print the response
  you are already reading"* would stop being available to the role whose entire surface is reading
  responses. `routes/tenant.php` states exactly this at the route and decided against it once already.
  Against that: the PDF **is** a metered export that writes an audit row, so charging it to the export
  permission is defensible on cost grounds rather than on read-scope grounds.
- **C — loosen the QR** to `can:view,form` or `can:viewOverview,form`. Today a Reviewer running a field
  day cannot print the poster for a form they are collecting on, which is a real workflow. Against it: the
  QR encodes the share slug, and **before guest access is switched on that slug is not yet public** — so
  an edit-level gate is defensible as *"only the author hands out the address."*

**Recommendation: A.** `M63`'s whole claim is that it added the first executable assertion about which
permission a gate names; changing a gate inside that same diff would make its mutation matrix ambiguous
about which half caught what. B and C are each worth taking as their own row **with an answer already in
hand** — and under A they become one-line manifest edits whose effect a reviewer can see, rather than
middleware changes nobody can measure. ⚠️ If B is ever taken, it needs the Viewer question answered
first: either Viewers lose the PDF, or `submissions.export` stops meaning "may move bytes off the
platform", and those are different products.

### D10 — `§9` item 9's escalation has fired. Adopt the value-object forcing device, or keep answering per surface? **(b) now — keep answering per surface, mechanically; a third unescaped surface makes (a) automatic.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — (b) now — keep answering per surface, mechanically; a third unescaped surface makes (a) automatic, the recommendation.** The discovery sweep the entry recommends under any option is filed as an `after-launch` row, and the threat model's §9 no longer calls this open.

**Filed 2026-09-01 by Lane A, during `M57`, at the moment the scope was decided.** Filed rather than
decided because the escalation is a **repo-wide refactor of every render path**, and it was measured
against exactly one instance. Nothing is broken today; this is about what the *next* surface costs.

**The trigger, and that it really did fire.** `docs/security-threat-model.md` §9 item 9 has said since
H6a that output encoding is *"convention plus one test per surface, not a mechanism that fails the
build"*, and it named its own escalation: **if a second surface is found unescaped after that contract
lands, adopt the forcing device** — a renderer returning a value object with no `__toString()` and one
method per output context, so a forgotten escape becomes a PHPStan-level-8 error the way
`OcrFieldEligibility`'s `default`-less match makes an unclassified field type one. `M57` found that
second surface: the published mail header interpolated a tenant name into an HTML `alt` where nothing
escaped the quote.

⛔ **AND THE FAILURE MODE IS WORSE THAN ITEM 9 DESCRIBED, WHICH IS THE REAL ARGUMENT FOR ACTING.** Item 9
predicted *"a surface added later escapes correctly only if its author reads the table"*. That is not what
happened. The mail surface **had** its per-surface test, the test was **green throughout**, and it could
not have been otherwise — it asserts markdown syntax, and `withSecuredEncoding()`'s three-character map is
simultaneously what neutralises `[` and what stops escaping `"`. **A per-surface test aimed at the wrong
context is not weak coverage; it is coverage that cannot fail.** Reading the table would not have helped:
the table had a row for this surface and it was ticked.

**The options.**

- **(a) Adopt the forcing device across every surface**, as item 9 prescribes. It is the only option that
  makes a *missing* escape a type error rather than a review question, and it is the only one that would
  have caught `M57` without anybody thinking of attribute context first. ⚠️ **The cost is not the class —
  it is every call site**: the HTML/Blade shells, the PDF templates, the Slack `mrkdwn` formatter, the
  markdown-mail views, the CSV/XLSX export path, and whatever the guest runtime hands to Vue. Several are
  Blade, where a value object with no `__toString()` is precisely what an echo cannot render, so the views
  change too.
- **(b) Keep answering per surface, and make each answer mechanical** — which is what `M57` shipped:
  a named escaper plus `scripts/mail-attribute-lint.php` as a merge-blocking step, so on that one surface
  a forgotten attribute escape now fails the build. Cheap, proven, and **it does not generalise**: the next
  surface owes its own gate, and nothing prompts its author to write one.
- **(c) Split the difference — adopt the forcing device only where the escaper is context-dependent.** The
  surfaces that have burned us (`mrkdwn`, markdown-mail attributes) are the ones where the *correct*
  escaper is not the framework default. Surfaces whose default is already right (ordinary Blade, Vue text)
  keep the convention.

**Recommendation: (b) now, and treat a *third* unescaped surface as automatic (a).** The per-surface
answer has now been tried twice and each time it closed the surface it was aimed at. One more failure
makes the pattern rather than the instance the defect, and at that point (a)'s cost is justified by
evidence instead of by a rule written in advance. ⚠️ **What (b) leaves genuinely open is the discovery
problem, not the fixing problem** — `M57` was found because a backlog row pointed at it, and no sweep of
"which surfaces render an untrusted value into a context whose default escaper is wrong" has ever been
run. **That sweep is worth doing under any of the three options** and is the cheapest next step whichever
way this is answered.

### D1 — Should the sixteen synchronous dispatch listeners become `ShouldQueue`? **Option 1 — leave them synchronous and say so in writing.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — option 1 — leave them synchronous and say so in writing, the recommendation.** The docblock rationale is filed as an `after-launch` `nit` (*"`WebhookEventDispatcher`'s and `ConnectorEventDispatcher`'s docblocks never say why the fan-out stays synchronous"*), and the moved-to line in the ledger records the answer.

**Filed 2026-08-25.** Moved here out of `docs/feature-backlog.md` § *Connectors & webhooks*, where
it sat as a `minor` row. It is **not** a defect with a known fix; it is an undecided question, and
it was also the only row an audit of all 62 open merge-gate rows confirmed as genuinely
cross-cutting.

**The facts, verified on `origin/main`.** Sixteen listeners — eight `app/Listeners/Webhooks/Dispatch*`
and eight `app/Listeners/Connectors/Dispatch*` — run synchronously inside the request, and nothing
has ever decided whether they should. `ConnectorEventDispatcher` already wraps `fanOut()` in
`TenantContext::runFor()`, so tenant context is not the obstacle.

**What makes it more than a one-line change.** `scripts/job-payload-lint.php` scans all of `app/`
in pass 1 and trips rule R1 — *"extends neither `TenantAwareJob` nor `MaintenanceJob`"* — on any
listener implementing `ShouldQueue`. Its only escape is an `EXEMPT_JOBS` entry **inside that
script**, because a listener cannot extend `TenantAwareJob`: that class's `handle()` is `final` and
it demands an abstract `$tenantId` payload hook. Separately,
`tests/Feature/Connectors/ConnectorFanOutTest.php:163` **hard-asserts** these listeners are *not*
`ShouldQueue`, so the current behaviour is deliberately pinned and whoever changes it changes an
assertion on purpose rather than discovering it.

**Options.**
1. **Leave them synchronous and say so in writing** — add the rationale to the fan-out docblock and
   close the row. Cheapest; the pinning test already encodes it, it just does not explain itself.
2. **Queue them**, adding `EXEMPT_JOBS` entries and re-pinning the test. Removes webhook/connector
   dispatch latency from the request, at the cost of weakening what R1 guarantees about `app/`.
3. **Queue only the connector eight**, leaving webhooks synchronous — same script cost, half the
   benefit, and two conventions where there is currently one.

**Recommendation: option 1 unless request latency is a measured problem.** Nobody has measured
that it is, the sixteen are cheap dispatchers rather than workers, and option 2 spends a real
structural guarantee (R1's coverage of `app/`) to buy something unquantified. If it is ever
measured and found to matter, option 2 is the right shape — not option 3.

### D3 — ADR-0020 §D7 approves *"4th of 12"* for every member. Three other surfaces withhold the twelve. Which moves? **Option 1 — withhold `of` from readers without `dashboard.org.view`.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — option 1 — withhold `of` from readers without `dashboard.org.view`, the recommendation.** Option 1 is what Lane B built in `M26` while this waited, so nothing changes and nothing is filed.

**Filed 2026-08-26 by Lane B, during `M26`.** Proceeding on the recommendation below rather than
waiting — Standing Rule 5. If the answer comes back the other way, the revert is one commit and it
is named at the bottom.

**The collision, in one payload.** `AchievementsController::__invoke()` emits
`progress.standing.of` — the workspace's active-member count — with **no permission at all**
(`:103`), and two fields later withholds `scoreboard` behind `can('viewAny', PointAward::class)`
(`:115-120`), whose gated `team.active_members` is **the same quantity**. The same controller's own
docblock (`:49-60`) argues at length that serving `team.active_members` ungated would be *"a
widening of an existing permission, performed by a new page"*. Both sentences are in one file and
they cannot both be right.

**It is a real disclosure, not theatre.** A Form Editor has no other route to that number:
`/dashboard`'s Members tile is nulled without `dashboard.org.view`
(`DashboardMetricsService:55,60`), `/members` needs `tenant.members.invite`
(`routes/tenant.php:409-410`), and the member search arm refuses the same three roles
(`MemberSearchArm:88-94`, docblock `:79`). It is also reachable off the page: every role may mint a
`read:gamification` token (`GamificationApiTest:104`) and `GET /api/v1/gamification/me` returns it.

**Why it happened — worth reading before choosing.** §D7's criterion is **names**: it gates *"the
**named** ranked list"*. K1e explicitly **replaced** that criterion for `team`, on the grounds that
plain workspace-wide counts are the sensitive thing, not just names. `standing.of` is the same
number under the replacement criterion and was never re-walked against it. So this is **not** a
disagreement with §D7 — it is §D7's line having moved once already, in a direction the product
chose, with one field left behind.

**Option 1 — withhold `of` from readers without `dashboard.org.view`; the label degrades to
"4th". (RECOMMENDED.)** No new permission key: it reuses the check already resolved two fields
away, so the 29-key catalog stays closed and both cross-lane parity gates stay still. `rank`
survives untouched, so §D7's actual grant — *a member sees their own position* — is honoured in
full. Cost: three of five roles see *"4th"* instead of *"4th of 12"*, and one existing test that
asserts the current behaviour for a `form_editor` changes.

**Option 2 — ratify: declare the headcount non-sensitive.** Honest about the fact that team size
is not much of a secret, and it costs no UX. But to be coherent it must **un-gate the other two**:
`kpis.members` on the dashboard and `team.active_members` on the achievements page. That is a
deliberate widening of `dashboard.org.view` across three surfaces to preserve one label, and it
contradicts the reasoning three separate increments wrote down.

**Option 3 — mint a `gamification.view_headcount` key.** Rejected in advance; §D7 rejected the
same shape for the same reason, and a thirtieth key means re-litigating which of five roles hold
it.

**Recommendation: option 1.** Every surface that ever *considered* this number withheld it; the two
that disclose it did so without deciding to. Option 1 aligns the outlier with the three, option 2
would move three to match the outlier, and only option 1 leaves §D7's actual promise intact.

⚠️ **The `/dashboard` half is NOT part of this question and is fixed either way.**
`DashboardController:124` emits `of` (and `rank`) into every dashboard payload, and `Dashboard.vue`
renders **neither** — they are declared at `:91` and never read. That is dead wire-level disclosure
with no product value, so it is deleted regardless of how D3 is answered.

**If the answer is option 2**, the revert is: restore `of` unconditionally in
`AchievementsController` and `MemberProgressResource`, drop the two negative tests, regenerate
`openapi.json`, and open a follow-up row to un-gate `kpis.members` and `team.active_members` — the
dashboard deletion above still stands.

### D4 — An archived webhook envelope has no form to be scoped to. Which permission reads it? **Option 1 — `webhooks.manage`, Owner and Admin.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — option 1 — `webhooks.manage`, Owner and Admin, the recommendation.** Option 1 is what is implemented, so nothing changes and nothing is filed.

**Filed 2026-08-26 by Lane B, during `M33`.** Proceeding on the recommendation below rather than
waiting — Standing Rule 5. The revert is one enum arm and it is named at the bottom.

**Why the question exists at all.** `M33` closes the row that `AttachmentPolicy::view()` is flat where
`SubmissionPolicy::view()` is scoped: `GET /attachments/{attachment}` read any stored object in the
tenant by id with no per-form check, so `form_editor` and `reviewer` — the two roles holding
`submissions.view` without `dashboard.org.view` — could read media on forms they had never
collaborated on, **and on forms they had been removed from.** For four of the six live kinds the fix
is mechanical: resolve the owner, apply the submission's own scope. **One kind has no owner the
scope means anything for.**

`WebhookPayloadArchive::archive()` writes an attachment owned by a `webhook_delivery`. A delivery
belongs to a tenant-configured endpoint, and **its envelope is the full outbound payload of whatever
form fired it** — so it does not belong to one form, it crosses every form boundary at once. There
is nothing to scope it *to*. Under the pre-M33 policy it was readable by **all five seeded roles**
on `submissions.view`, and it is servable: the row is written `ScanStatus::Skipped`, which
`servable()` admits, **under a comment at `WebhookPayloadArchive.php:67` asserting these bytes are
"never served to a browser."** The route makes that comment false, which is how the kind was found.

**The options, all three real.**

1. **`webhooks.manage` — Owner/Admin.** The authority that configures the endpoint the payload was
   sent to is the authority that reads what was sent. Narrows from five roles to two.
2. **`submissions.view` unchanged — all five roles.** Treat the envelope as submission data. Keeps
   today's behaviour, and keeps a tenant-wide cross-form read available to `form_editor` and
   `reviewer` — the precise pair the rest of `M33` exists to scope.
3. **`audit_log.view` — Owner/Admin.** Treat the envelope as a forensic record rather than
   configuration. Same audience as (1) today, but it would bind envelope access to the audit
   permission if those two ever diverge.

**Recommendation, and what is implemented: (1) `webhooks.manage`.** It is the smallest permission
whose holders are already trusted with the whole of what an envelope contains — a `webhooks.manage`
holder can already read every outbound payload by reconfiguring the endpoint, so this grants no new
authority to anybody. (2) is rejected because it leaves the increment's own defect open for one kind
while closing it for four. (3) is rejected as a coincidence of the current role matrix rather than an
argument: `audit_log.view` is about the audit trail, and an envelope is not in it.

⚠️ **This is a NARROWING, which is the class of change the J2d precedent says belongs to the user** —
recorded here for exactly that reason rather than decided silently in a policy file. It is
implemented rather than deferred because the alternative is leaving a live tenant-wide cross-form
read open while the question waits, and Standing Rule 5 exists to stop that trade.

**If the answer comes back the other way**, the revert is one arm of the `match` in
`app/Policies/AttachmentPolicy.php` — change `AttachmentKind::WebhookPayloadArchive` from
`$user->can('webhooks.manage')` back to `$user->can('submissions.view')` — plus the two cases in
`tests/Feature/Attachments/AttachmentPolicyTest.php` that name it (*refuses an archived webhook
envelope to a collaborating form_editor* and its owner-side positive control), and the §D10
paragraph in `docs/adr/0015-feedback-screenshot-capture.md`. No migration, no data, no client
contract: `openapi.json` is untouched by `M33`.

### D8 — A tracker surgery triggers no post-merge run at all. Which way should `ci.yml` regain the trunk observation? **Option 1 — a second, tiny workflow: `tracker-lint` only, on `push` to `main`, no path filter.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — option 1 — a second, tiny workflow: `tracker-lint` only, on `push` to `main`, no path filter, the recommendation.** The row awaiting it (*"A tracker surgery whose diff touches only `paths-ignore`d files produces no post-merge run"*) is unblocked with that remedy and its `Awaits` token struck.

**Filed 2026-08-31 by Lane A, during `M49`.** Filed rather than decided because every option trades
**CI minutes against gate coverage**, and `M39` removed those minutes deliberately after measuring
the cost. The row it comes from stays open in `docs/feature-backlog.md` until this is answered.

**The defect, stated once.** `ci.yml`'s `push` filter ignores `PROGRESS.md`, `PROGRESS_ARCHIVE.md`,
`docs/claims/**`, `docs/gate-baselines.md` and `docs/backlog-triage.md`, and GitHub evaluates it over
**every** file in the push. A pure permutation of the two tracker files — which is precisely what a
well-executed surgery is — therefore **cannot trigger CI on `main` at all.** The PR run still gates
the merge, so this is not a hole in the merge gate; what it removes is the **post-merge observation on
the trunk**, which is the only place a squash body's form can be verified. `M48` escaped it by
accident of scope, because a `scripts/` ratchet landed in the same commit.

⛔ **ONE OF THE TWO CANDIDATES THE ROW NAMED CANNOT BE WRITTEN, AND THAT IS MEASURED RATHER THAN
ARGUED.** *"Exempt a commit whose message carries the marker"* has nowhere to be expressed: a
workflow's `paths-ignore` is evaluated by GitHub **before a run exists**, over the pushed file paths
and nothing else. It has no access to a commit message. So the real field is three, not two.

**Option 1 — a second, tiny workflow: `tracker-lint` only, on `push` to `main`, no path filter.
(RECOMMENDED.)** Roughly one minute against the full pipeline's ~18, so `M39`'s measured cost is not
re-incurred; the trunk arm becomes reachable for exactly the diff shape it guards; and it is additive,
so nothing about the existing pipeline changes. Cost: a second workflow file to keep in step with the
first, and one more run appearing in `gh run list` — which anything counting *"six completed checks"*
must not mistake for a seventh required context.

**Option 2 — drop `PROGRESS.md` and `PROGRESS_ARCHIVE.md` from the filter.** One line, no new file,
and the post-merge run is the real pipeline rather than a slice of it. Cost: **every close-out queues
the full ~18-minute pipeline again**, which is what `M39` removed after measuring six cancelled runs;
a close-out pushes documentation two or three times per increment, so this is the expensive option and
it re-opens the `deploy.yml` trigger question `M39` closed.

**Option 3 — a process rule: a surgery must deliberately touch one non-`paths-ignore`d file.** Costs
nothing and changes no configuration. It is also **a reminder rather than a mechanism**, which is the
class this project has repeatedly found insufficient — Rule 7(g)'s stale ADR number survived
twenty-three increments as prose. It would work, right up until the increment that forgets.

**Recommendation: option 1.** It buys the observation for about a minute a push and does not disturb
a filter that was added for a measured reason. ⚠️ **Not proceeded on**: it adds a workflow to a public
repository and changes what runs on the trunk, and `D7`'s branch-protection question may make the
required-contexts count matter — so the two are better answered together than separately.

➕ **THE STATED BLOCKER IS NOW HALF-GONE, AND `M60` NOTED IT WITHOUT TAKING THE DECISION
(2026-09-02).** This entry defers partly on the grounds that *"`D7`'s branch-protection question may
make the required-contexts count matter — so the two are better answered together than separately."*
**`D7` is answered and applied**: the ruleset names six required status checks. So option 1's stated
cost — *"one more run appearing in `gh run list`, which anything counting six completed checks must
not mistake for a seventh required context"* — is now concrete rather than speculative, and it
resolves in option 1's favour: a second workflow is **not** a required context unless the ruleset is
edited to name it, and nothing about the six changes. The remaining objection is the one that was
always the user's: it adds a workflow to a public repository.

⚠️ **AND `M60` IS EVIDENCE BOTH FOR AND AGAINST OPTION 3, WHICH IS WHY IT IS RECORDED HERE RATHER
THAN USED AS AN ARGUMENT.** `M60` needed the trunk observation — it owed the end-to-end squash proof
`M47` and `M48` had each handed forward — and got it, because its `TRACKER_BYTE_CEILING` ratchet put
`scripts/` in the same push. That is option 3 working. But it worked *because this surgery happened
to owe a ratchet*, exactly as `M48` escaped the same hole *by accident of scope*. **Two for two on
coincidence is not a mechanism**, and a surgery that needs no ratchet still merges with its marker
unverifiable. The recommendation stands at option 1, and the decision is still not proceeded on.

### D9 — Should the legacy client's identity be rewritten out of git history as well? **RECOMMENDED AGAINST.** **Option 1 — leave history alone.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — option 1 — leave history alone, the recommendation.** Nothing is filed.

**Filed 2026-08-31 by Lane A during `M51`, unconditionally and without being asked**, because `D6`'s
answer redacts the **working tree** and the repository is public. A redaction that reduces an exposure
without closing it must say so and must name the remaining question, or the next reader will assume the
material is gone. `D6`'s original defect was a deadline that expired unnoticed and let the default win
by silence; **closing it with wording that implied the material had been erased would be that same
defect pointing the other way.**

**The facts.** `M48`'s secret scan read the repository's whole history — hundreds of commits — which is
how it produced 818 findings on its first real run. That is direct evidence the history is readable, and
it is the reason this entry exists rather than an inference about GitHub. The redacted strings remain in
every commit that ever carried them, reachable by anyone who clones.

**Option 1 — leave history alone. (RECOMMENDED.)** The working-tree redaction is what a reader, a search
engine and a casual clone see; the history requires deliberate archaeology. Three costs make the
alternative a bad trade:

1. ⛔ **A force-push across the whole repository is the largest possible instance of the
   mechanical-operation class this project already gates.** `R7` exists because one splice deleted 1,086
   lines and merged green; `mutate.php` exists because a restore that looked right was not. A history
   rewrite is that class at maximum blast radius — **and no gate here would catch it going wrong**,
   because every gate compares against a history the operation has just replaced.
2. ⛔ **It changes every sha, and three separate mechanisms are keyed to shas.** `state.php`'s
   merged-pull-request-title cross-check — the *second, independent* source for the increment number —
   is resolved against commits; `R7`'s evidence is blob sizes and commit messages at specific shas; and
   `.gitleaksignore`'s fingerprints are **commit-scoped**, so every one of them silently stops matching
   and the secret scan starts reporting findings that were already adjudicated. Two of those three are
   the machinery this series spent `M48`, `M49` and `M50` building.
3. **The exposure is not live-exploitable.** The material is architectural criticism of a legacy project
   **the owner owns** — a schema audit and a deployment-posture inventory — not credentials, tokens or
   personal data. Nothing in it can be used against a running system, and the secret scan found no real
   secret.

**Option 2 — rewrite history (`git filter-repo`), then force-push.** Closes the exposure completely.
Costs all three of the above, plus: every existing clone and fork diverges permanently, open pull
requests are invalidated, and the operation is **irreversible in practice** once collaborators fetch.

**Option 3 — make the repository private.** Closes the exposure without touching history. Rejected here
for the same reason `D6` rejected it: it is a much larger decision about the project, and it would
silently remove the free-Actions-minutes premise several CI decisions rest on.

**Recommendation: option 1**, and it is **not being proceeded on in either direction** — nothing is
rewritten and nothing further is redacted until this is answered. ⚠️ **The honest framing is that this
is a cost/benefit call, not a security emergency.** If the answer is option 2, it should be taken as a
deliberate, scheduled operation with the three keyed mechanisms re-derived afterwards — not folded into
an increment.
⚠️ **Annotated 2026-09-17 during `M98`, after D48.** Option 3 is no longer a refusal: the user answered that the
repository stays public with fake data only through testing and goes private before any real data, so the exposure
weighed here stops growing at that flip while every commit that ever carried the redacted strings stays readable in
the history behind it. That sharpens the trade rather than settling it — a rewrite would close a window that is
closing anyway — so this stays open and recommended against.

---

### D29 — `M90` made `saveAsTemplate()` the FIRST request-path isolation-level change in this codebase. Should that become the pattern for the other multi-statement snapshot reads, stay a one-off, or be replaced by a different instrument? **Option 1 — adopt it as the pattern.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — option 1 — adopt it as the pattern, the recommendation.** The row awaiting it (*"Two more callers of the snapshot serializer still read a torn tree"*) is unblocked: both wrappers, plus the rule stated in `docs/form-versioning-schema-migration.md`.

**Filed 2026-09-10 by Lane A during `M90`, while closing `docs/feature-backlog.md`'s torn-snapshot row.**
Filed rather than decided because it sets a precedent on the request path, and the alternative to deciding
it is that the next person copies `saveAsTemplate()` without knowing it was a first.

**What was measured, before the options.** `SchemaSnapshotSerializer::snapshot()` issues exactly three
reads. Outside a transaction they are three separately autocommitted statements, so a concurrent builder
edit between them yields a blueprint describing a tree that existed at no instant. ⛔ **A plain transaction
does not fix it** — no `pgsql` connection in `config/database.php` pins an isolation level, so READ
COMMITTED applies and each statement takes its own fresh snapshot. ⛔ **And `lockForUpdate()`, the
instrument `M89` used on the publish path, would be worse than useless here**: the `draft_child` policy
applies its `USING` expression to a locking SELECT as a FILTER, and both entry points admit a NON-DRAFT
version, so it would persist an EMPTY blueprint with no error and no failing test.

`REPEATABLE READ` is what shipped. It is status-blind, takes no lock, and cannot raise a serialization
failure on this path because the transaction's only write is an INSERT of an unrelated row.

⚠️ **The precedent it leans on is thinner than it looks, and that is why this is a question.**
`set transaction isolation level` occurs **once** in the whole tree before `M90` —
`TenantExtractService`, reached only from a console command, over a 41-table offline extract
(`docs/adr/0018-per-tenant-extraction.md` §D6). Choosing a non-default isolation level on a synchronous
web + API POST has no in-repo analogue at all.

⚠️ **And it is not testable from the suite.** `SET TRANSACTION ISOLATION LEVEL` must be a transaction's
first statement, so it is guarded on `DB::transactionLevel() === 1` and skipped when nested — which under
`RefreshDatabase` is always. The shipped gate pins the transaction BOUNDARY, not the level.

**The options:**

1. **Adopt it as the pattern** — apply the same wrapper to `XlsformExporter::build()` and
   `FormBuilderService::saveFieldToLibrary()`, and state in `docs/form-versioning-schema-migration.md`
   that any multi-statement canonical read takes one. Cost: two more request paths at a non-default
   isolation level that no test can observe. **Recommended**, because the alternative is three call sites
   of one serializer with three different consistency stories, and the two remaining ones are already
   filed as a defect row.
2. **Keep it a one-off.** Only the persisted, user-facing capture gets it; the exporter's output is
   transient and the library item's realistic tear is one filtered validation rule. Cost: the next reader
   has to work out why one caller is wrapped and two are not, and the row stays open.
3. **Replace the instrument with a single-statement read.** Rewrite the serializer to fetch the tree in
   one query and drop the isolation level entirely. Cost: a real rewrite of a file three surfaces depend
   on, for a `minor` row — and it would be the largest change of the three.

⚠️ **Whichever is chosen, the testability gap is not closed by any of them.** If the level itself must be
provable, that is a fourth piece of work (a committing harness or a read-back manifest like
`TenantExtractService`'s), and `docs/testing-strategy.md` §8 records what it would cost.

### D30 — Which version should the builder's request-layer uniqueness rules be scoped to, now that both available answers are wrong inside the race? **Option 1 — leave both rules as they are and say so in a comment.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — option 1 — leave both rules as they are and say so in a comment, the recommendation.** The comment is filed as a `during-testing` `nit`.

**Filed 2026-09-10 by Lane A during `M90`.** The backlog row that prompted it said this half "needs its
own decision", `M90`'s first pass concluded that was wrong, and **the adversarial pass restored the row's
own judgement** — which is why it is here rather than fixed.

**What was measured.** `UpdateFieldRequest` and `UpdateSectionRequest` scope their `Rule::unique(...)` on
`$form->draft_version_id`, read off the route-bound model. If a publish commits between binding and
validation, that names a version that is now frozen.

⛔ **The obvious repair — scope on the child's own `form_version_id` — is not an improvement.** Outside the
race the two are the same value. Inside it, the child's version is now PUBLISHED and immutable under §2/§4
and the RLS `draft_update` policy, so the rule would be checking key uniqueness inside a version no write
can ever reach. Both answers are wrong in the race, in opposite directions.

✅ **Nothing is corrupted either way, and that is what makes this a choice rather than a defect.** `M88`'s
re-reading guard refuses the write afterwards regardless, so the only thing at stake is **which 422 the
user sees** — a uniqueness complaint about the wrong version, or the draft-guard's own message.

**The options:**

1. **Leave both rules as they are and say so in a comment.** The guard is the real gate; the request layer
   is a convenience that produces a slightly wrong message in a race nobody has reported. **Recommended** —
   it is the only option that adds no code, and the message it produces is wrong in a way that still tells
   the user to reload.
2. **Re-read the form inside the rule.** Scope on a freshly-read `draft_version_id`, so the rule is right
   in the race. Cost: a query per validated request on the builder's hottest write path, to improve an
   error message that is already followed by a refusal.
3. **Make the request layer a refusal door.** Have the rules fail explicitly when the bound version is no
   longer the draft, so the user gets the draft-guard message from the request layer rather than from the
   service. Cost: it duplicates the guard, and duplicating a guard is how the two copies drift.

⚠️ **`D27` is adjacent and does NOT cover this.** That decision is scoped to the submission save/submit
doors; this is the builder's validation layer. They should not be answered as one.

### D56 — A workspace Owner's two-factor reset clears the member's second factor in EVERY workspace they belong to. Should the Owner surface be narrowed? **A — leave it as built, with C revisited at launch.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — A — leave it as built, with C revisited at launch, the recommendation.** The revisit is filed as a `before-launch` row, and the threat model's §9 no longer calls this open.

**Filed 2026-09-20 by `M107` while building `D37`'s answer, which did not consider this.** `D37` chose an
admin reset performed by *"a workspace owner or the platform operator"*, and `M107` built both. The fact
neither the decision nor the row it came from noticed is that **`two_factor_secret`,
`two_factor_recovery_codes` and `two_factor_confirmed_at` live on the global `users` table**, which has no
tenant column. There is no per-workspace second factor to clear, so there is no narrower act available: an
Owner clearing Alice's enrolment clears it for every workspace Alice is in.

**What it does and does not grant.** It grants the Owner **no access** — Alice keeps her password and they
never learn it. What it does is lower another workspace's authentication assurance without that workspace
being told, and the audit row lands in the acting tenant's ledger rather than theirs. It is the only place
in this product where an Owner's authority leaves their own boundary.

**Shipped in the meantime, and deliberately the honest rather than the safe default:** the Owner surface is
live, its confirm dialog says *"This applies to their account everywhere, including any other workspace they
belong to"*, and `docs/security-threat-model.md` §9 item 12 records the gap. A tester locked out of their
account is a problem today; a multi-workspace tester is not, because the testing site is one workspace
(`D46`).

- **A — leave it as built, and revisit before launch.** The dialog states the consequence and the act is
  audited. Costs nothing now; the exposure arrives with the first person who belongs to two workspaces.
- **B — operator-only.** Remove the Owner route and leave the console path. Narrower than `D37`'s own
  answer, and it puts every locked-out tester in a queue behind one operator — which is the support burden
  `D37` was answered to remove.
- **C — refuse a target who belongs to a second workspace.** Closes the cross-tenant effect exactly. ⚠️ **The
  refusal itself discloses that the person is a member somewhere else**, which is a fact this product is
  otherwise careful never to state — `ImpersonationController` collapses all its refusals into one message
  for precisely that reason. It also sends the hardest cases to the operator anyway.

**Recommendation: A, with C revisited at launch.** The effect is real but grants nothing, the dialog is
honest about it, and the testing site is a single workspace so nobody can meet it yet. `C` is the right
end state and needs a disclosure-safe refusal designed first, which is a decision rather than a patch.

### D73 — Single-form OCR sends each scanned page, which carries a respondent's handwritten answers, to Google Cloud Vision, and neither the threat model nor any text in the product says so. What does that data flow need before launch? **A — document it.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — A — document it, the recommendation.** Filed as a `before-launch` row: the threat-model row for the external processor and the module sentence. The reserved ADR the bake-off writes can carry the processor facts.

**Filed 2026-10-03 by `M128`, from building the reading job.** The reading job posts each stored page to `vision.googleapis.com` over TLS, with the workspace's scan in the request body and the API key in a header, and keeps Google's full response privately beside the scan so the matcher can be re-run without a second call. `docs/security-threat-model.md` lists OCR scans only as untrusted uploads; it has no row for a third party that receives respondent data. Nothing on the paper form, in the OCR module's description or in the workspace settings tells anybody that a scan leaves the server. Testing under `D72` uses made-up answers, so nothing real is sent before Oct 12, and this does not block the testing.

- **A — document it.** A threat-model row for the external processor (what is sent, what is kept, for how long, under which key), and one sentence in the OCR module's description: "Scans are read by Google Cloud Vision." Nothing respondent-facing, because staff decide to scan.
- **B — A, plus notice at collection.** When a form accepts scans, its printed blank form says that answers may be read by an automated service.
- **C — keep scans in-house.** Replace Cloud Vision with a recognizer that runs on the server. This is a different product decision, with an accuracy cost the samples would have to measure.

**Recommendation: A**, with B added if the deploying agency's privacy officer asks for notice at collection. A is what the threat model owes in any case, and B is a printed sentence that can be added without touching the reading path.

### D101 — Archiving is one-way and, since `M146`, closes the public link; there is no un-archive. Should a form be restorable from the archive? **A — no; archive stays terminal for the testing window.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M147` — A — no; archive stays terminal for the testing window, the recommendation.** B is taken only if a tester asks, so nothing is filed.

**Filed 2026-10-08 by `M146`, silently under `D66`, from `D99` A's third clause** — "un-archiving reopens the link" — which named a feature that does not exist: no method, route, policy arm or UI un-archives a form, `RestoreService` and the XLSForm importer refuse an archived one, and the state diagram ends at `Archived`. `M146` built the two clauses that exist (the link closes; a queued offline response parks) and made the archive dialog say that archiving cannot be undone. No row is blocked on this; a tester who archives by mistake has no way back today beyond the database.

- **A — no.** Archive stays terminal and the dialog says so (what `M146` ships). The form's versions and responses stay readable from the inbox, the exports and the analytics pages.
- **B — a Restore action.** An *Archived* filter on the forms list and a Restore action that sets the form back to `published` (or `draft` when it was never published), reopens the link when guest access is still on, and cuts a new draft on demand — `FormPresenter`, `FormListFacets`, `FormService`, a policy arm and an audit event.
- **C — B, with the link staying closed until the author reopens it by hand.**

**Recommendation: A** for the testing window; **B** after it if a tester asks, with C's clause if a deliberate reopen is wanted.

### D99 — An archived form keeps collecting responses through its public link while the forms list hides it. What does archiving do to the link? **A — archiving closes the link.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M146` — A, the recommendation.** Put to the user first thing that session because `R-f6567fc2` was blocked on it, and built in the same increment. ⚠️ **Two of A's three clauses held and one named nothing:** the guest routes answer an archived form as they answer one with guest access off (`Form::allowsGuestAccess()`, one predicate for every route and for `GuestReachability`); a queued offline response parks on its FIRST replay, which is stronger than "refused like any closed form" (a closed form is retried five times, then parked); and **"un-archiving reopens the link" has nothing to attach to — there is no un-archive anywhere**, so the archive dialog now says archiving cannot be undone and the question is `D101`.

**Filed 2026-10-07 by `M145`, silently under `D66`, from `R-f6567fc2` (a `major`, `during-testing`), which reads `ready` while its remaining work is this choice.** `FormService::archive()` discards the draft and leaves `allow_guest_submissions` and `current_published_version_id` as they were; neither `GuestFormController::mint()` nor `GuestSubmissionController::store()` reads the form's status, so `/f/{slug}` still mints, renders and accepts; `FormPresenter` leaves archived forms out of the list. Responses arrive on a form its author can no longer find. The row carries `**Awaits D99.**`, so the line shows it blocked on this rather than ready.

- **A — archiving closes the link.** The guest routes answer an archived form with the same 404 they give a disabled one, a queued offline response to it is refused like any closed form, and un-archiving reopens the link. The list stays as it is.
- **B — the list shows archived forms that still collect.** A badge on the card, and an archived form keeps its link until the author disables guest submissions by hand.
- **C — archiving asks.** A confirmation that offers to close the link or keep it, remembered per form.

**Recommendation: A.** It is what every tester will assume "archive" means, it is the smaller change, and B leaves the trap in place with a label on it. A changes nothing for a form that is not archived, and the testing server has no archived form collecting today.

### D100 — An export, a Google Sheets sync and an Airtable sync write every answer inside a repeat group as an empty cell. What shape does a repeat group take in a spreadsheet? **A — one row per response, each repeat question's instances joined into one cell, the same on every channel.**

**Answered 2026-10-08 (user decision, in chat), recorded by Lane A during `M146` — A, the recommendation.** Put to the user first thing that session because `R-6c0f0e56` was blocked on it, and built in the same increment: `SubmissionRowProjector` joins a member's instances with ` | ` IN POSITION (an instance that left the member blank still contributes a part, so `Kid A | Kid B` beside ` | Cooking` says which kid cooks; all-empty is `''`), each instance formatted through `displayValue()` so a multi-select keeps its `; `. B (a Kobo-style sheet per repeat) would build on that cell format rather than replace it.

**Filed 2026-10-07 by `M145`, silently under `D66`, from `R-6c0f0e56` (a `major`, `during-testing`), which reads `ready` while its remaining work is this choice.** `SubmissionRowProjector::resolveColumns()` makes a column for every data field, repeat members included, and `answerValues()` reads each at the top level of the stored answers — but a repeat group's answers are stored under the SECTION key as a list of instances, so the lookup always misses and every caller (`SubmissionExporter`, `GoogleSheetsConnector`, `AirtableConnector`, the automation webhook) writes blanks. The row carries `**Awaits D100.**`.

- **A — one row per response, each repeat question's instances joined into one cell** (`Kid A | Kid B`), the same on every channel. Smallest change; a Sheets or Airtable table has one row per response in any case.
- **B — Kobo-style: the file export gets one sheet per repeat group** with a column naming the parent response and the instance number, while Sheets and Airtable take A. Analysts who know Kobo expect it; the file export grows a second sheet per repeat.
- **C — one row per instance in the main sheet**, the response's other answers repeated on each. Readable for one repeat, wrong for two.

**Recommendation: A** for the testing window, because it fixes every channel at once and loses nothing a reviewer cannot read; B after testing if analysts ask for it, built on A's cell format.

### D57 — Reported item 16 asks for three ways to present sections; `forms.single_page_mode` is a boolean that expresses two of them and "tabular" is not expressible at all. What is the model? **A now, C next — ship the two modes the boolean already expresses, then make "per page" real by honouring `page_break`.**

**Answered 2026-09-26 (user decision, in chat), recorded by Lane A during `M114` — A now, C next.**

**Filed 2026-09-25 by `M110`, from a user-directed exploration of the running system.** The request was *"form also must have an option on how to segregate sections. is it a pager but sectioned, is it a tabular layout or a per page setup."* `D35` already answered the adjacent question — add the single-page setting, default step by step — and `M110` files its write path as a row. But `D35` was about a boolean, and this asks for three modes. ⚠️ **Two of the three are not what they sound like.** "Paged but sectioned" is today's `StepView` and is already built. "Per page" is *also* `single_page_mode = false`, but with a break per question — and `visibleSteps` (`useFormRuntime.ts:405-462`) steps by **section only**, while `page_break` sits in `RENDERS_NOTHING` (`engine/field-roles.ts`) and is ignored entirely by both engines. "Tabular" has no renderer on either side.

- **A — keep the boolean, ship the two modes it already expresses, and file "tabular" as a product idea.** Costs one write path (which `D35` already authorised) and nothing else. Leaves "per page" meaning the same thing as "paged but sectioned", which is a naming disappointment rather than a missing capability.
- **B — replace it with a `presentation` enum (`stepped | single_page | tabular`) and build a tabular renderer.** Matches the request literally. ⚠️ But `visibleSteps` steps by section in **both** engines, and the server's `StepProjection` moves with it — so this is a two-engine parity change, with golden vectors, for a mode nobody has described a use for beyond naming it.
- **C — keep the boolean and add an orthogonal `page_break_mode`, so "per page" becomes real by finally honouring the `page_break` field type the step projection currently ignores.** Redeems a field type that exists, is in the palette, and today does nothing at all — which is its own latent defect.

**Recommendation: A now, C next.** A is the part `D35` already bought. C is the answer to the half of the request that is genuinely missing, and it is cheaper than B because `page_break` already exists as a type and a palette entry — the work is teaching two step projections to break on it, not inventing a mode. B invents a renderer from an example rather than a need; if tabular is genuinely wanted, it should be asked for on its own evidence.

⛔ **AMENDED 2026-10-01 (user decisions, in chat), recorded by Lane A during `M123` — WHAT "C NEXT" MEANS.** C's own text adds an orthogonal `page_break_mode`, while the recommendation says the work is teaching the step projections to break, *"not inventing a mode"* — so `R-8c517fb6` was silently deciding which, along with what the staff page does. Two questions were put to the user under `D66`, because that `early-testing` work row was blocked on them, and both answers were the recommendation:
- **Page breaks paginate AUTOMATICALLY in every step-by-step form.** No `page_break_mode` and no new setting. A live stepped form that already contains a page break starts splitting on the next deploy, without a republish; single-page mode is unaffected.
- **Staff manual entry (the encode page) stays one step per section for now.** The respondent form and the builder preview split; the encode channel can follow later if testers ask.
- `R-8c517fb6` is buildable from here and carries the design detail; it is queued for `M124`.

---

### D59 — `ComparisonOperator` is about to gain a human label, and `ConditionRow.vue` already has a different one for the same eight operators. One set or two? **C — one enum in PHP with two renderings, `label()` and `sentenceLabel()`, both shipped through `BuilderPresenter::enums()`.**

**Answered 2026-09-26 (user decision, in chat), recorded by Lane A during `M114` — C.**

**Filed 2026-09-25 by `M110`, from reported item 4** (*"i noticed the operator. if we can show the real meaning not the gt for greater than"*). The validation editor renders operators through `BuilderPresenter::humanize()` (`:254`), which is `ucfirst` over an underscore replacement — so the dropdown literally reads `Gt`, `Lte`, `Neq`. Two tabs away, `ConditionRow.vue:180-210` already ships proper labels (`has at least`, `is blank`, `does not include`) **and** filters them by context. ⚠️ **The two sets cannot simply be merged, because they are not the same sentence.** A rule row reads *"Maximum value · at most · 100"*; a condition reads *"Age is at least 18"*. `at most` is wrong in the second and `is at most` is wrong in the first.

- **A — two sets, each at its own point of truth, pinned by a key-coverage test.** Cheapest, and honest that they are different renderings. Leaves two places to edit when an operator is added, which is the drift shape this repository has measured six times on the client type mirrors.
- **B — one set in PHP; `ConditionRow` adopts it.** One source. ⚠️ Changes eight strings an author has already learned, and any E2E locator reading them.
- **C — one enum in PHP with two renderings — `label()` and `sentenceLabel()` — both shipped through `BuilderPresenter::enums()`.** One source of truth, both readings preserved, one extra method.

**Recommendation: C.** It is B's single source with A's two readings, for the cost of one enum method, and it removes the drift surface entirely rather than agreeing to watch it. ⚠️ The symbol belongs with the label, not beside it: `at most (≤)` as one string, so no caller can render the symbol without the words or pair them wrongly.

---

### D63 — Form settings become reachable from both a builder modal and a hub tab. Two entry points — acceptable? **A — both: a Settings tab on the form hub and a "Form settings" modal on the builder toolbar, mounting the same child components against the same routes.**

**Answered 2026-09-26 (user decision, in chat), recorded by Lane A during `M114` — A.**

**Filed 2026-09-25 by `M110`, from reported item 13** (*"the form itself doesn't have a settings section"*). ⚠️ **The obvious fix is the one already refused.** Folding the five settings routes into one was declined in writing, in three separate FormRequest docblocks; and the builder's missing tab strip is a recorded user decision, restated at `types.ts:162-170`. Both records still hold, and `M110` files the row to respect them: every section keeps its own route and its own FormRequest, and what changes is only where an author *finds* them. That leaves one question the records do not answer.

- **A — both.** A Settings tab on the form hub (for administering a form) and a single "Form settings" modal on the builder toolbar (for authoring one), mounting the **same child components**. Nine ungrouped toolbar buttons become one, which is also what frees the room the live preview needs.
- **B — builder modal only.** One entry point. ⚠️ Hides settings from somebody administering a form they are not editing, which is a different person on a scoped deployment.
- **C — hub tab only, with a link out of the builder.** One entry point, and the tab strip already exists. ⚠️ Costs an author a navigation away mid-authoring — which is where the complaint started.

**Recommendation: A.** The usual objection to two entry points is drift, and drift is impossible here because both mount the same components against the same routes; there is no second implementation to disagree. ⚠️ `builder-layout.test.ts` pins all three spellings of the eight secondary toolbar actions with a per-line regex — collapsing the toolbar **must update that test in the same PR**, and `templates-axe.spec.ts` requires Publish to keep its slot text.

---

### D64 — Type conversion across incompatible value shapes — a Photo becoming Short text. Refuse, or allow with total loss? **A — refuse; the target list offers only shape-compatible types, plus `note` and `hidden`, which are always allowed.**

**Answered 2026-09-26 (user decision, in chat), recorded by Lane A during `M114` — A.**

**Filed 2026-09-25 by `M110`, from reported item 19** (*"flexibility to change input type without removing all the encoded rules or configs … unless the input is completely different and impossible to transfer"*). The user's own sentence carves out the impossible case; this decides where the line sits. Today no conversion exists at all — the workaround is delete-and-re-add, which cascade-deletes every validation row, mints a new key from `uniqueKey()`, and dangles every `${key}` reference in the version.

- **A — refuse; the target list offers only shape-compatible types** (plus `note` and `hidden`, which are always allowed because "turn this question off" is the move an author actually wants). Geo, Media and Grid convert only within their own shape.
- **B — allow anything, behind a confirm dialog naming everything that will be lost.** Maximum flexibility, and no published submission is affected either way because published versions are frozen — the loss is authoring-time only, which is what makes B tempting.

**Recommendation: A.** The loss argument does not settle it, because B's real cost is not data. ⛔ **An unfiltered 31-item dropdown IS reported item 4 wearing a different control** — the same complaint that validation rules must not offer `min_length` on a date. Offering "convert this photo to a phone number" is the same defect in the same session, and A is the same remedy. The compatible set is still wide: every choice type converts to every other choice type carrying its options and their translations, and the whole text and number families are lossless within themselves.

⛔ **AMENDED 2026-10-01 (user decisions, in chat), recorded by Lane A during `M121` — WHERE THE SHAPE LINE ACTUALLY FALLS.** Building the engine showed that `A` did not decide the boundaries: `ValueShape` puts `likert_scale` (Scale), `yes_no` (Boolean) and `cascading_select` (Hierarchy) outside Choice, while the recommendation's *"every choice type converts to every other choice type"* and the overhaul plan's lossy cascade↔choice rows assumed otherwise. Four questions were put to the user and every answer was the recommendation:
- **The choice family is the four OPTION-LIST types** — `single_select`, `multi_select`, `dropdown`, `likert_scale` (`ValueShape::carriesOptionList()`). They convert among each other carrying their options and each option's `label_translations`. `yes_no` and `cascading_select` convert **only** to `note` or `hidden`; flattening cascade levels is refused, as `A` refuses loss.
- **`note` and `hidden` are TWO-WAY.** "Always allowed" holds as a target and now as a source too, so a parked field can be turned back — which is what lets the builder's undo reverse a conversion through the same endpoint. That includes `note`/`hidden` → `calculated`, with a warning that a formula must be added, because publish does not catch an empty one.
- **`calculated` converts only to `note`/`hidden`, and `page_break` converts to and from nothing.** `integer`↔`decimal` is lossless. ⚠️ **`duration` is its own `ValueShape`, so under `A`'s own words it too converts only to `note`/`hidden`** — recorded here as the literal reading, not as a fifth question.
- **The row is split:** `M121` ships the engine (`app/Support/Forms/FieldTypeConversion.php`), `M122` the request, controller and routes.
- **Amended 2026-10-18 by `D145`:** `yes_no` converts to `single_select` (choices Yes and No) — the one exception to its Isolated family — and back, for undo only.

---

### D66 — The generated line offers open decisions and decision-blocked rows as work to take, and the raw counts read as a demand to decide all of them at once. When is a filed decision put to the user? **Narrow — only when the tier being worked holds a work row blocked on it.**

⛔ **SUPERSEDED 2026-10-08 BY `D102`** — a decision is now asked in the session that files it, not only when the tier being worked is blocked on it.

**Filed and answered 2026-09-26 (user decision, in chat), recorded by Lane A during `M114`.** Shown the generated line, the user read its `blocked` and `waiting on you` counts as *decide all of this right now* and said *"for future tasks, you will just ask me for decisions when it is already needed."* ⚠️ **That reading was reasonable, and the line is what made it so:** it uses the same two words for a question nobody has ever seen and for a row whose precondition merely does not hold. Measured against this tree, of **132** blocked rows **61** are latent preconditions, **18** are not-live, **37** are decisions and **16** are rows awaiting one — and only **four** of those sixteen sat in the tier being worked.

⛔ **The trigger is a WORK row, not the decision row.** Both readings were put to the user and the narrow one was chosen. `D58` is the case that separates them: it is itself tiered `early-testing`, but the only row awaiting it (`R-f0c5b682`) is `during-testing` — so under this answer `D58` is **not** put to the user now, and `M114` records that as the rule first application rather than leaving it to be re-derived.

**The imperative half is in `CLAUDE.md`,** under *Taking a row*: file a decision silently with its options and a recommendation, and put it to the user only when the worked tier holds a row blocked on it — never read the whole board at them. **The generator half is `R-98b2d568`** (*"The Next section and the hand-off list open decisions as work to take"*), which this decision **retiers `during-testing` to `early-testing`**, with the reason recorded at the row. ⚠️ `M98` had argued `during-testing` for its sibling because *"no tester sees any of this"* — true, and beside the point: the noise falls on the user directly, which is what this instruction is about.

---

### D67 — When the rule engine fails on a respondent's device, every conditional question is shown with no conditions applied. Does the guest form warn and let them submit, or refuse to submit? **A — warn, and keep Submit working.**

**Filed and answered 2026-10-01 (user decision, in chat), recorded by Lane A during `M121`.** It decides `R-95a94b36` (*"A respondent is never told the rule engine failed"*), whose own text left the question open. ⛔ **ADR-0008 §D4 did NOT settle it, although a first reading said it did:** §D4 says a completed submission is never rejected because the tenant exceeded quota, and the route comments at `routes/api.php` apply that to route gates — neither speaks to a client-side guard, and the client already refuses submit on field errors.

- **A — warn.** Show a notice that some rules could not be checked, so the respondent may see questions that do not apply, and leave Submit working. The server stays the authority, which is how the degrade was designed, and it is exactly what the staff encode page already does. Cost: if the server's engine fails too, the respondent fills the form and then meets *"A form expression could not be evaluated."*
- **B — refuse.** Show the notice and block Submit; save-for-later stays. Cost: any fault confined to the device becomes a hard block on a response the server would have accepted, and it only saves effort when the fault is visible on arrival — a broken answer check surfaces mid-fill.

**Recommendation: A**, and **A was chosen.** ⚠️ The engine's failure latch never resets, so the notice's copy is hedged (*"you may see questions that don't apply"*) to stay true after an input-dependent failure clears.

---

### D68 — The app sidebar is to be collapsible. Where does the collapsed state persist — on the server for the user, or in the browser for the device? **B — in the browser, per device (`localStorage`).**

**Answered 2026-10-01 (user decision, in chat), recorded by Lane A during `M122` — B, the recommendation.** Put to the user under `D66`'s rule, because `R-33c7fd56` — an `early-testing` work row — was blocked on it by name. The row's `Awaits D68` token is stripped in the same push, and its remedy is now the `localStorage` one: no migration, no PHP, and the two hub files the option names.

**Filed 2026-10-01 by `M121`, from re-verifying `R-33c7fd56` (the collapsible sidebar, the overhaul plan's `B4`).** The row and the plan's title both say server-side, but that was a recommendation carried forward, never a decision: nothing here or in the plan's decisions records it. The only recorded basis is PRD Feature #9's *"stored server-side … not merely in browser local storage"*, which was written for the theme axes.

- **A — server-side**, a fifth column on `user_ui_preferences` through `PATCH /settings/appearance`. Follows the person across devices and workspaces. Costs a migration, four hub files, and a full page round trip per toggle — which on the builder can cancel an in-flight publish response — and no e2e spec may click it, because every spec shares one seeded user and the row would leak silently into every later desktop scan.
- **B — `localStorage`**, per device and per workspace. No PHP, no migration, two hub files, testable end to end because storage is per browser context. It is the first stored client preference in `resources/js`, and a collapse chosen on a laptop does not follow the person to a desktop monitor.

**Recommendation: B.** A collapse is a screen-size preference, closer to the drawer state than to a theme, and it is the cheaper and the more testable of the two. If the person wants it to follow them across devices, A is right and costs what it says.

---

### D69 — When a note carries content blocks, does the respondent still see the note's label? **B — author-only once the note has blocks.**

**Answered 2026-10-01 (user decision, in chat), recorded by Lane A during `M122` — B, the recommendation.** Put to the user under `D66`'s rule, because `R-c9f50df2` — an `early-testing` work row — was blocked on it by name. The row's `Awaits D69` token is stripped in the same push. ⚠️ That does not make the renderer buildable: it still needs the block shape `R-6dedc3a9` owns, and the line has no grammar for one row waiting on another's code (`R-6175d935`).

**Filed 2026-10-01 by `M121`, from verifying `R-6dedc3a9` (content blocks).** Today a note renders its label and nothing else. Once it can hold headings, paragraphs and callouts, the label either duplicates the first heading or becomes something only the author sees. The renderer (`R-c9f50df2`) cannot be built without the answer, and the editor's help text under the Label input depends on it.

- **A — always shown, above the blocks.** Today's behaviour, extended; authors will repeat their heading.
- **B — author-only once blocks exist.** The label names the note in the canvas, the logic rail and the PDF, and respondents see only the blocks. Backward compatible — no note has blocks today.
- **C — a per-note "Show the label to respondents" switch.**

**Recommendation: B.**

---

### D71 — A date compared with `>` or `<` never holds, because ordering is numeric-only. Does publish refuse it now, or is date comparison built first? **A — refuse it at publish now, and queue real date comparison as its own `early-testing` row.**

**Filed and answered 2026-10-02 (user decision, in chat), recorded by Lane A during `M126` — A, the recommendation.** Put to the user under `D66`'s rule, because `R-87160c81` — an `early-testing` work row — could not fix its refusal set without the answer. Ordering in both engines is numeric-only (`ExpressionEvaluator::numericCompare()`, through `Coercion::NUMERIC_RE`), and `today()` returns `YYYY-MM-DD`, so `${dob} <= today()` is constant false. In a constraint, that means every answer is refused. `docs/architecture/technical-architecture.md` records chronological ordering as deferred, but nothing stopped an author from writing one: the condition editor offers "is more than" on a date, and an XLSForm import carries ODK's common date constraints straight in.

- **A — refuse now, build soon.** Publish refuses a date ordering with a message naming the question, and date comparison is filed `early-testing`. Some imported forms will not publish until the condition is removed.
- **B — do not refuse; build date comparison next.** The silent failure stays live for one more increment.
- **C — refuse now, build later.** As A, with date comparison filed `during-testing`.

**Recommendation: A.** A form that publishes and then refuses every answer is worse than one that will not publish and says why. And the refusal is lifted precisely when date ordering ships, so it costs nothing permanent.

---

### D58 — A content-block image is an attachment. Whose is it, and who may read it? **B — owned by the `form`.**

**Answered 2026-10-03 (user decision, in chat), recorded by Lane A during `M127` — B, the recommendation.** Put to the user under `D66`'s rule, because `D72` moved the row it blocks — `R-f0c5b682`, the content-block images row — into `early-testing`. The row's `Awaits D58` token is stripped in the same push.

**Filed 2026-09-25 by `M110`, from reported item 17** (*"a page where they can put image, welcome notes, instructions"*). The plan stores content blocks in `form_fields.config` on a `note` field and carries images by `attachment_id`, never by URL — a remote image would be an IP-logging beacon aimed at every respondent and would break offline. That settles the reference. It does not settle the ownership, and **the ownership is the single hardest unbuilt piece of item 17.**

⛔ **The trap, named.** `attachments`' morph map already carries `form_field`, so field ownership looks free. It is not: `PublishService` clones the whole draft tree forward into a new draft on every publish, minting **new `form_fields` row ids**. A field-owned attachment therefore dangles the moment the form is published — and the clone is the one path in this system that must stay a pure clone.

- **A — owned by the draft `form_field`, re-pointed by the tree cloner on publish.** No new morph entry, no new `AttachmentKind`. ⚠️ It puts a re-pointing rewrite inside `SchemaTreeCloner`, which is exactly the path whose value is that it copies and does not interpret.
- **B — owned by the `form`.** Needs a new `AttachmentKind` and a new morph entry, and nothing is cloned. An author editing an image changes it for already-published versions — which is precisely the trade `forms.confirmation_message` already made by living on `forms` rather than `form_versions`, with that reasoning written into its migration.
- **C — owned by the `form_version`, frozen with its schema.** Most correct for a respondent: what they were shown is what the version says. Most expensive: every publish duplicates every image against a real `UsageMetric::StorageBytes` quota.

**Recommendation: B.** A is refused on the clone-purity argument alone. C is the honest ideal and would be right if images were consent-bearing documents — but a heading illustration is not, and C bills a tenant for every republish. B accepts a real consequence and it is a consequence this repository has already accepted once, in writing, for the same class of content. ⚠️ **Whichever wins, the read route must be publicly readable for a published form and gated on `ScanStatus::servable()`** — an unscanned or infected image renders as its alt text, never as a broken image.

---

### D60 — A data link snapshots another form's responses. Does that snapshot ride under the schema checksum, or beside it? **A — beside it, with its own version stamp and cache key.**

**Answered 2026-10-03 (user decision, in chat), recorded by Lane A during `M127` — A, the recommendation.** Put to the user under `D66`'s rule, because `D72` moved the row it blocks — `R-5da4a30f`, the Connect project row — into `early-testing`. The row's `Awaits D60` token is stripped in the same push.

**Filed 2026-09-25 by `M110`, from reported item 10** (*"in kobo toolbox, there is this thing called connect project … research on this"*). KoboToolbox generates an XML file per link, caches it for 300 seconds with a 600-second regeneration lock, serves stale while revalidating, and delivers it to offline clients as an OpenRosa manifest entry keyed by md5. This system has no OpenRosa manifest; it has a **checksummed schema snapshot** (`form_versions.checksum`) and a service worker. So the question Kobo answered with a manifest hash has to be answered here, and it has to be answered before the table is designed.

- **A — beside it.** The link's data carries its own version stamp and its own cache key; `form_versions.checksum` never moves because a source form received a submission. Costs a second freshness channel for the service worker to reason about.
- **B — under it.** One freshness signal; an offline client's existing schema pin already tells it to refresh. ⚠️ **Every submission to the source form would invalidate the cached schema of every destination form on every offline device.** A 300-second source TTL becomes a schema re-download storm, and the schema is the large artefact.

**Recommendation: A.** B is simpler exactly until the source form is busy, which is the only condition under which anybody wants a data link. A second freshness channel is what the service worker is for. ⚠️ Whichever wins, `selected_fields` must be `null` (meaning all) or a **non-empty** list — never `[]`. Kobo's own code carries a warning comment because `[]` means "all fields" in its stored config and "no fields" as a computed intersection; refusing the empty list at the FormRequest makes that ambiguity unconstructible here.

---

### D61 — Form media (reference attachments): attached to the form, or to the form version? **B — the form version, frozen with the schema, with byte dedupe.**

**Answered 2026-10-03 (user decision, in chat), recorded by Lane A during `M127` — B, the recommendation.** Put to the user under `D66`'s rule, because `D72` moved the row it blocks — `R-bf49e4c1`, the form-attachments row — into `early-testing`. The row's `Awaits D61` token is stripped in the same push.

**Filed 2026-09-25 by `M110`, from reported item 11** (*"we can attach excel, photos, word file, pdf etc that we can make as reference in our form"*). ⛔ **This is blocked before it is designed, and by something small.** `App\Enums\AttachmentKind` has no form-level kind, and the attachment morph map is `submission | form_field | webhook_delivery` — `form` and `scope_node` appear only for `resource_grants`, not for attachments. So this needs a new kind **and** a new morph entry, and the entry's target is this question. Note `form_templates.cover_image_attachment_id` is a real FK that is never populated and has no upload route: the same problem, already half-built.

- **A — the form.** One file, edited in place, visible to every published version. Cheapest, and an author correcting a typo in a reference PDF fixes it everywhere at once.
- **B — the form version, frozen with the schema.** Publishing duplicates the *reference row*, not the bytes, because `AttachmentStorageService` already computes a SHA-256 on every upload and can dedupe on it. ⚠️ Costs rows on every publish and a dedupe path that must be right.

**Recommendation: B, with byte dedupe.** A reference document a respondent is told to read before answering is part of what they consented to, and an author silently swapping it under a live version is the failure mode worth spending on. The checksum that makes B affordable is already computed for every upload, so B costs rows rather than bytes. ⚠️ Note this answers **differently from `D58`** on purpose, and the difference is the point: a heading illustration is decoration, a reference document is evidence. If they are answered the same way, one of the two answers is wrong.

---

### D62 — Does a form automation run inside the submit request, or on the queue? **A — queue only.**

**Answered 2026-10-03 (user decision, in chat), recorded by Lane A during `M127` — A, the recommendation.** Put to the user under `D66`'s rule, because `D72` moved the row it blocks — `R-b7bc5149`, the automations row — into `early-testing`. The row's `Awaits D62` token is stripped in the same push.

**Filed 2026-09-25 by `M110`, from reported item 12** (*"in fillout.com, there is this thing called workflows, research on this"*). Fillout's engine is trigger → linear action chain, scoped to one form, with `Filter` (a gate) and `Branch` (a fan-out) as control-flow actions. `Filter` is the case that forces this question: if it can refuse, it must run before the response is accepted.

- **A — queue only.** Every action is dispatched after commit, exactly as `app/Listeners/Connectors/DispatchConnectorsFor*.php` already do over `DomainEventType`. A `Filter` that cannot block still has a job — it stops the rest of its chain.
- **B — queue, except `Filter`, which runs inline so it can refuse a submission.** Matches the literal mental model of a gate. ⚠️ Makes a third-party outage a *submission* outage, and adds an author-authored expression to the submit path's latency budget.

**Recommendation: A.** The whole offline story assumes a submission is accepted and reconciled later — `outbox.ts`, the Dexie draft store and the queue tag on the confirmation screen all exist because a response may be accepted long before anything else happens to it. B contradicts that at the one moment it matters. If refusing a submission is genuinely wanted, the instrument is the validation engine, which already runs in both places and already refuses.

---

### D65 — A preset per-form theme would set more than the six properties `brand-ramp.blade.php` pins as its complete set. Amend the invariant, or confine the preset? **A — confine the preset.**

**Answered 2026-10-03 (user decision, in chat), recorded by Lane A during `M127` — A, the recommendation.** Put to the user under `D66`'s rule, because `D72` moved the row it blocks — `R-6017d6d8`, the per-form themes row — into `early-testing`. The row's `Awaits D65` token is stripped in the same push.

**Filed 2026-09-25 by `M110`, from reported item 3** (*"if we can also include a theme selector for the form"*), answered in chat as **preset themes only**, layered over the tenant brand. ⚠️ **That answer collides with a written invariant.** `resources/views/partials/brand-ramp.blade.php` overrides exactly six properties and says so in terms — *"only these SIX properties, never a neutral, semantic or chart token"* — and `GuestBrandingPresenter` is deliberately the **single** reader for the guest style block, the `theme-color` meta and the PWA manifest, on the stated grounds that if any of them derived its own answer they could disagree. A preset that sets a font pairing and a corner radius sets more than six.

- **A — confine the preset.** It sets those six roles plus a named font pair and a radius scale, each added as its **own documented property** with its own line in the partial and its own contrast or legibility check. The set grows; the property that every member is enumerated and justified does not change.
- **B — amend the sentence** to "the brand ramp sets exactly six; a theme preset may set a documented superset". One edit, and the presets are then free.
- **C — no per-form theme; per-tenant brand only.** Declines the request and says why.

**Recommendation: A.** The invariant's value is not the number six — it is that the set is closed and each member was argued for. A keeps that and pays the cost of arguing for each addition, which is a cost worth paying exactly once per property. B weakens the sentence to nothing, because "a documented superset" is not a constraint anybody can fail. ⚠️ Every preset must pass the existing contrast gate in **every theme mode**, and that totality is the gate worth writing — a preset that is legible in light and unreadable in dark is the failure this buys protection against.

---

### D72 — There is a testing session on Oct 12. What must be ready by then, and in what order? **All 19 builder comments — Connect project, form attachments and automations as v1 — and the single-form OCR channel, in one lane; everything else continues while users test.**

**Filed and answered 2026-10-03 (user decision, in chat), recorded by Lane A during `M127`.** The user set the date and the order: *"our top priority for now is all the 19 comments … before october 12 the app must be ready, including the OCR … then after the 19 comments and ocr developed, we will continue the remaining while other users are testing the app."* Measured against it: about 30 increments for everything in full, against 18–27 at the recent pace of 2–3 a day. So three further questions were put, and answered:

- **Scope — trimmed, the recommendation.** All 19 comments, but Connect project (`R-5da4a30f`), form attachments (`R-bf49e4c1`) and automations (`R-b7bc5149`) ship as v1, each scoped at its row, with what v1 leaves filed as its own `during-testing` row. OCR ships the single-form channel. Rejected: *everything in full*, which likely misses Oct 12, and *comments only*, which moves OCR after testing starts.
- **Lane — one, with OCR interleaved.** Two lanes were recommended first and withdrawn once `docs/adr/0022-single-lane-development.md`'s three incidents were named: a new ADR, the R6 and `preflight` changes and a second Docker stack on one host cost about a day. The user chose one lane, and ADR-0022 stands.
- **Samples — the user needs help preparing them,** and asked for the sample-free OCR work to go first. The groundwork (upload, reading job, field matching, review screen, save path) needs none. The provider bake-off (H1d, which writes the reserved ADR-0010) needs 10–20 hand-filled **Print blank** copies, scanned and photographed, with correct answers for five — **due 2026-10-08**. Later than that, OCR ships on Cloud Vision untuned, and tuning moves into testing.
- **`D58`, `D60`, `D61`, `D62` and `D65` — all accepted as recommended,** each recorded above.

**What moved:** nine comment rows up into `early-testing`; fourteen rows that are neither a comment nor a builder defect found while fixing one down into `during-testing`, each with the reason at the row (`R-68656155` and `R-2dc95042`, both security-flavoured, first once the Oct 12 work is done); and `ocr-channels` un-held and split into `ocr-single-form`, `ocr-provider-bakeoff` and `ocr-linelist`. `uploading-import` stays held — the user released OCR only. The testing-server notice is not re-owed, because `before-testing` is untouched.

---

### D70 — Do content blocks carry translations? **A — not yet. Blocks show in the form's base language, and the editor says so.**

**Answered 2026-10-03 (user decision, in chat), recorded by Lane A during `M129` — A, the recommendation.** Put to the user under `D66`'s rule, because the editor half of `R-6dedc3a9` — an `early-testing` work row, taken by `M129` — takes a different shape under each answer. No row carried an `Awaits D70` token, so none is stripped. Measured at the time: `forms.supported_locales` has no writer anywhere in `app/`, so no form can become multilingual through the product today, and the answer changes nothing a tester will see before Oct 12.

**Filed 2026-10-01 by `M121`, from verifying `R-6dedc3a9`.** Labels and hints are translated per locale on every surface; the proposed block shape has no translation member, and because the shape refuses unknown keys, adding one later is a deliberate widening rather than an accident.

- **A — not yet.** The shape ships without translations and is widened later by appending an optional member; a multilingual form shows its blocks in the base language until then, and the editor says so.
- **B — now.** Every text leaf carries a translations map from the first block, multiplying the editor's inputs by the form's languages before anything ships.

**Recommendation: A.** It does not block the shape half of `R-6dedc3a9`, which is why it is filed `during-testing`.

---

### D74 — A scanned sheet was printed from a version of the form that has since been republished. What happens to its answers? **A — carry them onto the current published version, question by question; list every answer that cannot carry for the reviewer.**

**Filed and answered 2026-10-03 (user decision, in chat), recorded by Lane A during `M129` — A, the recommendation.** Put to the user under `D66`'s rule, because `ocr-single-form` — an `early-testing` work row, taken by `M129` — could not build its save path without the answer. It was an unqueued obligation until then: `docs/ocr-pipeline-design.md` §3's as-built note said a scan of old paper "needs a decision before it can become a draft", and no row or decision held it.

The matcher reads a sheet against the version it was printed from, superseded ones included, because the printed stamp names that version. Every save path refuses a version that is no longer published: `SubmissionPipeline::submit()`, `SubmissionDraftService::saveDraft()` and `promote()`. Any republish supersedes the version a sheet was printed from, including one that only fixes a typo, and during testing authors republish often.

- **A — carry the answers over.** Each answer moves to the question with the same key in the current version, when the type is the same and the value is one the current question can hold (every choice still offered, every cascade level valid, a flat target). Anything else is listed for the reviewer with its label and the text read. The pipeline then validates the reviewed answers as it validates any response.
- **B — refuse the scan.** Tell the reviewer that the paper is from an older version, and to print a fresh blank or enter the response by hand.

**Recommendation: A.** It is what a person keying old paper into the current form already does, and B turns every typo fix into a refused stack of paper. The carry rule is strict on purpose: a value is never handed to a control that cannot show it, so nothing the reviewer cannot see is saved.

---

### D75 — Every Oct 12 increment so far has broken `D13`'s batching rule for the same reason, and `M130` would be the third. How are the Oct 12 increments batched? **A — one increment per plan slot until the Oct 12 session, each row its own commits in a stated order; `D13` applies as written again afterwards.**

**Filed and answered 2026-10-04 (user decision, in chat), recorded by Lane A during `M130` — A, the recommendation.** Put to the user under `D66`'s rule, because the `early-testing` rows named for `M130` could not be batched without an answer. All four edit hub files, and two of them — the content-block renderer (`R-c9f50df2`) and the appearance renderer (`R-048a3286`) — edit the same six files: `FieldInput.vue`, `FieldControl.vue`, `InstanceField.vue`, `schema-mapping.ts`, `resources/public-runtime/lib/types.ts` and `EncodeFormPresenter.php`. `D13`'s exception log already held #4 (`M128`) and #5 (`M129`) for the Oct 12 reason, and #5 said that a third for that reason means the rule needs an answer rather than a sixth exception.

- **A — one increment per plan slot until Oct 12.** Each row lands as its own commits in a stated order, so a red run still points at one row. `M130` takes all four rows.
- **B — split rows that share files.** Lift only the one-hub limit until Oct 12; rows that edit the same file go in separate increments. This slot becomes three increments, about half a day more.
- **C — keep `D13` as written.** One hub-editing row per increment: four increments for this slot, and the Oct 12 plan slips by days.

**Recommendation: A.** It keeps what both clauses exist for — a red run bisects to one row, by commit rather than by batch — and it matches the plan's day slots. It expires with the Oct 12 session: from then on `D13` and `D15` govern batching exactly as written, and a deviation goes back into `D13`'s numbered log.

---

### D76 — A form can now send respondents on to another form or a web address once they finish. When does it take them there? **A — thanks first, then go: the thank-you and its reference show first, with "Continue now" and "Stay on this page", and it moves on after 20 seconds. A response saved offline to send later never redirects.**

**Filed and answered 2026-10-04 (user decision, in chat), recorded by Lane A during `M130` — A, the recommendation.** Put to the user under `D66`'s rule, because `R-db169c29` — an `early-testing` work row taken by `M130` — builds a different screen under each answer. The confirmation screen prints the server-issued reference, which `ConfirmationScreen.vue` calls the string a respondent should write down, and nothing in `docs/` said anything about automatic navigation or WCAG 2.2.1 before this.

- **A — thanks first, then go.** The thank-you and the reference show first, with "Continue now" and "Stay on this page"; after 20 seconds it moves on by itself. 20 seconds is the accessibility minimum for an automatic move (WCAG 2.2.1).
- **B — go straight there.** As Typeform, Tally and Jotform do; the thank-you screen and the reference are skipped.
- **C — a Continue button only.** The thank-you shows with its reference and a "Continue" button; nothing moves automatically.

**Recommendation: A.** It keeps the reference in front of the respondent and meets WCAG 2.2.1 without a setting, while still taking them on. Verification added three rules, recorded at the row: no countdown inside a frame or while the device still holds unsent responses; the timer stops on Continue, Stay, "Submit another response" and `pagehide`; and a page restored by the Back button never restarts it.

---

### D77 — With a layout setting for choices, what does a single-choice question show by default? **A — round buttons: a list of radio buttons, one per line. The Dropdown type stays a dropdown.**

**Filed and answered 2026-10-04 (user decision, in chat), recorded by Lane A during `M130` — A, the recommendation.** Put to the user under `D66`'s rule, because `R-048a3286` — an `early-testing` work row taken by `M130` — renders a single choice differently under each answer. Measured: `single_select` and `dropdown` both render the same native select (`MdsSelect`) in every channel, so the two palette types look identical to a respondent, while ODK, the XLSForm export (`select_one` with no appearance) and the printed blank form all mean a list of round buttons.

- **A — round buttons.** Matches ODK, the printed form and the separate Dropdown type. Every existing single-choice question changes look; layouts such as side by side become options, and an author who wants a dropdown uses the Dropdown type.
- **B — keep the dropdown.** Nothing existing changes, and the list layouts become opt-in choices in the new setting.

**Recommendation: A.** A visible change on testers' forms before Oct 12, accepted as the price of a single choice meaning what it means everywhere else.

---

### D78 — The forms list gains a grouping. What is the model? **A — folders, one per form, shared by the whole workspace; the list filters by folder and by Unfiled.**

**Filed and answered 2026-10-04 (user decision, in chat), recorded by Lane A during `M131` — A, the recommendation.** Put to the user under `D66`'s rule, because `R-9e634897` — an `early-testing` work row taken by `M131` — builds a different schema under each answer. Measured: the list has no pagination and filters in PHP over the rows `FormPresenter::list()` admitted after `visibleTo()`, so a folder filter and its counts done the same way can never reveal a form the viewer cannot already see. `docs/adr/0011-analytics-substrate.md` §D6 coined no tag model and named its trigger — one form in two programs — which a single-valued folder does not meet.

- **A — folders, one per form.** A nullable `forms.folder_id`; the list filters by folder and by Unfiled.
- **B — tags, several per form.** A join table; the list filters by one tag. About half a day more, and it meets that ADR's tag trigger.
- **C — nested folders.** Folders inside folders; the most to build, and likely past the Oct 7 slot.

**Recommendation: A.** It answers "a workspace grouping in the form list (filtering option)" with the least to learn, and fits the slot. Tags stay possible later as a second axis.

---

### D79 — Who may create, rename and delete a folder? **A — anyone who can create forms creates folders and files the forms they can edit; only Owners and Admins rename or delete a folder, and deleting one unfiles its forms rather than deleting them.**

**Filed and answered 2026-10-04 (user decision, in chat), recorded by Lane A during `M131` — A, the recommendation.** Put to the user under `D66`'s rule, because `R-9e634897`'s policy differs under each answer. Measured: no workspace-level "manage forms" permission exists; Owners and Admins hold `forms.edit.any`, Form Editors hold `forms.create` and `forms.edit.own`. Every answer reuses those keys, so none mints a permission or a backfill migration.

- **A — authors create, admins tidy.** Create: `forms.create`. Rename and delete: `forms.edit.any`. Filing a form: whoever may edit it.
- **B — any author does everything.** Rename and delete also on `forms.create`.
- **C — admins only.** Create, rename and delete on `forms.edit.any`; authors only file their forms.

**Recommendation: A.** An author can organise without waiting for an admin, and a folder other people's forms sit in cannot be renamed or removed from under them by any author.

---

### D80 — How much AND/OR should the rule editor offer for the Oct 12 testing? **A — one all/any switch per rule list: "Required when ALL / ANY of these hold" and "The answer must pass ALL / ANY of these rules". Nesting is filed for after Oct 12, and a grouping a template made that the switch cannot show is shown with a notice and kept untouched.**

**Filed and answered 2026-10-04 (user decision, in chat), recorded by Lane A during `M131` — A, the recommendation.** Put to the user under `D66`'s rule, because `R-799d60f5` — an `early-testing` work row taken by `M131` — builds a different editor under each answer. Measured: both engines fold a group flat, left to right by each row's own operator, with no precedence; units of the required and skip families combine as ANY and constraint units as ALL. So "Required when ALL" is one `and` group, and "Must pass ANY" is one `or` group, with no new column.

- **A — one all/any switch per list.** Covers "required when A and B" and "valid if X or Y"; nesting is its own `during-testing` row.
- **B — groups inside a list,** like the visibility editor's "Add group", so (A and B) or C can be built. About half a day more.

**Recommendation: A.** The common cases for the Oct 12 testing, built in the slot; a template's deeper grouping stays visible and intact rather than flattened.

---

### D81 — Which plans may give a form a preset theme? **A — every plan. A workspace's own brand colour stays Starter+.**

**Filed and answered 2026-10-04 (user decision, in chat), recorded by Lane A during `M131` — A, the recommendation.** Put to the user under `D66`'s rule, because `R-6017d6d8` — an `early-testing` work row taken by `M131` — either carries a `feature:branding` gate or does not. The tenant brand is gated at Starter+ (`docs/adr/0008-entitlement-and-metering.md` §D7, restated by `docs/adr/0014-tenant-brand-ramp-generation.md`); `M120` declined to mint a gate for the page-mode setting because that is a pricing decision.

- **A — every plan.** Presets are made and contrast-checked by the product, not the workspace's own colour.
- **B — Starter+ only, like branding.** A Free workspace sees the picker locked.

**Recommendation: A.** A preset is a product look, not a brand; the paid line stays where `0008-entitlement-and-metering.md` drew it.

---

### D82 — A form automation can send an email when a response arrives. What does the email carry, and to whom? **A — a notice and a link: the form, the reference number and the time, with a link that opens the response for members who may see it. No answers. Up to five addresses the author types.**

**Filed and answered 2026-10-04 (user decision, in chat), recorded by Lane A during `M132` — A, the recommendation.** Put to the user under `D66`'s rule, because `R-b7bc5149` — an `early-testing` work row taken by `M132` — builds a different email under each answer. Measured: nothing in the app emails an arbitrary address or sends answers by email today. Every email is a queued notification to a member, or to one address the respondent typed (the resume link).

- **A — a notice and a link.** The answers stay behind the app's own access check (`submissions.show`, `can:view,submission`), and the email still says that something arrived and where.
- **B — the answers in the body,** leaving out questions marked personal or sensitive. Most like Fillout, but answers then travel by email to any address the author types, and those two flags are author-set and default to off.
- **C — members only, picked from a list,** with a notice and a link.

**Recommendation: A.** These forms carry health data. A link keeps it where access is checked, and B's exclusion depends on flags nobody is made to set.

---

### D83 — A form automation can call a web address when a response arrives. What does it send? **A — the answers, keyed by question, with the form and the reference number, signed with a secret shown once. Only someone who may manage webhooks can set one up, on a plan that includes webhooks, behind the same address check.**

**Filed and answered 2026-10-04 (user decision, in chat), recorded by Lane A during `M132` — A, the recommendation.** Put to the user under `D66`'s rule, because `R-b7bc5149` builds a different action under each answer. Measured: the workspace webhooks send IDs only — `SubmissionCreated`'s payload excludes the answers by design — and `routes/api.php` has no route that returns a response's answers. So an IDs-only action would add nothing a form-scoped webhook does not already do, and its receiver could not fetch what it was told about. The per-endpoint `include_answers` opt-in on workspace webhooks stays its own row.

- **A — the answers.** Built from the same projection the Sheets and Airtable connectors write, signed like a webhook, gated to `webhooks.manage` and the `webhooks` plan feature.
- **B — IDs only, reusing the workspace webhook machinery.** Retries and the delivery log come for free, but the receiver cannot get the answers.

**Recommendation: A.** An automation exists to move a response somewhere. The permission and the plan gate stay exactly where webhooks put them, because a URL that receives answers is a channel out of the workspace.

---

### D84 — A form's reference files: should they work offline on a respondent's phone? **A — once opened: a file opened while online stays on the device; nothing downloads in advance.**

**Filed and answered 2026-10-04 (user decision, in chat), recorded by Lane A during `M132` — A, the recommendation.** Put to the user under `D66`'s rule, because `R-bf49e4c1` — an `early-testing` work row taken by `M132` — either warms its files with the form or does not. Measured: content pictures are warmed with the form (`M130`), and capped at 2 MB; a reference PDF may be five times that.

- **A — once opened.** The service worker keeps a copy of what the respondent opened; nothing is fetched they did not ask for.
- **B — downloaded with the form,** like content pictures. Best offline, but every respondent pays the mobile data for every file.
- **C — online only.**

**Recommendation: A.** A field worker who reads the guide once keeps it, and nobody downloads a large file they never open.

---

### D85 — A choice question can take its choices from another form's responses. What does a respondent's answer save? **A — the text shown. Two responses giving the same text are one choice.**

**Filed and answered 2026-10-05 (user decision, in chat), recorded by Lane A during `M133` — A, the recommendation.** Put to the user under `D66`'s rule, because `R-5da4a30f` — the Connect project row, an `early-testing` work row taken by `M133` — stores a different answer under each option. Measured: every reader of a choice answer — the inbox, the submission PDF, the export, the Sheets and Airtable connectors and piping — turns a stored value into a label through `config.options` alone (`SchemaValueFormatter::options()`), and a linked question's snapshot holds no options.

- **A — the text shown.** The answer reads correctly on every surface with no change, and duplicates collapse into one choice.
- **B — the source response's reference number.** Each response is its own choice even when names repeat, but every reader shows the number until each learns the linked list.

**Recommendation: A.** It is what the respondent saw and chose, and it reads correctly everywhere today. Telling two same-named sources apart belongs with lookups, in the remainder row.

---

### D86 — May a question marked personal or sensitive be shared with other forms? **A — no. Those questions are never offered for sharing.**

**Filed and answered 2026-10-05 (user decision, in chat), recorded by Lane A during `M133` — A, the recommendation.** Put to the user under `D66`'s rule, because `R-5da4a30f` builds a different share list under each answer. Measured: a shared question's answers reach every respondent of a form that links it, including anonymous ones through the public link, and no other read path in the app filters on `is_pii` or `is_sensitive` — those flags drive only audit redaction today.

- **A — never shareable.** The share list leaves them out, and the check runs again at the linking form's publish and on every serve.
- **B — shareable after the acknowledgement,** like any other question.

**Recommendation: A.** These forms carry health data, and a shared list is readable by anyone with the link.

---

### D87 — Who may switch on sharing for a form? **A — anyone who can edit the form and can also see its responses.**

**Filed and answered 2026-10-05 (user decision, in chat), recorded by Lane A during `M133` — A, the recommendation.** Put to the user under `D66`'s rule, because `R-5da4a30f` gates the switch under each answer. Measured: every form-settings route is `can:update,form`, which an Editor grant satisfies without any right to read responses (`SubmissionPolicy::viewAny()` is `submissions.view`), and `forms.owner_user_id` is read by no policy.

- **A — editors who see the responses.** `can:update,form`, and the person must also be able to read the form's responses — nobody shares what they cannot see.
- **B — workspace Owners and Admins only.**

**Recommendation: A.** It keeps the setting with the people who run the form, and closes the one hole: an editor who cannot read the answers cannot hand them out.

---

### D88 — Older email and web-address questions have no format check, and the staff entry page is the only place the browser still checks them. Once that page matches the public page, what do those questions get? **A — leave them as they are; no backfill.**

**Filed and answered 2026-10-05 (user decision, in chat), recorded by Lane A during `M134` — A, the recommendation.** Put to the user under `D66`'s rule, because `R-b21da9f3` — an `early-testing` work row taken by `M134` — owed "a decision on the legacy-field backfill" before a line changed. Measured: the server accepts any string for an email or URL answer unless the question carries a `pattern` row; `DefaultFieldRules` seeds one only on a question ADDED or CONVERTED since `M112`; the platform templates and the field library's "Email address" carry none; and a published version cannot be changed at all (the immutability trigger), so a backfill could reach only drafts.

- **A — leave them as they are.** The same as the public page today. New questions keep their check, and the templates and the field library start carrying it too.
- **B — add the check to drafts.** Every unpublished email or web-address question without one gets the default; a published form gets it when next published. A check an author removed on purpose comes back, since nothing tells the two apart.
- **C — always check, built in.** The server and both pages check the format of every such question whatever its rules say; authors can no longer turn it off.

**Recommendation: A.** The public page — the main channel — already behaves this way, the published forms cannot be reached anyway, and closing the two creators that still make unchecked questions stops the gap growing.

---

### D89 — A date-and-time answer has no time zone, but `now()` runs in UTC — eight hours behind the Philippines. How does "is after now" work on a date or time question? **A — refuse it at publish for now; dates and times order against each other and against `today()`.**

**Filed and answered 2026-10-05 (user decision, in chat), recorded by Lane A during `M134` — A, the recommendation.** Put to the user under `D66`'s rule, because `R-62b638e1` — an `early-testing` work row taken by `M134` — orders a date-time against `now()` differently under each answer. Measured: a date-time answer is the browser's `datetime-local` value, wall-clock with no offset (`FieldInput.vue`'s `textType`), while both engines stamp the clock in UTC with `+00:00` (`isoClock()`, `SemanticValidator::validate()`), and nothing in the app holds a workspace time zone.

- **A — refuse it for now.** Comparing a date, time or date-time question with `now()` is refused at publish as "not supported yet", and the message offers `today()`. A workspace time zone is filed for after Oct 12.
- **B — read the answer as UTC.** Works everywhere at once, but near "now" the result is silently wrong by the device's offset.

**Recommendation: A.** A refusal that names the question and offers `today()` costs an author one edit; a silent eight-hour error costs a respondent a refused answer nobody can explain. The filed time-zone row also fixes `today()` being the UTC calendar day.

---

### D90 — `M135` takes the plan's Oct 11 slot six days early, and that slot says "freeze". The testing server deploys every merge to `main`. How long does the freeze last? **A — fixes only until the Oct 12 session.**

**Filed and answered 2026-10-05 (user decision, in chat), recorded by Lane A during `M135` — A, the recommendation.** Asked because the user's own instruction for `M135` began *"freeze"*, and the plan wrote that word for Oct 11, the day before testing; taken on Oct 5 it either holds for a week or ends with the increment. Measured: `deploy.yml` deploys every green push to `main` to the testing server, so anything merged reaches staging, where testers have been working since 2026-09-19.

- **A — fixes only until the Oct 12 session.** From `M135` until the session, only fixes from the staging smoke test and the OCR samples work (`ocr-provider-bakeoff`, samples due 2026-10-08) are merged. Nothing new reaches staging before testing. `during-testing` work resumes after the session.
- **B — this increment only.** `M135` is fixes-only; afterwards the `during-testing` work resumes and deploys to staging as usual.

**Recommendation: A.** The point of the slot is that what testers see on Oct 12 is what was smoke-tested. It expires with the session, as `D75` does.

---

### D94 — Every Oct 12 row left is OCR, and both OCR rows wait on the user until the morning of Oct 7. Does other work merge before the session? **Yes — `D90` is lifted for non-OCR work from 2026-10-06: security first, then the Kobo-style choice lists, then automation conditions.**

**Filed and answered 2026-10-06 (user decision, in chat), recorded by Lane A after `M139`.** Asked because the early-testing tier holds only `ocr-provider-bakeoff` (the samples, due 2026-10-08), `ocr-staging-scan` (one real scan by the user) and `R-551873af` (latent). `D90` admitted nothing else until the session. The user's words: *"start other tasks that are not blocked by ocr … tonight, lets develop what we can develop on new session."*

- **What changes.**
  - `during-testing` work merges before the Oct 12 session, one green increment per PR, as usual.
  - Every merge still deploys to the testing server, so testers see each change as it lands.
  - `D75` (one increment per slot) expires with the session as written.
- **The order, recommended at the decision and not the line's own.** The line orders a tier by readiness, which puts tracker housekeeping first:
  1. the two security rows the Oct 12 plan already put at the head of the after-testing work — `R-68656155` (the service worker caches a credential-bearing resume shell) and `R-2dc95042` (self-registration can occupy an address in a domain the workspace does not control);
  2. `D92`'s first Kobo-style use: choice lists from an uploaded CSV, with cascading filters (region → province → city/municipality → barangay);
  3. `D93`'s first step: a condition on each automation.
- **OCR stays first whenever its inputs arrive.** The samples and a Vision key that reads come from the user on the morning of 2026-10-07; `ocr-provider-bakeoff` then goes ahead of everything above.

---

### D91 — The staging smoke test passed 16 of 21 checks, and four of the passes came with a request for something new, which `D90` freezes until the Oct 12 session. Which go in before testing? **All four, beside the fixes: a bigger settings window, sections from the preview, a redirect delay the builder sets, and a searchable question list.**

**Filed and answered 2026-10-06 (user decision, in chat), recorded by Lane A during `M137`.** Asked because the smoke test (`staging-smoke-test`, marks read from https://claude.ai/artifact/2PF4Wdcp8UcNDTTveAJEWX) came back with four fails and four requests riding on passes, and `D90` admits only fixes until the session. The options were each request on its own, with *not picked* meaning after Oct 12. The user picked all four. **This is an exception to `D90` for these four only**; everything else still waits for the session.

- **Comment 13 — the form settings window is too small.** It grows: `MdsModal` gains a wider size.
- **Comment 18 — sections only from Structure.** The preview gains its own way to add a section and a question in it, and questions move into a section by dragging in Structure.
- **Comment 14 — the 20-second redirect.** ⚠️ **This amends `D76`:** the form builder chooses the delay — 5, 10, 20 (the default) or 30 seconds — and "Continue now" and "Stay on this page" stay. Under 20 seconds a respondent has less time to stop the move than WCAG 2.2.1 asks for, and the setting says so.
- **Comment 10 — the "Only the questions I choose" list in Data sharing scrolls when a form has many questions.** It gains a search.

---

### D92 — Reference files show respondents a list of downloads, and the user's smoke test says that is not what Kobo does. How are they rebuilt? **A — hide them from respondents now; after Oct 12 rebuild them Kobo-style, starting with choice lists from a CSV.**

**Filed and answered 2026-10-06 (user decision, in chat), recorded by Lane A during `M137` — A, the recommendation.** Asked because the smoke test failed comment 11: *"how can we use this in the form builder? … the reference must be used to create form. Please research how Kobo uses that."* Researched against Kobo's and ODK's own documentation (`support.kobotoolbox.org/media.html`, `select_from_file_xls.html`, `pull_data_kobotoolbox.html`, `dynamic_data_attachment.html`; `docs.getodk.org/form-datasets/`). Three findings:

- **In Kobo an attached file is always used by the form, and something in the form names it by filename.** It can be:
  - a picture, audio clip or video inside a question, note or choice;
  - a choice list kept in a CSV (`select_one_from_file`), filtered by an earlier answer for cascading lists;
  - a lookup table that pre-fills details from an ID (`pulldata()`);
  - another project's data (Connect Projects).
- **Nothing is ever offered to respondents as a download.** ODK Central accepts only files whose names the form refers to.
- **PDF, Word and Excel are not accepted media.** For data it takes `.csv`, `.xml` and `.geojson`; `.xlsx` is the form definition itself.

The options:

- **A — hide now, CSV lists next.** Before Oct 12, reference files stop showing on the respondent's form and stay as staff-only notes on the form. After Oct 12 they are rebuilt in order:
  1. choice lists from a CSV with cascading filters (region → province → city/municipality → barangay);
  2. pre-filling by an ID;
  3. pictures and audio in questions and choices.
- **B — build CSV lists before Oct 12.** About 2–3 days, competing with `D91`'s four.
- **C — remove reference files.** Take the feature out and plan the Kobo-style uses after testing.

**Recommendation: A.** It stops the wrong behaviour before testers see it, and puts the most valuable use first. Health geography and facility lists are too long to type, and a cascading select already exists to receive them. ⚠️ **A lookup over patient-level data must not ship as a plain CSV:** on a public form every respondent receives the whole table.

---

### D93 — Automations send one email or one web call per response, and the user's smoke test says Fillout's workflows are something else. What do testers see, and what comes next? **A — keep v1 for the Oct 12 testing; build Fillout-style Workflows after it.**

**Filed and answered 2026-10-06 (user decision, in chat), recorded by Lane A during `M137` — A, the recommendation.** Asked because the smoke test failed comment 12: *"I don't think this is the automation Fillout or KoboToolbox uses. Please conduct a research on its use."* Researched against Fillout's and Kobo's own documentation (`fillout.com/help/workflows`, `filter-branch-workflows`, `approval-workflows`, `delay-workflows`; `support.kobotoolbox.org/rest_services.html`). Three findings:

- **v1 is not Workflows.** It is one action per automation, on submit, with a runs list. That is Fillout's simpler notifications and integrations layer, and close to Kobo's REST Services, which retries three times and sends no emails at all.
- **Fillout Workflows is a chain.** One trigger, then ordered steps: Filter, Branch by answer, a custom email with answers inserted by `@`, a webhook, Delay, Slack, AI, and an approval step whose approvers act from the results page. Each response shows its own run history.
- **The remainder row already holds part of it.** Filter, Branch, Delay and Slack sit on the automations remainder row.

The options:

- **A — keep v1, Workflows after.** Testers use today's automations. After Oct 12, in order:
  1. a condition on each automation;
  2. the recipient taken from an answer, and answers inserted into the message;
  3. a review/approval step;
  4. a failure alert and each response's run history.
- **B — add conditions before Oct 12.** About a day, competing with `D91`'s four.
- **C — hide automations until v2.** So testers do not judge the wrong model.

**Recommendation: A.** v1 works and is safe, and the chain is too big for the days left. Its order is the research's ranking for a health department: alert only on a danger sign first, then route by facility.

---

### D95 — `D92`'s CSV choice lists feed a four-level cascade (region → province → city/municipality → barangay). What shape of file does the author upload? **A — one file per level, in Kobo's own format.**

**Filed and answered 2026-10-06 (user decision, in chat), recorded by Lane A in `M140`'s claim — A, the recommendation.** Asked while planning `M140`, so `R-f69aab42` (the `M141` row under `D94`) does not stop on it. Kobo's `select_one_from_file` reads a CSV with `name` and `label` columns, and a cascade filters each level by a column naming its parent (`support.kobotoolbox.org/select_from_file_xls.html`).

- **A — one file per level.** Each file has `name` and `label` columns, and every level below the first adds a column naming its parent, for example `provinces.csv`: `name,label,region`. Kobo's existing CSVs work as they are. Four uploads for four levels.
- **B — one wide file.** One row per barangay, with a code column and a name column for each level. One upload, the way a master list usually sits in Excel, but not Kobo's format.
- **C — accept both.** The upload detects which shape it is. About half a day more to build and test.

**Recommendation: A.** `D92` asks for Kobo-style, and Kobo's own files then need no conversion. ⚠️ **The `name` column must be a unique code at its level** (for example a PSGC code), not a place name: both engines key a cascade's options by value at each level (`SemanticValidator.php`'s cascade check and its TS mirror), and barangay names such as "Poblacion" repeat across cities.

---

### D96 — The first printed blank form was reviewed on paper before any OCR sample existed: unorganized, with one-letter-per-box combs that limit respondents, on paper mostly printed in monochrome. What does the paper look like? **A — layout 2: one open box for text in block capitals; combs only for numbers, phones and dates; choices side by side; numbered questions; a boxed instruction banner; monochrome; a `Layout 2` word beside the stamp.**

**Filed and answered 2026-10-07 (user decision, in chat), recorded by Lane A in `M143`'s claim — A, the recommendation.** Asked because the user looked at the rendered "Survey Question Sampling" blank before printing the Oct 8 samples, said so in chat ("unorganized"; the boxes "limiting"; "printed in monochrome more frequently"), and asked for industry research first. Researched against the ICR form-design guidance (Accusoft's whitepaper: square pen-sized boxes, at least 1.5 box widths between fields, a bold top-of-form instruction with example characters wherever answers are written freely; Tungsten/OmniPage's OMR floor of 3.5–4mm for a check box marked with an X; LEADTOOLS on registration marks), Google's own Vision guidance (`DOCUMENT_TEXT_DETECTION`, at least 1024×768) and the 2026 benchmark of handwritten-form digitisation (arXiv 2604.16504). It supersedes the 2026-08-09 layout decisions of record (`docs/ocr-pipeline-design.md` §2.5, `PrintAnswerArea`'s docblock): combs for every text answer, one option per line, tenant colour on paper.

- **A — layout 2, as above.** The reader follows the paper: `OcrAnswerReader::choices()` reads many options to a line, the layout word is read back by `PrintedFormMatcher::layoutOf()`, and a layout-1 sheet is refused with a reason rather than misread.
- **B — keep the combs, reorder only.** Numbered, spaced, monochrome, but a box per letter stays. Best for recognition; still what the user called limiting.
- **C — a free-form sheet for a vision-language model.** No boxes at all, every answer on a line; readable only through an LLM. Trades deterministic positional parsing for model judgement before a single sample has been measured.

**Recommendation: A.** The combs stay exactly where they buy accuracy — digits, where positional parsing is the whole point of a machine-readable date — and go where they cost goodwill: names, addresses. The banner carries what the comb used to enforce. No registration marks and no QR, both for one reason: the app container has no GD or imagick, so nothing server-side can warp an image or decode a code, and Vision has no barcode feature. ⚠️ **Every sheet printed before `M143` is refused by the reader once it deploys** — the samples must be printed from layout 2, which `ocr-provider-bakeoff`'s blocker now says.

---

### D97 — Which engine reads the layout-2 samples in the bake-off, and what is the fallback? **A — Cloud Vision alone first; a vision-language-model arm only if G9 is missed; Document AI not pursued.**

**Filed and answered 2026-10-07 (user decision, in chat), recorded by Lane A in `M143`'s claim — A, the recommendation.** Asked beside `D96`, because `ocr-provider-bakeoff`'s title named Document AI as the alternative and the research changed the picture. Measured facts: Document AI's Form Parser needs a service account (no API key), costs about $30 per 1,000 pages against Vision's $1.50 after the free 1,000 a month, and its own documentation says it "doesn't reliably parse a KVP with an unfilled value" — a weak fit for a layout this product prints itself and already maps field by field. The 2026 benchmark of 17 vision-language models on handwritten medical forms (arXiv 2604.16504) puts the best at about 85% strict field accuracy with 6–8% hallucination, weakest on check boxes and tilted phone photos. Azure Document Intelligence is the one cloud OCR with native selection marks; AWS Textract's handwriting is English-only. No existing agent skill fits: the "handwritten OCR" skills found route free text through a vision LLM and output documents, none maps fields on a known layout.

- **A — Vision alone first.** The harness exists (`ocr:bakeoff`), the key reads on staging, and the reader anchors on our own layout. A VLM arm is written into ADR-0010 as the fallback: output constrained to the printed options, behind the reviewer, triggered only if G9 (under 15% of fields corrected on a clean scan) is missed.
- **B — Vision and a VLM side by side now.** A second reader in the harness before the first result; a second Google product and a service account to set up.
- **C — Vision and Document AI side by side.** The original pairing; the facts above argue against spending the samples on it.

**Recommendation: A.** `M144` runs it; ADR-0010 records the measurement, the fallback and the rejection with their reasons.

---

### D98 — Layout 2 combs numbers, phones, dates and times one character per box and gives text an open box; the user asks whether one open box should serve numbers and text alike. Which answer areas keep a comb? **A — open boxes for numbers and phones; the captioned combs stay for date, time, datetime and duration.**

**Answered 2026-10-07 (user decision, in chat), recorded by Lane A during `M144` — A, the recommendation.** Put to the user first thing that session because `R-31fa2676` was blocked on it, and built in the same increment as the paragraph-box row (`R-d696ba9e`) as layout 3, before the bake-off samples were printed — see `docs/ocr-pipeline-design.md` §2.5.11.

**Filed 2026-10-07 by `M143`, from the user's comment on the merged layout 2** ("is it really relevant to have a per box per letter for other indicators? can we just have 1 box for number and texts?"), deliberately not resolved in that session. The trade-off is real and it is not the same for every digit field:

- a number or a phone in an open box reads as a digit string, and Cloud Vision reads a short run of handwritten digits well — the comb there buys segmentation, which the recognizer mostly does itself;
- a DATE in an open box is "03/04/2026", and the reader cannot tell the 3rd of April from the 4th of March from the ink — the captioned `DD MM YYYY` groups are the one comb that buys correctness rather than convenience (`docs/ocr-pipeline-design.md` §2.5.3), and `OcrAnswerReader::date()` parses only from those groups today.

- **A — open boxes for numbers and phones; keep the captioned comb for date, time, datetime and duration.** The reader needs no new parser: `number()` already reads free text, and the date groups stay positional. The paper loses most of its boxes and keeps the one kind that matters.
- **B — open boxes for everything, dates written as DD/MM/YYYY in one box with a printed hint.** A free-text date parser (strict day-first, slashes or spaces) is new reader work, the bake-off's answer sheet already assumes day first, and a respondent who writes month first is undetectable.
- **C — keep layout 2 as it is.**

**Recommendation: A.** Either A or B is a new layout (`BlankFormPrintPresenter::LAYOUT` 3), and the reader refuses every sheet printed from layout 2 once it ships — so this is decided and built BEFORE the bake-off samples are printed, together with the paragraph-answer row filed beside it.

---

### D15 — `D13`'s one-hub-row cap is now the binding constraint on batch composition, and it is stricter than its own purpose. **ANSWERED — option 4: the cap reads the files a row REMEDY EDITS, excluding the close-out artefacts.** **Tier: early-testing.**

**Filed 2026-09-05 by Lane A, during `M72`, at the moment the cap decided a batch that value had not.**
Recorded here rather than as a row because `D13` is a user decision and an increment does not re-scope
one of those on its own judgement.

⚠️ **`M93` (2026-09-14): no generator enforces the cap any more.** `render_batch()` and `BATCH_MAX` are deleted, so the one-hub-row cap lives only in `D13`'s text and in whoever groups a tier's rows. The question stands; what it governs is narrower.

⛔ **RETIERED `after-launch` → `early-testing` ON 2026-09-24 (`M108`), A USER DECISION, AND THE REASON IS THAT IT STOPPED BEING A LATER QUESTION.** It now blocks the top of the tier every session is pointed at: `R-b6b9bfa4` cannot be taken without presupposing one of the three answers below, and `R-4346557c` exists only because the cap has no exception procedure. A question that gates the most urgent tier is not `after-launch` work, whatever it is about.

⛔ **AND `M108` MEASURED THE CAP AGAINST EVERY CANDIDATE ROW RATHER THAN AGAINST A BATCH, WHICH IS THE NUMBER THIS DECISION WAS MISSING.** Across the seven rows verified for that increment, **7 of 7 breach the cap**, and **a batch of ONE breaches it** — `R-7849e303` alone harvests three hub files. The reason is structural and has nothing to do with which rows are chosen: **five of the hub files are touched by close-out procedure alone** — `docs/feature-backlog.md` (7 of 7 rows), `docs/pipeline.md` (7 of 7), `PROGRESS.md` (6 of 7), `docs/claims/lane-a.md` (5 of 7) and this file (4 of 7). `M107`'s own work commit `a0132f8` touched **eleven**. ⚠️ So the cap has not been *stricter than its purpose* since those five crossed the threshold; it has been **unsatisfiable**, and every recent increment has been in silent breach of it. Clause 1 — *no two rows may cite the same non-hub file* — is satisfied universally and separates the rows perfectly well: of those seven, only three touch any non-hub file at all and their sets are pairwise disjoint.

⚠️ **A FOURTH OPTION THE ORIGINAL THREE DO NOT COVER, OFFERED AS A RECOMMENDATION AND NOT TAKEN:** keep the cap and make it read the files a row's **remedy edits**, excluding the close-out artefacts every increment touches by procedure. That is the smallest change that makes the rule mean what `M72` meant by it, and it leaves the hub derivation alone. ⛔ `M108` did **not** apply it — this entry is the user's and an increment does not re-scope one of those, which is the same sentence this decision opens with.

⚠️ **AND THE INPUT TO THIS QUESTION MOVED UNDER IT ON THE SAME DAY.** `M108` widened the citation harvester to read class names, which is the row this entry's own closing note asks to be closed first. The derived hub set went from **49 files to 60**, and the rows harvesting no file at all fell from **59 to 30**. Any answer reasoned from the older degree counts is reasoning from a floor that has since risen.

⛔ **WHAT `M72` MEASURED, AND IT IS THE WHOLE QUESTION.** Fifteen rows were verified read-only before the
branch was cut. **Five of the six highest-value live rows touch a hub file — and they touch five
DIFFERENT ones**: `.github/workflows/ci.yml`, `scripts/mutate.php`, `scripts/backlog-triage.php`,
`scripts/tracker-lint-controls.php` and `docs/data-dictionary.md`. `D13` allows **one row per batch** to
touch a hub, so four of those five were unselectable this increment for a reason that has nothing to do
with them.

⛔ **THE CAP EXISTS TO PREVENT COLLISION, AND ROWS IN FIVE DIFFERENT FILES CANNOT COLLIDE.** `D13`'s own
reasoning says so: the 26-row component *"is glued only by hub files … which are meta-files, not product
code"*, and the rule it derived is *"no two rows in a batch may cite the same non-hub file, and at most
one row may touch a hub file."* The first clause is per-file. The second is per-batch, and that
asymmetry is what now binds. ⚠️ **It was a sound rule when it was written and the tree has moved under
it**: the remaining queue is overwhelmingly meta/tooling debt concentrated in a handful of files,
because that is what six consecutive increments of gate work produces.

⚠️ **AND IT ALREADY COST THIS INCREMENT SOMETHING CONCRETE.** `M72`'s `R3` built the proof `M61` asked
for, the proof found a live defect in `resources/public-runtime/sw.ts` — a hub file — and the fix is the
one line its sibling route already carries. It could not be taken, because `R1` had spent the budget. It
is now a row, and the next increment will pay the re-derivation cost to close a defect that was fully
diagnosed while the file was open.

✅ **ANSWERED 2026-09-26 (user decision), APPLIED BY `M112` — OPTION 4, `M108`'s, which the original three do not cover.** **The cap reads the files a row's REMEDY EDITS, excluding the close-out artefacts every increment touches by procedure** — `docs/feature-backlog.md`, `docs/pipeline.md`, `PROGRESS.md`, `docs/claims/lane-a.md` and this file. Both of `D13` item 1's clauses stand exactly as written; only their INPUT changes, from *cited* to *edited*.

⛔ **WHAT DECIDED IT WAS A CLAUSE-1 BREACH, WHICH IS NEW.** `M108` and `M109` both recorded exceptions against clause 2 while reporting clause 1 *satisfied exactly*, and `M108` concluded the rule was merely unsatisfiable. `M112` measured a batch where **clause 1 breaks too**: `app/Services/Forms/StructuralValidationGate.php` has citation degree **2** against `HUB_THRESHOLD` 3 — so it is a NON-hub file — and `R-3df897a6` and `R-a6607470` both cite it. Under the citation reading those two rows may not share a batch. Under the remedy reading they may, because `R-a6607470`'s remedy creates one new file and edits none. **The citation reading was forbidding a pair that cannot collide, which is the precise failure `D15` opens by describing.**

⛔ **AND IT ENDED THE EXCEPTION SERIES RATHER THAN EXTENDING IT.** `D13`'s escalation rule says three exceptions for one reason mean the rule is wrong rather than the batch, and that the entry to write at that point is an answer here — not a fourth exception. `M112`'s batch would have been the third. The entry written was this answer.

**What it costs, stated rather than discovered.** A row's remedy-edit set is known only once the row is understood, so batch composition now happens AFTER the verification pass rather than before it. That is a real change of order and it is the honest price: `M112` composed its batch from three verified rows and dropped a fourth (`R-33c7fd56`) on the strength of what its remedy turned out to touch — `docs/data-dictionary.md` §19, a hub, because a fifth column on `user_ui_preferences` belongs in the table that enumerates that table's columns. ⚠️ **No gate forces that edit** — `DocumentedDefaultDriftTest` compares only *documented* cells against `information_schema` and has no completeness arm — **so the temptation the answer creates is to under-declare a remedy to get the hub count down.** That is the one way to cheat this rule, it is named here so it is not discovered later, and `M112` declined it explicitly.

⚠️ **TWO GENERATORS RESTATE THE CLAUSE VERBATIM AND BOTH WERE UPDATED IN THE SAME PUSH** — `scripts/next.php` and `scripts/pipeline.php` each carried *"no two citing the same non-hub file, at most one touching a hub"* into `docs/pipeline.md` and into every hand-off. **No gate reads that sentence**, which is exactly why it had to be changed by hand rather than left to drift. Making them POINT at `D13` instead of copying it is `R-b6b9bfa4`'s remedy and stays with that row, which this answer unblocks.

⚠️ **WHAT THIS DOES NOT DECIDE.** The hub set is still derived at `HUB_THRESHOLD = 3` by `scripts/backlog-triage.php` from harvested *citations*, and that derivation is untouched — a file is still a hub because many rows CITE it, while the cap now asks which rows EDIT it. Those are two different questions and the answer deliberately changes only the second. `R-f8bcf113` and `R-b6b9bfa4` both concern the first and remain open.

**The three original options are kept below as the record of what was weighed, not as live alternatives.**

**The options, none of them a rewrite of `D13`:**

1. **Keep it as written.** The cap has never yet caused a wrong batch, only a smaller one, and `D13`'s
   measured ~42% saving does not depend on which rows are in the batch. Costs: the meta/tooling queue
   drains at one hub row per increment regardless of how cheap the fixes are.
2. ✅ **Relax the second clause to match the first: *no two rows in a batch may touch the SAME hub
   file*.** This is what the cap is for, stated the way the other clause already is. Every batch `M72`
   could have built satisfies it trivially, and it would have let `R3` close its own finding.
   **Recommended.** ⚠️ The honest cost: a batch touching four hub files is a wider blast radius for
   `D13`'s bisection rule, so it is worth pairing with *at most two hub-touching rows* until measured.
3. **Re-derive the hub set per batch rather than globally.** A file is a hub only relative to the rows
   still open, and `scripts/backlog-triage.php` recomputes it every run — so the set is already dynamic
   and this option only changes the threshold. Cheapest to implement, least principled: it makes the
   cap loosen automatically as the queue drains, which is the opposite of what a safety rule should do.

⚠️ **Whatever is chosen, `D13`'s batch SIZE is not in question.** It is answered, proven seven times, and
this entry is about which rows may sit together — not how many.

⛔ **`M73` (2026-09-05) FALSIFIED THE EVIDENCE THIS ENTRY OFFERS FOR ITSELF. THE QUESTION SURVIVES; THE
ARGUMENT DOES NOT.** This entry's most concrete claim is that the cap *"already cost this increment
something concrete"* — that `M72`'s `R3` diagnosed a live `sw.ts` defect, could not fix it, and *"the next
increment will pay the re-derivation cost"*. **`resources/public-runtime/sw.ts` is not a hub file, and was
not one when that was written.** `HUB_THRESHOLD` is 3; exactly **two** open rows cite it; and
`docs/backlog-triage.md` — regenerated by `M72` in its own close-out, in the same commit range that wrote
the sentence — omits `sw.ts` from the hub table and lists it in the NON-hub cites column of both rows that
name it. **The `D13` budget never bound on that row.** `M73` took it as an ordinary non-hub row and closed
it in the same batch as a genuine hub row.

⚠️ **What that does and does not change.** It does NOT answer the question: the five-hub observation that
opens this entry stands on its own, and a queue concentrated in a handful of meta-files is still the
condition that makes a per-batch cap bind. It DOES remove the one worked example, so whoever answers this
should not weigh *"it already cost us a fix"* — that cost was a miscount, not the cap. ⚠️ **And it adds a
different concern, pointing the other way**: the hub set is derived from harvested citations, and `M73`
found that `scripts/backlog-triage.php` silently drops any citation written as a PARTIAL path, so such a
row contributes to no file's hub degree at all. **The hub set that both options reason about is a floor.**
Filed as its own row; worth closing before this decision is taken on degree counts.

⛔ **`M87` (2026-09-08) ADDS THE MEASUREMENT THIS ENTRY HAS BEEN MISSING SINCE `M73` TOOK ITS WORKED
EXAMPLE AWAY — AND IT IS STRONGER THAN THE ONE IT REPLACES, BECAUSE IT IS ABOUT THE RULE RATHER THAN ABOUT
ONE INCREMENT'S LUCK.** `docs/feature-backlog.md` is itself in the derived hub table, at degree **3**
(cited by the open rows at `6024`, `7926` and `4996`). **Every closure and every correction edits the
ledger.** So `D13`'s second clause, read to its letter — *at most one row may touch a hub file* — makes a
batch of more than **one** row illegal, always, for every possible selection. **Eighteen batched increments
have relied on an exemption nobody wrote down**, and the generator cannot apply it either: it implements
*cite*, not *touch*, so it never notices.

⚠️ **THAT IS NOT AN ARGUMENT FOR RELAXING THE CAP; IT IS AN ARGUMENT THAT THE CAP AS WRITTEN IS ALREADY
NOT THE RULE ANYONE FOLLOWS.** Whichever option is chosen here should say what the ledger is, because the
answer "it is a hub and batches may touch one of them" has been false in practice since `M65`.

⚠️ **AND THE PRACTICAL BIND IS NOW MEASURED RATHER THAN ASSERTED.** Every harness script in this repository
is a hub file — `pipeline.php` (12), `backlog-triage.php` (11), `state.php` (10), `mutate.php` (6),
`citation-liveness-lint.php` (6), `tracker-lint-controls.php` (4), `loop.php` (4), `next.php` (3),
`pipeline-lint.php` (3), `pre-push-guard.php` (3), `tracker-surgery.php` (3) — and `docs/data-dictionary.md`
(13) with them. The remaining queue is overwhelmingly repairs inside those files, so the cap admits
**exactly one harness row per increment** regardless of how cheap the rest are. `M87` composed its batch by
hand against this constraint and rejected the generated proposal for the third increment running, for a
third distinct mechanism — `M83`'s was a hub-free harvest of a hub-only repair, `M86`'s was a file of nil
harvested degree, and `M87`'s was three hub-touching rows in a four-row proposal plus a fourth blocked on
an open decision. **Three different mechanisms, one rule, no fix yet.**

⛔ **`M88` ADDS A FOURTH MECHANISM, AND IT IS THE ONE NO BATCH-COMPOSITION RULE CAN PREVENT: A CORRECTION
CAN FORCE A HUB TOUCH THAT WAS UNKNOWABLE AT CLAIM TIME.** `M88` declared one hub-touching row
(`docs/data-dictionary.md`) and composed the rest to be pairwise disjoint. It finished having touched
**two** hub files, because verifying row `8559`'s *premise* revealed that the false sentence the row was
built on had also propagated into `D27` — and `docs/claims/decisions.md` is itself a hub file at 3 citing
rows. ⚠️ **The excess was not chosen and could not have been foreseen**: you cannot know a claim has
propagated into the decision roster until you check it, and the alternative — knowingly leaving a false
sentence in the roster while closing the row that proved it false — is strictly worse than exceeding the
cap. ⚠️ **This is not the ledger exemption argued above**; it is a second, independent way the cap is
un-followable, and it applies to the *correction* half of `D13`'s own workflow rather than to selection.
⛔ **Whichever option is taken, it should say what happens when verification itself forces the second hub
touch**, because that is now measured rather than hypothetical, and answering only the selection half
would leave `D13` binding on a case no selection can control.

---

### D38 — The API documentation promises features that were never built. Build them, or trim the documentation? **C — mark the six as not built, in place, the way §7.1 already annotates.**

**Answered 2026-09-20 (user decision, in chat), recorded by Lane A during `M105` — C.**

**Filed 2026-09-14 by `M93`, from Decision Board card `api-promises`.** Six ledger rows describe documented,
unbuilt API surface: the async export endpoints, the users-and-roles resource group, the form-draft builder
endpoints, `Idempotency-Key` deduplication, the per-user 300-a-minute limiter and the one-concurrent-export
guard. The web app works without every one of them, and nothing shipped depends on them.

- **A — trim the documentation now, and build an item when an integrator needs it.** The specification then
  describes what exists.
- **B — build them all before launch.** Several increments of work.

**Recommendation: A** — honest documentation stops testers and integrators planning against endpoints that are
not there.

⛔ **CORRECTED BY `M105` (2026-09-20) BEFORE THE ANSWER WAS TAKEN — THE PREMISE SENTENCE ABOVE IS FALSE FOR ONE OF
THE SIX, AND THE OPTION SET WAS MISSING THE ONE THIS REPOSITORY ALREADY PRACTISES.**

- ⛔ **The sentence *"nothing shipped depends on them"* is FALSE for `R-47552102`.** The documented
  `GET /api/v1/roles` is the stated reason roles use UUIDv7 primary keys rather than Spatie's bigints —
  `docs/multi-tenancy-rbac-design.md:56`, `app/Models/Role.php:13` and `config/permission.php:20-21` each cite it
  as the justification. **That schema shipped.** Option A as worded deletes the rationale for a decision already
  paid for.
- ⚠️ **The phrase *"never built"* is imprecise for `R-fcfc1f52`.** `PROGRESS_ARCHIVE.md:322` records
  `Idempotency-Key (§2.4) deferred` in Increment E's documented-not-fixed list — a **lapsed deferral**, not an
  unbacked promise.
- ✅ **What the entry got right, and it is the load-bearing half:** all six are genuinely unbuilt on `/api/v1`
  (`routes/api.php` registers 68 routes carrying none of them), and the **generated** `openapi.json` — 51 paths,
  produced from the routes by Scramble — independently corroborates every absence. **There is no hand-maintained
  second copy of the API documentation**, which is the rot this kind of row usually dies of.

- **C — mark the six as NOT BUILT, in place. Added by `M105`.** §7.1 already carries an in-place annotation
  convention: `docs/architecture/technical-architecture.md:445-446` read *"**implemented (Increment F5)**"*.
  Marking these six the same way makes the documentation honest, preserves `R-47552102`'s shipped rationale, and
  costs a line-length change rather than six deletions.

⚠️ **THE REASON `C` BEATS `A` IS A CASCADE NEITHER `A` NOR `B` MENTIONS.** `docs/api-specification.md` and
`docs/architecture/technical-architecture.md` are citation **tier 1 with zero tolerance**
(`scripts/citation-liveness-lint.php:77-78`, run at `.github/workflows/ci.yml:242`), and `docs/pipeline.md:50` —
itself tier 1 — cites `docs/api-specification.md:63` **by line**. Deleting lines shifts every citation below them,
so option A owes a re-point of every affected citation in the same commit. Annotating in place shifts nothing.

**Consequences, recorded so they are not rediscovered:**

- The six rows are **not** taken here. They become `ready` and belong to `M106` or later.
- ⛔ **They cannot be grouped under `D13` as it stands.** Three edit one table
  (`docs/architecture/technical-architecture.md` §7.1, lines 443 / 449 / 454) and three edit
  `docs/api-specification.md` lines 63 / 73 / 75, two of those in one table. `D13` forbids grouping rows citing the
  same non-hub file, while the citation cascade **requires** moving them together. Filed as its own row: the
  exception must be recorded or the six cannot be taken at all.
- ⚠️ **The real code-facing risk is section RENUMBERING, not line-trimming.** Fourteen first-party sites cite
  `docs/api-specification.md` by section number, including `app/Providers/AppServiceProvider.php:374`,
  `app/Support/Api/ApiErrorResponse.php:10`, `routes/api.php:66` and `config/scramble.php:24`. Option C moves no
  section number.
- ✅ **No contract gate can break on this.** `.github/workflows/ci.yml:581-666` runs `scramble:export` and diffs
  against `openapi.json`; it reads routes and models only, and `tests/Feature/Api/OpenApiContractTest.php` names
  `api-specification.md` in a failure *message* only.

---

### D44 — Should a new customer create their own workspace at sign-up, or do operators create every workspace? **A — operators create every workspace; `tenants:create` stays the only path.**

**Answered 2026-09-20 (user decision, in chat), recorded by Lane A during `M105` — A.**

**Filed 2026-09-14 by `M95`, while building the first-workspace command.** Two documents describe self-serve
creation: `docs/onboarding-template-content-plan.md` §2 (step 1, *Signup → tenant creation*) has sign-up create
the tenant and its founding Owner, and `docs/PRD.md` §4's goal G1 measures a brand-new tenant's time from signup to
a first published form. Nothing in the product does that. A central-host registration joins no workspace, the
landing page's "Create a workspace" button and the welcome email both offer something the product cannot do (two
rows in `docs/feature-backlog.md`), and after `M95` the only path that creates a workspace is the operator command
`tenants:create`. ⚠️ **It blocks nothing before launch:** the testing server is invitation-only (D31, answered).

- **A — operators create every workspace.** `tenants:create` stays the only path, sign-up stays an account-only
  door, and the landing page and welcome email stop offering creation. Cheapest; it gives up G1's self-serve
  premise, and every new customer waits on an operator.
- **B — self-serve creation at sign-up.** A central-host registration creates a workspace, its domain label, the
  Owner membership and role, and a default plan, reusing `tenants:create`'s write set and its `SubdomainLabel`
  rule. Meets G1; costs a workspace-address picker, a plan choice, abuse controls on a public door, and the
  system-actor audit shape operator provisioning still lacks.
- **C — operator-only now, with the write set kept in one service**, so a later self-serve door is a page rather
  than a rewrite. A's cost today, with B's path kept open.

**Recommendation: A — operator-only (`tenants:create`) until a pilot needs self-serve.** Nothing before launch
needs a public door, the first pilot customer is itself an open question, and building one ahead of that answer is
work the tiers exist to hold back.

---

### D51 — `R-5ecfa6cd`, the central-host sign-in loop, is tiered `early-testing`; `M98` measured it as unreachable by any tester. Which tier? **A — `before-launch`.**

**Answered 2026-09-20 (user decision, in chat), recorded by Lane A during `M105` — A.**

**Filed 2026-09-18 by `M99`, which verified the row without taking it.** The row's own `M98` clause ends *"`before-launch`
fits the tier definitions better than the `early-testing` above, and that change is the user's to make"*, and nothing
filed it, so the row goes on publishing `early-testing · ready` in `docs/pipeline.md` and goes on being named in the
Next section that tells each session what to take. Two premises were re-measured for this entry.
`resources/js/Pages/Welcome.vue` already hides *Create a workspace* behind a `registrationOpen` guard, so once the
checklist closes sign-up the headline symptom is simply absent and only the sign-in loop is left. And under `D46`,
`CENTRAL_DOMAIN=pitahc.gov.ph` resolves to the agency's own website on another machine, so **no tester can reach the
central host at all** — only the operator, on the box, through the `hosts` line the checklist adds.

- **A — `before-launch`.** It bites the operator once per console sign-in, with a documented workaround, and every
  customer on a public central host later. Nothing a tester meets, so it leaves the tier that exists for what testers
  meet. ⚠️ The remedy is blocked under `D44` in any case, and a grant gap sits under it (`pgsql_auth` can read
  `users` and `tenant_users` and has nothing on `tenants` or `domains`), so `early-testing` buys no earliness.
- **B — keep `early-testing`.** The operator is a real person hitting a loop with no message and no way out, and the
  console is used throughout testing. Defensible if "affects the operator now" counts as early-testing.
- **C — `during-testing`.** A middle reading: not a blocker for standing the server up, but wanted before testers are
  running in volume and the operator is in the console daily.

**Recommendation: A.** The tier that exists for what a tester meets should not hold a row no tester can reach.

---

### D52 — `R-e6a10f97`, the welcome email telling a central-host account to create a workspace, is tiered `early-testing`; `M98` found it latent behind a reset-and-verify path. Which tier? **C — keep `early-testing`.**

**Answered 2026-09-20 (user decision, in chat), recorded by Lane A during `M105` — C.**

**Filed 2026-09-18 by `M99`, which verified the row without taking it.** The row's `M98` clause ends *"`during-testing`
or `before-launch` fits the tier definitions better than the `early-testing` above; the wording also depends on `D44`,
and both calls are the user's"*. The wording half is `D44`, which is open and is now named on the row by an
`Awaits` token so the line can publish the row as blocked rather than ready. The tier half was filed nowhere.
What `M98` established is that invitation-only sign-up does **not** make the copy unreachable: an invitation creates a
placeholder user with a random password, nothing guards a placeholder against *Forgot password*, and an invited tester
who resets, signs in and verifies **before** accepting the invitation fires `Verified` while still Invited — which is
exactly the branch `tests/Feature/Auth/WelcomeEmailTest.php` already pins. That path was inferred from the code and
never run.

- **A — `during-testing`.** Reachable by a tester, but only down a path nobody walks on purpose, and the harm is
  confusing copy rather than a blocked task. It wants fixing while testers are running, not before they start.
- **B — `before-launch`.** The wording cannot be settled until `D44` is answered, and a default install is the
  population that really meets it. Tying it to the `D44` answer avoids writing the copy twice.
- **C — keep `early-testing`.** Every tester who is invited can reach it, and first-contact copy that tells someone to
  do something the product does not allow is worth fixing before testers arrive.

**Recommendation: A**, with the wording written only once `D44` is answered. ⚠️ Note that the header-logo half of this
row — the unbranded logo linking to the agency website — is **not** waiting on any of this: `M99` takes it under
`R-62fb2e05`, and this row is amended to say so.

⛔ **RE-PRICED BY `M105` (2026-09-20) BEFORE THE ANSWER WAS TAKEN — THIS ENTRY PRE-DATES `M103` BY ONE DAY, AND
`M103` CHANGED WHAT THE DEFECT COSTS.** `M103` (`77a268d`, 2026-09-19) added a `welcomed_at` once-per-person guard
(`app/Listeners/Auth/SendWelcomeEmail.php:60-67` and `:153-172`, backed by migrations `2026_08_17_000112` and
`…000113`).

- ✅ **It does NOT close the path this entry describes.** A placeholder is written with `email_verified_at` NULL
  (`app/Services/Tenancy/TenantMembershipService.php:794-801`), so the backfill skips it, `welcomed_at` stays NULL
  and `claimWelcome()` wins. The reset-and-verify branch still fires, and
  `tests/Feature/Auth/WelcomeEmailTest.php:145-171` still pins it.
- ⛔ **But it makes the bad copy the person's ONLY welcome.** Accepting the invitation fires no `Verified`
  (`SendWelcomeEmail.php:29-33`), so no second, correct email is ever sent. **Option A's pricing — *"confusing copy
  rather than a blocked task"* — is therefore wrong:** the copy is now one-shot and unrepeatable, and that is what
  moved the answer from `A` to `C`.
- ⚠️ **Option B's own rationale expired in this same push.** It says *"the wording cannot be settled until `D44` is
  answered"*. `D44` is answered above, in this increment — **A**, operators create every workspace — so the wording
  is settled: the email stops offering workspace creation. It is one string at
  `app/Notifications/Auth/WelcomeNotification.php:88`.

---

### D53 — `R-e7d6f223`, the deploy that deletes the previous build's chunks, is tiered `during-testing`; `M98` re-judged its precondition as ordinary traffic. Which tier? **A — `early-testing`.**

**Answered 2026-09-20 (user decision, in chat), recorded by Lane A during `M105` — A.**

**Filed 2026-09-18 by `M99`.** This is the one pending tier verdict that would **add** a row to `early-testing` rather
than remove one, and it was invisible to a per-row pass because no row in the Next section names it. The row's `M98`
clause says *"on this evidence the tier wants re-reading as early-testing, which is the user's call"*, and nothing
filed it. What changed under it: `DEPLOY_ENABLED` was set on 2026-09-17, so a build swap now happens on every merge,
and the nightly schedule's deploy fires around 08:50Z — **16:50 Philippine time, inside the testers' working day**.
`M98`'s own documentation skip took close-out pushes back out of the exposure, leaving merge pushes and the nightly
run on a moved tip as the whole of it. What remains inferred is the other half: which sites fetch a chunk on demand
without navigating was counted and never exercised.

- **A — `early-testing`.** A tester with the builder open across a nightly deploy is unremarkable, and it lands inside
  their working day. Moving it up puts it in front of the tier the testing server is being stood up for.
- **B — keep `during-testing`.** The unexercised half is the half that decides how often anyone actually sees it; a
  user who wants it measured before it is re-ranked would leave it where it is and mark it latent again.
- **C — `early-testing`, but only after the on-demand-chunk half is exercised.** Measure first, then re-rank, which
  costs one increment and answers the question rather than judging it.

**Recommendation: A.** The precondition is now ordinary traffic in the testers' own working hours, and the unexercised
half changes the frequency rather than whether it happens.

---

### D26 — The offline panel's storage-quota line counts every visit's submissions while the three sentences beside it count only this one. Reword the line, re-scope the number, or drop the count? **1 — say what it counts: *"N responses across all sessions on this device"*.**

**Answered 2026-09-20 (user decision, in chat), recorded by Lane A during `M105` — 1.**

**Filed 2026-09-07 by Lane A, during `M85`, after a read-only fan-out found the row's stated blocker was
false and the residue was a copy call.** Recorded here rather than left as a row because two increments
have now looked at it, and both stopped at the same place: **what remains is what the sentence should
say, and that is the user's.**

⛔ **THE ROW'S STATED BLOCKER IS MEASURABLY FALSE, WHICH IS WHY THIS IS A DECISION AND NOT A HOLD.** The
row (`docs/feature-backlog.md`, filed `M21`) says touching the device-wide count *"risks the boot drain
that ADR-0021 makes load-bearing"*. It does not. The boot trigger reads `pending` alone; `queued` is a
local `const` whose only consumer is the warning string, and it escapes nowhere. `M77` reached the same
conclusion independently and filed a second row saying so, which is itself a signal.

**What a respondent actually reads**, three consecutive `<p>` elements under a heading that already says
*"My submissions on this device"*: a visit-scoped summary, a visit-scoped *"responses from earlier
sessions on this device"* note, and then a device-wide *"N responses waiting to send"*. ⚠️ **The quota
line therefore discloses nothing the panel does not already state deliberately, in plainer words** —
which deflates the row's own harm claim, and neither row says so. It renders only above 80% of quota, so
it is rare rather than hypothetical.

**The options, all one line of code:**

1. ✅ **Say what it counts: *"N responses across all sessions on this device"*.** Honest, matches the
   heading's own location framing, and leaves the megabytes and the count measuring the same population.
   Wordier, and it is the only option that needs no other number to move. **Recommended.**
2. **Re-scope the count to the visit** — the value `SyncStatus.vue` already computes as `unsent`. Then
   the count is visit-scoped and the megabytes are still device-wide, which is the same mismatch
   inverted and harder to notice.
3. **Drop the count, keep MB and percentage.** Contradicts `docs/offline-first-sync-design.md`, which
   specifies *"you have N submissions queued and using X MB"* — so it also owes a document edit.

⚠️ **WHICHEVER IS CHOSEN, RENDER THE PANEL BEFORE SHIPPING IT.** `M15`'s note on this component records
that no unit test could see the defect it introduced and that it took rendering the hand-over to catch —
and `M77` repeated the instruction. ⚠️ **And the gate here is weaker than it looks**: the existing case
asserts `toContain('1 response')` with a single enqueued row, so it cannot tell device-wide from
visit-scoped at all. A two-visit case in the same `describe` block reddens under option 2 and should be
written whichever option is taken, because otherwise the scope is enforced by nothing.

⚠️ **Three stale citations sit in the exact files a repair opens**, and they are free to fix in the same
pass: two cite `docs/offline-first-sync-design.md:93` for a spec that is now at `:192`, and one cites
`:103` for a *"Sync now"* rule that has also moved. They resolve to live lines, so the citation gate is
blind to them by design.


---

⚠️ **`M86` ADDENDUM (2026-09-07) — THREE MEASUREMENTS THIS ENTRY DID NOT HAVE, AND ONE OF THEM IS A
STRONGER ARGUMENT AGAINST OPTION 2 THAN THE ONE RECORDED ABOVE.**

⛔ **(1) THE STRONGEST OBJECTION TO OPTION 2 IS ADJACENCY, NOT THE INVERTED MISMATCH.** `SyncStatus.vue`
already renders, for the **visit-scoped** number, the sentence *"N responses on this device have not been
sent yet"* — pinned verbatim in `sync-status.test.ts`. Re-scoping the quota line to the visit would print
**the same phrase with a different number two paragraphs apart**. That is worse for a reader than the
imprecision it fixes, and it is a reason to reject option 2 outright rather than to rank it second.

⛔ **(2) OPTION 2 ALSO CONTRADICTS A WRITTEN DECISION, WHICH NOTHING ABOVE NOTES.**
`docs/adr/0021-respondent-scoped-device-outbox.md`'s scoping table states that the device-wide `counts`
*"drives the boot drain and the storage-quota estimate"* — the estimate, not only the drain — and two test
rationales restate it as the reason the count is device-wide. So option 2 owes an ADR amendment, which
moves it out of *one line of code* and into a decision about a shipped ADR. Option 1 owes nothing.

⛔ **(3) THE `M77` ROW PRICES ITS OWN REMAINING WORK AGAINST THE WRONG FILE.** It says a re-aim *"changes
a string another increment deliberately pinned in `sync-status.test.ts`"*. That file carries **no quota
assertion and structurally cannot** — its fixture holds a null `quotaWarning`, so the paragraph never
renders in that suite. The only pin is a `90%` assertion in `sync-outbox.test.ts`, which no re-wording of
the count clause would break. ⚠️ **And the triage has already harvested that wrong file into the collision
graph**, so `D13` has been batching the row against a file its repair never opens.

✅ **NONE OF THIS CHANGES THE RECOMMENDATION — OPTION 1 — IT STRENGTHENS IT**, and it removes option 2
from contention on two independent grounds rather than one. ⚠️ **`M86` NEARLY FILED THIS A SECOND TIME AS
A NEW DECISION**, because the `M77` row says *"a product decision nobody has taken"* and cites nothing.
**A row that names a decision without naming WHICH one costs the next increment exactly the
re-derivation this entry exists to prevent** — and the roster is now long enough that the collision is
not obvious. Corrected in the ledger: that row now names this entry.

---

### D20 — The service worker caches a credential-bearing resume shell, where the credential IS the cache key. Purge it, keep it, or split the difference? **2 — cache the resume shell under a token-free key.**

**Answered 2026-09-20 (user decision, in chat), recorded by Lane A during `M105` — 2.**

**Filed 2026-09-06 by Lane A, during `M78`, at the moment the row's two stated blockers were both
measured dead and a real trade was found underneath them.** The row (`M70`) asks to stop caching
`/f/resume/{token}`. Its two reasons for not doing so are now known to be false, and what replaced them
is a genuine product question rather than an engineering one — which is why this is here and not in the
diff.

**The exposure, measured.** `GET /api/v1/public/drafts/{resumeToken}` carries **no auth middleware**; the
token in the path is the whole credential, and the response is the respondent's full answer map **plus a
freshly minted share token**, so it is a write credential too. The resume navigation is cached under
`guest-shell-html` on a seven-day clock. ⛔ **Cache Storage is ORIGIN-scoped, not per-document, and the
token is the cache KEY** — so any same-origin script can run
`caches.open('guest-shell-html').keys()` and enumerate every resume token on the device **without reading
a single response body**. Stripping `data-resume-token` from the HTML would therefore not close it.

**What purging actually costs, measured — and it is not what the row says.** *"It costs offline resume
access outright"* is **false**: `App.vue`'s `loadResume()` opens with a bare fetch to a path no
service-worker route matches, so offline it rejects and the IndexedDB read two calls downstream is
unreachable. **A cached resume shell has never rendered the form offline.** What it does carry is the app
shell, the offline indicator and the always-render sync surface — including the *"Sync now"* action
`docs/non-functional-requirements.md` §7 makes the iOS Background-Sync fallback. ⚠️ **For a respondent who
only ever opened an emailed link, that entry is their ONLY cached navigation**, so purging it costs them
the entire offline surface, not the form.

**Three real options.**

1. ⭐ **Purge the resume shell from the cache, and accept that a resume-link-only respondent has no offline
   surface.** One predicate on the shell route. ⚠️ It is **not** a two-line change: it makes
   `isResumeShell()` in `lib/brand-cache.ts` guard a condition that can no longer arise, turning its three
   dedicated cases **vacuously green** — the succeeds-on-empty-input shape this repository gates against
   everywhere, and the exact predicate `M75` worked to make load-bearing. Those cases must be deleted or
   explicitly re-labelled as unreachable in the same PR. ⚠️ And resolving them makes the row cite
   `brand-cache.test.ts`, the one non-hub file the open second-writer row already cites, so under `D13` the
   two rows can no longer share a batch — the situation `M74` deliberately refused to create.
2. **Keep the write and close the enumeration instead** — cache the resume navigation under a
   **token-free key** (rewrite the cache key to a constant like `/f/resume/`, serving the shell from a
   single entry) so `keys()` leaks nothing and the offline surface survives. Costs: one shell serves every
   resume session on the device, so the brand-refresh sweep and the seven-day clock both become per-device
   rather than per-link, and `isResumeShell()` stays meaningful. This is the option the row never
   considered, and it is the only one that keeps both properties.
3. **Do nothing and record the exposure as accepted**, on the grounds that reading it already requires
   same-origin script execution on the tenant origin — i.e. an XSS or a compromised bundle, at which point
   the attacker can read the live token from the page anyway. ⚠️ The counter-argument is durability: the
   cache holds **every** resume token the device has seen for seven days, where the page holds one.

**Recommendation: option 2, and it is not close.** Option 1 trades a real, documented accessibility
fallback for a threat that requires same-origin code execution, and it does so while manufacturing three
vacuous tests and a batching conflict. Option 3 leaves a seven-day, device-wide credential store in place
for no benefit once option 2 is known to exist. Option 2 removes the enumeration primitive — which is the
part that turns one compromised session into every resume link on the device — while keeping the offline
surface the requirements commit to. ⚠️ **It needs its own measurement before being taken**: whether a
constant-key shell breaks the resume boot's own `data-resume-token` read, since the served HTML would then
be some *other* session's. If it does, option 1 becomes the fallback and its three vacuous tests must be
handled as described.

**Until this is answered**, `resources/public-runtime/__tests__/sw.test.ts` pins the current behaviour
explicitly — one arm asserts the resume shell IS matched today, labelled as a pinned exposure rather than
an endorsement, so the state cannot drift silently in either direction.

---

### D28 — Should `MdsSegmentedControl` get a component-level wrap or shrink affordance, or should its four stretch-clamped hosts keep guarding themselves? **3 — leave the component alone and guard at the host.**

**Answered 2026-09-20 (user decision, in chat), recorded by Lane A during `M105` — 3.**

**Filed 2026-09-08 by `M87`, while closing the census row that measured the answer's inputs.** Recorded
here rather than taken, because the cheapest correct fix touches **13 call sites** and the only instrument
that can verify it is an e2e run in the container — so this is a change whose blast radius exceeds what the
increment that found it can honestly validate.

**What is now measured, and it is why the row can be closed while this stays open:**

| | |
|---|---|
| Call sites | 13, across 9 files |
| Stretch-clamped hosts | **4** — `.config__group`, `.mds-field` (members ×2), `.sheets-fields`, `.encode-field` |
| …of those, inside an `overflow-y: auto` box | **2 of the 4 classes established** — `.config` for `.config__group`; `.mds-modal__body` for `.mds-field` (twice) and `.sheets-fields`. ⛔ **`M109` corrected this line.** *"4 of 4 — `.config` for the first, `.mds-modal__body` for the other three"* mixed a class count with a call-site count: those are three call SITES across two classes, and `.encode-field` is in no modal at all — it renders in an `MdsCard` on the encode page and in the public runtime, so its containing scroller was never established. |
| …with any e2e coverage | **1** (the builder pane, and only through a document-level assertion that cannot see it) |
| `flex-shrink: 1` on `__seg` | a **no-op** — the initial value, and absent from the tree |
| The 30px | **unprovenanced** — no test, fixture or snapshot records it |

⛔ **EVERY ONE OF THE FOUR ABSORBS ITS SPILL INTO A SCROLLBAR NOBODY LOOKS FOR**, which is why no gate has
ever reported this and why "is it real today" cannot be answered from the tree. `.app-shell__content`
measures 0 everywhere — the wrong box.

**The options:**

1. **`flex-wrap: wrap` on `.mds-segmented`.** Covers all four hosts at once and every call site with them.
   ⚠️ The stated reason this was ruled out — *"`.topnav` is a fixed 64px with `flex-shrink: 0`"* — is
   **stale**: that instance collapses to glyphs at ≤1024 and is `display: none` at ≤899, so it never
   reaches a width where a wrap could grow the bar. Cost: a 13-call-site visual re-measure.
2. **`min-width: 0` plus an `overflow` escape on `.mds-segmented__seg`.** Also component-level. ⚠️
   `min-width: 0` **alone is incomplete**, not merely conservative: `__seg` carries no `overflow` and its
   span no `text-overflow`, so it converts a fieldset-level spill into a text-level one that still extends
   the container's scrollWidth. Adding `white-space: nowrap` to make an ellipsis work would change wrapping
   at all 13 sites, which is the same blast radius as option 1 with less of the benefit.
3. ✅ **Leave the component alone and guard at the host, as one host already does. Recommended.**
   `resources/js/Pages/Settings/Index.vue` solves this exact problem with `align-items: center` plus a
   `min-width: 0; max-width: 100%` rule on its non-text children, **with a comment saying so**, and three
   call sites are protected by it today. It is the smallest change, it needs no re-measure of the nine
   unaffected sites, and it is already proven in this codebase. ⚠️ Its honest cost: four hosts must each
   remember, and the fifth one written next year will not — which is the argument for 1 or 2, and it is a
   real one.

⚠️ **Whichever is chosen, the instrument comes first.** A ~6-line element-level Playwright assertion on
`.config`, in the shape `personalization-axe.spec.ts` already uses twice, decides whether the spill exists
at CI's font stack — and it is the only thing that can, because the dev host never loads the dyslexia face.
**It is not shipped here deliberately**: added blind it would either merge green and prove nothing or go
red and block an increment on a question nobody has answered. It belongs with whichever option is taken.

⛔ **`M109` (2026-09-25) — THE ANSWER SURVIVES; ITS ILLUSTRATION DOES NOT, AND A READER WHO FOLLOWS THE ILLUSTRATION SHIPS A NO-OP.** Option 3 is still the answer and nothing about *guard at the host* changes. But the guard it points at cannot work on any of the four hosts. `Settings/Index.vue`'s `.settings-row` is a flex **ROW**, where `min-width: 0` overrides the automatic minimum size along the MAIN axis — that is why it works there — and the rule the illustration leaves out is `flex-wrap: wrap`, declared on that same host two lines above it. **All four defective hosts are flex COLUMNS**, where a stretched child is already exactly container-width, so `min-width: 0; max-width: 100%` changes nothing whatsoever. ⚠️ **The counter-example was already in the tree and in this entry's own table:** `.encode-field` carries `min-width: 0` today and still spills. What overflows is the control's own flex LINE, whose `__seg` has `min-width: auto` and therefore never shrinks below its longest word plus 24px of padding — and the table above already records `flex-shrink: 1` as inert for the same family of reasons without carrying it through to the recommendation. **The host-side affordance that does work is `flex-wrap: wrap` on the hosted control**, which is what `M109` declared at each of the four hosts. Filed as `R-6c429d4e`, closed beside `R-45b0cf8a` in one increment: shipping the fix while leaving this sentence standing would have left the roster prescribing a change that measures identically.

---

### D33 — May a workspace admin invite any email address, or only addresses at a verified company domain? **A — keep inviting anyone.**

**Answered 2026-09-20 (user decision, in chat), recorded by Lane A during `M105` — A.**

**Filed 2026-09-14 by `M93`, from Decision Board card `invite-domain`.** The ledger row it answers says in its
own words that applying a domain check is *"a product decision, not a cleanup"*: `MemberController::invite()`
validates the address and a role, with no domain-ownership check. An invitation grants nothing until it is
accepted, but it can put the product's mail in a stranger's inbox.

- **A — keep inviting anyone.** Contractors and personal addresses keep working; watch invitation volume on
  the testing server.
- **B — only verified company domains.** Refuses outside addresses unless the domain is verified, which changes
  what inviting means for every workspace.

**Recommendation: A.** An unaccepted invitation grants no access, and B would block ordinary use such as
contractors.

---

### D34 — Must a new account confirm its email address before that address counts as its own? **A — confirm first.**

**Answered 2026-09-20 (user decision, in chat), recorded by Lane A during `M105` — A.**

**Filed 2026-09-14 by `M93`, from Decision Board card `self-signup-email`.** Self-registration can occupy an
address the registrant does not control until the real owner resets the password. Nothing forges
`email_verified_at`, so the squatter takes over nothing — but any fix touches the ordinary registration path for
everybody.

- **A — confirm first.** The address is not the account's own until the emailed link is clicked. Stops the
  squatting, at one extra step for everyone.
- **B — keep it as it is.** No extra step; the real owner reclaims the address with a password reset.

**Recommendation: A** — the standard protection, and it matters most once outsiders can reach a server.

---

### D35 — Should form authors get a setting to show a form on one page instead of step by step, and which is the default? **A — add the setting, default step by step.**

**Answered 2026-09-20 (user decision, in chat), recorded by Lane A during `M105` — A.**

**Filed 2026-09-14 by `M93`, from Decision Board card `single-page-mode`.** The runtime can render a single-page
form, but `forms.single_page_mode` has no writer outside the seeders, and its documented default disagrees across
four documents — the PRD says single page should be the default.

- **A — add the setting, default step by step.** Existing forms look exactly as they do today.
- **B — add the setting, default one page.** Matches the PRD; new forms open as a single page.
- **C — remove single-page mode.** Drop the unused capability and correct the documents instead.

**Recommendation: A** — authors get the choice without changing what testers already see.

---

### D36 — When staff correct a submitted response, should their edits be kept automatically as they type? **A — keep saving on the button, and document that corrections are not resumable.**

**Answered 2026-09-20 (user decision, in chat), recorded by Lane A during `M105` — A.**

**Filed 2026-09-14 by `M93`, from Decision Board card `correction-autosave`.** A correction is kept only when
Save is pressed; the page already warns before leaving, so work is lost only to a browser crash. The ledger row
it answers says the gap is an endpoint that does not exist plus a decision nobody has taken.

- **A — keep saving on the button.** No change. Each save sends an approved response back for review and is
  audited once.
- **B — a working copy kept as staff type.** Review status and the audit log change only on the final save. A
  second save path to build.

**Recommendation: A** — loss is already limited to a crash, and B needs a whole second save path.

---

### D37 — If someone loses the device they use for two-step sign-in and has no recovery codes, how do they get back in? **A — an admin reset, recorded in the audit log.**

**Answered 2026-09-20 (user decision, in chat), recorded by Lane A during `M105` — A.**

**Filed 2026-09-14 by `M93`, from Decision Board card `twofa-recovery`, and from `docs/security-threat-model.md`
§9.** Today there is no way back without an operator editing the database: disabling two-factor sits behind
`auth` and `password.confirm`, and the super-admin console offers no reset. Testers who turn two-step sign-in on
could lock themselves out.

- **A — an admin reset, recorded in the audit log.** A workspace owner or the platform operator clears the
  enrolment, and the reset is audited.
- **B — re-verify by email.** The person proves they own the address, then enrols again. More to build.
- **C — support only.** Keep it manual, and say so on the challenge screen.

**Recommendation: A** — the simplest safe path for testing, and every reset leaves an audit record.

---

### D54 — The testing site sends no `Strict-Transport-Security` header. What `max-age` should it commit to, and does the vhost or the application send it? **A — `max-age=300`, in the vhost, no `includeSubDomains`, no `preload`.**

**Answered 2026-09-20 (user decision, in chat), recorded by Lane A during `M105` — A.**

**Filed 2026-09-19 by `M101`, which took the sibling `X-Robots-Tag` row and would not guess this one.** `R-06228b4f`
says in terms that *"the remedy is a decision before it is a header"* and then carried no `Awaits` token, so
`docs/pipeline.md` published it as **ready** and any session could have taken it and chosen a number on the user's
behalf. On a `.gov.ph` host HSTS is a commitment made to the **browser**, not to us: once sent, a failed renewal
becomes a hard block with no click-through for as long as the `max-age` says, and this box's renewal has already
failed eleven times in one day (`D49`) and rests on a scheduled task that is about 59 days from its first real
exercise. `preload` must not be sent at all while the name is a staging host, and that is not in question here.

**What was measured for this entry, on 2026-09-19, from outside the agency network:**

- `Strict-Transport-Security` is **absent** from `/`, `/login`, `/up`, `/robots.txt`, `/favicon.ico` and a 404 —
  and the strings `Strict-Transport-Security` and `HSTS` appear **nowhere in the tracked tree**.
- ⚠️ **Port 80 has no listener.** A connection from outside times out after 21 seconds. So there is no plaintext
  downgrade path on this box for HSTS to protect against, except an active attacker who opens one. **That narrows
  the benefit; it does not narrow the cost**, which is unchanged. ⛔ **CORRECTED BY `M102` (2026-09-19), WHICH READ THE BOX INSTEAD OF PROBING IT FROM OUTSIDE: THE FIRST SENTENCE OF THIS BULLET IS FALSE.** `httpd.conf` carries an uncommented `Listen 80`, and `httpd -S` maps a live `*:80` vhost for `staging.pitahc.gov.ph` to `confxtra\httpd-vhosts.conf` at `:47`, serving a `Redirect permanent` to the `https://` origin. **There IS a listener and there IS a plaintext request**; the 21-second timeout measured the agency firewall, not the server. So an internal tester who types the bare hostname makes exactly the cleartext round-trip HSTS exists to remove, and the benefit is wider than this bullet says — narrower than a public host, but not nil. ⚠️ **This corrects an input, not the recommendation: `A` still stands**, and if anything it stands more firmly, because there is now a real downgrade path for a short `max-age` to close.
- ✅ **HSTS is HOST-scoped, and this is what separates it from the sibling row.** One response carrying it covers
  the whole origin, so the application is an adequate home for it — the exact opposite of `X-Robots-Tag`, which is
  response-scoped and had to stay in the vhost because Apache serves `/robots.txt` without invoking PHP at all.
- ⚠️ **But there is no precedent in this tree for an environment-conditioned response header** — zero instances
  across `app/`, `config/`, `bootstrap/` and `routes/` — and `AppSecurityHeaders` is mounted per route group at ten
  sites, never globally, because a global mount would break every embed. So the application arm means a **new,
  separate, globally-appended middleware**, not a line added to the existing one.

- **A — a deliberately short `max-age`, in the vhost beside the existing `X-Robots-Tag` line.** `max-age=300`, no
  `includeSubDomains`, no `preload`. A lapsed certificate then hard-blocks a tester for five minutes rather than a
  year, the mechanism is exercised before production ever needs it, and it needs no new pattern. It also arms for
  free under the gate `M101` just built: adding it is one entry in `STAGING_REQUIRED_HEADERS`. Cost: the value
  lives on the box, un-versioned, which is the very thing `R-562bcc2a` was about — mitigated by that gate, not
  removed by it.
- **B — a conventional `max-age=31536000`, in the vhost.** The strongest posture and the one a scanner expects. It
  is also the most damaging thing on this list if a renewal ever lapses mid-testing: a year of un-clickable-through
  failure on a `.gov.ph` address, for every tester who ever visited.
- **C — send nothing on staging; build a global middleware enabled for production only.** Production inherits a
  versioned, tested header and the testing site keeps its click-through. Costs a middleware, a registration in
  `bootstrap/app.php`, and the first environment-conditioned header in this repository — and leaves the testing
  site with no HSTS at all, which a security review will ask about.

**Recommendation: A.** The measured inputs push the same way. The benefit here is narrower than the row assumes,
because there is no port 80 to downgrade from; the cost of getting it wrong is a hard block on the exact people the
site exists for; and a five-minute commitment buys the posture while bounding the blast radius to minutes. ⚠️ **A
and C are not exclusive** — A is the right answer for *this box now*, and C is the right answer for production
later, which is a separate row rather than a second option here. ⛔ **Whatever is chosen, it must not be `preload`.**

---

### D49 — The testing site's certificate expires 2026-10-13, and no renewal path in the checklist can validate while inbound 443 stays shut. What renews it? **A — open inbound 443, and `tls-alpn-01` renews it.**

**Answered 2026-09-18 (user decision, in chat), recorded by Lane A during `M100` — A.**

**Filed 2026-09-17 by `M98`, while measuring the checklist's certificate step against what the network actually
allows.** Three renewal routes are written down — two in the checklist, and DNS-01 as the wildcard-certificate
assumption D46 records in `docs/deployment-infrastructure.md` §8 — and each needs something this network does not
give. Port 80 is closed from outside (D46), so win-acme's `http-01` could never have worked, yet the checklist's
*keep the existing renewer* path counts a win-acme installation as a working renewal. `mod_md`'s `tls-alpn-01`, the
checklist's other path, needs Let's Encrypt's validators to reach port 443 from the internet, and inbound 443 to
the server's public address was measured **dropped** on 2026-09-17 — which sits badly with D46's recorded TLS
handshake on 2026-09-15, although D46 says only that the handshake was made *outside the box*: a different front
address then, a firewall change since, or a 2026-09-15 handshake made from inside the office network would each
explain it, and none is measured. DNS-01 would validate, but DICT runs the name servers for `pitahc.gov.ph` and
offers no API, so it cannot be automated. The certificate served on 2026-09-15 expires **2026-10-13**, and the
weekly blind Apache restart the checklist records on the box — unmeasured from here — is not a renewal of anything.

- **A — ask the network team to open inbound TCP 443** to the server's public address. It is also the only thing
  that lets testers off the office network reach the site at all, and it makes `tls-alpn-01` work, so the
  certificate then renews itself. Costs a request to the network team and one confirmation from mobile data.
- **B — renew by hand with a DNS-01 TXT record**, requested from DICT at each renewal. No network change, and
  every renewal becomes a request to another agency, ninety days apart, with the site's certificate resting on
  someone remembering.
- **C — install an agency-issued certificate** — commercial or DICT-provided, typically a year long, with no ACME
  at all. Costs money or an agency process, and the file is still installed and replaced by hand.

**Recommendation: A, with B as the fallback** if 443 cannot be opened before the expiry. ⚠️ **An answer is needed
well before 2026-10-13; the 2026-10-01 bound below is this entry's own, not one anybody has agreed:** both routes
depend on another team acting, and B needs a TXT record published while the challenge is live. If nothing is
decided, the certificate lapses on 2026-10-13 and every tester meets a browser warning on a `.gov.ph` address,
which is the worst possible thing for them to be taught to click through.

**The verification recommended A; the network team opened inbound 443 and the user chose A.**

⚠️ **The question's own dates are superseded, and are kept as asked rather than rewritten.** The 2026-10-13 expiry
and the 2026-10-01 bound were both real when this was filed. The certificate they refer to has since been
replaced: the served leaf is now valid **2026-09-18 → 2026-12-17**, so the deadline this entry was racing no
longer exists and the next renewal falls due around 2026-11-17.

**Consequences, recorded so they are not rediscovered:**

- **Inbound 443 is open and was measured from outside the agency network**, on 2026-09-18 and again on 2026-09-19
  during `M100`: DNS resolves to `121.58.210.237`, TLS 1.3 completes, `/up` and `/login` both answer 200, and the
  served leaf is `CN=staging.pitahc.gov.ph` issued by `CN=YE2, O=Let's Encrypt`, serial `05F10E…53AE`, valid to
  2026-12-17. **Port 80 stays closed**, which is why `tls-alpn-01` rather than `http-01` is the challenge.
- ⛔ **Opening 443 was necessary and was not sufficient. The renewal then failed for a second, unrelated reason,
  and it cost a day to find: an EC-versus-RSA key-type mismatch.** The served leaf is EC P-256, while mod_md's
  default challenge certificate is RSA. Apache holds one certificate slot per key type and mod_md's `tls-alpn-01`
  swap replaces only the RSA slot, so Let's Encrypt — which resolves to ECDSA — was handed the ordinary
  certificate and answered *"Received certificate which is not self-signed."* **The fix is one directive,
  `MDPrivateKeys secp256r1`**, and it is in `conf\extra\meridian.conf`.
- ⛔ **ON WINDOWS mod_md CAN NEVER ACTIVATE A RENEWED CERTIFICATE BY ITSELF, so "the certificate then renews
  itself" in option A above is true only of the issuance, never of the installation.** `md_server_graceful()` is
  `APR_ENOTIMPL` on WIN32 and has no caller anywhere in the module; staged-to-live promotion happens only in
  `md_reg_load_stagings()`, called from exactly one place, `md_post_config_before_ssl()` — an Apache restart. A
  renewed certificate otherwise sits in `md\staging\<domain>\` while the served one expires.
  **`M100` built the scheduled task that performs that restart**: `scripts/activate-staged-cert.ps1`, registered as
  `meridian-certificate-activate`, daily at 03:20 as SYSTEM. It restarts only when a certificate is actually
  staged, refuses when `httpd -t` fails, and proves the activation by re-reading the served certificate. It was
  proved by deliberate defect on all four arms. `docs/deployment-infrastructure.md` §8.3 is the runbook for it.
- ⚠️ **The predecessor of that task looked like it already did this job and could not have.**
  `C:\meridian\check-certificate.ps1`, run daily with `-RestartIfReady`, tested for the exact filename
  `pubcert.pem`. With `MDPrivateKeys secp256r1` in force mod_md writes `pubcert.secp256r1.pem`, so its activation
  arm was unreachable from the moment the key-type fix landed — while the task still exited 0 and logged success
  every day. **The fix for one defect silently disarmed the mitigation for another, and every signal stayed
  green.** It is now unregistered, its definition backed up, and the script parked as `.superseded`.
- **The rate limit to respect if a renewal is ever forced by hand:** five failed validations per account per
  hostname per hour, resetting on the hour. Failed orders issue nothing, so the five-per-week duplicate-certificate
  limit stays untouched — the eleven failures of 2026-09-17 consumed none of it.
- **B and C are not needed and should not be built.** DNS-01 through DICT and an agency-issued certificate both
  stay available as fallbacks if 443 is ever closed again, and neither has any standing work attached.

---

### D48 — Should the repository stay public? **Public with fake data only through testing; private before any real data.**

**Filed and answered 2026-09-17 (user decision, in chat), recorded by Lane A during `M98`.** Visibility had never
been asked as its own question. `D6` redacted a named client from the working tree and left the history alone, and
`D9`, which asks whether that history should be rewritten too, is open and recommended against; both say in terms
that the exposure is reduced rather than closed *because* the repository is public. `D9`'s third option, going
private, was refused there in passing as *"a much larger decision about the project"* that would *"silently remove
the free-Actions-minutes premise several CI decisions rest on"*. This answer takes that option and gives it a
trigger instead of a refusal.

- **A — stay public indefinitely.** Standard runners stay free; `D6`'s and `D9`'s residual exposure stays
  open-ended.
- **B — public with fake data only through testing, private before any real data.** Nothing real is ever exposed,
  and the runner minutes stay free for the whole of testing.
- **C — go private now.** Closes `D9`'s forward exposure today and starts billing every CI minute today.

**The user chose B.**

**What the minutes cost, measured with `gh`:** 418 CI runs in the 30 days to 2026-09-17, of which **85 were
cancelled**, so 418 is an upper bound on full runs — a re-count on 2026-09-18 over a window one day later reads 393
(295 green, 23 failed, 75 cancelled), so the figure moves with the window. One full run bills about **38 minutes**:
six Ubuntu jobs measured at about 2, 3, 2, 1, 19 and 11 minutes, each rounded up to the minute, against 19 minutes
of wall clock. The run's own billable reading is zero, because a public repository's standard-runner minutes are not
billed. Once private, GitHub's plans page lists **2,000** free minutes a month on Free and **3,000** on Pro, and the
published rates are **$0.006 a minute** for a 2-core Linux runner and **$0.010 a minute** for a 2-core Windows
runner. At the 418-run volume that is roughly 15,900 minutes a month, about **$83 a month** on Free — about $64 if
the cancelled runs are left out, and about $78 at the 393-run re-count — before any Windows job exists. The nightly
schedule alone is about 1,140 minutes a month, more than half the Free allowance, and `ci.yml`'s comment that the
schedule is free because the repository is public becomes wrong at the flip.

**The obligations this answer creates:**
- **While public, fake data only.** No real respondent data, no real client name, no live credential in the tree, in
  a fixture or in a screenshot. Invitation-only sign-up (D31) keeps strangers out of the site; it says nothing
  about what is in the repository.
- **Before the flip, in this order.** (1) Read the account plan: GitHub's plans page puts protected branches for
  private repositories under Pro, and `gh api user` returns a null plan, so whether `D7`'s six-required-check
  ruleset keeps enforcing after the flip is INFERRED and unmeasured — upgrade, or accept losing the enforced merge
  gate. (2) Give the server a credential: `deploy.ps1` fetches over anonymous HTTPS today, so it needs either a
  read-only deploy key (an SSH remote, the key and `known_hosts` in NETWORK SERVICE's profile, and outbound port
  22, or port 443 on `ssh.github.com`, which the checklist's outbound list does not allow) or `deploy.yml` handing
  git the job's short-lived `GITHUB_TOKEN` through `http.extraheader`, which leaves no long-lived secret on the
  server but leaves a hand run — priming, or a rollback with `-Ref` — without one, so that account needs a
  documented credential of its own; set `GIT_TERMINAL_PROMPT=0` in `deploy.ps1` either way, because an auth prompt
  under a service account may hang rather than fail (INFERRED, not measured). Prove it while the repository is
  still public by switching the remote first. (3) Trim CI to a minutes budget — the nightly schedule, the six jobs
  every pull request runs, and `D21`'s `paths-ignore`; CI runs only on `main` pushes, pull requests targeting
  `main`, the schedule and a dispatch, so there is no per-branch job to drop. A before-launch row in
  `docs/feature-backlog.md` is owed for all three and is not filed yet; the flip itself is the user's action.
- **Five decisions now need annotating rather than reopening**, because the repository stays public through
  testing: `D9` (its refused third option is scheduled, not refused), `D21` (its close-out cost becomes billable),
  `D40` (the pilot customer's name stays out only until the flip), `D6` (the public-history limit gains an end
  condition) and `D45` (a Windows job is free today and $0.010 a minute afterwards).

---

### D47 — Should automatic deploys to the testing server be turned on now, or stay dormant until the deploy window is proved? **B — turn them on now.**

**Filed and answered 2026-09-17 (user decision, in chat), recorded by Lane A during `M98`.** `deploy.yml` was
committed dormant: every run of it skipped while the `DEPLOY_ENABLED` repository variable was unset, so the deploy
half of the testing-server build had never run by itself. The user set the variable in the GitHub UI rather than
waiting for the close-out redeploy row to be fixed first.

- **A — leave it dormant** until the same-sha skip ships and the window's length has been measured, deploying from
  the server by hand in the meantime. INFERRED wording: option A was never written down anywhere in the
  repository, and this is what the alternative amounted to.
- **B — turn them on now**, and take the first automatic window on the next push to `main`.

**What is measured**, read with `gh` on 2026-09-17: `DEPLOY_ENABLED=true`, set at 14:49Z that day, with
`MERIDIAN_APP_PATH` set beside it; the fork pull-request approval policy reads `all_external_contributors`; and the
self-hosted runner is registered and online, labelled self-hosted, Windows, X64.

**No recommendation was filed before the answer — the user turned deploys on, and this entry records it.**

**Consequences, recorded so they are not rediscovered:**
- Every green CI run on `main` now runs `deploy.ps1` on the server: a merge push, a close-out push that touches
  `docs/pipeline.md` or `docs/feature-backlog.md`, a direct fix push, the nightly schedule and a manual dispatch.
  A claim-only push still produces no run, because `docs/claims/**` sits in `ci.yml`'s `paths-ignore`, and neither
  does a push touching only `docs/backlog-triage.md` or `docs/gate-baselines.md`, which are in that list too. One
  thing only holds a pull-request run off: `deploy.yml` fires on a `workflow_run` whose head branch is named
  `main`, and its `if` reads the conclusion and the variable but never `workflow_run.event`, so an approved green
  pull request from a fork's own `main` branch is not excluded by it. INFERRED from `deploy.yml`; no fork run has
  been approved.
- A documentation close-out therefore deploys the site, which is what the close-out redeploy row in
  `docs/feature-backlog.md` describes. Its fix belongs in `deploy.ps1` rather than in `paths-ignore`, because
  `docs/pipeline.md`'s freshness is merge-gated (D21), and it has to compare against the recorded deployed sha
  rather than the push's own diff.
- The nightly run does not run at 03:00. `ci.yml` asks for `cron: "0 3 * * *"`, but the last six scheduled runs
  started between 07:49Z and 08:45Z — the most recent three at 08:27Z, 08:32Z and 08:32Z — and their Deploy runs
  followed at 08:46Z, 08:48Z and 08:51Z, measured with `gh run list` on 2026-09-18. That is about **16:50
  Philippine time**, the middle of the testers' afternoon, every day.
- A run on a sha that is already live opens no window: `deploy.ps1` compares the fetched sha with the recorded
  deployed sha and stops at `==> Nothing deployed`, so a nightly run on an unchanged tip is a no-op, and the
  checklist's expected `==> Deploy complete` line will not match it.
- Rows this makes live rather than latent, all in `docs/feature-backlog.md`: the close-out redeploy (only its
  stale header wording was live while the variable was unset), and the two window rows whose preconditions — a tab
  held open across a deploy, a worker restarted inside the window — are ordinary tester traffic from now on. Two
  others stay latent and are merely reachable without an operator now: the second `.env` copy left in
  `.deploy-stage` needs a deploy that fails before `up`, and the JSON request that falls through the window needs
  one in flight while the renames run.
  ⚠️ **Corrected 2026-09-18 during `M99`: the second of those was only half latent, and the half that was live
  was the more important one.** A request in flight while the renames run is what the *fatal error* needs; the
  broken CONTRACT needed nothing — even a fall-through that booted cleanly answered `/api/v1` with a 503
  `server_error` from the framework's `Throwable` arm rather than the documented `maintenance_mode` envelope, for
  the whole window, every window. `R-4ff3e848`'s own marker had already been corrected to `Live` and this bullet
  was not, which is the two-copies-of-a-fact shape: the ledger and this entry disagreed and only one was amended.
  Both are now moot — `public/maintenance-guard.php` answers those requests above the autoloader.
- The window's measured length is owed by the next merge's Deploy run. No CI run, and so no Deploy run, has
  happened since the variable was set: the newest Deploy run is 2026-09-17T08:51Z and it skipped, and the one push
  to `main` since — a claim commit touching `docs/claims/lane-a.md` and nothing else — is inside `paths-ignore`
  and produced no run at all.
- It is new input to D45 and does not close it, and it does not make the deploy's unverified sha safe: the job
  fetches `origin/main` rather than the triggering run's sha, and no row covers that yet.

---

### D46 — The testing server is the existing box behind `staging.pitahc.gov.ph`. How is the site laid out, with no new DNS records? **C — one workspace at the root of `staging.pitahc.gov.ph`, served by Apache, with the older sites removed.**

**Filed and answered 2026-09-15 (user decision, in chat), recorded by Lane A during `M97`.** The user's testing
server is the Windows Server 2016 box that already serves older sites at `staging.pitahc.gov.ph`, through Apache on
port 443, with port 80 closed from outside (measured from outside the box by DNS lookup, TLS handshake and HTTP
HEAD, not on the box). DICT's name servers answer for `pitahc.gov.ph`, with no API, and the user will request no new
records. `docs/deployment-infrastructure.md` §8 assumes nginx, a wildcard DNS-01 certificate and wildcard `A`
records, so none of its address or certificate steps could be followed as written.

- **A — request two records from DICT**, a central host and one workspace host, and follow §8. No limits, but it
  needs a DICT request and a new certificate.
- **B — serve the app under a path**, such as `staging.pitahc.gov.ph/meridian`. Measured as impossible without a
  large code change: every signed-in route group identifies the workspace by subdomain, about 150 front-end URLs
  are root-relative, and the service worker's scope is `/f/`.
- **C — serve the app at the root of `staging.pitahc.gov.ph`** with `CENTRAL_DOMAIN=pitahc.gov.ph` and
  `APP_URL=https://pitahc.gov.ph`, so the address becomes the one workspace, `staging`. No DNS change, and the
  existing certificate already covers the name.

**The verification recommended A, the only layout without limits. The user asked first for B, and chose C once B
was measured; they also chose Apache, already on the box, over nginx.**

**Consequences, recorded so they are not rediscovered:**
- There is exactly one workspace, and its slug must be `staging`. A second workspace needs its own DNS name.
- The operator console answers at `pitahc.gov.ph`, the agency's main website, so it opens only on the server
  itself, through a hosts-file entry, with a certificate-name warning.
- Links built from `APP_URL` rather than from a workspace host point at the agency's main website: a welcome email
  to someone outside the workspace, the redirect for an unidentifiable host, and the logo link in branded email
  (`app/Support/Branding/BrandPalette.php`). Invitation, password-reset, verification and share links carry the
  request's or the workspace's host, and are unaffected.
- ⚠️ **Corrected 2026-09-17 during `M98`: the branded and unbranded halves of the bullet above are the wrong way
  round.** Tenant-branded mail builds its header link from the workspace's own host, and it is **unbranded** mail
  that takes `config('app.url')` — password reset, address verification and the welcome email
  (`app/Support/Branding/BrandPalette.php`, `app/Notifications/Concerns/CarriesTenantBrand.php`). So a tester's
  reset and verification mail carries a header link to the agency's website, while an invitation, which is
  branded, does not.
- ⚠️ **Added 2026-09-17 during `M98`: Google sign-in cannot work on this layout.** The OAuth redirect is built from
  `APP_URL` (`config/services.php`), so it resolves to `https://pitahc.gov.ph/auth/google/callback` on the agency's
  website, and a failed sign-in whose state named no workspace is sent to `APP_URL/login` for the same reason. It
  is latent: the checklist never configures Google, so nothing exercises it yet.
- The Testing Server Checklist artifact carries this layout step by step. A row in `docs/feature-backlog.md` tracks
  bringing §8 in line, because the runbook in the repository still describes only nginx.
- The certificate served on 2026-09-15 expires 2026-10-13, and what renews it is not known. The checklist's
  certificate step covers both an existing renewal tool and Apache's own `mod_md`.
- ⚠️ **Corrected 2026-09-17 during `M98`: inbound 443 from outside was measured DROPPED**, which sits badly with
  this entry's own TLS handshake recorded on 2026-09-15 — though that one is recorded as made *outside the box*,
  not outside the network. The name may have resolved to a different front address then, the firewall may have
  changed since, or the handshake may have been made from inside the office network; none of the three is
  measured. While 443 stays shut, testers off the office network cannot reach the site at all, and no renewal path
  in the checklist can validate — port 80 is closed, so `http-01` cannot work, and `tls-alpn-01` needs inbound
  443. What renews the certificate before it expires on 2026-10-13 is therefore its own open question, D49.

---

### D31 — On the testing server, may anyone create an account, or only people who are invited? **A — invitation-only while testing.**

**Answered 2026-09-14 (user decision, in chat), recorded by Lane A during `M95` — A.** It is applied, not built:
`docs/deployment-infrastructure.md` §8.2 and the Testing Server Checklist turn Open signup off at `/admin/settings`
right after the first super-admin (`platform:super-admin`) has enrolled two-factor, and before the host is
published. Testers then join by invitation to a workspace the operator creates with `tenants:create`, which needs
a working mail transport and a running worker. ⚠️ **The conditional this answer spends:** the landing-page row in
`docs/feature-backlog.md` would have been retiered to before-testing on answer B; it stays early-testing, and its
text was amended in place. Whether sign-up should ever create a workspace is a separate question, D44.

**Filed 2026-09-14 by `M93`, from Decision Board card `signup-open`.** `SettingKey::RegistrationOpenSignup`
defaults to `true`, so a fresh install lets anyone who reaches the central host register. On a testing server
reachable from the internet that admits strangers — and a central-host account belongs to no workspace, so it
lands on a dead end this increment files as a row. The switch lives in the super-admin console; nothing here
needs code.

- **A — invitation-only while testing.** Turn the platform switch off; testers join by invitation to a
  workspace the operator creates. Reversible at any time.
- **B — open sign-up.** Anyone who finds the address can register. Useful only if outside testers should sign
  themselves up, and it exposes the no-workspace dead end until that row is fixed.

**Recommendation: A.** It keeps the server to people the user chose, invitations work normally, and it avoids
the dead end without code. ⚠️ **It blocks no row:** the answer is applied from the Testing Server Checklist.

---

### D32 — Will testers fill in forms together from one shared network, and should the guest per-address limit be raised for testing? **A — raise it on the testing server only.**

**Answered 2026-09-14 (user decision, in chat), recorded by Lane A during `M95` — A.** Applied from
`docs/deployment-infrastructure.md` §8.2 and the Testing Server Checklist: in the testing site's `.env`, raise
`GUEST_MINT_PER_IP` and, beside it, `GUEST_SUBMIT_PER_IP` and `GUEST_CHALLENGE_PER_IP` (`config/guest.php`), which
a group on one network exhausts in the same way; then run `php artisan config:cache`, because a cached config
ignores `.env` edits. Dev and CI keep the defaults, and the values are lowered again before launch.
⚠️ **Annotated 2026-09-17 during `M98`.** Do not lower them on the schedule this sentence implies. The testing
server sits behind source NAT, measured as one client address for everything arriving from the internet, so at the
default limits every public respondent would share a single bucket rather than one office sharing one. The raised
values must stay until the real client address reaches the app; the network fix was made on the network side and is
recorded in `R-df305332`, closed by `M100` on measurement: source NAT is gone, and real client addresses
reach the app. ⚠️ **The raised values still stay**, for this entry's ORIGINAL reason rather than the
source-NAT one — a room of testers behind one office NAT shares that NAT's public address — and
`docs/deployment-infrastructure.md` carries a second copy of this instruction that says so too.

**Filed 2026-09-14 by `M93`, from Decision Board card `guest-ip-limit`.** A public form mints its guest token
under `GUEST_MINT_PER_IP`, which defaults to 30 per minute and has no line in `.env.example`. A group session on
one office network shares an address, can hit the limit, and then sees "too many requests".

- **A — raise it on the testing server only.** An environment value, not a code change, and lowered again
  before launch.
- **B — keep the default.** Testers use their own connections, and the default stays as abuse protection.

**Recommendation: A** — group testing is likely early, and the value is one line to put back. ⚠️ **It blocks no
row:** the answer is applied from the Testing Server Checklist.

---

### D43 — Which PostgreSQL does the Windows Server 2016 testing site run? **B — stay on Windows Server 2016 with PostgreSQL 15.**

**Filed and answered 2026-09-14 (user decision, in chat), recorded by Lane A during `M95`. It was filed at tier
before-testing, because the runbook's database step could not name a version without it.** The user's testing
server is their own Windows Server 2016 box — the host `docs/adr/0005-hosting-self-hosted-windows-server.md`
describes — run as a separate testing site. Dev and CI pin PostGIS on PostgreSQL 17, and the EDB installer lists 17
as tested only on Windows Server 2019 and 2022, with 15 tested on 2016 (read from postgresql.org's Windows download
page during `M95`'s verification, not measured on the box).

- **A — upgrade the box to Windows Server 2022 and run PostgreSQL 17.** Parity with dev and CI, and it also
  discharges ADR-0005's mandatory operating-system move before Windows Server 2016's support ends (~January 2027).
  Against it: an operating-system upgrade stands between today and the first tester.
- **B — stay on Windows Server 2016 with PostgreSQL 15.** No operating-system work before testing. Against it:
  version parity with dev and CI is lost, and nothing gates it.
- **C — PostgreSQL 17 on Windows Server 2016.** Parity without the upgrade, on a combination the installer's vendor
  does not test.

**The verification recommended A; the user chose B.**

**Consequence, recorded so it is not rediscovered:**
- Dev and CI stay on PostgreSQL 17.
- `M95` ran the full CI suite once against PostgreSQL 15, on a throwaway probe branch opened as draft pull request
  #287 and closed unmerged. Pest passed 4,939 cases, and E2E, the contract tests, the frontend build and axe all
  passed. The one Pest failure was `SearchIndexUsageTest`'s guard that the server is PostgreSQL 17 or later, which
  fails by design on any other major; the leakproof-catalog cases it exists to protect passed on 15. Nothing
  specific to 15 needed fixing.
- No standing gate keeps that result true, so a during-testing row in `docs/feature-backlog.md` tracks parity.
- `docs/deployment-infrastructure.md` §8 step 2 prescribes a dedicated PostgreSQL 15 instance for the site.
- ADR-0005's operating-system trigger is unchanged; when the box is upgraded, moving the site to 17 retires the
  parity row.

---

### D12 — `D5`'s bar is now measurable, and it reads MET by nine increments rather than three. End the M-series, or keep going? **B — end the series. The tiered pipeline succeeds it.**

**Answered 2026-09-14 (user decision), recorded by Lane A during `M93` — B, and the answer arrived together with what replaces the series.** The user approved Realignment 6: the batch series ends, and ONE pipeline succeeds it, ordered by tier (`before-testing` → `early-testing` → `during-testing` → `before-launch` → `after-launch`), with every open decision a row and every open item in any document filed. Their words: *"i want everything in a single pipeline. no hidden tasks"* — and stand up a testing server as soon as the must-do items are done, then test and develop in parallel. ⛔ **This entry's own warning is discharged, not ignored.** It said B *"needs an answer to what replaces the series in the same breath"*, and the tiers are that answer: the held rows stay in the line under `before-launch`, and `D13` survives only as the rule for grouping the rows of one tier. `scripts/state.php` keeps printing the bar's two clauses as history; the queue's signal is now its Testing gate.

**Filed 2026-09-02 by Lane A, during `M64`, at the moment the bar became computable.** This is not a
re-ask of `D5` and it is not a re-ask of the answer given on 2026-09-02. `M63` reported the bar as
*reading* met and the user answered **"keep going and make the bar real first"** — an answer conditional
on the bar not yet being real. `M64` made it real. The condition is spent, so the question returns once,
with numbers instead of a floor.

⛔ **WHAT CHANGED IS THE EVIDENCE, NOT THE ARITHMETIC.** `M63`'s claim was a floor: 11 attributable
`major` bullets plus the absence of a contrary one, with **47 of 58 recording no filer**. Every severity
bullet now records one, resolved from the file's own history against all 135 of its versions, so the
clause is arithmetic:

| | |
|---|---|
| Open `major` rows | **0** — clause 1 |
| `major` bullets ever, all shapes | **55**, every one attributed, **none `(unattributed)`** |
| Highest increment that ever filed a `major` | **`M54`** |
| Consecutive released increments filing none | **`M55`–`M63`, nine** — against a bar of three |

⚠️ **AND THE MARGIN IS THE PART WORTH READING.** The answer to `D5` set the second clause at three
*because* the first clause alone is satisfiable at any instant by an increment nobody has verified yet.
Nine is not three: this is not a bar cleared on the last day, and eight of the nine increments in that
window each closed a row and filed new ones without any of them being `major`.

⛔ **WHAT THE BAR STILL DOES NOT MEASURE, SAID HERE RATHER THAN DISCOVERED AFTER STOPPING.** The gate
checks a filer is **recorded**, never that it is **correct** — a wrong id passes. Severity is
**self-assigned** by the increment that files the row, and no increment has assigned `major` since
`M54`, which is consistent with the defects getting smaller *and* with the bar quietly changing what
gets called `major`. Nothing here can tell those apart. **84 rows remain open**, and 30 of them say
nothing about whether they are still live.

- **A — keep going, and treat `D5` as satisfied-but-not-triggered.** The bar was written to stop a series
  that had no exit criterion at all, not to force a stop the moment it clears. 84 open rows remain and the
  recent ones are real: `M61` found a case-sensitivity defect that 404'd live share URLs, `M62` found an
  encode page discarding typed work, `M63` found a `can:` gate naming the wrong subject. **None of those
  was `major` and all three were user-visible.** Against it: a bar nobody acts on is `D5`'s own failure
  mode wearing the other face — *"declared met by whoever wants to stop"* has a twin in *"never triggered
  by whoever wants to continue."*
- **B — end the M-series here and re-plan.** `D5` was answered to make this a decision rather than a
  drift, and it has cleared by a factor of three. The remaining 84 rows do not disappear: they become a
  standing backlog worked under whatever succeeds the series, and the held list re-enters as the
  go-forward pipeline. Against it: the exit says nothing about the *shape* of what follows, and stopping
  without that is how a queue becomes a graveyard.
- **C — keep going, but re-cut the bar now that it can be measured.** `D5` recorded that the answer given
  was *not* the recommendation filed — the recommendation was a **category** bar (end on correctness and
  security, move style/docs/ergonomics to a standing backlog) and the answer was a severity bar. A
  category bar is measurable today and was not in `M36`: `state.php` sees every bullet, its filer and its
  liveness. Against it: re-cutting a bar at the moment it clears is exactly what it exists to prevent, and
  it needs the liveness backfill first — 30 open rows are unjudged.

**Recommendation: A, with the numbers on the record and this entry as the thing that makes B available at
any time.** The bar's purpose was to make stopping a decision rather than a drift, and that purpose is now
served whichever way it goes — it is measured, it is printed by `state.php` and `loop.php status` on every
run, and it cannot be quietly declared or quietly ignored again. What argues against acting on it *today*
is that the three most recent increments each found a live, user-visible defect while filing no `major`,
which is evidence the queue is still productive rather than evidence it is done. ⚠️ **C should not be
taken before the liveness backfill**, or the re-cut bar inherits 30 rows nobody has judged. ⛔ **And if B
is taken, it needs an answer to "what replaces the series" in the same breath**, because the held list —
OCR, uploading/import, payments, Track B, GDPR — is scheduled to re-enter at exactly that moment and that
is a bigger conversation than an exit condition.

---

### D13 — How should the remaining open backlog rows be worked, now that none of them is `major`? **In batches of 3–4 rows per increment, selected by file overlap, verified by a read-only fan-out, written by one hand.**

**Filed and answered 2026-09-02 (user decision), from a plan the user approved after an earlier version
of it was withdrawn; recorded by Lane A during `M65`, at the first increment run under it.** It is
recorded here rather than left in the plan file because a protocol nobody wrote down is a protocol the
next session re-asks — and this one was already asked, answered, and nearly re-litigated once.

⚠️ **Not to be confused with `§D13` in an ADR.** Three ADRs carry a sub-decision numbered `§D13` and
they are unrelated to this queue. Cite an ADR by **filename**, never by bare number; this entry is
`D13` in `docs/claims/decisions.md` and nothing else.

**What was measured before deciding** (2026-09-02, against this tree):

| | |
|---|---|
| Build phase, claim to work commit | ~70 min mean, `M56`–`M63` |
| Close-out, work to release commit | **~22 min and near-constant** |
| Session-start gap, release to next claim | ~65 min mean |
| **Overhead that is not the row's own work** | **~55% of wall-clock** |
| Open rows as a conflict graph, edge = a shared cited file | 50 components, largest 26, **43 singletons** |
| …with the hub files set aside | **65 components, largest 5** |

**The rows are not coupled.** The 26-row component is glued only by hub files — `ci.yml`, `PROGRESS.md`,
`CLAUDE.md`, this file, `scripts/state.php`, `PROGRESS_ARCHIVE.md`, `README.md` — which are meta-files,
not product code. Four rows in one increment cost `4×70 + 22 + 65` minutes against `4×157`, a **~42%
saving**, and the saving comes entirely out of overhead rather than out of verification.

**As decided:**

1. **Batch 3–4 rows per increment.** Selection is a file-overlap check, not a scheduling problem: **no
   two rows in a batch may cite the same non-hub file, and at most one row may touch a hub file.**
2. **Verification fans out; it does not compress.** Read-only subagents over disjoint rows, and the
   claim carries **`Evidence verified` and `Remedy verdict` once per row, never merged into one
   paragraph.** ⛔ That is the whole point: a row's evidence and its remedy are separately trustworthy —
   `M30`, `M31`, `M32` and `M34` each found their real defect in the remedy — and collapsing four rows
   into one narrative destroys exactly the property batching has to preserve.
3. **One writer.** Agents report; the session opens the citations and makes every edit. **`ADR-0022` is
   not reversed** — no second worktree, no second lane, no second claim file.
4. ⛔ **Gates remain the only validators. There is no approver agent and no validator agent.** An
   agent's approval cannot be turned red by `scripts/mutate.php`, which makes it a gate that cannot be
   proved — the decorative gate `M43` measured. The two roles that produce falsifiable output are the
   **researcher** (read-only verification against the code) and the **reviewer** (a finding *generator*,
   whose findings are then verified like any other). Those are the only two used.
5. **Bisection rule, agreed up front:** if a batch goes red and the cause is not obvious within one gate
   run, drop to the single row that reddened and re-run. **Never debug four rows at once.**

**What was considered and rejected, so it is not re-proposed:**

- **A scheduler script.** An earlier draft proposed one. The graph analysis was worth running once, and
  its finding is what makes the tool unnecessary: the rows are so weakly coupled that batch selection is
  a file-overlap check a reader can do from a table.
- **Widening `scripts/loop.php`'s mechanical recogniser.** Its `assess` clears few rows, and that is the
  gate working rather than a defect: matching row **bodies** instead of titles was measured to admit a
  CSS overflow row, a missing-middleware security row and an open decision — 68 of 78 wrongly in scope.
  The loop governs **unattended** runs only and is not in the path of an attended session, so widening it
  could not move the per-row cost this decision targets. ⚠️ **And completing the liveness backfill makes
  `assess` refuse MORE rows, not fewer. Do not read its eligible count as loop health.**

⛔ **THE SAVING IS TO BE PROVEN ON THE FIRST BATCHED INCREMENT, NOT ASSUMED.** Record `M66`'s claim,
work and release timestamps against the ~157 min/row baseline above. **If the measured saving is
materially under 40%, the batch size is wrong** — revisit it before running twenty more increments on
an unverified premise. Anyone promising more than ~42% is proposing to skip verification, which is the
half that works.

⚠️ **AMENDED 2026-09-14 BY THE ANSWER TO `D12`, RECORDED DURING `M93` — THIS NOW GOVERNS HOW THE ROWS OF ONE TIER ARE GROUPED, NOT WHICH ROWS COME NEXT.** The tiered pipeline decides what is next; items 1–5 above still decide how an increment groups rows taken from a single tier. `scripts/backlog-triage.php` no longer proposes a batch, and `scripts/next.php` no longer derives one from this entry.

#### `D13` exceptions — the numbered record, added 2026-09-24 by `M108`

**This subsection records DEVIATIONS from items 1–5 above. It does not amend them, and an entry here is not a precedent.** It exists because `R-4346557c` measured the alternative and found it wanting: the last exception, `M107`'s, was improvised in a closed row's own prose, which means the next session looking for *how may I except this* finds nothing, and the session after that improvises differently. The form is `docs/ux/exceptions-log.md`'s, which has held nineteen design deviations without drifting — **what was not satisfied · why · disposition** — and so is its escalation rule, restated here because it is the half that makes a log honest rather than a place to file guilt:

⛔ **THREE OR MORE EXCEPTIONS FOR THE SAME REASON MEAN THE RULE IS WRONG, NOT THE BATCH.** At that point the entry to write is not a fourth exception; it is an answer to `D15`.

⚠️ **AN EXCEPTION IS NOT ENFORCED BY ANYTHING, AND SAYING SO IS THE POINT.** No gate reads this subsection — `scripts/pipeline-lint.php` has no hub rule, and `M93` deleted `render_batch()` and `BATCH_MAX`, so the cap lives only in this entry's text and in whoever groups a tier's rows. This is a record, not a control. A reader who wants the control should read `D15`.

##### #1 — `M108` (2026-09-24) · three rows, every one touching a hub

**What was not satisfied:** item 1's second clause, *at most one row may touch a hub file*. The batch was `R-b6a4ea79`, `R-7849e303` and `R-4346557c`, and all three touch several. Item 1's **first** clause was satisfied exactly — the two rows carrying non-hub files carry one apiece and they are different files.

**Why:** because no batch could satisfy it, including a batch of one. `M108` measured the cap across all seven candidate rows and **7 of 7 breach it**; `R-7849e303` alone harvests three hub files before any sibling joins it; and five of the hub files are touched by close-out procedure alone. The measurement is recorded at `D15` rather than repeated here. ⚠️ **The honest statement is therefore not that this batch earned an exception but that the rule currently admits nothing** — and the first entry in an exceptions log being *the rule refused everything* is itself the finding.

**Disposition:** `D15` decides the rule; this increment did not touch it. `M108`'s own subject makes the question cheaper to answer rather than answering it — the harvester now reads class names, so the degree counts `D15` reasons about are measured rather than disputed. ⛔ **And the exception is recorded in BOTH places**, which no previous one was: inline at each closed row, the way `M107` did it, and in `PROGRESS.md`'s release paragraph, the way `M90` did it. Either alone is findable from one direction only, and the two precedents disagreeing about where to look is half of why `R-4346557c` was filed.

##### #2 — `M109` (2026-09-25) · four rows, clause 1 satisfied exactly, clause 2 breached by procedure

**What was not satisfied:** item 1's second clause, *at most one row may touch a hub file*. The batch was `R-e7d6f223`, `R-45b0cf8a`, `R-6c429d4e` and `R-2e2407e2`. ⛔ **Item 1's FIRST clause was satisfied exactly, and it is worth stating because it is the clause that still separates rows:** the four non-hub sets are pairwise disjoint — `deploy.ps1` plus `tests/Feature/Deploy/`; the four segmented-control hosts; none at all; `tests/Feature/Tenancy/CentralHostFallbackTest.php`.

**Why:** the same reason as #1, and nothing about this batch's composition. All four touch `docs/feature-backlog.md` (degree 9) and `docs/pipeline.md` (10) **by close-out procedure alone**, and `R-6c429d4e`'s whole remedy is an edit to this file (8). `M108` measured that no batch can satisfy the clause, including a batch of one; this increment did not re-measure it, because re-measuring a rule that admits nothing produces the same answer.

⛔ **THIS IS THE SECOND ENTRY FOR THE SAME REASON, AND THE THIRD IS THE TRIGGER.** The escalation rule above says three or more exceptions for one reason mean the rule is wrong rather than the batch, and that the entry to write at that point is an answer to `D15` — not a fourth exception. `D15` is open, is tiered `early-testing`, and already carries three options, a recommendation, and `M108`'s fourth option. **The next increment that composes a batch from this queue should expect to be the one that trips it.**

**Disposition:** `D15` decides the rule; this increment did not touch it, for the reason `D15` opens with — an increment does not re-scope a user decision on its own judgement. Recorded in both places the log requires: inline at each of the four closed rows, and in `PROGRESS.md`'s release paragraph.

##### The series ENDED at #2 — `M112` (2026-09-26) tripped the escalation and wrote the answer instead

⛔ **THERE IS NO #3, AND THAT IS THE ENTRY.** `M112` composed a batch from this queue and, as #2 predicted, was the increment that tripped the rule. The escalation above says three exceptions for one reason mean the rule is wrong rather than the batch, and that what gets written at that point is an answer to `D15` — not a fourth exception. **The user answered `D15` the same day, option 4**, and the cap now reads the files a row's REMEDY EDITS rather than the files it cites. The measurement, the cost and the one way to cheat the new rule are all recorded at `D15` rather than repeated here.

⚠️ **AND `M112` FOUND THE THING #1 AND #2 BOTH MISSED, WHICH IS WHY THE ANSWER WAS OWED RATHER THAN MERELY DUE.** Both earlier entries report item 1's FIRST clause — *no two rows may cite the same non-hub file* — as *satisfied exactly*, and `M108` concluded the rule was simply unsatisfiable on clause 2. `M112`'s batch breaches **clause 1**: `app/Services/Forms/StructuralValidationGate.php` sits at citation degree **2** against `HUB_THRESHOLD` 3, so it is a non-hub file, and two of the three rows cite it. **The clause that had been holding was forbidding a pair that cannot collide** — one row rewrites that file, the other only mentions it while creating a new module — which is the failure `D15` opens by describing, finally measured on a real batch rather than argued.

**Disposition:** the subsection stays, because the two entries above are the record of how the rule failed and a later reader needs them. It is closed to new entries under the citation reading, which no longer exists. A future deviation from items 1–5 is logged here as #3 in the ordinary way; it simply will not be for this reason.

##### #3 — `M124` (2026-10-02) · one row editing three hub files, then the other row one citation in a fourth, allowed by the user in chat

**What was not satisfied:** item 1's second clause as `M117` read it — *two hub files in one row's remedy is what the clause refuses without a stated breach*. `R-9f296f7e` edits three: `app/Services/Expressions/ExpressionEvaluator.php` (the equality rule), `resources/js/components/submissions/FieldInput.vue` (a Yes/No control that can show a boolean) and `docs/architecture/technical-architecture.md` (the note that document requires whenever a golden corpus grows). At claim time the clause's literal text was satisfied — `R-8c517fb6`, the batch's other row, edited no hub. ⛔ **Mid-build that stopped being true:** the paginated step model moved the line `docs/offline-first-sync-design.md` (a hub) cites as `useFormRuntime.ts:296` onto a blank, and tier 1 of the citation lint tolerates no rot — so `R-8c517fb6` now edits that one citation, and the batch breaches the clause as WRITTEN too, two rows touching hubs. Clause 1 is satisfied exactly: the two rows' edited files, tests included, are disjoint.

**Why:** one mechanism spans two engines and the one control that displays the answer. Without the document note, both corpora grow under a sentence that calls them frozen, and that document calls a changed count with no note *drift*. Without `FieldInput.vue`, the fix ships a regression: canonical booleans reach the guest outbox, so an offline response reopened for conflict review would show its Yes/No answers blank — the same defect resume and edit already have, which this fixes too.

**Disposition:** **the user allowed all three in chat on 2026-10-02** — first the document note, then `FieldInput.vue`, then the citation repair (over restructuring the new code so the cited line stood still) — each put to them by name because an `early-testing` work row was blocked on the choice. The citation repair was its own pushed claim extension before the file was opened. Recorded in both places this log requires: inline at the closed rows, and in `PROGRESS.md`'s release paragraph. Not a precedent, and the first of its reason, so the escalation rule above is not engaged.

##### #4 — `M128` (2026-10-03) · three items, each editing hub files, allowed by the user in chat

**What was not satisfied:** item 1's second clause, *at most one row may touch a hub file*. `M128` takes three items, and each one edits hubs. `ocr-single-form`'s groundwork 1 edits `routes/tenant.php` and `docs/data-dictionary.md`. `R-33c7fd56` edits the width-derivation comment in `resources/js/Pages/forms/Builder.vue` and `docs/ux/design-system-reference.md`. `R-d001de0c` edits `resources/js/components/builder/ConfigPanel.vue` and `ConfigPanel.test.ts`. Clause 1 is satisfied exactly: the three items' edited files are pairwise disjoint.

**Why:** the Oct 12 deadline (`D72`). The plan the user approved puts all three in the first Oct 12 increment. Splitting them one hub row per increment would cost about half a day of close-out overhead that the schedule does not have, and files that do not overlap cannot collide.

**Disposition:** the user chose to keep all three, in chat on 2026-10-03, asked by name because the batch named in the prompt breached the clause. Each item is its own commit, so a red run bisects to one item, and if the OCR part runs long the other two move to `M129` rather than squeezing verification. Recorded in both places this log requires: inline at the closed rows and in `PROGRESS.md`'s release paragraph. Not a precedent, and the first of its reason, so the escalation rule above is not engaged.

##### #5 — `M129` (2026-10-03) · three items, each editing hub files, allowed by the user in chat

**What was not satisfied:** item 1's second clause, *at most one row may touch a hub file*. `M129` takes three items and each edits hubs. All three edit `routes/tenant.php` and `docs/data-dictionary.md`. A (`ocr-single-form`, groundwork 2) also edits `resources/js/Pages/submissions/Encode.vue`, `docs/piping-output-encoding-design.md`, `docs/security-threat-model.md` and one citation in `docs/ux/design-system-reference.md`. B (`R-1132a6f3` with `R-4e96a994`, plus A's accept-scans setting) also edits `resources/js/Pages/forms/Builder.vue`, `app/Services/Forms/FormService.php` and `BuilderPresenter.php`. C (`R-6dedc3a9` and `R-f0c5b682`) also edits `resources/js/components/builder/ConfigPanel.vue`, `app/Enums/AttachmentKind.php`, `config/attachments.php` and `docs/piping-output-encoding-design.md`. Clause 1 is satisfied exactly: the three non-hub sets are pairwise disjoint, which took two design choices — A serves scan pages through its own helper, so the attachment controller and policy stay C's, and B's new builder prop is optional, so the builder's test fixtures stay C's.

**Why:** the Oct 12 deadline (`D72`), as for #4. The plan the user approved puts all three in one day's slot, and the user named them together in the prompt.

**Disposition:** the user chose to keep all three, in chat on 2026-10-03, asked by name because the batch named in the prompt breached the clause. Each item is its own commit, and an item that runs long moves to `M130` rather than squeezing verification. Recorded in both places this log requires: inline at the closed rows and in `PROGRESS.md`'s release paragraph. ⚠️ **This is the second exception for the Oct 12 reason.** The escalation rule above is not engaged yet. A third for the same reason means the rule, not the batch, needs an answer: file a decision about the rule rather than writing #6.

##### There is no #6 — `M130` (2026-10-04) tripped the escalation, and `D75` is the answer

The third Oct 12 batch broke the second clause again, and the first as well: two of its rows edit the same six files. As #5 said it should, `M130` filed a decision about the rule instead of a sixth exception. **`D75` (user decision, in chat): until the Oct 12 session, a plan slot is one increment, and each row lands as its own commits in a stated order.** The log resumes at #6 for any deviation after that.

---

### D7 — Should `main` get branch protection, with the repository owner as a bypass actor? **Yes.**

**Filed 2026-08-28 by Lane A during `M38`; answered 2026-08-31 (user decision); applied by Lane A during
`M51`.** It was filed rather than decided because it changes settings on a public repository, which is
the class of change that stays with the user.

**As decided — option 1, exactly as recommended.** A ruleset on `main` requiring **all six** status
checks, with the **repository owner as the sole bypass actor**.

**What it retires, and it is a measured failure rather than a hypothetical.** Every merge in this series
is a self-merge on a green run, and the check that the run was really green was *the model parsing `gh`
output*. That failed once already: `I5` merged during a GitHub Actions outage with four of six jobs
never having acquired a runner, reporting `steps: []` — a **vacuous success**. Every hand-off since has
carried *"parse each job's step count individually"* as prose, and prose has to be remembered. Under the
ruleset a required check that never acquired a runner is **pending, not passed**, and nothing merges.
The trap disappears mechanically.

**Why the owner bypass is not a loophole grudgingly accepted but a requirement.** Rule 7(g) makes a
claim a **pushed commit** — `git push origin HEAD:main` *before* the first file is opened. Blanket
protection would turn that one-commit lock into a pull request round trip and destroy the property that
makes it a lock. The bypass is exactly as strong as the discipline about using it, and it is for claim
commits and close-outs only.

⛔ **THE SIX CONTEXTS WERE READ FROM A REAL RUN, NOT FROM THIS ENTRY.** `D7` itself records that the plan
proposing it said **five**, and a ruleset built on the wrong number leaves a gate non-blocking — which is
the precise failure it exists to prevent. Taken from run `33398663198`, the post-merge run on `main` for
`M50`: `Static analysis, style & security` · `Tests (Pest on PostgreSQL)` · `Frontend build & type-check`
· `Design system a11y (axe)` · `Contract tests (OpenAPI)` · `E2E (Playwright + axe)`.

⚠️ **AND THE APPLICATION WAS SEQUENCED SO THAT IT TESTS ITSELF.** The ruleset was created **after**
`M51`'s pull request merged and **before** its close-out, so this increment's own close-out — a direct
`git push origin HEAD:main` — is the live exercise of the owner bypass rather than an assumption about
it. A protection rule whose bypass has never been used is a protection rule that has never been tested.

---

### D6 — The corpus names a real third-party client and publishes an audit of its weaknesses, on a public repository. Redact? **Yes — the working tree. History is NOT rewritten.**

**Filed 2026-08-28 by Lane A during `M38`; answered 2026-08-31 (user decision); applied by Lane A during
`M51`.**

**As decided:** the client identification and the published audit of that legacy system's weaknesses are
removed from the tracked files. **Every architectural lesson is kept** — it is the naming plus the
vulnerability detail that goes.

⛔⛔ **THE EXPOSURE IS REDUCED, NOT CLOSED, AND THIS ENTRY SAYS SO IN TERMS.** **History is not
rewritten, and that is a deliberate limit rather than an oversight.** The repository is public and its
full history is readable — `M48`'s secret scan proved exactly that by reading hundreds of commits to
produce 818 findings. Every redacted string therefore remains in the commits that carried it and is
reachable by anyone who clones. What changed is what a reader, a search engine and a casual clone see.
**Whether to rewrite history is filed as its own decision, `D9`, unconditionally and recommended
against.** This row's original defect was a deadline that passed and let the default win by silence;
recording it as *"the material is gone"* would be that same defect pointing the other way.
⚠️ **Annotated 2026-09-17 during `M98`, after D48.** *"The repository is public"* now has a term: the user answered
that it stays public with fake data only through testing and goes private before any real data. Nothing here
changes for the past — whatever was cloned while it was public stays cloned, and the history stays readable to
anyone who took a copy — so this entry's stated limit holds; what changes is that new commits stop being public at
the flip.

**The count was re-derived and disagreed with all three prior figures — and the unit turned out to be
the finding.**

| Source | Figure |
|---|---|
| The original backlog row | 6 sites |
| `docs/backlog-triage.md`'s census | "11+" |
| This entry's own table, when the row moved here | 17 occurrences across 9 files |
| **Measured during `M51`** | **26 occurrences across 11 files — or 20 lines carrying at least one** |

⚠️ **`grep -c` counts LINES and `grep -o | wc -l` counts OCCURRENCES, and on this corpus they differ by
six.** None of the three earlier figures says which it is, so *"the count grew"* was partly drift and
partly a change of unit. Some of the growth is also this project writing about its own redaction: three
of the eleven files were the decision record, the backlog row and the claim ledger.

⛔ **AND A REDACTION SCOPED TO THE LITERAL STRINGS IS THE WRONG SCOPE — THREE SITES WERE INVISIBLE TO
IT.** The corpus identified its subject in at least four ways: the system name, the project name, the
client's name, and a national geography standard named by acronym. Only the first two are greppable as a
unit. `docs/backlog-triage.md` contained **neither name** and identified the client by description,
inside its own summary of this very decision; `docs/domain-glossary.md` and `docs/PRD.md` each carried
one more. **A name-scoped search reports those files as clean.**

⚠️ **AND ONE FALSE-POSITIVE CLASS WOULD HAVE MADE A BLIND SUBSTITUTION DESTRUCTIVE.** `PROGRESS_ARCHIVE.md`
matched an acronym search **55 times** and **not one was the client** — every occurrence is the
developer's own Windows username inside a plan-file path. A substitution run on the obvious pattern
would have corrupted 55 paths and redacted nothing. The occurrences were read before they were replaced.

**Where the line was drawn between "lesson" and "audit", stated so the diff can be judged against it:**

- **Kept, in full:** every decision's rationale. `ADR-0001`'s MySQL-shaped gaps, `ADR-0002`'s absent
  tenant concept, and the **`id`-based super-admin convention repeated across several code layers** that
  `ADR-0002` §D3 exists to avoid. A decision whose provenance is deleted is a decision nobody can check,
  and this entry's own option 1 argued that the lesson is exactly as strong without a name attached.
- **Removed:** the exploitation mechanic spelled out beside that convention, and `ADR-0003`'s itemised
  inventory of the legacy system's repository and CI posture. Both read as a security and operations
  report on somebody else rather than as a reason for a choice here.
- **Checked and deliberately kept:** `app/Models/ScopeNode.php`, its migration and
  `docs/multi-tenancy-rbac-design.md` illustrate the **scope-tree feature** with three unrelated
  examples. They describe a customer's data, not the legacy client.

⚠️ **THE ANSWER IS BROADER THAN THIS ENTRY'S OWN RECOMMENDATION, AND THE ENTRY SAYS SO RATHER THAN
RETRO-FITTING ONE.** Option 1 as filed kept *"every technical lesson intact"* and stripped only naming.
The decision also removed the vulnerability detail. The boundary between the two is a judgement call; it
was taken toward keeping each decision's rationale, and it is recoverable in the removed-too-much
direction precisely because history was left alone.

---


### D5 — What bar ends the M-series? **Zero open `major` rows, plus three consecutive increments filing no new `major`.**

**Filed 2026-08-28 by Lane A during `M36`; answered 2026-08-28 (user decision); recorded by Lane A
during `M38`.** The series ran M1 → M37 with no exit criterion at all, so the honest description of
the plan was *"until the backlog is empty"* — and the backlog does not drain monotonically. M29 closed
one row and filed six; M36 closed one and filed three; M37 closed none.

**As decided:** the series ends when **no `major` remains open** *and* **three consecutive increments
have gone by without a new `major` being filed.** The second clause is what makes it an exit rather
than a moment: the first clause alone is satisfiable at any instant by an increment that has not yet
been verified, and this project's own record is that a row's verification is where the next row comes
from.

⚠️ **THIS IS NOT THE RECOMMENDATION THAT WAS FILED, AND THE ENTRY SAYS SO RATHER THAN RETRO-FITTING
ONE.** D5 recommended **option 2** — a *category* bar, ending on correctness and security and moving
style/docs/ergonomics rows to a standing backlog. The answer is closer to **option 1**, a severity
bar, with a stability clause option 1 did not have. The difference is real and worth keeping visible:
a category bar would have ended the series with `major` documentation-parity rows still open, and
**eight of the twelve open majors are exactly that** — documentation asserting things the code does
not do. The answer keeps them in scope.

⛔ **AND THE BAR IS NOT MEASURABLE TODAY. THIS IS MEASURED, NOT ESTIMATED.**

| | |
|---|---|
| Rows carrying the `major` marker | **12** |
| Of those, actually open *defects* | **11** — the twelfth is the disclosure row, which became `D6` in this same increment and is a decision, not a defect |
| Of the twelve, naming the increment that filed them | **1** (the `M32` fan-out row) |

⚠️ **THE FIRST CLAUSE IS NOT EVEN CLEANLY COUNTABLE, AND `M38` ITSELF IS THE PROOF.** A moved row keeps
its original bullet — that is `D1`'s established convention, so its reasoning survives for the reader —
which means `grep -c` still returns **12** the moment after one of them stopped being a defect. The
honest number is **11**, and nothing mechanical can tell them apart today.

**And the second clause cannot be evaluated at all.** *"Three consecutive increments with no new
`major`"* requires knowing which increment filed each `major` — and **eleven of the twelve do not record
it**. Provenance across the file appears in at least **15 distinct free-text shapes** (`Filed <date> by
<M>`, `Filed <date> from <J>`, `Found by <M>`, `Filed by **<M>**`, `(found by P3a, filed by K1c)`, and
more), so there is no single form to parse.

**What must land before this bar can be evaluated:** provenance normalised to one parseable form
across `docs/feature-backlog.md`, with a lint gate holding it there. Until then the exit condition is
recorded but not operable, and **saying so is the point** — a bar that cannot be measured is a bar
that will be declared met by whoever wants to stop.

⚠️ **THE SERIES DOES NOT STALL ON THIS AND NEVER DID.** Standing Rule 5 is unchanged: the next row is
taken under Rule 7(f) and built. This answers *when to stop*, not *whether to continue*.

---

### D2 — May an axe violation be retryable at all? **No. A flaky e2e result now fails CI.**

**Asked and answered 2026-08-26 (user decision), by Lane A while taking the share-panel row.** The
backlog row at `:1461` delegated this explicitly — *"whoever fixes (1) should also decide whether an
axe violation may be retryable at all"* — so it is recorded here rather than left in a PR body.

**Why it needed deciding.** `playwright.config.ts` sets `retries: process.env.CI ? 1 : 0`. That is
what turned a **deterministic** WCAG AA failure into a line that reads as noise: the same test, the
same rule and the same element (`builder-axe.spec.ts:198` › *share panel, live link*,
`color-contrast` on `footer > .mds-button--primary`) failed first-attempt and passed on retry in run
`32250476088` (2026-08-19) and again in run `32711202891` (2026-08-24) — five days apart, on two
unrelated diffs, neither of which touched a `.vue` or a design-system file. Both merged green. The
passed count drops by one while the total is unchanged, which reads exactly like a test having been
silently dropped.

**As decided:** keep `retries: 1` — a retry still rescues a genuine infrastructure hiccup and is what
produces the `trace: 'on-first-retry'` artefact — but add **`failOnFlakyTests: !!process.env.CI`**
(Playwright 1.61; the flag's own documentation uses that exact expression). A result that needed a
retry is now red. Rejected: dropping retries to 0, which would lose the trace on the first real
infrastructure flake and give nothing back.

⚠️ **THIS TIGHTENS MERGES FOR BOTH LANES, WHICH IS WHY IT IS HERE AND NOT ONLY IN A PR.** Any test
that passes only on a second attempt now blocks a merge, Lane B's included. The last two merge runs
read `551 passed + 10 skipped` with no flaky line at all. **If a flaky test does appear, it is a real
defect that was previously invisible; fix it, do not re-run it.**

⚠️ **AND THE FIRST DRAFT OF THIS ENTRY SAID "the cost is believed to be zero — the only flake on
record is the one the same PR fixes", WHICH WAS WRONG AND IS CORRECTED HERE RATHER THAN QUIETLY.**
`PROGRESS.md:1436` records a **second** flake in the same spec file — *"Builder — empty canvas
(dark)"* at what was then `builder-axe.spec.ts:170` — and `support/axe.ts` describes this file's
"standing reputation for contrast flakes at mobile+dark" as a known and until-then unmeasured
artifact. **There is therefore a real chance this flag reddens a run that would previously have gone
green, and that is the flag working rather than failing.** The scan-timing fix shipping beside it is
the most likely cure for that one too — both are mid-transition sampling — but "likely" is not
"measured", and the honest position is that the first such red run is information, not an obstacle.

⛔ **WHAT TO DO IF IT FIRES ON LANE B'S ROW:** it is almost certainly not Lane B's change. Read the
failure before touching anything, and check `support/axe.ts`'s incident notes first.

✅ **THE FLAG IS PROVED NOT BLIND, WITH A CONTROL — because the merge run could not prove it.** PR
#206's E2E job read `551 passed + 10 skipped` with **no flaky line at all**, which means
`failOnFlakyTests` was never exercised: a green run is exactly as green with the flag as without it,
so "CI passed" is no evidence the flag does anything. That is this project's standing *"a gate nobody
can tell is blind is a gate nobody is running"* shape, and Pint's probe is the precedent. So a
throwaway spec that fails on attempt 0 and passes on attempt 1 was run against **the real
`playwright.config.ts`**, imported rather than copied:

| | reported | exit |
|---|---|---|
| `CI=1` (flag active) | `1 flaky` | **1 — RED** |
| no `CI` (control, `--retries=1`) | `1 flaky` | **0 — GREEN** |

Same test, same retry, **the same `1 flaky` line in both** — and the flag alone is the difference
between red and the laundering this decision exists to stop. ⚠️ **Note what the control demonstrates
second: `1 flaky` printed above a zero exit code is what every one of those green merge runs actually
looked like.** The reporter was never hiding anything; nobody was reading the line.

⛔ **Ordering is load-bearing:** the flag lands in the *same* PR as the scan-timing fix and *after*
it, so CI is never red on the way through.
