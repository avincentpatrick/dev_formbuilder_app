# Decisions queue

**This file exists so that no lane ever idles on a question.** Standing Rule 5 already says a
design decision the user has not made is not automatically a blocker — propose one, recommend,
and proceed. This is where that becomes mechanical for the cases that genuinely *are* the user's.

**How a lane uses it.** On reaching a real product call: append the question, the two or three
**real** options, and **your own recommendation** — then take the next row **in the same turn**.
Never wait. The user answers in batches.

**What does NOT belong here.** A residual you simply chose not to fix goes in
`docs/feature-backlog.md`, filed **the moment you decide not to fix it** — not here, and not in
`PROGRESS.md` prose only, which is how four live defects stayed invisible from J4b1 until J6.

**Decisions already taken are decisions of record — do not re-ask them:** drop `sortable` on the
two server-paginated tables (2026-08-18) · fail **open** on an unseeded plan catalog (2026-08-18) ·
password policy min-12 + HIBP + classes (2026-08-09) · Google-only social login (2026-08-09) ·
gamification last (2026-08-09) · the held list stays held until the user signals, and they said
*"not yet, ask again later"* on 2026-08-18 · **a flaky e2e result fails CI** (2026-08-26, D2 below) · **the M-series ends at zero open
`major` rows plus three consecutive increments filing none** (2026-08-28, D5 below) · **the batch series ends and the tiered pipeline succeeds it** (2026-09-14, D12 below) · **the testing server is invitation-only** (2026-09-14, D31 below) · **the guest per-address limits are raised on the testing server only** (2026-09-14, D32 below) · **the Windows Server 2016 testing site runs PostgreSQL 15** (2026-09-14, D43 below) · **the testing site is one workspace at the root of `staging.pitahc.gov.ph`, served by Apache** (2026-09-15, D46 below) · **automatic deploys to the testing server are on** (2026-09-17, D47 below) · **the repository stays public with fake data only through testing, and goes private before any real data** (2026-09-17, D48 below) · **inbound TCP 443 is open and `mod_md` renews the testing site's certificate over `tls-alpn-01`, with a scheduled task to activate it** (2026-09-18, D49 below) · **the API documentation marks its six unbuilt promises as not built, in place, rather than deleting them** (2026-09-20, D38 below) · **operators create every workspace, and `tenants:create` stays the only path** (2026-09-20, D44 below) · **the central-host sign-in loop is retiered `before-launch`** (2026-09-20, D51 below) · **the central-host welcome-email copy stays `early-testing`** (2026-09-20, D52 below) · **the deploy that deletes the previous build's chunks is retiered `early-testing`** (2026-09-20, D53 below) · **the offline panel's quota line says what it counts — *"across all sessions on this device"*** (2026-09-20, D26 below) · **the resume shell is cached under a token-free key, closing the enumeration primitive** (2026-09-20, D20 below) · **`MdsSegmentedControl` is left alone and its four stretch-clamped hosts guard themselves** (2026-09-20, D28 below) · **a workspace admin may invite any address** (2026-09-20, D33 below) · **a new account confirms its email address before that address counts as its own** (2026-09-20, D34 below) · **single-page mode becomes an author setting, defaulting to step by step** (2026-09-20, D35 below) · **corrections save on the button, and the documents say they are not resumable** (2026-09-20, D36 below) · **a lost second factor is cleared by an audited admin reset** (2026-09-20, D37 below) · **the testing site sends `Strict-Transport-Security: max-age=300` from the vhost** (2026-09-20, D54 below).

---

## OPEN

### D55 — `D31`'s invitation-only stance is enforced by one runtime toggle. Should it also be lockable from configuration? **Tier: during-testing.**

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

---

### D50 — Should DICT be asked to publish DKIM and DMARC records for `pitahc.gov.ph`? **Tier: before-launch.**

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

### D39 — What will the product be called? "Meridian" is a working codename. **Tier: before-launch.**

**Filed 2026-09-14 by `M93`, from Decision Board card `product-name` and `docs/PRD.md` §9.4.** The name appears
across the app, its mail and its documents, and testers will see "Meridian" until this is settled.

- **A — keep Meridian as the real name**, after checking it is free to use for this kind of product.
- **B — choose a name before launch**, then rename everywhere in one pass.

**No recommendation** — a product's name is its owner's call.

---

### D40 — Who is the first pilot customer? **Tier: before-launch.**

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

---

### D41 — Should collecting data by text message, voice call or USSD ever be planned? **Tier: before-launch.**

**Filed 2026-09-14 by `M93`, from Decision Board card `sms-channels` and `docs/PRD.md` §9.4.** A firm non-goal
today, worth revisiting only for a customer working in very low-connectivity areas.

- **A — keep it a non-goal**, and revisit if a customer asks.
- **B — plan it for after launch.**

**Recommendation: A** — no customer has asked, and it would be a large separate product.

---

### D42 — Should a native mobile app ever be built, instead of the installable web app? **Tier: before-launch.**

