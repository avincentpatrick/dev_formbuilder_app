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
