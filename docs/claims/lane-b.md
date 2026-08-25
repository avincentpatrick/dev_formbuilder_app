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

## Status: NO ACTIVE CLAIM

Lane B holds nothing.

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
