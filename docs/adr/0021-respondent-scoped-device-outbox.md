# ADR-0021 — The guest runtime's device-local outbox belongs to a *visit*, not to the device

- **Status:** Accepted
- **Date:** 2026-08-26
- **Increment:** M15
- **Supersedes / amends:** nothing. It decides a question no document had ever asked.
- **Related:** `docs/offline-first-sync-design.md` §3, §7 · `docs/ux/form-filling-ux-flow.md` §7.1–§7.3 ·
  `docs/security-threat-model.md` · `docs/feature-backlog.md:68` (kiosk mode, unbuilt)

---

## Context

`resources/public-runtime/App.vue` mounts `<SyncStatus />` inside `.app-shell__banner`, **above the
phase machine and outside every phase panel**, on `/f/{slug}` — a page with **no authentication of
any kind**. It renders `SubmissionOutbox`, whose rows come from `lib/outbox.ts`'s
`listSubmissions()`, which is **cross-form by design**.

`lib/outbox.ts` has named the threat model in its own words since Increment I10d:

> This runs on enumerator tablets and **shared kiosk hardware**, so the moment the server has the
> data there is no reason for a copy of someone's answers to stay on the device.

That reasoning was applied to data **at rest** — I10d's scrub empties `answers` and deletes queued
media in the same transaction as the status write. In the same increment, and without anyone
noticing the tension, the surviving **metadata** became far more visible:

- the surface moved from inside the fill session to **app level**, so it survives the confirmation,
  error, unavailable and loading phases;
- every row gained a **Discard** button (`canDiscard` on `needs_attention`) and a **Retry now**;
- delivered rows became **retained receipts**. Before I10d `markSynced()` *deleted* the row, so a
  server reference never persisted on the device at all; it now keeps up to twenty for twenty-four
  hours.

The question nobody had asked is **who is looking at the screen**. On shared hardware the next
respondent saw the previous one's queue tags, server references and per-row status sentences, could
**permanently delete** a stranger's unsent response and its queued media, and — through the
conflict-Review path — could open a parked row whose `answers` are **not** scrubbed
(`useSyncOutbox.conflictRow()` bypasses `listSubmissions`' scrub and `App.vue` seeds a fill session
with `row.answers`), rendering the previous respondent's answers **field by field**. A screen reader
would additionally announce their server reference from the `aria-live` region.