**Filed 2026-09-14 by `M93`, from Decision Board card `native-app` and `docs/PRD.md` §9.4.** Deferred
indefinitely, and needed only for something the web app cannot do, such as background location.

- **A — keep the web app only**, and revisit if a customer needs what it cannot deliver.
- **B — plan a native app for after launch.**

**Recommendation: A** — the installable web app already works offline, and no customer has asked for more.

---

### D45 — Should `deploy.ps1` get a committed Windows CI job, so its proof runs on every change? **Tier: before-launch.**

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

---

### D27 — Should a form republishing mid-request refuse the write under the lock, on the save door, on both doors, or on neither? **Tier: during-testing.**

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

---

### D25 — `P2c`, the deferral-phrase arm, measures 5% precision and ~2% recall. Keep it, drop it, or re-aim it as a staleness lint? **Tier: after-launch.**

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

### D24 — The coverage rules pin a residue of 134 undischarged obligation sites. Schedule the sweep, or leave the residue pinned indefinitely? **Tier: before-launch.**

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


### D23 — `scripts/loop.php` refuses held work by a hand-written keyword list, and there is now a gate proving the pipeline holds every held row. Keep the list, derive it, or cross-check it? **Tier: after-launch.**

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

---

### D22 — The pipeline generator's own discovery floor is 40 against a live scan of 869. Ratchet it, leave it, or let the gate carry the only binding floor? **Tier: after-launch.**

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

### D21 — `docs/pipeline.md` is merge-gated but sits in no `paths-ignore`, so every close-out now triggers a full CI run. Accept the cost, exempt it, or split the file? **Tier: after-launch.**

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

---

### D19 — A Reviewer holds `submissions.create` and can encode on no form. `M77` made every document say so. Should the ROLE now gain encoding, or is documenting the gap the whole answer? **Tier: during-testing.**

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

---

### D18 — The proof-of-work solver yields every 5000 candidates against a 120000 search space, and nothing has ever decided that number. Keep 5000, derive it, or make it configurable? **Tier: after-launch.**

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

---

### D17 — A local container Pest run silently omits 40 test files. `M76` made that loud, which makes every local run RED. Keep it, soften it, or change how the suite is run? **Tier: after-launch.**

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

---
### D15 — `D13`'s one-hub-row cap is now the binding constraint on batch composition, and it is stricter than its own purpose. Keep it, relax it to per-file, or re-derive the hub set per batch? **Tier: after-launch.**

**Filed 2026-09-05 by Lane A, during `M72`, at the moment the cap decided a batch that value had not.**
Recorded here rather than as a row because `D13` is a user decision and an increment does not re-scope
one of those on its own judgement.

⚠️ **`M93` (2026-09-14): no generator enforces the cap any more.** `render_batch()` and `BATCH_MAX` are deleted, so the one-hub-row cap lives only in `D13`'s text and in whoever groups a tier's rows. The question stands; what it governs is narrower.

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

### D16 — The `npm audit` judge makes a required status check green when the registry is unreachable. Accept it, isolate it, or keep the hard block? **Tier: after-launch.**

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

---

### D14 — The compliance spec promised audit events for deleting and restoring a submission, and there is no delete or restore surface at all. Build it, or record it as not built? **Tier: during-testing.**

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

### D11 — Two byte-serving routes gate on a subject their own comments question. Leave them, or move one? **Tier: during-testing.**

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

### D10 — `§9` item 9's escalation has fired. Adopt the value-object forcing device, or keep answering per surface? **Tier: after-launch.**

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

### D1 — Should the sixteen synchronous dispatch listeners become `ShouldQueue`? **Tier: after-launch.**

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

---

### D3 — ADR-0020 §D7 approves *"4th of 12"* for every member. Three other surfaces withhold the twelve. Which moves? **Tier: during-testing.**

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

### D4 — An archived webhook envelope has no form to be scoped to. Which permission reads it? **Tier: during-testing.**

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

---

### D8 — A tracker surgery triggers no post-merge run at all. Which way should `ci.yml` regain the trunk observation? **Tier: after-launch.**

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

---

### D9 — Should the legacy client's identity be rewritten out of git history as well? **RECOMMENDED AGAINST.** **Tier: after-launch.**

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

---

### D29 — `M90` made `saveAsTemplate()` the FIRST request-path isolation-level change in this codebase. Should that become the pattern for the other multi-statement snapshot reads, stay a one-off, or be replaced by a different instrument? **Tier: during-testing.**

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

---

### D30 — Which version should the builder's request-layer uniqueness rules be scoped to, now that both available answers are wrong inside the race? **Tier: during-testing.**

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


### D56 — A workspace Owner's two-factor reset clears the member's second factor in EVERY workspace they belong to. Should the Owner surface be narrowed? **Tier: during-testing.**

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


## ANSWERED
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
| …of those, inside an `overflow-y: auto` box | **4 of 4** — `.config` for the first, `.mds-modal__body` for the other three |
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
