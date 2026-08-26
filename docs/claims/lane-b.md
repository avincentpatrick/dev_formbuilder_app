# Lane B — active claim

**One writer: Lane B.** Lane A never edits this file, and Lane B never edits `lane-a.md`. That is
what makes a claim conflict structurally impossible rather than merely unlikely.

**The protocol is Standing Rule 7(g)**, not this header. In one line: **a claim is a *pushed*
commit** — write it here, `git push origin HEAD:main`, and only then open the first file. An
unpushed claim does not exist; M14 proved that by writing a perfect one that nobody could see.

**Before opening any shared or paired artefact**, `git fetch` and read both lane files.

Shared artefacts, which are claimed and never owned: `docs/**`, `openapi.json`, `phpunit.xml`,
`PROGRESS.md` (own block only), and the top-level `tests/e2e/*.spec.ts`.
Paired files — where a change obliges you to edit *both* halves in the same PR — are listed in
Standing Rule 7(b-bis). ⚠️ **The next Lane B row that touches `SyncStatus.vue` takes one**:
`packages/design-system/src/theme/__tests__/clipped-node-containment.test.ts` lists it in an
exact-equality `KNOWN_UNGUARDED` assertion, so the list shrinks in the *same* PR as the fix.

---

## Status: ACTIVE CLAIM — `M22`, the guest device's missing enumerator and reaper for abandoned answer content

**Taken 2026-08-26.** Branch `m22-guest-orphan-reaper`, cut from `origin/main` at `88ba1e8`, PR into
`main`. Row: the `major` in `docs/feature-backlog.md` — *"The guest device has no enumerator and no reaper
for abandoned answer content"* — filed by M21 the moment M21 decided not to fix it.