**There was no decision to overturn.** No ADR and no line of `docs/` had ever scoped the
device-local outbox to a respondent. `docs/offline-first-sync-design.md` records the *opposite* as
an assumption — *"the dominant single-device-per-respondent case"* — and the real answer sat parked
as an unbuilt `nice`/Phase-3 backlog row (*"Kiosk mode … lock to one form, auto-reset, clear PII on
timeout"*).

### The constraint that makes this non-trivial

An offline kiosk respondent whose submission is queued has a **legitimate need to know it is
queued**. `docs/ux/form-filling-ux-flow.md` commits that a failed submission *"remains visible and
retryable indefinitely"* and that one is *"never silently dropped"*. Deleting the surface, or
silently dropping rows, would trade one real defect for a worse one.

---

## Decision

**A respondent session — a *visit* — is one visit to one browser tab, identified by a UUIDv7 held in
`sessionStorage` and rotated on an explicit restart and after an idle timeout.** Finalized outbox
rows carry it as `respondent_session_id`.

| Read | Scope after M15 | Why |
|---|---|---|
| `listSubmissions` — the identified list | **this visit** | it is what a respondent reads |
| `listConflicts` → `nextConflict` / `conflictRow` — the review flow | **this visit** | it returns unscrubbed `answers` |
| `countsFor` — the badges and summary | **this visit** | they describe the list beneath them |
| `discardRow` — permanent delete of a row **and its media** | **this visit** | it destroys |
| the `aria-live` reference announcement | **this visit** | it speaks a server reference aloud |
| `earlierUnsent` — a bare count of everybody else's unsent rows | other visits | the only thing said about them |
| `counts` — device-wide totals | **device** | drives the boot drain and the storage-quota estimate |
| `listPending` / `retryAll` — the drain | **device** | see *Consequences* |

**Everything an earlier visit owns collapses to one sentence carrying a number and nothing else** —
no queue tag, no server reference, no form, no time, no per-row action.

### This is a more faithful reading of the UX spec, not a departure from it

`docs/ux/form-filling-ux-flow.md` §7.1 scopes its list to *"every submission **the respondent** has
finalized"* — definite article, singular. §7.3 specifies the **persistent app-level** surface as a
count plus a call to action (*"1 submission couldn't be sent. Tap to review."*), **not** the
identified list. The spec assumes one respondent per device throughout and never contemplates two;
showing the identified list to the current respondent and degrading everything older to §7.3's own
count shape is the reading that survives the case the spec did not consider.

### Nothing is ever deleted for privacy

A previous respondent's **unsent** row is hidden, not dropped, at any age, by any predicate. It
still drains in the background from whoever picks the device up next. Only a delivered **receipt**
belonging to an earlier visit is pruned, and only because it can never again be rendered (the list
is scoped) or counted (`earlierUnsent` counts unsent rows only) — retaining it would keep a server
reference on shared hardware in exchange for nothing.

---

## Alternatives considered

### A. One id per page load, held in memory (rejected — it breaks a merge-blocking gate)

Strictly stronger privacy and simpler: nothing is persisted, so nothing can outlive the visit. It
was the first design and it is **wrong**, on evidence rather than on taste.

`tests/e2e/public-runtime-offline.spec.ts`'s second test queues a row, flips it to `conflict`
through raw IndexedDB, **reloads**, and only then asserts the review CTA, the badge, the absent
Retry and the whole resolve flow. A per-load id makes that row a stranger's the moment the page
reloads: `conflictHere` falls to zero, the CTA never renders, and four assertions fail — on a suite
that **cannot run on the development host** and is therefore discovered at merge.

The gate is right and the design was wrong: a respondent who refreshes the page has not become a
different person.

### B. `sessionStorage` rotated **only** on "Submit another response" (rejected — insufficient alone)

It is the deliberate hand-over and it is kept. It cannot be the whole answer, because it depends on
the previous respondent **doing something correct before they walk away**, and walking away without
pressing anything is the dominant kiosk path rather than an edge case.

### C. A device-owner gate / kiosk unlock (deferred — it is a different feature)

The right long-term answer for genuinely operator-run hardware, and it already exists as a backlog
row (*"Kiosk mode — lock to one form, auto-reset, clear PII on timeout"*). It needs an operator
concept the guest runtime does not have, and it would leave the defect live until it was built.

### D. Reference-only rows with no Discard (rejected — it does not contain the disclosure)

Removing the destructive action leaves the queue tag, the server reference, the per-row status
sentences, the cross-form count and the Review path — i.e. it addresses the part of the row that was
newest and not the part that identifies anybody.

### E. Redacted rows rather than a count (rejected, but it was close)

`SubmissionOutbox` already has a precedent for "visible but not actionable": a **foreign-form** row
renders with an *"Open this form"* link instead of a Review button. The precedent is deliberately
**not** followed, because a foreign-*form* row is still *your* row while a foreign-*visit* row is
someone else's — and §7.3's own shape for this surface is a count.

---

## Consequences

### The drain stays device-wide, and that is load-bearing

`lib/replay.ts` calls `listPending(db)` and `sw.ts` calls `replayOutbox(openDb(), fetch)` with **no
session, no slug and no hooks** — a service worker has no `sessionStorage`. A respondent-scoped
drain would strand every earlier row on the device **forever**, which is precisely the silent data
loss the offline architecture exists to prevent. `retryAll` is device-wide for the same reason plus
one more: retrying **sends** a stranger's submission, which discloses nothing, destroys nothing, and
is the outcome they wanted. **The asymmetry with `discardRow` is the decision** — showing and
destroying are the harms; sending is not.

### The session id is an argument, never an import

`lib/respondent-session.ts` reads `sessionStorage`. `sw.ts` imports `lib/db.ts` and `lib/replay.ts`
→ `lib/outbox.ts`, and `tsconfig.sw.json` re-checks that graph under
`lib: ["ESNext", "WebWorker"], types: []`, where `Storage` does not exist. One import would fail the
second type-check program. The id is therefore read at the composition root (`App.vue`), provided
through `RespondentSessionKey`, and passed into the data layer as a plain string — the same shape
`lib/device.ts` has always had, for the same reason.

### Null means "not this visit", and that is the safe direction

A row written before M15 has no session. Every reader treats null as an **earlier** visit, never as
a wildcard: an unknown owner reads as *somebody else*. The alternative would have left every
pre-existing row visible to everybody, which is the defect rather than a migration.

### The cost, stated rather than discovered later

On a **personal** device, closing the PWA and reopening it tomorrow ends the browsing session and
moves the respondent's own row into the count line. They keep *"One response … is still waiting to
send"*, `Sync now` and `Retry all`; they lose the per-row reference and the per-row Retry.
§7.2 promises the submission is not **lost**, which holds exactly — it promises nothing about
rendering. Two tabs on one device are likewise two visits.

### Ten minutes, and why the number is not a guess at how long a form takes

The stamp tracks **idle** time and is refreshed on every read, so an active fill never expires under
its own reader. The only exposed window is *after* finishing: long enough to survive reading a
confirmation screen, short enough that someone arriving at a shared desk almost always crosses it.

### No schema change

`respondent_session_id` is **un-indexed**, so there is no Dexie `version()` bump, no new store and
no new index — the precedent `conflict_code`, `server_reference`, `synced_at` and
`base_content_checksum` all set. `created_at` remains the indexed ordering and the visit predicate
runs in JS, applied **before** the page limit so a respondent's own older rows never hide behind a
stranger's newer ones.

---

## Revisit when

**Genuinely operator-run kiosk hardware is built** — i.e. when `docs/feature-backlog.md`'s kiosk-mode
row is taken. That row owns the operator concept, the "lock to one form" behaviour and a
configurable or shorter reset window, all of which supersede the idle heuristic here rather than
competing with it.

**Not** on the strength of a report that a respondent lost sight of their own row after reopening
the app: that is this decision working, and the count line plus the always-visible `Sync now` is the
answer it was designed to give.

---

## ⚠️ AMENDED IN INCREMENT M21 (2026-08-26) — THE SAME DECISION, ONE CHANNEL EARLIER, ON THE TIER THAT CARRIES ANSWERS

**This ADR did not mention drafts. Not once.** `grep -ic draft` over this file returned **0** before this
amendment. Everything above reasons about `outbox` — **finalized** rows, whose answers I10d already scrubs
at rest — and the phrase it kept using for the threat was *"the previous respondent's queue tags, server
references and Discard buttons"*, i.e. **metadata**. One table over, `draft_answers` held the answers a
respondent was **still typing**, keyed `[form_version_id + local_draft_id]` with no visit in it, and
`useAutosave.restore()` handed them to whoever loaded the page next.

⛔ **AND THE OMISSION WAS NOT THAT NOBODY NOTICED — M15's OWN SWEEP FOUND IT AND FILED IT THE SAME DAY.**
It went into `docs/feature-backlog.md` and into the *Residual* clause of this decision's row in
`docs/security-threat-model.md`, and **into no ADR**, so a reader arriving at M15's contract to learn what
"a visit" means for device-local state could not discover that the larger of the two channels had been
considered and left open. **A deferral recorded only in a backlog row is invisible to the document that
defines the concept it defers.** That is the lesson worth keeping from this amendment, more than the fix.

### What M21 changes

`DraftRow` gains `respondent_session_id`, un-indexed, on the identical terms as `OutboxRow`'s — **no
`version()` bump**, the precedent §*No schema change* above already sets. `useAutosave.persist()` stamps
the visit; `useAutosave.restore()` and `App.vue`'s resume read both refuse a row the current visit did not
write, through **one shared predicate** (`draftBelongsToVisit`, in `lib/db.ts` beside the field) rather than
two copies of the rule — because two readers of one table disagreeing about ownership is exactly how the
sibling defect in `reconcile.ts` came to exist.

**Null still means "not this visit"** and `undefined` still means "do not scope"; §*Null means "not this
visit"* governs both channels now, and the two absences are deliberately different — `undefined` is the
CALLER having no visit concept, `null` is the ROW having none.

### The refusal is on the READ, and the row is deliberately NOT deleted

§*Nothing is ever deleted for privacy* holds here without amendment, and it is doing more work than it was
for the outbox. The primary key is **shared**, so the next respondent's first keystroke overwrites the
abandoned row within 800 ms: **containment and collection are the same mechanism.** Deleting a stranger's
work on a *read* would be the harm `lib/outbox.ts` names, and it would also be unnecessary.

⛔ **WHICH IS WHY THE VISIT IS NOT IN THE PRIMARY KEY, AND THE FIRST REASON IS MEASURED RATHER THAN
ARGUED.** `node_modules/dexie/dist/dexie.js:3832` throws `Upgrade('Not yet support for changing primary
key')` inside the upgrade transaction. A three-part key does not degrade — **the database fails to open on
every device that already has one**, taking the outbox and the queued media with it. The second reason is
the collection above: a per-visit key would leave abandoned answers unreachable on shared hardware, because
this table has two readers, one deleter keyed to the current session, and **no enumerator at all** — its
seven-day TTL is a branch inside `restore()`, not a sweeper.

⚠️ **Stated honestly, because it is a difference of rate rather than of kind: uncollectable rows already
exist here.** A republish moves `form_version_id`, so the pre-republish row is orphaned with nothing able to
reach it. That is a real pre-existing hole; it is filed in `docs/feature-backlog.md` and it is not this
decision's to close.

### The harm was never only on screen, and that is why `major` was an understatement

Three consequences followed a silent cross-respondent restore, none of them named by the row that filed it:

1. **The UI affirmatively vouched for the stranger's answers.** `restoreAnswers()` writes into the reactive
   map the autosave watcher observes, so the restore itself trips `schedule()` — the header pill reads
   *"Saving…"*, then *"Saved"*. There was no banner (the welcome-back banner needs a resume seed) and no
   announcement; the only moving pixel told the new respondent their work was being kept safe.
2. **"Save and finish later" made it durable and mailed it.** A restored draft carries a **fresh**
   `client_submission_uuid` and a **null** baseline, so the guest draft POST wrote the previous
   respondent's answers as a **new server draft** under this respondent's identity, and emailed them a
   30-day resume link to it.
3. **Media followed the answers.** `attachToSubmission()` re-pointed the previous respondent's queued blob
   onto this submission — its docblock had claimed *"still-unassigned"* since G8b while the `.modify()`
   never filtered on it. M21 narrows the write as well as closing the restore that fed it.

**And on the resume path the damage was worse than on plain entry, which is why its row is re-filed from
`minor` to `major`.** `App.vue` seeds the session with the **local** tier's answers but the **server's**
uuid and the **server's** baseline — deliberately, and correctly, for the case it was written for. With a
stranger's draft winning `reconcileDraft`'s newest-wins rule, that respondent's next save wrote a stranger's
answers over **their own** server record and **passed P3a's lost-update guard by construction**, because
the baseline genuinely was theirs. A submit then promoted that row.

### The kiosk-mode row was NOT this defect's precondition

The backlog row deferred itself on the ground that *"telling a personal device from a kiosk needs an
operator concept the guest runtime does not have… that row is this one's precondition."* §*C. A
device-owner gate / kiosk unlock* above had already answered that: it deferred kiosk mode **and said in
terms that doing so "would leave the defect live until it was built."** Kiosk mode adds operator *policy*;
the mechanism was already wired — the visit id is read at `App.vue`'s composition root, provided, and
injected **two lines above** the `createAutosave` call it never reached.

### The cost, on the same terms as §*The cost, stated rather than discovered later*

On a **personal** device, a respondent who closes the tab mid-fill and returns loses the draft where it
would previously have been restored — `sessionStorage` dies with the tab. On a form with save-and-resume
they have §5.2's emailed link, which is the channel `docs/ux/form-filling-ux-flow.md` reserves for exactly
that journey; on a form without it, the work is gone.

⚠️ **The UX spec does not settle this and it should not be quoted as though it does.** §5.1 opens
*"session/device-scoped"* and continues, in the same sentence, *"so that a reload of the same browser tab
**or the same device's browser** recovers the draft"* — both readings, one clause. What settles it is
§*This is a more faithful reading of the UX spec, not a departure from it* above: every sentence in §5.1 is
written about *the* respondent, definite article, singular, and the shared-device case is the one nobody
wrote down. The trade is **one silent cross-respondent disclosure of answer content, plus a server-side
overwrite and an emailed link, against one personal-device respondent losing an unfinished draft after
closing their tab.** That is not close.

### ⛔ A CORRECTION TO §*Ten minutes* ABOVE, WHICH WAS FALSE AS WRITTEN — AND M21 FIXES IT RATHER THAN FILING IT

*"The stamp tracks idle time and is refreshed on every read, so an active fill never expires under its own
reader"* — the first clause is true of the FUNCTION and the conclusion did not follow, because the runtime
read the visit id **exactly once per page load**, at `App.vue`'s composition root. Nothing re-read it while
a fill was in progress, so `lastSeen` recorded **boot time** and the window measured
elapsed-since-last-boot. The same overstatement was in `lib/respondent-session.ts`'s own docblock, which
described a `touch()` that did not exist in the module.

⚠️ **AND M15's OWN TEST PINNED THE CLAIM AND PASSED.** `__tests__/respondent-session.test.ts` reads the
session three windows apart, gets one id, and asserts *"refreshes the stamp on every read, so an active
visit never expires under its own reader"*. It is a correct test of a contract that **nothing exercised**.
A unit test proves what a function does when it is called; only a call-site sweep proves that it is called.
Sixth intention-read-as-a-measurement in this project, and the first caught by grepping the call sites of a
function whose own test was green.

**It was live before M21 and it DELETED DATA.** A reload more than `IDLE_MS` after boot — including
`App.vue`'s own `window.location.reload()` after a conflict discard — issued a fresh visit, and
`pruneSynced()` then bulk-deleted the respondent's own delivered receipts as a stranger's: the single
deletion §*Nothing is ever deleted for privacy* promised would only ever touch an earlier visit.

⛔ **AND IT IS WHY THIS COULD NOT BE DEFERRED. Scoping the draft to a visit makes a wrong visit boundary
into LOST WORK.** A respondent filling for twenty minutes on their own phone who refreshes would have been
issued a fresh id, and their own half-filled draft would have become foreign and unrestorable — M21 would
have turned a documented `minor` into silent data loss on a personal device, which is the exact harm this
whole area exists to prevent. **A containment change inherits every defect in the boundary it starts
trusting.** That is the general form, and it is the reason this amendment fixes rather than files.

**As built:** `touchRespondentSession()` refreshes `lastSeen` only when a valid, unexpired marker already
exists — it **never mints** (an absent marker stays absent) and **never revives** (an expired one stays
expired, so a keystroke from the next respondent cannot resurrect the previous visit). It is called from
respondent **input**, threaded into `useAutosave` as an `onActivity` callback rather than imported, and
hung off `schedule()` rather than `persist()` — because `persist()` also runs from the 30-second backstop,
so touching there would keep an abandoned tab's visit alive forever and defeat the rotation entirely. The
window is now idle time, which is what §*Ten minutes* always claimed.

### The backstop was a heartbeat, and that made an abandoned draft immortal

`persist()` wrote unconditionally and `setInterval(persist, 30_000)` fired regardless of activity, so an
open tab pushed `updated_at` forward every thirty seconds forever. **The seven-day TTL could therefore never
fire on the one case it exists for**, because the only thing that expires a draft is a timestamp that stops
moving; and `reconcile.ts`'s newest-wins compared a heartbeat against a real save, so a dead draft on a
kiosk beat a genuinely newer server draft written from another device, by construction. `persist()` now
skips the write when the content signature is unchanged, recorded only after the write commits.

---

## ⚠️ AMENDED IN INCREMENT M22 (2026-08-26) — CONTAINMENT WAS ONLY HALF OF IT; THE DEVICE ALSO NEEDS A COLLECTOR

- **Status:** Accepted
- **Increment:** M22 — `resources/public-runtime/lib/reap.ts`
- **Amends:** §*Nothing is ever deleted for privacy* (narrowed, and the narrowing is the decision) ·
  §*The refusal is on the READ, and the row is deliberately NOT deleted* (its closing concession is now closed)
- **Row:** `docs/feature-backlog.md` — *"The guest device has no enumerator and no reaper for abandoned
  answer content"* (`major`), filed **by M21, in the same commit that decided not to fix it**

### The two sentences this ADR wrote about its own remainder, and what they turned out to be worth

M21's amendment closed every **read** that could surface an abandoned draft, and then said this, twice —
once here and once in `lib/db.ts`:

> Stated honestly, because it is a difference of rate rather than of kind: uncollectable rows already exist
> here. A republish moves `form_version_id`, so the pre-republish row is orphaned with nothing able to reach
> it. That is a real pre-existing hole; it is filed in `docs/feature-backlog.md` and it is not this
> decision's to close.

It was right to file it and right not to take it mid-increment. **It was wrong about what it would cost.**
The filed row argued the fix needed "a mechanism this table has never had — an enumerating sweeper plus a
retention decision (how long, on what trigger, and whether it runs in the service worker …). That is its own
increment with its own ADR question." Two of those three turned out to be already answered in the tree, and
the third answered itself once the first was looked at properly:

- **"How long"** is not a new number. `DRAFT_TTL_MS` — seven days — has gated `useAutosave.restore()` since
  F6b and is `docs/ux/form-filling-ux-flow.md` §5.1's own inactivity expiry. The defect was never that the
  window was unspecified; it was that **nothing could apply it to a key no reader holds.** M22 imports the
  existing constant rather than minting a second one, which is why it moved from `useAutosave.ts` to
  `lib/db.ts` (a composable cannot be imported from `sw.ts`'s graph).
- **"On what trigger"** has a precedent in this same runtime: `pruneSynced()` is called from
  `useSyncOutbox.refresh()` **and** from `replay.ts`'s drain, so it runs at boot with a tab and on
  Background Sync without one. `reapAbandoned()` is called from both, in the same two places, for the same
  two reasons.
- **"Whether it runs in the service worker"** was the one that looked like a question and was a
  **constraint**. It runs there — and the reason it *can* is the design below.

⛔ **SO THIS AMENDMENT SPENDS NO NEW ADR NUMBER, AND THAT IS DELIBERATE FOR THE THIRD INCREMENT RUNNING.**
`0022` stays free and stays Lane A's block-opener. This is `ADR-0021`'s own threat model — shared kiosk
hardware, guest runtime, device-local stores — carried from *disclosure* to *retention*. Minting a number to
restate an accepted decision's premise would spend the scarcer namespace for nothing. Same reasoning as M18
and M21.

### Decision

**The guest device gets one sweeper, `lib/reap.ts`, and it tests REACHABILITY rather than ownership.**

A `draft_answers` row is deleted when its `updated_at` is older than `DRAFT_TTL_MS`. A `media_queue` row is
deleted when its `client_submission_uuid` is `null`, **no answer document on the device still carries its
`local:` ref**, and it is older than `MEDIA_ORPHAN_GRACE_MS`. Nothing else is touched — the sweeper reads
`outbox` and never writes it.

### Why ownership could not be the test, and why that is the better outcome

The obvious design is the one this ADR already uses everywhere else: reap what does not belong to the visit
on screen. **It is unavailable here, and not as a matter of taste.** `sw.ts` imports `lib/db.ts` and
`lib/replay.ts`; `tsconfig.sw.json` re-checks that entire graph under `lib: ["ESNext", "WebWorker"],
types: []`. A module reachable from there cannot import `lib/respondent-session.ts`, cannot read
`sessionStorage`, and therefore cannot know whose visit anything is. That is the same wall §*The session id
is an argument, never an import* describes, hit from the other side: there the id could be threaded in from
the composition root, because the caller had one. **A Background-Sync event has no composition root.**

Reachability turns out to be the stronger test anyway, on three counts.

1. **It gets the device-wide drain right, which ownership would have broken.** §*The drain stays
   device-wide* is load-bearing: an earlier visit's queued submission still sends from whoever picks the
   device up next, because sending a stranger's submission is what they wanted. Its media must therefore
   survive too — and an ownership test would have eaten exactly those blobs. The reachability test spares
   them without needing to know they are anyone's in particular.
2. **It cannot drift from the linker.** `attachToSubmission()` decides which blobs belong to a finalized
   submission by walking the answers with `collectLocalMediaIds()`. The sweeper decides which blobs are
   live by walking the answers with **the same function**. If instance-addressed media is ever allowed
   (`StructuralValidationGate` refuses it today), the linker breaks in the same commit as the sweeper,
   loudly, instead of the sweeper quietly eating live blobs on its own.
3. **It needs no clock for the part that matters.** An abandoned blob does not wait out a window; it dies
   with the draft that named it, and the next respondent's first keystroke replaces that draft on the
   shared primary key within 800 ms. §*The refusal is on the READ* argued that shared key was what made
   containment and collection the same mechanism. It still is. **The sweeper is the floor under that, not
   a replacement for it.**

### §*Nothing is ever deleted for privacy* is NARROWED, and this is the narrowing

That section says a previous respondent's **unsent** row is hidden, not dropped, at any age, by any
predicate. **It still says that, and M22 does not weaken it by one row.** `reapAbandoned()` does not delete
from `outbox` at all — it reads it, to learn what to spare. Nothing that could still be sent is touched, at
any age, by any predicate.

What the section did not distinguish, because until M22 nothing acted on the difference, is **submission
intent** from **the two tiers that hold no intent at all**:

- a `draft_answers` row is answers the respondent was still typing, under a **seven-day expiry the product
  has always promised**. Deleting an expired one is keeping that promise, not overriding this section.
- an orphaned `media_queue` blob is a file **no answer document names**. It cannot be uploaded (replay
  reaches media by uuid), cannot be rendered (a `local:` ref only paints if the answers are restored, which
  M21 scoped), and cannot be linked (`attachToSubmission` walks answers). It is not a respondent's pending
  work; it is a file with no remaining referent.

⚠️ **AND THE SECTION'S OWN JUSTIFICATION ALREADY REACHED THIS CONCLUSION WITHOUT BEING APPLIED HERE.** It
prunes an earlier visit's delivered receipt because "retaining it would keep a server reference on shared
hardware in exchange for nothing." An orphaned ID scan is that argument with the stakes raised by an order
of magnitude, and the answer cannot be different.

### The grace window is engineering, not policy — and the conflict-review session sets it

`MEDIA_ORPHAN_GRACE_MS` is one hour. It is **not** a retention window: retention here is "as long as
something references it." It covers only the interval in which a genuinely live `local:` ref exists in
memory and not yet in any row the sweeper can read.

The ordinary case needs 800 ms — `stash()` returns the ref, the answer map changes, autosave commits.
⛔ **The case that sets the number is the conflict-review session, which runs `createAutosave` with
`enabled: false` and therefore writes NO draft row at all** (Increment G8c, deliberately: a transient review
must not clobber a live fill on the shared key). A media pick made during a review is referenced by nothing
on disk until the resubmit, while `refresh()` can fire underneath it on `online` or `visibilitychange`. A
review takes minutes; an hour covers it with room, and still bounds kiosk exposure to an hour instead of
"until the browser evicts the origin."

It doubles as the concurrency guard, which is why no transaction spans the mark and the sweep: a pick that
lands between building the live set and running the delete is younger than the cutoff by construction.

⚠️ **THE RESIDUAL, STATED RATHER THAN LEFT TO BE DISCOVERED: a conflict review that runs longer than the
grace loses that pick's blob**, and the submission then parks as `needs_attention` with *"queued media is
incomplete"* rather than failing silently. Filed in `docs/feature-backlog.md` as a `minor`. The real fix is
to stop the review session being invisible to the mark set, not to lengthen the window.

### No schema change, which is the second thing the filed row was wrong about

§*No schema change* holds, and M22 adds no field, no store and no `db.version()`. Both indexes the sweeper
needs were declared in **v1**: `draft_answers` carries `updated_at`, so the TTL is an indexed range query
rather than a table scan, and `primaryKeys()` returns the compound keys without ever loading a respondent's
answer document.

⚠️ **`media_queue` is walked in full, and the index it declines to use is the point of the whole defect.**
`client_submission_uuid` **is** indexed — and is useless, because **IndexedDB does not index `null`**. An
orphan is absent from that index, so no argument to `where('client_submission_uuid')` can ever reach it;
that is precisely why `markSynced()` and `deleteRow()` have never collected one. `status` is indexed and
every orphan is `queued` today, but only as a consequence of a call graph (`markUploaded()` is reachable
only through `listForSubmission(db, uuid)`, which needs a non-null uuid) — keying the sweep on that would
make the reaper depend on a proof staying true. The walk gates on the field itself.

⚠️ **The range query is lexicographic on an ISO string, which is sound only because every write of
`updated_at` is provably `toISOString()`.** There are exactly two writers: `persist()` stamps
`new Date(now()).toISOString()`, and the one-time localStorage migration writes `migrated.savedAt` **only
after `fresh()` has accepted it** — and `fresh()` rejects anything `Date.parse` cannot read. Fixed-width UTC
ISO-8601 sorts lexicographically exactly as it sorts chronologically.

### The kiosk-mode row is where configurability lives, and that is a precedent rather than a deferral

§*Ten minutes, and why the number is not a guess* already settled this shape once: a shorter or
operator-configurable window belongs to the unbuilt kiosk-mode row (`docs/feature-backlog.md` — "lock to
one form, auto-reset, clear PII on timeout"), not to the module that needs a defensible default today. The
same holds for both constants here. **No question was filed to `docs/claims/decisions.md`, and that is a
finding rather than an omission:** the row predicted "its own ADR question", and once the sweeper tested
reachability instead of ownership there was no product call left to make — the window was already specified,
the trigger already had a precedent, and the service-worker half was a type-check constraint with one
answer.

### It is a retention fix and not a disclosure one, and it stays that way

M21 closed every read. Nothing in M22 re-opens one, and nothing here should be re-filed as a security row:
before this increment an orphan was already unreachable from every UI. What it was not was **gone**.

### Revisit when

- **instance-addressed media is allowed** — `collectLocalMediaIds()` gains a nested walk, and the linker and
  the sweeper must move together in that commit (they share the function precisely so they cannot not).
- **the conflict-review session gains durable state of its own** — the grace window's stated reason
  disappears with it and the number should shrink.
- **kiosk mode is built** — both constants become operator settings there, per §*Ten minutes*.
