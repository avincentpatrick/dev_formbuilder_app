# Backlog triage — M37

**Every open row in `docs/feature-backlog.md`, re-validated against the merged tree.**

**Measured 2026-08-28** against `origin/main` at `f2a663f`, by six independent read-only passes — one per
section — each answering four questions per row: *is the defect still live · have its citations moved ·
is its prescribed remedy actually correct · does the row understate itself.*

⚠️ **This file triages. It does not fix, and it does not edit `docs/feature-backlog.md`** — that file is a
shared artefact both lanes claim, and a fan-out must not contend for it. Folding these verdicts back into
the backlog is a later, serial decision.

---

## The result inverts the premise the row was filed on

M37 was claimed expecting **a large minority of rows to be stale**, on the strength of M20's finding that
three of its four filed cases were already passing. That is not what the census found.

| | Rows |
|---|---|
| Triaged | **68** (12 `major`, 56 `minor`) |
| **Still LIVE** | **65** |
| Already fixed and never struck through | **1** |
| Promoted out to `decisions.md` | **1** |
| Cannot tell from reading alone | **1** |

**The backlog is accurate about *whether* defects exist.** What it is unreliable about is **scope and
remedy**:

| Failure | Count |
|---|---|
| Row **understates itself** — more instances than it names | **14** |
| Row **overstates itself** — fewer instances, or a cause not reachable | **4** |
| **Prescribed remedy is wrong, incomplete, or would fatal** | **8** |
| Citations FALSE (blank line, table separator, `---` rule) or moved | **~30** |

**So the discipline to carry forward is not "check the row is real." It is "check the row's scope and
check its remedy."** M36 added `Evidence verified` and `Remedy verdict` to the claim template on the
strength of four rows; this census puts the count at eight and shows the evidence half is the reliable one.

---

## Priority queue — what to take next

### 1. ⛔ `major` · Unthrottled credential endpoints — **three of them need no session at all**

The filed row names `POST /user/confirm-password` (authenticated). Verifying it found **eight** Fortify
routes with no rate limit, and the three worst are reachable by anyone:

| Endpoint | Session? | Exposure |
|---|---|---|
| `POST /forgot-password` | **none** | unlimited reset-mail dispatch · account enumeration |
| `POST /reset-password` | **none** | unlimited reset-token guessing |
| `POST /register` | **none** | unlimited account creation |
| `PUT /user/password` | yes | password guessing (validates `current_password`) |
| `POST /user/confirmed-two-factor-authentication` | yes | unlimited 6-digit TOTP guessing |
| `POST` / `DELETE /user/two-factor-authentication`, `POST /user/two-factor-recovery-codes` | yes | unthrottled 2FA lifecycle |

**Verified independently, not taken on report:** `throttle:` appears **four times** in Fortify 13.18.1's
route file — `login`, email-verification ×2, and the 2FA challenge. `config/fortify.php:169-172` maps only
`login` and `two-factor`. None of the 16 `RateLimiter::for()` registrations covers the rest. `route:list`
confirms `POST /forgot-password` carries no throttle and no global fallback.

⛔ **THE ROW'S PRESCRIBED REMEDY DOES NOT WORK, AND BOTH PASSES SAID SO INDEPENDENTLY.** *"One
`RateLimiter::for()` plus one `->middleware('throttle:…')`"* has nowhere to land: Fortify has no per-route
middleware hook, and `config/fortify.php` says so three times (`:119`, `:133-135`). The real options are a
group-wide entry at `config/fortify.php:150` — which double-throttles `/login` — or a path-checking
middleware in the `GateRegistration` mould. **Differently shaped, and larger, than the row prescribes.**

### 2. `major` · Four maintenance fan-outs asserted by a fixture too small to see a wrong tenant id

Directly analogous to M32, and the remedy is **SOUND and already proven**: `RefreshConnectorTokensJob` is
fixed in exactly the prescribed shape (a drain loop plus a second tenant). The other four are unfixed and
hoisting any loop variable is a no-op against their single-tenant fixtures. *(Row says six; the tree has
five, one already done.)*

### 3. `major` · Documentation asserting things the code does not do — five rows, all live

ADR-0001's `citext`/`pgcrypto` claim · ADR-0002 §D3's two unbuilt isolation controls · the audit spec's
omission of impersonation events · the `APP_PREVIOUS_KEYS` register entry · ADR-0017's "no SSO rows" claim.
**Four of the five understate themselves** — the same false claim recurs in documents they do not name.

### 4. 👤 A decision that is yours, not mine