**⚠️ NUMBERED `M22`, AND THE REF WAS RE-READ TWICE.** The opening fetch and the fetch taken immediately
before this file was written both return `88ba1e8`, and `docs/claims/lane-a.md` reads **ACTIVE CLAIM
`M20`** on branch `m20-ds-merge-gate` in both. `M21` is merged and released (PR #211, `15dc10b`). So the
next free number is `M22`, not `M21` and not `M20`. M21 measured the opening fetch going stale in four
minutes; this claim was written from a read taken minutes before the push, not from the session-open read.

**No collision on the merits.** Lane A's `M20` is wholly inside
`packages/design-system/src/components/{Combobox,DataTable,PasswordStrength,Checklist}/`. This row is
wholly inside `resources/public-runtime/`, which Standing Rule 7(b) grants Lane B outright. The two
columns do not meet. The only overlap is `docs/feature-backlog.md`, by disjoint region — Lane A closes
rows under **Design system**, this closes one under the guest-runtime review — so it rebases as a merge.

---

### What was verified against the code BEFORE this claim was written

The row is M21's own, which is **no protection at all** — M15 wrote the row M21 took, and that row argued
the fix was impossible on two grounds and was wrong on both. So both halves were re-walked:

**(a) `media_queue` orphans — CONFIRMED.** `lib/media-queue.ts:40` `put`s a row and the caller supplies
`client_submission_uuid: null` for a mid-fill pick. The table's only two deleters are
`lib/outbox.ts:107` and `:159`, both `db.media_queue.where('client_submission_uuid').equals(uuid).delete()`
against a uuid **string**. A `null` value is not merely unequal to that string — **IndexedDB does not index
`null` at all**, so an orphan row is absent from the `client_submission_uuid` index and no `where()` on it
can ever reach the row, by any argument.

**(b) `draft_answers` pre-republish orphans — CONFIRMED.** Two readers (`App.vue:249`,
`useAutosave.ts:229`), two writers (`:153`, `:279`), one deleter (`:196`, `db.draft_answers.delete(pk)` for
the *current* pk only), and **no `where`/`orderBy`/`toArray`/`each`/`count` anywhere in the tree**. The
seven-day TTL is a branch inside `restore()` against the key it just fetched, so a row whose
`form_version_id` moved under a republish is never written, read or deleted again.

**⛔ ONE THING THE ROW DOES NOT SAY, AND IT CHANGES THE COST.** `draft_answers` is declared
`'[form_version_id+local_draft_id], form_version_id, updated_at'` (`lib/db.ts:195`) — **`updated_at` is
already a secondary index.** A TTL sweep over it is `where('updated_at').below(cutoff)`, not a table scan,
and it needs no `db.version()` bump. `media_queue` is `'attachment_local_id, client_submission_uuid,
status'` and has no time index, but `status` is indexed and every orphan is `queued`. So the enumerator
this table "has never had" costs no schema change on either store — which is a strictly better position
than the row assumed, and is why this is one increment rather than a migration.

**⚠️ A `db.version(3)` IS OFF THE TABLE AND THE REASON IS MEASURED.** `db.test.ts` asserts
`expect(db.verno).toBe(2)`, and `node_modules/dexie/dist/dexie.js:3832` throws
`Upgrade('Not yet support for changing primary key')` — the database fails to open on every device that
already has one. Un-indexed fields need no bump; a new **store** would. This increment adds neither.

---

### The hard half is a decision, and it is filed rather than guessed

*How long, on what trigger, and whether it runs in the service worker* is a genuine product call, so it
goes to `docs/claims/decisions.md` with a recommendation and this lane proceeds on that recommendation
rather than idling — Standing Rule 5. ⛔ **The service-worker half is not a preference, it is a
constraint**: `tsconfig.sw.json` re-checks `sw.ts`'s import graph with `types: []`, and that graph already
contains `lib/db.ts`, `lib/replay.ts`, `lib/outbox.ts` and `lib/media-queue.ts`. `sessionStorage` does not
exist there, so a visit cannot be read and `lib/respondent-session.ts` can never be imported into it.

**⚠️ THIS IS A RETENTION DEFECT, NOT A DISCLOSURE ONE.** M21 closed every read that could surface this
content: `listForSubmission` is by uuid, a `local:` ref only renders if the answers are restored, and
both `draft_answers` readers now refuse a foreign row through `draftBelongsToVisit`. It will not be
re-filed or re-argued as a security row.

---

### Shared and paired artefacts this claim touches

- `docs/feature-backlog.md` — closes one row under the guest-runtime review; Lane A's `M20` closes three
  under **Design system**. Disjoint regions, so **rebase before push and it is a merge, not a collision**.
- `docs/claims/decisions.md` — appends the retention decision as `D3`. `D1` (the sixteen `ShouldQueue`
  listeners) is not touched and is not this lane's to take.
- `PROGRESS.md` — Lane B's own status block and Lane B's own hand-off line only, per 7(d).
- ⛔ **`packages/design-system/src/theme/__tests__/clipped-node-containment.test.ts` is NOT touched.**
  It is Lane A's tree and lists `RuntimeShell.vue` and `SyncStatus.vue` in an exact-equality
  `KNOWN_UNGUARDED`. This increment adds and removes no `clip: rect(0 0 0 0)` in `resources/public-runtime`,
  so the list must not move — and that will be **proved by running the design-system chunk**, not assumed.
  `NotificationTypeParityTest` and `ShellAbilityParityTest` are unreachable without a new `NotificationType`
  or ability key; neither is minted here.

**Namespaces unspent so far:** migration block `2026_08_17_000111` (nothing server-side moves here),
ADR-0016 `§D35`, ADR `0022` — which **stays free and stays Lane A's block-opener**; if this increment
needs an ADR the reasoning belongs as a sub-decision of `ADR-0021`, whose subject is exactly the
respondent-scoped device stores this row lives in. `0010` stays reserved for H1d; `#16` stays free.

**Baseline this branch starts from (`origin/main` @ `88ba1e8`, after M21):** CI Pest **4544 / 19,280**
(2 pre-existing warnings) · Vitest **130 files / 2,236** (design-system 35/545 · **public-runtime 35/805**
· resources/js 60/886) · axe **42 / 299** · E2E **551 passed + 10 skipped, no flaky line** · PHPStan CI
`[OK]`, local **18 against the FILE LIST** · four host lint gates **97 · 113 · 31 · 113/121/0** ·
`openapi.json` byte-identical. ⚠️ **This row is again `.ts` under `resources/public-runtime/`, so expect
the public-runtime chunk to move and Pest not to** — the chunk is what gets re-measured, never the total.
---

## RELEASED — M21, the abandoned draft that is restored into the next respondent's form (merged as PR #211, `15dc10b`, 6/6)

**Taken 2026-08-26.** Branch `m21-draft-respondent-scope`, cut from `origin/main` at `336d295`, PR into
`main`. Row: `docs/feature-backlog.md:1099` — *"An abandoned local draft is restored into the NEXT
respondent's form, silently, with their answers on screen"* (`major`) — plus the `minor` immediately
below it, *"`reconcile.ts:43`'s local-wins note tells a respondent a stranger's answers are theirs"*,
which the row itself says is closed by closing the major.

**⚠️ NUMBERED `M21`, NOT `M20`, AND THE PROTOCOL WORKED FOR THE THIRD TIME IN THREE DAYS.** This session
opened on a `git fetch` at `336d295`, where **both** lane files read `NO ACTIVE CLAIM`, and reasoned — as
M18's claim did, correctly on what it could see — that the next free increment number was mine. It was
right when read. **Lane A pushed `claim(M20)` at 10:34:46 while this lane was still in read-only recon**
(`987c571`, then extensions `17da814` and `f3fc136`), so **M20 is Lane A's and this is M21.** Caught by
re-reading the ref immediately before writing this file rather than trusting the opening fetch, which is
the whole of 7(g). ⛔ **THE COST OF THE OPENING FETCH IS NOW MEASURED RATHER THAN ARGUED: it was stale
within four minutes.** Recon took forty; a claim written from a forty-minute-old read of `origin/main`
would have collided on the number and been discovered at push time.

**No collision on the merits.** Lane A's M20 is *"the three design-system merge-gate rows"*, wholly inside
`packages/design-system/src/components/{Combobox,DataTable,PasswordStrength,Checklist}/`. This row is
wholly inside `resources/public-runtime/`, which 7(b) grants Lane B outright. The two columns do not meet.

---

### What was verified against the code BEFORE this claim was written

**The defect is LIVE, and the walk is four statements long.** `RuntimeSession.vue:142-162`'s `onMounted`
returns early only for a resume seed (`:145-153`); on plain entry `props.initialAnswers` is `undefined`,
so `:155` calls `autosave.restore()`, `:157-159` push the result into `runtime.restoreAnswers()`, the
locale and the step. `useAutosave.ts:139` reads `db.draft_answers.get(pk)` where `pk` is
`[options.formVersionId, options.slug]` (`:73`) — **no respondent dimension** — and `fresh()` at `:128-134`
gates on the schema checksum and a seven-day TTL (`:14`) and on nothing else. Respondent A abandons;
respondent B loads the same share link on the same browser; **B's fields render A's answers with no
banner**, because `WelcomeBackBanner` is `v-if="resume && resume.greet !== false"` (`:481`) and there is
no resume seed on plain entry.

**⚠️ SIX OF THE ROW'S TEN CITATIONS HAVE DRIFTED, AND THE DRIFT IS UNIFORMLY *FORWARD* — the file grew
under them.** Every claim is TRUE; six of the line numbers are not, so a reader who trusts them lands in
the wrong function.

| The row says | Actually at | Verdict |
|---|---|---|
| `useAutosave.ts:14` — 7-day TTL | `:14` | **holds exactly** |
| `useAutosave.ts:136-155` — the `draft_answers` read | `:136-182`; the `get(pk)` is `:139` | holds; the range stops mid-function |
| `db.ts:127` — the compound key | `:130` declares `Table<DraftRow, [string, string]>`; `:139` is the index string | **off by 3 / 12** |
| `RuntimeSession.vue:149-156` — `autosave.restore()` | `:155`, inside `onMounted` at `:142-162` | holds; range straddles |
| `RuntimeSession.vue:262`, `:313`, `:344` — `clear()` on the submit paths | `:267`, `:322`, `:353` | **off by 5 / 9 / 9** |
| `RuntimeSession.vue:471` — the banner's `v-if` | `:481` | **off by 10** |
| `App.vue:230` — the respondent-blind resume read | `:249`; `:230` is `loadResume()`'s signature | **off by 19** |
| `reconcile.ts:43` — the local-wins note | `:43` | **holds exactly** |
| `WelcomeBackBanner.vue:47` — renders the note | `:47` | **holds exactly** |
| `useAutosave.ts:14` — the TTL as the only other gate | plus `fresh()` at `:128-134` | holds |

⛔ **AND THE ROW IS WRONG ABOUT THE ONE THING THAT DECIDES WHETHER IT CAN BE FIXED AT ALL — SO THE
CITATIONS HOLDING IS AGAIN NOT THE ROW HOLDING, WHICH IS NOW TEN-FOR-TEN.** The row defers itself in two
sentences: *"the draft has **no analogue** [to the outbox's degraded surface] — its legitimate feature *is*
cross-visit restore, so scoping it to a visit would delete the feature rather than contain it"*, and
*"telling a personal device from a kiosk needs an operator concept the guest runtime does not have, which
is exactly the unbuilt **Kiosk mode** row — **that row is this one's precondition**."* Both halves are
false, and each is falsified by a document that predates the row.

**(1) THE ANALOGUE EXISTS AND M15 BUILT IT.** `SyncStatus.vue:123-128` renders *"One response from an
earlier session on this device is still waiting to send."* — existence, a count, and no content. That is
precisely the shape this row says the draft channel lacks.

**(2) THE SPEC BOUNDS THE PROMISE EVEN WHERE IT IS LOOSE ABOUT THE MECHANISM.**
`docs/ux/form-filling-ux-flow.md:148` opens *"This save is **session/device-scoped**, not a durable
resume-later feature"* and closes *"deliberately positioned to respondents as 'your progress is saved while
you're filling this out,' **never as 'come back later'** — the latter promise is reserved for §5.2."*
Appendix A #8 (`:336`) repeats the first half as a decision of record. §5.2's emailed resume link is the
sanctioned come-back-later channel and is respondent-bound by a signed token.

⚠️ **AMENDED BEFORE THE FIRST FILE WAS OPENED — THIS CLAIM ORIGINALLY OVERSTATED IT, AND THE ADVERSARIAL
PASS ON ITS OWN EVIDENCE IS WHAT CAUGHT IT.** The first version of this paragraph read *"the spec already
says the draft is session-scoped, so scoping it implements the spec rather than narrowing it"* and leaned on
the words *"session/device-scoped"* as decisive. **The same sentence continues *"so that a reload of the
same browser tab **or the same device's browser** recovers the draft"*** — which authorises the broader
reading in the same clause. So `:148` is **not** the arbiter this claim first made it. What survives is the
narrower and sufficient point: the *promise* is bounded to the fill in progress, and the come-back-later
promise is §5.2's. **The honest justification is ADR-0021's own move** (`:78-85`): a more faithful reading
of a case the spec never considered — every sentence at `:148` is written about *the* respondent, definite
article, singular, and the shared-device case is the one nobody wrote down. ⛔ **Recorded rather than
quietly rewritten, because a claim that overstated its authority and was corrected by its own verification
pass is worth more to the next reader than one that was right the first time.**

**The kiosk-mode row is NOT this row's precondition, and that is refuted by the ADR rather than by the
spec.** `ADR-0021:119-123` considered the device-owner / kiosk-unlock gate and **deferred** it in terms:
*"It needs an operator concept the guest runtime does not have, **and it would leave the defect live until
it was built**."* Kiosk mode adds operator *policy*; the mechanism this row needs is already wired and one
argument short.

### Three defects the row does not mention, found by grepping the shape rather than the row

- **⛔ THE LEAK HAS A SECOND DOOR, AND A FIX THAT GUARDS ONLY DEXIE LEAVES IT OPEN.**
  `useAutosave.ts:157-181` migrates a pre-G8b **localStorage** draft — `meridian:draft:{formId}:{slug}`
  (`:19-21`), also respondent-blind — into Dexie *and returns it* on first restore, reached whenever the
  Dexie `get` returns `undefined` **or throws** (`:153`). Same disclosure, different store.
- **`major` · An abandoned fill's MEDIA is never deleted, on the hardware `outbox.ts` names as the
  threat.** `media_queue` rows are removed at exactly two call sites, `outbox.ts:107` and `:159`, both
  `where('client_submission_uuid').equals(uuid)`. A blob stashed mid-fill by `App.vue:154-160` carries
  `client_submission_uuid: null` until finalize (`media-queue.ts:45-50`), so an **abandoned** fill's photo
  or signature is orphaned with no uuid, no TTL and no prune — permanently. I10d's own principle is
  *"the moment the server has the data there is no reason for a copy of someone's answers to stay on the
  device"*; here the server never got it, and the copy stays anyway.
- **`minor` · `lib/respondent-session.ts`'s docblock states a mechanism the build does not have.**
  `:26-27` says *"`touch()` runs on every read, and the runtime reads this on every refresh of the sync
  surface, so a respondent filling a long form never crosses [IDLE_MS]."* `respondentSession()` has
  **exactly one** non-test call site — `App.vue:140`, at the composition root, once per page load — so the
  `lastSeen` stamp is never refreshed during a fill. The **conclusion** survives (within one page load the
  id is a constant and cannot rotate at all), but the stated reason is an intention read as a measurement,
  which this project has now recorded four times. The real consequence is one the docblock does not name:
  a respondent who **reloads** after ten idle minutes gets a fresh visit id and loses sight of their own
  outbox rows. Filed, not fixed here.

### The design, and the alternative it rejects

**Stamp `respondent_session_id` on `DraftRow`, un-indexed, and scope the RESTORE — never the write.**
Exactly M15's shape, deliberately, so the runtime keeps one convention rather than two: `sessionId` is an
optional **argument** and never an import (`outbox.ts:31-38` — `lib/db.ts` is in `sw.ts`'s import graph and
`tsconfig.sw.json` re-checks it with no `Storage` type); `undefined` means *do not scope*, preserving every
pre-M21 call shape; and **`null` on a row means an EARLIER visit, never a wildcard** (`db.ts:63-66`).

**No Dexie `version()` bump**, and the document already states the rule rather than my inferring it:
`docs/offline-first-sync-design.md:124` — *"a new STORE requires a version bump, an un-indexed field on an
existing row shape does not"* — the precedent `conflict_code`, `server_reference` and M15's own
`respondent_session_id` all set.

**A draft this visit did not write is never applied silently. It is offered, content-free, or it is not
offered at all.** A matching session id restores exactly as today — which is what keeps
`form-filling-ux-flow.md:148`'s *"a reload of the same browser tab"* working, and is the case
`respondent-session.ts:10-16` chose `sessionStorage` for. A non-matching or null id renders a notice that
names **existence and a date and nothing else**, with an explicit choice.

**⚠️ THE REJECTED ALTERNATIVE IS THE ONE THE ROW ASSUMES, AND IT IS REJECTED ON A MEASUREMENT.** Putting
the session id into the **primary key** (`[form_version_id+local_draft_id]` → three parts) is stricter and
simpler and wrong twice: it needs a `version(3)` with an upgrade that would orphan every existing draft,
and it deletes the honest respondent's recovery outright, because `sessionStorage` dies with the tab. The
row's *"scoping it to a visit would delete the feature"* is a true statement **about that design only**.

### Files claimed

**`resources/public-runtime/` — Lane B's outright under 7(b)'s CRDT grant:**

- `lib/db.ts` — `respondent_session_id` on `DraftRow`
- `composables/useAutosave.ts` — stamp on write, scope on restore, **and close the localStorage door**
- `components/RuntimeSession.vue` — thread the id already injected at `:124` into `createAutosave`
- `App.vue` — scope the resume path's local read at `:249` so a stranger's draft cannot win reconciliation
- `lib/media-queue.ts` — **ADDED AFTER THE CLAIM, and named here rather than absorbed quietly.**
  `attachToSubmission()` re-points an already-owned blob, so the previous respondent's photo or signature
  was uploaded as this submission's attachment. Found by the sweep, inside `resources/public-runtime/`
  which 7(b) already grants Lane B outright — so it needed no extension commit, only this line.
- `lib/respondent-session.ts` — **ALSO ADDED AFTER THE CLAIM, and it is the one that had to be fixed
  rather than filed.** M15's ten-minute window was documented as idle time and was elapsed-since-boot.
  Scoping the draft to a visit turns a wrong visit boundary into LOST WORK, so shipping the scope without
  this would have traded a disclosure for silent data loss on a personal device. **A containment change
  inherits every defect in the boundary it starts trusting.**
- `__tests__/autosave.test.ts` · `__tests__/db.test.ts` · `__tests__/media-queue.test.ts` ·
  `__tests__/respondent-session.test.ts`

⛔ **THREE CLAIMED FILES WERE RELEASED UNTOUCHED, AND ONE OF THEM IS A DESIGN THIS CLAIM GOT WRONG.**

- `components/EarlierDraftNotice.vue` — **NEVER CREATED, AND THE CLAIM WAS WRONG TO PLAN IT.** It was to be
  a content-free "unfinished answers from an earlier visit — Resume / Start fresh" affordance. **The click
  IS the leak**: the runtime has no evidence of ownership to attach to that button, so its copy cannot
  distinguish *"yours"* from *"this device's"* — which is **verbatim the reason the sibling `minor` row
  existed**. It would have converted a silent disclosure into a *consented-looking* one, which is worse for
  the audit trail and no better for the previous respondent. Killed by the adversarial pass over this
  claim's own design, not by a gate. ⚠️ **It would also have made a Lane A file paired**, since
  `clipped-node-containment.test.ts` scans `resources/public-runtime` — so the wrong design was also the
  one that reached across the boundary.
- `lib/reconcile.ts` — released untouched **exactly as the claim predicted it might be**. The `minor`
  closes entirely in `App.vue` by never handing `reconcileDraft` a foreign draft, so `LOCAL_WINS_NOTE` is
  correct English for every case that can now reach it.
- `lib/types.ts` — released untouched. `DraftBlob` is what `restore()` hands its caller; the visit stays
  internal to the table and never needed to reach it.
- `__tests__/components.test.ts` and `__tests__/reconcile.test.ts` — released untouched, and **that is a
  measurement rather than an oversight**: `sessionId` defaults to *do not scope*, so all forty bare
  `RuntimeSession` mounts and all five pure-function reconcile cases behave exactly as before. The
  `undefined`-means-unscoped convention is what bought that, and it was chosen for it.

**Shared artefacts, claimed and never owned:**

- `docs/feature-backlog.md` — see the disjoint-region note below
- `docs/security-threat-model.md:75` — that row's **Residual** paragraph names this exact defect and
  repeats both false premises; it is corrected in the same PR that closes it. **Lane A is not in this file.**
- `docs/offline-first-sync-design.md` — §3's table row for `draft_answers` (`:119`) and a §8 amendment
  beside M15's at `:219-236`, where P3a, M12 and M14 also landed theirs
- `docs/adr/0021-respondent-scoped-device-outbox.md` — **Lane B's own ADR** (M15), amended rather than
  superseded: same decision, second channel
- `docs/ux/form-filling-ux-flow.md` — §5.1 records as-built what `:148` has always specified
- `docs/claims/lane-b.md` — this file
- `PROGRESS.md` — **Lane B's own Current Status block and Lane B's own hand-off line only**, per 7(d)

⚠️ **`docs/feature-backlog.md` IS HELD BY BOTH LANES RIGHT NOW, DELIBERATELY, AND THE REGIONS ARE NAMED SO
THE OVERLAP IS A MERGE AND NOT A COLLISION.** Lane A's live M20 claim takes the three rows under the
**Design system** heading of the merge-gate review. This row takes **`:1099`** and the `minor` directly
below it, and appends the three new findings above. Disjoint by inspection, in a file that appends rather
than restructures — the same reasoning 7(d) already applies to `PROGRESS.md`. **A rebase before the push is
what proves it rather than assumes it.**

**⛔ NOT TAKEN, and each for a stated reason rather than by omission:** nothing in `app/`, `config/`,
`scripts/`, `resources/js/`, `packages/design-system/src/components/`, `ci.yml`, `phpunit.xml`. **No
`/api/v1` route is reached** — the guest draft channel's server half is untouched — so `openapi.json` must
come back **byte-identical**; if it moves, that is a finding, not a rebaseline. `MEMORY.md` is Lane A's.
`tests/e2e/` is claim-first and **is not claimed**: no selector this row can invalidate appears in it —
`public-runtime-offline.spec.ts` has **zero** matches for `draft`, `autosave`, `restore` or `Welcome`, and
its three reloads (`:52`, `:79`, `:225`) all sit inside one browser context, so `sessionStorage` survives
them and the visit id does not rotate. **Grepped, not assumed.**

**PAIRED FILES (7(b-bis)) — CHECKED, AND THE ONE IN RANGE IS ALREADY IN LANE A'S HAND, SO IT IS
DELIBERATELY *NOT* CLAIMED HERE.**
`packages/design-system/src/theme/__tests__/clipped-node-containment.test.ts` scans
`resources/public-runtime` (`SCAN_ROOTS`, `:39`) and asserts `KNOWN_UNGUARDED` (`:71-79`) by exact equality
at `:162`; the public-runtime entry is `RuntimeShell.vue`, which this row does not open. Lane A's M20 claim
says the same file *"is in range"* for them. **Two lanes cannot both hold it, so this design is built not
to move it**: the new component gives its root a `position: relative` if it carries any `clip: rect(0 0 0
0)` node at all, which is the exact fix M15 applied to `.sync-status`. ⛔ **If the list moves anyway, that
is a finding and a coordination event with Lane A — never a silent edit to a file the other lane is
holding.** The other two paired gates need a new `NotificationType` or ability key; this row mints neither,
which is checked and not assumed.

**Namespaces spent: NOTHING from either, and the reasoning is written down before the fact so the
measurement has something to disagree with.** **ADR `0022` STAYS FREE and stays Lane A's block-opener** —
this is ADR-0021's own decision applied to the second channel, and minting `0022` would spend the scarcer
namespace to restate an accepted decision, which is exactly M18's reasoning for not minting it either. No
migration: **`2026_08_17_000111` stays free**, because nothing server-side moves. `0010` stays reserved for
H1d; `#16` stays free; ADR-0016's next free sub-decision stays **`§D35`**.

**Baseline measured on THIS tree at `336d295`, not quoted:** Vitest `resources/public-runtime` **35 files /
782 tests passed**, exit 0, **no `Failed to start forks worker` lines** — the file count checked, per the
trap that fails green. Four host lint gates **97 · 113 · 31 · 113/121/0**, all exit 0, read unpiped.
⚠️ **The lint numbers disagree with the ones Lane A released after M19 (`97 · 111 · 31 · 111/119/0`) and
the difference is exactly M18's two migrations** — their line was written against a pre-M18 tree. Recorded
rather than reconciled quietly, because a gate number moving on a diff that cannot move it is the standing
tell for the other lane's merge.

---

## RELEASED — M18, the SSO email-domain trust anchor (merged as PR #209, `1eae4fc`, 6/6)

**Every claimed file was edited except three, and all three were claimed on a stated prediction that they
might not be.** `app/Support/Tenancy/ConstraintBoundaries.php`, `app/Support/Tenancy/TenantExtractColumns.php`
and `tests/Feature/Sso/SsoStepUpWebTest.php` are absent from the diff — the claim said in words that they were
taken *"on a prediction, not a plan… because a file that might get written to is a file another lane must not
be holding"*, the M17 `DnsRecordBlock.vue` precedent. **Three released untouched by design is the claim
working, not three wasted lines.** The step-up suite needed nothing because that arm never provisions, which
the claim had already reasoned out; the two registries needed nothing because of schema decisions taken
*after* the claim and recorded in its first extension.

**The claim was extended TWICE, each time as its own pushed commit before the file was opened** (`1a14569`,
`a9286f1`): once for `tests/Feature/Tenancy/TenantExtractColumnDriftTest.php`, found by **reading the gate**;
once for `SsoAuthFailureLogTest` and `SsoSettingsWebTest`, found by **running the suite**. The second
extension also records a path deviation rather than absorbing it quietly: the command shipped flat as
`app/Console/Commands/SsoDomainCommand.php`, matching the three commands already there, not in the
subdirectory the claim reserved.

⛔ **ALL EIGHT CITATIONS HELD — THE FIRST ROW IN NINE TO BE RIGHT ABOUT ITS OWN EVIDENCE — AND IT WAS STILL
WRONG IN TWO DIRECTIONS.** The eight-for-eight streak of finding the row's framing wrong ends on the
citations and continues on the substance, which is a distinction worth keeping: *verifying the file:line
claims is not the same as verifying the row.*

**(1) It understated its severity.** *"NOT LIVE — both known exploits are closed"* was too generous. JIT is
permitted to CREATE, and `SsoUserProvisioner::createUser()` stamps `email_verified_at`, defending it as
*"the IdP's claim rather than a convenience"*. **`users` is a DEPLOYMENT-WIDE table**, so that line wrote a
platform-global identity fact from a per-workspace trust root: a paying SSO tenant could mint a `users` row
for any unregistered address carrying a **forged mailbox-control claim**. It did not stay local —
`TenantMembershipService::identityIsEstablished():877` reads that exact column, so the forged stamp fed
**M8's own predicate**, and the address's true owner, invited later by their real employer, was refused the
password-setting arm and handed a sign-in-then-accept hop they could not complete. **Squatting, plus a denial
of the recovery path M8 built.** That docblock is amended in place rather than left reading as settled — its
sentence is true again, **and only because a guard elsewhere now runs first**, which is stated as the
dependency it is.

**(2) It missed a second defect entirely, which the fix's ORDERING closes for free.** The failures panel
renders `existing_account_not_member` as *"Address already has an account elsewhere"* and `jit_disabled` as
*"Nobody here matches that address"* — so an SSO-entitled admin could assert **any** address and read back,
from their own settings page, whether it has an account **anywhere in the deployment**, having proven nothing
about the domain. §D19's uniform 404 was intact the whole time; **the panel was the surface that leaked**, and
§D26 built it precisely because that audience is entitled to answers. ⚠️ **This is the M15 shape recurring:
the row named a disclosure and missed a worse one on the same surface.**

✅ **THE "grandfathering call" THE ROW CALLED *"the part that makes it a decision rather than a feature"* WAS
DISSOLVED RATHER THAN ANSWERED.** It priced a per-connection mode column or a backfill from members'
addresses; **neither is built and neither is needed.** The check sits after the `Active` early return, so **an
active membership IS the grandfather** — no live deployment loses a member on deploy, no public-mailbox
exclusion list is maintained, and no column can be left in the wrong state. ⚠️ **That rests on an ENUMERATED
fact rather than a reasoned one**: the four writers of `TenantUserStatus::Active` were listed and each asked
what it demanded of the person. **Neither rejected alternative would have survived contact** — the backfill
hands a workspace with `gmail.com` members authority over the whole of `gmail.com`, and the "enforce only once
a domain is verified" shape is opt-in security whose switch is held by the threat actor.

⚠️ **THE FIXTURE BLAST RADIUS WAS THE CONTROL WORKING, AND IT MADE M1's AND M9's OWN CASES STRONGER.** Seven
cases across three suites went red because their workspaces had verified nothing — **the same thing that will
happen to every real deployment.** Verifying the fixture domain in `beforeEach` means those cases now certify
*"even inside a domain the workspace has proven it controls, single sign-on will not adopt an existing
account"*. `evil.test` is left unverified deliberately, so the attacker fixture has two independent reasons to
be refused. **The prediction that flagged "a fourth registry" as the most likely error was right about the
SHAPE and wrong about the PLACE** — there was no fourth registry; `TenantScopedTables` and the extract census
were the whole set, and the misses were test fixtures. The tell was exactly as written down: a red gate naming
a file the diff does not touch.

✅ **THREE SCHEMA DECISIONS WERE FORCED BY A GATE AND ARE RIGHT ON THE MERITS, WHICH IS THE GOOD CASE.**
`ConstraintBoundaryDriftTest` pins two censuses by exact equality, so: the table is scoped to the **TENANT**
with no FK to `sso_connections` (a workspace controls a domain, and re-importing IdP metadata must not destroy
the proof); its unique key is **`(tenant_id, domain)`**, so **two workspaces may each verify the same domain**
on their own token — a global unique would have let whichever claimed first deny the other, turning a control
designed to stop squatting into a squatting primitive; and there is **no unique index on the token**, because
nothing looks a row up by one, so it would have been a boundary-crossing key buying nothing. All three
censuses stayed still, as the first claim extension predicted.

⚠️ **THE ORPHANED `docker exec` TRAP FIRED TWICE, THE SECOND TIME ON PHPSTAN RATHER THAN PEST.** The tool's
two-minute timeout kills the host side, the container process keeps running, and the redirect's stream is
already severed — so the result is unrecoverable and the host `ps` count reads **0** while the container reads
**1**. Both times the container probe is what found it. **Every long run went to the background afterwards**,
which is the actual remedy rather than a faster check.

✅ **PINT WAS PROVED NOT BLIND BEFORE IT WAS BELIEVED** — a deliberately misformatted probe under `app/`
returned FAIL with exit 1 and was deleted before the real run, which then passed on 1410 files. **And PHPStan
caught six real errors of mine before CI could**: missing `Collection` generics on `forTenant()`, three
optional-offset reads the analyser cannot prove, and two redundant `?->` on the left of `??`. **Not one was a
`property.notFound` phantom** — which is exactly why the local baseline is measured by FILE LIST and never by
count, and the final run came back at 18 with none of my files in it.

⛔ **WHAT WAS DELIBERATELY NOT BUILT, EACH FILED THE MOMENT THE DECISION WAS TAKEN** (the J4b1 rule, whose
whole point is that a deliberately-unfixed finding recorded only in prose is invisible to a backlog search):
the tenant-facing `/settings/sso` domains card — **a LANE A row**, and the reason `php artisan sso:domains`
exists and is tested; periodic re-verification, where **the demotion rule is the hard half, not the lookup**;
`MemberController::invite()`'s missing domain check, a product decision rather than a cleanup; and
self-registration as the weakest of the four `Active` doors. **Residual 32 is rewritten from "nothing verifies
this" to those three**, so nobody reads M18 as partial.

---

## SUPERSEDED CLAIM (kept for the record) — M18 (`m18-sso-domain-verification`)


Opened: 2026-08-26, cut from `origin/main` at `eb45eb9`. **Numbered M18**: M16 and M17 are Lane A's and
both merged; Lane A holds no claim, so the next free increment number is mine.

Row: `docs/feature-backlog.md:1514` — *"`major` · Nothing verifies that a workspace controls the email
domain its identity provider asserts."* Carried as `docs/security-threat-model.md` **residual 32** and
named as ADR-0016 §D33's revisit trigger.

**Files.**
*New:* `database/migrations/2026_08_17_000109_create_sso_verified_domains_table.php` ·
`database/migrations/2026_08_17_000110_recreate_sso_auth_failures_reason_check_for_unverified_domain_refusal.php` ·
`app/Models/SsoVerifiedDomain.php` · `app/Services/Sso/SsoDomainService.php` ·
`app/Console/Commands/Sso/SsoDomainsCommand.php` · `database/factories/SsoVerifiedDomainFactory.php` ·
`tests/Feature/Sso/SsoDomainVerificationTest.php`.
*Edited:* `app/Services/Sso/SsoUserProvisioner.php` · `app/Services/Sso/SsoAuthenticationException.php` ·
`app/Enums/SsoFailureReason.php` · `app/Support/Tenancy/TenantScopedTables.php` ·
`app/Support/Tenancy/ConstraintBoundaries.php` · `app/Support/Tenancy/TenantExtractColumns.php` ·
`config/saml.php` · `tests/Feature/Sso/SsoAcsWebTest.php` ·
`tests/Feature/Sso/SsoLoginCompletionWebTest.php` · `tests/Feature/Sso/SsoStepUpWebTest.php` ·
`docs/adr/0016-sso-saml-federation.md` · `docs/security-threat-model.md` · `docs/feature-backlog.md` ·
`docs/data-dictionary.md` · `docs/claims/lane-b.md` · `PROGRESS.md` (Lane B's block only).

⚠️ **EXTENDED 2026-08-26, BEFORE THE FILE WAS OPENED — `tests/Feature/Tenancy/TenantExtractColumnDriftTest.php`.**
A new tenant-scoped table joins `TenantScopedTables::STRICT`, which is the list the P2b extractor walks, so its
`EXTRACTED_COLUMN_CENSUS` gains an entry the moment the table exists — and that constant lives in the **test
file**, not in `TenantExtractColumns`. **Found by reading the gate rather than by CI reddening**, and it is
exactly the shape this claim's own prediction flagged as most likely wrong: a registry the `sso_auth_failures`
grep did not reach.

⚠️ **EXTENDED AGAIN 2026-08-26 — two more suites, and this time CI's local proxy found them rather than a
read of the gate: `tests/Feature/Sso/SsoAuthFailureLogTest.php` and `tests/Feature/Sso/SsoSettingsWebTest.php`.**
Four cases across them refuse with `domain_not_verified` where they used to refuse (or succeed) for their
own reason — three of M1's and M9's failure-panel cases, plus P1a's whole-round-trip canary. **Every one is
the control working on a fixture that has not verified a domain, which is the same thing that will happen to
every live deployment**, and the fix is the `SsoAcsWebTest` one: verify the suite's fixture domain in
`beforeEach` so each case keeps certifying what it was written to certify. `evil.test` in the failure-log
suite is deliberately left UNVERIFIED — it is an attacker fixture and now has a second reason to be refused.

⛔ **AND A PATH DEVIATION, RECORDED RATHER THAN QUIETLY ABSORBED: the artisan command shipped as
`app/Console/Commands/SsoDomainCommand.php`, not the `app/Console/Commands/Sso/SsoDomainsCommand.php` this
claim reserved.** All three existing commands in this repository sit flat in `app/Console/Commands/`, so a
one-file subdirectory would have been a new convention introduced by accident. The claim's substantive
property — that no other lane could be holding it — held either way, since the whole directory is
unassigned and Lane A has no console work. Corrected here because a claim nobody can trust to be accurate
is worse than no claim, which is M16's own lesson about a stale one.

✅ **THE PREDICTION THAT SAID THIS WOULD HAPPEN WAS RIGHT ABOUT THE SHAPE AND WRONG ABOUT THE PLACE.** It
named "a fourth registry" as the likely miss and said the tell would be a red gate naming a file the diff
does not touch. There was no fourth registry — `TenantScopedTables` and the extract census were the whole
set — and the misses were **test fixtures** instead. The tell was exactly as described.

✅ **AND THE SAME READ SETTLED TWO SCHEMA DECISIONS, SO `ConstraintBoundaries.php` AND ITS DRIFT TEST STAY
UNTOUCHED — BY DESIGN NOW, NOT BY ASSUMPTION.** `ConstraintBoundaryDriftTest` pins two censuses by exact
equality: composite FKs whose key contains `tenant_id`, and unique indexes on a `tenant_id`-carrying table whose
key does **not**. So (a) the table is scoped to the **TENANT** and carries **no** FK to `sso_connections` — a
workspace controls a domain, an IdP does not, and a metadata re-import must not destroy the proof of control —
which keeps the FK census still; and (b) its unique key is **`(tenant_id, domain)`**, never a global unique on
`domain`, which keeps the second sweep still. ⚠️ **(b) has a product consequence worth stating rather than
discovering: two workspaces may each verify the same email domain**, each with its own token. That is correct —
one controller can legitimately run two workspaces — and a global unique would have let whichever claimed first
deny the other.

⚠️ **THE THREE `app/Support/Tenancy/` REGISTRIES AND `SsoStepUpWebTest.php` ARE CLAIMED ON A PREDICTION,
NOT A PLAN** — a new tenant-scoped table may oblige all three registries, and the step-up suite may not
need touching at all because that arm never provisions. **Claimed anyway, because a file that might get
written to is a file another lane must not be holding**, whatever my current intention for it (M17's
`DnsRecordBlock.vue` precedent, released untouched by design).

**Shared artefacts taken:** four `docs/` files and `PROGRESS.md` (own block only). **`openapi.json` is NOT
taken** — no `/api/v1` route is reached, so it must stay byte-identical and that is an assertion rather
than a regeneration. **`ci.yml` and `phpunit.xml` are NOT taken.** **No `tests/e2e/*.spec.ts`** — grepped:
nothing there drives an SSO assertion.

**Paired files taken: NONE, and that is verified rather than assumed.** Neither 7(b-bis) PHP gate is
reachable: no `NotificationType` moves and no ability key moves (an artisan command carries no policy).
The third gate cannot fire — no `clip: rect(0 0 0 0)` is added or removed. ⚠️ **The one that looked like a
paired file is not one**: this row adds a `SsoFailureReason` case, and `resources/js/components/sso/types.ts:88`
types `SsoFailureRow.reason` as a plain `string` with `reason_label` and `hint` **composed server-side**,
its docblock saying in words that a second map in that package is forbidden. `cards.test.ts` uses two
string literals and enumerates nothing. **Grepped both trees: there is no parity gate on this enum.**

**Namespaces spent:** migration block `2026_08_17_000109` **AND** `2026_08_17_000110` — both, so the next
free prefix is `2026_08_17_000111`. ADR-0016 sub-decision **`§D34`** is **SPENT**. ⛔ **No new ADR number:
`0022` STAYS FREE and stays Lane A's block-opener.** This is an SSO trust decision inside ADR-0016's own
scope, and minting `0022` for it would spend the scarcer namespace to say the same thing. `0010` stays
reserved for H1d; `#16` stays free.

### What is already measured, so the plan is not built on the row's own framing

**All eight of the row's citations HOLD, which is the first time in nine rows.** `SsoUserProvisioner::provision()`
carries exactly the two refusals named, at `:110-126`; `app/Support/Tenancy/DnsTxtResolver.php` and
`CustomDomainService::verify()` are real and reusable; the grep for
`verified_domain|domain_verified|assertEmailDomain|domainOwn` returns **zero hits — not merely in the two
SSO directories the row names, but across all of `app/`, `database/` and `config/`**; residual 32 and
ADR-0016 §D33's revisit trigger are both present and say what the row says they say.

⛔ **BUT THE ROW UNDERSTATES ITS OWN SEVERITY, AND THE SWEEP FOUND TWO THINGS IT DOES NOT NAME.** It says
*"NOT LIVE — both known exploits are closed"*. Two consequences survive M9 and are live today:

1. ⚠️ **THE SSO DOOR WRITES A PLATFORM-GLOBAL IDENTITY FACT FROM A TENANT-SCOPED TRUST ROOT.**
   `SsoUserProvisioner::createUser():207` stamps `email_verified_at => now()`, and its docblock defends
   that as *"the IdP's claim rather than a convenience… the assertion is signed by the tenant's own trust
   anchor and names this address."* **That reasoning is exactly what this row attacks**: a trust anchor the
   asserting workspace installed vouches for nothing about a domain that workspace does not control. So a
   paying SSO tenant can mint a global `users` row for **any** unregistered address with a **forged
   mailbox-control claim** on it. And `TenantMembershipService::identityIsEstablished():877` **reads that
   very column**, so the forged stamp feeds M8's own authentication predicate: the true owner, invited
   later by their real employer, is refused the password-setting arm and sent to a sign-in-then-accept hop
   they cannot complete. **Identity squatting plus a denial of M8's recovery path — filed nowhere.**
2. ⚠️ **A CROSS-TENANT ACCOUNT-EXISTENCE ORACLE ON THE FAILURES PANEL.** `existing_account_not_member`
   renders as *"Address already has an account elsewhere"* while `jit_disabled` renders as *"Nobody here
   matches that address"*. An SSO-entitled admin can therefore assert any address and read back, from
   their own settings page, whether it has an account anywhere in the deployment — having proven nothing
   about the domain. §D19's uniform-404 posture is intact on the wire and is simply not the surface that
   leaks.

✅ **THE ENFORCEMENT POINT IS SINGULAR, AND THAT IS GREPPED RATHER THAN ASSUMED.** `SsoIdentity` has exactly
two consumers: `SsoUserProvisioner::provision()` and `SsoStepUpService::matchSubject()`. The step-up arm is
already bound — it compares the resolved user against `sso_auth_requests.user_id`, written before the
redirect by an authenticated session — so it never lets an address *select* a person. `provision()` is the
whole seam, exactly as the audit said.

✅ **THE GRANDFATHERING CALL THE ROW CALLS "the part that makes it a decision rather than a feature" IS
DISSOLVED RATHER THAN ANSWERED, AND THE PREDICATE IS VERIFIED.** The gate sits **after** the `Active`
early-return, so **an Active membership IS the grandfather** — no backfill, no per-connection mode column,
no public-mailbox exclusion list, and **not one existing member of any live deployment is locked out.**
That rests on a claim I enumerated instead of reasoning about: the only writers of
`TenantUserStatus::Active` are `accept()` (needs the emailed token *and*, since M8, either a fresh identity
or the real person signed in as themselves), `joinOpenTenant()` (self-registration, which is a separate and
older door where nothing is forged), `joinViaGoogle()` (Google verified the mailbox) and `joinViaSso()`
(downstream of this gate). **No door mints an Active row for a stranger's address on an assertion alone.**
⛔ Rejected: *"enforce only once a connection has verified a domain"* — that is opt-in security whose switch
is held by the threat actor, and it closes nothing.

⚠️ **THE ORDER INSIDE `provision()` IS LOAD-BEARING, WHICH IS M12's LESSON POINTED AT A NEW METHOD.** The
domain check goes **before** both identity refusals, not after: that is what closes the oracle in (2), and
it makes the panel's answer the one the admin can act on. **The cost is stated rather than discovered — it
moves M1's and M9's own regression cases**, which assert `existing_account_not_member` and
`established_identity_not_joined` against `@identity.test` addresses on an Acme connection. Those cases
gain a verified domain so they keep certifying their own refusal, which makes them assert the **stronger**
statement (*even with the domain verified, adoption is refused*), and new cases cover the gate firing ahead
of them.

✅ **THE FIXTURE DOMAINS ARE FIXED, NOT FAKER-RANDOM, SO THE M9 DICE-ROLL TRAP DOES NOT APPLY HERE.**
`FakeIdp::$nameId` is the literal `ada@acme.test` and `tests/Pest.php:157` builds committed identities as
`Str::random(12).'@identity.test'` — **the local part is random and the domain is not.** Two verified-domain
rows in the shared `beforeEach` therefore cover the 42-case ACS suite and the 22-case completion suite.

### Deliberately NOT in this PR, each filed rather than dropped

- **The tenant-facing `/settings/sso` domain panel.** It is `resources/js/**`, which is **Lane A's outright**
  since the 2026-08-25 widening, and splitting a paired change across two lanes is the one thing 7(b-bis)
  says cannot work. The interim surface is `php artisan sso:domains`, which is the `domains:activate`
  precedent in ADR-0012 §D6. **Filed as a Lane A backlog row.**
- **Periodic re-verification of a verified domain.** `CustomDomainService::sweep()` re-reads on a cadence as
  its dangling-DNS control; this table gets `verification_checked_at` so the column is there, but no sweep
  job, because `routes/console.php` records that nothing runs the scheduler on the production box and trust
  decay is a product call rather than a defect. **Filed, with the residual named.**
- **`MemberController::invite()`'s missing domain check**, which is the same root on the invitation door and
  is named in this row's own chain. Out of scope here and filed separately.

### Prediction, written before the run so the measurement has something to disagree with

- **CI Pest MOVES, and it is the only gate that should.** ~4515 → ~4545 (+25 to +35 new cases), with the
  existing 4515 all still passing; 2 pre-existing warnings unchanged.
- **PHPStan CI `[OK]`; local 18 = baseline BY FILE LIST, not by count** — a new model brings new
  `property.notFound` phantoms local PHPStan invents and CI does not, so the count may rise and the file
  list is the measurement.
- **Four host lint gates: 97 controllers unchanged · 111 → 113 migrations · 31 jobs unchanged ·
  constraint-boundary 111/119/0 MOVES** (a new tenant-scoped table adds constrained columns), read without
  a pipe.
- **`openapi.json` byte-identical** — asserted from the diff, no `/api/v1` route is reached.
- **Vitest 130 files / 2,213 and axe 42 / 299 unmoved, and ASSERTED rather than re-measured**, because no
  `.vue`, no `packages/` source and no `resources/` file is in the diff. **E2E 551 passed + 10 skipped, no
  flaky line.**
- ⚠️ **The prediction I most expect to be wrong: that the three `app/Support/Tenancy/` registries are the
  complete set a new tenant-scoped table must join.** I found `TenantScopedTables::STRICT` and three drift
  tests by grepping for `sso_auth_failures`, which is a floor and not a census — the same limitation
  7(b-bis)'s own sweep records about itself. **If a fourth registry exists, the first red gate will name a
  file this diff does not touch, and the rule is to treat that as structural rather than as a flake.**

## RELEASED — M15, the respondent-scoped device outbox (merged as PR #207, `f052dd5`, 6/6)

Every claimed file was edited except one: `resources/public-runtime/components/SubmissionOutbox.vue` was
claimed and **not touched**, because scoping `rows` upstream means the list simply receives fewer — which
is also why every selector `tests/e2e/public-runtime-offline.spec.ts` locates survived untouched. The claim
was **not extended** mid-build; nothing outside it was opened.

**Both halves of the 7(b-bis) paired file moved in the one PR**, which is what that rule exists to force:
`packages/design-system/src/theme/__tests__/clipped-node-containment.test.ts` lost its `SyncStatus.vue`
entry in the same commit as `.sync-status` gained `position: relative`. `RuntimeShell.vue` stays listed —
untouched here, and a containing block added without a look at the running app is what that file refuses.

**Namespaces:** ADR **`0021`** is **SPENT**. Next free overall is **`0022`**; `0010` stays reserved for
H1d and `#16` stays free; ADR-0016's `§D34` and the migration block `2026_08_17_000109` are **UNSPENT** —
M15 touched no PHP and added no migration.

**Prediction vs measurement, since the point of writing one first is to be measured against it.**
The file counts were exact and the test count was under-predicted: predicted `public-runtime` 35 files /
~765 and a repo total of 130 / ~2,196; **measured 35 / 782 and 130 / 2,213**. `design-system` 35/545,
`resources/js` 60/886, Pest **4515 / 19,161 with 2 warnings**, E2E **551 passed + 10 skipped**, axe,
the four lint gates, the byte-identical `openapi.json` and the absence of a Dexie version bump all held
exactly as predicted. **One prediction was wrong in a useful direction**: I expected to have to fix
`sync-outbox.test.ts:194-195` and `outbox.test.ts:191-203`, because their fixtures build rows with no
session. In the event **one** existing assertion in the whole suite needed changing — `listSubmissions
honours its limit`, an options-object signature change — because an unscoped driver deliberately answers
the pre-M15 device-wide numbers, so every existing case kept meaning what it meant.

---

## Template

```
## CLAIMED — <row name> (<branch>)
Opened: <date>. Row: <the backlog row, quoted enough to identify it>.
Files: <every file to be edited, repo-relative>.
Shared artefacts taken: <docs/…, openapi.json, … — or "none">.
Paired files taken: <7(b-bis) entries, and the other half of each — or "none">.
Namespaces spent: <migration prefix / ADR number — or "nothing from either namespace">.
Prediction: <what you expect the gates to do, written BEFORE the run so it can be measured
             against rather than explained afterwards>.

## RELEASED — <row name> (merged as PR #<n>, <sha>, 6/6)
<what was actually taken; whether every claimed file was edited; anything the claim was
 extended to mid-build, each of which was its own pushed commit before the file was opened>
```
