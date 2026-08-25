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

## CLAIMED — M15, the respondent-scoped device outbox (`m15-respondent-session-scope`)

Opened: 2026-08-26. Re-cut from `origin/main` at `19afc1b` (the previous branch carried zero commits
and was cut one behind).

**Row** (`docs/feature-backlog.md:922`): *"`major` · The device-wide outbox is mounted above the
phase machine on an unauthenticated page."* On shared kiosk hardware the next respondent sees the
previous one's rows and a Discard button that permanently deletes a stranger's unsent response and
media.

**Files** — all `resources/public-runtime/**`, Lane B's outright under 7(b) as widened 2026-08-25:
`lib/respondent-session.ts` (new) · `lib/db.ts` · `lib/outbox.ts` · `composables/useSyncOutbox.ts` ·
`components/SyncStatus.vue` · `App.vue` · `components/RuntimeSession.vue` ·
`__tests__/{respondent-session,outbox,sync-outbox,sync-status,db,fixtures}.ts`.
NOT `components/SubmissionOutbox.vue` (needs no change) and NOT `lib/conflict-notice.ts` (frozen).

**Shared artefacts taken:** `docs/adr/0021-respondent-scoped-device-outbox.md` (new) ·
`docs/ux/form-filling-ux-flow.md` · `docs/offline-first-sync-design.md` ·
`docs/security-threat-model.md` · `docs/feature-backlog.md` · `PROGRESS.md` (Lane B's block only).
`tests/e2e/public-runtime-offline.spec.ts` is **grepped, not edited** — the design preserves all
nine of its assertions.

**Paired files taken — 7(b-bis), BOTH halves in this one PR, per the user decision of 2026-08-25:**
`packages/design-system/src/theme/__tests__/clipped-node-containment.test.ts` (Lane A's tree). Its
`KNOWN_UNGUARDED` at `:63` lists `resources/public-runtime/components/SyncStatus.vue` and `:153`
asserts exact equality, so the guard is added to `.sync-status` and the entry is deleted together.
`RuntimeShell.vue` stays listed — untouched here.

**Namespaces spent:** ADR **`0021`**. Next free overall becomes `0022`; `0010` stays reserved for
H1d and `#16` stays free; ADR-0016's `§D34` and the migration block `2026_08_17_000109` are
**unspent** — this row adds no migration and no PHP. Lane B's reserved `0026-0030` block is
untouched.

**Prediction, written before the run so it can be measured against rather than explained
afterwards.** Vitest `public-runtime` rises from 34 files / 744 tests to **35 / ~765** (one new spec
plus roughly a dozen scoping cases), so the repo total moves from 129 / 2,175 to **130 / ~2,196**;
`design-system` stays **35 / 545** (`clipped-node-containment` changes a constant, not a case count);
`resources/js` stays **60 / 886**. Pest stays **4515 / 19,161** and PHPStan `[OK]` — no PHP is
touched. `openapi.json` byte-identical. axe stays **42 / 299**. The four host lint gates stay
**97 · 111 · 31 · 111/119/0**. Dexie `verno` stays **2** (`db.test.ts:48`) and the store list stays
five (`db.test.ts:10-17`) — the new field is un-indexed on purpose. The gates I expect to have to
fix rather than merely re-run are `sync-outbox.test.ts:194-195` and `outbox.test.ts:191-203`, whose
fixtures build rows with no session id.
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