✅ **ANSWERED AND DONE — `M51` (2026-08-31); see `D6` in the `ANSWERED` section of
`docs/claims/decisions.md`.** The corpus named a **real third-party client** and published an audit of
its weaknesses. Both are out of the tracked files and every architectural lesson is kept.
⛔ **The exposure is REDUCED, not closed — history was not rewritten**, and whether it should be is
filed as its own decision, `D9`, recommended against.
⚠️ **This census under-counted, and so did the row it was checking:** the row named 6 sites, this file
said **"11+"**, `D6`'s table said 17 across 9 files, and the measurement was **26 occurrences across 11
files — or 20 lines carrying at least one**, because `grep -c` counts lines and `grep -o` counts
occurrences and nothing said which. ⛔ **And this very file was one of the sites it missed** — it carried
no searchable name and identified the client *by description*, in this paragraph, inside its own summary
of the decision. A name-scoped search reported it clean.

---

## What the sweep found about its own accuracy

⚠️ **Six verdicts were re-checked by hand and all six held** — the two `preserveState` predicates being
byte-identical; a fifth bare `toThrow` at `SubmissionPipelineTest.php:426`; `ConnectorRulePausedNotification`
carrying zero `CarriesTenantBrand`; `KNOWN_OVERFLOWING` being genuinely empty; `SheetsRuleFields`'
`connectExisting()` lacking the `busy` guard its sibling has; and the README block being fully
docker-prefixed. That is the basis for trusting the aggregate — not the agents' confidence ratings.

### ⛔ Two rows filed by M36 — yesterday — were already wrong

Recorded because it is the sharpest available evidence for why this pass was worth running.

- **The Pint under-scan row says "every hand-off"**. Lane A's no longer does — M36 fixed it in the same
  increment that filed the row. It is one hand-off line now, not two.
- **The `fb-lane-c` row's remedy is wrong.** *"`git worktree remove` is the whole fix"* — it refuses on a
  worktree with modified tracked files, and **that row itself reports the dirty file.** It needs `--force`
  or a discard first. The row also said 104 commits behind; it is now **120**.

**A row can be stale in hours, and its author is not exempt.**

### A row whose own citations have rotted faster than the code

The *"cluster of stale by-line citations"* row: of ~25 sub-items, **9 are now FALSE** — pointing at blank
lines, table separators and `---` rules — 2 have moved, and one is misfiled against the wrong ADR entirely
(`docs/adr/0011` is analytics-substrate; the `MdsTabs` material is in the design-system reference).
**Its remedy is sound in intent and unusable in execution: the list must be re-derived before it is worked,
not merely worked.**

### A green heading sitting over a live defect

`### ✅ Found by the repaired overflow gate (M17) — ALL FOUR FIXED AND ALL FIVE ENTRIES DELETED` is
**literally true** — all four are fixed, `KNOWN_OVERFLOWING` really is `new Set([])` — and its **implied
completeness is false**: a live `minor` sits directly beneath it, filed *by that same work*. The heading
counts what it deleted and not what it created. Honest form: *"— FOUR FIXED, FIVE ENTRIES DELETED, ONE NEW
ROW FILED AND STILL OPEN."*

### Rows that overstate themselves — four

Worth knowing before an increment is spent: twelve `finally`-inside-transaction sites are **eleven** (one
is a census error — its `finally` is outside the transaction); the authenticated autosave's wrong-sentence
cause is `submission_uuid_claimed` **only**, not "entitlement and content"; **two of three** saved-view
verbs already have permission-deny coverage; and `ApiAbilities` records **five** refusals to widen, not four.

---

## Method, and what it cannot see

Six read-only passes, one per section, each briefed with the repo's own measured traps —
`SubstituteBindings` running before `Authorize`, `RequireFeature` failing open on a null plan, an ability
refusal and a permission refusal both being 403, a missing Vue import passing both front-end gates.

⛔ **The brief forbade the DATABASE, not merely the files** — no `artisan`, no `pest`, no migrations, no
writing `docker exec`. M34's "read-only" agent ran `artisan test` and its `migrate:fresh` dropped the
schema under a live run, producing three phantom failures that read as real ones. The tell was the
assertion total moving, not the failure count.

**Limits, stated rather than discovered.** Nothing here was executed, so every verdict is a reading of
source. Two rows are marked MEDIUM confidence and one CANNOT-TELL for exactly that reason: a claim that
depends on a runtime-resolved CSS ancestor chain, or on whether a server actually refuses a stale
checksum, cannot be settled by reading. **A row this file calls LIVE is a row that looks live in the
source — the mutation harness (`scripts/mutate.php`) is what settles it**, and settling it is the job of
whichever increment takes the row.
