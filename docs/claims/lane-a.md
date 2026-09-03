# Lane A — active claim

**One writer: Lane A.** Lane B never edits this file, and Lane A never edits `lane-b.md`. That is
what makes a claim conflict structurally impossible rather than merely unlikely.

**The protocol is Standing Rule 7(g)**, not this header. In one line: **a claim is a *pushed*
commit** — write it here, `git push origin HEAD:main`, and only then open the first file. An
unpushed claim does not exist; M14 proved that by writing a perfect one that nobody could see.

**Before opening any shared or paired artefact**, `git fetch` and read both lane files.

Shared artefacts, which are claimed and never owned: `docs/**`, `openapi.json`, `phpunit.xml`,
`PROGRESS.md` (own block only), and the top-level `tests/e2e/*.spec.ts`.
Paired files — where a change obliges you to edit *both* halves in the same PR — are listed in
Standing Rule 7(b-bis).

---

## Status: NO ACTIVE CLAIM — `M66` is merged; the batching saving is measured, not assumed, and it cleared the bar by a wide margin

`M66` closed **four** rows and filed **three**, so the open count moved 86 → 85 and stayed at **zero
`major`**. `state.php` counts the tree; do not take that sentence's arithmetic on trust.

✅ **`D13`'S DEBT IS PAID, AND THE ANSWER IS NOT MARGINAL.** The batching protocol was answered on a
predicted ~42% saving that `D13` insisted be **proven on the first batched increment or the batch size
revisited**. Measured end to end — `M65`'s release commit to `M66`'s — the four rows cost **~105 minutes
against a 628-minute baseline, an ~83% saving**, and the build phase alone was **34 minutes of authoring
for four rows plus eight mutation controls**. ⚠️ **Do not read 83% as the new expected figure.** See the
release below for the three reasons it flatters, the most important being that `D13`'s per-row baseline
was drawn from increments whose build phase included a CI wait this one overlapped with useful work.

⚠️ **For whoever takes the next batch: the three lessons `M66` would most like to hand on.**
**(1) THE REMEDY IS STILL THE HALF THAT IS WRONG, AND BATCHING MADE THAT MORE VISIBLE RATHER THAN LESS.**
Four rows, four sound bodies of evidence, **two broken remedies** — one of which (`R2`) could not have
executed at all, because the row prescribed calling a method on a trait the class had never used. Reading
the four together is what showed the pattern was not bad luck: the evidence is written by someone looking
at the defect, the remedy by someone predicting a fix, and only one of those gets re-checked.
**(2) A DEFECT DEFERRED ON A STATED PREMISE OUTLIVES ITS PREMISE, SILENTLY.** `R3`'s route comment named
its own defect in a full sentence and deferred it because *"the POST routes have never been reachable from
a UI"*. That was true when written. Three call sites falsified it later, and nothing anywhere re-read the
sentence — the comment went on reassuring every reader for two increments. **When you defer on a premise,
the premise is the thing that needs a gate**, which is why the fix here is an enumerating sweep rather
than three more constraints.
**(3) A ROW CAN UNDERSTATE ITSELF INTO A WORSE FIX.** `R4` read as a copy defect. The obvious kind
sentence — *"your answers are saved on this device"* — is **false in a conflict review**, because the
durable outbox row is discarded before the recovery runs and autosave is off in that mode. The row that
looked cosmetic was sitting on a data-loss path, and the naive fix would have replaced one false
reassurance with another.

⛔ **`D9` must never be started without an explicit answer.** Open decisions: `D1`, `D3`, `D4`, `D8`,
`D9`, `D10`, `D11`, `D12`. `D12` — whether to end the M-series — is still the one thing that needs the
user, and `M66` deliberately did not touch it. **`D13` is answered, proven, and not to be re-asked.**

⛔ **RUN `php scripts/state.php` FOR EVERY NUMBER.**

---

## RELEASED — `M66`, the first batched increment: four rows, two of whose remedies were wrong (merged as PR #257, `730a2d9`, 6/6 green with real step counts — Static analysis 23 · E2E 20 · Contract 16 · Frontend 12 · Pest 11 · axe 11)

**Shipped 2026-09-03.** Branch `m66-batched-rows`. The first increment run at `D13`'s full width, and the
one that owed `D13` its measurement.

✅ **EVERY CLAIMED FILE WAS EDITED AND NO FILE WAS EDITED THAT THE CLAIM DID NOT NAME.** Twelve code and
test files plus the two shared documents the claim declared. Recorded because `M65`'s release had to
confess the opposite, and because the check costs one `git diff --stat`.

### The measurement `D13` owed

| | |
|---|---|
| `M65` release → `M66` claim (session-start gap) | **31 min** (`D13` mean: 65) |
| claim → merge (build, including one CI round trip) | **52 min** (`D13` mean: 70 **per row**) |
| merge → release (close-out) | see the close-out commit; `D13` mean 22 |
| **Baseline, 4 rows** | **628 min** (4 × 157) |
| **`D13`'s own model** | 367 min — a 41.6% saving |
| **Measured** | **~105 min end to end — an ~83% saving** |

⛔ **THE BAR WAS 40% AND IT CLEARED BY MORE THAN DOUBLE, WHICH IS A REASON FOR SUSPICION AND NOT
CELEBRATION.** Three things flatter this number and all three are named here rather than left for someone
to discover when the next batch comes in slower:

1. **The baseline's build phase is not measured the same way.** `D13`'s ~70 min/row came from increments
   whose claim→merge window *contains* the CI wait — `M65`'s was **402 minutes**, almost all of it
   waiting. This increment overlapped its CI wait with useful work, so part of the "saving" is a
   measurement artefact rather than batching.
2. **These four rows were small.** They were selected for **separability**, which correlates with size.
   A batch containing one genuinely large row will not look like this.
3. **The fan-out was cheap here and will not always be.** Three read-only agents cleared four rows. A row
   whose evidence needs a database or a running suite cannot be verified that way at all.

**The honest reading: batching is worth doing and `D13`'s ~42% is the number to plan against, not 83%.**
The clause `D13` was actually protecting against — *materially under 40%* — is not in play, so **the batch
size stands and is not revisited.**

### How the prediction fared

| Predicted | Outcome |
|---|---|
| PHPStan **can** move; expect no errors, said rather than quoted | ✅ **held, and measured properly.** Swapped all five changed `app/`/`routes/` files to their `origin/main` versions, re-analysed, restored: **18 before, 18 after — delta exactly zero**, all pre-existing local phantoms |
| Contract gate unchanged — `openapi.json` moves by zero | ✅ **held.** Untouched in the diff, and Contract green at 16 steps |
| Vitest file count unchanged | ✅ **held** — cases added to an existing file; 40 → 43 tests in it |
| Pest up across four directories | ✅ **held** |
| **Pint flags the reformatted `routes/admin.php` one-liners** | ❌ **WRONG.** Passed first time. ⚠️ And `passed` was **not** believed on its own — a deliberately misformatted probe in `app/` came back with six fixers first, per the standing rule |
| ⚠️ **The one named as most likely wrong: the contract gate** | ✅ **HELD** — so the flagged prediction was right and an unflagged one was wrong, which is the reverse of `M65` |
| `MU6` reddens nothing | ❌ **WRONG, AND WRONG IN THE INSTRUCTIVE DIRECTION** — see below |

⛔ **`MU6` WAS PREDICTED BACKWARDS BECAUSE I APPLIED A DOCUMENT-SWEEP RULE TO A ROUTE-TABLE SWEEP.** The
claim said a discriminator mutation on an already-correct route should redden **nothing**, reasoning from
`M58`, where mutating a correct row checks a document sweep for false positives. That is the wrong analogy.
`AdminConsoleGateTest`'s new arm asserts a property over **every** parameterised console route, so
un-pinning `admin.feedback.update` — a route `R3` never touched — **must** redden it, and it did. **That
reddening is the evidence the arm is a sweep rather than three hard-coded names**, which is precisely what
the discriminator was for. The prediction was wrong; the control did its job and told me so.

### Two remedies were wrong, and one of them could not have run

- **`R2` — fatal.** The row prescribed `->withBrand(...)`. `ConnectorRulePausedNotification` used
  `Queueable` alone, so that call is `Call to undefined method`. Four edits, not one. **And the loss was
  bigger than the row's framing**: `branded()` supplies the Meridian *template* as well as the palette, so
  the class had been rendering the framework's default shell — a brand gap, not a colour gap.
- **`R1` — would have shipped a defect.** *"The code edit and the test edit are the same edit"* — but the
  middleware ended in a bare `redirect()`, so mounting it on a group whose clients send
  `Accept: application/json` would have answered with markup. The JSON arm had to come first.

⛔ **AND `R2`'S ROOT CAUSE WAS A GATE THAT COULD NOT SEE THE CLASS.** `scripts/job-payload-lint.php`'s
EXEMPT_JOBS had carried it since H16a; `QueuedMailContractTest`'s list never did — and it is that test which
asserts the trait is present. The two lists are hand-maintained, the test's own docblock warns that adding
a class means adding it to both *"or two separate gates fail"*, and that is a description of what had
already happened. The eleventh entry is appended; **the gap is filed rather than closed**, because proving
two lists agree is a decision about where such a gate lives.

### Proven by mutation — eight, each reddening only what it should

| | Mutation | Red |
|---|---|---|
| `MU1` | mint mount commented out | structural **and** behavioural arms |
| `MU2` | JSON arm removed | behavioural only — **structural stayed green** |
| `MU3` | `withBrand()` removed | the new brand assertion |
| `MU4` | trait removed | `QueuedMailContractTest`'s trait arm |
| `MU5` | one `whereUuid` dropped | structural **and** behavioural |
| `MU6` | a route `R3` never touched, un-pinned | the sweep — see above |
| `MU7` | `R4`'s fix reverted | the network **and** resolving cases; **terminal case green** |
| `MU8` | `R4`'s terminal arm removed | the terminal case **only** |

✅ **`MU2` AND `MU7`/`MU8` ARE THE PAIRS WORTH KEEPING.** `MU2` reddened the behavioural case while leaving
the structural one green, which is `M43`'s pairing demonstrated rather than asserted — the mount was still
there, only its behaviour changed. `MU7`/`MU8` are mutation-distinct in opposite directions, and `MU8` is
the one that matters: without it, *"stop saying the form is gone"* could be satisfied by never saying it,
turning a genuinely dead form into a check-your-connection lie.

⛔ **`R4`'S CONTROL IS HAND-ROLLED AND THAT IS AN OPEN ROW, NOT A CHOICE.** `scripts/mutate.php` is
Pest-in-a-container only. `M62`'s discipline was reimplemented at the call site — baseline first, restore in
a `finally`, sha256 must move and must return to its exact original value, decode with errors replaced.
⚠️ **My first control script printed CAUGHT for both mutants and named no tests**, because its
failure-name parser never matched Vitest's output format. The verdict was sound (exit codes) but the
evidence for **mutation-distinctness** — the thing the test comments assert — was missing. Re-run with a
working parser rather than left as a claim.

### Three rows filed, each the moment it was decided

- **The Fortify group carries no org-2FA gate.** `R1` named one route; the whole Fortify group serves
  tenant subdomains with no `EnforceTenantTwoFactor`. **Deliberately not a group-level mount** — the 2FA
  enrolment endpoints must stay outside, which is the structural escape hatch the middleware's docblock
  calls the whole design. **Live.**
- **A failed conflict-review recovery can strand the only copy of the answers.** The lifecycle defect
  behind `R4`'s copy. **Live.**
- **Nothing proves the two queued-mail lists agree.** `R2`'s root cause. **Live.**

### Corrections on the record

- Two of `R1`'s own claims were false and are recorded at the row: the middleware has **no alias** (so the
  `StepUpReauthenticationTest` manifest shape it prescribes does not transfer), and the phrase it quotes as
  repository text — *"gate the mint, not the bearer"* — **appears nowhere in the tree**. It is the row's
  own coinage, and it read like a citation.
- `R2`'s citation was **never** correct in the row's life; `R4`'s had rotted by 33 lines, inherited
  un-remeasured from the **closed** row's pre-`M14` sweep list. Both repaired; the ledger held at exactly
  18 of 18 against its ceiling.
- A first draft of the `R1` row closure left **two** `Filed by` clauses in one bullet, which
  `BacklogProvenanceTest`'s filer arm requires to be exactly one. Caught by running the gate rather than by
  reading the edit.

### The four rows as claimed — evidence and remedy, per row

Retained verbatim from the claim rather than folded into the narrative above. `D13` records that
collapsing four rows into one account destroys the property batching has to preserve: **a row's
evidence and its remedy are separately trustworthy, and the remedy is the half nobody checks.** Two of
the four below prove it again.

### `R1` — Evidence verified

| | Row says | Tree says |
|---|---|---|
| `routes/api.php:73-89` is the mint group's middleware array | yes | ✅ **held**, exact |
| `EnforceTenantTwoFactor` absent from it | yes | ✅ **held** — and not imported in the file at all |
| Group B carries no 2FA gate either | yes | ✅ **held** (`:109-128`) |
| The middleware is an enrolment nudge by its own docblock | yes | ✅ **held**, verbatim at `EnforceTenantTwoFactor.php:19-22` |
| Its escape hatch is a route outside its own group | yes | ✅ **held** (`:34-40`, `routes/tenant.php:970-994`) |
| Token abilities capped at the issuer's RBAC | yes | ✅ **held** (`routes/api.php:92-96`, `ApiV1Test.php:255-267`) |
| Nothing would catch it silently coming off | yes | ✅ **held, and this is the row's real motivation** — `tests/Pest.php:931-932` and `GroupBPolicyGateTest.php:41` both **exclude** `api.v1.tokens.*` from the Group-B sweep |
| `routes/api.php:80-88` carries the mint-vs-bearer argument | yes | ⚠️ **off by a paragraph** — it is `:79-83`; `:84-87` is a different paragraph about the JSON arm |
| *"gate the mint, not the bearer"* | quoted as repo text | ⚠️ **the row's own coinage** — the phrase exists nowhere in the tree |
| *"(or its alias)"* | implied | ⚠️ **false** — there is no alias; `bootstrap/app.php:158-176` registers nine and this is not one |

### `R1` — Remedy verdict

⛔ **WRONG AS PRESCRIBED, AND MOUNTING IT VERBATIM SHIPS A DEFECT.** *"The code edit and the test edit are
the same edit: mount it on Group A"* is false. `handle()` has **no `expectsJson()` branch** — it ends
`return redirect()->route('two-factor.required')` (`:105`). An unenrolled member under enforcement calling
`POST /api/v1/auth/tokens` would receive a **302 into HTML**, which is precisely the failure
`routes/api.php:85-87` says that group exists to prevent.

✅ **The correct remedy is two code edits and the repository already contains its exact template.**
`EnsureVerifiedEmail.php:109-111` is the sibling gate on the same group, and its docblock supplies both
halves: `:40-43` names this gate as the one that *"tolerates exactly that"* while `EnsureVerifiedEmail`
*"simply does not create it"*; `:45-50` records that on `/api/v1/*` **and only there** the exception
becomes the documented `forbidden` envelope, and that **"neither moves `openapi.json`"**.

**The one behaviour change, stated before the run:** `GET /notifications` currently 302s an unenrolled
member and `EnforceTenantTwoFactor.php:54-60` deliberately tolerates it. It becomes a 403 the same client
already swallows — and still serves no notification content to someone being told to enrol, which is the
property `:59-60` insists on. That paragraph is rewritten in the same edit rather than left false.

**The prescribed test shape only half-transfers.** `StepUpReauthenticationTest:115` anchors on
`getMiddlewareAliases()`, which has no analogue for an alias-less middleware.
`TenantTwoFactorEnforcementTest.php:145-160` is the right template — FQCN via `gatherMiddleware()`, with a
non-vacuous enumeration floor already at `:172-184`.

⛔ **AND A BEHAVIOURAL TEST HERE PASSES VACUOUSLY.** `SettingKey.php:73` defaults
`security.require_two_factor` to `false`, nothing in any factory or seeder writes it, and every Group-A
fixture user is unenrolled — so a new case that forgets `requireTwoFactor()`
(`TenantTwoFactorEnforcementTest.php:69-78`) passes against an **unmounted** middleware. The manifest arm
is the one that can actually fail.

---

### `R2` — Evidence verified

| | Row says | Tree says |
|---|---|---|
| The send is unbranded | yes | ✅ **held** |
| At `DeliverConnectorMessageJob.php:330` | yes | ⚠️ **rotted, and was NEVER right** — the site is `:382-383`; `:330` is a blank line inside `finishCredentialRejected()`. It was `:325` when introduced (`f5ec530`), `:343` after `M5`, `:383` since `M6` |
| It is the **only** unbranded tenant-facing connector email | yes | ✅ **held, and stronger than the row states** — eight sends are branded; three more are unbranded **by design**, with the intent stated in-line at `app/Models/User.php:152`, and all three still render the Meridian shell. This is the sole omission |
| A branded tenant gets one branded and one product-default email from the same job | yes | ✅ **held** — the branded sibling is 23 lines above at `:356-360` |

### `R2` — Remedy verdict

⛔ **WRONG, AND FATAL — *"one argument"* is not what this is.** `ConnectorRulePausedNotification` does not
use `CarriesTenantBrand` **at all**: it declares `use Queueable;` alone (`:39`) and its `toMail()`
(`:54-62`) returns a bare `MailMessage`. **`->withBrand(...)` on it today is `Call to undefined method`.**
It is the only one of ten notification classes missing both the trait and the `branded()` call.

The loss is also **template-level, not colour-level**: `CarriesTenantBrand::branded()` (`:80-87`) supplies
`markdown('mail.notification')` **and** `theme('meridian')`, and `config/mail.php:142-146` sets no global
theme — so without it the tenant loses the whole brand shell, not a palette.

✅ **Four edits, and the fourth is the root cause rather than a side-effect.** `scripts/job-payload-lint.php`
EXEMPT_JOBS carries **eleven** notification entries including this class (`:102`); `QueuedMailContractTest`'s
list carries **ten** and omits it. That test's own docblock (`:37`) says adding a queued mail notification
means adding it in **both** places *"or two separate gates fail"* — a discipline broken exactly once, for
exactly this class, and its case at `:100-101` is the assertion that would have caught the missing trait.

**No hazards, checked rather than assumed.** `$this->tenantId` is a non-nullable promoted `string`;
`BrandPalette::forTenantId()` (`:133-142`) returns `product()` for null or empty and `forTenant()` fails
closed when the key disagrees with `TenantContext::currentTenantId()`; `notifyBlocked()` is reached only
through `handleForTenant()`, where the GUC is live. **Nothing asserts the unbranded shape**, so nothing
goes red on the fix — `GoogleSheetsDeliveryTest.php:234, 265` assert the class name only.

---

### `R3` — Evidence verified

| | Row says | Tree says |
|---|---|---|
| `suspend`, `reactivate`, `assign-plan` bind `{tenant}` unpinned | yes | ✅ **held** — `:58`, `:59`, `:62`; a `whereUuid` sweep of `routes/` returns five hits and none is these |
| The two routes around them pin the pattern | yes | ✅ **held** — `show` at `:56-57`, `impersonate` at `:71-72` |
| A malformed uuid 500s instead of 404ing | yes | ✅ **held** — binding is implicit, the model overrides no route key, the controller type-hints `Tenant $tenant` (`TenantAdminController.php:64, 75, 91`), and `tenants.id` is a native Postgres `uuid` (`2026_07_05_000001_create_tenants_table.php:21-22`) under `DB_CONNECTION=pgsql` (`phpunit.xml:80`). SQLSTATE 22P02 is raised before `firstOrFail()` can 404 |
| `routes/admin.php:56-63` | yes | ⚠️ **approximate** — the three routes span `:58-62`; `56-63` is the enclosing block. Nobody is misdirected |
| The docblock **justifies** the omission | yes | ⛔ **MISCHARACTERISED — it NAMES it.** `:52-55` reads *"The three POST routes below share that latent defect"*. What is stale is only its reason — *"and have simply never been reachable from a UI"* |

⛔ **THAT REASON IS FALSE TODAY, WHICH IS WHY THE ROW EXISTS.** `TenantDetail.vue:83` posts to the plan
route, `:95` to the suspend/reactivate pair, and `Tenants.vue:47` posts the same pair from the list page.
The comment is rewritten, not deleted — it is the correct diagnosis with a spent premise.

### `R3` — Remedy verdict

✅ **WORKS**, and it is the only one of the four that does. `->whereUuid('tenant')` copied verbatim from
the neighbours. UUIDv7 ids match the framework pattern; **no route anywhere takes a slug as `{tenant}`**
(the slug is the subdomain host, never a parameter); the group sets no name prefix and no `Route::pattern`,
so route names are untouched and `AdminConsoleGateTest` / `StepUpReauthenticationTest`'s enumerations are
unaffected.

**And the row stops one step short of the durable half.** `AdminConsoleGateTest` enumerates
`adminConsoleRoutes()` (`tests/Pest.php:828-835`) for **middleware only**, never for parameter constraints,
so a fourth unpinned route would land exactly as these three did. An enumerated arm goes in, in the shape
`StepUpReauthenticationTest.php:157-171` adopted after `M35` measured that a hand-written name list cannot
fail for a route it does not name — **with a floor**, or it passes by matching nothing.

---

### `R4` — Evidence verified

| | Row says | Tree says |
|---|---|---|
| The bare `catch` binds no error | yes | ✅ **held**, `RuntimeSession.vue:198` |
| A dropped connection reads as *"This form is no longer available."* | yes | ✅ **held**, `:199`, an inline literal shared with nothing |
| A terminal claim about the form, made about the network | yes | ✅ **held, and precisely stated** |
| `resources/public-runtime/components/RuntimeSession.vue:160-168` | yes | ⚠️ **ROTTED** — `handleDrift` is at `:193-201`; `:160-168` is now the `onMounted` autosave-restore block. The figure was copied from the **closed** row's pre-`M14` sweep list (`docs/feature-backlog.md:1037`) and never re-measured |
| *"a fourth fold site"* | yes | ⚠️ **mixes two taxonomies** — the closed row's fold sites fold *409 causes*; this folds *transport vs HTTP*. On that axis it is the **third** unbound `catch` of the `SaveForLater.vue` species (`:53`, `:78` fixed; `:198` not) |
| *"widening past them was declined deliberately"* | yes | ✅ **corroborated by the suite itself** — `__tests__/components.test.ts:1134-1136` names the swallow in a comment and supplies a working `fetchSchema` **specifically to steer around the catch block** |

### `R4` — Remedy verdict

✅ **WORKS, and nothing is missing to build it.** `error instanceof ApiError` already separates transport
from HTTP, and this component uses that exact split 76 lines below the defect (`:275`);
`error.normalized.kind === 'terminal'` separates a gone form from a 429 or a 500.

⛔ **THE STRONGEST EVIDENCE THE ROW IS RIGHT IS THAT THE SAME TWO CALLS ARE ALREADY WRAPPED CORRECTLY
THREE TIMES.** `remint()` then `fetchSchema()` appears at four recovery sites; `App.vue:411-431` and
`lib/replay.ts:238-246` / `:275-281` all bind the error and choose copy from it. `handleDrift` is the only
one that does not. And the sentence itself is a convention this one site breaks: *"…no longer available"*
has three first-party sites and the other two are bound to a real 404 —
`GuestDraftResumeController.php:48` emits it as `404 draft_not_found`, and `App.vue:236-242` emits its
variant only behind `kind === 'terminal'`. **The fix is a transcription of `App.vue:236-242`, not a design.**

⛔ **AND THE ROW UNDERSTATES ITSELF IN A WAY THAT WOULD MAKE THE OBVIOUS COPY A SECOND FALSE SENTENCE.**
`handleSubmitError` discards the durable outbox row at `:233` **before** reaching `handleDrift` at `:242`
and `:259`. On the ordinary path the Dexie autosave draft still holds the answers — but in **resolve mode**
autosave is disabled by construction (`:137`, `enabled: !props.resolving`, because the durable copy *was*
the parked row `:233` just deleted). A network drop during a conflict review therefore leaves the reviewed
answers **in memory only**, and the current sentence tells that respondent to stop trying. Reassuring them
that *"your answers are saved on this device"* would be false in exactly the branch where the stakes are
highest. **The lifecycle defect is filed, not fixed** — keeping the row until recovery succeeds reaches the
sync contract `replay.ts` and the background driver share, and `D13` forbids widening mid-batch.

---

---

## RELEASED — `M65`, the schedulability sweep: liveness backfilled and gated, the triage derived (merged as PR #256, `76416b2`, 6/6 green with real step counts — Static analysis 23 · E2E 20 · Contract 16 · Frontend 12 · Pest 11 · axe 11)

**Shipped 2026-09-03.** Branch `m65-schedulability`. Closed both rows `M64` filed saying they should be
taken together, recorded `D13`, and was the first increment run under it.

⚠️ **ONE FILE WAS EDITED THAT THE CLAIM DID NOT NAME, AND IT IS RECORDED HERE RATHER THAN LEFT TO BE
FOUND.** `scripts/next.php` — one sentence in the generated hand-off that called the triage census *dated
and not the tree*, which the same increment made false. It is a small, low-risk edit and no contention was
possible with `lane-b` retired, but Standing Rule 7 wants a mid-build extension published as its own
commit **before** the file is opened, and this one was not. Every other file was as claimed.

### How the prediction fared

| Predicted | Outcome |
|---|---|
| PHPStan cannot move — no `app`, `database` or `routes` file touched | ✅ **held.** 0 errors; the diff is `docs/`, `scripts/`, `tests/` only. Said rather than quoted. |
| `tests/Feature/Docs` goes 7 → 8 passed, assertions rise | ✅ **held** — 8 passed, 51 → 67 → **74** assertions after `MU5`'s fix |
| The citation ledger stays at exactly 18 of 18 | ✅ **held** through the backfill, the closes and the insertions |
| `MU4` reddens the FLOOR alone, per-row arm green | ✅ **held**, and the assertion total dropped 67 → 66, which is `M34`'s tell |
| **Pint flags the new script at least once** | ❌ **WRONG.** It passed first time, on a 300-line script written straight through |
| ⚠️ **The one named as most likely wrong: the liveness split would invert `M37`'s** | ✅ **HELD** — `M37` found 65 of 68 live; these 30 came back **9 live · 9 latent · 12 not live** |

⛔ **THE PREDICTION FLAGGED AS LEAST TRUSTWORTHY WAS THE ONE THAT HELD, AND THAT IS WORTH MORE THAN THE
FOUR EASY ONES.** It was not a guess: the reasoning written down beforehand was that these 30 rows were
unmarked *precisely because* they are the calls nobody could make cheaply, so the population is selected
for difficulty rather than representative. The claim also wrote the falsifier — *"if the split comes back
resembling the marked population, read that as evidence the agents defaulted to `live` rather than
judged"* — and it did not.

### What the fan-out could and could not do

60 read-only agents, one judging each row against the code and one adversarially refuting it. **No agent
wrote anything.** ⛔ **5 of the 30 verdicts were changed by hand before any was written** — three because
the two passes had split systematically on the vocabulary, two more on the corpus's established usage.
Ten rows were hand-checked including all nine contested, and **two landed opposite ways**, which is the
evidence that this was adjudication rather than deference: `2265` kept the judge's `not-live` because the
repaired button guard really does stop the duplicate click, and `1880` took the adversary's `live` because
`invite()` validates address shape only — no address-ownership check exists in the controller or the
service, and `resolveOrCreateUser()` mints a global identity from an unproven address.

⛔ **THE SWEEP FOUND A ROW THAT CONTRADICTS ITSELF.** `3724` ends `**Live, and deliberately not fixed.**`
while its own body says in bold *"Not reachable today"* and then names the precondition. Marked
`**Latent**`, which is what its own evidence says.

### Conservation, and why the plan's version of it was wrong

The plan asserted `M64` achieved a zero-net-line backfill. ⛔ **It did not** — `git show --numstat` reads
`213 157`, net **+56**, with one 57-line insertion at 2900, below the highest citation. The real invariant
`M64` held is *no shift at or above the citation ceiling*. `M65` used the stricter form and proved it four
ways: line count identical, `numstat` exactly `30 30`, **all 30 hunks `@@ -N +N @@` with zero unbalanced**
— which fails on an insertion anywhere rather than only on a net one — and zero CR bytes. Then confirmed
against the tree: `UNMARKED 0`, **zero misclassifications**, the other 55 rows unchanged.

### Corrections on the record

- The claim said **9** rows pre-stated a verdict. It is **8**: the detector that produced the 9 matched
  *live* inside the word *delivery* — the same class of defect as the code-span strip this increment
  gates, found in my own instrument rather than in the corpus. Corrected in place, mid-build.
- A first draft of `scripts/backlog-triage.php`'s docblock claimed the generator *"asserts the sha is an
  ancestor of `origin/main`"*. It makes no such assertion — it reads `origin/main` directly, which is
  stronger. Writing the control is what exposed it. **A comment describing a control that is not there is
  worse than no comment**, which is an open row in this very backlog.
- A `wc`-style CR check written during the splice used `$'\r'`, which the tool layer collapsed to an empty
  pattern that matches every line — reporting 6,126 CR bytes in a file with none. `cmp` and `od`, never a
  hash through a pipe.

### Deliberately not done, filed the moment it was decided

- **`scripts/backlog-triage.php --check` has no caller.** Proved to work in both directions and nothing
  runs it; a lint sibling needs a `ci.yml` step and `ci.yml` is the user's, and wiring it changes what a
  close-out is obliged to do. `Live`.
- **`docs/backlog-triage.md` keeps a tier-1 citation exemption whose stated reason stopped being true**
  the moment it became generated. Not promoted, because the ledger has zero headroom at 18 of 18.
- **The gate checks a verdict is recorded, never that it is right**, and the error rate is now measured at
  5 of 30. Filed so agent output is never written unadjudicated.

**Namespaces spent:** `D13`. Nothing from the ADR or migration namespaces.
---

## RELEASED — `M64`, `D5`'s exit bar is not operable and the gap is provenance (merged as PR #255, `b35e0d7`, 6/6 green with real step counts — Static analysis 23 · E2E 20 · Contract 16 · Frontend 12 · Pest 11 · axe 11)

**Shipped 2026-09-02.** Branch `m64-backlog-provenance`. Every claimed file was edited except
`docs/gate-baselines.md`, which was regenerated rather than edited. The claim was **extended to
`docs/claims/decisions.md`** — `D12`, filed because the bar became answerable inside this increment —
and that landed as its own pushed commit. **No production file changed**, so PHPStan could not move;
saying that is the point rather than quoting an unchanged number.

### What the defect actually was

`D5` ends the M-series on **zero open `major` plus N consecutive increments filing none**, and its
second clause was unevaluable: provenance appeared in at least fifteen free-text shapes and most
bullets carried none. `D5` wrote down the cost in advance — *"a bar that cannot be measured is a bar
that will be declared met by whoever wants to stop"* — and `M63` then reported it as *reading* met off
a floor of 11 attributable bullets. The user's answer was **keep going and make the bar real first**.

### ⛔ THE ROW'S SCOPE WAS WRONG, AND THE CORRECTION IS WHERE THE WORK WAS

The row is written about **rows**. `D5`'s second clause asks which increment **filed** each `major`,
and **45 of the 55 `major` bullets are closed** — a `major` filed and closed inside one increment was
still filed by it. **`state.php`'s parser could not see a closed bullet at all**: its severity regex
matched `- **`major` ·` and nothing else, which is why `total_bullets` read 185 while `open` read 84.
**77 bullets existed nowhere.** Scope is 161 bullets, not 84 rows — and that is the third increment
running in which verifying the row's **premise**, not merely its evidence and its remedy, was the value.

⚠️ **Its counts were wrong in both directions**: it says *47 of 58* `major` and *45* open rows; the tree
said **45 of 55** and **41–42 of 84**. Only **5** of the 161 carried the strict form against **54**
carrying some free-text filer.

### ⛔ THE LOOSE PARSER WAS ACTIVELY MIS-ATTRIBUTING, WHICH THE ROW DOES NOT SAY

The maintenance fan-out row quotes the row it superseded under a *"THE ROW AS FILED FOLLOWS"* heading,
so `state.php` read **`M32` out of a quotation** while the row's own first paragraph says `M44` filed
it. The claim asserted this risk *measured zero*, on a check that counted bullets carrying two
different ids. **That check was too weak** — it could not see a bullet with exactly one id in the wrong
place. Backticks are what separate a record somebody wrote as a record from prose about a filing.

### The archaeology went through three forms, and the first two were wrong

| Form | Method | Why it failed |
|---|---|---|
| 1 | walk ADDED LINES | mis-attributes every bullet whose first line was **re-wrapped at close** |
| 2 | walk VERSIONS, 70-char key | the window **spills past a short title** into the body, so an editor who touched the next sentence re-attributes the row — it put `M48`'s row on `M60` |
| 3 | walk VERSIONS, key bounded by the title's own bold-close | shipped |

Three answers were established **by hand and each by a different route** — `M5` from a bare `docs:`
commit that names its increment only in the body, `M44` from the row's own text, `M48` from the
pickaxe — and asserted as **known-answer controls that abort the sweep**. That is not decoration: a
sweep whose only output is `(unattributed)` reads exactly like an honest one.

### ⛔ NO LINE WAS ADDED, AND THAT WAS NOT CAUTION

**21 line-number citations point into the backlog from 8 files** — 9 in `PROGRESS_ARCHIVE.md` (never
rewritten), 4 in `lane-b.md` (never edited), the highest at **line 2297**. **74 of the 156 lines the
backfill changed sit above it.** An insert would have rotted every one, invisibly: `citation-liveness`
checks a line is *alive*, not that it still says what the citing sentence claims. Conservation was
proved three independent ways — `wc -l` identical, `git diff --numstat` 156/156 with no net add or
remove, and a per-index check that every changed line is old-text plus suffix — and the gate's own
ledger stayed at **18 rotten against a ceiling of 18**, which is the external confirmation.

### MEASURED — 5 mutations, every red set read individually

Scope `tests/Feature/Docs`, baseline **7 passed / 51 assertions**.

| | Mutation | Red set | Reading |
|---|---|---|---|
| `MU1` | a bullet's clause deleted | the per-bullet arm | — |
| `MU2` | reverted to `Found by` | the per-bullet arm | — |
| `MU3` | a malformed id | the per-bullet arm | — |
| `MU4` | **the parser blinded** | **the FLOOR ALONE** | ⛔ the per-bullet arm stayed **green**, passing vacuously over zero bullets |
| `MU5` | a second clause on one bullet | the per-bullet arm | the quotation case |

⛔ **`MU4` IS THE ONE THAT MATTERS AND THE PREDICTION WAS WRONG ABOUT IT.** The claim named the floor
as most likely to be decorative. It is load-bearing: with the parser blinded, the arm that checks every
bullet passes over an empty set and only the floor goes red. Assertions dropped 51 → 47, which is
`M34`'s tell. Wrong in the direction of pessimism, and worth saying rather than presenting 5/5 as clean.

### How the prediction fared, including the parts that were wrong

✅ Predicted and held: PHPStan cannot move; the Static-analysis step count does not move (no new lint
step, which is what the Pest-not-lint choice bought); Pint sees `tests/` and was **proved to** by a
deliberately misformatted probe naming the new file by path, restored byte-exact by sha256.
⛔ **Wrong: the claim said the ambiguity a canonical form defends against "measures zero today."** It
measured zero on a check too weak to see it, and the `M32`/`M44` mis-attribution was live the whole time.
⛔ **Wrong: `MU4` was predicted as the likely survivor and it is the sharpest control in the set.**

### ➕ Two controls caught the AUTHOR rather than the code

- The gate's discrimination arm was written expecting **`M999x9` to be refused**. The id grammar
  accepts it — it is the shape of the real ids `J4b1` and `P3a`, and the vocabulary is deliberately not
  M-only. The assertion is **kept, inverted, with the reason beside it**, because a control that only
  ever confirms the author is not a control.
- **`git log -S$needle` — glued to its flag — returns zero hits at exit 0 on this host**, where
  `-S $needle` returns one. Uncontrolled it would have marked all 161 bullets `(unattributed)`. The same
  species bit twice more in one session: `grep -c "$(cat token)"` reported **0** for a token a
  `grep -F -f` pattern file found exactly once.

### ➕ A check this increment proved wrong by triggering it

`scripts/loop.php`'s `NOT_LIVE_MARKER` carries a comment claiming it is *"anchored on the bolded
literal, never a substring"* — and **a bolded literal inside backticks is still a substring**. The
comment names the exact failure it does not prevent. Filing a row **about** liveness coverage, whose
text necessarily quotes `**Not live**`, classified it not-live on the first run while the row says
`**Live.**`. Both readers now strip inline code spans before detection; measured **live 40 → 41,
not-live 11 → 10**, and `loop assess` **still stops** on a genuine `**Not live**` row — which is the
control that separates a fix from a disabled check.

### 👤 WHERE THE BAR NOW STANDS, AND WHY IT IS A QUESTION AND NOT AN ANNOUNCEMENT

Clause 1 **MET**. Clause 2 **MET** — the last increment that ever filed a `major` is **`M54`**, so
`M55`–`M63` is **nine consecutive** against a bar of three, with **every `major` attributed and none
`(unattributed)`**. `M63`'s floor said four; the measurement says nine.

Filed as **`D12`** with three real options and a recommendation to **keep going**. ⛔ **`state.php`
reports it and deliberately does not decide it**, and `loop.php status` says so in terms — because
`D5`'s failure mode has a twin: *"declared met by whoever wants to stop"* and *"never triggered by
whoever wants to continue"*. Two things the bar still cannot see are written into `D12` rather than
left to be discovered after stopping: it checks a filer is **recorded**, never that it is **correct**;
and severity is **self-assigned**, so "no `major` since `M54`" is equally consistent with the defects
getting smaller and with the bar quietly changing what gets called `major`.

### Deliberately not done, filed the moment it was decided

- **The liveness backfill** — 30 open rows say nothing. Provenance was a fact recoverable from git;
  liveness is a judgement against the code, one row at a time, which is the `M37` triage job whose own
  finding was that 65 of 68 rows were still live. Gating a marker nobody has decided would make it a
  formality — `M43`'s decorative-gate mistake.
- **`docs/backlog-triage.md`'s ranking** — its top three items are all `major` and all closed, while
  `CLAUDE.md` still sends every session to read it first for the ranked order.

---

## RELEASED — `M63`, a `can:` gate that names the wrong subject was invisible to every test in the repository (merged as PR #254, `b3cc7c0`, 6/6 green with real step counts — Static analysis 23 · E2E 20 · Contract 16 · Frontend 12 · Pest 11 · axe 11)

**Shipped 2026-09-02.** Branch `m63-can-gate-subject`. Every claimed file was edited except
`docs/gate-baselines.md`, which was regenerated rather than edited. The claim was **extended to two files it
did not name** — `docs/api-specification.md` and `docs/security-threat-model.md` — both because they
describe this control and both landed as their own pushed commit. **No production file changed**, so
PHPStan could not move; saying that is the point rather than quoting an unchanged number.

### What the defect actually was

`routes/api.php` gates six analytics routes on `can:viewAny,SavedReportView::class`, whose policy reads the
two dashboard keys, and **rejects `can:viewAny,Submission::class`**, whose policy reads `submissions.view`,
in a prose comment duplicated in `SavedReportViewPolicy`'s docblock. Swap the subject and **every test in
the repository stayed green** — `GroupBPolicyGateTest` discarded the payload, and both policies refuse a
role-less member identically, so no principal any test used could tell the two gates apart. The rejection
was defended by a sentence and nothing executable.

### ⛔ THE ROW'S OWN MUTATION WOULD HAVE PROVED A DIFFERENT DEFECT

`Submission` is **not** in `routes/api.php`'s `use App\Models\…` block. `Submission::class` written there
resolves to the global `\Submission`, which does not exist → `Gate::getPolicyFor()` returns null → the route
**403s everyone**, which the existing 200s already catch. The mutation has to spell the FQCN literally.
Same family as `M49`'s shell-eaten `$`, arriving through a namespace instead — and the third increment
running in which **verifying a row's premise, not merely its evidence and remedy, was where the value was**.

### The two halves, and why neither is sufficient

**Derived (`DC1`–`DC6`, no manifest, no database).** Ability parses; class subjects resolve to a registered
policy implementing the ability; **a non-class subject must be a declared route parameter**; bound subjects
resolve through `signatureParameters(['subClass' => UrlRoutable::class])`, the production binder; a bare
ability is a real permission key; policy arity is satisfiable.
⛔ **`DC3` is the sharpest and it came from reading the installed vendor code, not from the row.**
`Authorize::isClassName()` is `str_contains($value, '\\')` and `getModel()` returns
`$request->route($model, null) ?? null` — **a subject that is not a declared route parameter authorizes
against `[null]` and silently refuses every principal, forever.**
⚠️ **STATED LIMIT: none of the six can see this increment's own row.** A well-formed gate aimed at the wrong
audience passes all of them identically. That sentence is in the file's header, not only here.

**Declared (`GroupBGateSubjectTest`).** The **audience** — which of the 29 permission keys opens each of the
21 eligible gates — declared, and computed from the live policy by granting one key at a time.
⚠️ **The manifest was produced by running the oracle in print mode and deciding each computed set against
the route's intent one by one; the values were never pasted in.** 21 is a number a human can actually
review, which is why the scope is Group B and `routes/tenant.php`'s ~95 gates are filed rather than swept.
**Eligibility is class-subject or bare-ability only** — a bound-model gate's answer also depends on the row
(ownership, `resource_grants`), so there is no honest single set to declare, and pretending otherwise would
have been the weaker artefact wearing the stronger one's clothes.

### The fixture, and why the defect survived so long

⛔ **No seeded role can produce the discriminating principal.** Measured against
`RolePermissionSeeder::MATRIX`: **all five roles holding `submissions.view` — owner, admin, form_editor,
reviewer, viewer — also hold at least `dashboard.form.view`.** So the row's recipe needs a **direct
permission grant**, which it does not say. `memberHoldingOnly()` inherits its no-synthetic-role discipline
from `FormVisibilityScopeTest`'s committed-role leak, which reddened `RbacRlsTest` a hundred files away.

### MEASURED — 8 mutations, all committed, every red set read individually

Scope for every run: `tests/Feature/Api tests/Feature/Analytics`, baseline **261 passed / 1456 assertions**.
A red set is scope-bound and this one is named rather than implied.

| | Mutation | Red set | Reading |
|---|---|---|---|
| `MU1` | report subject → `App\Models\Submission` | `T2`, `T3`, oracle | — |
| `MU2` | export subject → the same | `T2′`, `T3′`, oracle | — |
| `MU3` | delete `can:` from the report route | `T1`, `T2`, oracle coverage case, structural case 1 | `T1` **is** in the set; the structural case reddening alone would have laundered it |
| `MU4` | **the MECHANISM** — `SavedReportViewPolicy::viewAny()` → `submissions.view` | the six new arms + oracle | ⛔ **the pairing test** |
| `MU5` | `savedReportView` → `savedReportViews` | **`DC3` alone** | — |
| `MU6` | `can:view` → `can:viewOne` | **`DC4` alone** | — |
| `MU7` | `tenant.settings.manage` → `…mange` | `DC5`, oracle, 2 existing | — |
| `MU8` | `can:create` → `can:view` (arity) | `DC6`, oracle, 2 existing | found the oracle's fatal |

⛔ **`MU4` IS THE ONE THAT MATTERS, AND IT IS `M43`'s PAIRING TEST PASSING.** With the policy rewritten to
read `submissions.view`, **`AnalyticsApiTest`'s Viewer 200, `AnalyticsPageGateTest`'s Reviewer case and
`M34`'s own role-less deny case all stayed GREEN.** The entire red set was the new arms plus the oracle. The
species really is new rather than a restatement of coverage that already existed.

### How the prediction fared, including the parts that were wrong

✅ **The claim named `MU5`'s "sole detector" as the thing most likely to be wrong, and it held** — `DC3` was
the only detector, and `OpenApiContractTest` did not redden it despite walking the route table.
⛔ **What was wrong was the predicted survivor.** The claim predicted **`MU6` would SURVIVE** if `DC4`
skipped the route. `DC4` caught it, so **all eight are CAUGHT and the matrix has no survivor** — a weaker
result than one with a deliberate survivor in it, and worth saying plainly rather than presenting 8/8 as an
unqualified win.
⛔ **`MU3`'s predicted red set was also wrong in one entry:** `T3` was predicted red and stayed green,
correctly — deleting the gate SERVES the `dashboard.form.view` principal, which is exactly what `T3`
asserts. A prediction that lists a test as red because the mutation is "in that area" is not a prediction.
✅ Predicted and held: PHPStan cannot move (no production file); Pint sees `tests/` and was **proved to** by
a deliberately misformatted probe naming the new file by path, restored byte-exact.

### ➕ Carried beyond the row, each recorded rather than absorbed

- **The `M34` record citing `GroupBPolicyGateTest.php:97` was annotated, not rewritten** — the sentence was
  true when written and is why the later row existed. ⚠️ **`citation-liveness-lint` structurally cannot see
  this class of rot** (it checks a line is *alive*, not that it still says what the citation claims) and it
  sits **at its ceiling**, so the repair was by hand.
- **`docs/api-specification.md` and `security-threat-model.md` §9 item 33** both described the tripwire as a
  presence check. Both now state the two halves and, more usefully, what stays residual.
- **The `D11` conversion deliberately departs from `D1`'s precedent.** `D1` left the original
  `- **`minor` · …**` bullet in place beneath the moved-to line, so `state.php` still counts it open — the
  exact miscount `D5` records as making its first clause *"not cleanly countable"*. Here the severity token
  is spent on the moved-to line and the reasoning kept verbatim below it.
- **Both rows filed by `M63` record their filer in the form `state.php` parses**, which is the convention the
  `D5` row asks for rather than merely describing.

### 👤 THE ONE QUESTION PUT TO THE USER, AND THEIR ANSWER

`D5`'s exit bar for the M-series **reads met**: zero open `major`, and no `major` bullet in the backlog is
attributable to `M59`–`M62` (the highest filer that records itself is `M49`) — four consecutive increments
against a bar of three. ⛔ **It was NOT declared met**, because `D5` set its own precondition — provenance
normalised with a lint gate holding it — and that is half-built: **47 of the 58 `major` bullets record no
filer in any parseable form.** The measurement is a floor built on 11 attributable bullets plus the absence
of a contrary one: good enough to report, not good enough to end a series on, and `D5` predicted exactly
this failure in writing (*"a bar that cannot be measured is a bar that will be declared met by whoever wants
to stop"*). **User decision of record, 2026-09-02: keep going and make the bar real first.** Filed as a row.

### ⚠️ A STANDING NOTE ABOUT THE LOCAL FULL RUN IS RIGHT ABOUT THE CAUSE AND INCOMPLETE ABOUT THE REMEDY

`M58` recorded that `php artisan test`'s subprocess workers do not inherit `-d memory_limit`, so a full
local run fatals mid-suite while **the pipe reports exit 0** — and prescribed `vendor/bin/pest` in process
instead. Measured here: **the in-process form hits the container's 128 MB cap too**, dies with
`Allowed memory size of 134217728 bytes exhausted`, and **still exits 0**. Running it in process is what
makes the cap *liftable* (`php -d memory_limit=-1 vendor/bin/pest`), which is the half the note does not
say. The failure is silent either way, so **the exit status of a local full run is not evidence of
anything** — the capped run and the lifted run BOTH exited 0, and only one of them had actually finished.
✅ **Measured with the cap lifted: `2 warnings, 4342 passed (18453 assertions)` in 1524s**, which is the
first full local green this lane has been able to state as a number rather than defer to CI. CI's Pest job
remains the authority and passed at 11 steps; the value of the local run is that it now exists.
---

## RELEASED — `M62`, the encode page silently discards typed work, in two independent ways (merged as PR #253, `df48e1b`, 6/6 green with real step counts — Static analysis 23 · E2E 20 · Contract 16 · Frontend 12 · Pest 11 · axe 11)

**Shipped 2026-09-02.** Branch `m62-encode-typed-work-loss`. Every claimed file was edited except
`docs/gate-baselines.md`, which was regenerated rather than edited. The claim was extended to nothing.
`docs/feature-backlog.md` closed **two** rows and gained **two**, one of them a defect on a third code path
that neither row named.

### ⛔ THE SECOND ROW'S IMPLIED REMEDY WOULD HAVE TURNED A VISIBLE REFUSAL INTO A SILENT LOST UPDATE

*"Return it as a validation error so `preserveState` holds"* is right about the errors bag and wrong about
what it is sufficient for. `preserveState` gates the component **re-key**, never the **props**:
`swapComponent` in the installed `@inertiajs/vue3` assigns the new page object to `page.value`
unconditionally. `EncodeFormPresenter::present()` reads `editing.baseline` off the stored row on every
render, so `back()` re-renders carrying the *other* editor's checksum, the preserved page adopts it, and the
next Save matches and blindly overwrites the change just refused.

**Shipped:** the errors bag *plus* a component-local snapshot of the render-time baseline, never re-synced.
The second Save is refused too, and that is the feature — the editor keeps every character they typed and
the client can never write over a document it has not seen.

⚠️ **Read at the installed vendor build, not from the documentation.** This is the M30 discipline paying
out: the behaviour that decides the whole design is four lines of `@inertiajs/vue3`, and no amount of
reasoning about `preserveState`'s *name* would have produced it.

### ⛔ THE PREDICTED SURVIVOR WAS WRONG, AND BEING WRONG IS WHAT FOUND THE REAL GAP

The claim predicted, in writing, that inverting the `preserveState` predicate would **survive** — on the
argument that the existing 422 cases exercise the same predicate and would redden anyway. It was **CAUGHT,
by exactly one test: the new one.** The prediction's premise was false. **No existing case ever read that
predicate at all** — every 422 case asserts rendered output and none of them inspects the third argument to
`router.patch`. A prediction that assumed coverage discovered its absence by failing.

### ➕ ONE MUTATION DID SURVIVE, IN THE FUNCTION THIS INCREMENT REWRITES

`dispose()`'s outer gate `dirty || debounceTimer !== null` narrowed to `&&` left **all 22 cases green**:
every dispose case in the file happens to have both true at once, so the `||` arm was load-bearing and
pinned by nothing. The separating state is a 5xx — it sets `dirty` and deliberately does not re-schedule, so
a keyer who hits a server error and clicks away has unsaved work and no armed timer, and under `&&` it is
dropped in silence. **Pre-existing, not written here**, and closed anyway: a known-survivable mutation in the
function an increment is rewriting is not something to hand on. The mutation now reddens exactly that case.

### ⛔ A COMMENT ASSERTED THE PROPERTY WHOSE ABSENCE WAS THE BUG

`postKeepalive()`'s docblock claimed routing through the injected `post` kept *"the single-flight
bookkeeping … not bypassed in the one place that would be hardest to notice."* Neither branch touches
`inFlight` or `pendingWhileInFlight`. It is the most likely reason the race survived review, and it is the
same shape as `M61`'s stale header comment — **the second consecutive increment whose defect was protected
by a sentence describing the code as safe.** Corrected in place, with the measurement in it.

### ⚠️ THE VITEST MUTATION HARNESS'S OWN FIRST DRAFT LEFT TWO MUTANTS STACKED IN THE TREE

`scripts/mutate.php` drives Pest only, so its discipline was reimplemented at the call site (tokens from
files, exactly-once, sha256 must move, byte-exact restore, baseline first). **The first draft decoded the
subprocess output with the Windows console codepage and raised `UnicodeDecodeError` AFTER the write and
BEFORE the restore** — `Encode.vue` was left carrying V-1 and V-2 together, and the only reason it was
caught is that the harness prints the sha256 chain and the second run's *before* did not match the first
run's *before*. The restore now lives in a `finally`. ⛔ **A harness that can abort between write and restore
silently corrupts the tree it is measuring**, and this is the third variant of the M9/M31/M49 family in the
ledger — the failure keeps moving, from the shell eating a token, to the flag that does nothing, to the
harness aborting mid-cycle.

### ✅ THE GATES

**8 mutations, 7 CAUGHT first time, 1 SURVIVED and closed.** Three through `scripts/mutate.php`
(each with a byte-exact restore verified by sha256), five through the reimplemented harness.
Vitest ran clean with its **file count equal to the figure in `docs/gate-baselines.md`** — the equality is
what proves no chunk was silently SIGKILLed, and the figure is deliberately not copied here. Pest was run
scoped, not full: `tests/Feature/Submissions` and `tests/Feature/Guest`, all green (a LOCAL full-suite Pest
number is not CI's, and the baselines file says by how much). Pint proved scanned by a deliberate probe that
made it FAIL first, at the bare whole-project file count that file records. **PHPStan moved by exactly
zero**, and it was *measured* — `git show origin/main:<path>` over the one changed `app/` file, re-analysed,
then restored — rather than asserted from the shape of the diff.

⚠️ **The prediction's other half held.** PHPStan was predicted not to move, and did not. The Pest risk named
in the claim — that adding an errors bag would redden an existing case asserting a clean session — **did not
happen**; no existing case asserted that, which is the same absence-of-coverage finding as the predicate.
The Vitest microtask risk was real but landed as a harness defect rather than a test defect.

### ➕ FILED, NOT FIXED — A DEFECT ON A THIRD CODE PATH NEITHER ROW NAMED

`submit()` calls `autosave.dispose()` and then `router.post(…)` carrying the **same**
`base_content_checksum`. Both reach `updateDraft()`, serialize on its `lockForUpdate`, and the loser is
refused — there is no idempotency escape for identical content, the guard compares checksums only. If the
keepalive wins, and it is dispatched first, **the Submit is refused**, telling a keyer their draft was
changed somewhere else on a page with no somewhere else. ⚠️ **Read, not run** — which request actually wins
is timing, and the row says so rather than claiming a measurement. Found by opening the closed row's
citation and reading what sat beside it: **the row is a floor**, for the fourth increment running.

---

## RELEASED — `M61`, share-slug lookup is case-sensitive, so a mixed-case share URL 404s (merged as PR #252, `6292827`, 6/6 green with real step counts — Static analysis 23 · E2E 20 · Contract 16 · Frontend 12 · Pest 11 · axe 11)

**Shipped 2026-09-02.** Branch `m61-share-slug-canonicalization`. Every claimed file was edited except
`docs/gate-baselines.md`, which was regenerated rather than edited. The claim was extended to nothing, and
`docs/feature-backlog.md` gained a **fourth** filed row the claim did not anticipate — for a harness defect
found while running the gates rather than while doing the work. See *the gate that exits 0*, below.

### ⛔ THE ROW'S REMEDY WOULD HAVE SHIPPED A WORSE DEFECT THAN THE ONE IT CLOSED

The row prescribed *"one call at each of the two lookups, not a migration."* Applied as written that turns
the 404 into a 200 **and leaves the mis-cased URL in place** — and that URL is a storage key in four
independent client systems: the service worker's `guest-shell-html` Cache Storage entry (Workbox keys by
full request URL), the Dexie `draft_answers` compound primary key, the outbox row's `slug` column, and the
installed PWA's `id`/`start_url`/`scope`. The raw casing reached all four because `mint()`'s view arm
emitted the **request path** while `resume()` directly beside it emitted the canonical value — **the two
arms disagreed by construction**, which is this row seen from its other end.

Concretely: install from `/f/Clinic-Intake`, the shell caches under that URL, `start_url` resolves to
`/f/clinic-intake`, and **the installed app is a cache miss offline** — the one trade `brand-cache.ts`
argues in its own header must never be made. A remedy that fixes a 404 by creating a silent offline
failure is worse than the defect it closes, and nothing in the row hints at it.

⚠️ **The evidence half held in substance and was wrong in its vocabulary.** `share_slug` — the identifier
the row uses throughout — **does not exist in executable source**; the column is `forms.public_slug`. Two
more: *"writes are constrained by the regex, so no uppercase slug can be stored"* is true of the HTTP
surface and **stated as an invariant of the column**, which `FormService::setShareSettings()` did not hold
up; and a **third** unlowered resolver sat beside the two named (`FormSlug::isTaken()`). The row is a floor.

### ⛔⛔ CI CAUGHT A DEFECT THE LOCAL SUITE STRUCTURALLY COULD NOT, AND THIS CLAIM HAD PREDICTED IT

The Pest job was red on **exactly one test of 4,657** — the new maintenance case, `500` not `503`, from
`ViteManifestNotFoundException` raised inside `MaintenanceResponse::make()`. The maintenance blade carries
`@vite(['resources/css/app.css'])`.

**This claim's stated most-likely failure was that exact mechanism**, and it still happened, because
`TenantMaintenanceTest`'s own header comment said *"the paused cases render the maintenance blade instead,
which is why they were green without it."* **That sentence is false** — and the code directly beneath it
calls `$this->withoutVite()` on every paused case anyway, so the comment described a habit rather than the
reason for it. ⛔ **Predicting a trap does not protect you from a document that tells you the trap is not
there.** The comment is corrected rather than deleted, with the measurement in it.

⚠️ **It cannot fail locally.** This host has a `public/build`, and `public/hot` besides — Laravel's Vite
helper checks `hot` first and never reads the manifest, so the local suite passes for two independent
reasons. Reproducing CI meant removing **both**.

### ⛔ AND THE FIRST CONTROL FOR THAT FIX WAS A FALSE GREEN

Proving the `withoutVite()` fix meant removing it again and watching the test go red. The first attempt
used `php -r` in a **double-quoted** shell string; the shell ate `$this`, `php` fataled on *"Using $this
when not in object context"* — printed above the test output and easy to skim past — the mutation never
applied, and the run reported **1 passed** against the *fixed* file. **A control that never applied looks
exactly like a control that survived.** This is the M49 class, third occurrence, and the fix is the one
`scripts/mutate.php` already implements: tokens from files, no shell in the path. Redone through it under
the reproduced CI asset state: baseline 8 passed, mutant reddens exactly that one test.

### ✅ THE ONE DESIGN DECISION THAT PAID FOR ITSELF IN A MUTATION

The manifest route deliberately does **not** redirect — it lowers its lookup and emits a canonical `scope`.
Four reasons were written down first, and the fourth turned out to be the measurable one: serving a
mis-cased manifest URL **200** makes `$scope = '/f/'.$form->public_slug` a behavioural property, and its
mutant goes red. Under a redirect that mutant would have SURVIVED and the defect would have been invisible.
**Choosing the smaller change made the gate stronger**, which is the opposite of the usual trade.

### ⛔ THE REDIRECT'S POSITION IS THE WHOLE SECURITY ARGUMENT, AND IT IS PINNED THREE WAYS

Placed before the 404 gates the redirect is an **existence oracle**: `301`-then-`404` tells a prober the
slug exists, which is exactly the distinction both controller docblocks say those gates return
404-never-403 to prevent. Guarded by one test per gate, each asserting `assertHeaderMissing('Location')`
rather than only the status — a `302`-then-`404` variant reads as a pass otherwise — and proven by a
mutation that hoists the block above the guest and published gates and reddens exactly those two cases.
⚠️ **Hoisting it into middleware would make the extra `throttle:guest-mint` hit free**, which is the
tempting version and the one that trades the oracle away. Named in the docblock so the trade stays visible,
and a throttle test pins the double-count as a stated property rather than leaving it to be discovered.

### ✅ TEN MUTATIONS: NINE CAUGHT, ONE SURVIVED AS PREDICTED IN WRITING BEFOREHAND

`M-9` — restoring `'slug' => $slug` in the view arm — was **declared a predicted survivor in the plan and
in the claim before it was run**, and survived. Once the redirect exists that arm is reachable only when the
two expressions are equal, so the change is defence-in-depth whose value is that the redirect's absence is
not catastrophic. **No contrived test was written to force it red.** `mutate.php`'s own header says a
survivor is a finding to file rather than explain away, and filing it *as predicted, with its reasoning* is
the difference between a hole in the suite and a deliberate belt-and-braces.

⚠️ **`M-8` is the one worth copying.** It moves the service's lowering **below** the audit arrays: the
database still ends up lowercase, so the column assertion stays green, and only the audit assertion catches
it. The tell was the assertion COUNT — baseline 100, `M-7` 97, `M-8` 98. One assertion further in, i.e. a
*different* assertion failing. **A control that reddens the same test for a different reason is not the
same control**, and the count is the only thing that says which.

### ⛔ THE GATE THAT EXITS 0 — FOUND WHILE RUNNING THE GATES, NOT WHILE DOING THE WORK

`CLAUDE.md`'s gate table and two design documents all prescribe `docker compose run --rm e2e`. On this host
that needs **three** undocumented prerequisites, and the most natural wrong form is silent: appending
`npx playwright test ...` passes `npx` as the CLI's subcommand, because the compose service's `entrypoint`
is already the Playwright CLI. It prints `error: unknown command 'npx'` and **returns exit code 0.** That is
the succeeds-on-empty-input class this project has now measured four times, and it is the one that would
launder a skipped e2e run into a claim. The other two: Node cannot resolve `acme.localhost` inside the
image while `curl` can, so the probe fails, Playwright tries to boot `php artisan serve` and dies on
`php: not found` — **the message names PHP and the cause is DNS**; and `public/hot` must be removed and the
assets built, or the shell points `@vite` at a dev server in a container this one cannot reach and
`global-setup` times out on the login field, which reads as a broken login page. **Filed.**

### ✅ A LOCAL E2E RED THAT THE CONTROL SETTLED IN THE OPPOSITE DIRECTION

The two `public-runtime-offline.spec.ts` cases failed locally on some viewports and passed on others —
45 passed, 3 failed. The control (same spec, this increment's five `app/` files reverted to `origin/main`)
returned **6 failed, 0 passed**: strictly worse, and it failed the *tablet* cases that had passed with the
change applied. ⛔ **Reverting a change cannot cause failures that change fixes**, so the only consistent
explanation is run-order-dependent state in this host's long-lived dev database, degrading across
consecutive runs. CI's E2E job then passed. **Two runs of the same spec on this host are not two
measurements of the same thing** — which is the sharper form of the lesson `M19` recorded.

### ⚠️ A GO/NO-GO WHOSE FIRST ANSWER WAS MEANINGLESS

The pre-deploy check for pre-existing mixed-case rows returned **empty under `meridian_app` — and that role
sees zero rows at all**, because RLS scopes it to a null tenant. An empty result from a role that can see
nothing is not evidence of absence; it is the same shape as the three splices that read a missing file and
reported success. Re-run under the bypassing role, with a discriminator control proving the predicate fires
on a deliberately mixed-case value: **20 forms, 10 slugs, all lowercase.** ⛔ **This host's database is not
any deployed one**, so the check is filed as a per-environment obligation rather than discharged.

### THE PREDICTION, INCLUDING WHERE IT WAS WRONG

- ⛔ **WRONG: "PHPStan *will* move — four `app/` files and a widened union return type."** It did not.
  Measured rather than counted: the five files swapped to their `origin/main` versions and re-analysed in
  the same container, the error sets are **byte-identical, delta zero**. The local errors are the known
  model-property phantoms and none is in a touched file. **Predicting a gate will move is as much a claim as
  predicting it will not**, and this one was made from the size of the diff rather than from what the gate
  reads.
- ⛔ **WRONG, and it was the flagged risk, in the exact predicted MECHANISM and the wrong FILE.** The claim
  said the likeliest miss was a shell assertion missing `withoutVite()` — it named `GuestRuntimeTest`, which
  got the guard, and the one that needed it was `TenantMaintenanceTest`. See the CI section above.
- ⛔ **WRONG in one branch: `M-2`'s predicted survivors.** Case 5 (shell boot) stayed green as predicted;
  case 6 (the throttle case) went red, because it asserts `301` on its first request and a lookup-only fix
  returns `200`. Half right, and the half that was wrong was the half I reasoned about least.
- ✅ **RIGHT: no PRE-EXISTING test broke.** Every request in the five touched suites is canonical, so the
  redirect branch is unreachable for all of them. The one red was a test this increment wrote.
- ✅ **RIGHT: Pint, the lint gates, Vitest and axe unmoved.** No front-end file is in the diff, so two of
  them cannot move by construction — said, rather than quoted as an unchanged number.
- ✅ **RIGHT, exceeded: 16 new cases** against a predicted ten to twelve, and **10 mutations** against a
  predicted nine. The oracle guards multiplied once *one per gate* became the rule rather than *one per
  route*.


## RELEASED — `M60`, the `## Current Status` tracker surgery, the last `major` row (merged as PR #251, `55c6409`, 6/6 green with real step counts — Static analysis 23 · E2E 20 · Contract 16 · Frontend 12 · Pest 11 · axe 11)

**Shipped 2026-09-02.** Branch `m60-current-status-surgery`, three phases. Every claimed file was
edited except `docs/gate-baselines.md`, which was regenerated rather than edited, and the claim was
extended to nothing — no mid-build extension was needed, the first increment in a while where that is
true.

**What moved.** `PROGRESS.md` lines 196–231 — **36 lines, 102,115 bytes, `M56` down to `M29`** — into
the **head** of `PROGRESS_ARCHIVE.md`'s existing `## Archived status bullets` section. Head and not
tail, because that section's first bullet is `M26` and appending would have inverted the newest-first
order it has always had. `PROGRESS.md` **196,030 → 94,757 bytes (−51.7%), 323 → 287 lines**;
`## Current Status` from **110,268 bytes (56.3%, its largest section) to ~8,100 (~8.6%)**. The
heading, the newest three bullets and a rewritten pointer survive — `R2` forbids deleting the heading,
which is the choice `M41` made and recorded. `TRACKER_BYTE_CEILING` ratcheted **200,000 → 130,000**.

### ⛔⛔ THE ROW'S PREMISE WAS THE WRONG THING, AND NEITHER CLAIM FIELD WOULD HAVE CAUGHT IT

`M36` added `Evidence verified` and `Remedy verdict` because four consecutive rows had sound evidence
and a broken remedy. **This row had a third failure mode neither field asks about.** Its central
instruction — *"IT MUST NOT BE PLANNED AS A REPEAT OF EITHER PREDECESSOR… Rule 7(b) gives each lane
its own status block, so the section has two writers; a slice that moves both lanes' bullets is the
one thing the boundary forbids"* — is not evidence and not a remedy. It is the **premise the row's
scope was derived from**, and it expired **two days after the row was filed**: the row is dated
2026-08-29, `M50` retired Lane B on 2026-08-31. `lane-b.md` reads *"READ AND NEVER WRITTEN"*, its
status is `RETIRED`, it holds no forward queue, and Rule 7 itself carries a superseded banner.

⚠️ **`M59` had already noticed and deliberately did not conclude:** *"It has now been noticed three
times and measured never; measure it."* That is the right instinct written down — a suspicion handed
forward as an obligation rather than as a fact — and it is why this cost ten minutes instead of an
increment.

### ⛔⛔ EVERY FIGURE THE ROW STATED WAS STALE, ALL FOUR IN THE SAME DIRECTION, AND IT INVERTED THE PRIORITY

The row says 67,982 bytes and 42.1% of a 161,298-byte file. Measured when it was taken:
**110,268 bytes and 56.3% of 196,030** — the section had grown **62%** in four days. The consequence
is not cosmetic: `R1`'s ceiling is 200,000, so the headroom was **3,970 bytes — about ONE ordinary
close-out from a red trunk.** **A row that reads as tidiness was one increment from blocking every
merge**, and nothing in its text said so. `state.php` counts the tree for rows and decisions but not
for the *contents* of a row, and this is the gap that leaves.

### ✅ THE END-TO-END SQUASH PROOF, OWED SINCE `M47` AND FORFEITED BY `M48`, IS DISCHARGED

Post-merge run on `main` (`33586412469`), the line itself:

```
tracker-lint: DECLARED SURGERY: 36 line(s) and 101,273 byte(s) removed from PROGRESS.md
tracker-lint: R7 base is 54bb8bd… (github.event.before, via TRACKER_LINT_BASE_SHA)
```

**No surgery had ever produced this.** `M41` reddened `main` by emptying the `--body`; `M45` merged
with the marker present twice and the gate matching nothing, because GitHub's default body renders
each subject as `* <subject>`; `M48` pushed the branch to `main` and forfeited the squash entirely.
The form that worked is an explicit `--body` whose **first content line** is the marker, verified
against **the gate's own regex before merging rather than after**.

⚠️ **And the byte arm fired ALONE** — 36 lines against a 200 limit, 101,273 bytes against 50,000. The
line threshold is blind to a surgery that removes half the file, which is exactly why `M48` added the
byte half.

### ⛔ A HAND-FORWARD IN THE ROW ABOVE IT IS UNSATISFIABLE, AND ONLY THE INCREMENT IT NAMED COULD FIND IT

`M49`'s row says the first real exercise *"is the `## Current Status` surgery, **which is a
multi-commit push**"*. It is not. **A squash merge puts exactly one commit on `main`**, so
`github.event.before..HEAD` holds one commit and is identical to `HEAD~1..HEAD` — the same collapse
that row identifies for `M49`'s own merge, one paragraph earlier, then assumes away for this one. The
only way to push several commits to the trunk is `git push origin HEAD:main` on a loaded branch, which
is what `M48` did and what `pre-push-guard` now refuses at `MAX_DIRECT_TRUNK_COMMITS = 1`. **So `R7`'s
multi-commit `push` range is structurally unreachable on `main` by any compliant push**, and the row
is corrected in place rather than closed.

### How the prediction fared

**Right:** `R7` fired on the byte arm alone, at 2.0× the limit; `R1`, `R2`, `R4`, `R5`, `R6` stayed
green; PHPStan could not move; every other gate count was unchanged. **Right for the reason given:**
byte conservation was named as most likely to fail — `M41` predicted the same and **failed by exactly
one byte at the join seam** — and it held on the first run **because `M41`'s corrected formula was
used rather than re-derived**. That is the value of a release recording its own arithmetic error.

**Wrong, and cheaply:** the claim said the slice was 102,152 bytes. It is **102,115 of content plus 36
newlines**; the earlier figure had silently folded the newlines in. The conservation identity is
insensitive to which convention is used **only if the same one is used on both sides**, which is the
whole reason the formula states the added bytes rather than inferring them.

**Wrong in a way worth keeping:** the plan asserted the moved slice was 102,152 bytes *and* that the
file would land at "~93,900". It landed at **94,757**, because the rewritten pointer is 878 bytes
larger than the one it replaced — an addition the estimate treated as zero. **A conservation check
catches this and an estimate does not**, which is the argument for having both.

### Proof and controls

**17 checks, 0 failed**, with the moved slice re-read **from git at phase 1's parent** rather than
from anything the splice wrote:

1. a counted multiset of 36 line hashes with exact multiplicity — present in the archive **and**
   decremented in the tracker. All 36 were distinct here, unlike `M48`'s 178 lines of which only 95
   were, where a set equality would have dropped 83 silently;
2. **exact byte conservation, no tolerance, added bytes stated** — `2,653,273 + 2,013 == 2,655,286`;
3. `## Standing Rules` byte-identical at `3eb6baf2be0186f1`;
4. the git-level hash `1b61ff0a` on both sides.

Phases 1 and 2 are split **to buy proof 4, not for tidiness**: nothing is removed until the archive
already holds it. `M48` collapsed both files into one commit and forfeited exactly that.

**Five positive controls**, each red when mutated and byte-identical when restored, against a green
baseline taken first: `R7` with the declaration amended out of phase 2 — it names the **byte** limit —
`R1` with the ceiling below the new size, `R4` with a `## Current Status` heading in the archive, `R5`
with one CR byte, and `R2` with the heading renamed, the heading this surgery deliberately did not
delete. `scripts/mutate.php` could drive none of them: its `--tests` argument is Pest paths through
`docker exec` with no `--command` mode, so its discipline was reimplemented at the call site.

### Two smaller things, recorded rather than filed

➕ **The ceiling comment's adjective outlived four ratchets.** Every version said *"roughly a dozen
ordinary close-outs"* and none named the rate. Measured across `M46`–`M59`: **mean 3,460 bytes per
close-out, max 4,892**, so 35,243 bytes of headroom is about **ten** at the mean and seven at the
worst. Say the number, not the adjective.

➕ **`state.php`'s prose-literal disagreement count for `PROGRESS.md` fell from 30 to 6**, because 24
of them lived inside the moved bullets — a scan of a shrinking file gets quieter without anything
being fixed. Worth knowing before reading that number as progress.

⚠️ **The surgery rotted no live citation, and that was checked rather than assumed.** Eleven
`PROGRESS.md:<line>` citations exist outside the archive; all are either below the cut point or were
**already** rotten inside dated `RELEASED` records (one names `:465` in a file that has never had 465
lines since). `citation-liveness-lint` cannot see this class — it checks a line is *alive*, not that
it still says what the citing sentence claims — so it was done by hand and cross-checked twice.

**One row closed, TWO filed, one corrected. Namespaces spent: nothing from either namespace —
twentieth consecutive.**

---

## RELEASED — `M59`, the README prescribes a design-system command that cannot work in the service it names (merged as PR #250, `34514c9`, 6/6 green with real step counts — Static analysis 23 · E2E 20 · Contract 16 · Frontend 12 · Pest 11 · axe 11)

**Shipped 2026-09-02.** Branch `m59-readme-axe-command`. Every claimed file was edited, plus one that was
not: `composer.lock` — extended mid-build, as its own pushed commit, for the advisory below.

### The row's evidence held at all five citations and its SEVERITY ARGUMENT WAS FALSE

The row sizes itself on the claim that the failure *"is the confusing kind: a native-module error from
inside a container, not a missing-server message."* **Measured, it is exactly a missing-server message** —
`test-storybook` names the `--url` flag and links its own documentation. Reason (2) fires first and
reason (1) is never reached on that path.

**The row is still `major`, for a reason it does not give:** it is the only line in that block that cannot
be made to work by fixing the reader's own tree.

### ⛔ THE PROOF THAT MATTERED, BECAUSE THE OBVIOUS TEST GIVES THE WRONG ANSWER

Run bare in the `node` service, the scan fails on an **absent** browser — which implies *"install it"* as
the remedy, and that remedy is wrong. So it was installed. `playwright install` there warns it is
*"downloading fallback build for ubuntu24.04-x64"*, and the scan then reports `spawn ... ENOENT` **with a
189 MB binary present and executable**, at 0 of 42 suites.

| Where | Result |
|---|---|
| `node`, as the README prescribed it | the missing-server message, exit 1 |
| `node`, server up, **no browser installed** | `ENOENT` — reads as "install the browser" |
| `node`, server up, **browser installed** | **`ENOENT` again, binary present and executable** — 0 of 42 |
| `e2e` image, `ci.yml`'s shape | **42 suites / 303 tests, exit 0** |

**That third row is the whole argument for changing images rather than adding an install step**, and only
the second and third together make it.

### Remedy verdict: the row's one prescription is wrong in the direction that matters

*"The working shape … is two steps, not one."* `ci.yml` is **five**, three of them one-time installs, and
its fifth is a two-process orchestration. It is also not transplantable: every native binding on disk is
linux-only, so the host recipe needs a host `ds:install` first, which J4c measured as breaking the node
container's Vite. **The user chose the `e2e`-image form on those alternatives** rather than the host form.

### Three findings that came only from running the block, not reading it

- **`--maxWorkers` is local-only and NOT optional.** Without it, 34 of 42 suites die `ENOMEM` — at *load*,
  so every test that runs still passes and the tail looks survivable. CI never showed this because a
  runner has the memory.
- ⛔ **`ds:storybook:build` FAILS AND EXITS `0`** against an incomplete package tree: Storybook's
  crash-report prompt runs after the error and swallows the status. **This is the `M27` class pointing the
  other way** — that row's lesson was *"the failure prints a success after it, trust the exit code"*, and
  here the exit code is the liar. Both now sit together in the README. Filed as its own row.
- **`ds:test` could never have been made to work as a one-liner.** The dev server that would serve `:6006`
  exists in the package and has **no root alias**, so the missing half was not merely unstarted — it was
  inexpressible in the block's own vocabulary. Filed.

### The gate, and why it is a test rather than a lint script

`tests/Feature/Docs/DocumentedCommandDriftTest.php`. Three reasons, and the third decided it: the defect
IS the document so the failure already names file, line and command; `php artisan test` discovers
`tests/Feature` so no `composer.json`, `quality` or `ci.yml` entry is needed; and ⛔ **`scripts/mutate.php`
drives Pest-in-a-container and nothing else**, so a lint sibling would have had to reimplement its
discipline by hand — which `M42` recorded as the weaker form.

Every arm is an **artifact** claim derived from `package.json` and `docker-compose.yml`; nothing asserts a
command *works*. Comment lines are skipped deliberately, or the README's own explanation of this defect —
which must name the broken command and the missing flag — would make the fix unwritable.

Proved red four ways through `scripts/mutate.php`, sha256 asserted to move and to return:

| Control | Result | What went red |
|---|---|---|
| The axe script restored to the musl `node` service | CAUGHT | the musl arm only |
| `--url` stripped from the scan | CAUGHT | the url arm only |
| A README script name that does not exist | CAUGHT | the existence arm only |
| **The fence marker changed so the block stops parsing** | **CAUGHT** | **two floors, at 4 arms / 25 assertions not 27** — the floor fired instead of reporting green over nothing |

### How the prediction fared — three right, one right for the wrong reason, one not predicted at all

- ✅ **"Static analysis and Pest both stay at their current step counts."** They did — 23 and 11 — and the
  stated reason is the right one: the gate needed no registration.
- ✅ **PHPStan, Vitest, axe and E2E cannot move.** None did.
- ⛔ **"The one I most expect to be wrong is the citation sweep."** It was wrong, and **the direction is
  the finding.** I predicted `citation-liveness-lint` could not see this class, because it checks a cited
  line is ALIVE and never that it says what the citing sentence claims. Both halves are true and **the
  conclusion did not follow**: the README grew 22 lines, a cited line was pushed *inside a fenced block*,
  and *inside a fence* is an **aliveness** rule. The gate caught it, at 19 over its ceiling of 18. It
  would still have missed a line that merely moved and stayed prose.
  ⚠️ **The repair was scoped to what this branch moved.** Three other README citations were already
  pointing at a blank line before this branch existed, and the row that carries them says its list must be
  re-derived before it is worked — so they were left. `README.md`'s first 88 lines were proved
  byte-identical to `origin/main` to establish that nothing else had shifted.
- ⛔ **NOT PREDICTED AT ALL, AND IT IS THE MORE USEFUL MISS.** The prediction enumerated *which gates my
  diff could move*, which is a different question from *which gates will be green*. `composer audit` went
  red on three `high` `league/commonmark` advisories published **2026-09-01 20:17–20:21 UTC** — after
  `M58` merged and after this branch was cut.

### ➕ The extension: `league/commonmark` 2.9.0 → 2.10.0

Taken rather than reported, because `composer audit` is a merge gate and until it landed **no pull request
in this repository could reach 6/6**. Transitive via `laravel/framework ^2.8.1`, so `composer.json` never
moved and the lock delta is one package and its version fields.

⚠️ **Regression evidence, because this is the library `Markdown::render()` runs on** — the exact surface
`M57` worked and `scripts/mail-attribute-lint.php` guards: `tests/Feature/Mail` + `tests/Unit/Mail` +
`tests/Feature/Notifications`, **161 passed, 580 assertions**.

⛔ **The extension was published by CHERRY-PICK, not `git push origin HEAD:main`** — that form pushes the
whole branch, which is how `M48` put a surgery on the trunk with no squash merge.

### ⚠️ Two mechanics that cost time and will recur

- **`git add -A` swept the `ds:install` lockfile churn into the first commit.** `packages/design-system/package-lock.json`
  loses `"peer": true` markers on four packages whenever that install runs, and `docs/claims/lane-a.md`
  has warned since `M20` to revert it rather than commit it. Caught by reading `git show --stat`, not by
  any gate. **Stage by path on any branch where a design-system install has run.**
- **The shell ate a backtick inside a `grep` argument** and the command died on an unmatched quote — the
  documented trap, met in the wild. The prose files in this increment were written through the Write and
  Edit tools for exactly that reason.

### Filed rather than fixed, each at the moment it was decided — four

1. **`minor`** · A failing `ds:storybook:build` exits `0`. The real remedy disables Storybook telemetry in
   the package script, which is untested against `ci.yml`'s axe job — CI is on a clean tree and has never
   hit this, so the change is unproven exactly where it matters most.
2. **`minor`** · The design-system dev server has no root alias.
3. **`minor`** · Three gate invocations fetch `http-server` from the network and nothing declares it — so
   the merge-blocking accessibility gate has an undeclared, unpinned dependency.
4. **`minor`** · The new gate reads `README.md` only; three other documents carry runnable command blocks,
   and widening the `docker compose exec <musl service>` arm to a deployment runbook would produce false
   positives on every line. **The corpus needs choosing before the constant is widened.**

### ORIGINAL CLAIM (`M59`)

Taken 2026-09-02. Branch `m59-readme-axe-command`, cut from `origin/main` at `03b0738`, PR into `main`.
Row: `docs/feature-backlog.md` — **`major` · The README prescribes a design-system command that cannot
work in the service it names**, filed by `M46`. **Named by title and never by line number**, because this
increment edits that file and a line number would not survive its own diff.

### Evidence verified

Five citations, five opened, five held.

- **`README.md`'s design-system block runs the axe suite as `docker compose exec node npm run ds:test`** —
  **HELD**, verbatim, and it is the last line of that block.
- **The `node` service is `node:24-alpine`** — **HELD** in `docker-compose.yml`. The service is a stock
  image with no build stage, so nothing in it installs a browser or a glibc shim.
- **`CLAUDE.md`'s gate table records the glibc-Chromium `ENOENT`** — **HELD, and the table is more precise
  than the row is.** It says *cannot run in the musl node container*, which is exactly the scope J4b's
  retraction left standing: J4b retracted *"impossible on this host"*, never *"impossible in that
  container"*. No `CLAUDE.md` edit is owed.
- **`ds:test` resolves to `test-storybook` with no `--url`** — **HELD** through one indirection:
  the root script is `npm --prefix packages/design-system run test-storybook`, and the package script is
  the bare string `test-storybook`. No flags at all.
- **`ci.yml`'s axe job does it correctly** — **HELD**: glibc runner, its own `playwright install chromium`,
  a static build, `http-server` + `wait-on` under `concurrently`, then `test-storybook --url`.

⚠️ **AND THE ROW UNDERSTATES ITSELF THREE TIMES.**
**(1) `ds:test` has no reachable server even in principle, from the vocabulary the README uses.**
`packages/design-system/package.json` *does* carry a `storybook dev -p 6006 --no-open` script — the one
thing that would make the line true — and the root `package.json` exposes **no `ds:*` alias for it**. The
missing half is not merely unstarted; it is inexpressible in the block's own idiom.
**(2) The line above it is half of the same defect.** `ds:storybook:build` writes `storybook-static` and
the block never says where that lands or that it must be served. The two lines are a broken *pair*.
**(3) The block's own prose miscounts itself** — *"the two above are the bootstrap"* sits over four
commands.

### Remedy verdict

**The row offers one prescription — *"the working shape exists and is two steps, not one"* — and it is
WRONG IN THE DIRECTION THAT MATTERS.** `ci.yml`'s axe job is **five** steps, three of them one-time
installs, and its fifth is itself a two-process orchestration. More to the point it is **not
transplantable into the `node` service under any arrangement**, because the blocker is the image.

Measured on this host rather than argued, before a line was written:

- Every native binding on disk — `@esbuild`, `@swc/core`, `@rollup`, `@rolldown`, in both `node_modules/`
  and `packages/design-system/node_modules/` — is **linux-only**. That is the `node` container's own
  `npm install` writing through the bind mount.
- So J4b's host recipe would need a host `ds:install` **first**, which J4c measured as breaking the node
  container's Vite until `packages/design-system/node_modules` is removed and the container restarted.
  **Destructive to a running dev stack, and therefore rejected as the headline command** — the user's
  call, taken on the alternatives rather than assumed.
- `http-server` is installed in neither tree. CI reaches it through `npx`, and so must anything local.

**Chosen shape: build in `node`, scan in the `e2e` image.** That service is already a glibc Playwright
runner with the repo bind-mounted, so the linux `node_modules` on disk are the *correct* ones there.
Nothing is installed on the host and the dev stack is untouched.

Files: `README.md`, `docs/feature-backlog.md`, `tests/Feature/Docs/DocumentedCommandDriftTest.php` (new),
`docs/claims/lane-a.md`, `PROGRESS.md` (Lane A's own status block only).
Shared artefacts taken: `README.md`, `docs/feature-backlog.md`, `docs/claims/lane-a.md`, `PROGRESS.md`.
Paired files taken: none.
Namespaces spent: nothing from either namespace — no ADR, no migration prefix, no sub-decision id.

Prediction, written before the run so it can be measured against:

- The Pest job's step count and the Static-analysis job's step count both stay where they are, because a
  `tests/Feature` gate is discovered by `php artisan test` and needs no `composer.json` or `ci.yml` entry.
- PHPStan cannot move — it scans `app`, `database` and `routes`, and this is a docs-and-test diff.
- Vitest, the axe suite and E2E cannot move; no SFC, story or spec is touched.
- ⚠️ **The one I most expect to be wrong is the citation sweep.** This diff changes the line count of both
  `README.md` and `docs/feature-backlog.md`, and `citation-liveness-lint` checks that a cited line is
  ALIVE, never that it still says what the citing sentence claims — the defect `M57` and `M58` each
  shipped one increment apart. I expect most hits to be **already rotten before this branch existed**, and
  I expect to be wrong about how many.

### ➕ CLAIM EXTENDED MID-BUILD — `composer.lock`, for a blocking advisory that is not this row's

**Extended 2026-09-02, as its own pushed commit before the file was opened**, and by cherry-pick rather
than `git push origin HEAD:main` — that form pushes the WHOLE branch, which is how `M48` put a surgery on
the trunk with no squash merge.

`PR #250`'s **Static analysis** job failed on `Composer audit (SCA)` while every other gate passed, and
the failure is **not this branch's**: three `high` advisories against `league/commonmark`, all published
**2026-09-01 20:17–20:21 UTC** — after `M58` merged and after this branch was cut. Two denial-of-service
and one XSS (`on*` handler filter bypassed with a U+000C form feed). Affected `<2.9.1`; this tree carries
**2.9.0**, released 4 weeks ago.

⚠️ **It is transitive, not direct** — `laravel/framework v13.18.1` requires `^2.8.1`, so `2.9.1` satisfies
the existing constraint and no framework move is needed. `composer.json` does not change; only the lock.

⛔ **TAKEN RATHER THAN REPORTED, BECAUSE IT BLOCKS EVERY INCREMENT AND NOT JUST THIS ONE.** `composer
audit` is a merge gate, so until this lands no pull request in this repository can reach 6/6.

⚠️ **AND THE ONE THING THAT MAKES IT MORE THAN A LOCK BUMP HERE:** `league/commonmark` is what
`Markdown::render()` runs on, which is the exact surface `M57` worked and `scripts/mail-attribute-lint.php`
guards. The mail suites are the regression evidence, and they are named in the release.

---

## RELEASED — `M58`, the canonical schema reference documents database-side defaults the database has never had (merged as PR #249, `220f827`, 6/6 green with real step counts — Static analysis 23 · E2E 20 · Contract 16 · Frontend 12 · Pest 11 · axe 11)

**Shipped 2026-09-01.** Branch `m58-documented-default-drift`. Every claimed file was edited, plus one that
was not claimed: `docs/adr/0008-entitlement-and-metering.md` — see *the citation I moved*, below. The claim
was not extended otherwise.

### ⛔ THE ROW'S EVIDENCE WAS EXACT AND ITS SCOPE WAS A THIRD OF THE TRUTH

Four assertions, four measured, four held — including the one that decides everything: **0 of 37 `uuid`
`id` columns carry a `column_default`.** And this server is **PostgreSQL 17.5**, so `uuidv7()` is not
merely undeclared here, *it does not exist*.

But the defect is not `uuidv7()`. It is **any function named in the `Default` column**, and the half the
row never mentions is the larger one:

| | Cells | Columns |
|---|---|---|
| `uuidv7()` on an `id` row | 32 | 32 |
| **`now()` on a timestamp row — absent from the row entirely** | **61** | **74** |
| **True, and therefore untouched** | **2** | **2** |

The second document is `docs/multi-tenancy-rbac-design.md`, which the row does not name — the same failure
`docs/backlog-triage.md` predicted for 14 of the 68 rows it triaged, landing on a row filed *after* that
census.

### ⛔ THE FINDING THAT DECIDED THE SHAPE OF THE FIX

**Exactly two function-shaped `Default` cells in the entire corpus are true** — `audits.created_at` and
`feedback_reports.submitted_at`, both from a real `->useCurrent()`. **A sweep over `now()` would have
falsified two correct rows while repairing sixty-one.** That is the whole argument for closing this with a
gate rather than a replace, and it is why the third control below is the one that matters: it sweeps the
*true* cell as well, and must redden the discriminator while leaving the phantom sweep green.

### Remedy verdict: the offered fork is FALSE, and the lookup it declines is what dissolves it

The row says *"either the column rows say 'application-generated' or the preamble's conditional is repeated
per row; choosing which is a documentation decision, not a lookup."* Repeating the conditional would repeat
an **unresolved choice on a system that resolved it**: `App\Models\Concerns\HasUuidv7` mints the key in PHP
across 45 models, and the preamble's own reason (b) — the offline client needs the key *before* the row
reaches the server — makes client-side generation correct **independently of the server version**. A
DB-side default would never have filled that column even on PG 18.

⚠️ **And the row is too generous to the preamble.** *"The preamble is not wrong"* is true as far as it
goes; it is a **live conditional over a settled question**, which is a different defect from a false
sentence and is why it was in scope rather than left alone. ➕ **In the same paragraph, unprompted:**
*"Two tables deliberately deviate … `bigint identity`"* was itself stale. There are **five**.

### The gate, and why it is not a lint script

`tests/Feature/Migrations/DocumentedDefaultDriftTest.php` — `RefreshDatabase`, raw `DB::select` against
`information_schema`. ⛔ **Deliberately a test and not a `scripts/*-lint.php` sibling.** That pattern
exists because a drift failure names a constraint and not the file that wrote it; here the defect **is** a
document, so the failure already names file, table and column, and a static twin would have to infer
defaults from `->default()` / `->useCurrent()` / `->nullable()` / raw `DB::statement` against a question
the catalog answers exactly. It checks **presence, not equality** — `now()` is documented where Postgres
reports `CURRENT_TIMESTAMP`, and demanding string equality would fail on a synonym.

Proved red four ways through `scripts/mutate.php`, sha256 asserted to move and to return:

| Control | Result | What went red |
|---|---|---|
| An `id` row re-asserts `uuidv7()` | CAUGHT | the sweep |
| A timestamp row re-asserts `now()` | CAUGHT | the sweep |
| **The TRUE `audits.created_at` cell is swept too** | **CAUGHT** | **the discriminator only — the sweep stayed green, at 15 assertions not 18** |
| The table-header shape changes | CAUGHT | both, at **4** assertions not 18 — the floor fired instead of reporting green over nothing |

### How the prediction fared — one right, one right for a better reason than I gave, one wrong

- ✅ **"The one I most expect to be wrong is the cell count."** It was, in the predicted direction and for
  the predicted reason: 92 measured locally, **93 cells / 106 columns** on a fresh schema. This host's
  database is missing `sso_verified_domains` despite its migration being in the tree. ⚠️ **The lesson is
  the one that generalises: a local measurement against this hybrid database is a FLOOR, and the number
  that goes in a document must come from `RefreshDatabase`.**
- ✅ **Correct, and the gate itself is the reason:** the Pest job's step count stayed at 11 and Static
  analysis stayed at 23, because the gate needed no `composer.json` or `ci.yml` entry — `php artisan test`
  already discovers `tests/Feature`.
- ⛔ **WRONG, AND IT IS THE SAME DEFECT `M57` RECORDED ONE INCREMENT AGO.** *"The docs-and-test diff cannot
  move anything else."* The preamble rewrite added **two lines** to `docs/data-dictionary.md`, which shifts
  every line-number citation into that file past it. **`citation-liveness-lint` passed at ledger tier 18,
  unchanged, and could not have caught it** — it checks a cited line is ALIVE, never that it still says
  what the citing sentence claims, which is the limit `M46` filed at the moment it shipped the gate.
  Found by hand, checking all eleven citations: ten were **already rotten before this branch existed**
  (pointing at blank lines, table separators and unrelated rows), and exactly one was correct and moved —
  `docs/adr/0008-entitlement-and-metering.md`'s `data-dictionary.md:22`, now `:24`. Its sibling `:622` in
  the same parenthesis was already dead and was repaired to `:760` while in the sentence.
  ⚠️ **`PROGRESS_ARCHIVE.md`'s citation was left alone deliberately** — a dated record is history, and
  rewriting one falsifies the log.

### ⚠️ Two things about the local suite that are worth more than the green

- **The full local `php artisan test` run was INCONCLUSIVE and reported exit code 0.** It fataled on the
  container's 128 MB limit: `artisan test` spawns subprocess workers that **do not inherit** the CLI
  `-d memory_limit` flag, and **the pipe hid the exit status** — the documented trap, met in the wild.
  What was run instead, and is the honest evidence: `vendor/bin/pest tests/Feature/Migrations` **in
  process** with the raised limit, which also proves the one local risk this file carries — Pest loads a
  directory into a single process, so a file-scope helper colliding with `SubmissionReferenceBackfillTest`
  would be a fatal redeclaration. 9 passed, 45 assertions. CI's Pest job is the authority and is green.
- **Pint's own probe is why its result is trustworthy**: `preflight --with-pint` proves the scan is real by
  failing on a deliberate probe first. It then found one genuine style issue in the new test file
  (`single_quote`), which a bare green would not have distinguished from a blind pass.

### Filed rather than fixed, each at the moment it was decided

- **The gate reads function-shaped cells only.** A documented *literal* that disagrees with the database is
  invisible to it — and that is most of the column. Scoped out because the literal comparison needs a
  normalizer per type (`'local'::character varying` against `'local'`), so a first draft would fire on
  formatting rather than drift. **Honestly a second gate, not a widened predicate.**
- **`submission_geo_index` is a real table the data dictionary does not mention at all** — found while
  reconciling the `bigint identity` deviation list. Documenting a table means recovering its semantics,
  PII classification and RLS shape; that is its own row.

---

## RELEASED — `M57`, the secured mail encoder stopped escaping quotes and the header says it didn't (merged as PR #248, `757fbd9`, 6/6 green with real step counts — Static analysis 23 · E2E 20 · Contract 16 · Frontend 12 · Pest 11 · axe 11)

**Shipped 2026-09-01.** Branch `m57-mail-attribute-escaping`. Every claimed file was edited, plus one that
was not claimed: `scripts/preflight.php`, because a gate registered in `composer.json` and `ci.yml` alone
would only ever have failed in CI. The claim was not extended otherwise.

### ⛔ THE CONTROL THAT CLOSED ONE ROW IS THE CONTROL THAT OPENED THIS ONE

`Markdown::withSecuredEncoding()` — H23a4's mitigation, which closed the markdown-injection row — does not
*add* escaping. It **replaces** it: `Markdown::render()` swaps `EncodedHtmlString`'s encoder for the whole
render with a three-character map (`[`, `<`, `>`). No `"`, no `'`, no `&`. The published mail header then
interpolated `$tenant->name` into an `alt`, and a name of `Acme" onerror="alert(1)` appended a live event
handler to the `<img>`.

⚠️ **The sharpest part is that the surface's own per-surface encoding test was green throughout and could
not have been otherwise.** It asserts markdown syntax — `[Reset your password](https://evil.example)` — and
the map that neutralises `[` is the same map that stops escaping `"`. **A per-surface test aimed at the
wrong context is not weak coverage; it is coverage that cannot fail.** That is a harder failure than the
one `docs/security-threat-model.md` §9 item 9 had recorded, which was *"a later author does not read the
table"*, and item 9 now says so.

### The row was wrong in both directions, and the useful half is the direction nobody checks

| | |
|---|---|
| Understates | **`{!!` is not the hazard.** `{{ }}` runs the same map, so it is equally unsafe in an attribute. The header held two more (`href`, `src`); a gate counting the directive would have been **fully green** against this defect. |
| Overstates | **The two sinks are not two defects.** The bare `{!! $slot !!}` is text context, where `<`/`>` are already escaped and re-escaping would double-encode. It is correct and it stays. |

**Remedy verdict: none offered**, so nothing was disproved — but the two obvious candidates were both
measured and both wrong. `{{ }}` is the same map. `htmlspecialchars` at its default `double_encode: true`
turns the map's own `&lt;` into `&amp;lt;`, shipped to exactly the images-off audience an `alt` exists for.

### How the prediction fared — one right for the wrong reason, one wrong, one wrong mid-build

- ✅ **"The one I most expect to be wrong is the positive control."** It was the one I was most confident
  about being uncertain, and it held: the injected attribute **survives** `CssToInlineStyles`. The
  inliner does not repair the break-out, it **normalises it into** a proper second attribute, because the
  concatenation happens before any parser runs. `&` is the only character the round-trip fixes by itself.
- ⛔ **WRONG, AND IT COST A RED TEST: THE ASSERTION FORM.** The first draft asserted
  `&quot; onerror=&quot;` — the form `SubmissionPdfRendererTest` produces, and the obvious precedent — and
  it was **red against a correct fix**. The inliner re-quotes the attribute with `'` instead of encoding
  the `"`. ⚠️ **The consequence is worth more than the fix**: a text assertion here *cannot* discriminate,
  because ` onerror=` sits inside the `alt` value in both the broken and the fixed output. The test now
  asserts the rendered `<img>`'s **attribute set by equality** — the same discipline `M56` reached for
  response bodies, arriving from a different direction.
- ⛔ **WRONG: "no other baseline moves."** True of the gates, false of the ledger. Inserting §5.2 into
  Doc #26 shifted a paragraph down one line and killed a live citation pointing at it — `citation-liveness-lint`
  caught it in the same run, ledger tier 18 → 19, and the pointer it named was **inside the row that is
  itself about citations rotting**. Repaired in the same commit. The gate `M46` built found a defect
  `M57` created, within minutes of creating it.
- ✅ **Correct:** three violations today, all in one file, zero after the fix; Static analysis 22 → 23
  steps and every other job unchanged.

### The gate, and why it is not a `{!!` counter

`scripts/mail-attribute-lint.php` keys on **a Blade echo inside a quoted attribute**, never on the
directive, because on this surface the directive distinguishes nothing. Proved red four ways before it was
trusted — `scripts/mutate.php` cannot drive a gate that is not Pest-in-a-container (`M42`), so its
discipline was reimplemented at the call site: sha256 asserted to **move** before each run, the **specific**
failure message asserted rather than the shared prefix (`M49`), restore by byte comparison.

**C1** the unescaped `alt` put back · **C1b** the same defect written as `{{ }}` · **C2** both scan roots
moved · **C3** the attribute regex made to match nothing. **C3 is the one to remember**: files still found,
rules still run, nothing matched — the failure with no symptom, which a file count alone cannot see. The
Pest half was mutation-proved separately and CAUGHT.

### Filed rather than silently left

The framework's own unpublished mail components interpolate into attributes identically and are outside the
gate's reach. All six `->action()` call sites pass an application-built URL, so it is unreachable today —
filed as a `minor` **in the same commit that decided not to fix it**, with the measurement and with why
publishing them is worse than the defect.

⚠️ **`§9` item 9's named escalation has fired and was deliberately not taken.** Its trigger was *"a second
surface found unescaped after that contract lands"*. The value-object forcing device across every render
path was priced against one instance and refused; the per-surface answer shipped instead. **It stays named
and owed** — if a third surface is found unescaped, the per-surface answer has been tried twice.

### Also corrected, because a negative claim is only as wide as the search that produced it

Doc #26's *"the one permitted raw-HTML sink in the entire codebase"* scoped its own evidence to
`v-html`/`innerHTML` in `resources/`, then generalised the conclusion to everywhere — while the mail header
carried two `{!!` the whole time. The claim silently widened between its clause and its conclusion. Item 9
also still said `withSecuredEncoding()` was *"still off"*, eleven increments after H23a4 turned it on.

### The claim as filed, kept verbatim so the section above can be measured against it

⚠️ **This is history and not a live claim.** It is preserved rather than summarised because a release that
rewrites its own prediction is a release that cannot be wrong — and two of these predictions were.

Taken 2026-09-01. Branch `m57-mail-attribute-escaping`, cut from origin/main at `e0509eb`, PR into main.
Row: the `major` in `docs/feature-backlog.md`'s *Documentation & specs* section — *"A second raw-HTML sink
shipped in this branch, and the escaping contract says there is none."* Chosen by the user from the four
open `major` rows; the other three stay open.

### Evidence verified

- `docs/piping-output-encoding-design.md` §5, Blade-shells row — *"Zero `{!!` exists in application code today"*, status *(holds)*: **HELD as a citation, FALSE as a claim.** The cell says what the row says it says, and the tree refutes it.
- Same file, the *"one permitted raw-HTML sink in the entire codebase"* paragraph: **HELD, and false.** It also scopes itself to `v-html`/`innerHTML` in `resources/js`, so its own sentence and its own contract clause disagree about what a *sink* is.
- `resources/views/vendor/mail/html/header.blade.php` — two `{!!`: **HELD.** `alt="{!! trim($slot) !!}"` in an attribute, and a bare `{!! $slot !!}` in the no-logo branch.
- The premise the file's own comment rests on — *"the slot arrived from `mail.notification` already escaped with ENT_QUOTES"*: **FALSE, and falsified by this application's own hardening.** `app/Providers/AppServiceProvider.php` calls `Markdown::withSecuredEncoding()`; `Illuminate\Mail\Markdown::render()` then replaces `EncodedHtmlString`'s encoder with a three-character map — `[`, `<`, `>`. No `"`, no `'`, no `&`. The comment describes the framework default, which this app is deliberately not on.
- The value reaching the slot: **HELD.** `resources/views/mail/notification.blade.php` writes `{{ $brand['name'] }}` into the header slot and `App\Support\Branding\BrandPalette::branded()` sets that to `(string) $tenant->name`.
- *"No user-facing write route for `tenants.name` was found"*: **HELD, independently.** No `Tenant::create(` and no `->fill(['name'` outside docblocks anywhere in `app/`. Latent is the right severity.
- `tests/Feature/Mail/BrandedMailRenderTest.php` *"only pins the unquoted case"*: **HELD.** Its header case asserts `alt="Acme Health"`; nothing in the file carries a quote.

⚠️ **THE ROW UNDERSTATES ITSELF IN ONE DIRECTION AND OVERSTATES ITSELF IN THE OTHER.** `{!!` is not the
hazard — **attribute context under secured encoding** is, and `{{ }}` runs the same three-character map,
so it is equally quote-unsafe in an attribute. Our own header holds two more (`href=`, `src=`) and the
vendor components we render through hold others. A gate that counts `{!!` measures the wrong thing and
passes. In the other direction the row counts two sinks: the bare `{!! $slot !!}` is HTML **text**
context, where the secured map has already escaped `<` and `>`, and re-escaping it would double-encode —
so one of the two is a defect and one is correct.

### Remedy verdict

**NONE OFFERED** — the row asserts the invariant is false and stops. So nothing is disproved, and the
shape is mine to choose. Two candidates were measured and rejected before the escaper was written:
`{{ }}` is **wrong**, because under secured encoding it is the same three-character map; and
`e()`/`htmlspecialchars` at its default `double_encode: true` is **wrong**, because the value has already
been through that map and would ship `&amp;lt;` to exactly the images-off audience the `alt` exists for.
`double_encode: false` is the load-bearing argument.

Files: `app/Support/Mail/MailAttribute.php` (new), `resources/views/vendor/mail/html/header.blade.php`,
`scripts/mail-attribute-lint.php` (new), `composer.json`, `.github/workflows/ci.yml`,
`app/Providers/AppServiceProvider.php`, `tests/Feature/Mail/BrandedMailRenderTest.php`,
`tests/Unit/Support/Mail/MailAttributeTest.php` (new).
Shared artefacts taken: `docs/piping-output-encoding-design.md`, `docs/testing-strategy.md`,
`docs/security-threat-model.md` (read; amended only if it restates the old claim),
`docs/feature-backlog.md`, `composer.json`, `.github/workflows/ci.yml`, `PROGRESS.md` (own block only).
Paired files taken: none.
Namespaces spent: nothing from either namespace — no migration, no ADR. `0023` stays free.
Prediction: the positive control shows a live injected attribute surviving the `CssToInlineStyles` DOM
round-trip; the new linter finds exactly three violations today, all in one file, and zero after the fix;
job 1's step count rises by one and no other baseline moves. **The one I most expect to be wrong is the
positive control** — the inliner re-parses and re-serialises the whole document, and if `DOMDocument`
drops or folds the injected attribute the severity is lower than the row implies and I will say so rather
than keep the framing.

⛔ **`D9` must never be started without an explicit answer.** Open decisions: `D1`, `D3`, `D4`, `D8`, `D9`.

⛔ **RUN `php scripts/state.php` FOR EVERY NUMBER.**

---

## RELEASED — `M56`, the published error contract describes a body the API has never returned (merged as PR #247, `26505fb`, 6/6 green with real step counts — Static analysis 22 · E2E 20 · Contract 16 · Frontend 12 · Pest 11 · axe 11)

**Every claimed file was edited, plus one that was not claimed and should have been:**
`tests/Feature/Api/CustomDomainApiTest.php`, where the `abort()` envelope case belongs because the
domain fixtures already live there. **The claim was not otherwise extended**, and no ADR, migration,
route or `§D<n>` was spent.

### ⛔ THE ROW NAMED FOUR OF FIVE DEFAULTS, AND THE FIFTH HAD NO COMPONENT TO NOTICE

`components.responses` was the whole of the row's scope. **`HttpExceptionToResponseExtension` emits its
body INLINE**, so three further responses were wrong in exactly the same way and were structurally
invisible to any fix scoped to components: `422 POST /domains/{domain}/primary`,
`422 DELETE /domains/{domain}` and `409 POST /webhooks/{endpoint}/deliveries/{delivery}/redeliver`, all
from bare `abort()` calls. **Seven bodies, not four.** Fixed here rather than filed, because the row's
own sentence — *"every 401, 403, 404 and 422 in the published contract is wrong in the same way"* —
already covered them, and shipping the increment that fixes the rest while leaving three known-wrong
bodies behind is the failure the *floor, not a census* rule exists to prevent.

### ✅ AND ONE APPARENT EIGHTH CASE WAS NEVER A DEFECT

`403 POST /public/f/{shareToken}/draft` carries no top-level `properties`, which is the exact signature
of the defect — and it is an `anyOf` whose **two branches both already documented the envelope**. The
first draft of the census gate would have called it broken. It descends `anyOf`/`oneOf`/`allOf` instead,
which is why a naive rule was not shipped as a merge gate.

### THE MECHANISM THE ROW'S REMEDY SENTENCE HIDES

*"A set of custom `ExceptionToResponseExtension`s replacing Scramble's four defaults"* is correct and
underdetermined. Read in the installed v0.13.30 rather than assumed:
`TypeTransformer::handleResponseUsingExtensions()` selects with `->reverse()->first()`, so **registration
order is precedence read backwards**. Registered alphabetically — the obvious thing to do — the generic
HTTP arm would have captured all **34 documented 404s**. The vendor's own order is matched instead, and
the comment beside it says what happens if someone tidies it.

Second: **`reference()` had to be INHERITED, not written.** It is what keeps the four component keys, and
therefore all **113 `$ref` strings**, byte-identical. Each class therefore extends its vendor counterpart
and overrides **`toResponse()` alone** — `shouldHandle()` comes free and cannot drift from the package's.

### HOW THE PREDICTION FARED — INCLUDING THE PARTS THAT WERE WRONG

- ✅ **"Exactly seven bodies."** Held. Seven diff hunks; `$ref` count 113, paths 51, operations 68, all
  unchanged, and no `$ref` or path key appears in the diff at all.
- ⚠️ **"The one I most expect to be wrong: `ApiHttpErrorResponse`'s round-trip through
  `parent::toResponse()`."** **WRONG — it worked first time.** Status, description and the `abort()`
  message example all survived being re-hung under `error.message`. The named risk was not the real one.
- ❌ **THE REAL FAILURE WAS THE GATE, AND IT WAS NOT PREDICTED AT ALL.** The census gate's first draft
  asserted the required set was *exactly* `['code','message']` and went **red on the 422**, whose
  `details` is legitimately required. **The gate was wrong and the document was right** — loosened to
  "code and message are among the required", with the reason recorded beside it. That is the increment's
  own instance of *verify the remedy separately*: a gate written from four examples and applied to a
  fifth.
- ✅ **"`abort()` bodies carry `request_failed`."** Held, and **measured rather than reasoned** — the
  three routes' existing tests assert `assertStatus()` only and never open a body, so nothing had ever
  confirmed the catch-all envelopes them.
- ⚠️ **"Pint does not move."** Its RESULT did not, but it was not free: `fully_qualified_strict_types`
  fired on a docblock `@see`, so bare `pint --test` went red once before passing.
- ✅ **PHPStan**: no error in any M56 file (local reports 18, all the known model-property blindness; CI's
  baseline is no errors). **Vitest, Storybook axe and e2e**: not run and not moved — zero `.vue`, `.ts`
  or selector movement, stated rather than skipped silently.

### TWO CONTROLS, BECAUSE ONE MUTATION CANNOT COVER BOTH HALVES

The census gate reads the **committed** `openapi.json`, so removing the registration does not redden it —
the two halves needed separate proofs, and conflating them would have proved neither.

1. **Mechanism** — registration removed, re-exported: all four components revert to `{ message }` and
   exactly three inline bodies revert. Restored to an exact sha256 from a byte copy taken first.
2. **Gate** — `mutate.php` on the committed contract: **CAUGHT**, `1 failed, 2 passed` against a
   `3 passed` baseline, restored to an exact sha256.

⚠️ **`mutate.php` refused the first attempt because the target was dirty** — which is the rule working:
the restore compares bytes against the file as it stands, so a dirty target bakes the edits in.

### ⚠️ THE HAND-WRITTEN SPEC HAD BEEN RIGHT SINCE PHASE 0

`docs/api-specification.md` §3 sketches a `ValidationError` component with `error.code`, `error.message`
and `error.details.fields` — **the exact shape now generated**, including the field map's position. So the
design document and the generated contract had contradicted each other for the life of the surface, and
nothing compared them. **Its component is named `ValidationError` and the generated one is
`ValidationException`** — a consequence of the deliberate decision to keep the generated keys so the 113
`$ref`s stayed put. Recorded here rather than filed: it is a naming difference between an illustrative
sketch and a generated artefact, and the decision that produced it is on this page.

⚠️ **The three "undocumented STATUS" rows are a different defect and stay open** — the sync 403s,
`promote`'s three 409 causes and `SyncSubmissionResultResource`'s bare strings are *missing* responses,
not misdescribed ones. Nothing here documents a status Scramble still cannot infer, and folding them in
would have been the widening this row was filed to avoid.

---

## RELEASED — `M55`, `assess` stops on a row that says it is not a defect (merged as PR #246, `2f7fae4`, 6/6 green)

**Every claimed file was edited and the claim was not extended.** Not a backlog row: **a defect in
`M52`'s driver, found by the first unattended run that driver was built for.**

### ⛔ TWO OF THE THREE ROWS THE CLASSIFIER OFFERED WERE NEVER DEFECTS, AND BOTH SAID SO THEMSELVES

- *"The citation-liveness gate cannot see a behaviour negative…"* — **`Not live` — both are stated
  limits, filed so they cannot be forgotten.** ⛔ **Its own text says the gate "must never be widened
  into that shape".** An unattended run that took this row would have built the thing the row forbids,
  and every gate in this repository would have gone green on it.
- *"Nothing checks that a `§D<n>` citation names a section…"* — **`Not live` — this is a missing gate,
  not a defect.**

**Only `/gamification/me` was live**, and it merged as `M54`. **A one-in-three hit rate is the finding**,
and it is worth more than the row this increment fixed.

### ✅ MEASURED, AND THE VOCABULARY ALREADY EXISTED

`docs/feature-backlog.md` carries **11** `**Not live**` markers and **13** `**Live**` ones. The corpus
has marked liveness on both sides for a long time; `M52`'s `assess` read severity, held topics,
forbidden paths, stop phrases and open decision ids — **and nothing that answers *"is this a defect?"***.
The fix is not a new idea, it is reading a field that was already there.

### ⚠️ TWO CONSTRAINTS SHAPED IT, AND EACH IS A WAY THE OBVIOUS VERSION WOULD BE WRONG

⛔ **Anchored on the bolded literal, never a substring.** This corpus quotes its own vocabulary — rows
discuss liveness in prose, and one uses the word about a gate's *subject* rather than about itself. A
bare `contains('Not live')` stops rows that merely mention it, and **a control proves the anchor**: a
row saying *"the gate is not live yet"* in plain prose is still `AUTO`.

⚠️ **Silence deliberately does not stop.** Only **24 of 78** rows carry a marker at all. Treating an
absent one as dead would stop nearly everything and make the driver useless rather than careful — the
failure mode of over-correcting after a miss.

It is a **stop** rule, so it scans the body: `M52`'s asymmetry holds — stops maximally sensitive, the
go-signal maximally specific — and the marker is always the row's closing sentence.

### ✅ THE PREDICTION HELD EXACTLY

**Eligible 2 → 0**, and `assess` exits **3**. The claim named that number before the change. Three
controls: live → `AUTO`, marked → `STOP` naming liveness, prose mention → `AUTO`. Backlog restored
**byte-exact**. Pint was the only gate that could move and did not need to; PHPStan cannot.

### ⛔ WHAT THIS DID NOT FIX, AND THE HONEST FRAMING OF WHAT IT DID

**`assess` can only see what a row says about ITSELF.** Two blind spots are now measured and filed:

1. **A row's remedy cost is invisible.** `M54` was classified mechanical and its *evidence* was — four
   checks — while its remedy took reading three vendor classes and ended in a new class plus a
   registration. **"Mechanical" means the row's claim is checkable without judgement; it does not mean
   the fix is small**, and nothing in the classifier distinguishes the two.
2. **A row dead for a reason nobody wrote down still passes.** The marker covers eleven rows. It cannot
   cover the rest.

⚠️ **So this raised a floor rather than closing a hole, and the eligible count is a shortlist for a
human — never a work queue.** Saying that plainly is the point: a green `assess` that implies otherwise
is the same species as a green gate that is blind, which is the failure this whole series exists to end.

### ➕ WHAT THE FIRST UNATTENDED RUN ACTUALLY PROVED

**The choreography works and the judgement is still the expensive part.** Claim, gates, pull request,
six completed checks read individually, merge with an explicit body, close-out — all of it ran without
the user being the trigger, four increments in a row. What the run did *not* demonstrate is that the
queue can be trusted unsupervised: **it offered three rows and two of them should never have been
offered.** The driver caught none of that. **A human reading two closing sentences did.**

**Namespaces: nothing spent.**

### The claim, preserved

Taken 2026-09-01. Branch `m55-assess-liveness`, cut from `origin/main` at `fefb1d5`, PR into `main`.
**Not a backlog row — a defect in `M52`'s driver, found by the first unattended run it was built for.**

### Evidence verified — measured, not inferred

The authorised run was scoped to the three rows `php scripts/loop.php assess` classified as mechanical.
**Two of the three were not defects at all**, and both say so in their own last sentence:

- *"The citation-liveness gate cannot see a behaviour negative…"* — **`Not live` — both are stated
  limits, filed so they cannot be forgotten.** It also says of its first limit that the gate *"must
  never be widened into that shape"*. **Taking it would have built the thing the row forbids.**
- *"Nothing checks that a `§D<n>` citation names a section…"* — **`Not live` — this is a missing gate,
  not a defect.**

Only `/gamification/me` was live, and it is merged as `M54`.

**The corpus census:** `docs/feature-backlog.md` carries **11** `**Not live**` markers and **13**
`**Live**` ones. So a liveness marker exists, is used on both sides, and **`assess` has no notion of
it** — it reads severity, held topics, forbidden paths, stop phrases and open decision ids, and nothing
that answers *"is this a defect?"*.

⚠️ **AND THE 24 MARKED ROWS ARE A MINORITY OF 78, WHICH SHAPES THE FIX.** Most rows carry no marker at
all, so absence cannot mean "not live" without stopping nearly everything. The rule has to be: an
explicit `Not live` stops; silence does not.

### Remedy verdict

**No row prescribed this, so there is nothing to disprove** — but the shape of the fix is constrained
by two things worth stating before writing it.

⛔ **The marker must be matched anchored, not as a substring.** `docs/feature-backlog.md` is a corpus
that quotes its own vocabulary — several rows discuss liveness in prose, and `M46`'s row uses the word
about the gate's *subject* rather than about itself. A bare `str_contains($body, 'Not live')` would stop
rows that merely mention it. The marker in practice is the bolded literal `**Not live**`, and that is
what is matched.

⚠️ **And this is a stop rule, not a go rule, which decides where it belongs.** `M52` established the
asymmetry deliberately: stop rules scan title **and** body because a stop should be maximally
sensitive; the go-signal scans the title alone because the body quotes everything. Liveness is a stop,
so it scans the body — where the marker actually lives, since it is always the row's closing sentence.

### Files

`scripts/loop.php` · `docs/feature-backlog.md` (file what this leaves) · `docs/claims/lane-a.md` ·
`PROGRESS.md` (own block) · `docs/gate-baselines.md` (close-out).

**Shared artefacts taken:** `PROGRESS.md`, `docs/**`.
**Paired files taken:** none. **Namespaces spent: nothing.**

### Prediction

**Pint is the only gate that can move** — one `scripts/` file. PHPStan cannot: it scans `app`,
`database`, `routes`. Vitest 134, axe, e2e and `openapi.json` unmoved. No CI step added.

**The measurable outcome is the eligible count: 2 → 0.** Both remaining rows must stop, each naming
liveness as the reason, and `assess` must then exit 3 — *"nothing here is mechanical enough to start
unattended"*, which is a **normal outcome and not an error**, and is exactly the state the queue is
really in.

⚠️ **The one most likely to be wrong is the ceiling, not the floor.** Anchoring on `**Not live**` will
catch the eleven marked rows; what it cannot catch is a row that is dead for a reason nobody wrote
down. `M54`'s release already recorded that **a row's remedy cost is invisible to `assess`**, and this
adds a second blind spot of the same family: **the classifier can only see what a row says about
itself.** That is a floor being raised, not a hole being closed, and the release will say so rather
than implying the queue is now safe to trust unattended.

---

## RELEASED — `M54`, a module-gated route documents the 403 it can actually answer (merged as PR #245, `c824732`, 6/6 green)

**Every claimed file was edited and the claim was not extended.** `/gamification/me` now documents the
403 it can answer, through a general mechanism rather than a per-route patch.

### ✅ THE ROW'S MECHANISM WAS EXACT, AND IT WAS CHECKED IN THE VENDOR SOURCE RATHER THAN TRUSTED

`ErrorResponsesExtension:60` in the **installed** v0.13.30 adds a 403 **only** when the gathered
middleware starts with `can:` or `Authorize::class.':'`. `module:` is invisible to it. That is why the
sibling route documented both statuses and this one did not — it carries `can:` as well, and this one
is deliberately ungated per ADR-0020 §D7.

⚠️ **The citation had drifted**: the row cites `routes/api.php:440`, which is the comment block; the
route is at `:456-457`. Recorded rather than quietly corrected, because a drifting line number is how a
row stops being checkable.

### ⛔ BOTH OBVIOUS REMEDIES WERE WRONG, AND REJECTED ON EVIDENCE

1. **`openapi.json` may not be hand-edited.** CI exports a fresh document and diffs it, so a hand-added
   403 is reverted by the next export and reddens the gate in between.
2. **`@throws` cannot work either.** The exception is thrown by `RequireModule`; the controller never
   mentions it, so there is nothing in the action for Scramble to read. **The gate lives on the route,
   so the documentation has to be derived from the route.**

The fix is an operation transformer — **general**, so the next `module:`-gated API route documents its
403 without anyone remembering to, which is the defect class the row was one instance of.

### ✅ WHAT WAS MEASURED

**Exactly one operation changed.** `/gamification/leaderboard` was untouched — ⚠️ **the risk the claim
named as most likely to matter.** It carries `can:` *and* `module:`, so Scramble had already given it a
403; a transformer that appended unconditionally would have emitted the status twice and changed a
route this row is not about. The guard is explicit and the diff proves it held. `components.responses`
unchanged; **a second export is byte-identical**, which is what the contract gate actually requires.

**PHPStan: 18 errors across the same 10 files as the baseline, and neither of this diff's files
appears — zero delta BY FILE LIST.** ⚠️ The claim flagged that PHPStan **could** move here, unlike the
previous four increments, because this adds a class under `app/`. It did not — but *"it cannot move"*
had stopped being the reason, and saying so was the point. `tests/Feature/Api` 128 passed.

⚠️ **The local run needed `--memory-limit=1G`**: at the default 128M PHPStan crashed inside its own
result cache, which reads as a failure and is an environment fact rather than a finding.

### ⛔⛔ THE ROW SAT ON A MUCH LARGER DEFECT, FILED RATHER THAN FOLDED IN

**Every error component in `openapi.json` documents a body this surface does not return.** All four —
`AuthorizationException`, `AuthenticationException`, `ValidationException`, `ModelNotFoundException` —
describe Laravel's default `{ message }`. Every `/api/v1` error is rendered through `ApiErrorResponse`
as `{ error: { code, message, details? } }`, which `docs/api-specification.md` §2.3 calls the contract.
Measured directly: an API 403 answers `{"error":{"code":"forbidden",…}}` while the published document
promises a top-level `message`. **Every 401, 403, 404 and 422 in the contract is wrong the same way.**

Filed as its own `major`: the fix replaces Scramble's four default exception extensions and changes the
published contract for the whole surface, which wants its own increment and its own review. **This row
was one route's MISSING 403; that one is every route's MISDESCRIBED one.**

⚠️ **So this increment's new 403 is deliberately the only accurate one in the document, and the
inconsistency is stated rather than hidden.** Matching the neighbours would have meant documenting a
shape the code does not return — in the increment closing a documentation-truth row.

### ➕ ONE OBSERVATION ABOUT THE LOOP ITSELF, SINCE THIS WAS ITS FIRST INCREMENT

**The classifier called this row mechanical and it was — but only in its evidence, not in its remedy.**
Verifying the row took four checks; finding a remedy that CI would accept took reading three vendor
classes, and the honest fix turned out to be a new class plus a registration. ⚠️ **"Mechanical" as the
driver uses it means *the row's claim is checkable without judgement*, and that is not the same as *the
fix is small*.** The stop-list was never in danger here — nothing tripped — but a row's remedy cost is
invisible to `assess`, and the release says so rather than letting the eligible count imply otherwise.

**Namespaces: nothing spent.**

### The claim, preserved

Taken 2026-09-01. Branch `m54-module-403-contract`, cut from `origin/main` at `87e8291`, PR into `main`.
Row: the `minor` — *"`/gamification/me` documents only `200`"*. **This is the first increment of the
first unattended loop run**, authorised by the user and scoped to the three rows
`php scripts/loop.php assess` classifies as mechanical.

### Evidence verified

Every claim in the row opened against the merged tree:

- **`/gamification/me` documents only `200`** — read out of `openapi.json`: `['200']`. ✅ **held**
- **Its sibling `/gamification/leaderboard` documents both** — `['200', '403']`. ✅ **held**
- **The route carries `module:gamification`** — ✅ **held**, but the citation has **moved: the row says
  `routes/api.php:440`, and `:440` is the explanatory comment block.** The route is at **`:456-457`**.
  Same logical block, off by seventeen lines. Recorded rather than silently corrected, because a drifting
  line number is how a row stops being checkable.
- **`ModuleDisabledException` answers 403 on a supported user action** — ✅ **held**, and the file says so
  in its own words: *"AND THE STATUS IS 403, NOT 402 … this is a workspace-level configuration refusal"*.
  Thrown by `RequireModule.php:61`, rendered in `bootstrap/app.php:346`.
- **"nothing inferred it because the endpoint deliberately has no `can:` gate"** — ✅ **held, and this is
  the one part I verified in the vendor source rather than taking on trust.**
  `vendor/dedoc/scramble/src/Support/OperationExtensions/ErrorResponsesExtension.php:60` adds the 403
  **only** when the route's gathered middleware starts with `can:` or `Authorize::class.':'`. `module:`
  is invisible to it. **The row's stated mechanism is exactly the mechanism.**

⚠️ **The row is a floor here, as usual — but only just.** A census of `module:` across the routing tree
returns **five** sites, not two: three are in `routes/tenant.php` and are **web** routes, outside the
`api/v1` surface Scramble exports, so they cannot appear in `openapi.json` and are correctly not this
row's business. The API surface really is the two gamification routes.

### Remedy verdict

**The row offers no remedy**, so there is nothing to disprove — and the obvious one is wrong.

⛔ **`openapi.json` MUST NOT BE HAND-EDITED.** CI's contract job exports a fresh document and fails on
drift against the committed file, so a hand-added 403 would be reverted by the next export and would
redden the gate in between. The fix has to make **Scramble infer it**.

⛔ **And the annotation route does not work either.** Scramble's 403 comes from the middleware list, not
from the controller: the exception is thrown by `RequireModule`, which the controller never mentions, so
there is no `@throws` for Scramble to read. Verified in the extension source above rather than assumed
from the framework's general behaviour.

**So the fix is an operation transformer**, registered where this project already configures Scramble
(`AppServiceProvider`, which already calls `Scramble::extendOpenApi`).
`Scramble::configure()->withOperationTransformers()` exists in the installed **v0.13.30** —
`vendor/dedoc/scramble/src/GeneratorConfig.php:167`, checked against the version actually installed
rather than the current documentation. It adds the 403 to any operation whose route carries `module:`
middleware, which is **general** rather than a per-route patch: the next module-gated API route gets it
for free, and that is the defect this row is an instance of.

### Files

`app/Providers/AppServiceProvider.php` · a small transformer class under `app/Support/` · `openapi.json`
(regenerated, never hand-edited) · `docs/feature-backlog.md` (close the row) · `docs/claims/lane-a.md` ·
`PROGRESS.md` (own block) · `docs/gate-baselines.md` (close-out).

**Shared artefacts taken:** `openapi.json`, `PROGRESS.md`, `docs/**`.
**Paired files taken:** none from 7(b-bis). ⚠️ **`openapi.json` is paired-shaped with the exporter**: it
is generated, so it moves only as a consequence of the transformer and must be regenerated by the same
command CI runs, never edited.
**Namespaces spent:** **nothing.** No ADR, no migration, no `§D`, no decision id, no new ability or
permission key.

### Prediction

⛔ **PHPStan CAN move on this one, and that is a change from the last four increments.** They were all
`scripts/` and `docs/`; this adds a class under `app/`, which PHPStan scans. It should stay at its
baseline because the class is small and fully typed — **but "cannot move" is no longer the reason, and
saying that plainly is the point.**

**`openapi.json` WILL change, and that is the deliverable** — exactly one operation gains exactly one
response. **The contract job is the real gate**: it re-exports and diffs, so if my transformer produces
anything the committed file does not have byte-for-byte, it goes red. Vitest 134, axe and e2e cannot
move — no `.ts`, no `.vue`, no selector.

⚠️ **The one most likely to be wrong is the leaderboard, not the `me` route.** `leaderboard` carries
**both** `can:` and `module:`, so it already documents a 403 from the `can:` path; a transformer that
appends unconditionally would either duplicate the response or overwrite the existing description. **The
failure would show up as a diff on a route this row is not about** — which is precisely how a "small
documentation fix" becomes a contract change nobody reviewed. It is guarded explicitly and gets its own
control.

---

## RELEASED — `M53`, the close-out exemption in both places it was missing (merged as PR #244, `dcd3309`, 6/6 green)

**Every claimed file was edited and the claim was not extended.** Taken immediately on the user's
instruction, ahead of the first unattended run, because a loop that reaches its close-out and is
refused by its own guard fails on its first outing at the one step this realignment exists to automate.

### ⛔ THE ERROR WAS CONCEPTUAL, AND IT IS THE MOST TRANSFERABLE THING IN THE WHOLE REALIGNMENT

`M52`'s guard derived its exempt set from `ci.yml`'s `paths-ignore` on the principle **"one authority,
referenced rather than copied"** — the principle this repository applies to gate numbers, to the lane
boundary and to the claim template. The principle is right. **It was applied to the wrong authority.**

| | answers |
|---|---|
| `paths-ignore` | *"can this change affect the product's CI?"* |
| the guard needed | *"is this the claim and close-out protocol?"* |

The two sets overlap almost entirely and differ on **exactly one path — `docs/feature-backlog.md`** —
which is the file *every* close-out edits, because closing a row is step 1 of the close-out `CLAUDE.md`
prescribes. So `M52`'s guard refused `M52`'s own close-out, and it went out under `--no-verify`.

⚠️ **Deriving from an authority is only safer than copying when its semantics answer YOUR question.
Otherwise it is a copy wearing borrowed confidence** — and it is worse than a copy, because the
borrowed confidence stops you checking.

### ✅ THE RELATIONSHIP IS NOW ASSERTED INSTEAD OF ASSUMED

The guard owns `PROTOCOL_PATHS`. What is derived from `ci.yml` is no longer the **answer** but the
**check**: `PROTOCOL_PATHS` must be a **superset** of `paths-ignore`, asserted on every run and
**failing closed with exit 2** if the workflow grows an entry the guard does not know. The two lists
stay visibly related without either pretending to be the other.

⛔ **THE ROW'S NON-REMEDY WAS THE LOAD-BEARING HALF AND WAS VERIFIED RATHER THAN ACCEPTED.** *Do not
add the backlog to `paths-ignore`.* That block sits under `push:`, so an entry means **no run at all**
on a backlog edit — a real reduction in gate coverage, in a file on the user's stop list. Checked in
the workflow rather than taken from the row I had written.

### ⚠️ THE SECOND HALF NEEDED A DIFFERENT FIX AND WAS DELIBERATELY NOT PATTERN-MATCHED INTO THE FIRST

`loop gates` was **not** misclassifying paths. It was asking `preflight` a question with **no true
answer** on a close-out branch: Rule 7(g) wants the claim to name the current branch, and a close-out
runs on a branch the claim *could not* have named because it did not exist when the claim was written.

Both get an explicit `--closeout` mode that downgrades the claim assertion to a **stated waiver**, and
prints that it *is* a waiver so a mistaken use is loud.

⛔ **Explicit, never inferred from the branch name.** `m<n>-closeout` is a convention, and **a check
that relaxes itself whenever a branch is named a certain way is one anyone can switch off by renaming a
branch.** Two fixes for one row, and the temptation to make them the same fix was the thing to resist.

### ✅ EIGHT CONTROLS, AND ONE THAT ONLY EXISTS BECAUSE PINT TOUCHED THE FILE

| Control | Result |
|---|---|
| the exact commit range `M52`'s close-out was refused for | **allow** (was refuse) |
| three commits straight to the trunk | **refuse** |
| work on an unclaimed branch | **refuse** |
| the same work on the claimed branch | **allow** |
| branch deletion | **allow** |
| an entry dropped from `PROTOCOL_PATHS` | **exit 2**, naming the divergent path |
| `preflight` on an unclaimed branch, no `--closeout` | **exit 1** |
| the same branch **with** `--closeout` | **exit 0**, waiver stated |

The mutation was restored **byte-exact**. ⚠️ **The five original guard controls were re-run AFTER Pint
reformatted `pre-push-guard.php`** — a reformat is a change, and controls that passed before one prove
nothing about after it. That re-run cost one command and is the kind of check that is skipped precisely
because it feels redundant.

### ✅ THE ACCEPTANCE TEST WAS NOT A CONTROL, AND IT WAS NAMED BEFORE THE FIX WAS WRITTEN

The claim said: *"this increment's own close-out must push WITHOUT `--no-verify`. `M52`'s could not. If
the close-out is refused again, the fix is wrong no matter what the controls said."* **It pushed
clean**, and `loop gates --closeout` came back green on a branch the claim does not name — the second
half of the fix exercised in its real setting rather than a simulated one.

⚠️⚠️ **BUT THIS ACCEPTANCE TEST IS WEAKER THAN THE CLAIM PROMISED, AND THE HONEST THING IS TO SAY SO
RATHER THAN BANK IT.** `M53`'s close-out touches only `PROGRESS.md`, `docs/claims/lane-a.md` and
`docs/gate-baselines.md` — **all three were already in `paths-ignore`, so this close-out would have
passed under `M52`'s broken guard too.** The reason is incidental: the row was closed inside the pull
request rather than in the close-out, so the one path that exposes the defect,
`docs/feature-backlog.md`, is not in this push.

**The real proof is control C7**, which replays the exact commit range `M52`'s close-out was refused
for — four paths including the backlog — and now returns allow where it previously returned refuse.
⛔ **A close-out that happens not to touch the defective path is not evidence about the defect**, and
recording this as *"the acceptance test passed"* would be precisely the vacuous success this project
keeps cataloguing. The next close-out that closes a row is the unforced end-to-end case.

⚠️ **The claim's named risk did not fire, and saying so matters.** It predicted the superset assertion
would fail closed on a cosmetic glob mismatch — `docs/claims/**` against `docs/claims/`. It does not:
the comparison normalises the `/**` suffix before comparing. The risk was real and the mitigation was
already in the code, which is a better outcome than being lucky.

**Namespaces: nothing spent.** No ADR, no migration, no `§D`, no decision id.

### The claim, preserved

Taken 2026-09-01. Branch `m53-closeout-exemption`, cut from `origin/main` at `0bce9db`, PR into `main`.
Row: the `major` filed by `M52`'s own close-out — *"The pre-push guard REFUSES A NORMAL CLOSE-OUT, and
it refused the one that shipped it."* **Taken immediately and on the user's instruction**, because the
first unattended run is gated on it: a loop that reaches its own close-out and is refused by its own
guard would fail on its first outing, at the one step this realignment exists to automate.

### Evidence verified

Every claim in the row re-checked against the merged tree rather than trusted from the row I wrote:

- **`ci.yml`'s `paths-ignore` is exactly five entries** — `PROGRESS.md`, `PROGRESS_ARCHIVE.md`,
  `docs/claims/**`, `docs/gate-baselines.md`, `docs/backlog-triage.md`. ✅ **held**, parsed from the
  file this session.
- **`docs/feature-backlog.md` is not among them.** ✅ **held** — and closing a row there is step 1 of
  the close-out `CLAUDE.md` prescribes, so the divergence is **exactly one path** and it is the one
  every close-out touches.
- **The refusal is real and was observed**, not predicted: `M52`'s close-out was refused with *"This
  push changes 4 path(s) that are not documentation"* and had to go with `--no-verify`. ✅ **held**
- **`loop gates` is red on a close-out branch by construction** — it runs `preflight`, whose Rule 7(g)
  check requires the claim on `origin/main` to name the current branch, and `m<n>-closeout` is a branch
  the claim cannot name. ✅ **held**, observed on the same close-out.

### Remedy verdict

**The row's prescribed remedy is right, and its stated non-remedy is right too** — which is a first for
this arc, and unsurprising given the row was written by the increment that caused the defect.

⛔ **The row's warning is the load-bearing half: do NOT fix this by adding the backlog to
`paths-ignore`.** That would stop CI running on backlog edits — a real reduction in gate coverage, in
`ci.yml`, which is on the user's stop list. Verified rather than assumed: `paths-ignore` is under
`push:`, so an entry there means *no run at all*, which `CLAUDE.md` already warns must be read as
*correctly skipped* and never as *pending*.

**So the guard gets its own set**, and the relationship to `paths-ignore` becomes **asserted instead of
assumed**: the guard's list must be a **superset** of `paths-ignore`, checked at run time, failing
closed if `ci.yml` ever grows an entry the guard does not know. That keeps the two visibly related
without one pretending to derive from the other — which is the mistake `M52` made.

⚠️ **The second half is NOT the same fix and must not be pattern-matched into one.** `loop gates` is
not misclassifying paths; it is asking `preflight` a question that has no true answer on a close-out
branch. The fix there is an explicit `--closeout` mode, propagated to `preflight`, that downgrades the
claim assertion to a note **and says why**. ⛔ **Deliberately explicit rather than inferred from the
branch name**: `m<n>-closeout` is a convention, and a guard that silently relaxes itself whenever a
branch is named a certain way is a guard anyone can switch off by renaming a branch.

### Files

`scripts/pre-push-guard.php` · `scripts/preflight.php` · `scripts/loop.php` · `docs/feature-backlog.md`
(close the row) · `docs/claims/lane-a.md` · `PROGRESS.md` (own block) · `docs/gate-baselines.md`
(close-out).

**Shared artefacts taken:** `PROGRESS.md`, `docs/**`.
**Paired files taken:** none from 7(b-bis). ⚠️ **One coupling is taken and is the whole point of the
increment:** the guard's documentation set and `ci.yml`'s `paths-ignore` must stay in a superset
relation, and that relation is now enforced at run time rather than remembered.
**Namespaces spent:** **nothing.** No ADR, no migration, no `§D`, no decision id. `0023` stays free.

### Prediction

**Pint is the only gate that can move** — three `scripts/` files change, and bare host Pint is the form
that scans that directory. PHPStan cannot move (`app`, `database`, `routes`). Vitest 134, axe, e2e and
`openapi.json` unmoved. No CI step added or removed, so `static-analysis` keeps its step count.

⚠️ **The thing most likely to be wrong is the superset assertion firing on its own tree.**
`docs/claims/**` is a glob and the guard's matcher handles `/**` by prefix; if the guard's own set is
written with a trailing slash, or `ci.yml` is later written with a different glob shape, the superset
check compares strings that mean the same thing and are not equal — and it **fails closed**, which
turns a cosmetic mismatch into a refused push. That is the right direction to fail, and it is the
first thing to check if a push is refused for a reason that looks absurd.

⛔ **And the acceptance test is not a gate at all: this increment's own close-out must push WITHOUT
`--no-verify`.** `M52`'s could not. If the close-out is refused again, the fix is wrong no matter what
the controls said — so the proof is deferred to the close-out deliberately, and stated here before it
can be claimed afterwards.

---

## RELEASED — `M52`, the pre-push guard and the loop driver (merged as PR #243, `37ec14f`, 6/6 green)

**Every claimed file was edited and the claim was not extended.** Two rules that had each been broken
by a real push are now refused by a hook, and the scripted half of a session no longer needs a human
to type it.

### ⛔ THE PRESCRIBED HOOK, TAKEN LITERALLY, WOULD HAVE BLOCKED EVERY CLOSE-OUT

*"Refuse a push when `docs/claims/lane-a.md` on `origin/main` does not name the current branch"* is
correct for work and wrong for the close-out, which runs on an `m<n>-closeout` branch the claim
**cannot** name — that branch did not exist when the claim was written. `M50`, `M51` and `M51`'s
correction were all pushed that way, so this was measured against three real pushes rather than
imagined. **A hook that refuses those gets `--no-verify`'d on its first outing, and a bypassed guard
is furniture.**

The rule keeps its purpose and narrows its scope: a push whose changed paths are **entirely
documentation** is exempt, and that set is **read from `ci.yml`'s `paths-ignore`** — already this
repository's definition of *"a change that cannot affect the product"*. ⚠️ **The parse has a floor of
three and fails CLOSED**, because a list that silently came back empty would exempt nothing and fire
rule A on every claim push. That is the *succeeds-on-empty-input* family, now hit in a third script.

⚠️ **And the exemption is what makes rule A possible at all**, not a convenience: at the moment of the
claim push the claim is *in the commit being pushed* and is not yet on `origin/main`, so the rule
cannot be satisfied by the very commit that establishes it.

### ✅ PROVEN SIX WAYS, THEN END TO END ON A REAL PUSH

| Control | Result |
|---|---|
| documentation-only push to the trunk (a claim push) | **allow** |
| three commits pushed straight to the trunk (`M48`'s shape) | **refuse — rule B** |
| work pushed to a branch the claim does not name | **refuse — rule A** |
| the **same commit** pushed to the branch the claim does name | **allow** |
| a branch deletion | **allow** |
| `ci.yml`'s `paths-ignore` mutated below the floor | **exit 2, refuses** |

Then for real: `git push origin hook-proof` was **refused, exit 1**, and `git ls-remote` confirmed the
branch was **never created**. The identical commit pushed to the claimed branch was allowed. The
`ci.yml` mutation was restored **byte-exact**. ⚠️ **The synthetic controls alone would not have been
enough** — they prove the logic, not that git invokes it, and those are different claims.

### ⛔ THE DRIVER'S FIRST VERSION WAS PERMISSIVE BY DEFAULT WHILE ITS OWN DOCBLOCK CLAIMED THE OPPOSITE

It said *"auto-eligible only if positively recognised as mechanical"*, and the code admitted **every**
`minor` row that tripped no stop rule — **68 of 78 rows in scope.** That is `M43`'s lesson occurring
inside this increment's own logic: **a check can read as strict and behave as decorative**, and the
only thing that separated them here was running it and looking at the output.

Two fixes, both measured rather than reasoned:

1. **A positive marker is required**, not merely an absent stop. The stop list is a floor, not a census.
2. ⛔ **The go-signal matches the row TITLE only, while stop rules still scan title AND body.** The
   asymmetry is the finding: a stop should be as sensitive as possible, a go-signal as specific as
   possible. Matching the body admitted a CSS overflow row, a missing-middleware security row, and a
   row that **is an open decision** — each on a keyword buried in its own commentary. **The title is
   the row's claim; the body is its argument, and the argument quotes everything.**

**78 rows → 3 eligible**, all genuinely citation or documentation-truth rows, which is the scope that
was actually asked for.

➕ **And it stops on any row naming an OPEN decision, which nothing in the brief asked for.** Taking
such a row unattended would answer the decision by building one of its options — the `D6` failure in
miniature, a deadline passing and the default winning by silence.

**The stop rules were proven by injection**: one synthetic row flipped through each trigger — `major`,
a held topic, `ci.yml`, an open decision — each stopping with the correct reason named, and the
backlog restored byte-exact afterwards.

### ⛔ TWO FINDINGS AGAINST MY OWN WORK, BOTH CAUGHT BY THINGS THIS SERIES BUILT

1. **The driver's first draft hand-rolled a backlog parser** — while `state.php` already returns every
   row with its severity, line and provenance, and is the authority this project tells every session
   to trust for counting the tree. **A second parser would have drifted from the first**, which is the
   defect Rule 7(b), `docs/gate-baselines.md`, `TEMPLATE.md` and this increment's own guard each record
   separately. It was written *one function away from a comment warning about it.* Rewired.
2. **`loop gates` caught this increment's own red.** Adding the enable step to `README.md` shifted
   every line below it by **15**, and a backlog citation into `README.md:85-86` landed inside the
   fenced block I had just added — `M34`'s *a citation into a file your own diff edits must be re-read
   after the edit*, for the **third increment running**. ⚠️ **All five** displaced README citations
   were re-pointed by the verified shift, not only the one the gate flagged: the other four were
   already dead against a blank line so the count was unaffected, **but my diff moved what they
   meant**, and leaving them would have made them doubly wrong for whoever takes that row.

### ✅ THE PREDICTION WAS RIGHT, AND IT WAS STATED MECHANICALLY BECAUSE THE LAST TWO WERE NOT

`M50` named R3 and `M51` named citation-liveness; both were reasoned soundly and both were wrong, and
both misses were about *volume* when the mechanism was about *position*. So this claim refused to name
a gate and named a property instead: **"a pre-push hook is invisible to every gate in this repository
— nothing in CI runs it, `composer quality` cannot exercise it, and its own success message is
indistinguishable from it never having been invoked. It will be proven the only way a hook can be, by
making a push that must be refused and watching it be refused."** That is exactly what happened.

**Pint was named as the only gate that could move, and it moved** — it failed on both new `scripts/`
files with three fixers each, which is simultaneously the proof that bare host Pint scans `scripts/`
at all. PHPStan could not move and did not.

### ⛔⛔ THE GUARD REFUSED THIS INCREMENT'S OWN CLOSE-OUT, AND IT WAS RIGHT TO — THE DEFECT IS MINE

**Filed as a `major` and pushed with `--no-verify`, disclosed here because the guard's own refusal
message says to.** The close-out edits `docs/feature-backlog.md` to close a row — step 1 of the
close-out `CLAUDE.md` prescribes — and **that file is not in `ci.yml`'s `paths-ignore`**. So the push
was classified as *work*, and rule A refused it because `m52-closeout` is not the branch the claim
names.

⛔ **THE ERROR IS CONCEPTUAL AND IT IS THE MOST TRANSFERABLE THING IN THIS INCREMENT.** *"One
authority, referenced rather than copied"* — the principle this repository applies to gate numbers, to
the lane boundary and to the claim template — was applied **correctly, to the wrong authority**.
`paths-ignore` answers *"can this change affect the product's CI?"*. The guard needed *"is this
documentation?"*. The two sets overlap almost entirely, **and the file they differ on is the one every
close-out edits.** Deriving from an authority is only safer than copying when its semantics answer
**your** question; otherwise it is a copy with extra confidence.

⚠️ **It cannot be fixed by adding the backlog to `paths-ignore`** — that would stop CI running on
backlog edits, a real change to gate coverage, behind `ci.yml`, on the user's stop list. The fix
belongs in the guard, with its divergence from `paths-ignore` written down at the point of use.

⚠️ **And the same blind spot exists from the other side in `scripts/loop.php`**: `gates` runs
`preflight`, whose claim check is red on any close-out branch by construction, so the driver halts on
the part of the loop it most exists to automate. **One increment put the close-out exemption in the
hook, and in neither the hook's path list nor the driver.**

✅ **The honest reading is that the guard worked.** It caught a real inconsistency in its own author's
model on its first real close-out, the escape hatch was used exactly as designed — once, deliberately,
and disclosed — and the finding is filed rather than smoothed over. A guard that had let this through
would have taught nothing.

### ⚠️ THE LIMITS, STATED RATHER THAN LEFT TO BE DISCOVERED

**The guard is opt-in per clone and cannot be otherwise.** `core.hooksPath` is local git configuration
and a repository may not enable its own hooks — a deliberate git security property, not a gap to
engineer around. `preflight` now reports its absence, so an unguarded checkout says so at session open
rather than at the push it would have caught. **`--no-verify` bypasses it by design: this guards
mistakes, not intent.** The server-side half is `M51`'s ruleset.

**The driver does not write the increment**, and was never meant to. The user asked for the *trigger*
to go, not the judgement. Reading citations, verifying a remedy, designing a fix and writing controls
remain the work; what is automated is preflight, the numbers, the row classification, the gate
sequence and the close-out choreography.

⛔ **AND THE FIRST UNATTENDED RUN HAS NOT HAPPENED.** It needs an explicit go, and asking for it is the
last act of this increment rather than something to be assumed from the tooling existing.

**Namespaces: nothing spent.** No ADR, no migration, no `§D`, no decision id, no exceptions entry.

### The claim, preserved

Taken 2026-08-31. Branch `m52-hooks-and-driver`, cut from `origin/main` at `02eba8d`, PR into `main`.
**Not a backlog row.** This is the third and last increment of the user-directed realignment: make the
protocol's two most-violated rules mechanical, and remove the user from being the trigger for the next
session.

### Evidence verified

Both rules this increment mechanises have been broken **by real pushes**, and both incidents are in the
ledger rather than hypothesised:

- **`M14` wrote a perfect claim nobody could see** — it was never pushed. That is the whole reason
  Rule 7(g) says *a claim is a pushed commit*, and `preflight` already checks it. ✅ **held** — but
  `preflight` is advisory and not a merge gate, so it asserts the rule at session open and cannot
  stop the push that breaks it.
- **`M48` put a tracker surgery on the trunk with no squash merge**, because `git push origin HEAD:main`
  — the command Rule 7(g) itself prescribes — **pushes the whole branch**, and by then the branch
  carried the work. ✅ **held**, and `M50`'s release records it as still unguarded.
- **This very session had two agents in one worktree**, which the claim lock is now the only thing
  standing against. ✅ **held**

**Ground state verified before designing:** `core.hooksPath` is **unset**, there is no `.githooks`
directory, and `.git/hooks` contains nothing but samples. This is net-new, with nothing to amend.

### Remedy verdict

⛔ **THE PRESCRIBED HOOK (a), TAKEN LITERALLY, BLOCKS EVERY CLOSE-OUT — AND I KNOW BECAUSE I JUST MADE
THREE.** *"Refuse a push when `docs/claims/lane-a.md` on `origin/main` does not name the current
branch"* is correct for work and wrong for the close-out: a close-out runs on `m<n>-closeout`, which the
claim **cannot** name because it did not exist when the claim was written. `M50`, `M51` and `M51`'s
correction were all pushed from such a branch. A hook written to the letter would have refused all
three, and the first thing anyone would do is `--no-verify` — which is how a guard becomes furniture.

**The fix keeps the rule and narrows its scope to what the rule is FOR.** A claim exists so that *work*
cannot begin unannounced. A close-out is not work; it is the release of a claim that has already
merged. So the hook exempts a push whose changed paths are **entirely documentation** — and it reads
that set from **`.github/workflows/ci.yml`'s `paths-ignore`**, which is already this repository's
definition of *"a change that cannot affect the product"*. One authority, referenced rather than
copied, exactly as `TEMPLATE.md` and `docs/gate-baselines.md` require of their own facts. ⚠️ **A second
hand-maintained copy of that list is the defect this project has now recorded four separate times.**

**Hook (b) transfers intact and needs no interpretation.** *Refuse `HEAD:main` when the branch carries
more than the claim commit* — i.e. more than **one** commit beyond `origin/main`. That permits a claim
push, a claim extension and a close-out, all of which are single commits, and refuses exactly `M48`'s
shape.

### Files

`.githooks/pre-push` *(new)* · `scripts/loop.php` *(new, the driver)* · `scripts/preflight.php` (assert
the hooks are installed, since a hook nobody enabled is not a guard) · `README.md` (the one-line
enable step) · `composer.json` (register the driver and a `hooks:install` script) · `PROGRESS.md`
(own block) · `docs/claims/lane-a.md` · `docs/feature-backlog.md` (file what this deliberately leaves)
· `docs/gate-baselines.md` (close-out).

**Shared artefacts taken:** `PROGRESS.md`, `docs/**`, `composer.json`, `README.md`.
**Paired files taken:** none from 7(b-bis). ⚠️ **One coupling is taken and is named:** the hook's
documentation-exempt list is *derived from* `ci.yml`, so a future edit to `paths-ignore` changes hook
behaviour. That is the intended direction, and the hook fails **closed** if it cannot parse the file.
**Namespaces spent:** **nothing** — no ADR, no migration, no `§D`, no decision id, no exceptions entry.
`0022` is spent by `M50`; `0010` stays reserved for H1d.

### ⛔ THE LIMIT THAT MUST BE STATED BEFORE IT IS DISCOVERED

**`core.hooksPath` is LOCAL git configuration and cannot be committed.** A hook in `.githooks/` does
nothing at all until someone runs one command, and **git offers no way for a repository to enable its
own hooks** — that is a deliberate security property of git, not an oversight to engineer around. So
this increment ships a guard that is **opt-in per clone**, and the honest mitigation is to make its
*absence* visible: `preflight` reports whether `core.hooksPath` points at `.githooks`, so an unguarded
checkout says so at session open rather than silently at the moment of the push it would have stopped.
⚠️ **`--no-verify` also bypasses any pre-push hook by design.** This is a guard against mistakes, not
against intent, and it is not a substitute for the server-side ruleset `M51` applied.

### Prediction

**Pint is the only gate that can move**, and it will see two new PHP files under `scripts/` — bare host
Pint is the form that scans that directory at all. **PHPStan cannot move**: it scans `app`, `database`,
`routes`. Vitest 134, axe, e2e and `openapi.json` unmoved — no `.ts`, no `.vue`, no route.
**`static-analysis` keeps its step count**, because no CI step is added: these hooks are deliberately
*not* a CI step, for the same reason `preflight` is not one — there are no worktrees, no local branch
and no unpushed claim inside a CI runner, so registering them there would gate nothing.

⚠️ **The one most likely to be wrong is the hook's own control, not a gate — and the reason is the last
two increments.** `M50` and `M51` each named a gate as the likeliest risk and each was wrong, and both
misses shared a shape: **the prediction was about volume when the mechanism was about position.** So
the risk here is stated mechanically instead: **a pre-push hook is invisible to every gate in this
repository.** Nothing in CI runs it, `composer quality` cannot exercise it, and its own success message
is indistinguishable from it never having been invoked. **It will therefore be proven the only way a
hook can be — by making a push that must be refused, and watching it be refused** — with the exempt
path, the claim path and the multi-commit path each driven separately, against a real remote-tracking
state rather than a simulated one.

---

## RELEASED — `M51`, the redaction and two decisions of record (merged as PR #242, `4f35058`, 6/6 green)

**Every claimed file was edited, plus one extension published *before* the file was opened.** `D6` and
`D7` are in the `ANSWERED` section of `docs/claims/decisions.md`; `D9` is filed and open.

### ⛔ THE MEASUREMENT WAS THE WORK, AND IT WAS WRONG IN THREE DIFFERENT WAYS BEFORE IT WAS RIGHT

The row said **6** sites, the triage census said **"11+"**, `D6`'s own table said **17 across 9 files**,
and the measurement here is **26 occurrences across 11 files — or 20 lines carrying at least one.**

1. ⚠️ **The unit was never stated, and it accounts for six of the difference.** `grep -c` counts
   **lines**; `grep -o | wc -l` counts **occurrences**. Every earlier figure is bare. So *"the count
   keeps growing"* was partly real drift and partly nobody saying what was being counted — and this
   release states both numbers for exactly that reason.
2. ⛔ **A SEARCH SCOPED TO THE TWO NAMES IS THE WRONG SCOPE, AND THAT IS THE TRANSFERABLE FINDING.**
   The corpus identified its subject **four** ways — the system name, the project name, the client's
   name, and a national geography standard named by acronym — and only the first two are greppable as
   one pattern. **Three sites carried no name at all**, and the sharpest was `docs/backlog-triage.md`,
   which identified the client *by description* **inside its own summary of this very decision**. A
   name-scoped search reports that file as clean. The other two were in `docs/domain-glossary.md` and
   `docs/PRD.md`.
3. ⚠️ **AND ONE FALSE-POSITIVE CLASS WOULD HAVE MADE A BLIND SUBSTITUTION DESTRUCTIVE.**
   `PROGRESS_ARCHIVE.md` matched an acronym search **55 times and not one was the client** — every
   occurrence is the developer's own Windows username inside a plan-file path
   (`C:\Users\…\.claude\plans\…`). A `sed` on the obvious pattern would have corrupted 55 paths and
   redacted nothing. **They were read before they were replaced**, which is the only reason this is a
   footnote rather than an incident.

### ⛔ THE EXPOSURE IS REDUCED, NOT CLOSED — AND THE ENTRY SAYS SO RATHER THAN IMPLYING OTHERWISE

History is **not** rewritten. The repository is public and its history is readable — `M48`'s secret scan
demonstrated exactly that by reading hundreds of commits — so every redacted string remains in the
commits that carried it. **`D6`'s original defect was a deadline that expired unnoticed and let the
default win by silence; recording the outcome as "the material is gone" would be that same defect
pointing the other way.** `D9` is filed unconditionally and recommended against, naming three costs: a
force-push is the largest instance of the mechanical-operation class this project already gates and
**no gate would catch it going wrong**; every sha changes, breaking `state.php`'s merged-title
cross-check, `R7`'s blob-size evidence and `.gitleaksignore`'s commit-scoped fingerprints; and the
material is architectural criticism of a project the owner owns rather than credentials or personal
data.

### ✅ WHERE THE LINE WAS DRAWN, AND IT IS BROADER THAN `D6`'s OWN RECOMMENDATION

`D6` option 1 kept *"every technical lesson intact"* and stripped only naming. The user's answer also
removed the vulnerability detail, so:

- **Kept in full:** every decision's rationale, including the `id`-based super-admin convention that
  `ADR-0002` §D3 exists to avoid. **A decision whose provenance is deleted is a decision nobody can
  check**, and `D6` itself argued the lesson is exactly as strong without a name attached.
- **Removed:** the exploitation mechanic spelled out beside that convention, and `ADR-0003`'s itemised
  inventory of the legacy system's repository and CI posture — both read as a security and operations
  report on a third party rather than as a reason for a choice here.
- **Checked and kept:** `app/Models/ScopeNode.php`, its migration and `docs/multi-tenancy-rbac-design.md`
  illustrate the **scope-tree feature** with three unrelated examples; they describe a customer's data.
  `.claude/settings.local.json` matches the name twice and is **gitignored and has never been on the
  trunk**, so it is out of scope for a redaction of *published* material — recorded, not edited.

### ✅ `D7` APPLIED, AND SEQUENCED SO THAT IT TESTS ITSELF

Ruleset `21935815`, `enforcement: active`, on `~DEFAULT_BRANCH`, requiring **all six** contexts **read
from run `33398663198`** rather than from `D7`'s list — `D7` records that an earlier plan said *five*,
and a ruleset built on the wrong number leaves a gate non-blocking, which is the failure it exists to
prevent. GitHub reports **`current_user_can_bypass: always`**, and this close-out's direct
`git push origin HEAD:main` is the **live exercise** of that bypass rather than an assumption about it.

✅ **AND THE PUSH PROVED BOTH HALVES, WHICH IS BETTER THAN THIS ENTRY FIRST CLAIMED.** The close-out
pushed directly to `main` with the ruleset `active` and GitHub answered:

> `remote: - 6 of 6 required status checks are expected.`

**The rule engine RAN on a direct push, evaluated all six contexts, found every one of them
unsatisfied — and the push landed anyway.** That is the refusal side demonstrated in substance rather
than declared: the only thing standing between that message and a rejection is the bypass actor, so the
same push from a non-bypassed identity is refused, and the ruleset is not a decoration.

⚠️ **This paragraph originally said the block was "asserted rather than measured", and that was wrong.**
It was written before the push, on the assumption that a bypassed push would print nothing — GitHub in
fact reports the violation it is waiving. **The strict negative control was still not run** (stripping
the bypass actor, pushing, watching the rejection, restoring): it was attempted and **blocked by this
environment's safety classifier**, correctly, because it edits a live protection setting. What is NOT
demonstrated is a rejection observed end to end from an identity that CANNOT bypass, which nothing here can
produce. The remaining cheap confirmation is the first pull request whose run goes red: if GitHub
refuses that merge, the `required_status_checks` rule is confirmed on the PR path too.

⚠️ **TWO RULES BEYOND WHAT `D7` SPECIFIED WERE ADDED, AND ARE NAMED HERE RATHER THAN LEFT TO BE FOUND.**
`deletion` and `non_fast_forward`. Both are conservative, both keep the owner bypass, and the second is
directly load-bearing for `D9`: **a history rewrite now requires a deliberate bypass instead of being a
single command.** That is a scope addition, and a reader who disagrees should say so — it is one API
call to remove.

### ⛔ THE PREDICTION NAMED THE WRONG GATE FOR THE SECOND INCREMENT RUNNING

The claim called `citation-liveness` *"the one most likely to be wrong … named for a measured reason
rather than a hunch: it is exactly what caught `M50`"*, on the grounds that nine documents change length
and the ledger has **zero headroom** at 18/18. **It stayed at 18 and never moved.** The reason is one
the claim did not think through: this diff is overwhelmingly **substitution**, not insertion or
deletion, so line numbers barely shifted — whereas `M50` added a ten-line header to a cited file.

⚠️ **That is now two increments in a row where the named risk was reasoned soundly and concluded
wrongly**, and the shape is the same both times: **the gate that broke, or didn't, was decided by
whether line NUMBERS moved — not by how much text changed.** `M50` changed 8 KB and broke a citation;
`M51` changed 12 files and broke none. Byte volume is the wrong predictor; line-offset displacement is
the right one, and neither claim used it.

**The rest of the prediction was dull and correct**, which is the point of writing it down: a
documentation-only diff cannot move Pint, PHPStan, Vitest, axe, e2e or `openapi.json`, and none moved.
`tracker-lint` stayed green — the archive edit is a substitution, so the byte delta is nowhere near
`DROP_BYTE_LIMIT` and **no surgery marker was owed**.

### ✅ THE CLAIM EXTENSION WAS PUBLISHED BEFORE THE FILE WAS OPENED

`docs/backlog-triage.md`, as its own commit, pushed to `main` before the edit, on a branch carrying
nothing else so `HEAD:main` published exactly it. **`M50` recorded getting this order wrong one
increment earlier; this is the corrected form**, and it is recorded because a rule that is only ever
described is a rule nobody has demonstrated.

### The claim, preserved

Taken 2026-08-31. Branch `m51-protection-and-redaction`, cut from `origin/main` at `6d4c3a0`, PR into
`main`. Rows: **`D6`** and **`D7`** in `docs/claims/decisions.md`, both **answered by the user**, plus
`D6`'s surviving `major` in `docs/feature-backlog.md`. Neither is a defect row; both are decisions, and
the user has supplied the answer to each.

### Evidence verified

**`D7` — every fact re-checked against the live repository, not read from the entry:**

- `gh api …/branches/main/protection` → **`404 Branch not protected`**. ✅ **held**
- `gh api …/rulesets` → **`[]`**. ✅ **held** — net-new control, nothing to amend.
- **The six required contexts, read from a real run** (`33398663198`, this project's own post-merge run
  on `main`) rather than from `D7`'s list: `Static analysis, style & security` · `Tests (Pest on
  PostgreSQL)` · `Frontend build & type-check` · `Design system a11y (axe)` · `Contract tests (OpenAPI)`
  · `E2E (Playwright + axe)`. ✅ **six, and they match `D7`'s six exactly.** `D7` already records that
  an earlier plan said **five**, so the entry is corroboration and the run is the source.
- Rule 7(g) still requires a direct `git push origin HEAD:main` for the claim commit — this very claim
  is one — so blanket protection would break the mechanism that makes a claim a lock. ✅ **held**

**`D6` — the count re-derived from the tree, and it disagrees with all three prior figures:**

| Source | Figure |
|---|---|
| The original backlog row | 6 sites |
| `docs/backlog-triage.md`'s census | "11+" |
| `D6`'s own table, taken when it moved | 17 occurrences across 9 files |
| **Measured now** | **26 occurrences across 11 files** (20 lines carry at least one) |

⚠️ **AND THE UNIT IS THE FINDING, NOT THE NUMBER.** *Occurrences* and *lines carrying an occurrence*
are different measurements and the earlier figures do not say which they are: `grep -c` counts **lines**
and `grep -o | wc -l` counts **occurrences**, and on this corpus they differ by six. Both are stated
above so the next reader does not have to guess. The growth from 17 is partly real drift and partly
**this project writing about its own redaction** — `docs/claims/decisions.md`, `docs/claims/lane-a.md`
and `docs/feature-backlog.md` now hold 8 of the 26 between them, and every one of those is
meta-discussion rather than corpus.

Per-file, current tree: `PROGRESS_ARCHIVE.md` 3 · `docs/PRD.md` 3 · `docs/adr/0001` 3 ·
`docs/adr/0002` 2 · `docs/adr/0003` 2 · `docs/domain-glossary.md` 2 ·
`docs/competitive-feature-parity-matrix.md` 2 · `docs/architecture/technical-architecture.md` 1 ·
`docs/feature-backlog.md` 4 · `docs/claims/decisions.md` 2 · `docs/claims/lane-a.md` 2.

### Remedy verdict

**`D7`'s prescribed remedy transfers intact** — a ruleset requiring all six contexts with the repository
owner as bypass actor. This is the first row in this arc whose remedy needed no correction. ⚠️ **Its one
trap is a number, and the entry names it itself**: a ruleset built on five contexts leaves one gate
non-blocking, which is the exact failure it exists to prevent. The six are therefore taken from the run.

⛔ **`D6`'s recommended option is NARROWER THAN THE USER'S INSTRUCTION, and the difference is the whole
scope of the work.** Option 1 says *"replace the client name and project name … and keep **every
technical lesson intact**"*, arguing that `"an id === 1 super-admin convention duplicated across four
code layers"` is exactly as strong an argument without a name attached. The user's answer goes further:
*"it is the naming **plus the vulnerability detail** that goes."*

**Reading taken, stated here so the diff can be judged against it rather than reverse-engineered:**

1. **Identification goes everywhere** — the project name, the client name, and the sentences that
   identify the client by description (a named national health department, "a single government
   department", "government-reporting tool"). Replaced with a non-identifying description.
2. **The architectural lesson stays, expressed as a fact about THIS project's choice** — MySQL-shaped
   gaps → PostgreSQL; no tenant concept → RLS; a fragile id-based super-admin convention → an explicit
   `is_super_admin` boolean. `ADR-0002`'s D3 keeps a rationale, because a decision whose provenance is
   deleted is a decision nobody can check.
3. **The enumerated audit of a third party's posture goes** — the exploitation mechanic (*"silently
   transferable if user #1 were ever deleted and the ID reused"*), and `ADR-0003`'s repository-state
   inventory (Sail present but unused, CI limited to PHPUnit, no deploy stage), which reads as a
   security and operations report on somebody else rather than as a reason for a choice here.

⚠️ **THE BOUNDARY BETWEEN (2) AND (3) IS A JUDGEMENT CALL AND IS FLAGGED AS ONE.** A stricter reading
would also strike the `id === 1` fact, which would leave `ADR-0002` §D3 asserting a decision with no
recorded reason. The call is recoverable in the *removed-too-much* direction and not in the other, and
**git history is not being rewritten**, so nothing is destroyed either way — that asymmetry is why the
call is taken rather than escalated.

### Files

`docs/claims/decisions.md` (`D6` → ANSWERED, `D7` → ANSWERED, **new `D9`**) · `docs/feature-backlog.md`
(close `D6`'s row to what was *done*) · `docs/PRD.md` · `docs/domain-glossary.md` ·
`docs/competitive-feature-parity-matrix.md` · `docs/adr/0001-postgresql-over-mysql.md` ·
`docs/adr/0002-multi-tenancy-shared-db-rls.md` · `docs/adr/0003-hosting-laravel-cloud.md` ·
`docs/architecture/technical-architecture.md` · `PROGRESS_ARCHIVE.md` · `docs/claims/lane-a.md` ·
`PROGRESS.md` (own block) · `docs/gate-baselines.md` (close-out).

⚠️ **`PROGRESS_ARCHIVE.md` IS A DATED HISTORY FILE AND IS STILL REDACTED**, which is a deliberate
exception to *never rewrite a dated record*: what is removed is an **identifier**, not a claim, so no
statement in the log changes truth value. Named here because the general rule is a good one and this
increment is stepping around it on purpose.

⚠️ **AND THE META FILES ARE REDACTED TOO, OR THE REDACTION IS THEATRE.** A decision entry that records
*"we removed the name"* while printing the name is self-defeating. `decisions.md`, `feature-backlog.md`
and `lane-a.md` therefore describe the search terms without reproducing them.

**Shared artefacts taken:** `docs/**`, `PROGRESS.md`, `PROGRESS_ARCHIVE.md`.
**Paired files taken:** none from 7(b-bis). ⚠️ **One paired-shaped coupling is taken:** the
citation-liveness ledger — this diff edits nine documents that other documents cite by `file:line`, so
`scripts/citation-liveness-lint.php` is the partner and any citation this diff shifts must move with it.
**Namespaces spent:** decision id **`D9`** (the history-rewrite question, filed unconditionally and
recommended against). **No ADR** — `D6` and `D7` are decisions of record in `decisions.md`, and the
collapse ADR is `M50`'s. No migration, no `§D`, no exceptions entry. `0010` stays reserved for H1d.

### CLAIM EXTENDED — `docs/backlog-triage.md`, and this one is published BEFORE the file is opened

✅ **`M50` recorded getting this exact order wrong one increment ago; this is the corrected form.** The
extension is its own commit, pushed to `main` before the file is edited, and the branch carries nothing
but this commit — so `git push origin HEAD:main` publishes exactly it, which is the distinction `M48`
paid for.

**Why it was missed:** the claim's file list was built from a search for the two **names**, and
`docs/backlog-triage.md` contains neither. It identifies the client **by description instead**, in its own summary of this very decision.

⛔ **THAT IS THE FINDING, AND IT GENERALISES BEYOND THIS ROW: A REDACTION SCOPED TO THE LITERAL STRINGS
IS THE WRONG SCOPE.** Searching for the two names returns 26 occurrences in 11 files. Searching for the
identifying *description* returns sites the name-search cannot see, in files the name-search says are
clean. The corpus identifies its subject in at least four separate ways — the system name, the project
name, the client's name, and a national-geography standard — and only the first two are greppable as a
unit. **Three sites were found this way after the claim was written**, and each is in a file whose hit
count for the names is zero or already accounted for:

- `docs/backlog-triage.md` — the client named by description, in the triage's own summary of `D6`.
- `docs/domain-glossary.md` — two phrases tying a glossary term to the client's own sector and
  agency, in a row whose **term** is worth keeping.
- `docs/PRD.md` — a national geography standard named by its acronym, in a scoping bullet that is
  otherwise a real statement about this product.

### ⚠️ CHECKED AND DELIBERATELY NOT REDACTED, so the next reader does not re-litigate them

Two sites match the identifying words and are **product content, not provenance**, and redacting them
would damage the documentation to no benefit:

- `app/Models/ScopeNode.php` and its migration — a comment illustrating the **scope-tree feature** with
  three unrelated examples, one of which happens to be a national geography. It describes a customer's data, not the legacy client.
- `docs/multi-tenancy-rbac-design.md` — the same illustration, same reason.

**`docs/adr/0002`'s two hits are also left**: *"tenants collecting health/personal data"* and
*"plausibly government or enterprise"* are generic risk language that names nobody.

### Prediction

**No gate can move on content.** PHPStan scans `app`, `database`, `routes`; this diff is documentation
only — no `.php`, no `.ts`, no `.vue`, no route, so Pint, PHPStan, Vitest, axe, e2e and `openapi.json`
are all structurally unable to react. **Pest cannot move either**, which is worth saying because a
documentation diff that reddens a test would mean a test is asserting on prose.

⚠️ **The one most likely to be wrong is `citation-liveness`, and this time it is named for a measured
reason rather than a hunch: it is exactly what caught `M50`.** Nine documents change length, and
`docs/feature-backlog.md` alone carries citations into `PRD.md`, `technical-architecture.md` and the
ADRs. `M50` shifted one file by ten lines and broke a citation into it. This diff shifts nine. **The
ledger is at 18 against a ceiling of 18, so there is no headroom at all** — one shifted citation is red.

⚠️ **Second most likely: `tracker-lint` R1/R7 on `PROGRESS_ARCHIVE.md`.** The archive is 2.4 MB and the
redaction touches it, but the edit is a substitution rather than a deletion, so the byte delta should be
near zero and certainly nowhere near `DROP_BYTE_LIMIT`. **If that prediction is wrong it will be wrong
loudly**, which is the useful direction.

**Branch protection is applied AFTER the merge and BEFORE the close-out push, deliberately**, so that
this increment's own close-out — a direct `git push origin HEAD:main` — is the live test of the owner
bypass rather than an assumption about it.

---

## RELEASED — `M50`, one lane, one worktree, and a retired lane that a gate can now see (merged as PR #241, `56fbb49`, 6/6 green)

**Every claimed file was edited, and the claim was extended to two more that it should have named.**
`fb-lane-b` and `fb-lane-c` are gone, `main` is checked out nowhere but here, and the decision of
record is `docs/adr/0022-single-lane-development.md`. Standing Rule 7 is superseded **in place**.

✅ **THE FIX PROVED ITSELF AT THE MOMENT OF MERGE.** `gh pr merge 241` returned cleanly with no local
error — the first time in this arc. Every previous merge errored *after the merge had already landed*,
because `main` was checked out in `fb-lane-b` and git refuses one branch in two worktrees. The
increment's own close-out is the end-to-end proof of its own headline.

### ⛔ THE PREDICTION NAMED THE WRONG GATE, AND TWO I DID NOT NAME WENT RED

**This is the part worth keeping.** The claim named `tracker-lint` R3 as *"the one most likely to be
wrong … R6 is the trap the plan names and is therefore the one that will get attention; R3 is the one
nobody is looking at."* **R3 never moved.** It anchors on the `## Next Session` *heading*, which this
diff keeps — so the reasoning that made it a risk was sound and the conclusion was wrong.

**The two that actually went red were neither of them, and both were mine:**

1. **`tracker-lint-controls` — 5 of 11 cases failed.** Its fixtures build a synthetic `PROGRESS.md`
   carrying **both** lane markers, so the new set-check failed inside every case. **A control harness
   is a consumer of the gate it controls**, and changing a gate's expectation breaks its own fixtures
   exactly as it breaks the tracker. `M49` built that harness one increment ago; the first change to
   R6 since found it. The fixture models the tracker, so it now models one lane — 11/11.
2. **`citation-liveness` — the ledger went 18 → 19 against a ceiling of 18.** This diff's own
   `lane-b.md` header pushed the cited text from `:29` to `:39`, and a backlog citation landed on a
   horizontal rule. That is `M34`'s *"a citation into a file your own diff edits must be re-read after
   the edit"* — **hit by an increment that had the lesson in front of it.** Re-pointed; back to 18/18.
   ⚠️ `ACCESS-MATRIX.md:446` was checked rather than assumed: dead at `HEAD` too, so not this diff's.

**The generalisation: I predicted the gate whose EXPECTATION I was changing, and missed the two gates
that CONSUME the thing I was changing.** A control fixture and a citation are both consumers, and
neither is visible from the file being edited.

⚠️ **The dull half of the prediction held.** PHPStan could not move and did not — it scans `app`,
`database`, `routes`. Pint was named as the only gate that could move; it stayed green over **1424**
files, and preflight proved the scan live with its own probe before the `PASS` was believed. Vitest
134, axe, e2e and `openapi.json` unmoved.

### ✅ THE ROW'S REMEDY WAS WRONG IN BOTH WAYS THE CLAIM PREDICTED, AND BOTH WERE MEASURED

*"`git worktree remove` is the whole fix"* — measured, `exit 128`, refused on the modified tracked
file. Cleared by saving the bytes outside the repository, **proving the saved copy reconstructed them
byte-for-byte before the original was touched**, then restoring the file. `--force` was never used,
and the guard was treated as working rather than as an obstacle.

⚠️ **And the removal only half-succeeded, which is this project's signature shape.**
`git worktree remove` reported `failed to delete … Function not implemented` on **both** worktrees —
yet `git worktree list` came back clean and the `.git` pointer files were gone. **The registration was
removed and the directories were not**, so a command that reported failure had done most of its job.
The husks were deleted separately, and the leftovers that survive on purpose are recorded rather than
discovered: the Docker volume `fb-lane-b_pgdata` (both stacks went down **without `-v`**, because a
removal that is easy to do and impossible to undo belongs to a human) and both worktrees' gitignored
`.env` files, copied to `C:/tmp/m50-lane-c-rescue/` because they were the only copies.

### ✅ FIVE CONTROLS, AND THE ONE THAT MATTERS IS THE MUTATION

| Control | Result |
|---|---|
| Remove Lane B's marker, **gate unedited** | **RED** — R6 alone, 1 check in 1 group, R3 untouched |
| Resurrect `**LANE B NEXT PROMPT`, gate current | **RED** — set-check alone; the per-lane loop **passes** |
| **M-a: set-check disabled**, marker still resurrected | **GREEN** — so the set-check is the only thing catching it |
| Remove Lane A's marker | **RED** — both halves, 2 checks in 1 group |
| Remove `## Next Session` | **RED** — R2 and R3, 2 checks in 2 groups |

⛔ **`M-a` IS THE ONE THAT MAKES THE OTHERS WORTH ANYTHING.** A resurrected retired lane passes the
per-lane loop, because that loop only ever looks for the lanes it already knows about. Without the
set-check the whole run is **green** with a dead lane's hand-off sitting in the tracker — which is
`M43`'s lesson exactly: a structural check can be fully green and entirely decorative, and only
mutating the **mechanism** tells you which. Every mutation moved the sha256; every restore was
byte-exact; the tree was clean afterwards.

### ⛔ THE CLAIM'S FILE LIST WAS SHORT BY TWO, AND THE EXTENSION WAS WRITTEN AFTER THE EDIT

`CLAUDE.md` and `docs/ACCESS-MATRIX.md`. Rule 7(g) wants an extension to be its own pushed commit
*before* the file is opened; these were opened first. **Recorded as a deviation rather than dressed up
as process.** Both were found by sweeping for stale references *after* the change — `CLAUDE.md` still
read `--lane=a  # or --lane=b`, and ACCESS-MATRIX described `fb-lane-b` as a live stack with its own
ports and workspace, which **my own change had made false**: the `M46` defect class, filed against
myself. `CLAUDE.md`'s edit is **subtractive only**, because R8 gates that file against namespace
literals and the fix must not arm it.

⚠️ **The mitigating fact is the one that no longer generalises: there is no other lane to collide
with.** That is why the deviation was harmless *here*, not why it was correct — and it is precisely
the check this realignment's pre-push hook is meant to make mechanical instead of remembered.

### ⛔ THE INCREMENT'S OWN SESSION REPRODUCED THE DEFECT IT WAS FIXING

This session opened while **another session (PID 24104) was live in this worktree** finishing `M49`'s
close-out. It wrote `docs/claims/lane-a.md` and `PROGRESS.md` minutes after this session's first read,
and moved `HEAD` from `m49-r7-event-base` to `m49-closeout` underneath. `git status` was clean at
session start and was not clean shortly after. **Nothing was lost only because that session committed
before it switched** — the coin-flip `M33` described. The work was held **read-only** until that
session pushed, which is why there was nothing to reconcile. **Three incidents of this shape in four
days, and zero concurrent increments**, is the whole argument the ADR makes.

### ⚠️ WHAT WAS NOT DONE, STATED RATHER THAN LEFT TO BE DISCOVERED

**`docs/claims/lane-b.md` is KEPT, and deleting it would have been a numbering defect** — `state.php`
derives the increment from the `## RELEASED` headings of both claim files and it holds ten releases
recorded nowhere else. Its `## Template` **heading** is kept as the truncation anchor, so removing the
template body is provably neutral: **M49 / M50 and 33 numbered headings, before and after.** Only the
fenced example went, and it contained a verbatim `## RELEASED` line the truncation existed to exclude.

**Standing Rule 7 was not deleted**, so the tracker delta is `+5 lines, −8,028 bytes` — under both
limits, and **no surgery marker was owed.** A large deletion was available and was not taken:
`CLAUDE.md` holds the instruction and `PROGRESS.md` holds the reasoning, and the reasoning is what
makes the next person hesitate before reintroducing two lanes.

**Namespaces: ADR `0022` and nothing else.** No migration, no `§D`, no exceptions entry, no decision
id — the collapse was an instruction, not a question. `0010` stays reserved for H1d.

### The claim, preserved

Taken 2026-08-31. Branch `m50-lane-collapse`, cut from `origin/main` at `bc7bc07`, PR into `main`.
Row: the `minor` under *Tracker, CI and process* — *"`fb-lane-c` is an abandoned worktree that every
numbering check must now read past"* (`docs/feature-backlog.md`, in the tracker/CI cluster). **The row
is one part of this increment, not the whole of it**: the collapse itself is user-directed, and the row
is the piece of it that was already filed.

### ⛔ THIS CLAIM CROSSES THE LANE BOUNDARY ON PURPOSE, AND IT IS THE ONE INCREMENT ENTITLED TO

`docs/claims/lane-b.md` is edited by Lane A here. That is forbidden by the rule this increment
**abolishes**, which is the only reason it is safe — and it is stated rather than done quietly, on the
`M28` precedent. Three independent facts make it safe rather than merely permitted, all verified this
session against the merged tree:

- `lane-b.md`'s `## Status` is `NO ACTIVE CLAIM` and has been since 2026-08-27.
- **It carries no forward queue.** The file was mapped heading by heading; the only forward-looking
  text under `## Status` is a warning *to* Lane B about reading `lane-a.md`, which is not a claim.
- No Lane B session is running, and its worktree is clean at 68 commits behind.

⚠️ **`state.php`'s `LANE_A_WRITABLE` reports this and does not gate it** — a disagreement inside
`lane-b.md` is deliberately *reported* because Lane A could not fix it without breaking the rule that
found it. So no gate will object, which is precisely why the crossing is declared here instead.

### ⛔ AND THE SESSION THIS CLAIM OPENS IN HAD TWO WRITERS, WHICH IS THE ROW'S OWN THESIS ARRIVING EARLY

This session began while **another Claude session (PID 24104) was live in this same worktree**,
finishing `M49`'s close-out. It wrote `docs/claims/lane-a.md` at 19:59:35 and `PROGRESS.md` at 20:00:39
— after this session had started and after its first read — and it moved `HEAD` from
`m49-r7-event-base` to `m49-closeout` underneath. `git status` was clean at session start and was not
clean minutes later. **Nothing was lost, because that session committed before switching**; the
`M33` incident is what happens when it does not. This increment was held read-only until that session
pushed `bc7bc07`, and the wait is the reason there is nothing to reconcile.

### Evidence verified

Every claim in the row opened against the merged tree:

- **Three worktrees** — `git worktree list` returns `dev_formbuilder_app`, `fb-lane-b` on `main`
  (clean), `fb-lane-c` on `lane-c-bootstrap` at `b44a36c`. ✅ **held**
- **`lane-c-bootstrap` is M14-era** — `b44a36c` is *"Merge pull request #204 from …/m14-closeout"*.
  ✅ **held**
- **"104 commits behind `origin/main`"** — ⚠️ **moved to 177.** Correct when filed, stale now; the row
  is a dated measurement and the drift is the reason it may not be quoted forward.
- **"one dirty file"** — ✅ **held**, and identified: `packages/design-system/package-lock.json`,
  `+1/−21`, every deletion a bare `"peer": true` key plus one comma reformat, mtime 2026-08-25 23:05.
  **npm peer-resolution bookkeeping, not work.** Regenerable, and preserved anyway before removal.
- **No `docs/claims/lane-c.md` anywhere** — ✅ **held**, and stronger than the row says:
  **`lane-c-bootstrap` does not exist on the remote either**, verified against `git ls-remote --heads
  origin`. Lane C was cut, never used, never published, and released nothing — no `M`-series entry is
  attributable to it.

### Remedy verdict

**The row's prescribed fix — *"`git worktree remove` is the whole fix"* — is WRONG, and wrong in the
direction this repository keeps cataloguing: it would have succeeded on the easy half and failed
silently on the rest.**

1. ⛔ **`git worktree remove` REFUSES on `fb-lane-c`**, because the worktree holds a modified tracked
   file. The refusal is the guard working. The row treats removal as unconditional and does not
   mention it.
2. ⛔ **The row scopes the defect to `git worktree list` being noisy.** The actual coupling is in code:
   `tracker-lint.php` R6 loops `['A', 'B']` and requires `/^\*\*LANE {$lane} NEXT PROMPT/m` **exactly
   once at line start** in `PROGRESS.md`. Removing Lane B's hand-off without amending R6 **in the same
   commit** reddens `main`. `preflight.php` and `next.php` each carry their own two-entry `LANES`
   const. Removing a worktree fixes none of that.
3. ⚠️ **The row is silent on the numbering input that must NOT be removed.** `state.php` derives the
   increment from the `## RELEASED` headings of **both** claim files; `lane-b.md` holds ten releases
   that exist nowhere else. Deleting it would drop one of the two independent sources and trade a tidy
   listing for a numbering collision.

**So the row is a floor in both halves**, which is now the expected shape rather than a surprise.

### Files

`docs/claims/lane-a.md` · `docs/claims/lane-b.md` *(boundary crossing, above)* ·
`docs/claims/TEMPLATE.md` · `PROGRESS.md` · `scripts/tracker-lint.php` · `scripts/preflight.php` ·
`scripts/next.php` · `docs/adr/0022-<slug>.md` *(new)* · `docs/feature-backlog.md` ·
`docs/gate-baselines.md` *(regenerated at close-out, from this increment's own post-merge run)*.

⚠️ **`docs/claims/TEMPLATE.md` is in the list for a reason that is easy to miss.** Its own warning says
*"`lane-b.md` still carries its own copy … Lane B adopts this file **on its next increment** by
deleting its copy and linking here."* **Lane B has no next increment after this one**, so that sentence
becomes false at the moment of the collapse and has to be rewritten in the same PR — the same
paired-fact shape as R6.

**Shared artefacts taken:** `PROGRESS.md` (Standing Rule 7, the parallel-lanes section, and Lane B's
hand-off line — i.e. **not** own-block-only, which Rule 7(d) normally requires and which this increment
is entitled to for the same reason as the `lane-b.md` crossing), `docs/feature-backlog.md`,
`docs/claims/*`, `docs/adr/`, `docs/gate-baselines.md`.

**Paired files taken:** none from the 7(b-bis) table. **Two paired-shaped couplings are taken and are
named here because they behave identically:** *(Lane B hand-off ⇄ `tracker-lint.php` R6)* and
*(`lane-b.md`'s template copy ⇄ `TEMPLATE.md`'s note about it)*. Each must move in the same commit as
its partner or the trunk goes red.

**Namespaces spent:** **ADR `0022`** — derived from `state.php`, and the `0010` gap is **reserved for
H1d, not free**. No migration prefix, no `§D`, no exceptions entry, no route, no ability key. No
decision id: the collapse is a user instruction, not a question, and `D6`/`D7` belong to the next
increment rather than this one.

### CLAIM EXTENDED - two files, and the extension is recorded AFTER the edit rather than before

⚠️ **STATED AS A DEVIATION, NOT PRESENTED AS PROCESS.** Rule 7(g) wants an extension to be its own
pushed commit *before* the file is opened. These two were opened first and the extension written
afterwards, so the rule was not followed and saying so is the point:

- **`CLAUDE.md`** - its start-every-session block still read `--lane=a  # or --lane=b`, which this
  increment makes false. Subtractive only: **no number was added**, because `tracker-lint` R8 gates
  that file against namespace literals and the fix must not arm it. Re-verified green after the edit.
- **`docs/ACCESS-MATRIX.md`** - the canonical local-access document described `fb-lane-b` as a live
  second stack with its own ports, workspace and Mailpit. **My own change made that document false**,
  which is the M46 defect class exactly, so leaving it would have been filing a documentation-truth
  row against myself.

**Both were found by sweeping for stale references after the change rather than by the plan**, which
is why neither was in the file list. The mitigating fact is the one that no longer holds in general:
there is no other lane to collide with. That is a reason the deviation was *harmless here*, not a
reason it was correct - and it is precisely the check the pre-push hook in this realignment's third
increment is meant to make mechanical instead of remembered.

### Prediction

**PHPStan cannot move and will not.** It scans `app`, `database`, `routes`; this diff is `scripts/`,
`docs/` and `PROGRESS.md`. Saying so beats quoting an unchanged number.
**Pint can move and is the only gate that can** — three `scripts/` files are edited, and bare host Pint
is the form that sees `scripts/` at all. **Vitest stays at 134 files**, Storybook axe and E2E unmoved,
`openapi.json` byte-identical: no `.ts`, no `.vue`, no route, no controller.
**`static-analysis` keeps its step count** — no CI step is added or removed.

⚠️ **The one most likely to be wrong is `tracker-lint` R3, not R6.** R6 is the trap the plan names and
is therefore the one that will get attention; **R3 is the one nobody is looking at.** It counts
`Next Session` across **both** tracker files and requires exactly `EXPECTED_CROSS_FILE_NEXT_SESSION`,
which is **1** — and the parallel-lanes block being edited sits directly beneath the
`## Next Session — Resume Here` heading that supplies that single occurrence. A collapse that tidies
one line too far takes R3 red for a reason that has nothing to do with lanes. **Both R3 and R6 get a
deliberate defect that turns them red, restored by byte comparison; the mechanism is mutated, not the
declaration.**

⛔ **RUN `php scripts/state.php` FOR EVERY NUMBER.** Increment, ADR, migration prefix, exceptions-log
entry, open rows, open decisions, and how far behind the trunk `docs/gate-baselines.md` has fallen.
Nothing in this file or in `PROGRESS.md` is the authority for any of them any more.

✅ **`CLAUDE.md` IS THE IMPERATIVE LAYER AND IS AUTO-LOADED.** Read it before this file. It carries no
numbers at all, and `tracker-lint` R8 keeps it that way.

⛔⛔ **`git push origin HEAD:main` PUSHES THE WHOLE BRANCH, NOT THE COMMIT YOU JUST WROTE — AND THAT IS
HOW `M48`'s SURGERY REACHED THE TRUNK WITHOUT A SQUASH MERGE.** Rule 7(g) prescribes exactly that
command for publishing a claim, and it is correct on an empty branch. It is **wrong the moment you use
it for a mid-build claim EXTENSION**, because by then the branch carries the work. **Publish an
extension with a PR, or push only that commit** (`git push origin <sha>:main`). The PR was then
auto-marked merged, its remaining commits stranded, and the end-to-end proof lost. **Nothing was
damaged and nothing was force-pushed** — but the increment's headline deliverable was.

✅ **`R7` IS NO LONGER BLIND ON A `push` — `M49` CLOSED IT.** The base now comes from
`github.event.before`, and every path either resolves or **exits 2**; the run prints which base it used
and where it came from, so a re-blinding is visible in the log instead of presenting as an absent
marker. ⚠️ **What is still unexercised is a multi-commit `push` on real GitHub**: a squash merge is one
commit, so `before..HEAD` and `HEAD~1..HEAD` coincide on it. **The `## Current Status` surgery is the
first real test**, and it is the open `major`.

✅ **`R7` HAS NOW FIRED, AND `M48` IS THE FIRST INCREMENT IT EVER LOOKED AT.** What that cost to learn:
`ci.yml`'s `fetch-depth: 2` — chosen in `M40` *for this rule* — leaves only the PR's **last** commit in
the clone, so a marker on any earlier one is invisible. Fixed to `0`. **A gate that has never fired has
never been tested**, and this arc has now produced that lesson three times over.

⚠️ **THE END-TO-END SQUASH PROOF IS STILL OWED**, and is handed to the `## Current Status` surgery,
which is the next `major` and is filed with its measurement. Merge it with an explicit `--body` whose
first content line is the marker, and read the post-merge run on `main` rather than the PR run.

---

## RELEASED — `M49`, R7 takes its base from the event payload and the clone can no longer lie about its shape (merged as PR #240, `12b0ef5`, 6/6 green)

**The `major` is closed and the rule no longer assumes the unit of change is one commit.** On a `push`
the base is `github.event.before`; on a `pull_request` it stays the merge commit's first parent, with
the clone's shape asserted against the payload. Every path either resolves or **exits 2**, and every
run now prints `R7 base is … (…)` — the provenance line is the artefact, because a re-blinding then
appears in the log as the wrong base instead of as a mysteriously absent marker.

**Every claimed file was edited and the claim was not extended.** Namespaces spent: **nothing from
either** — no migration, no ADR (`0022` free, `0010` reserved), no `§D`, no route, no exceptions entry.
One decision id, `D8`, from `decisions.md`'s separate namespace.

### ⛔ THE ROW'S EVIDENCE HELD IN FULL AND ITS PRESCRIBED REMEDY WAS HALF WRONG

Every citation opened and every one held — the first time in this arc that has happened. The remedy is
the half that broke, and it broke in the direction this repository keeps cataloguing: **it would have
printed a number.**

The row asked for `github.event.pull_request.base.sha` on the `pull_request` arm. `base.sha` is the
base tip as of the **event**; the checkout is `refs/pull/N/merge` as of the **run**. When `main`
advances between them — routine with two lanes, and already recorded here as *"a gate number moving on
a diff that cannot move it is the OTHER LANE"* — `base.sha..HEAD` sweeps in the other lane's commits
and reports **their** `PROGRESS.md` delta as this pull request's. The merge commit's first parent is
exact, so the arm keeps `HEAD~1` and the payload is spent on the one job `base.sha` cannot do.

**That reallocation is what closes the `fetch-depth` guard `minor` as well.** That row named its own
difficulty precisely — *"`R7` cannot tell the two apart from inside"*, because *"no commit in this
range carries the marker"* is the same observation whether the commit is missing or the marker is.
Comparing the range's commit count against `github.event.pull_request.commits` is the distinction, and
it converts a silent re-blinding into a loud broken gate.

### ✅ THE PREDICTION NAMED AS MOST LIKELY WRONG WAS RIGHT, AND THE REAL PAYLOAD SETTLED IT EXACTLY

The claim said the commit-count assertion was the thing to doubt: `github.event.pull_request.commits`
had never been read in this repository, and if it counted something other than what
`git rev-list --count HEAD~1..HEAD` sees, the control would be right in shape and wrong in arithmetic.
`>=` was the hedge.

**Measured on PR #240's own run:** `R7 base is HEAD~1 (the merge commit's first parent; 6 commit(s) in
range against 5 in the pull request)`. Six against five — the PR's commits plus the synthetic merge
commit, **exactly**. The hedge was never needed, and `>=` stays because the failure it guards is a
range holding *fewer* commits; a strict equality would redden on legal topologies, and a false red in
the one rule that must never cry wolf costs more than the extra commits it would catch.

⚠️ **The rest of the prediction was dull and correct, which is the point of writing it down.** PHPStan
could not move and did not — it scans `app`, `database` and `routes`, and this diff is `scripts/`,
`.github/`, `composer.json` and documentation. Vitest, axe and e2e likewise. **Pint was named as the
only gate that could move, and it moved**: it failed on `scripts/tracker-lint-controls.php` with three
fixers, which is also the proof it scanned `scripts/` at all. `static-analysis` gained one step, 21 to
22, exactly as predicted, so its baseline changes rather than fails.

### ⛔ THE LARGER HALF IS THE HARNESS, AND IT WAS COMMITTED RED

`scripts/tracker-lint-controls.php` — eleven synthetic git histories, the shipped bytes of the gate
copied into each. Committed **red first** (`dac636e`), against the unfixed gate:

| | against the unfixed gate | after the fix |
|---|---|---|
| `C1`, `C3` — the push arm sees the deletion | **FAIL** | pass |
| `C2`, `C4` — **the defect**, on the same fixture, against `HEAD~1` | pass | pass |
| `C5`–`C7` — absent, zeroed and unreachable base sha | **FAIL** | pass |
| `C8` — the `pull_request` arm | pass | pass |
| `C9`, `C10` — clone-shape assertion | **FAIL** | pass |
| `C11` — an ordinary edit stays under both limits | pass | pass |

⛔ **`C2` AND `C4` ARE THE ONES THAT MATTER AND THEY ARE KEPT PERMANENTLY.** They run the same bytes
with the pre-`M49` base and assert the gate sees `+0 lines and +0 bytes` — `M48`'s incident in
miniature. `M19`'s lesson is that a probe measuring zero proves nothing unless it touched the defect,
and a harness that only shows the new code passing has shown nothing at all.

✅ **`C8` PASSING BEFORE THE FIX IS AN HONEST CONTROL, NOT A GAP.** The `pull_request` arm was already
correct once `M48` raised the depth to `0`. The row was right to name the `push` arm alone.

⛔ **AND IT IS A CI STEP, BECAUSE `R7`'s `push` ARM CANNOT EXECUTE DURING A PR RUN.** `M47` built
controls for this gate in a detached worktree and threw them away, and the `fetch-depth` defect then
survived eight increments. A control that is not committed is a control that ran once.

### ⚠️ A MUTATION FOUND A CONTROL PASSING FOR THE WRONG REASON, INSIDE THE INCREMENT THAT WROTE IT

Two mechanism mutations, each committed and each restored by byte comparison against a copy taken
before the first write — never with `git checkout --`, which reverts to `HEAD` and eats uncommitted
work.

- **M-a — disabling the push arm's empty-base refusal left `C5` GREEN.** An empty sha fell through to
  the commit-ness check and exited 2 from a *different* branch, so the case could not tell which
  mechanism had fired. This is `M43`'s lesson exactly — a structural check can be fully green and
  entirely decorative. **All five cannot-measure cases now assert their own message rather than the
  shared `CANNOT MEASURE R7` prefix**, and `C5` then failed alone under the same mutation.
- **M-b — disabling the clone-shape assertion failed `C9` alone**, with `C8` and `C10` still green. The
  two halves are load-bearing separately, not one branch that happens to cover both.

### ⚠️ FOUR METHOD FAILURES, ALL MINE, ALL CAUGHT

1. ⛔ **A mutation reported success while never applying — the sha256 guard caught it.** `perl -0pi -e`
   in **double quotes** let the shell eat `$sha`, so the pattern matched nothing. That is `M31`'s
   defect verbatim, hit by the increment writing about it, and the only reason it was not a phantom
   green is that the sha was compared before and after.
2. **The single-quoted retry also did nothing**, for a second and unrelated reason: Perl's `-i` without
   a backup suffix is a no-op on this host. **Two shell layers, two silent no-ops**; switched to an
   exact-string edit with no shell in the path, which is `mutate.php`'s own doctrine one level down.
3. ⛔ **The fixture inherited the host's `core.autocrlf` and measured the host.** The only case that
   checks a branch out and merges came back with CR bytes in every line, so `R5` went red and `R2`'s
   line-anchored headings went red behind it — three failures in two rule groups, **none of them R7**,
   in the cases built to exercise R7. The fixture now pins `core.autocrlf` and `core.eol`.
4. **`git add -A` committed the commit-message file** the harness wrote for `-F`. Moved outside the
   repository.

### ⚠️ WHAT IS NOT PROVEN, STATED RATHER THAN DISCOVERED

**A multi-commit `push` on real GitHub.** A squash merge is one commit, so `before..HEAD` and
`HEAD~1..HEAD` coincide on it, and the close-out push is `paths-ignore`d and produces no run at all.
✅ **The post-merge run does prove the WIRING, and it is quoted here rather than asserted** — run
[33389037597](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/33389037597),
`push` on `main`: `R7 base is 017108c39957c53064adbd7b4719fa3fb0b34905 (github.event.before, via
TRACKER_LINT_BASE_SHA)`. That sha is the claim commit — the tip `main` carried before the merge —
so the payload reached the rule and the rule used it. **What it does not prove is the multi-commit
case**, because on this push the two bases coincide. **The first real exercise is the `## Current Status` surgery**, which is the open
`major`; read its post-merge run on `main`, not its PR run. Filed as its own `minor` so it cannot be
forgotten.

**And the `gitleaks` half of the guard row does not close.** `gitleaks` has no payload number to check
itself against, so it cannot know how many commits it *should* have seen: a future depth reduction
re-blinds it and it reports `no leaks found`. Re-filed as its own `minor`, with three uncosted
candidates and the honest note that a commit-count floor is a ratchet somebody has to maintain.

### ➕ BEYOND THE ROW

- **The `paths-ignore` row's first candidate cannot be written.** *"Exempt a commit whose message
  carries the marker"* has nowhere to be expressed: a `paths-ignore` filter is evaluated by GitHub
  **before a run exists**, over the pushed file paths and nothing else. Corrected in the row, and the
  remaining run-cost question promoted to **`D8`** with a recommendation rather than decided here.
- **Nothing anywhere covered `tracker-lint.php` before this** — zero references under `tests/`. That
  was not in the row and is the reason the two defects in this arc were both invisible.

### The claim, preserved

**Row** (`docs/feature-backlog.md`, the last `major` in the tracker/CI cluster): *"`R7` measures the
tip against its parent, so a large removal that is not in a push's LAST commit is invisible — and the
constitution reached `main` through exactly that hole."* Filed by `M48` on its own push rather than
predicted.

**Also taken, because this work closes it rather than because it is a second effort** (`minor`, two
rows above): *"Nothing asserts that CI's checkout is deep enough for `R7` to see the commit that
declares a surgery, and the failure presents as a missing marker rather than as a broken gate."* Its
own text names the difficulty — **`R7` cannot tell the two apart from inside** — and a payload-supplied
base plus a clone-shape assertion is exactly the assertion it asks for.

**Not taken, recorded** (`minor`, one row above): the `paths-ignore` row. One of the two remedies it
names is structurally impossible and that correction goes into the row; the rest is a run-cost decision
and goes to `decisions.md`.

### Evidence verified

Every citation opened against the merged tree at `102a9a6`, not taken from the row.

- **`R7` uses `HEAD~1` for all three reads — HELD.** `scripts/tracker-lint.php`, the R7 block: four
  `exec()` calls, `rev-parse --verify HEAD~1`, `git show HEAD~1:`, `git cat-file -s HEAD~1:` and
  `git log --format=%B HEAD~1..HEAD`. No other base is consulted anywhere.
- **`ci.yml` is `fetch-depth: 0` — HELD.** The `static-analysis` checkout, with `M48`'s reasoning
  written above it.
- **`HEAD~1` on a `pull_request` is the base tip — HELD.** `refs/pull/N/merge`'s first parent is the
  base branch tip, so the PR arm has been correct since `M48` raised the depth. **The `push` arm is
  the broken one, and the row is right to name it alone.**
- **Nothing else in the tree reads the commit graph this way — HELD.** `HEAD~1` occurs only in
  `scripts/tracker-lint.php` and in `ci.yml`'s comment *about* that script.
- ➕ **BEYOND THE ROW: nothing anywhere covers `tracker-lint.php`.** Zero references under `tests/`;
  `M47`'s controls were built in a detached worktree and thrown away, which is the reason the
  `fetch-depth` defect survived eight increments. **That is the larger half of this increment.**

### Remedy verdict

The row prescribes *"`github.event.before` on `push`, `github.event.pull_request.base.sha` on
`pull_request`, passed in as an environment variable, with `HEAD~1` kept only as a local-run
fallback."*

⛔ **RIGHT ON `push`. WRONG ON `pull_request`, AND WRONG IN THE DIRECTION THAT PRINTS A NUMBER.**
`pull_request.base.sha` is the base tip *as of the event*; the checkout is `refs/pull/N/merge` *as of
the run*. When `main` advances between them — routine here, and already catalogued as *"a gate number
moving on a diff that cannot move it is the OTHER LANE (PR CI tests a merge with current main)"* —
`base.sha..HEAD` sweeps in the other lane's commits and reports their `PROGRESS.md` delta as this
PR's. `HEAD~1` on the synthetic merge commit is the tip actually being merged into, and is exact.

**So the PR arm keeps `HEAD~1` for the measurement and spends the payload on a different job**: a
clone-shape assertion from `github.event.pull_request.commits`, which is what the guard row wants and
what `base.sha` cannot give. Resolution is keyed on `GITHUB_EVENT_NAME`, which GitHub always sets, so
a `ci.yml` edit that drops the new variables **exits 2** rather than going quiet.

Files: `scripts/tracker-lint.php`, `scripts/tracker-lint-controls.php` (new),
`.github/workflows/ci.yml`, `composer.json`, `docs/feature-backlog.md`, `docs/claims/decisions.md`,
`docs/claims/lane-a.md`, `PROGRESS.md`, `docs/gate-baselines.md`.
Shared artefacts taken: `.github/workflows/ci.yml`, `composer.json`, `docs/feature-backlog.md`,
`docs/claims/decisions.md`, `docs/gate-baselines.md`, `PROGRESS.md` (own block only). Lane B's file was
read in full and holds nothing.
Paired files taken: none.
Namespaces spent: **nothing from either** — no migration, no ADR (`0022` free, `0010` reserved), no
`§D`, no route, no exceptions entry. One `D<n>` in `decisions.md`, a separate namespace, derived at
write time.
Prediction: PHPStan **cannot** move — it scans `app`, `database` and `routes` and this diff is
`scripts/`, `.github/`, `composer.json` and docs; Vitest, axe and e2e likewise. Pint is the only gate
that can, and only on the two `scripts/` files. `static-analysis` gains one step, so its baseline step
count changes and must be re-read rather than compared. **Most likely to be wrong: the PR commit-count
assertion.** `github.event.pull_request.commits` is taken on trust from the payload and has never been
read in this repository; if it counts something other than what `git rev-list --count HEAD~1..HEAD`
sees, the control is right in shape and wrong in arithmetic. `>=` is the hedge; if that still fails the
honest fix is to drop the count and keep the reachability check alone.

---

## RELEASED — `M48`, `## Next Session` leaves the constitution, and the checkout depth that had blinded two gates (merged as PR #239, `f000a89`, 6/6 green; the surgery itself reached `main` directly — see below)

**The row is closed and the constitution is a third of the size it was.** `PROGRESS.md` **360,207 →
161,298 bytes**, 483 → 306 lines; `## Next Session` **214,178 → 15,269**, from 59.5% of the file to
9.5%. 178 lines / 200,625 bytes moved verbatim to `PROGRESS_ARCHIVE.md`, proved by a counted multiset
with exact multiplicity and byte conservation with no tolerance, **2,618,558 == 2,618,558**.

⛔ **THE INCREMENT'S REAL OUTPUT IS NOT THE DIFF. IT IS THAT ONE YAML INTEGER WAS SILENTLY BLINDING TWO
INDEPENDENT GATES, AND ONE OF THEM WAS THE SECRET SCAN ON A PUBLIC REPOSITORY.** `ci.yml` used
`fetch-depth: 2`, chosen in `M40` for `tracker-lint`'s `R7`. Consequences, both measured on this
increment rather than reasoned about:

- **`R7` cannot see a multi-commit PR.** At depth 2 the clone holds the merge commit and the PR's
  **last** commit; every earlier one is grafted away. `M48` put `[tracker-surgery]` on its phase-1
  commit and `R7` reported the marker absent **while measuring the delta perfectly**. Reproduced with
  `git fetch --depth=2 origin refs/pull/238/merge`, not inferred. Fixed to `fetch-depth: 0`; a bounded
  depth is what created this, and `3` would fail the next three-commit PR silently.
- ⛔ **`gitleaks detect --source .` SCANS GIT HISTORY, so the secret scan was checking two commits at a
  time for the repository's whole life.** At depth 0 it scanned **818 commits on the first run** and
  reported three findings that had never been reachable. ✅ **There is no real secret**: all three are
  the *same string* by sha256 — the password fixture in `PasswordStrength.test.ts`, quoted twice into
  the tracker the day that component shipped. That line already carried `// gitleaks:allow`, which
  suppresses the match **in the commit where the directive exists** and cannot reach the commits that
  predate it. `.gitleaksignore` records the three fingerprints and why. **A reassuring outcome from an
  alarming mechanism**, and the mechanism is what the filed row now carries.
- **`R7` is blind on a `push` as well, and it is filed as a `major`** rather than fixed, on the user's
  call. It compares `HEAD~1` against `HEAD`, so a removal not in the push's last commit is invisible.
  `M48`'s own four-commit push carried the 198,909-byte deletion and the run measured **zero**.

✅ **AND `R7` FIRED, WHICH IT NEVER HAD BEFORE.** `DECLARED SURGERY: 177 line(s) and 198,909 byte(s)
removed from PROGRESS.md`. ⛔ **Read the line figure twice: 177 against a limit of 200 — UNDER IT.** The
surgery that removed three-fifths of the constitution is invisible to the line threshold; the byte half
caught it at 3.98× over. Eight controls in a detached history fixture, each with a green baseline and a
byte-compared restore: marker stripped → **red on the byte limit**; restored → green with the message
md5 back to baseline exactly; `* ` and `- ` → pass; mid-sentence and indented → fail; ceiling one byte
under the file → **R1 red**. **And C3, the one worth keeping:** marker stripped *and* `DROP_BYTE_LIMIT`
raised past the drop → **exit 0**, the pre-`M47` gate classifying a 198,909-byte undeclared removal of
the constitution as an ordinary edit.

⛔ **THE DEVIATION, STATED PLAINLY AND FIRST-PERSON: `git push origin HEAD:main` PUSHES THE WHOLE
BRANCH.** Rule 7(g) prescribes exactly that command for publishing a claim, and it is right on an empty
branch. Used for a mid-build **extension**, by which time the branch carried the surgery, it put the
whole thing on the trunk without a squash merge, and PR #238 was auto-marked merged when its commits
became ancestors of `main`. Nothing was damaged, nothing was force-pushed, `main` stayed green — **but
the end-to-end proof `M47` handed to this increment was forfeited, and that was this increment's
headline deliverable.** The second and third extensions were published by cherry-pick onto `main`
instead, which is the correction. **The squash proof is handed forward to the `## Current Status`
surgery**, filed as the next `major`.

**Every claimed file was edited**, and the claim was extended twice, each time in its own pushed commit
before the file was opened: `.github/workflows/ci.yml`, then `.gitleaksignore`. Namespaces spent:
**nothing from either** — no migration, no ADR (`0022` stays free, `0010` stays reserved), no `§D`, no
exceptions entry, no route.

**How the prediction fared.** The one named as most likely to be wrong — *"`R7` reports DECLARED SURGERY
on the PR run and again post-merge"* — **was wrong, and wrong about the mechanism rather than the
outcome.** It feared the squash body, the failure `M45` and `M47` had both paid for; the actual cause
was a checkout depth nobody had connected to it. **That is the third time in this arc a prediction has
named the right gate and the wrong mechanism**, which is the argument for writing predictions down: not
that they come true, but that the measurement has something specific to disagree with. Right: `R1`'s
headroom (predicted ~39 KB, measured **38,702**); no movement in PHPStan, Vitest, axe or e2e (e2e passed
with 20 real steps on an unrelated diff); no Pint reformatting; the ledger tier reduced 19 → 18 and the
constant ratcheted with it.

⚠️ **FIVE METHOD FAILURES, ALL MINE, ALL CAUGHT, AND THEY REPEAT ONE SHAPE — AN OPERATION THAT SUCCEEDS
ON EMPTY INPUT.**

1. **A splice whose input file was empty wrote one blank line and reported success — twice**, both
   times because `getenv()` in a `php -r` read an environment variable the shell had not exported. The
   first shipped a claim extension consisting of nothing; the commit message described a change the
   file did not contain. **Fixed with a length floor** — refuse unless the inserted text is at least
   *n* lines — which is `M36`'s "a gate with no floor reports passed while blind", one layer down, in a
   splice rather than a gate.
2. **A guard asserted the wrong constant count** (3 references where the file has 5) and refused to
   write. **The guard was right and I was wrong**, which is the inverse of `M41`'s lesson and deserves
   saying: verify the check, and then believe it.
3. ⛔ **A substring guard could not tell a quotation from a declaration.** The docblock correction
   *quotes* the false sentence it retracts, so `str_contains` matched the retraction. Constrained to a
   whole-line match. **This repository's most-repeated lesson, hit by the commit writing about it.**
4. **A positive control returned green and the fixture was wrong, not the gate.** The first
   `.gitleaksignore` probe used AWS's *documented example* keys, which gitleaks allowlists. `M19`'s
   lesson — a probe measuring zero proves nothing unless it touched the thing — recurring in a new tool.
5. **`Pint --test` prints `passed` with no file count, even under `-v`.** Proven non-vacuous by a
   deliberate misformat of the exact file being edited: exit 1, naming the file and five fixers,
   restored by byte comparison.

⚠️ **AND ONE ABOUT ROWS, WHICH IS THE HALF THE CLAIM TEMPLATE EXISTS FOR.** Three of the row's four
factual counts were wrong — 14 hand-off blocks not 19, and a `(do not re-open)` range that named
`I5`, `I8`–`I12` and the whole J-series, none of which are in the section. Sweeping the block's
*"recorded, not fixed"* items **against the code rather than against the record** found **three already
closed** and **one live and filed nowhere**. Filing from the record would have produced three phantom
rows and still missed the real one.

---


---

## RELEASED — `M47`, the surgery marker becomes armable and R7 stops being blind to bytes (merged as PR #237, `8555ae1`, 6/6 green)

⛔ **THE SHARPEST FINDING IS THAT THE GATE HAD NEVER FIRED ONCE, AND THE PROOF IS A RECORDING RATHER
THAN AN ARGUMENT.** The shipped `tracker-lint` was run in a detached worktree at `1f966a4` — M45's own
merge, the largest surgery since the incident this gate exists for — and printed
`R7 delta — PROGRESS.md line delta is -133 (583 to 450)`. **The ordinary branch.** A 161,528-byte
removal of the constitution, declared twice in the trunk message, classified as a routine edit. Every
claimed file was edited; the claim was not extended. **Namespaces spent: NOTHING** — `ADR-0022` stays
free, `0010` stays reserved, the migration prefix is untouched. ⚠️ **The consecutive-increment count
every previous release quotes here is deliberately omitted**: it is a number read out of prose, which
is the class `state.php` exists to end.

### ⛔ THE ROW UNDERSTATED ITSELF, AND THE FOOTNOTE WAS THE LARGER DEFECT

The row is about the marker. It mentions the line threshold once, in passing, to explain why R7 stayed
green on M45 — *"its drop was 133 lines against `DROP_LIMIT`'s 200"* — and moves on. That sentence is a
**second, independent way the gate cannot fire**, and it is the one that reaches the next surgery.

Measured across **every** commit that has ever touched `PROGRESS.md` on `origin/main` — 394
parent/child pairs, blob sizes from `git cat-file`, not from `numstat`:

| | bytes dropped |
|---|---|
| surgeries | 938,007 · **670,409 (the incident)** · 307,867 · 272,006 · **161,528 (M45)** |
| ordinary | **14,340** · 6,486 · 6,130 · 4,114 · … |

**Bimodal, with an order of magnitude between the halves and nothing in it.** `DROP_BYTE_LIMIT` is
50,000: 3.5× above the largest ordinary drop, 3.2× below the smallest surgery. ⚠️ **The threshold was
sized from that distribution and not from intuition**, and the direction to watch is stated in the
constant's comment — the ordinary half growing, because that 14,340 was **one generated hand-off line**.

### ⚠️ AND THE CANONICAL "1,086" IS NOT THIS GATE'S ARITHMETIC

R7 computes a **net** drop. To R7 the incident is **1,085** and M45 is **133**; the extra line in each
case is one the commit added. The constant's own comment had justified the limit with a number the gate
does not compute. Both figures now sit beside it. Small, and the same shape as everything else here.

### ✅ TEN POSITIVE CONTROLS, AND THE PAIRING IS WHAT MAKES THEM WORTH ANYTHING

M43's lesson applied deliberately: a structural gate can be fully green and entirely decorative, so each
half was removed and the run watched.

| control | result |
|---|---|
| new gate replayed at `1f966a4` | **DECLARED SURGERY**, 161,528 bytes |
| …marker predicate reverted to bare line start | **RED** |
| …byte limit raised past the drop | falls **silently** to the ordinary branch |
| synthetic overrun, marker mid-sentence | **FAIL** — the hole M40 closed stays shut |
| …marker indented two spaces | **FAIL** — no `\s*` leaked in |
| …marker at bare line start | **PASS** — no regression |
| …marker behind `* ` and behind `- ` | **PASS** — the squash shape |
| 50,001 bytes in 125 lines, undeclared | **FAIL** |
| 49,994 bytes in 67 lines, undeclared | **PASS** |

The last two pin the threshold **at the boundary**, six bytes apart, so it is a threshold rather than a
number that happens to be exceeded.

### ⛔ `mutate.php` COULD NOT DRIVE THIS, AND NEITHER WOULD THE FIX FILED FOR IT

The open `minor` row proposes a `--command=` mode. **It would not have reached R7 either**: R7's input
is the **commit graph** — blob sizes at `HEAD~1` and a declaration read out of `git log --format=%B`.
No amount of file mutation touches it. What it needs is a *history* fixture — a detached worktree at a
chosen ref, a synthetic commit, the message amended per case. Built by hand, thrown away with the
worktree, and filed as a refinement: that row is right in scope and one size too small in shape.

### ⛔ THE GATE'S OWN ADVICE WAS BREAKING A SECOND GATE

R7's failure message said *"or put it in the PR title"*. `remote_highest()` in `scripts/state.php`
anchors merged titles on the increment-number prefix for the independent cross-check that prevents a
numbering collision — so following R7's advice would have made that pull request invisible to it, and
**the failure mode of an invisible spend is a lower maximum rather than an error.** Deleted, and the
reason written into both files in both directions. The row's diagnosis — *"the two gates want
incompatible first characters on the same string, and nothing in either file mentions the other"* —
was exactly right.

### ➕ THE M46 CITATION GATE FIRED ON THIS DIFF, ON THIS ROW'S OWN CITATION

Adding the docblock to `state.php` pushed line 249 into a comment; the ledger tier went 19 → 20 and
went red. **A gate built one increment ago catching the increment that edits the file it cites is the
strongest evidence available that it works** — and it was caught locally, before the push. The citation
now names `remote_highest()`, which is what `CLAUDE.md` already prescribes.

### ⚠️ AND ONE FIGURE OF MY OWN WENT STALE INSIDE THE COMMIT THAT WROTE IT

The `## Next Session` residual first stated the section size **and** the whole-file size. The Standing
Rule 8 paragraph added in the same commit moved `PROGRESS.md` from 353,875 to 356,365, so the second
figure was wrong before it was pushed. **M40 recorded this exact shape** — its attribution table cited
line numbers and its own splice moved every one. Fixed by dropping the figure rather than correcting
it: the section size is what the next taker plans against, and `tracker-lint` prints the whole-file
size on every run.

### ⛔ WHAT IS NOT PROVEN, STATED RATHER THAN DISCOVERED

**No real GitHub squash was exercised.** M47's own drop is far under both limits, so a green R7 on this
merge is the vacuous-success family — the very thing this increment exists to end. The end-to-end proof
is **owed by the increment that moves `## Next Session`**, which is re-measured at 242,873 bytes and is
now over the byte limit by nearly five times. That row says so, and says to read the post-merge run on
`main` rather than the PR run.

### How the prediction fared

**The one named as most likely wrong — the 50,000 threshold being too low — was not exercised**, which
is not the same as being right: nothing in this increment's diff came near it, and the boundary controls
prove only that the constant does what it says. It stays the thing to watch. **The second prediction was
wrong in a useful direction**: the replay at `1f966a4` did not fail for an unrelated rule, so reading
R7's own line rather than the exit code turned out to be unnecessary caution — the whole run was green
there, which is itself the finding. **Unpredicted:** the citation gate reddening on my own edit, and a
residual figure invalidating itself inside its own commit.

---

## RELEASED — `M46`, the corpus stops asserting what the code refutes, and a citation-liveness gate (merged as PR #236, `6dbe942`, 6/6 green)

**Every claimed file was edited, and the claim's file list was extended once — by measurement rather than
by discovery.** Eight `major` rows closed, six residuals filed, one new lint gate. **Namespaces spent:
NOTHING** — twenty-second consecutive. `ADR-0022` stays free and stays this lane's block-opener; `0010`
stays reserved for H1d; the migration prefix stays free.

### ⛔ THE SHARPEST FINDING IS ABOUT THIS INCREMENT'S OWN METHOD, NOT ABOUT THE ROWS

**Three numbers reached the claim, the plan or a draft from a reconnaissance pass, and every one of them
was wrong when measured directly.**

| Figure as reported | Measured | Where it would have landed |
|---|---|---|
| `Cache::` written at **six call sites in four files** | **three sites in three files** | It was already in the pushed claim's *Remedy verdict* |
| The data dictionary names **3 of 45** migration constraints | **9 of 45** | It was going into a filed backlog row |
| `impersonation_started` appears **in no document but the backlog** | already present in the data dictionary's `NotificationType` row | It was going into a closed row's evidence |

⚠️ **The first is the one that matters, because a claim is a pushed commit and that number was published
before it was checked.** The correction is recorded here rather than quietly fixed, and the finding it
replaced is *better*: the third cache write reaches the cache through an **injected repository**, so the
grep the false docblock prescribed could not have seen it at all — a sharper point than "six not three"
would ever have been. **A wrong number can conceal a better finding, not merely overstate a lesser one.**

### ⛔ FIVE OF THE EIGHT ROWS WERE WRONG ABOUT THEMSELVES, IN FOUR DIFFERENT WAYS

Ninth row in ten whose evidence is sound and whose remedy is not — and the failure modes are no longer
one kind:

- **STALE.** The README row was fixed on 2026-08-18, ten days before the census that re-validated it as
  live. All three of its citations are blank lines. ⚠️ **And a later increment had already noticed** —
  `M27` rewrote the same block for a different row and recorded in its commit message that these line
  numbers were rotten, without closing this row. **A stale row survives being read; it dies only when
  someone opens its citations.**
- **FALSE IN A CLAUSE.** ADR-0001's *"no lowercasing anywhere on the register/login path"* is false;
  Fortify canonicalises on four paths behind a config flag. ⛔ **The mechanism lives in `vendor/`, so a
  grep of first-party code returns nothing and CONFIRMS the false claim.**
- **OVER-READ.** ADR-0017's sentence has three clauses, two false and one true. The obvious remedy —
  delete it — would have fixed the false claims by destroying a real, named gap: the threat model
  models no isolation **topology** at all, which is the one thing that ADR is about.
- **UNDERSTATED.** Two rows named one document each and reached three and four. One of those extra
  sites is a **production runbook step prescribing a command that does not exist**.

### ✅ THE OPERATIONAL FINDING, WHICH IS NOT A DOCUMENTATION DEFECT

`docs/deployment-infrastructure.md` told an operator to install `php artisan reverb:start` as an
auto-restarting Windows service. Measured on this tree: `laravel/reverb` is absent from `composer.json`,
`reverb` appears in no `artisan list`, and the command exits **1**. Following the runbook exactly would
have produced a service failing on every start with the supervisor retrying it forever. ⚠️ **It was found
by chance** — this shell ate the backticks around a command in a diagnostic and executed it, which is the
recorded backtick trap producing, for once, the right answer. The measurement was then taken deliberately.

### The gate, and the scope rule that was allowed to decide

`scripts/citation-liveness-lint.php`, registered at **four** sites — `composer.json` (script and the
`quality` aggregate), its own `ci.yml` step because no CI job runs `composer run quality`,
`preflight.php`'s hardcoded gate array whose comment said *five*, and a `gate-baselines.php` metric whose
pattern was **proven against the real success line rather than assumed**.

⚠️ **The scope rule was fixed before the measurement so the run would decide it, and it then turned out to
be ambiguous — which is recorded rather than quietly resolved.** The report-only run returned **13
rotten occurrences across 10 distinct dead targets**; the rule said "≤12 → fix them all" without saying
which count it meant. The work is per target, so branch B was taken and tier 1 now gates at **zero**.
**All of `docs/**` measures 40 rotten** — the naive scope was never mergeable, which is the whole reason
the rule existed.

⛔ **AND THE COUNT MOVED TWICE, FOR TWO UNRELATED REASONS — THE PREDICTION NAMED THIS AS THE THING MOST
LIKELY TO BE WRONG, AND IT WAS WRONG TWICE OVER.** The planning estimate was 11. It measured 10, because
one of the eleven had already been repaired as part of an unrelated row in the same increment. Then
**widening the extension class from six characters to seven surfaced three more** — `.env.example` was
invisible to the extractor because `example` is seven letters, and with it went every dotfile citation in
the corpus. A cap chosen to prevent one false-positive class was silently causing a false-negative class.

### ⚠️ Five traps measured while building it, each of which produced a wrong answer first

1. **PCRE's newline class matches byte `0x85`, which occurs INSIDE UTF-8 emoji** (`✅` is `E2 9C 85`).
   Lines are split with `explode()`. This corpus is saturated with them.
2. **The extension class must start with a letter**, or WCAG contrast ratios (`4.5:1`) parse as citations.
3. **The pattern delimiter cannot be a tilde**, because the fence predicate must match a tilde fence.
4. **A bare basename must prefer the repository root**, or `README.md` is ambiguous against the
   design-system package's own and the three dead citations resolve to nothing — a clean run by accident.
5. **The extension cap, above.** Traps 2 and 5 are the same knob in opposite directions.

### The controls — and why `mutate.php` could not run them

⛔ **It would have reported a false SURVIVED.** Its verdict comes solely from parsing Pest's `Tests:`
summary line, which a standalone CLI gate never emits, so `$failed` stays 0 whatever the gate did. Its
five disciplines were reimplemented at the call site.

| Control | Verdict |
|---|---|
| **C1** re-introduce the dead Fortify citation this increment repaired | RED — named tier 1, the document, the citation and `BLANK` |
| **C2a** raise the document floor | RED as a discovery regression, **and proven NOT to print the citation-remedy footer** |
| **C2b** break the extraction pattern **silently, not fatally** | RED on the **citation floor** rather than reporting a clean scan |
| **C3** ratchet the ceiling below the measured count | RED with the ledger message, not tier 1's |

⚠️ **C2b was rewritten before it was run.** The first draft renamed the pattern constant, which would have
fataled on an undefined constant — and `php -l` does not catch that. **A control that fatals proves the
gate refused to run, not that it caught anything**, which is M32's recorded lesson arriving in a new place.
C1's mutation and its byte-compared restoration are committed.

### How the prediction fared

| Predicted | Outcome |
|---|---|
| Pint moves by exactly **one** file | ✅ **1423 against a baseline of 1422.** Proven live first with a deliberate probe. |
| PHPStan cannot move | ✅ Structural — it scans `app`, `database`, `routes`; the only `app/` change is a docblock. Stated rather than quoted. |
| Pest unmoved, with the docs-reading contract test the one risk | ✅ 53 passed / 200 assertions locally; the queue-order literal was verified untouched before and after every splice. |
| Vitest, axe, E2E, contract unmoved | ✅ No `.vue`, no `.ts`, no route, no schema. |
| The new baseline row resolves rather than reading `NOT FOUND` | ✅ Pattern proven against the real success line before the run. |
| ⚠️ **Most expected to be wrong: the tier-1 count** | ⛔ **Wrong, twice, for two unrelated reasons.** Named correctly in advance; the *reasons* were not foreseen. |

**Six lint gate lines now print in `preflight --with-gates`**, and the five pre-existing ones are unmoved.

**Six jobs, step counts parsed individually — Static analysis 21 · E2E 20 · Contract 16 · Frontend 12 · axe 11 · Pest 11. Not one `steps: []`.** ⚠️ **Static analysis is 21 where every prior release recorded 20, and that +1 is the point:** it is the new gate's own step, so the step count is itself the evidence that the registration took. A sixth gate registered in `composer.json` alone would have moved nothing, because no CI job runs the `quality` aggregate.

### ➕ Filed rather than silently left — six

1. **`major`** · The data dictionary states a `uuidv7()` **database-side default** on thirty table rows
   and no migration sets one. The preamble conditions it on PostgreSQL 18+; the thirty column rows do not.
   Same class as the `audits` CHECK row, one order of magnitude larger.
2. **`major`** · The README prescribes the axe suite in the **musl** node service, which `CLAUDE.md`'s own
   gate table records as impossible, and with no server for `test-storybook --url` to point at.
3. **`minor`** · Share-slug **lookup** is case-sensitive while storage is lowercase-only, so a mixed-case
   share URL 404s. A runtime defect, found from the opposite end of ADR-0001's `citext` retraction.
4. **`minor`** · The audit spec credits `submission` with two events emitted nowhere. **Not fixed on
   purpose:** narrowing a compliance spec's audited-event list has retention and SIEM consequences, and
   the right answer may be "build them".
5. **`minor`** · The dictionary names 9 of 45 declared constraints while enumerating them exhaustively in
   places — a census, not a sweep.
6. **`minor`** · The gate's own two limits: it cannot see a behaviour negative, and its ledger ceiling
   counts deliberately-preserved dead citations inside closed rows, so it can never ratchet to zero.

### ORIGINAL CLAIM (`M46`)

Taken 2026-08-29. Branch `m46-citation-truth-and-liveness-gate`, cut from `origin/main` at `6c3e8e5`, PR into
`main`. Row: the eight open `major` rows under `### Documentation & specs` in `docs/feature-backlog.md` —
ADR-0001's `citext`/`pgcrypto` claim · ADR-0002 §D3's two unbuilt isolation controls · the audit spec's
omission of the impersonation boundary events · the `APP_PREVIOUS_KEYS` register entry · ACCESS-MATRIX's
verification step 4 · the README's host command blocks · ADR-0017's "no SSO rows" claim · the data
dictionary's "No CHECK pairs the two". **Each is identified by its title, because this increment edits that
file and a line number would not survive its own diff.**

⛔ **`docs/backlog-triage.md` IS NOT A QUEUE, AND WAS NOT USED AS ONE.** Its top-ranked row shipped as `M43`
and the one below it as `M44`; it is 39 commits behind the trunk. The queue was re-derived from the tree,
which gives **twelve** open `major` rows — one is `D6` and belongs to no lane, two are the tracker chain, and
**eight** are this section. The triage's own entry for this section says *five*. **A dated census
understates by construction, and it understated by three.**

### Evidence verified

Every citation opened against the merged tree. **Three of the eight are sound as filed; five are wrong about
themselves**, which is the finding before any edit is made.

- **ADR-0001** — all three citations HOLD. Only `postgis` is enabled
  (`2026_07_12_000001_enable_postgis_extension.php`); `citext`, `pgcrypto` and `pg_trgm` appear nowhere
  outside prose. The row's own aside about the adjacent bullet is **off by one**.
- **ADR-0002 §D3** — the Realtime citation HOLDS; the Cache citation is **FALSE**, it points at the I7b
  carve-out amendment and the Cache row has moved. Ten-row count confirmed.
- **Audit spec** — the cited row has **MOVED by one**, and the row's summary of it is imprecise:
  `permission_changed` against `users` is a *separate preceding row*, so that section's `users` coverage was
  already two rows rather than the one the filing describes.
- **`APP_PREVIOUS_KEYS`** — **four of six citations are FALSE or moved**, including the `.env.example` range
  that is the row's own refutation.
- **ACCESS-MATRIX** — the step-4 citation is **FALSE**: it lands on a shell comment *inside a fenced block*.
  The warning-block citation HOLDS.
- **README** — **every citation is FALSE; all three are blank lines.**
- **ADR-0017** — the ADR citation HOLDS. Both refutations are **mis-anchored**: one range opens on Google
  sign-in rows and covers only the first SAML row of fourteen; the other names four rows where there are five.
- **Data dictionary** — **both citations HOLD exactly.** The only row of the eight whose line numbers are
  intact, and the only one where nothing had to be re-anchored.

### Remedy verdict

**Two of the eight prescribed remedies are correct. One is a no-op that would make the file worse, one would
destroy a true residual, and four are materially incomplete.**

- **ADR-0001 — WRONG IN ITS SECOND CLAUSE.** The row asserts *"no lowercasing anywhere on the register/login
  path"*. `config/fortify.php` enables username lowercasing, and Fortify canonicalises on four paths; the
  SSO, Google and invite paths lowercase too. ⛔ **A gate scoped to first-party code would have CONFIRMED
  the false clause** — the mechanism lives in `vendor/`. The real residual is narrower (the guarantee is
  application-layer, so a seeder or raw insert can still collide) **plus one the row never names: share-slug
  LOOKUP is case-sensitive, so a mixed-case share URL 404s.**
- **ADR-0002 §D3 — UNDERSTATES BY TWO, AND ONE IS OPERATIONAL.** `docs/deployment-infrastructure.md`
  prescribes an artisan Reverb command as a Windows service in a **production runbook step**, for a command
  that does not exist in this tree. And `ConnectorChannelDirectory.php` carries a docblock asserting that
  grepping `app/` for cache writes *finds nothing* — there are six call sites in four files. **A comment
  that greps first-party code and reports absence is the same defect one layer down.**
- **Audit spec — UNDERSTATES BY THREE.** The same undercount recurs in `docs/data-dictionary.md`, which calls
  the event catalog eight-valued where the enum has ten, twice over, and describes the domain-specific events
  by a count that is two short. The spec also **over-claims** in the other direction, crediting a
  `submission` scope with two events emitted nowhere.
- **`APP_PREVIOUS_KEYS` — CORRECT, AND ITS HEDGE IS THE VALUABLE HALF.** *Narrow it, do not close it* is
  right: the seam is documented, the **rotation procedure genuinely is not**, and the document that owns the
  procedure cites the gap as still open rather than closing it.
- **ACCESS-MATRIX — THE DOCUMENT NAMES THE WRONG MIDDLEWARE.** The observable holds and the mechanism does
  not: on the platform host the subdomain initialiser throws first and the exception renderer issues the
  redirect. The middleware the document blames never executes, and it would answer 404 rather than 302 if it
  did. **The row does not notice, so the obvious fix would leave a false explanation in place.**
- **README — THE REMEDY IS A NO-OP THAT WOULD RE-AFFIRM THE ONE WRONG LINE.** The block was corrected on
  2026-08-18; every frontend and design-system command already runs in the container under an explicit
  host-incapability warning. ⚠️ **Measured, and the row's blanket claim is over-broad:** three of the five
  named commands *can* run on this host. **Close as already fixed and re-file the residual** — the block
  prescribes the axe suite in the musl node service, which `CLAUDE.md`'s own gate table records as
  impossible, and with no server for it to point at.
- **ADR-0017 — THE OBVIOUS REMEDY OVER-CORRECTS.** The SSO half is refuted and so is the isolation half, but
  the **topology** half is still true: the threat model carries no tiering rows at all. Deleting the sentence
  destroys a genuine, named gap. **Split the verdict instead.**
- **Data dictionary — CORRECT AND CORRECTLY SCOPED.** The document transcribed the migration's reasoning for
  the constraint that was **rejected** rather than the one that shipped. It misses one adjacent omission.

Files: `docs/feature-backlog.md`, `docs/adr/0001-postgresql-over-mysql.md`,
`docs/adr/0002-multi-tenancy-shared-db-rls.md`, `docs/adr/0009-oauth-connector-token-custody.md`,
`docs/adr/0017-tenant-isolation-tiering.md`, `docs/audit-compliance-logging-spec.md`,
`docs/data-dictionary.md`, `docs/security-threat-model.md`, `docs/ACCESS-MATRIX.md`,
`docs/deployment-infrastructure.md`, `app/Services/Connectors/ConnectorChannelDirectory.php` (docblock only),
`scripts/citation-liveness-lint.php` (new), `scripts/gate-baselines.php`, `scripts/preflight.php`,
`composer.json`, `.github/workflows/ci.yml`, `docs/gate-baselines.md`, this file, `PROGRESS.md` (own block
only). Plus whichever documents the gate's own report-only run names — **that list is a measurement, not a
prediction, and it is recorded in the release rather than guessed here.**
Shared artefacts taken: the `docs/**` files above, `PROGRESS.md` (own block only).
Paired files taken: none.
Namespaces spent: **NOTHING FROM EITHER NAMESPACE.** `ADR-0022` stays free and stays this lane's
block-opener — every edit here corrects the record of a decision already taken, and this project has never
minted an ADR for a lint gate. `0010` stays reserved for H1d. The migration prefix stays free; no migration
is written. If the share-slug finding turns out to need a call it goes to `docs/claims/decisions.md` as the
next free `D`, and the next row is taken in the same turn.

Prediction, written before the first file was opened:

- **Pint moves by exactly one file** — CI runs a bare whole-project `pint --test` and `scripts/` is in scope,
  so the new gate script is a new scanned file. **Two baseline rows move, not one.**
- **PHPStan cannot move.** It scans `app`, `database` and `routes`, and the only `app/` change is a docblock.
  That is a structural claim, not a quoted number.
- **Pest unmoved** — with one real exposure: a contract test reads `docs/deployment-infrastructure.md` and
  asserts a queue-order literal. That literal is in a different bullet from the one being edited, verified
  before this claim was written, but it is the single Pest-reddening path in the diff.
- **Vitest, Storybook axe, E2E and the contract suite unmoved** — no `.vue`, no `.ts`, no route, no schema.
- **The new gate's baseline row resolves rather than reading `NOT FOUND`** on the first regeneration.
- ⚠️ **The one I most expect to be wrong: the tier-1 rotten-citation count, and therefore the gate's scope.**
  The planning measurement puts the specification corpus at eleven and all of `docs/**` at forty — the latter
  **red on arrival, which can never merge**. But ten of the eleven corrections point into files whose true
  anchors have not been re-measured, and this increment's own edits shift line numbers in three documents
  that other documents cite. **The scope rule is therefore fixed in advance so the run decides it and not
  the author:** zero means gate at zero; twelve or fewer means fix them all; more than twelve means ship a
  frozen, enumerated allow-list that can only shrink.
- ⛔ **And the gate's honest reach is three of these eight rows, not six.** It checks that a cited line is
  *alive*, never that it says what the citing sentence claims. Five of the eight cite **live lines that say
  the wrong thing**, and no gate catches those. That limit ships in the script header and is filed as a row.

---

## RELEASED — `M45`, the claim ledger leaves the constitution (merged as PR #235, `1f966a4`, 6/6 green)

**Every claimed file was edited and nothing was added that the claim did not name.** `PROGRESS.md` ·
`PROGRESS_ARCHIVE.md` · `scripts/tracker-lint.php` (one constant and its comment) ·
`docs/feature-backlog.md` · `docs/gate-baselines.md` · this file. **No production file, no test, no
`.vue`.** **Namespaces spent: NOTHING** — twenty-first consecutive.

**`PROGRESS.md` 508,441 → 346,913 bytes, 583 → 450 lines. `## Standing Rules` 208,039 → 46,511.**
134 lines and 162,601 bytes moved verbatim into `PROGRESS_ARCHIVE.md` (2,090,997 → 2,254,778).

### ⛔ The row's PREMISE was false, which is a different failure from a wrong mechanism

The row reads *"a 163,680-byte claim ledger that **duplicates** `docs/claims/lane-*.md`"* and calls the
block *"the second copy of a record that already has a home."* **Measured before a byte moved:
`docs/claims/` holds `## RELEASED` headings for `M15`–`M44` and nothing earlier; the block holds
`M1`–`M14` plus the J/K/P/I-series. The split is exact and the overlap is ZERO** — and 0 of its 129
non-blank lines appear verbatim in `PROGRESS_ARCHIVE.md` either. **It is the only copy.**

⛔ **The action the row invites is therefore destructive.** *"The second copy"* invites a deletion, and
a deletion loses the record of every claim before `M15` — including the one the whole `docs/claims/`
regime was created in response to. **Eighth row in nine whose evidence is sound and whose remedy is
not, and the first where the remedy fails because the PREMISE is false rather than the mechanism.**
Every previous member of that family prescribed a fix that did not work; this one prescribes a *frame*
that makes the wrong fix look obvious, which is harder to catch because there is no mechanism to test.

⛔ **AND IT COULD NOT GO WHERE THE ROW POINTS, FOR A REASON THE ROW IS ITSELF FILED UNDER.** The block
interleaves 🅰️ and 🅱️ bullets — both lanes' claims in one list — and Lane A may never write
`lane-b.md`. **One writer per claim file closes the only destination the row names.** That left
`PROGRESS_ARCHIVE.md`, which is `M41`'s precedent anyway, and it was settled before the splice rather
than discovered inside it.

### ⚠️ Two more corrections to the row, both cheap and both load-bearing

**(1) The boundary was off by three lines and 1,650 bytes.** The row cites `:123-259` / 163,680; the
ledger is `:126-259` / **162,601**, because `:123`–`:125` are live imperatives — the
ADR-number-is-not-written-here fix, the `0010`-is-reserved trap, and cite-by-filename. Splicing the
row's range would have deleted three rules. **A row's line numbers are the first thing to re-measure.**

**(2) *"Carrying zero imperatives"* is false.** Four of the moved bullets carry **`DO NOT RE-ASK` user
decisions of record**: `M9`'s three SSO-adoption decisions (2026-08-24), and leaderboard visibility,
the ⌘K palette's stacking behaviour and checklist dismissibility (2026-08-17). They moved with the
block — hoisting them into `decisions.md` would have created the second copy this row exists to end
*and* broken the conservation proof — and both the archive heading and the pointer name them
explicitly, so a reader looking for a settled decision is sent to them rather than left to guess.

### ⛔⛔ R7 CANNOT FIRE ON THIS SURGERY, AND IT WAS PROVEN RATHER THAN ASSUMED

`DROP_LIMIT` is **200 lines** with a strict `>`. This drop is **133**. So the rule that exists to catch
an undeclared tracker deletion is **silent on a tracker deletion of 162,601 bytes**, because it counts
lines and this block is 134 very long ones.

**Proven, not inferred:** the phase-2 commit was amended to remove `[tracker-surgery]` entirely,
`tracker-lint` was re-run, and it **passed** — printing `-133` while asserting nothing about it. The
message was then restored and verified byte-identical (1,016 bytes, `87315091…`). ⚠️ **`CLAUDE.md`'s
own two-hundred-line threshold does not reach this diff either**, so the marker was carried because the
row demands it and for no other reason. **A green R7 here is the vacuous-success family again: a rule
that passed because it never looked.**

### ⛔⛔ AND THE MARKER DOES NOT SURVIVE A DEFAULT SQUASH — A NEW MEMBER OF `M41`'s FAMILY

`M41` recorded two rules: the marker must **start a line**, and an empty `--body` **discards** it.
Neither covers what happened here. The merge passed **no** `--body`, so the default was used, and the
marker *is* in the trunk message — **twice** — but GitHub's default squash body renders each commit
subject as a bullet:

```
* [tracker-surgery] M45 phase 1: the claim ledger lands in the archive
```

**`R7` matches `/^\[tracker-surgery\]/m`, and `* ` in front of it means no match.** Verified on the
merged commit: `grep '^\[tracker-surgery\]'` finds nothing, `grep 'tracker-surgery'` finds two.

⛔ **HAD THIS BEEN A DROP OVER 200 LINES, `main` WOULD HAVE MERGED RED** — `M41`'s exact outcome,
reached by the opposite route: it lost the marker by *emptying* the body, this lost it by *accepting
the default*. **Preserving the text is not preserving the form.**

⚠️ **AND THE OBVIOUS WORKAROUND IS CLOSED BY A SECOND MECHANISM.** `tracker-lint`'s own failure message
suggests putting the marker in the PR title. **`state.php:249` anchors merged pull-request titles on
`^M(\d{1,3}):`** for its independent increment cross-check, so a `[tracker-surgery]` prefix would break
the one guard that catches a numbering collision. **The two gates want incompatible first characters**,
and the only form satisfying both is an explicit `--body` whose first content line is the marker. Filed.

### ✅ Proved by 21 assertions, every one exact and none with a tolerance

| Proof | Result |
|---|---|
| Counted multiset of 134 moved line hashes, exact multiplicity | **130 distinct / 134 counted**, both sides |
| Byte conservation | **2,601,691 == 2,601,691** |
| Lines 1–125 byte-identical (7(g)'s imperatives survive) | `82773829…` |
| Rule 8 downward byte-identical | `b593cfeb…` |
| Independent git-level proof — pre-surgery slice vs new archive tail | both `868511fd…` |
| Heading uniqueness, tracker and cross-file | 7 checks, all held |
| Encoding, both files | LF only, trailing NL, no BOM |

⚠️ **THE MULTISET WAS NOT CEREMONIAL HERE.** 134 lines yield **130 distinct hashes** — five blank lines
share one — so a set equality would have passed while silently dropping four lines. `M41` argued for a
counted multiset from the 2026-08-16 incident; this is the first increment where the count itself was
load-bearing on its own data.

⚠️ **AND THE CLAIM NAMED BYTE CONSERVATION AS THE MOST LIKELY MISS FOR THE SECOND TIME IN A ROW, AND
THIS TIME IT HELD.** The reasoning was that this surgery has **two** seams where `M41` had one — it
cuts a hole in the middle of the tracker as well as appending to the archive — so the pointer's own
1,073 bytes enter the identity beside the heading's 1,180. Stating both as integers computed from the
literal inserted strings, rather than inferring them, is what made it exact on the first run.

### ⚠️ The paths-touched assertion failed twice, and the CHECK was wrong both times

`M41` recorded this once — *"a failing independent check is not automatically a defect; verify the
check before believing it"* — and it recurred **twice inside one increment, in the same assertion, for
two unrelated reasons**: first it compared committed state while phase 2 still sat in the working tree,
then its expected list omitted `scripts/tracker-lint.php`, which the plan had named in scope. Both
times the surgery was correct and the instrument was not. **Twenty of twenty-one assertions passing
with one failure is exactly the shape that tempts you to change the subject rather than the check.**

### ✅ Controls — four, because `mutate.php` cannot drive a standalone script

Its `--tests` argument is Pest paths execed through `docker exec` and there is no `--command=` mode, so
its discipline was reimplemented at the call site: green baseline first, tokens read from files,
sha256 asserted **moved**, `php -l` on any PHP mutant, restore by **byte comparison**.

| Control | Verdict |
|---|---|
| a second `## Next Session` in the archive | **R3 red**, named, exit 1 |
| a `## Current Status` in the archive | **R4 red**, named |
| the ceiling set below the new size | **R1 red**, named |
| one CR byte in the tracker | **R5 red**, named |

Each reddened its own rule **and only it**; each restore was byte-exact; the gate was green again
afterwards. ⚠️ **These are the rules this diff could actually have violated** — proving R7 instead
would have proven nothing, since it cannot fire at 134 lines, and offering it would have been the
decorative-gate mistake `M43` measured.

### How the prediction fared

**Everything structural held, and every gate number is byte-identical to the committed baseline** —
Pest **4627 / 19,579**, Vitest **134 files**, Storybook axe **42 / 303**, E2E **551 passed + 10
skipped, no flaky line**, PHPStan `[OK]`, Pint **PASS over 1422 files**. Zero delta on every one, which
is what a documentation diff plus one `scripts/` constant must produce. Six jobs, step counts parsed
individually — Contract **16** · Frontend **12** · Static analysis **20** · axe **11** · E2E **20** ·
Pest **11**. **Not one `steps: []`.** Five host lint gates unmoved at 97 · 113 · 31 · 113/121/0 · 180;
Pint proven live with a deliberate probe before its `PASS` was believed.

**Byte predictions were exact where they were derived and wrong where they were counted in my head:**
346,913 against a predicted ~346,600 (the formula was right; the round number was a guess), Standing
Rules **46,511** against ~45,500, archive **2,254,778** against ~2,253,700.

⚠️ **THE ONE CLEAN MISS: `preflight`'s line count. Predicted 449, actual 450.** I subtracted the 134
moved lines and forgot to add back the one line the pointer occupies. **It is trivially small and it is
the same arithmetic class as `M41`'s one-byte seam** — a move that also inserts is not a pure removal,
and the inserted thing has to be counted on both axes, not just in bytes.

**And `state.php`'s advisory literal count for `PROGRESS.md` fell 147 → 69**, predicted in the claim as
"sharply lower" precisely so the drop would not be read as a defect. 16 of the 21 `next free` lines
lived inside the moved block; they are dated records and are correct as history where they now sit.

### ➕ Filed rather than silently left

Three rows, each at the moment it was decided:

1. **`major` — `## Next Session` is a second historical ledger and is now 62% of the tracker**
   (214,073 of 346,913 bytes). ⛔ **`M41` named this section by size and nothing ever filed it** — it
   lived only in that release's prose and in this increment's plan, so no backlog search would have
   reached it. That is the `J4b1` shape exactly. ⚠️ **It is not a repeat of this increment and must not
   be planned as one**: `M45` moved a block that was provably 100% dated record with a clean boundary,
   and that one has live and dead material interleaved, so its boundary must be *decided* rather than
   measured.
2. **`major` → amended, not filed: the R8 row moves from *blocked* to *unblocked and still open*.** The
   precondition is met and the gate is not built. Two things named for whoever takes it: the exemption
   surface is no longer zero (this increment's own pointer cites `M1`, `M14`, `M15`; 7(g) still cites
   `0010`), so a naive rule is red on arrival for a *new* reason; and it must not be aimed at
   `## Next Session` until that section moves.
3. **`major` — the marker/squash finding above.** ⚠️ **Filed one severity higher than it was first
   drafted, and the reason is the argument for it:** the failure is silent, it lands on `main`, and it
   defeats the only gate this repository has against the incident that cost it 1,086 lines. A row that
   makes `R7` unarmable is not a tooling nicety.
4. **`minor` — the four `DO NOT RE-ASK` decisions now living in the archive rather than in
   `decisions.md`**, whose `ANSWERED` form is D-numbered questions and does not fit an increment
   decision with no question attached.

### ORIGINAL CLAIM (`M45`)

Kept as written, including the prediction that was wrong.

**Taken 2026-08-29.** Branch `m45-ledger-surgery`, cut from `origin/main` at `e89c4c3`, PR into `main`.
**Second tracker surgery**; `M41` is the precedent it is built on rather than a thing it repeats.

**Row:** `docs/feature-backlog.md`'s last `major` — *"Standing Rule 7(g) contains a 163,680-byte claim
ledger that duplicates `docs/claims/lane-*.md`, and it is why the constitution cannot be read in one
call."* Filed by `M42`, which deliberately did not take it; the decision to take it as its own
increment was the user's, 2026-08-29. Closing it advances `D5`, whose bar is zero open `major` rows.

Files: `PROGRESS.md` · `PROGRESS_ARCHIVE.md` · `scripts/tracker-lint.php` (constants only) ·
`docs/feature-backlog.md` · `docs/gate-baselines.md` · this file.
Shared artefacts taken: `PROGRESS.md` (own status block and Rule 7(g) only), `PROGRESS_ARCHIVE.md`,
`docs/feature-backlog.md`, `docs/gate-baselines.md`.
Paired files taken: **none**.
Namespaces spent: **nothing from either namespace** — no ADR, no migration prefix, no `§D`.

⚠️ **THE ONE I MOST EXPECTED TO BE WRONG: byte conservation, for a different seam than `M41`'s** —
this surgery cuts a hole *and* appends, so there are two seams and the pointer's own byte count enters
the identity. **It held on the first run.** The miss was one line, not one byte, and it was in the
line-count prediction rather than in the conservation check.

---

## RELEASED — `M44`, four maintenance fan-outs asserted by a fixture too small to see a wrong tenant id (merged as PR #234, `7837078`, 6/6 green)

**Every claimed file was edited and nothing was added that the claim did not name.**
`tests/Feature/Webhooks/WebhookRetrySweepTest.php` · `tests/Feature/Forms/ScheduledFormSweepTest.php` ·
`tests/Feature/Entitlements/UsageRollupTest.php` · `tests/Feature/Submissions/DraftReaperTest.php` ·
`docs/feature-backlog.md` · `PROGRESS.md` · `docs/gate-baselines.md` · this file. **No production file was
edited** — the production loop is correct at all four sites, and the whole row was about coverage.
**Namespaces spent: NOTHING** — no ADR, no migration prefix, no `§D`. **Twentieth consecutive.**

### ✅ The controls, and the one whose asymmetry is the finding

Six `mutate.php` runs, each with a green baseline first, the sha256 asserted **moved**, the mutant `php -l`'d,
and the restore verified by byte comparison. **The BEFORE runs were taken on the claim commit, before a
single test line existed** — the half `M32` once got wrong by measuring against a `HEAD` that had already
moved onto its own work.

| Mutation | Before | After |
|---|---|---|
| hoist the loop variable — every child gets tenant #1 (four files) | **SURVIVED ×4** | **CAUGHT ×4**, exactly the two new cases red, all 14 existing green |
| a `sweep()` that dispatches nothing (webhook) | CAUGHT — but **only 1 of 3 red** | CAUGHT — **5 of 5 red** |
| the drain loop reverted to the pre-M44 single-child drain | — | **CAUGHT — only the two-tenant case red** |

⚠️ **THE BEFORE RESULT IS A PROOF, NOT A MEASUREMENT, AND SAYING SO IS THE POINT.** With exactly one active
tenant `$first` *is* `$tenant`, so the mutant is **semantically identical** to the original and cannot fail.
Reporting four SURVIVEDs as though they were a discovery would overclaim. What they genuinely prove is
narrower and still worth having: the token matched, the sha256 moved, the mutant parsed, and the harness
ran — the four ways `M31` and `M9` record that a control silently proves nothing.

⛔ **AND THE MUTATION HAD TO BE SILENT RATHER THAN FATAL.** `->first()` on `activeTenants()` would have
fataled — it returns a `Generator` — which is `M32`'s recorded invalidated control, *a control that dies
loudly proves nothing about a silent defect*. `iterator_to_array()` is unsafe here too: `yield from` over a
chunked `LazyCollection` can repeat keys and collapse entries. The form used, `$first ??= $tenant;`, keeps
the dispatch **count** correct and changes only identity — which is exactly why the old fixtures were blind.

### ⛔⛔ A SEPARATE DEFECT, FOUND ONLY BECAUSE THE RED SET WAS READ RATHER THAN THE VERDICT

Replacing `SweepWebhookRetriesJob::sweep()`'s body with a comment — a sweep dispatching **nothing at all** —
left **two of that file's three cases GREEN**, because both assert `webhookQueueDepth() === 0` and a dead
sweep produces exactly that. They were passing for a reason unrelated to their names.

⚠️ **`mutate.php` reported `CAUGHT` for that run, and it was right to.** One case *did* redden, so the
aggregate verdict is indistinguishable from a healthy one. **The vacuity is visible only in the printed RED
list.** This is a new member of this repository's vacuous-success family and it fails in a new direction:
not a gate that never ran, but a gate whose verdict is true and whose *coverage* is a third of what the file
appears to assert. **Read the red set, never just the verdict.** Filed as its own row; the asserted fan-out
count fixes it, taking the same mutation to 5 of 5.

### ⛔ Two things checked rather than assumed, and both changed the build

**(1) The triage's remedy claim was false.** `docs/backlog-triage.md` said `M32` fixed the sibling *"in
exactly the prescribed shape (a drain loop plus a second tenant)"*. `M32` added a **`Bus::fake`
set-equality case only**; the drain loop in that file is **`M6`'s**, predates it, and dispatches the
**child** directly, so it never reaches the parent's loop at all. Two unrelated mechanisms in one file were
read as one. Had it been taken on report, the increment would have built the drain loop and skipped the one
shape actually proven against this mutation.

**(2) The row's own prescribed remedy is blind on one of its four files.** `WebhookRetrySweeper` writes
**no rows**, so under the hoist acme is swept twice and re-dispatches its own due delivery both times: the
queue depth reads **2**, numerically identical to two tenants swept once. A drain loop plus a count cannot
see it. That file asserts **per-delivery payload containment** instead — each due delivery enqueued exactly
once. This is the row's own *"the obvious fix produces a new green test that still cannot see it"* trap, one
level down, in a form the row does not name.

**Seventh row in eight whose evidence is sound and whose remedy is not.** ⚠️ And the evidence half held
completely this time — including the row's **scope**, which is exactly four. Seven `MaintenanceJob`
subclasses exist, five call `activeTenants()`, `VerifyCustomDomainsJob` and `PruneFailedJobsJob` work
inline, and `RefreshConnectorTokensJob` was `M32`'s. **A row that neither understates nor overstates itself
is rare enough here to be worth recording.**

### The shape of the fix

Two cases per file, eight in total. **No existing case and no `beforeEach` was modified** — every second
tenant lives inside its own new case. That is not fastidiousness: widening a shared fixture would leave each
existing case draining only the first of two children and **still passing**, because `lazyById()` enumerates
by UUIDv7, i.e. creation order, so the child that gets worked is always the first tenant's. Green, and its
new coverage silently deleted. That is `M31`'s hazard, and the row warned about it in as many words.

Case A names the child class and asserts the dispatched multiset; case B drives the real `database` queue and
asserts the per-tenant effect. They cannot be one case — `Bus::fake` prevents the child from ever reaching
the queue. Case A carries an identical name across all five fan-outs on purpose, so
`grep "fans out one child per active tenant" tests/` now returns **5**, which answers the row's own note
that four of the five child classes appeared under `tests/` only inside comments, and one not at all.

The drain helpers became bounded loops with the child count **asserted rather than inferred**
(`$activeTenants = 1` by default, so all fourteen existing call sites are unchanged in text and behaviour).
`tests/Pest.php`'s `workOneJob()` was deliberately **not** touched: dropping `--once` for
`--stop-when-empty` routes to `Worker::daemon()`, which additionally calls `resetScope` every iteration and
installs pcntl handlers — read from the installed vendor source, and a change ~25 files would inherit.

### ⚠️ Three vendor and model facts read from the installed source rather than from memory

- **`EventFake::dispatched()` returns argument ARRAYS, not the events** — `fakeEvent()` stores
  `func_get_args()` — unlike `BusFake::dispatched()`, which returns the commands. So the forms case maps
  `fn (array $args) => $args[0]->tenantId`. Its *filter* callback **is** spread (`$callback(...$arguments)`),
  which is precisely what makes the asymmetry easy to get backwards. This is the `toContain`/`toHaveKey`
  family again: a second argument that is not what it looks like.
- **`Worker::stop()` returns in 13.18.1 and does not `exit()`** — only `kill()` does. So a `--stop-when-empty`
  drain would not have killed the Pest process; it was rejected for `resetScope`, not for that.
- **The fairness limiter is per tenant, per queue, per minute** (60 for `scheduled-maintenance`), so a second
  tenant's child gets a fresh bucket and `RateLimited` cannot defeat a two-tenant drain.

### How the prediction fared

- ✅ **All four BEFORE controls SURVIVED and all four AFTER controls CAUGHT**, with exactly the predicted red
  set in every file: the two new cases red, every existing case green.
- ✅ **PHPStan could not move and was not quoted** — a test-only diff, and it scans `app`, `database`, `routes`.
  Vitest, Storybook axe and E2E were untouched. Pint passed at 1422 files and was **proved scanned** by
  preflight's deliberate probe rather than trusted (`M9`).
- ⚠️ **The named most-likely-wrong prediction was wrong, and in the safe direction.** `DraftReaperTest`'s
  two-tenant case was called the riskiest, on the theory that `$this->drafts` — resolved from the container
  under acme's context — might memoize a tenant. It did not: `SubmissionDraftService` holds only injected
  readonly collaborators, and the case passed first time. It was also the **first** file built and the
  pattern was proven on it before being replicated.
- ⛔ **AND THE PLAN UNDER-SCOPED ITSELF, WHICH THE CLAIM SHOULD SAY PLAINLY.** It proposed the row's
  end-to-end shape for three files and Layer 1 only for the webhook one, on the ground that a count there is
  blind. Blind it is — but *payload containment* is not, and the repo already had the idiom. Four files got
  both layers, not three. The plan's own reasoning was right and its conclusion was one step short.
- ⚠️ A predicted "roughly seven cases" was **eight**. The four files went 14 passed / 29 assertions →
  **22 passed / 93 assertions**; six containing directories read **1253 passed / 5029 assertions**, zero
  failures. Absolute gate figures are **not** restated here — they live in `docs/gate-baselines.md`,
  regenerated from this increment's own post-merge run.

### ➕ Filed rather than silently left

- The two vacuous `WebhookRetrySweepTest` cases (fixed here, filed as their own `minor` because the defect is
  distinct from the tenant-width one and would be invisible to any later search).
- The generic `MaintenanceJob` fan-out gate that was **not** built: a naive loop over the subclasses would
  invoke `VerifyCustomDomainsJob::handle()`, which does real DNS work inline, and `PruneFailedJobsJob`, which
  works inline on `failed_jobs`. Avoiding them needs a declared fan-out/inline registry that drifts unless it
  is itself set-equality-checked. Left because this row was already four files wide.
- **Not filed, because it is harness config rather than product:** `.claude/settings.local.json` carries two
  Increment-A-era allowlist entries running Pest **host-side with no `docker exec`**, a form this host cannot
  execute (`pdo_pgsql` is absent). Recorded here so the next reader does not copy them.

### ORIGINAL CLAIM (`M44`)

Taken 2026-08-29. Branch `m44-fanout-fixture-width`, cut from `origin/main` at `bcd0701`, PR into `main`.
Lane B holds nothing forward — `lane-b.md` read in full, not its `## Status` line, and `git worktree list`
run. `M43` is merged (PR #233, `c09c7ef`, 6/6 green) and released below.

**Row**, from `docs/feature-backlog.md` — identified by its title rather than by a line number, because
this increment edits that file: ***"`major` · Four maintenance fan-outs are asserted by a fixture too
small to see a wrong tenant id."*** Ranked **#2** in `docs/backlog-triage.md`; **#1 was `M43`**.

### Evidence verified

Every citation opened against the merged tree. **All five held.**

- `SweepWebhookRetriesJob.php:26` · `SweepScheduledFormsJob.php:30` · `RollUpUsageCountersJob.php:27` ·
  `ReapExpiredDraftsJob.php:25` — **held**, all four the bare
  `<Child>::dispatch((string) $tenant->getKey())` inside `foreach ($this->activeTenants() as $tenant)`.
- *"every fixture holds exactly one active tenant"* — **held** in all four files.
- The helper comments *"acme is the only active tenant"* — **held** in `ScheduledFormSweepTest`,
  `UsageRollupTest` and `DraftReaperTest`. **Partially false in the fourth**: `WebhookRetrySweepTest`
  encodes the same assumption as `// parent → fans out the per-tenant child` (singular), not in those
  words. The row cites only the three, so this is the row being precise rather than wrong.
- *"drain is hard-coded to exactly two `workOneJob('scheduled-maintenance')` calls"* — **held**, all four.
- `SweepTenantWebhookRetriesJob` appears nowhere under `tests/`; the other three children appear only
  inside comments — **held**, by grep of the whole tree.

⚠️ **AND THE ROW DOES NOT UNDERSTATE ITSELF, WHICH IS WORTH RECORDING BECAUSE IT IS THE EXCEPTION.**
Checked rather than assumed: **seven** `MaintenanceJob` subclasses exist and `activeTenants()` is called
by **five**. `VerifyCustomDomainsJob` and `PruneFailedJobsJob` never call it — they work **inline**, which
the base docblock explicitly permits for a sweep whose subject lives entirely in RLS-exempt tables.
`RefreshConnectorTokensJob` was fixed in `M32`. **Four remain, and four is what the row says** — against
M37's finding that 14 open rows understate their scope.

### Remedy verdict

⛔ **THE TRIAGE'S CLAIM IS FALSE. `docs/backlog-triage.md` says the remedy is *"SOUND and already proven:
`RefreshConnectorTokensJob` is fixed in exactly the prescribed shape (a drain loop plus a second
tenant)"* — and that is not what `M32` did.** Read first-hand rather than taken on report:

- `M32` added **one `Bus::fake` case** (`ConnectorTokenRefreshTest.php:188-225`): a second tenant,
  `(new RefreshConnectorTokensJob)->handle()` called **directly**, set-equality on the sorted
  `$job->tenantId` list. **No drain loop. No per-tenant effects.**
- The drain loop in that same file (`runRefreshSweep()`, `:74-89`) is **`M6`'s**, predates `M32`,
  dispatches the **child** directly and therefore never reaches the parent's loop at all.

Two unrelated mechanisms in one file were read as one. What is **actually** proven against this exact
mutation is the `Bus::fake` shape — `M32`'s own results table records *"the same hoist in
`RefreshConnectorTokensJob::sweep()` — SURVIVED, 9 passed / 38, exit 0 → CAUGHT"*.

⛔ **AND THERE IS A SECOND BLINDNESS THE ROW ITSELF DOES NOT NAME.** The row warns that a second tenant
plus a two-call drain leaves the second child unworked. Found by reading `WebhookRetrySweeper::sweep()`:
**it does not mutate the rows it dispatches for.** So under the hoist, tenant A is swept twice and
enqueues two `DeliverWebhookJob`s — and `webhookQueueDepth()` reads **2**, identical to correct
two-tenant behaviour. **A count-only assertion is blind on that file even with a working drain loop.**
Only asserting identity catches it.

**Verdict: sound in intent, incomplete in mechanism, and larger than necessary for three of the four
files.** Build the proven identity assertion in all four; add the row's end-to-end shape where it pays.

Files: `tests/Feature/Webhooks/WebhookRetrySweepTest.php` · `tests/Feature/Forms/ScheduledFormSweepTest.php` ·
`tests/Feature/Entitlements/UsageRollupTest.php` · `tests/Feature/Submissions/DraftReaperTest.php` ·
`docs/feature-backlog.md` · `PROGRESS.md` · `docs/gate-baselines.md` · this file.
**No production file is edited** — the production code is correct at all four sites and this row is
about coverage.
Shared artefacts taken: `docs/**` and `PROGRESS.md` (own block only).
Paired files taken: **none** — no Standing Rule 7(b-bis) entry is touched.
Namespaces spent: **nothing from either namespace** — no ADR, no migration prefix, no `§D`.
Prediction: Pest rises by roughly seven cases; Vitest, Storybook axe and E2E are untouched; **PHPStan
cannot move at all** on a test-only diff, since it scans `app`, `database` and `routes`. All four BEFORE
mutants read `SURVIVED` and all four AFTER mutants read `CAUGHT`. ⚠️ **The BEFORE result is a proof
rather than a forecast and will be reported as one** — with exactly one active tenant the hoisted mutant
is *semantically identical* to the original, so it cannot fail; its value is narrower but real, proving
the token matched, the sha256 moved, the mutant parsed and the harness ran. **The one I most expect to be
wrong is `DraftReaperTest`'s two-tenant case** — the only one whose second-tenant fixture must be built
under a *second* RLS context, with `$this->drafts` resolved from the container under acme's.

---

## RELEASED — `M43`, every credential-verifying Fortify route gets a bound (merged as PR #233, `c09c7ef`, 6/6 green)

**Every claimed file was edited, and one was added that the claim did not name.**
`app/Http/Middleware/ThrottleFortifyEndpoints.php` (new) · `app/Providers/FortifyServiceProvider.php` ·
`config/fortify.php` · `bootstrap/app.php` · `tests/Feature/Auth/FortifyRateLimitTest.php` (new) ·
`docs/security-threat-model.md` · `docs/feature-backlog.md` · `PROGRESS.md` · this file.
**Namespaces spent: NOTHING** — no ADR, no migration prefix, no `§D`. **Nineteenth consecutive.**

### The row was a floor in both halves, which is now the expected shape rather than a surprise

**Evidence held on every citation** — the middleware list, `config/fortify.php`'s two-entry `limiters`
array, `saml-step-up` at 20/min, and the seven routes `StepUpReauthenticationTest` pins behind the
step-up. ⛔ **And it understated itself 1 → 8.** Measured from the live route table rather than read:
**26 Fortify routes, 14 of them writes**, with `throttle:` on **four** — and the two verification routes
carry the vendor's literal `6,1` because `limiters` names no `verification` key, so that number was never
a decision this project made. Unbounded and credential-bearing were `POST /forgot-password`,
`POST /reset-password` and `POST /register` — **none of which needs a session** — plus `PUT /user/password`,
the row's own `POST /user/confirm-password`, `POST /user/confirmed-two-factor-authentication` and the
three 2FA lifecycle verbs.

⛔ **THE PRESCRIBED REMEDY WAS STRUCTURALLY IMPOSSIBLE, AND THE REPOSITORY HAD ALREADY SAID SO THREE
TIMES IN ONE FILE.** *"One `RateLimiter::for()` plus one `->middleware('throttle:…')`"* — **Fortify has no
per-route middleware hook**, which `config/fortify.php` records at the `GateRegistration` note, at the
`EstablishTenantDatabaseContext` note, and again where it explains that `priority()` cannot substitute
because priority reorders middleware a route already carries and never adds one. **Sixth row in seven
whose evidence is sound and whose remedy is not.**

### ⛔ The plan's own ordering argument was wrong, and measuring it is the transferable half

The plan asserted the class **must** be listed in `bootstrap/app.php`'s `priority()` or `$request->user()`
would read null, silently degrading every authenticated limiter to its IP arm. It reasoned correctly about
`SortedMiddleware` moving a listed class to the last-seen lower-priority index, and drew the wrong
conclusion. **Measured on the live route table both ways, by deleting the entry and re-deriving:** listed,
the class lands at index **6**; unlisted, it lands at **13 — last, and still after `Authenticate` at 5.**
The user resolves either way.

The entry is **kept**, for the reason that survived measurement rather than the one that motivated it:
refusal at index 6 rather than 13 is refusal *ahead of* `EstablishTenantDatabaseContext`'s database round
trip, `SubstituteBindings` and Inertia. On `POST /forgot-password`, which anyone can reach, bounding the
**work** is the point; refusing after paying for tenancy resolution would bound the mail and not the load.
✅ **All three comments that had stated the false reason were rewritten before the commit landed** — a
false claim about a control is worse than a missing one, because it stops the next reader looking.

### ⚠️ Three vendor facts read from the installed source rather than from memory

**(a) `ThrottleRequests::handle()` gates its named-limiter branch on `func_num_args() === 3`.** A fourth
argument — a decay, a prefix, a tidy-up — silently routes to the numeric path, where `resolveMaxAttempts()`
finds a non-numeric value and throws `MissingRateLimiterException`: **a 500 on every guarded route**, not a
degradation. Proven by `MC3`.
**(b) Bucket keys are namespaced `md5($limiterName.$limit->key)`**, so two limiters cannot collide even
with identical `by()` keys — the per-limiter prefixes are readability, not correctness.
**(c) The write routes carry a `.store` suffix the view routes do not.** `register.store`,
`password.confirm.store` and `two-factor.regenerate-recovery-codes` against `register`, `password.confirm`
and `two-factor.recovery-codes`. ⛔ **The plan named all three wrongly.** A map keyed on the
obvious-looking name throttles three GET pages the axe suite scans, leaves all three endpoints open, **and
every behavioural case still passes** — because the pages are not what anything posts to. Caught by
reading the vendor route file before a line was written, and now pinned by a dedicated case.

### ✅ Controls, and the one whose asymmetry is the actual finding

Docker Desktop was down for the first two-thirds of the build, so four mutants were run against the
structural logic with `mutate.php`'s discipline reimplemented at the call site — M42's recorded rule for a
gate that is not Pest-in-a-container. Once the daemon was up, **three real `mutate.php` runs**, each with a
baseline first, sha256 asserted **moved**, and restore verified by byte comparison:

| Control | Result |
|---|---|
| **MC1** delegation replaced by `return $next($request)` | **CAUGHT — 4 failed / 4 passed** |
| **MC2** `password-confirm` key collapsed to `by('pwconf')` | **CAUGHT — exactly 1 red** |
| **MC3** a fourth argument added to the delegation | **CAUGHT — 4 red** |

⛔⛔ **MC1 IS THE RESULT WORTH CARRYING FORWARD: THE FOUR STRUCTURAL CASES STAYED GREEN WHILE EVERY
BEHAVIOURAL ONE WENT RED.** A gate that only reads the map would have asserted that a lookup table has the
right shape while every route it names went unthrottled — **decorative, and green.** The structural half is
worth having (it is what makes a vendor-added route redden the file without the test knowing its name), but
**it cannot stand alone, and only a mutation could show that.**
⚠️ **MC2 is the M30 defect reproduced deliberately.** Without the cross-user request — same address, same
minute, different account — one deployment-wide bucket for the redemption door of this app's own step-up
gate survives with everything green.

### ⛔ A mistake of my own that the suite caught, and it is M30's trap wearing different clothes

`expect($live)->toHaveKey($name, $message)` — **`toHaveKey()`'s second argument is the expected VALUE, not
a message**, so the explanatory sentence was being asserted as the value stored under that key. Same family
as the `toContain` variadic-needles trap M30 recorded, **except that one stayed GREEN with the wrong value
in the array and this one failed loudly. Only the luck differed.** The rule is not "beware `toContain`"; it
is **a Pest expectation's second argument is not universally a message — read the signature.** Written into
the case rather than into a commit nobody re-reads.

### How the prediction fared

- ✅ **Local scope `tests/Feature/{Auth,Settings,Notifications,Tenancy}` 561 / 1912 → 569 / 1962** —
  **+8 tests and +50 assertions, exactly the eight cases and exactly their fifty**, reconciling in both
  numbers. The baseline was taken **with the production change active and the test file held aside**, so it
  also proves the middleware moved no existing count; restored byte-identically.
- ✅ **AND THE TWO MEASUREMENTS AGREE WITHOUT HAVING BEEN MADE THE SAME WAY.** The post-merge run's
  whole-suite Pest delta is **+8 tests and +50 assertions** — identical to the local per-directory delta
  above, arrived at from a different total on a different machine. Pint's file count moved by **exactly
  two**, which is the two files added, so the count is itself a check that it scanned them. The absolute
  figures are **not** restated here; they live in `docs/gate-baselines.md`, regenerated from this
  increment's own post-merge run on the trunk.
- ⚠️ **WRONG, in the safe direction: PHPStan did not move.** The claim said it *could*, because
  `phpstan.neon` scans `app` and this adds a class there — a deliberate correction to the usual "a
  test-only diff cannot touch it". Measured: **18 errors across 10 files, the recorded baseline, and
  neither new file is among them.** The class was clean at level 8 first time.
- ⚠️ **WRONG, and this is the one the claim named as most likely to be wrong: E2E.** The worry was that
  `tests/e2e/support/console.ts` posts the confirm-password form on every console visit and
  `ThrottleRequests` counts successes. **E2E passed at 20 steps.** The ceiling is 10/min rather than 6
  *because* of that measurement, so the prediction was wrong and the sizing it produced was right — those
  are different things and only the second one shipped.
- ✅ **Pint `passed`, proven live first** by a deliberately misformatted probe (exit 1, file and fixers
  named), placed **outside `app/`** so it could not also redden PHPStan — M35's clean-up trap avoided by
  construction rather than by remembering.
- ✅ **`openapi.json` byte-identical**, verified by `sha256` across a fresh `scramble:export` rather than
  asserted from the reasoning that a 429 comes from route middleware no controller mentions.

### ➕ Filed rather than silently left

`PUT /user/profile-information` is a fifteenth Fortify write route and a **genuine second mail cannon** —
it nulls `email_verified_at` and sends a verification notification on every address change, so one
authenticated session can mail arbitrary recipients without limit. It is out of scope **by decision**, is
named in the gate's `FORTIFY_UNBOUND_BY_DECISION` list beside `logout` so the coverage equality passes
*because a decision was recorded*, and is filed as its own `minor` row. Remove it from that list and the
gate goes red naming it.

### ⚠️ Two environment findings

**Lane A's stack came back HALF-UP after Docker Desktop was started**, exactly as `M35` recorded: `docker
ps` showed `worker` and `scheduler` running while `app`, `postgres`, `redis`, `node` and `mailpit` sat
`Exited (255)`. **`docker ps` alone cannot tell a healthy lane from a half-dead one — list with `-a`.**
`docker compose up -d` from the lane's own worktree fixed it and `preflight` then probed the container
cleanly.

**`mutate.php` refuses a dirty target, and that refusal was correct and useful** — it caught two
uncommitted edits that would otherwise have been baked into the "restored" bytes.

---

## RELEASED — `M42`, the numbers become derivable and the hand-off generated (merged as PR #232, 6/6 green)

**Every claimed file was edited.** `CLAUDE.md` (new, 167 lines) · `scripts/state.php` (new) ·
`scripts/next.php` (new) · `scripts/preflight.php` · `scripts/tracker-lint.php` · `composer.json` ·
`PROGRESS.md` · `docs/feature-backlog.md` · this file. **Namespaces spent: NOTHING** — eighteenth
consecutive.

`PROGRESS.md` 519,566 → 505,226 bytes, +1 line. The Lane A hand-off went from **18,425 bytes to 2,812**
and is now rendered rather than written. Lane B's line was not touched.

### ⛔ Three attempts were needed to find a form the check could safely read, and the first two failed in this project's signature way

**A mention is indistinguishable from a declaration unless the form is constrained** — `M41` wrote that
after finding it twice. `M42` found it twice more, inside its own gate, on its own tree:

1. **Gating a claim file's `## Status:` block went red on the claim that introduced it.** That block is
   structurally bounded, which looked constrained enough. It is not: this increment's claim *quotes*
   lane-b's stale declaration in order to file it, and the gate read the quotation as a declaration.
2. **Gating the hand-off line as prose went red on every increment it narrates** — 69 failures, each one
   a correct historical mention of a past number.
3. **The form that works is POSITIONAL, not natural language:** an anchored `[state next=… adr=…
   migration=…]` block immediately after the hand-off arrow, rendered end to end by `next.php`.
   **Proven both ways** — corrupting any of the three values reddens it, and *quoting the entire block
   verbatim later in the same line does not*.

⛔ **THE TRANSFERABLE RULE IS STRONGER THAN "CONSTRAIN THE FORM": NATURAL LANGUAGE CANNOT CARRY A
MACHINE TOKEN AT ALL.** Every failure in this family — `preflight`'s literal scrape, `R7`'s marker
matched anywhere in a commit range, and both attempts above — is an attempt to make prose
machine-readable. The fix is never a better pattern; it is a token that prose cannot accidentally
produce.

### ⛔ The plan's prescribed first act would have falsified the log

It asked for *"deleting the six stale 'next free ADR is 0021' claims and the wrong `000109`."* Measured:
**`M38` had already deleted both live ones** and recorded at `:777-780` that it deliberately left five
historical `RELEASED` bullets alone — *"a dated record is not a stale forward claim, and editing one
would falsify the log."* Every remaining site sits inside a dated ledger bullet.

**Exactly two live declarations remained** — `PROGRESS.md:123`, the ADR number, and `:232`, the
exceptions-log entry — and those are what became pointers. Every dated record is byte-identical: the
tracker diff is four hunks, one of which is the new status bullet.

⚠️ **`:232` also cited the exceptions log at a path it does not live at** (`exceptions-log.md:339`
rather than `docs/ux/exceptions-log.md`) — a line number in a file that has since moved, which is the
citation class `M37` measured at roughly thirty occurrences.

### ⛔ And the reason the constitution cannot be read in one call is not the imperatives

`## Standing Rules` is 207,468 bytes. **Rule 7 alone is 196,596 of them — 94.8% — and 163,680 is a claim
ledger with zero imperatives**, duplicating `docs/claims/lane-*.md`, which has been the home of claims
since the 2026-08-25 amendment. The plan's premise was that separating imperative from rationale would
fix the size. It would not have moved the number materially. Filed as a `major` row with its own
increment and its own surgery proof — decided with the user, 2026-08-29.

### ➕ `\R` MATCHES A BYTE INSIDE A UTF-8 CHARACTER, AND IT COST THIS SCRIPT ITS LINE NUMBERS

`preg_split` on the regex newline class without the `u` modifier matches the single byte `0x85` as a
Unicode NEL — and that byte is a *continuation* byte in ordinary characters, not only exotic ones.
**Measured:** the first draft split this file into **2,297 lines where it has 2,273**, because the
corpus is full of check marks (`E2 9C 85`), and every line number it reported after the first one was
wrong by a growing offset. That is how a false positive was traced to line 55 when the real line was 54.

⚠️ **One live occurrence exists and is filed rather than fixed**, because `tests/` was outside this
claim: `tests/Feature/Audit/ImpersonationAttributionTest.php:204` splits a streamed audit CSV that way,
and **`Å` is `C3 85`** — the name `Åsa Lindqvist` splits that CSV into 4 rows where it has 3, shifting
every positional index the test asserts. **That is the `M9` shape**: a random faker name reddening a
test on a dice roll, where re-running would hide it forever.

### The row, and a remedy that was sound for once

Closes `docs/feature-backlog.md`'s *"`scripts/preflight.php` derives the next increment number from
prose, so a FORECAST reads as a SPEND."* **Its prescribed remedy was SOUND and is exactly what shipped**
— the first row in six whose fix did not have to be corrected. ⚠️ **But it understated itself:** it
names one consumer and there are two, because the same wrong number was rendered into every hand-off,
which is the artefact a fresh session actually reads. The fix is only complete because `next.php`
generates that line from the same figures.

✅ **MEASURED ON THE TREE THAT REPRODUCES IT.** Before the change, on this branch, `preflight` answered
*"highest M seen: `M42` → next free is `M43`"* — raised by this increment's own claim naming its own
number, which is precisely the compounding the row predicted. After: *"highest released `M41` → next
free is `M42`"*, with merged pull-request titles agreeing independently.

### Controls — ten of them, every mutation moving the sha256, every restore byte-exact

| Control | Verdict |
|---|---|
| A prose `M99` in a claim file | stays `M42` — the defect is not reproduced |
| The same text as a real `## RELEASED — M99` heading | moves to `M100` — the guard is not refusing everything |
| A numbered `RELEASED` heading *below* `## Template` | **exit 2, CANNOT MEASURE** — not a silently lower maximum |
| R8 vs a migration prefix · an increment number · a sub-decision id · "next free" | red ×4 |
| `CLAUDE.md` truncated to 20 lines · deleted outright | red ×2 — absence is not a free pass |
| `[state …]` values corrupted ×3 | red ×3 |
| The whole `[state …]` block quoted later in the same line | **green** — position, not substring |
| A second Lane A marker, then `next.php --write` | refuses, rather than taking the first |

⚠️ **`scripts/mutate.php` COULD NOT DRIVE ANY OF THEM, AND THAT IS ITS OWN ROW.** Its `--tests` argument
is Pest paths and it execs them through `docker exec`, so a gate implemented as a standalone script has
no harness at all — and Docker was down on this host, so there was no container either. The controls
therefore reimplement its discipline at the call site: a green baseline first, tokens read from files so
no shell can eat them, abort unless the sha256 moves, and restore by byte comparison rather than
`git checkout --`.

### How the prediction fared — and the one number it got wrong proves the row it filed

Everything structural held. **`Static analysis` stayed at 20 steps**, because R8 landed inside the
already-registered `tracker-lint` step rather than as a new one. Pest, Vitest, Storybook axe and E2E
were unmoved, as predicted from a diff touching no `app/`, `database/`, `routes/`, `tests/`,
`resources/` or `packages/`. PHPStan was unmoved and structurally unable to move.

⚠️ **THE ONE MISS WAS PINT'S FILE COUNT, AND IT MISSED FOR THE REASON THIS INCREMENT FILED A ROW
ABOUT.** The claim predicted **1419**, reasoning from `docs/gate-baselines.md`'s committed **1417**
plus the two new scripts. CI reported **1420**. The baseline is eleven commits and two increments
stale — it predates `M40`'s `scripts/tracker-lint.php` — so the trunk was already at 1418 and the
arithmetic was right on a wrong input.

⛔ **A STALE BASELINE DOES NOT ANNOUNCE ITSELF; IT PRODUCES A CONFIDENT WRONG PREDICTION.** The figure
looked measured because it *was* measured — just not recently, and nothing in the file or in
`preflight` said how long ago. That is the whole argument for the staleness row, made by the increment
that filed it, against itself.

### Six rows filed, not fixed, each at the moment it was decided

The ledger surgery (`major`) · the `\R` defect in `tests/` · `gate-baselines.md` having no staleness
signal while being eleven commits stale · a claim file having no constrained forward-declaration form,
so lane-b's stale number cannot be gated · R8 being unable to reach `PROGRESS.md` until the ledger moves
· `mutate.php` being unable to control a gate that is not Pest.

**Net: one closed, six filed.** That is the treadmill `D5` describes, and every one of the six was
measured rather than suspected.

### Files

`CLAUDE.md` · `scripts/state.php` · `scripts/next.php` · `scripts/preflight.php` ·
`scripts/tracker-lint.php` · `composer.json` · `PROGRESS.md` · `docs/feature-backlog.md` ·
`docs/claims/lane-a.md`. **Namespaces spent: NOTHING.**

---

## RELEASED — `M41`, the tracker surgery (merged as PR #231, 6/6 green)

**`PROGRESS.md` 1,451,863 → 513,856 bytes (−64.6%), 2,152 → 579 lines.** 1,576 lines and 135 older
status bullets moved verbatim into `PROGRESS_ARCHIVE.md`. **Namespaces spent: NOTHING** — seventeenth
consecutive.

### Proved four ways, three of them independent of each other

1. **Multiset of 1,576 moved line-hashes** preserved with exact multiplicity — a *counted set
   equality*, which is exactly what the 2026-08-16 incident lacked.
2. **Byte conservation, exact:** `2,597,391 == 2,597,391`.
3. **Standing Rules byte-identical**, `sha256 764cb1b0…` — the constitution came through untouched.
4. **Independent git-level proof**, outside the script's own arithmetic: the moved slice read from the
   *pre-surgery commit* and the tail of the *new archive* both hash to `85c52749…`.

⚠️ **PROOF 4 FAILED ON ITS FIRST RUN AND THE CHECK WAS WRONG, NOT THE SURGERY** — it used `310,1886`,
including the blank line the script had deliberately popped. **A failing independent check is not
automatically a defect; verify the check before believing it.**

### How the prediction fared — it named the failure and the failure was one byte

The claim flagged byte conservation as most likely to break *"because this is not a pure permutation"*.
It broke: **2,597,391 vs 2,597,392**. The first formula omitted the **join seam** between the old
archive tail and the first inserted line; the truth is `sum(P)+sum(H)+|P|+|H|+1`. ⛔ **A conservation
check with a tolerance would have absorbed that silently. An exact one named it**, and the fix is
derived arithmetic, not a fudge factor.

### ⛔ `R7` reported the deletion as DECLARED before the surgery was committed

The marker was matched **anywhere** in the commit range, so the **claim commit** — whose message merely
explains that surgery commits must carry the marker — armed it. **A mention is indistinguishable from
a declaration unless the form is constrained**, which is the same defect as `preflight` reading a
number in prose as a spend: **found twice in one session, and this time inside a gate written one
increment earlier.**

The marker must now **start a line**. Proven by construction: same tree, same commit range, verdict
flipped from a wrong `declared` to a correct **FAIL**.

### `## Current Status` deliberately survives, because the previous gate said so

`M40`'s `R2` asserts exactly one such heading in the tracker, so deleting it would have turned the
previous increment's merge-blocking gate **red**. That gate working on its first real test is why the
shape is *heading + newest 10 bullets + pointer*. The moved block sits under its own archive heading —
**not** a `Current Status` one, which `R4` forbids there and which would be wrong regardless.

**Gate constants lowered in the same commit, or `main` merges red:**
`EXPECTED_CROSS_FILE_NEXT_SESSION` 2 → 1 (the archive's duplicate heading renamed) and
`TRACKER_BYTE_CEILING` 1,500,000 → 600,000, keeping deliberate headroom rather than hugging the new
size.

### ⚠️ The plan's acceptance test was unreachable and is restated, not quietly missed

It asked for **~40 KB**. Removing *all* of Current Status leaves 462,966 bytes, because **Standing
Rules (207,468) + Next Session (226,452) + tail = 462,646 on their own.** No arrangement of this
increment reaches it, and neither does the `CLAUDE.md` split that follows — that moves the imperatives
out and leaves the rationale the plan says this file keeps. **One-call loading needs the Next Session
history and the Standing Rules incident record to move as well: a decision about what the constitution
keeps, not a splice.**


**Taken 2026-08-28.** Branch `m41-tracker-surgery`, cut from `origin/main` at `79d1589`, PR into `main`.
Fourth increment of the operating-loop realignment, and **the one the previous increment exists to
guard**. Its commits carry `[tracker-surgery]`, or `R7` refuses them.

⚠️ Numbered from the `## RELEASED` headings; `preflight` agrees. Lane B reads **NO ACTIVE CLAIM**,
highest released `M35`. Both files re-read in full at write time.

### Row

From the approved realignment plan. Not a `docs/feature-backlog.md` row.

### Evidence verified — the anatomy, measured before a byte is moved

`PROGRESS.md` is **1,451,863 bytes / 2,152 lines**, pure LF, no BOM, trailing newline present.
**The five segments sum to exactly the file size, and that identity is the first assertion:**

| Section | lines | bytes |
|---|---|---|
| header | 1–6 | 320 |
| `## Standing Rules` | 7–287 | 207,468 |
| **`## Current Status`** | **288–1886** | **988,897** |
| `## Next Session` | 1887–2077 | 226,452 |
| tail | 2078–2152 | 28,726 |
| **sum** | | **1,451,863** ✅ |

`## Current Status` holds **145 top-level bullets**. The archive is 1,145,528 bytes and carries the
second, stale constitution: `## Standing Rules for This Project` (line 5) and a `## Next Session —
Resume Here` (line 16) that is **byte-identical to the tracker's heading**.

### Remedy verdict

⛔ **THE PLAN'S ACCEPTANCE TEST IS UNREACHABLE BY THE OPERATION IT PRESCRIBES, AND THE ARITHMETIC SAYS
SO BEFORE ANY WORK STARTS.** It asks for *"`PROGRESS.md` at or under ~40 KB, so the constitution can be
loaded, not merely opened."* Removing **all** of `## Current Status` leaves **462,966 bytes** — Standing
Rules (207,468) plus Next Session (226,452) plus the tail are **462,646 bytes on their own.** No
arrangement of this increment reaches 40 KB, and **neither does the `CLAUDE.md` split that follows it**:
moving the imperative half of Standing Rules out still leaves the rationale and the incident record that the plan
explicitly says `PROGRESS.md` keeps.

**So the target is restated honestly rather than quietly missed:** this increment takes the tracker from
**1,451,863 to roughly 513,000 bytes — a 65% reduction, and from ~58 `Read` calls to ~5.** One-call
loading needs the Next Session history and the Standing Rules incident record to move as well, which is
a separate decision about what the constitution keeps, not a splice.

⛔ **AND `## Current Status` MUST NOT SIMPLY DISAPPEAR — `M40`'s OWN GATE FORBIDS IT.** `R2` asserts
exactly one `^## Current Status$` in the tracker, so deleting the heading would turn the previous
increment's merge-blocking gate red. That is the gate working as designed on its first real test. The
heading stays, holding **the newest 10 bullets (50,477 bytes, `M40` back to `M29`) plus a pointer**;
the older **135 bullets (~938,400 bytes)** move to the archive under a heading of their own.

⚠️ **The moved block must NOT carry a `## Current Status` heading into the archive** — `R4` forbids one
there, and that is also the right shape: an archive of past status is not a current status.

### The method, and why it is not a search

⛔ **SPLIT BY PRE-MEASURED LINE INDEX, THEN PROVE THE RESULT IS A PERMUTATION OF THE INPUT BYTES.** The
1,086-line deletion of 2026-08-16 (`f565ac9`) happened because a script searched for an anchor in a file
containing a **verbatim example of that anchor**. A better regex is not the fix; not searching is.
`M40` hit the same shape three times in one increment, and `M38`'s own status bullet quoted the heading
it was being inserted under.

**Two commits on one branch — archive first, then tracker — so git itself can prove the move:** the
moved slice taken from the tracker at the commit *before* the surgery must hash identically to the same
slice read out of the new archive.

**Assertions, all of which must pass or the branch is abandoned:**

1. **Multiset of line hashes** — every moved line appears in the archive with *exactly* the same
   multiplicity. A counted set equality, not "the archive got bigger". This is what the 2026-08-16
   incident lacked.
2. **Byte conservation** as an exact integer: `tracker_before + archive_before == tracker_after +
   archive_after + (bytes of headings added)`, with the added bytes stated, not inferred.
3. **Surviving-section identity** — `sha256` of Standing Rules (lines 7–287) byte-identical before and
   after. The constitution must come through untouched.
4. **Heading uniqueness** — one `^## Standing Rules$`, one `^## Current Status$`, one `^## Next
   Session` in the tracker; **and exactly one `^## Next Session` across BOTH files**, down from two.
5. **`tracker-lint` constants lowered in the same commit** — `EXPECTED_CROSS_FILE_NEXT_SESSION` 2 → 1,
   and the byte ceiling from 1,500,000 to the new real headroom. A gate whose expectation the surgery
   invalidates must be updated by the surgery, or `main` merges red.
6. **Encoding** — zero CR, no BOM, trailing newline, both files.
7. **`git diff --name-only`** returns exactly the four expected paths.
8. **The acceptance test, restated** — tracker at or under **520,000 bytes** and readable in ~5 calls,
   with the remaining blockers named by size rather than hand-waved.

### Files

`PROGRESS.md` · `PROGRESS_ARCHIVE.md` · `scripts/tracker-lint.php` (two constants) ·
`docs/claims/lane-a.md`. **Namespaces spent: NOTHING** — seventeenth consecutive.

### Prediction

No code, no test, no `.vue`: every gate unmoved except `tracker-lint`, whose own constants change.
`scripts/` is on Pint's path, so bare `pint --test` must stay green. Static analysis stays at **20**
steps.

⚠️ **THE ONE I MOST EXPECT TO BE WRONG: the byte-conservation assertion, because it is not a pure
permutation.** Moving a block also *adds* heading and pointer text, so a naive equality will fail and
the honest form must account for exactly the bytes added — stated as an integer computed from the
strings inserted, never as a tolerance. **A conservation check with a fudge factor is not a
conservation check.**

---

## RELEASED — `M40`, a merge-blocking tracker gate, and a vacuous success found inside it (merged as PR #230, `9c20924`, 6/6 green)

**Every claimed file was edited.** `scripts/tracker-lint.php` (new), `composer.json`,
`.github/workflows/ci.yml`, plus the six misattribution corrections. **Namespaces spent: NOTHING** —
sixteenth consecutive Lane A increment.

### How the prediction fared — it held, including the part flagged as most likely wrong

**Static analysis went 19 → 20 steps**, measured on the green run (`33183400689`). ⚠️ **The red control
run showed 19 and that number was NOT the delta**: a failure at step 12 skipped `Setup Node`, which
takes its paired `Post Setup Node` step with it, so the count coincidentally matched the old one. **A
step count read off a failed run is not comparable to one read off a green run** — that is a new
instance of this project's *measure the right thing* rule, and it was nearly reported as a miss.

The claim named the line-count-delta rule as most likely to be wrong, *"if the parent is not reachable
the check must fail loudly rather than skip"*. **It was right to flag it, and the failure was worse
than predicted** — see below.

### ⛔ THE CONTROLS FOUND A VACUOUS SUCCESS INSIDE THE GATE WRITTEN TO END VACUOUS SUCCESSES

**`R7` — the one rule that would have caught the 1,086-line deletion — was permanently blind on this
host, and it reported success.** It used `HEAD` followed by a caret. PHP's `exec()` runs through
**cmd.exe** on Windows, where **the caret is the escape character**, so git received plain `HEAD`.
Measured: `HEAD` and `HEAD`-caret both resolved to `216ea25`, while `HEAD~1` gave `f537bea`.

The rule compared `PROGRESS.md` **against itself** and printed `+0` forever. ⚠️ **The cannot-measure
guard could not save it** — `rev-parse --verify --quiet` *succeeded*, because `HEAD` resolves perfectly
well. It did not skip loudly; **it passed quietly.** ⛔ **Correct on Linux CI and blind on every local
run**, so CI would have been green while every local proof of `R7`, including all future ones, was a
lie.

✅ **FOUND ONLY BECAUSE ONE CONTROL COMMITTED ITS MUTATION INSTEAD OF LEAVING IT IN THE WORKING TREE.**
The first harness mutated in place and `R7` reported **CAUGHT** — correctly, but for the wrong reason:
with the change uncommitted, `HEAD` still held the unmutated file. **A control that does not reproduce
CI's actual condition can confirm a broken check as confidently as a working one.** Fixed to `HEAD~1`
throughout, with the trap written into the script rather than into a hand-off.

### The gate is proven on BOTH sides, which is the whole point of it being a CI step

| Proof | Where | Result |
|---|---|---|
| Eight violations, one per rule group | host | **8/8 CAUGHT**, both files restored byte-identically by sha256 |
| Declared-surgery escape, mutation **committed** | host | undeclared drop **fails** (naming `f565ac9`); with `[tracker-surgery]` it **passes** |
| **`R7` under `fetch-depth: 2`** | **CI** | **step 12 failed**: *"lost 300 lines (2151 down to 1851) … no commit in HEAD~1..HEAD carries [tracker-surgery]"* |

⛔ **THE PR WAS DELIBERATELY RED BEFORE IT WAS GREEN.** `R7`'s CI behaviour depends on `fetch-depth: 2`
making the parent reachable, which cannot be tested on the host — so the violation was pushed on
purpose and reverted in the same PR, restored byte-identically. **A gate proven only on the host is the
defect being re-committed.**

⚠️ **The escape hatch was proven separately because the surgery cannot merge without it.** A gate with
an untested escape is a gate that blocks the next increment.

⚠️ **And the first attempt at that control was invalid, recorded rather than quietly rerun:** it cut
the last 301 lines, removing the `Next Session` heading and both hand-off markers, so three other rules
fired and `R7`'s result was buried. Cutting from the **middle** isolates the rule under test.

### Two prescribed remedies did not work

1. **"Exactly one `^## Standing Rules` and one `^## Next Session` across both files" would have been RED
   ON ARRIVAL.** `^## Standing Rules$` is unambiguous (tracker **1**, archive **0**), **but
   `^## Next Session` is byte-identical in both files**, so the cross-file count is **2**. The gate
   pins exactly 2 — blocking the hazard the plan itself named, *"a naive append would make three"* —
   and the surgery lowers it to 1 in the same commit that removes the archive's duplicate.
2. **`scripts/mutate.php` cannot prove this gate**: it runs Pest inside the app container, and this is
   a standalone CLI script.

### Six misattributions corrected, and the distinction matters

The incident is **J4b1's**, 2026-08-16, `f565ac9` — settled from `git show --numstat`, never from
prose. The corpus said `M31` (4 sites) or `M16` (2); **every `M` number was invented by a later
retelling, and `M38` and `M39` wrote three of them.** The only site that never named an increment is
the only one that stayed true.

⛔ **This is NOT the case `M38` left alone.** Those `0021` bullets recorded what was *true when
written* — a dated record is not a stale claim. **This was never true at any point**, so correcting it
fixes an error rather than falsifying a log.

⚠️ **AND THIS CLAIM'S OWN CITATIONS WENT STALE INSIDE THE COMMIT THAT WROTE THEM.** The attribution
table cited line numbers, and splicing the claim into that same file moved every one — the *"~30
citations false or moved"* class `M37` measured, reproduced live. It now cites counts and filenames
only. **Counts and filenames are stable; line numbers in a file being edited are not.**

### ➕ The absence-read-as-success family gained two members in one increment

`steps: []` is a job that never acquired a runner. A path-filtered push is a run that does not exist.
**`HEAD`-caret was a comparison against itself that reported success.** And the watcher script polling
this PR read an **empty check rollup** as *"all checks complete"* — the old run had just been cancelled
and the new one had not registered, so *"nothing is incomplete"* was satisfied by nothing at all.
⚠️ **Four shapes, one defect: absence mistaken for a passing result** — two of them found while
building the gate that exists for exactly that class.


**Taken 2026-08-28.** Branch `m40-tracker-lint`, cut from `origin/main` at `f537bea`, PR into `main`.
Third increment of the operating-loop realignment. **It must land before the tracker surgery**, because
a local-only check would not have caught the incident it exists for: that deletion **merged green**.

⚠️ **Numbered from the `## RELEASED` headings, and `preflight` now agrees** (`M39` → next free). Lane B
reads **NO ACTIVE CLAIM**, highest released `M35`. Both files re-read in full at write time.

### Row

From the approved realignment plan. Not a `docs/feature-backlog.md` row.

### Evidence verified

⛔ **THE INCIDENT IS REAL, AND IT IS MISATTRIBUTED IN SIX PLACES — THREE OF WHICH THIS LANE WROTE
YESTERDAY.** Settled from git rather than from prose: commit **`f565ac9`**, dated **2026-08-16**,
subject *"docs(progress): LANE A — J4b1 merged as #158"*, numstat **`1 1086 PROGRESS.md`** — one line
added, **1,086 deleted**, in the `j4b1-tracker` window (PR #160). **It is a J-series incident.**

| Attribution in the corpus | Sites | Verdict |
|---|---|---|
| *"M31's 1,086-line deletion"* | 4 live | **WRONG** — `M38`/`M39` wrote three of them |
| *"M16's 1,086-line deletion"* | 2 live | **WRONG** |
| The original record, `PROGRESS.md:90` | 1 | **RIGHT** — mechanism plus the date, claiming no `M` number |

**Line numbers are deliberately not cited for those six.** The first draft of this table gave them, and splicing this very claim into this very file moved every one of them — a citation that its own commit invalidates is the `~30 false or moved citations` class M37 measured, reproduced live. Counts and file names are stable; line numbers in a file being edited are not.

⚠️ **THE ONE SITE THAT NEVER NAMED AN INCREMENT IS THE ONLY ONE THAT STAYED TRUE.** Every later
retelling added a number, and every added number was invented. That is this project's
*documentation-asserting-what-the-code-does-not-do* class applied to its own incident log — and the lane
that filed ten such backlog rows produced three more of them in two increments.

⛔ **AND THIS IS NOT THE SAME CASE AS `M38`'s `0021` BULLETS, WHICH WERE DELIBERATELY LEFT ALONE.** Those
recorded what was *true when written*; a dated record is not a stale claim. **This was never true at any
point**, so correcting it fixes an error rather than falsifying a log. All six sites are corrected.

### Remedy verdict

**The plan prescribes two things that do not work on this tree, both verified.**

1. ⛔ **"Exactly one `^## Standing Rules` and one `^## Next Session` ACROSS BOTH FILES" WOULD BE RED ON
   ARRIVAL.** Measured: `^## Standing Rules$` is unambiguous (`PROGRESS.md` **1**, archive **0** — the
   archive's reads *"…for This Project"*), **but `^## Next Session` is byte-identical in both files**, so
   the cross-file count is **2** today. A gate asserting 1 could never merge. **What ships instead pins
   the hazard the plan itself named** — *"a naive append would make three"* — asserting the cross-file
   count is **exactly 2**, plus per-file uniqueness. The surgery lowers it to 1, deliberately and visibly.
2. ⛔ **`scripts/mutate.php` CANNOT PROVE THIS GATE.** It runs **Pest inside the app container**
   (`--tests=<pest paths>`), and Docker is down on this host besides. `tracker-lint.php` is a standalone
   CLI gate, not a Pest test. **The control is one deliberate violation per rule**, each run against the
   real script and restored by byte comparison — and, because a local-only gate is exactly what this
   increment exists to reject, **one violation is pushed to the PR branch so CI itself goes red**, then
   fixed in the same PR. A gate proven only on the host is the defect being re-committed.

**And the byte ceiling cannot be the target value.** `PROGRESS.md` is **1,444,477 bytes** today. The
ceiling ships just above current as a **ratchet**, printing its headroom so staleness is visible, and the
surgery drops it to the real target. A ceiling red on arrival is a gate nobody can merge.

### Files

`scripts/tracker-lint.php` (**NEW** — Lane B's column) · `composer.json` (claim-first) ·
`.github/workflows/ci.yml` (claim-first: one step plus `fetch-depth: 2`) · `PROGRESS.md` (two
misattributions, plus Lane A's block and hand-off line) · `docs/claims/lane-a.md` (four
misattributions).

⛔ **CROSSES THE LANE BOUNDARY ON PURPOSE; LANE B HOLDS NOTHING, VERIFIED AT WRITE TIME. THIS CLAIM IS
THE PERMISSION** — the `M28`/`M39` shape. **Namespaces spent: NOTHING.** Sixteenth consecutive.

### ⛔ THE CONTROLS FOUND A VACUOUS SUCCESS INSIDE THE GATE WRITTEN TO END VACUOUS SUCCESSES

**`R7` — the one rule that would have caught the 2026-08-16 deletion — was permanently blind on this
host, and it reported success.** The check used `HEAD^`. PHP's `exec()` runs through **cmd.exe** on
Windows, where **the caret is the escape character**, so the shell delivered `HEAD`. Measured:

| Command through `exec()` | Returned |
|---|---|
| `git rev-parse --short HEAD` | `216ea25` |
| `git rev-parse --short HEAD` + caret | **`216ea25`** — the caret was eaten |
| `git rev-parse --short HEAD~1` | `f537bea` |

So the rule compared `PROGRESS.md` **against itself** and printed `+0` forever. ⚠️ **And the
cannot-measure guard could not save it**: `rev-parse --verify --quiet` *succeeded*, because `HEAD`
resolves perfectly well. The check did not skip loudly — **it passed quietly.**

⛔ **IT WOULD HAVE BEEN GREEN ON LINUX CI AND BLIND ON EVERY LOCAL RUN**, which is the worse half: CI
would have been correct while every local proof of `R7` was a lie, including all future ones.

✅ **IT WAS FOUND ONLY BECAUSE ONE CONTROL COMMITTED ITS MUTATION INSTEAD OF LEAVING IT IN THE WORKING
TREE.** The first harness mutated the file in place and `R7` reported **CAUGHT** — correctly, but for
the wrong reason: with the change uncommitted, `HEAD` still held the unmutated file, so comparing
against `HEAD` accidentally gave the right answer. **A control that does not reproduce CI's actual
condition can confirm a broken check.** Fixed to `HEAD~1` throughout, and the trap is written into the
script rather than into a hand-off.

### The controls, all measured, all restored by sha256 byte comparison

**Eight violations, one per rule group, each run against the real script — 8/8 CAUGHT**, and both
tracker files verified byte-identical afterwards:

| Control | Verdict |
|---|---|
| `R1` push `PROGRESS.md` past the ceiling | CAUGHT, naming the byte count |
| `R2` a second `Current Status` at line start | CAUGHT |
| `R3` a **third** `Next Session` — the hazard the plan named | CAUGHT |
| `R4` the archive grows a `Current Status` | CAUGHT |
| `R5` trailing newline stripped · a CR byte introduced | CAUGHT, both |
| `R6` the Lane A hand-off marker duplicated | CAUGHT |
| `R7` 300 lines removed, undeclared | CAUGHT |

⛔ **AND THE ESCAPE HATCH WAS PROVEN SEPARATELY, BECAUSE THE SURGERY CANNOT MERGE WITHOUT IT.** With
the mutation **committed** — the real CI condition — a 300-line drop with no marker **fails**
(`exit 1`, naming `f565ac9`), and the identical drop with `[tracker-surgery]` in the message **passes**
(`exit 0`, printing `DECLARED SURGERY: 300 lines removed`). A gate with an untested escape is a gate
that blocks the next increment.

⚠️ **The first attempt at that control was itself invalid and is recorded rather than quietly
rerun:** it cut the last 301 lines, which removed the `Next Session` heading and both hand-off markers,
so `R2`, `R3` and `R6` fired and `R7`'s result was buried. Cutting from the **middle** isolates the
rule under test.

### ⚠️ This claim's own citations went stale inside the commit that wrote them

The first draft of the attribution table cited line numbers — and **splicing this claim into this file
moved every one of them.** A citation invalidated by its own commit is exactly the *"~30 citations
false or moved"* class `M37` measured across the backlog, reproduced live. The table now cites counts
and file names only. **Counts and filenames are stable; line numbers in a file being edited are not.**

### Prediction

No `app/`, `database/`, `routes/`, `.vue` or test file: Pest, Vitest, axe, E2E, PHPStan, `openapi.json`
and the five host lint gates unmoved. `scripts/` **is** on Pint's path, so bare `pint --test` must stay
green. **Static analysis goes 19 → 20 steps** — that delta, not the green tick, is the evidence the gate
is registered rather than merely written.

⚠️ **THE ONE I MOST EXPECT TO BE WRONG: the line-count-delta rule.** On a `pull_request` event
`actions/checkout` gives a **merge commit**, so `HEAD^` is main's tip rather than the PR head, and
`fetch-depth: 2` must actually make that parent reachable. If it does not, the check must **fail loudly
rather than skip** — a delta silently not measured is precisely the vacuous-success family this
increment is joining.

---

## RELEASED — `M39`, CI's post-merge verification stopped being cancelled (merged as PR #229, `454d9ba`, 6/6 green)

**Every claimed file was edited.** `.github/workflows/ci.yml`, `scripts/gate-baselines.php` and
`docs/gate-baselines.md` (regenerated, never hand-edited). **Namespaces spent: NOTHING** — fifteenth
consecutive Lane A increment.

### ✅ The measurement that closes the row — and it is the first of its kind here

**Run `33175202807`, `M39`'s own merge-commit run on `main`: `completed/success`, `event=push`,
`headBranch=main`,** six jobs with real step counts (Static **19** · Contract **16** · E2E **20** ·
Frontend **12** · axe **11** · Pest **11**). ⛔ **That is the first merge-commit run to SURVIVE on
`main` in seven increments** — #222, #223, #224, #226 and #228 were all cancelled, along with M36's
and M38's close-outs.

### ⛔ The control the PR could not give, and why the obvious one was not enough

⚠️ **CONTROL 2 (PR-SIDE CANCELLATION STILL FIRES) IS NECESSARY AND NOT SUFFICIENT, WHICH WAS NEARLY
MISSED.** Run `33173580512` was cancelled by a second push to the PR branch, proving the expression is
evaluated rather than ignored. **But an `always-true` `cancel-in-progress` passes that identical test
while leaving `main` exactly as broken** — a PR-side control cannot distinguish *correctly evaluated*
from *always true*. **The reasoning that "the expression is evaluated, therefore `push` yields false"
is an argument, not a measurement**, and this project's standing rule is that a gate you just added is
proven by a positive control.

✅ **SO THE CONTENTION WAS FORCED ON PURPOSE.** A real finding — `deploy.yml`'s trigger meaning changed
with this fix — was filed as its own backlog row and **pushed to `main` at 21:25:08 while
`33175202807` was in flight**, on a path deliberately outside the new `paths-ignore` set so that it
would create a contending run. Under the old configuration the merge run would have been dead inside
thirty seconds. **It was still `in_progress` at 21:25:18, :50, 21:26:21, :52 and 21:27:23, and it went
on to `success` at 21:43:33.** ⛔ **Two CI runs coexisted on `main` — `33175202807` and `33175297787` —
which has never happened in this repository before.** The main-side arm is proven by measurement.

### The guard, proven in both directions

| Control | Run | Result |
|---|---|---|
| the run that stamped the live baseline file | `33132909007` (`pull_request` on `m36-loop-verification-harness`) | **REFUSED, exit 1** |
| `M38`'s own PR run | `33171176804` (`pull_request`) | **REFUSED, exit 1** |
| **negative — a real push on `main`** | `33135990415` | **ACCEPTED, exit 0** |

⛔ **A GUARD THAT REFUSES EVERYTHING IS NOT A GUARD**, which is the whole reason the third row exists.
⚠️ **The second refusal first read `EXIT=0` because it was measured through a pipe** — `head`'s status,
not the script's. **A pipe hides the exit status**; it is written down in this project and it still
cost a re-measurement.

### ⚠️ The prescribed remedy was wrong in one detail that would have broken a designed feature

The plan said refuse any run whose **`event != push`**. That rejects the **nightly `schedule` run on
`main`** (`33079934859`), a genuine successful measurement of the trunk added on purpose as insurance
against an outage-skipped verification. **Implemented instead: refuse `pull_request`, and refuse any
`headBranch != main`** — which closes the defect and admits `push`, `schedule` and `workflow_dispatch`.
`--run=` was **kept**: the escape hatch was never the defect, the missing validation was.

### ✅ Baselines stamped from a post-merge `main` run for the first time ever

`docs/gate-baselines.md` now names run `33175202807` — `push` on `main`, sha `454d9ba`. The previous
generation named a `pull_request` run on a feature branch, and the one before that was a **docs-only
push**, because every merge run had been cancelled. ⚠️ **Only the provenance line changed: not one gate
number moved**, exactly as the claim predicted for a diff touching only `ci.yml` and `scripts/`.

### How the prediction fared

Every gate held as predicted, and the one flagged as *"most likely to be wrong — that the PR run proves
the concurrency fix"* was **correct to flag and is the reason the contending push exists**. Pint was
proven not blind on `scripts/` by a deliberately misformatted probe (exit 1, twelve fixers, deleted and
verified absent) — a bare `pint --test` had returned `{"tool":"pint","result":"passed"}` **with no file
count at all**, which is precisely the shape of *"`passed` is not evidence it scanned anything"*.

### ➕ One row filed, not fixed

**`deploy.yml`'s effective trigger changed meaning with this fix and nothing says so at the site.**
Before `M39` the only runs that could ever reach its `workflow_run` gate were docs-only close-out runs
— a production deploy path that could only ever have shipped a documentation commit. It will now fire
on real merge runs, which is correct and is also a material change to a latent path. **Latent, not
live:** `DEPLOY_ENABLED` is unset. Filed because the day that variable is set is the wrong day to
discover it.


**Taken 2026-08-28.** Branch `m39-ci-truth`, cut from `origin/main` at `c532d59`, PR into `main`.
Second increment of the operating-loop realignment, and **the correctness fix of the pair**.

⚠️ **NUMBERED `M39` FROM THE `## RELEASED` HEADINGS AND `gh pr list --state merged`, NOT FROM
`preflight`** — which currently answers **one increment too high** because `M38`'s claim discusses `M39`
while filing the row about that very behaviour. Lane A's highest released is `M38` (PR #228,
`44e79a9`); Lane B's is `M35`; `lane-b.md` reads **NO ACTIVE CLAIM**. Both files re-read in full at
write time.

### Row

Not a `docs/feature-backlog.md` row. Taken from the approved realignment plan, whose highest-value
single line this is.

### Evidence verified

**Every claim below re-measured on this tree; one was measured deliberately an hour ago.**

- ⛔ **`ci.yml:38-40` cancels `main`'s post-merge verification.** `concurrency.cancel-in-progress` is an
  unconditional `true`, and an increment pushes to `main` two or three times within minutes, so each
  push kills the previous run. **Measured across `main`'s recent history — six cancelled runs, five of
  them merge commits:** `33039884109` (#222) · `33044944615` (#223) · `33109182225` (#224) ·
  `33133951888` (#226) · `33134073337` (M36 close-out) · **`33172633170` (#228, `M38`'s own merge run,
  cancelled by `M38`'s close-out push at 12:5x today — watched from `in_progress` to `cancelled` on
  purpose, as the last clean "before" measurement).** `M38` also cancelled `33170390017`, its own
  **claim** run, with its claim-extension push.
- ⛔ **And `ci.yml:16-17` asserts the opposite in its own words** — *"the post-merge `push` run above is
  the re-verification."* It is not; it is being killed by construction. That comment is the reason the
  defect survived: anyone auditing the file reads the assertion, not the run list.
- ⚠️ **`deploy.yml` inherits it.** It fires on `workflow_run` of CI `completed` on `main` gated on
  `conclusion == 'success'`, so **the only runs that could ever trigger a deploy today are docs-only
  close-out runs** — every code merge is cancelled. `DEPLOY_ENABLED` is unset (`gh variable list` is
  **empty**), so it is latent rather than live, and it detonates the day it is set.
- ⛔ **`docs/gate-baselines.md` is stamped from a PR-branch run.** Its provenance names run
  `33132909007`; verified **`event=pull_request`, `headBranch=m36-loop-verification-harness`**. The file
  written to end stale numbers was measured off a proposal, not the trunk. The script's *default* path
  is correct; the `--run=` escape hatch walked around it.
- ⚠️ **`phase1-completion` is still a live trigger, not dead config.** It is on both `push` and
  `pull_request` in `ci.yml`, and the branch **still exists on the remote** (`f4fb535`) despite being
  retired by PR #179 on 2026-08-18.
- ✅ **The `paths-ignore` set is safe, and it was measured twice.** (a) The only test that reads a
  tracked docs file at runtime is `tests/Feature/Mail/QueuedMailContractTest.php:135` →
  `docs/deployment-infrastructure.md`, which is **not** in the set; every other `docs/` hit under
  `tests/` is a comment. (b) **The last four close-out commits touch only the five proposed paths and
  nothing else** — `PROGRESS.md`, `PROGRESS_ARCHIVE.md`, `docs/claims/**`, `docs/gate-baselines.md`,
  `docs/backlog-triage.md`. The set was derived from the right observation.

### Remedy verdict

**The plan's prescribed fix is right in its main clause and WRONG in one detail, and the detail would
have broken a designed feature.** It says `gate-baselines.php` should refuse any run whose
**`event != push`**. That rejects the **nightly `schedule` run on `main`** — a real, successful
measurement of the trunk (`33079934859`), added deliberately as *"cheap insurance"* against an
outage-skipped verification. **The guard implemented instead rejects `pull_request` and any
`headBranch != main`**, which closes the actual defect and admits `push`, `schedule` and
`workflow_dispatch`. ⚠️ **And `--run=` is KEPT**: the escape hatch was never the defect, the missing
validation was — a check that only guards the default guards the case nobody gets wrong.

⚠️ **A second figure in the same plan is wrong and is corrected in `D7` rather than here:** it names
**five** required CI contexts. There are **six**.

### Files

`.github/workflows/ci.yml` · `scripts/gate-baselines.php` · `docs/gate-baselines.md` (regenerated, not
hand-edited) · `docs/claims/lane-a.md` · `PROGRESS.md` (Lane A's block and hand-off line only) ·
`PROGRESS_ARCHIVE.md` (close-out entry).

⛔ **THIS CLAIM CROSSES THE LANE BOUNDARY ON PURPOSE, WHICH IS WHY IT IS WRITTEN BEFORE ANY FILE IS
OPENED.** `scripts/` is **Lane B's column** under Standing Rule 7(b), and `.github/workflows/ci.yml` is
in the **"NEITHER — claim in this file FIRST"** column. **Lane B holds nothing**, verified in the same
read that fixed the number, so this is the cheapest available window. **This claim is the permission**
— the same shape as `M28`. **Namespaces spent: NOTHING** — no ADR, no migration, no `§D<n>`. Fifteenth
consecutive Lane A increment spending nothing.

### Prediction

No `app/`, `database/`, `routes/`, `.vue` or test file is touched, so **Pest, Vitest, Storybook axe,
E2E, PHPStan, `openapi.json` and all five host lint gates are unmoved by construction.** `scripts/` **is**
on Pint's path (M28 proved it with a deliberate misformat probe), so bare `pint --test` must stay green
— **not** `pint --test app tests database`, which misses `scripts/` entirely. Six jobs green.

⚠️ **THE ONE I MOST EXPECT TO BE WRONG: that the PR run proves the concurrency fix.** It cannot. A green
PR run is exactly as green with the expression right or wrong, and the arm that matters only fires on
`main`. **So the fix gets three positive controls, stated before they are run:** (1) replaying
`--run=33132909007` — the run that produced today's baseline file — must now be **refused**, naming its
event and branch; (2) two rapid pushes to this PR branch must still leave the first run **cancelled**,
proving the expression evaluates `true` for `pull_request` rather than having disabled cancellation
everywhere; (3) after merge, the merge-commit run on `main` must reach **`success`**, and the close-out
push must produce **no run at all** — read as *correctly skipped*, never as *pending*.

### ✅ CONTROLS 1 AND 1b MEASURED — AND THE NEGATIVE CONTROL IS THE ONE THAT MATTERS

| Control | Run | Result |
|---|---|---|
| the run that stamped the LIVE baseline file | `33132909007` (`pull_request` on `m36-loop-verification-harness`) | **REFUSED, exit 1**, naming the branch |
| `M38`'s own PR run | `33171176804` (`pull_request` on `m38-decisions-d5-d6-d7`) | **REFUSED, exit 1** |
| **negative — a real push on `main`** | `33135990415` (`push` on `main`) | **ACCEPTED, exit 0**, stamping `push` on `main` |

⛔ **A GUARD THAT REFUSES EVERYTHING IS NOT A GUARD**, which is why the third row exists. Two
refusals prove only that something is rejecting; the acceptance proves it is rejecting *the right
thing*.

⚠️ **THE SECOND REFUSAL WAS FIRST MEASURED THROUGH A PIPE AND READ `EXIT=0`** — `head`'s status,
not the script's. **A pipe hides the exit status**, which this project has written down and which
still cost a re-run. Re-measured unpiped: **exit 1.**

✅ **PINT PROVEN NOT BLIND ON `scripts/`, WHICH IS THE ONLY GATE THIS DIFF CAN MOVE.** A bare
`pint --test` returned `{"tool":"pint","result":"passed"}` with **no file count at all** — exactly the
shape of *"`passed` is not evidence it scanned anything"*. A deliberately misformatted
`scripts/PintProbeM39.php` turned it **red, exit 1**, naming the file with twelve fixers; the probe was
then deleted and its absence verified. `scripts/` is deliberately not on PHPStan's path, so the probe
could not redden a second gate the way an `app/` probe would.

---

## RELEASED — `M38`, three decisions filed and a constitution corrected (merged as PR #228, `44e79a9`, 6/6 green)

**Every claimed file was edited, and the claim was extended once — as its own pushed commit, before
either newly-claimed line was opened.** `D5` moved to ANSWERED; `D6` and `D7` were filed OPEN; the
disclosure row was **moved** out of `docs/feature-backlog.md` rather than copied; two rows were filed
and not fixed. **Namespaces spent: NOTHING** — fourteenth consecutive Lane A increment.

### How the prediction fared — the gates held, and the claim's own figures did not

The claim predicted no gate could move, and none did: six jobs green with real step counts, and the
one it flagged as *"most likely to be wrong — that this PR's green tick means anything"* was correct
to flag. **Nothing in CI reads `decisions.md`.** The proof was the read-back, and it is recorded above.

⛔ **THE CLAIM'S OWN FIRST DRAFT WAS WRONG, AND IT WAS CORRECTED IN THE CLAIM RATHER THAN QUIETLY.** It
asserted that *"Lane A's hand-off figure `000109` is spent twice over"*. **It was not.**
`PROGRESS.md:1891` already read `next free ADR 0022` and `migration 2026_08_17_000111` — **both
correct.** That figure was inherited from a planning document describing a state `M37`'s close-out had
already fixed, and it reached a claim without being checked against the file. ⚠️ **A stale figure taken
from a document rather than from the tree is the exact defect this increment is about, and it got into
the claim that says so.**

### ⛔ The finding inverts the premise, and it is the transferable part

**The artefact rewritten from scratch every increment — the hand-off — was CORRECT. The artefact meant
to be stable — the constitution — was STALE.**

`PROGRESS.md:123` is **Standing Rule 7(g) itself**, the clause written to stop exactly this, and it
read *"NEXT FREE ADR NUMBER: `0021`"* from **`M15`** — the increment that shipped
`0021-respondent-scoped-device-outbox.md` — all the way to `M38`. It sat **directly above its own
sentence boasting that it had said `0020` for three increments after K1a spent it.** A rule that
records its own past failure in the same breath as repeating it is the strongest available argument
for deriving the number from `ls docs/adr/`.

⚠️ **Five historical `RELEASED` bullets naming `0021`/`000109` were deliberately LEFT ALONE.** Each
records what was true at its own increment; a dated record is not a stale forward claim, and editing
one would falsify the log. **Only the two live forward claims were changed** (`:123`, `:1889`).

### What the measurements found that the sources did not

| Figure | Source said | Measured |
|---|---|---|
| `D6` disclosure sites | row **6** · M37's census **"11+"** | **17 across 9 files** |
| `D7` required CI contexts | the proposing plan: **5** | **6** |
| Open `major` rows naming their filer | — | **1 of 12** |

⚠️ **M37's census reproduced the very under-counting it was built to detect** — that is worth more than
the corrected number.

### ⚠️ Three splice assertions fired on prose quoting the anchor it searched for

The tracker contains **verbatim examples of its own headings**: Standing Rule 7(e) at `:88` quotes the
hand-off marker, and a first-draft status bullet quoted the status heading. Substring counts went to 2
and 3 and the writes were refused. **Every assertion is now line-anchored — which is exactly why
`preflight.php` anchors its own, and why the coming tracker surgery must split by pre-measured line index rather
than search.** J4b1's 1,086-line deletion (2026-08-16, `f565ac9`) in miniature, caught by an assertion rather than by a reader.

### ⛔ Baselines deliberately NOT regenerated, and the reason is `M39`'s row

`docs/gate-baselines.md` still carries run `33132909007` — **verified `event=pull_request` on branch
`m36-loop-verification-harness`**, i.e. the file written to end stale numbers is itself stamped from a
PR-branch run. It is **not** regenerated here because the only fresh post-merge candidate is this
increment's own merge run, and **that run is being cancelled** by this close-out push. That is the
defect `M39` fixes, and `M38` is its last measured instance: on this increment alone, run
`33170390017` (the claim push) was already cancelled by the claim-extension push.


**Taken 2026-08-28.** Branch `m38-decisions-d5-d6-d7`, cut from `origin/main` at `4a1ebc8`, PR into
`main`. **Docs-only.** First increment of the operating-loop realignment; `M39` (CI truth) follows it.

⚠️ **NUMBERED `M38`, AND BOTH CLAIM FILES WERE RE-READ IN FULL AT WRITE TIME.** `lane-b.md` reads
**NO ACTIVE CLAIM** and its highest released is `M35`; Lane A's highest released is `M37` (PR #227).
The only `M38` string anywhere in either file is this file's own floor-announcement.

⛔ **`scripts/preflight.php` REPORTS "next free is M39", AND THAT IS WRONG — IT IS THE FIRST FINDING
OF THIS INCREMENT.** It scrapes the highest `M<n>` **literal** out of both claim files, so it reads a
FORECAST (*"`M38` is the next free number"*) as a SPEND. A number must be derived from what is
merged, never from prose that merely mentions a number — which is the same defect these decisions are
about, one level down. **Filed in `docs/feature-backlog.md`, not fixed here**: `scripts/` is Lane B's
column and this row is docs-only.

### Row

Not a `docs/feature-backlog.md` row. Taken from the approved realignment plan, whose diagnosis is that
three questions gate later increments and none of them is the lane's to answer. One of the three — the
named-client disclosure — **is** a backlog row (`docs/feature-backlog.md`, under *Documentation &
specs*) and is being **moved** to `decisions.md`, not copied.

### Evidence verified

Every figure measured first-hand against the merged tree, not carried from the plan:

- **`D6` — the corpus names a real third-party client.** The row cites **6** sites; M37's census said
  **"11+"**. **Measured: 17 occurrences of the two identifying names across 9 tracked files** —
  `docs/PRD.md`, `docs/architecture/technical-architecture.md`, `docs/adr/0001`, `0002`, `0003`,
  `docs/domain-glossary.md`, `docs/competitive-feature-parity-matrix.md`, `docs/feature-backlog.md`,
  `PROGRESS_ARCHIVE.md`. **The row understates itself by nearly three times — and so did the census
  that re-validated it.** Repo confirmed `visibility=PUBLIC`.
- **`D7` — branch protection is net-new.** `gh api repos/:owner/:repo/branches/main/protection` returns
  **404 `Branch not protected`**; `rulesets` returns **`[]`**. There is nothing to amend, only to create.
- **`D5` — the exit bar is not measurable today.** **12 open `major` rows**, which matches
  `docs/backlog-triage.md`'s independent count of 12.

### Remedy verdict

**None of the three offers a remedy to measure — they are questions, not defects**, which is precisely
what makes `decisions.md` their home rather than the backlog. The one testable claim in the vicinity is
the disclosure row's own deferral clause, *"the merge is the natural last moment"*, and it is **SPENT**:
that merge (PR #179) landed 2026-08-18, so the row's stated deadline expired ten days ago and nothing
acted on it. A deferral whose deadline passes silently is not a deferral.

### Files

`docs/claims/decisions.md` · `docs/feature-backlog.md` (the disclosure row struck with a pointer, plus
one new row for the preflight defect above) · `docs/claims/lane-a.md` · `PROGRESS.md` (Lane A's status
block and hand-off line only) · `PROGRESS_ARCHIVE.md` (one archive entry).

**Shared artefacts taken:** `docs/claims/decisions.md`, `docs/feature-backlog.md`, `PROGRESS.md`,
`PROGRESS_ARCHIVE.md`. **Paired files taken:** none. **Namespaces spent: NOTHING** — no ADR, no
migration, no `§D<n>`. **Fourteenth consecutive Lane A increment spending nothing.**

### ⛔ CLAIM EXTENDED — two lines in `PROGRESS.md` outside Lane A's own block

**Pushed as its own commit before either line was opened**, per Rule 7(g). Lane B holds **NO ACTIVE
CLAIM**, so there is no live boundary to negotiate.

⚠️ **AND THIS EXTENSION CORRECTS THIS CLAIM'S OWN FIRST DRAFT, WHICH WAS WRONG.** It asserted that
*"Lane A's hand-off figure `000109` is spent twice over"*. **It is not.** `PROGRESS.md:1891` already
reads `next free ADR 0022` and `migration 2026_08_17_000111` — **both correct**. That figure was
inherited from a planning document describing a state `M37`'s close-out had already fixed, and it was
carried into a claim without being checked against the file. **A stale figure taken from a document
rather than from the tree is precisely the defect this increment is about, and it got into the claim
that says so.**

⛔ **WHAT IS ACTUALLY WRONG INVERTS THE PREMISE, AND THAT IS THE FINDING.** The artefact that is
rewritten from scratch every increment — the hand-off — is **correct**. The artefact that is supposed
to be stable — the constitution — is **stale**:

| Site | Says | Truth |
|---|---|---|
| `PROGRESS.md:123` — **Standing Rule 7(g) itself** | `NEXT FREE ADR NUMBER: 0021` | `0022` |
| `PROGRESS.md:1889` — `## Next Session` | `Next free ADR is 0021` | `0022` |

`docs/adr/0021-respondent-scoped-device-outbox.md` **exists on `main`**, so both are spent. ⚠️ **Rule
7(g)'s line boasts, in its own next sentence, that it *"said `0020` for three increments after K1a
spent it"* — and it has now done exactly the same thing again.** A rule that records its own past
failure in the same breath as repeating it is the strongest available argument for deriving the number
from `ls docs/adr/` rather than from prose.

**Not touched:** `PROGRESS.md:235`, `:238`, `:239`, `:255` and `:465` also name `0021`/`000109`, and
they are **correct** — each sits inside a `RELEASED` bullet and records what was true at that
increment. A historical record is not a stale forward claim, and editing one would be falsifying the
log. Only the two live forward claims above are changed.

**Namespaces spent: still NOTHING.** No ADR, no migration, no `§D<n>`. Both figures derived from `ls`,
never from prose.

### Prediction

Docs-only, so **no gate can move**: Pest, Vitest, Storybook axe, E2E, PHPStan, all five host lint gates
and `openapi.json` are unmoved by construction, and Pint does not scan `docs/`. Six jobs green with
real step counts.

⚠️ **THE ONE I MOST EXPECT TO BE WRONG IS THAT THIS PR'S GREEN TICK MEANS ANYTHING.** It cannot —
nothing in CI reads `decisions.md`, and this is exactly the docs-only shape that let J4b1's 1,086-line deletion (2026-08-16, `f565ac9`)'s
`PROGRESS.md` deletion merge green. **The proof is the read-back, not the tick**, and the close-out
must report what it read rather than that CI passed.

⚠️ **AND THIS INCREMENT WILL ITSELF BE HIT BY THE DEFECT `M39` FIXES.** Its merge-commit run on `main`
will be cancelled by the close-out push, exactly as happened to M31, M34, M35 and M36. That is
expected, recorded here in advance so it is read as a prediction rather than an incident, and harmless
for a docs-only diff. **It is the last increment that will suffer it.**

---

## RELEASED — M37, a read-only re-validation of the open backlog (merged as PR #227)

**Every claimed file was edited and `docs/feature-backlog.md` was deliberately NOT**, as claimed — it is
a shared artefact both lanes claim and a fan-out must not contend for it. **Namespaces spent: NOTHING**
(ADR `0022` free, still Lane A's block-opener, thirteenth consecutive increment; migration
`2026_08_17_000111` free; no `§D<n>`).

### How the prediction fared — it was wrong, and the way it was wrong is the finding

The claim predicted **"a large minority of rows will be stale"**, reasoning from M20's three-of-four.
**Of 68 rows, 65 are LIVE.** One was already fixed and never struck through, one had been promoted to
`decisions.md`, one cannot be settled by reading.

**The backlog is accurate about *whether* defects exist and unreliable about *scope* and *remedy*:** 14
rows understate themselves, 4 overstate, **8 prescribe a fix that is wrong, incomplete or would fatal**,
and ~30 citations are false or moved. M36 added `Evidence verified` and `Remedy verdict` on the strength
of four rows; this puts it at eight and shows **the evidence half is the reliable one**.

⚠️ **The second prediction — that the agents would disagree about what "still live" means — did not
materialise.** The verdict vocabulary held across all six passes without amendment; the ambiguity landed
instead on *overstated* rows, which the brief had not anticipated as a category at all.

### ⛔ The finding the row did not contain

The filed row names `POST /user/confirm-password`, which needs a session. Verifying it found **eight
unthrottled Fortify routes, three of which need no session at all** — `POST /forgot-password`,
`POST /reset-password`, `POST /register`. Verified independently rather than taken on report, and **the
row's prescribed remedy does not work**: Fortify has no per-route middleware hook, which
`config/fortify.php` states three times.

### ⚠️ Two rows M36 filed the day before were already wrong, and one was mine

The Pint under-scan row says *"every hand-off"* — M36 fixed Lane A's in the same increment that filed it.
The `fb-lane-c` row's remedy is wrong: `git worktree remove` refuses on a worktree with modified tracked
files, and **that row itself reports the dirty file**. It also said 104 commits behind; it is now 120.
**A row can be stale in hours, and its author is not exempt.**

### On trusting a fan-out

**Six verdicts were re-checked by hand and all six held.** That, and not the agents' own confidence
ratings, is why the aggregate is actionable. ⛔ The brief forbade the **DATABASE**, not merely the files —
M34's "read-only" agent ran `artisan test` and its `migrate:fresh` dropped the schema under a live run.
---

## RELEASED — M36, the loop's traps made executable (merged as PR #226, `ca6f802`, 6/6 green)

**Every claimed file was edited, and two were claimed and then deliberately NOT edited.**
`docs/claims/lane-b.md` was never opened — one writer each is what makes a claim conflict structurally
impossible, so Lane B adopts `TEMPLATE.md` by deleting its own copy, and `TEMPLATE.md` says so rather
than leaving it to be noticed. `fb-lane-c` was left alone: it is another lane's checkout and this row
had no reason to touch it. Both are filed in `docs/feature-backlog.md` rather than only here.

**Namespaces spent: NOTHING.** ADR `0022` stays free and stays Lane A's block-opener — **twelfth
consecutive Lane A increment spending nothing.** Migration `2026_08_17_000111` free. No `§D<n>`.

### How the prediction fared

The claim predicted Pest, Vitest, axe and E2E could not move, PHPStan could not move, `openapi.json`
stayed byte-identical, bare Pint went 1414 → 1417, and the job list stayed at six. **All held.** The
one flagged as most likely to be wrong — *"all five lint gates still pass with a floor added"* — also
held, on the host. It did **not** hold in the container, which is the finding below rather than a miss.

### ⛔ The finding the row did not contain: the gates under-scan in the container and print "passed"

`RecursiveDirectoryIterator` descends the Windows bind mount only partially while `find` on the same
path sees every file. Measured on one tree, three ways:

| Gate | Host | Container | CI |
|---|---|---|---|
| `controller-gate` | **97** | 49 | **97** |
| `migration-lint` | **113** | 86 | **113** |
| `constraint-boundary-lint` | **113 / 121 / 0** | 86 / 81 / 0 | **113 / 121 / 0** |

`controller-gate` scanned **49 of 97** controllers and exited 0. **CI agrees with the host**, which is
what makes the host authoritative rather than merely different — and that is the measured mechanism
behind the standing *"lint gates on HOST"* note, which had carried no number until now. It also gave
the floors a **real** positive control instead of a synthetic one.

⚠️ **A floor does not fix it and was never going to.** It catches the controller case at 49 < 55; it
does not catch 86 > 65, because a floor high enough would trip on ordinary deletion. The remedy is
running the gates where CI runs them, which `preflight --with-gates` now asserts and explains.

### ⚠️ Four traps this increment hit WHILE BUILDING THE TOOL THAT PREVENTS THEM

Recorded because each is a case of the project's own written knowledge failing to transfer, which is
the whole argument for making it executable.

1. **`grep -c` exits 1 on zero matches** — the standing note says use `wc -l`; a watcher written this
   session used `grep -c` anyway and its break condition never fired.
2. **The tool layer collapses doubled backslashes** — the floor messages went in with literal newlines
   instead of `\n`. They printed correctly, so only Pint caught it. **Build an escape as `chr(92)`.**
   ✅ A **quoted** heredoc is otherwise safe: 60 backticks and every glyph survived one intact, measured.
3. **A line-count assertion caught an off-by-one and refused to write** — the `lane-a.md` splice
   asserted a delta of 16 where the truth was 17. That is J4b1's 1,086-line deletion (2026-08-16, `f565ac9`) prevented rather
   than described.
4. **`/tmp` is not visible to Windows PHP** — a probe read nothing and "proved" two regexes broken. The
   real cause was ANSI arriving as the literal two characters caret-bracket, with **zero ESC bytes** in
   a three-megabyte log.

### Baselines

`docs/gate-baselines.md` regenerated from run `33132909007`, which is the run that tested this merge.
⚠️ **It is a PR run rather than a post-merge `main` run** — stated because provenance is the point of
that file. Pint moved 1414 → 1417; nothing else moved.
---

## RELEASED — M32, the backfill fan-out asserted by job count alone (merged as PR #225)

**Two test files, no production change.** `tests/Feature/Gamification/BackfillCommandTest.php` (+67) and
`tests/Feature/Connectors/ConnectorTokenRefreshTest.php` (+30/-1).

✅ **CI 6/6 GREEN WITH REAL STEP COUNTS, PARSED INDIVIDUALLY, TWICE** — E2E **20** · Static analysis **19** ·
Contract **16** · Frontend **12** · Pest **11** · Design-system axe **11**. Not one `steps: []`.

⚠️ **TWO PEST NUMBERS, AND THE DIFFERENCE BETWEEN THEM IS THE OTHER LANE.** Run `33108016145`, on this branch
**before** Lane B's `M35` merged, is the clean measurement of *this* diff: **`4597 / 19,439`** against M31's
`4595 / 19,433` — **+2 tests and +6 assertions, reconciling counted both ways**: Gamification
**134 / 479 → 136 / 483** (+2/+4) and the connector file **38 → 40 assertions** (+0/+2). Run `33109949970`,
on the **merge with current `main`** and therefore the authority for what lands, reads **`4611 / 19,465`** —
the extra **+14 tests / +26 assertions are `M35`'s**, which merged mid-flight. **A gate number that moves more
than your own diff explains is the other lane, not a defect** — and the only way to tell them apart is to have
measured your own branch before the merge.
**Vitest `134 files / 2,293`** · **Storybook axe `42 / 303`** · **E2E `551 passed + 10 skipped` (17.3m),
NO flaky line** — all three unchanged, as a PHP-test-only diff requires. **PHPStan CI `[OK]`**; local
gates: `tests/Feature/Connectors` **246 / 932**, five host lint gates unchanged at
**97 · 113 · 31 · 113/121/0 · 180**, `openapi.json` untouched.

### THE ROW'S OWN PRESCRIBED FIX WAS WRONG, AND THIS TIME THAT WAS KNOWN BEFORE THE FIRST LINE

Three rows running now. M30's was wrong in both mechanisms, M31's in its probe, and M32's was already flagged
wrong in the hand-off. **The habit that closed this one is the whole content of the increment: measure the
mutation, and make the harness prove its own mutation landed.**

`Queue::assertPushed($class, $closure)` is an **at-least-one-match** predicate — read from the vendor source
for the version installed (Laravel 13.18.1, `QueueFake.php:130-134`: `pushed($job, $callback)->count() > 0`),
never from memory of the docs. One closure asking *"is this id one of the two?"* is satisfied by the **first
of two identical jobs**.

✅ **WHAT ACTUALLY WORKS, AND IT IS STRONGER THAN THE HAND-OFF'S CORRECTION TOO.** The hand-off prescribed
*one closure per expected id*. That works, but `Queue::pushed($job)` returns the job **objects**
(`:364-375`, `->pluck('job')`) and `Bus::dispatched()` the same (`BusFake.php:564-573`) — so the whole set
compares at once as sorted `[tenantId, afterAuditId, limit]` tuples. **One assertion, pinning the multiset in
both directions** (nothing missing, duplicated, extra, no ordering assumed), which subsumes N closures *and*
the count. The count assertions were **kept** regardless: a deleted loop was the one mutation they caught.

### MEASURED — every mutation printed the line it changed, and restored by saved bytes with a sha256 check

Local baseline `tests/Feature/Gamification` **134 / 479**; `BackfillCommandTest.php` alone **8 / 19**.

| Mutation | Before | After |
|---|---|---|
| hoist the loop variable — every child gets `$targets[0]` | **SURVIVED**, 8 passed / 19, exit 0 | CAUGHT |
| non-null `afterAuditId` on the fan-out (kills *every* membership award) | **SURVIVED** | CAUGHT |
| `--tenant` resolves to the wrong workspace | **SURVIVED** | CAUGHT |
| the same hoist in `RefreshConnectorTokensJob::sweep()` | **SURVIVED**, 9 passed / 38, exit 0 | CAUGHT |
| delete the loop entirely | CAUGHT | CAUGHT — **not weakened** |

⚠️ **TWO CONTROLS IN THIS INCREMENT WERE INVALID BEFORE THEY WERE FIXED, AND BOTH WOULD HAVE READ AS RESULTS.**
(a) The first `connectorhoist` mutation used `$this->activeTenants()->first()` — but `activeTenants()` returns
a **Generator** (`MaintenanceJob.php:123-129`), so it would have fataled. **A control that dies loudly proves
nothing about a silent defect.** (b) The "before" measurement of that mutation was first taken against
`git cat-file blob HEAD:…` — and `HEAD` had by then moved onto **this increment's own commit**, so it
re-measured the new test and reported it CAUGHT. Re-run against `0d0c590` it read **9 passed / 38, exit 0 —
SURVIVED**. ⛔ **`HEAD` IS NOT A SYNONYM FOR "BEFORE MY CHANGE" — NAME THE COMMIT.**

### WHAT THE ROW GOT WRONG, VERIFIED FIRST-HAND RATHER THAN FROM THE CENSUS

⛔ **"Six sibling fan-outs were checked individually and are NOT defects" is wrong as a coverage claim** — it
is true of the *production code*, which is correct at all six sites, and false of the *tests*. Two of the six
(gamification, connector) assert a bare integer count under a fake. The other four assert real per-tenant
effects on a real `database` queue — the stronger idiom — but **every fixture holds exactly one active
tenant**, so a hoist is a no-op mutation there. Their helper comments say it out loud: *"acme is the only
active tenant."* Filed as its own row, with the note that **adding a second tenant alone produces a new green
test**, because each drain is hard-coded to exactly two `workOneJob()` calls.

⚠️ **The second instance was the sharper of the two and the row never named it.**
`ConnectorTokenRefreshTest.php:188` is the **only place in the repository where `RefreshConnectorTokensJob`'s
loop runs at all** (`runRefreshSweep()` at `:74-89` dispatches the *child*), it has no `--sync` sibling
proving a usable id reaches the child, and its failure **recurs hourly** rather than once. The second half of
that test's own name — *"holds no tenant context itself"* — had also never been asserted; it is now.

➕ **A silent mutation class the census missed:** a well-formed uuid that is **no tenant at all**.
`TenantAwareJob`'s guard is shape-only, so the job finds no row and **deletes itself** with an `info` log —
silent in production *and* in the suite, with **zero** workspaces backfilled. Set-equality catches it.

### FILED, NOT FIXED — both user decisions of record (2026-08-28), do not re-ask

1. The four single-tenant sibling fan-outs (`major`).
2. `--sync` returning FAILURE after `DB::commit()` has already made every award durable (`minor`).

**Namespaces spent: none** — no ADR, no migration, no `§D`. Twelfth consecutive increment.

---

## RELEASED — M31, the concurrency guard that was pinned from one side only (merged as PR #223, `5419ddf`, CI 6/6 green with real step counts)

**Taken 2026-08-27.** Branch `m31-answer-edit-checksum`, cut from `origin/main` at `659c6ca`, PR into `main`.
Row: the `major` under **Test suite & CI gates** in `docs/feature-backlog.md`.

### The row was right in its headline, wrong in its probe, and incomplete about the damage

**Right:** `SubmissionAnswerFactory` stamps no `answers_content_checksum`, so `seedInboxSubmission()`
produces the *legacy* row, `baselineOf()` casts null to `''`, `ConvertEmptyStringsToNull` turns it back to
null, and all three accepted writes reach `SubmissionAnswerEditService.php:135` with null on both sides.

⛔ **WRONG:** *"Drop the client token from the guard and the suite stays fully green."* **Deleting the guard
reddens three cases** — editor B re-reads the answer row at `:114`, so the under-lock check at `:202`
compares a value against itself, B's write is accepted, and the refusal cases fail. M30 sharpened this in
advance and it held: **the suite was never blind to REMOVING the guard, only to WEAKENING it.** A test
written to the row's stated probe would have been aimed at a mutation the suite already catches — **the
second consecutive increment where that was true**, after M30's `throttle:login` rename.

⚠️ **INCOMPLETE:** the row names one failure direction. **Two survived, and they are opposites.**

| Mutation | Before | After | Damage if shipped |
|---|---|---|---|
| **(1)** `$baseline === null && $stored->... !== null` — presence-only | **60 passed, GREEN** | **2 failed** | Two editors each holding a real, *different* token never conflict. The lost update the guard exists to prevent is silently live again. |
| **(2)** `$baseline !== null \|\| $stored->... !== $baseline` — reject every non-null | **60 passed, GREEN** | **4 failed** | Every submission written by the real pipeline is **permanently uneditable**. |
| **(3)** `answers_schema_checksum` for `answers_content_checksum` — adjacent-column typo | **2 failed** | 2 failed | *Already caught.* |

**(3) IS WHY THE NULL-VS-NULL EQUALITY IS MORE PROTECTIVE THAN IT LOOKS, AND WHY THE FIRST TWO CANDIDATE
MUTATIONS WERE DISCARDED.** Because `null === null` is trivially *true*, any mutation that breaks the
equality outright throws on the accepted writes and reddens them. **The only mutants that survive are the
ones that preserve `(null, null)` equality and `(real, null)` inequality** — i.e. those that discriminate on
*nullness alone*. That is the precise statement of the gap, and it is narrower and sharper than *"the suite
compares null to null"*: **the suite pinned exactly one bit, and the guard has two.**

### What was added, and where it deliberately was not added

**Four cases, two per file**, all going through the real submit pipeline so the rows carry production-shaped
checksums — `SubmissionAnswerEditTest`'s own `submitForEdit()` helper, which the row correctly noted **no
concurrency case used**, and a sibling in the routes file.

⛔ **NOT FIXED IN `SubmissionAnswerFactory`, AND THAT WAS THE OPEN QUESTION THE CLAIM REFUSED TO PREJUDGE.**
Stamping a checksum there would **convert** the legacy rows rather than **add** the production ones. Three
reasons, in increasing order of importance: it changes the fixture shape under every caller of
`seedInboxSubmission()` in **both** lanes' suites; it deletes the only coverage the nullable path has; and
**that path is a supported production state, not an artefact** — `EditSubmissionAnswersRequest` uses
`present` + `nullable` *on purpose*, and says so in its own words, so that submissions written before the
pipeline stamped a checksum do not become permanently uneditable. **The obvious "fix the fixture" move would
have destroyed real coverage to buy new coverage.** The old cases stay and still pass.

### ⛔ THE NON-VACUITY GUARD IS THE ASSERTION THAT MAKES THE WORD "REAL" TRUE, AND IT WAS PROVEN, NOT ASSUMED

Every new case asserts its baseline is a non-empty string **before** using it. Without that, each one
silently degrades back into a fourth `null === null` the moment the pipeline stops stamping — passing while
measuring nothing, which is this project's most-repeated failure mode.

**Control D proves it fires:** nulling `SubmissionFinalizer.php:96`'s stamp fails **all four** cases **at
that assertion**, printing *"Failed asserting that null is of type string"* and *"Expecting '' not to be
''"* against the exact line. ⚠️ **THE LOG WAS READ, NOT THE EXIT CODE** — M30's standing lesson that a
control which only checks the exit status is half a control.

⛔⛔ **AND CONTROL D FAILED SILENTLY ON ITS FIRST RUN, WHICH IS THE TRANSFERABLE FINDING OF THIS INCREMENT.**
The mutation was applied with `perl -0pi -e "s/...\$contentChecksum,/...null,/"` inside **double** quotes;
the shell ate the escaping, **the substitution never applied**, and the suite came back **`64 passed`** —
a result indistinguishable from *"the guard is decorative and the control disproves it."* It was caught only
because the command **printed the mutated line** and the line was unchanged. ⚠️ **A CONTROL MUST PROVE ITS
OWN MUTATION LANDED BEFORE ITS RESULT MEANS ANYTHING** — a green positive control is either a real
disproof or a no-op edit, and nothing in the test output distinguishes them. Every mutation in this
increment therefore greps the mutated line and aborts if it does not match. **This is `THE SHELL EATS
BACKTICKS` widened: it also eats `\$` inside double quotes, and a silently-unapplied `sed`/`perl` mutation
is a false negative that flatters the code.**

### The file record

Amended: `tests/Feature/Submissions/SubmissionAnswerEditTest.php` ·
`tests/Feature/Submissions/SubmissionEditRoutesTest.php` · `docs/feature-backlog.md` ·
`docs/claims/lane-a.md` · `PROGRESS.md`. **No new files.** `database/factories/SubmissionAnswerFactory.php`
was claimed as *"only if"* and, as the claim predicted it might, **was not touched** — the M8/M11/M14/M30
shape recurring by design for a fifth time.

**Read-only, never edited:** `app/Services/Submissions/SubmissionAnswerEditService.php`,
`app/Services/Submissions/SubmissionFinalizer.php`. Both were mutated **in the working tree** for controls
and restored by **sha256 byte comparison** after every run, per M9 — `git checkout --` is not a mutation
restore. Every restore verified `OK`.

### ➕ ONE FINDING FILED, NOT FIXED

`baselineOf()` in `SubmissionEditRoutesTest.php` returns `(string) $value`, which coerces a null checksum to
`''` — and only `ConvertEmptyStringsToNull` turns it back into null before the service compares it. **The
round trip happens to be correct, and it is correct by coincidence of two unrelated behaviours.** Left as-is
because changing the helper's return type touches every case in the file and the new cases assert the real
path directly; **named here so the next reader does not have to rediscover that the `''` is a cast artefact
rather than a value anybody chose.**

### THE QUEUE BEHIND IT — `M32` — RELEASED, AND ITS SCOPING NOTE IS SPENT

**`M32` merged as PR #225 (2026-08-28); see its own `## RELEASED` section above.** The note that stood
here was right that the row was real and that its prescribed fix did not work, and **wrong in one claim a
reader would have acted on**: it recorded the six sibling maintenance fan-outs as *"checked individually
and not defects"*. That holds for their production code and fails for their coverage — four of them are
blind to a hoisted loop variable because each fixture carries exactly one active tenant, which no amount
of reading the dispatch line reveals. It is now its own backlog row.
⛔ **The lane pre-claims no forward number: Rule 7(f) governs from here.**

---

## RELEASED — M30, the guest limiter that keys on a parameter one of its five routes does not have (merged as PR #220, `5e58c05`, 6/6 green)

**CI 6/6 with real step counts, parsed individually rather than trusted from a tick:** E2E **20** · Pest
**11** · Static analysis **19** · Contract **16** · Design-system axe **11** · Frontend **12**. Not one
`steps: []`.

⚠️ **THE FIRST CI RUN WAS A ZOMBIE AND WAITING ON IT WOULD HAVE COST THE SESSION.** Run `32985770877` sat
**queued for 8h53m and never started a single job**, while runs *submitted later* on other branches
completed normally — so it was not a busy queue, it was an orphaned run. Lane B recorded the same outage
shape on M29 (three runs failed or sat queued 30–57 minutes with empty job lists). ⛔ **THE TELL THAT
SEPARATES "BUSY" FROM "DEAD" IS A LATER RUN FINISHING FIRST**, and it costs one `gh run list`. The fix was
to merge current `main` into the branch and push — which restarted CI on a fresh run *and* picked up M29 and
M33 — rather than to keep waiting. **Merging `main` in was also what avoided a force-push:** rebasing a
pushed branch would have needed one, and this repository's standing instruction is never to force.

### What was actually wrong

**The row was a coverage gap; the sweep found a live defect one door over.** `RateLimiter::for('guest')`
keyed its per-token `Limit` on `'gtok:'.hash('sha256', (string) $request->route('shareToken'))`. **MEASURED
from the live route table** — matched on the resolved `ThrottleRequests` class rather than the printed
alias, M13's lesson reused — five routes carry `throttle:guest`, four declare `{shareToken}`, and
`GET /api/v1/public/drafts/{resumeToken}` does not. A parameter a route does not declare reads back null,
`(string) null` is `''`, `hash('sha256', '')` is a **constant**, and every draft-resume request in the
deployment across every tenant shared **one** bucket at 30/min.

⚠️ **THE MIDDLEWARE ORDER IS WHAT MADE IT REACHABLE RATHER THAN THEORETICAL, AND IT WAS MEASURED.** On that
route the throttle is index **0**, ahead of `EstablishGuestDraftContext`, `SubstituteBindings` and
`RequireFeature:save_and_resume` — so a **garbage** token spent the shared budget before the token was
verified, before tenancy existed, and before the paid-feature gate. The per-IP arm allows 60/min, so one
unauthenticated IP could spend the 31 requests needed to 429 **every legitimate resume link platform-wide**,
indefinitely and for free. `EnforceGuestFormRateLimit` is not on that route.

⛔ **NEITHER CHANGE THAT CAUSED IT WAS WRONG ON ITS OWN, AND THAT IS THE TRANSFERABLE PART.** F5's comment
said *"Keyed on the raw {shareToken} string"* and was accurate when every route on the limiter carried that
segment. H9b's resume group then reused `throttle:guest` **correctly** — it is the same gate as the
draft-save channel — and reused a key that had silently stopped applying. **A limiter's key is a contract
with the route's parameter NAMES, and nothing in this repository expressed it.** ⚠️ **Generalised: any
middleware alias that reads `$request->route('<literal>')` is coupled to every route that will ever carry
it, and that coupling is invisible at both ends.**

### The row's headline held and BOTH of its mechanisms failed — the first time a row has been wrong in the SAFE direction

Corrected against the vendor source for the version actually installed, not argued.

- **(a) "unmetered credential stuffing" is a DEGRADATION.** Nulling `fortify.limiters.login` drops
  `throttle:login`, but vendor `AuthenticatedSessionController.php:86` re-inserts `EnsureLoginIsNotThrottled`
  into the default pipeline on exactly that condition, and this app sets no `pipelines` key and never calls
  `Fortify::authenticateThrough`, so the branch **is** reached — 5 *failed* attempts/min on
  `lower(email)|ip`, byte-for-byte the key `FortifyServiceProvider.php:160` builds.
- **(b) THE RENAME MUTATION IS ALREADY COVERED, LOUDLY.** On `laravel/framework` v13.18.1
  `ThrottleRequests::resolveMaxAttempts()` throws `MissingRateLimiterException` for an unregistered name, so
  a rename 500s every login POST and reddens `AuthenticationTest.php:48` and `TwoFactorChallengeTest.php:174`
  today. **The row inherited its "resolves to an UNLIMITED PASSTHROUGH" premise from
  `SsoLoginWebTest.php:286`, which was true on Laravel ≤ 9 and is false here.** ⛔ **A test written to the
  row's stated rationale would have been aimed at a mutation the suite already catches — READ THE VENDOR
  SOURCE FOR THE VERSION INSTALLED; A PROJECT'S OWN COMMENT IS NOT A CITATION.**
- **(c) What survives is the severe half.** `throttle:two-factor` is the **only** bound on guessing a
  6-digit TOTP or a recovery code: the vendor controller counts nothing, `TwoFactorLoginRequest` counts
  nothing, and `app/` registers no `Lockout` or `TwoFactorAuthenticationFailed` listener.

### The gate asks the question instead of checking a list

`tests/Feature/Auth/RateLimiterBindingTest.php` **invokes** the limiter for every live route bound to it,
with two different token values, and asserts the two bucket keys differ. Holding its own copy of the
parameter names would have reproduced 7(b-bis)'s paired-list hazard one file later; as written **a sixth
route with a third parameter name reddens it without the test knowing the name.** The production fallback
keys on the IP so a future mismatch is per-caller rather than deployment-wide — **a floor, not the design** —
and a second case fails if any live route ever reaches it, because the set comparison cannot see two
IP-keyed arms differing from nothing.

### ⛔ FOUR POSITIVE CONTROLS, EACH RESTORED BY sha256 BYTE COMPARISON — AND ONE FOUND A VACUOUS TEST A GREEN RUN WOULD HAVE SHIPPED

| Control | Result |
|---|---|
| **C1** drop the `resumeToken` arm | key case reddens, **naming `api/v1/public/drafts/{resumeToken}`** |
| **C2** null `fortify.limiters.two-factor` | binding case reddens; **registration case stays GREEN**, proving the two measure different facts |
| **C3** mistyped limiter name in the route walk | non-vacuity guard fires with its own message |
| **C4** pre-M30 key vs the behavioural case | fails at exactly the third request, and **only** that case in a 31-case file |

⛔⛔ **C1 IS WHY THE IP-FALLBACK CASE EXISTS IN WORKING FORM. Its first draft was
`->not->toContain($key, $message)` and stayed GREEN with the offending key sitting in the array, because
PEST'S `toContain` TAKES VARIADIC NEEDLES, NOT A NEEDLE AND A MESSAGE** — the explanatory sentence was being
asserted as a second thing the array should not contain. The probe that caught it dumped the actual keys;
the exit code alone said nothing. **Third increment running where the control was worth more than the fix,
and a second instance of the standing rule that a control which only checks the exit code is half a
control.** ⚠️ **Check the SIGNATURE of any expectation you pass a message to** — `toBe`, `toBeFalse` and
`toBeGreaterThanOrEqual` take one; `toContain` does not.

⚠️ **THE BEHAVIOURAL CASE'S DISCRIMINATING ASSERTION IS THE THIRD REQUEST, NOT THE 429.** A case that
exhausts one token and asserts 429 passes on the **broken** code too — the global bucket 429s more eagerly,
not less. Only *"a second, unrelated resume token is still served"* separates the two.

**THE FILE RECORD.** Amended: `app/Providers/AppServiceProvider.php` · `tests/Feature/Guest/GuestDraftRuntimeTest.php`
· `docs/security-threat-model.md` (§4, one row) · `docs/feature-backlog.md` · `docs/claims/lane-a.md` ·
`PROGRESS.md`. New: `tests/Feature/Auth/RateLimiterBindingTest.php`. **Every claimed file was edited except
`openapi.json`**, which was claimed because it might move and predicted in writing not to — the M8/M11/M14
shape recurring by design, and the prediction held.

### ➕ THREE FINDINGS FILED, NOT FIXED, AT THE MOMENT THE DECISION WAS TAKEN

All three are in `docs/feature-backlog.md` under `### Test suite & CI gates`, per the J4b1 rule that a
deliberately-unfixed finding living only in a transcript is invisible to every later backlog search.

1. **`POST /user/confirm-password` carries no rate limit at all** — `major`, **LIVE**. Unlimited online
   password guessing against an authenticated session, on the redemption door for `RequireRecentPassword`,
   which `StepUpReauthenticationTest.php:135-147` pins over seven member/admin routes. **The asymmetry is
   the argument:** SAML step-up and `POST /two-factor-challenge` are both bounded; the password step-up
   path, verifying the same credential to unlock the same actions, is not. `routesThrottledBy()` in the new
   test file is the helper it wants. **The strongest single row M30 leaves behind.**
2. **`throttle:saml-acs`'s route BINDING is unasserted** while its registration is — an asymmetry inside the
   very test family the closed row held up as its model.
3. **The *"UNLIMITED PASSTHROUGH"* rationale is false on this framework version**, in two SSO test files.

(2) and (3) were left because `tests/Feature/Sso/` is Lane B's most active subsystem and M30 already crossed
the lane boundary once.

### THE QUEUE STILL HELD — `M31` AND `M32`, VERIFIED THIS SESSION AND NEEDING NO SECOND PASS

- **`M31`** — *every accepted write in the answer-edit concurrency suite compares `null === null`*.
  **REAL, and the row is precise.** Every hop traced: `SubmissionAnswerFactory` stamps no
  `answers_content_checksum`, `baselineOf()` casts to `''`, `ConvertEmptyStringsToNull` turns it back to
  `null`, and all **three** accepted writes reach `SubmissionAnswerEditService.php:135` with null on both
  sides. ⚠️ **ONE SHARPENING THE ROW DOES NOT HAVE: DELETING the guard outright IS caught** — editor B
  re-reads `$stored` at `:114`, so the under-lock check at `:202` compares a value against itself and B's
  write is accepted, reddening three cases. The suite is blind to **weakening** the comparison, not to
  removing it, **so the test owed is a real-checksum comparison, not another deletion probe.** The file's
  own `submitForEdit()` helper already produces production-shaped rows and no concurrency case uses it.
  Files: `tests/Feature/Submissions/SubmissionAnswerEditTest.php`,
  `tests/Feature/Submissions/SubmissionEditRoutesTest.php`, possibly
  `database/factories/SubmissionAnswerFactory.php`.
- **`M32`** — *the queued half of `gamification:backfill` is asserted by job count alone*.
  **REAL, and the row's own prescribed fix does not work.** `Queue::assertPushed($class, $closure)` is
  *"at least one match"*, so the literal reading — one closure asserting the id is one of the two — stays
  **green** under the silent mutation. It needs one closure **per expected id**, with the existing count
  assertion **KEPT**, because a deleted loop is the one mutation the test catches today. ⚠️ **A second
  instance the row does not name:** `tests/Feature/Connectors/ConnectorTokenRefreshTest.php:188-196` fakes
  the child and asserts `Bus::assertDispatchedTimes(…, 2)` with no payload — the identical hole. Six sibling
  maintenance fan-outs were checked **individually** and are **not** defects (they drain the child and
  assert a DB effect), which is M20's *a character-identical declaration is not an identical defect* holding
  for a third increment. Local baseline `tests/Feature/Gamification` **134 / 479**.
---

---

⚠️ **THE HOST LINT QUARTET IS A QUINTET FROM M28 ONWARD — `97 · 113 · 31 · 113/121/0 · 180`.**
The fifth is `component-import-lint` (180 SFCs). A hand-off quoting four numbers is quoting a
pre-M28 baseline.

---

## RELEASED — M28, the component-import gate (merged as PR #218, `d0a5452`, 6/6 green)

**Taken 2026-08-26.** Branch `m28-component-import-gate`, cut from `origin/main` at `4c97c8b`, PR into
`main`. Row: the `minor` under **Test suite & CI gates** in `docs/feature-backlog.md` — *no gate in this
repository detects a component used in a template but never imported*.

**⚠️ NUMBERED `M28`, AND `lane-b.md` WAS RE-READ IMMEDIATELY BEFORE THIS FILE WAS WRITTEN.** It reads
**NO ACTIVE CLAIM**; `M24` and `M26` are merged and released. `M25` and `M27` are mine and both merged
(PRs #216, #217). So `M28` is the next free number.

### ⛔ THIS CLAIM CROSSES THE LANE BOUNDARY ON PURPOSE, AND THAT IS WHY IT RAN LAST

Two of the files below are **not Lane A's**, which is exactly why the row was scheduled last rather than
taken opportunistically:

- **`scripts/` is LANE B's column** under Standing Rule 7(b).
- **`.github/workflows/ci.yml` is in the "NEITHER — claim in this file FIRST" column.**

**Lane B holds nothing at this moment**, verified in the same read that fixed the increment number, so
this is the cheapest possible window for it. **This claim is the permission**; it is pushed before either
file is opened. ⚠️ **The row itself flagged this** — *"it lands in `scripts/` and moves a gate baseline,
which is a tooling row rather than the page row that found it"* — and it was right.

### ⛔ NAMESPACES — THIS CLAIM SPENDS NOTHING

**No ADR.** `0022` stays free and stays Lane A's block-opener (`0022-0025`) — **eighth consecutive Lane A
increment to spend nothing**. Adding a lint gate that enforces an existing, unwritten invariant is not a
decision; nothing was weighed and rejected. **No migration** (`2026_08_17_000111` still free). **No
ADR-0016 `§D<n>`**, no threat-model row. `0010` reserved for H1d; `#16` free.

### Every file this claim touches, named before it is opened

- `scripts/component-import-lint.php` — **NEW.** Lane B's column, claimed above. Named to match the four
  siblings (`constraint-boundary-lint`, `controller-gate`, `job-payload-lint`, `migration-lint`).
- `composer.json` — a `component-import-lint` script plus an entry in the `quality` aggregate. In neither
  lane's column; claimed here.
- `.github/workflows/ci.yml` — **one step** in the *Static analysis, style & security* job. Claimed above.
- `docs/feature-backlog.md` (this row only), `docs/claims/lane-a.md`, `PROGRESS.md` (Lane A's block and
  hand-off line only).

**If this list grows, the claim is extended as its own pushed commit before the file is opened.**

⛔ **THE `ci.yml` STEP IS NOT OPTIONAL AND `ci.yml:73-76` SAYS SO IN ITS OWN WORDS**: *"no CI job runs
`composer run quality` — the four steps above invoke the scripts individually, so a composer.json-only
registration would gate nothing."* A gate registered only in `composer.json` is a gate nobody runs, which
is this project's most-repeated lesson. Both registrations, or neither.

### ✅ ALREADY MEASURED BEFORE CLAIMING — THE ROW GIVES NO FALSE-POSITIVE ESTIMATE AND THAT IS THE RISK

A gate that fires on two hundred files is not a gate anybody will run, so the rule was **prototyped
read-only against the tree before this claim was written**:

- **180 `.vue` files** across `resources/js`, `resources/public-runtime` and `packages/design-system/src`.
- **All 180 are `<script setup>`** — no Options-API tail to special-case. The rule needs exactly one shape.
- Naive rule (imports + local declarations + a Vue/Inertia allow-list): **2 files flagged**.

**Both flags are real, and they are two DIFFERENT bugs in the naive rule** — which is the whole value of
prototyping first:

1. **`packages/design-system/src/components/Badge/Badge.vue:40` — a tag inside an HTML comment.** The
   template carries a long `<!-- … -->` that *quotes markup*: `` `<Badge :variant :icon :label />` ``.
   ⚠️ **This is the `NAME THE THING, NEVER QUOTE IT` lesson arriving from a new direction** — a comment
   that quotes the construct it discusses booby-traps the tool that reads it. **The rule must strip
   `<!-- … -->` before extracting tags.**
2. **`resources/js/components/builder/ConditionRows.vue:85` — a genuine RECURSIVE self-reference.**
   `<ConditionRows v-if="child.kind === 'group'">` inside its own template; Vue resolves a `<script
   setup>` SFC's own filename with no import. Correct code. **The rule must allow tag === basename.**

**With those two exemptions plus the globals allow-list, the baseline is ZERO** — so the gate ships
merge-blocking on day one with **no `KNOWN_*` quarantine list**, which is the shape M19 spent an entire
increment draining.

### ⛔ THE GATE MUST FAIL ON AN EMPTY SCAN, AND ITS OWN NEIGHBOURS DO NOT

`docs/feature-backlog.md` carries a live `minor` immediately below this row: *"Neither structural lint
gate fails on an empty scan"* — `scripts/constraint-boundary-lint.php:296-304` and
`scripts/migration-lint.php:140` print the file count and `exit(0)` regardless, so a discovery regression
is indistinguishable from a clean run. **This gate will assert a non-zero file count and fail if it finds
none**, because writing a fifth instance of a defect filed one bullet away would be indefensible.
⚠️ **That sibling row is NOT being closed here** — fixing the two existing scripts is its own change to
Lane B's files with its own baseline movement, and it is not this row.

### The positive control, stated before it is run

Per the standing rule, a gate is not proven by a green CI run. **Delete the `MdsBanner` import from
`resources/js/Pages/invitations/Show.vue`** — M9's actual historical instance, the defect that motivated
this row — and the gate must go **red naming that file**. `vue-tsc --noEmit` and `vite build` both exit 0
in that state (M9 measured it), which is precisely why this gate is owed. Restore by **saved-bytes
sha256 comparison**, never `git checkout --`.

### Blast radius, stated rather than discovered

⚠️ **THE HOST LINT-GATE QUARTET BECOMES A QUINTET.** The standing baseline `97 · 113 · 31 · 113/121/0` is
quoted in every hand-off; a fifth number joins it and the hand-off line must say so rather than leaving
the next reader to reconcile four against five.

**PHPStan does not cover `scripts/`** (`phpstan.neon` paths are `app`, `database`, `routes`) and **Pint's
Laravel preset does not list it either** — ⚠️ both to be **verified by running them**, not trusted, on
M9's lesson that *Pint's `passed` is not evidence it scanned anything*. No `.vue`, no `resources/`, no
test file, no route: **Vitest, Storybook axe, E2E, Pest and `openapi.json` are unmoved by construction.**

### ✅ WHAT THE GATES MEASURED, AND THE ONE THING WORTH CHECKING TWICE

**6/6 green with real steps counts** — and **Static analysis went 18 → 19 steps**, which is the new gate
itself. ⛔ **THAT DELTA IS THE MEASUREMENT THAT MATTERS, NOT THE GREEN TICK.** A gate registered but not
running is this project's most-repeated failure, so the step was read out of the CI log by name:
`Component import linter … success`, step 11, printing **`Component-import linter passed (180 SFC(s)
scanned)`**. The same 180 as locally, so CI's checkout and the host agree on discovery — which is itself
worth knowing, because container file discovery has silently under-counted here before.

**Five host linters — `97 · 113 · 31 · 113/121/0 · 180`**, the four prior figures re-measured and unmoved.
PHPStan **18**, this file not among them. **Pint scans `scripts/`**, proven by a deliberate misformat
probe rather than inferred from its `passed` line. E2E, Vitest, Storybook axe, Pest and `openapi.json`
unmoved by construction.

⚠️ **THE R2 CONTROL IS THE TRANSFERABLE PART OF THIS INCREMENT.** It was run as a genuine question rather
than a formality, and it found a defect in the gate: the first version handed *"add the import"* advice to
a **missing scan root**, whose remedy is entirely different. **A gate whose failure message points at the
wrong fix costs more than the bug it caught** — and nothing but running the control in its own right would
have surfaced it, because the exit code was already correct. A control that only checks the exit code is
half a control.

---

## RELEASED — M27, the README can bootstrap a fresh clone (merged as PR #217, `4c97c8b`, 6/6 green)

**Taken 2026-08-26.** Branch `m27-fresh-clone-bootstrap`, cut from `origin/main` at `6c5494e`, PR into
`main`. Row: the first `major` under **Documentation & specs** in `docs/feature-backlog.md` — *`npm run
build` cannot bootstrap a fresh clone or worktree, and the README is the only document that does not say
so*.

**⚠️ NUMBERED `M27`, AND `lane-b.md` WAS RE-READ IMMEDIATELY BEFORE THIS FILE WAS WRITTEN.** It reads
**NO ACTIVE CLAIM** — `M24` and `M26` are both merged and released — so `M27` is the next free number.
**Lane B holds nothing, so there is no live boundary to negotiate**, and this claim touches no PHP at all.

### ⛔ NAMESPACES — THIS CLAIM SPENDS NOTHING

**No ADR** — `0022` stays free and stays Lane A's block-opener (`0022-0025`), **seventh consecutive Lane A
increment to spend nothing**. A README correcting itself to match `ci.yml` is not a decision; the decision
(four ordered steps) was taken when `ci.yml` was written. **No migration** (`2026_08_17_000111` still
free). **No ADR-0016 `§D<n>`**, no threat-model row. `0010` reserved for H1d; `#16` free.

### Every file this claim touches, named before it is opened

- `README.md` — **the only file that needs fixing**, and that is the row's own finding: every other
  document already states the sequence correctly. Three sites, not one (see below).

Shared, claimed here: `docs/feature-backlog.md` (this row only), `docs/claims/lane-a.md`, `PROGRESS.md`
(Lane A's block and hand-off line only). **If this list grows, the claim is extended as its own pushed
commit before the file is opened.**

⚠️ **`docs/deployment-infrastructure.md` IS DELIBERATELY NOT IN THAT LIST.** The row cites `:39` as
documenting the true sequence and it does, correctly — *"`npm ci` + design-system deps + `npm run
ds:tokens` + `npm run build`"*. Nothing there needs changing, and opening it would be scope the row does
not ask for.

**No code, no test, no `.vue`, no PHP.** Every gate is unmoved **by construction**: Pest, Vitest,
Storybook axe, PHPStan, the four host lint gates, `openapi.json` and the E2E figure. **This is a
documentation-only PR and will be green by construction — which is exactly the shape that let the
1,086-line PROGRESS.md deletion merge in M16.** So the proof here is not CI; it is the worktree
reproduction below, which was done BEFORE this claim was written.

### ✅ ALREADY PROVEN, IN A THROWAWAY WORKTREE, BEFORE CLAIMING

Done during M25's e2e wait, exactly as the row demands (*"PROVE IT IN A THROWAWAY `git worktree`, NOT BY
MOVING `dist/` ASIDE"*). `git worktree add --detach <scratch>/fresh origin/main` at `a457b7d`;
`packages/design-system/dist` confirmed **absent** by `ls`, not inferred. Run with the main tree's root
`node_modules` bind-mounted so the toolchain is real and only the *repository* content is fresh.

**Step 3 without step 2 — `vite build`, exit 1:**

    Unable to resolve `@import "@meridian/design-system/tokens.css"` from /fresh/resources/css
    Unable to resolve `@import "@meridian/design-system/tokens.css"` from /fresh/resources/public-runtime
    ✗ Build failed in 11.18s

**Step 2 without step 1 — `ds:tokens`, exit 1:** `Cannot find package 'style-dictionary' imported from
/fresh/packages/design-system/build-tokens.mjs`. So **all three steps are load-bearing and each has a
measured failure**, rather than the row's assertion that the sequence is merely "documented elsewhere".

⚠️ **TWO MECHANICS WORTH KEEPING:** `-w` is mangled by MSYS (`MSYS_NO_PATHCONV=1` is required, the same
shape as the `docker exec -w` note in Lane B's hand-off), and vite writes `node_modules/.vite-temp`, so a
`:ro` mount fails with `EROFS` **before reaching the real error** — a read-only mount looks like a
different bug entirely.

### ⚠️ THE ROW IS RIGHT AND UNDERSTATES ITSELF THREE TIMES

**(1) TWO ENTRY POINTS FAIL, NOT ONE.** The row names only `resources/css/app.css:11`. The guest runtime's
`resources/public-runtime/public-runtime.css:4` imports the same artifact, and `vite.config.ts:26` lists
both as build inputs — the error output names both directories. ⚠️ **`resources/public-runtime/` is LANE
B's column, and this claim does not touch it**: it is evidence, not a target, and the fix is in `README.md`.

**(2) THE README HAS THREE SITES, NOT ONE.** The row cites `README.md:51-59`; the actual command is `:63`
(`:51` is the `## Everyday commands` heading). And the frontend block lists **`build` at `:63` BEFORE
`ds:tokens` at `:67`**, under a comment calling `ds:tokens` a *"regenerate"* — which reads as optional
maintenance rather than a prerequisite. **`ds:install` appears nowhere in the README at all.** There is
also a **third** site at `:97`, the e2e bootstrap, which runs `ds:tokens && build` correctly ordered but
still omits `ds:install`. So the defect is not a missing note; it is an **ordering that actively
misleads**, in two places, plus a step that is absent from the document entirely.

**(3) THE FAILURE PRINTS A SUCCESS AFTER IT — AND THIS IS THE SHARPEST PART.** The PWA plugin's
service-worker build runs *after* the client build fails and succeeds on its own: `✓ built in 329ms`,
`public/build/sw.mjs 134.93 kB`. **The last thing on screen is a green tick.** Only the exit code (`1`)
and the eleven lines above it disagree. A new contributor reading the tail of the output concludes the
build worked and then debugs a blank page. The row does not mention this and it is the strongest argument
for the fix.

### One thing found and deliberately NOT fixed

`packages/design-system/package.json`'s `exports` maps **two** `dist/` artifacts: `./tokens.css` →
`./dist/tokens.css` **and** `./tokens` → `./dist/tokens.ts`. Nothing imports the latter today (grepped
`resources/` and `packages/design-system/src`), so it is not a live second failure — a second build
artifact behind a public export path, worth a sentence in the README's note rather than a change.
**Filed here at the moment the decision not to fix it was taken**, per the standing rule that a
deliberately-unfixed finding goes in writing immediately or becomes invisible.

### ✅ WHAT THE GATES MEASURED

**6/6 green, every job with a real steps count** (20 · 11 · 18 · 11 · 16 · 12). Documentation-only, so
every figure is unmoved as predicted — and that is the weakness of this PR's CI rather than its strength.
**The proof is the worktree reproduction, not the green tick**, which is the same reasoning that makes
J4b1's 1,086-line deletion (2026-08-16, `f565ac9`) the standing warning about docs-only PRs.

⚠️ **THE SEQUENCE WAS RUN FORWARD AS WELL AS BACKWARD, AND ONLY THE FORWARD RUN PROVES THE FIX.**
Reproducing the failure shows the row was real; running `ds:install` → `ds:tokens` → `build` to
**exit 0, `✓ built in 13.58s`** shows the sequence now written in the README is *sufficient* and not
merely *different*. A documentation fix that is never executed is a guess with better formatting.

---

## RELEASED — M25, the axe scans that assert nothing about which page they landed on (merged as PR #216, `6c5494e`, 6/6 green)

**Taken 2026-08-26.** Branch `m25-axe-landing-assertions`, cut from `origin/main` at `d1d1d72`, PR into
`main`. Row: the first `major` under **Test suite & CI gates** in `docs/feature-backlog.md` — *the 16-page
responsive scan asserts nothing about which page it landed on*.

**⚠️ NUMBERED `M25`, AND `lane-b.md` WAS RE-READ IMMEDIATELY BEFORE THIS FILE WAS WRITTEN, NOT AT SESSION
OPEN — WHICH IS THE ONLY REASON THE NUMBER IS RIGHT.** The opening fetch returned `0e9c48d`; by the time
the branch was cut `main` was `d1d1d72`, one commit further on (`fix(M24)`, Lane B, pushed mid-read).
`lane-b.md` reads **ACTIVE CLAIM `M24`** in both reads, so `M25` is the next free number. Lane B's M24
touches `app/Services/Gamification/`, `tests/{Unit,Feature}/Gamification/` and `docs/adr/0020` — **no
file in this claim is in that set**, and the only shared artefacts are `docs/feature-backlog.md` (a
different row) and `PROGRESS.md` (own block only).

### ⛔ NAMESPACES — THIS CLAIM SPENDS NOTHING

**No ADR.** `0022` **stays free and stays Lane A's block-opener (`0022-0025`)** — sixth consecutive
increment to spend nothing. This is a test-fixture correction, not a decision: it adds no mechanism, no
option was weighed and rejected, and the reasoning it records belongs in the spec's own comment beside
the assertion. **No migration** (`2026_08_17_000111` stays free — no schema, no PHP at all). **No
ADR-0016 `§D<n>`.** No threat-model row. `0010` stays reserved for H1d; `#16` stays free.

### Every file this claim touches, named before it is opened

- `tests/e2e/responsive-axe.spec.ts` — the 16-page loop at `:148-156` gains **one** landing assertion,
  plus the comment recording why. **Shared artefact** (`tests/e2e/*.spec.ts`), claimed here.
- `tests/e2e/auth-axe.spec.ts` — the 5-page unauthenticated loop at `:81-89` gains the same one line.
  **Shared artefact**, claimed here. See the extension note below for why it is in scope and why the
  other three look-alikes are not.

Shared, claimed here: `docs/feature-backlog.md` (this row only), `docs/claims/lane-a.md`, `PROGRESS.md`
(Lane A's block and hand-off line only). **If this list grows, the claim is extended as its own pushed
commit before the file is opened.**

**No `.vue`, no `.ts` under `resources/`, no PHP.** So: no `docker restart` of the node container, no
Vitest movement, no Storybook axe movement, `openapi.json` byte-identical, PHPStan and the four host lint
gates unmoved. **Only the E2E figure can move, and it must not** — this claim adds assertions to existing
tests and creates no new test, so **551 passed + 10 skipped is a prediction, not a baseline to beat.**

### ⚠️ THE ROW'S CITATION IS WRONG, AND SO IS THE ONE IT OFFERS AS THE FIX

Both were re-walked against the tree at `d1d1d72` before this claim was written.

- **The row cites `responsive-axe.spec.ts:124-132`. That is inside the `pages` ARRAY** — the comment block
  on the `Two-factor required` entry. The `goto` → `forceTheme` → `assertClean` loop it means is
  **`:148-156`** (`goto` at `:151`, `assertClean` at `:153`).
- **The row cites the `filteredToZero` loop as `:154-163`. It is `:175-188`** (`goto` at `:178`, the
  `No matching` heading check at `:183`).
- **`support/console.ts:34` HOLDS exactly** — `expect(page.url(), …).toContain(path)`. That is the house
  idiom this fix adopts rather than inventing one.
- **`bootstrap/app.php:315-334` HOLDS** — `FeatureGateException` at `:323-329` and `ModuleDisabledException`
  at `:336-342` both `return back()->with('toast', …)`. **The row says "every plan/module refusal"; it is
  broader still — SEVEN handlers in that file return the same toast redirect** (`ScopeNodeException`
  `:286`, `GrantException` `:297`, `QuotaExceededException` `:310`, `FeatureGateException` `:323`,
  `ModuleDisabledException` `:336`, `XlsformImportException` `:389`, `FormNotAcceptingSubmissionException`
  `:458`), so the 302 is this application's standard web refusal rather than a special case.
- **`/achievements` is `module:gamification`** (`routes/tenant.php:258-259`), `RequireModule:60-62` throws
  `ModuleDisabledException` when the setting is off, and `E2eSeeder` enables no module — its only
  `gamification` mention is a comment at `:1134`. The row's example holds in full.

### ⚠️ WHERE THE ROW UNDERSTATES ITSELF — FOUR PAGES ARE EXPOSED, NOT ONE

The row names `/achievements` and leaves the impression it is the single instance. **Four of the sixteen
sit behind a gate that answers a web GET with a 302**, and the other three are gated on the tenant's
PLAN rather than a module default:

| Page | Gate | `routes/tenant.php` |
|---|---|---|
| `/achievements` | `module:gamification` | `:258-259` |
| `/analytics` | `feature:advanced_analytics` | `:891-892` |
| `/webhooks` | `feature:webhooks` | `:762-763` |
| `/integrations` | `feature:native_connectors` | `:823-824` |

The remaining twelve carry `can:` gates only, which raise **403** — a different and much weaker exposure
(a scan of an error page), **not** this defect. It is named here so a later reader does not "complete"
the sweep by adding assertions for a redirect that cannot happen.

⛔ **AND THE FILE ALREADY KNEW.** The `Analytics` entry's own comment (`:35-37`) says acme is reachable
"only because `E2eSeeder` upserts acme onto a Business plan (ADR-0011 §D9 names seeding that tenant as a
blocking obligation, **precisely so this gate cannot stay green over a page it never loads**)". The
obligation is real and it is discharged — **and nothing in this spec asserts that it was.** Three scans
are load-bearing on a seeder promise recorded in an ADR and enforced by nobody. That is the finding
above the fix.

### ⚠️ THREE LOOK-ALIKES ARE ALREADY PASSING — THE M20 LESSON, RE-MEASURED

A grep for `goto` → `assertClean` finds five more sites. **Three are already protected, and adding an
assertion to them would be noise dressed as diligence:**

- `tests/e2e/templates-axe.spec.ts:12-17` — `/forms/templates` IS `feature:form_templates`-gated
  (`routes/tenant.php:486-487`), the strongest-looking candidate of the five, **and it already asserts
  `Use this template` is visible**, which no dashboard can satisfy.
- `tests/e2e/admin-console-axe.spec.ts:45-52` — routes through `openConsole()`, which **ends** in
  `console.ts:34`'s URL assertion.
- `responsive-axe.spec.ts`'s own `filteredToZero` loop — already asserts the `No matching` heading, which
  is why `/webhooks?q=…` is covered while bare `/webhooks` twenty lines above is not.

**One is a genuine instance and is claimed** — `auth-axe.spec.ts:81-89`. Its five pages carry `guest`,
which redirects an authenticated visitor to `/dashboard` (`config/fortify.php:80`), and the ONLY thing
standing between it and ten green scans of the dashboard is the `test.use({ storageState: empty })` at
`:65`. **The file's own header names that exact hazard** — *"would be scanned as the LOGIN page it
redirects to and would pass while covering nothing"* — and then asserts nothing about it. Same class,
same one-line fix, same file family.

**One is NOT and is deliberately left** — `personalization-axe.spec.ts:36-42` and `:56-68` scan `/forms`,
`/settings` and `/submissions`. `/settings` carries no middleware at all (`:267`) and the other two are
`can:`-gated, so there is no 302 to catch. Filed here rather than swept in.

### The fix, and the positive control that will prove it

One line per loop — not per test — because both are `for` loops: **2 inserted lines cover 42 tests.**
The assertion is `console.ts:34`'s idiom verbatim, so the repository gains no new vocabulary.

**⛔ THE POSITIVE CONTROL IS THE ROW'S OWN SCENARIO, RUN BOTH WAYS.** Flip the `gamification` module
default off so `/achievements` genuinely 302s, then run the two Achievements tests **with** the assertion
(must go red naming `/achievements`) and **with it reverted** (must stay green — that green is the
defect, and it is the whole reason this row exists). Restore by **saved-bytes sha256 comparison**, never
`git checkout --`. The mutation is local and uncommitted; Lane B's tree and DB are separate checkouts and
a separate stack, so it cannot reach them.

### What will be run, and what will not

`responsive-axe.spec.ts` and `auth-axe.spec.ts` are the two specs this diff reaches, run in M19's e2e
image via `docker compose run --rm e2e`. `playwright.config.ts` pins `workers: 1`, so the full suite is
3–4 hours locally — **the rest of the suite is CI's, and this claim will say which specs were run rather
than implying the whole matrix.**

### ✅ WHAT THE GATES ACTUALLY MEASURED, AGAINST WHAT THIS CLAIM PREDICTED

**6/6 green, every job with a real steps count** (E2E 20 · a11y 11 · static 18 · Pest 11 · contract 16 ·
frontend 12 — no `steps: []`). **CI E2E `551 passed + 10 skipped`, NO flaky line — the baseline exactly**,
which is what this claim predicted rather than hoped: assertions were added to existing tests and no test
was created, so the count *must not* move. Every other gate unmoved by construction, as claimed.

⛔ **AND THE LOCAL RUN DISAGREED WITH CI IN BOTH DIRECTIONS, WHICH IS THE TRANSFERABLE PART.** The full
261-test local sweep returned **253 passed / 8 failed**, and NEITHER failure was the diff's:

- **6 × `Sheets rule detail — drift`** — reproduced **identically with the change reverted** on
  `origin/main`, and **pass in CI**. A stale drift fixture in this host's long-lived hybrid dev DB.
  Proving it took one extra 6.4m control run and was worth it: the alternative was reverting a correct fix.
- **2 × `Builder` (tablet)** — did **not** reproduce in the control run and passed cleanly on re-run
  (2 passed, 1.7m, ~30s each). A **load flake** inside a 1.1-hour saturated run, not a defect.

⚠️ **SO A LOCAL RED HAS AT LEAST THREE CAUSES AND THEY LOOK IDENTICAL FROM OUTSIDE:** the diff, the local
DB, and load. The only thing that separates them is re-running the failing subset with the change reverted
— which is cheap (6.4m for 12 tests) and was, this time, the difference between shipping and not.
⚠️ **AND `failOnFlakyTests` MAKES A FLAKY RESULT RED IN CI**, so a load flake reproduced there would have
blocked the merge; it did not appear in CI's 17.9m run.

---

## RELEASED — M23, the four App-UI rows (merged as PR #214, 6/6 green)

**All four fixed, every fix mutation-proven, and two of the four rows were wrong about something that
mattered.**

⛔ **THE HEADLINE: A GUARD THAT READS AS WORKING AND BLOCKS NOTHING.** `Button.vue`'s in-flight guard
called `event.stopPropagation()`. The consumer's `@click` is a **fallthrough listener on the same
element** — Vue merges it onto the component's own root — so bubbling was never what stood between it and
a second request. For four increments the docblock promised *"must not fire a second submit"* and the
function delivered that only for a native `type="submit"`, where the sibling `preventDefault()` happened to
do the real work. The cost was the one button in the tree whose second click provisions a second Google
spreadsheet in the tenant's Drive.

**MEASURED BEFORE IT WAS WRITTEN, because two facts had to hold and neither is obvious:**

| probe | consumer's `@click` fires? |
|---|---|
| `stopPropagation()` (as shipped) | **yes** — 1 call |
| `stopImmediatePropagation()` | **no** — 0 calls |

Handler order came back `["inner","consumer"]` — the component's own handler runs **first**, which is the
only reason a design-system-level fix existed at all. Had the order been reversed, the local guard would
have been the only option. Vue patches `stopImmediatePropagation` on the event precisely so it breaks out
of a merged handler array; a plain DOM listener array would not honour it.

⚠️ **AND THE ROW'S CLOSING JUSTIFICATION WAS WRONG WHILE ITS CONCLUSION WAS RIGHT — DO NOT REUSE THE
REASONING.** It argued every other `:loading` button is safe because Inertia's stream is
`maxConcurrent: 1, interruptible: true`. That is the constructor, not a dedupe: `interruptInFlight()`
aborts the in-flight XHR and sends the second request anyway, and the first POST has already reached the
server. What actually protects the rest of the tree is that they are `type="submit"` inside a
`@submit.prevent` form.

### ⛔ THE TEST THAT PASSED WITH THE FIX REVERTED, AND WHY IT IS THE MOST TRANSFERABLE THING HERE

The first draft of `SheetsRuleFields.test.ts` stubbed the button as `MdsButton` and asserted one call for
three clicks. It was **green with the local guard removed** — because Vue Test Utils matches a stub against
the component's own **inferred name**, which for a `<script setup>` SFC is its **filename**. `Button.vue`
gives `Button`; `MdsButton` is only the barrel's export alias and matches nothing, so the real component
rendered and *its* guard absorbed the extra clicks. The same three-click case reports **1** call under the
`MdsButton` key and **2** under `Button`.

**Only the mutation found it.** A green run said nothing, and the assertion was correct — the fixture was
not. ⚠️ **THIS IS REPO-WIDE: thirteen stubs across four other files key on the same alias** and are
therefore silently inert, so four suites are exercising more component than they claim. Filed as its own
row rather than fixed, because repairing them changes what those suites cover.

### The two rows whose framing was wrong

- **THE TOP-NAV ROW'S OWN FIX WOULD HAVE SHIPPED A REGRESSION.** It says to read `q` from `usePage()`. Read
  unconditionally, the workspace-search field shows **any** page's `q` — and `q` is the shared filter key
  on six list pages (forms, members, webhooks, audit log, feedback, the submissions inbox), all committing
  client-side with `preserveState`. The box labelled "Search this workspace" would have carried the audit
  ledger's filter term, and Enter posts that box to `/search`: "filter this list" silently becomes "search
  everything." Shipped gated on `page.component === 'search/Index'`, which also fixes the pre-existing
  full-page-load version instead of widening it. ⚠️ The file's own docblock also asserted that a browser
  Back "arrives as a fresh render" — it does not, Inertia swaps in place on popstate — and that false
  sentence was the stated justification for the implementation.
- **THE RULE-MODAL ROW DESCRIBED THE MILDER OUTCOME.** "Silently sends the unfiltered set" is true only for
  a disconnected grant. On a live one the server **does** reject it — under `event_types.{index}`, a dotted
  key this modal never renders — so the tenant got a 422 with no visible cause, on a modal that stays open,
  and could no longer rename the rule, rescope it, or repair its destination.

### ⛔ A SEMANTIC TOKEN IS NOT A VISIBLE ELEMENT, AND THE GATE THIS INCREMENT ADDED CANNOT SEE THAT

The medallion row was one token: in dark, `--mds-neutral-100` **is** `--mds-color-bg-surface`, so the disc
was painted its own card's colour at exactly **1.00:1** — absent, not faint. A primitive-ban gate was added
so the class cannot recur, proven by positive control (revert the token, it reddens naming the file).

**Then the adversarial pass found the identical defect wearing a token the gate permits.**
`LogicRail.vue`'s `.rail__dot` was `--mds-color-bg-sunken` on a `--mds-color-bg-canvas` ground, and in dark
**both resolve to `--mds-neutral-50`** — 1.000:1, live, and green under the new gate. Fixed in the same
increment. **The gate closes the cheap half only** and must never be reported as closing the class: the
real check is "does every painted element differ from the ground it actually lands on, in both themes",
which needs the resolved ancestor chain and is not a source-text scan.

⚠️ **THE GATE ALMOST DOCUMENTED ITSELF INTO FAILING.** It strips `/* */` comments, and the fix comments
originally quoted the banned string — so moving either into HTML comment syntax would have made the gate
report its own documentation as the offender. Both are now written without the `var(` prefix, so the raw
count of banned strings under `resources/` is **zero with no stripping at all**.

⚠️ **AND IT READS LANE B'S TREE, SO IT IS REGISTERED RATHER THAN NARROWED.** `APP_SCAN_ROOT` is
`resources/` entire, which includes `resources/public-runtime/`. That is 7(b-bis)'s shape exactly, and that
paragraph says to add it to the table when found — so it is the table's fourth row, flagged as a
**prohibition rather than a parity assertion**, because the table's stated remedy ("edit both halves in the
same PR") has no second half to apply to here. Narrowing to `resources/js` was rejected on the merits: the
guest runtime is application code, and it is the one tree shipped to unauthenticated respondents.

### What was left for someone else, filed the moment it was decided

The modal's GET-only refresh button (same shape, no irreversible side effect); the semantic-token audit
above; and the thirteen inert stubs. All three are rows in `docs/feature-backlog.md` — **not** notes in
this file, because a finding that lives only in a claim is invisible to a backlog search.

### Two things measured rather than assumed, both of which changed an answer

- **`AirtableRuleFields.vue` does not share the double-provision defect** — and not because it guards
  better: it has no create-destination button at all, and its two `busy`-aware `:disabled` bindings are on
  `MdsSelect`. Checked because it is the other tabular editor with an identical prop set, which is exactly
  the shape that made M20 file one row against two files.
- **`WebhookFormModal.vue` does not share the unfiltered-submit defect** — it iterates the unfiltered
  `eventTypes` prop, so rendered already equals sendable there.

**THE FILE RECORD.** New: `packages/design-system/src/components/Button/Button.test.ts` (this component's
first spec, ever) · `resources/js/components/integrations/SheetsRuleFields.test.ts` ·
`resources/js/components/integrations/RuleFormModal.test.ts`. Amended: `Button.vue` ·
`token-references.test.ts` · `Pages/achievements/Index.vue` · `components/builder/LogicRail.vue` ·
`RuleFormModal.vue` · `SheetsRuleFields.vue` · `TopNav.vue` · `TopNav.test.ts`. Shared:
`docs/feature-backlog.md` · `docs/claims/lane-a.md` · `PROGRESS.md` (own block, own hand-off line, **and
Standing Rule 7(b-bis)'s new fourth row**).

⚠️ **ONE PROCESS NOTE WORTH THE LINE.** `main` moved twice mid-increment — Lane B merged `M22` while this
was building, so a claim-extension push was rejected non-fast-forward and needed
`git rebase --autostash origin/main`. That is the normal shape of two live lanes, not an incident; it is
recorded because the reflex on a rejected push is `--force`, and here that would have discarded a merged
increment. ⚠️ And a `git reset --hard` used to re-sync after the merge **silently ate an uncommitted
docs edit** — the same lesson M9 recorded about `git checkout --`, arriving through a different command.

---

## RELEASED — M20, the three design-system merge-gate rows (merged as PR #212, `836a182`, 6/6 green)

**All three fixed, all three measured in a browser, and one of them was only half a defect.**

⛔ **THE HEADLINE FINDING: A CHARACTER-IDENTICAL DECLARATION IS NOT AN IDENTICAL DEFECT.** The pending-ring
row cited `PasswordStrength.vue:212-218` and `Checklist.vue:289-295` together, with **one pair of numbers
for both**, because a repo-wide grep returned exactly those two lines and they matched to the character.
Measured, they were never the same ring: `currentColor` is `--mds-color-text-secondary` in one and
`--mds-color-text-body` in the other.

| | as shipped (0.55 alpha) | as fixed (solid) | verdict |
|---|---|---|---|
| `MdsPasswordStrength` light | **2.28:1** | 5.55:1 | a real 1.4.11 failure |
| `MdsPasswordStrength` dark | **3.08:1** | 7.51:1 | already passing, by 0.08 |
| `MdsChecklist` light | **3.60:1** | 14.84:1 | already passing |
| `MdsChecklist` dark | **5.37:1** | 15.70:1 | already passing |

**One of four filed cases was actually below the bar.** Both were still fixed, and the dark row is why: the
same declaration reads **2.96:1 on `--mds-color-bg-surface` and 3.08:1 on `--mds-color-bg-canvas`** — it
fails on a card and passes on the page, and **nothing in the component decides which one it gets.** An
alpha composite is a function of the ground behind it; a shared component does not own its ground. Raising
the alpha to 0.75 clears the bar today (3.43:1) and leaves that fragility exactly where it was.

⛔ **AND THE REASON ALL THREE SHIPPED BEHIND A GREEN GATE STACK IS FIXTURES, NOT SCANNERS — WHICH IS M19'S
LESSON ARRIVING FROM THE OTHER DIRECTION.** M19 learned that a probe measuring zero proves nothing unless
it exercised the defect. Here, three separate gates were reporting zero over fixtures too small to reach
the thing they were pointed at:

- Every `MdsCombobox` story seeded **four** options, so `max-height: 22rem` was never reached: axe's
  `scrollable-region-focusable` had **no scrollable region to look at**, and happy-dom computes no layout.
- Every stacked `MdsDataTable` story declared **one** sortable column, so the sortbar has rendered a single
  chip for as long as it has existed — **it had never wrapped**, never had a second row to be 8px away
  from, and never had a short header to overhang.
- And for the touch target there is no scanner at all: **SC 2.5.8's floor is 24×24, so axe passes a 32px
  control and always will.** What is breached is DSR §4.4's stricter 44×44.

Two new fixtures now reach all of it — a 21-option `Scrolling` story and a five-column `StackedSortWrap`
story, both light and dark. **Storybook axe 42 suites / 303 in CI, up from 299 — four new stories, still green.**

### What each row cost, and the one citation that was wrong

**(1) The combobox highlight — all three citations HELD, and the row was right end to end.** Measured with
a positive control before the fix was trusted: at the palette's real worst case the list is **1195px in a
352px band**, pinned to the top **exactly five of twenty-one rows are visible**, and the last option sits
**843px** below the box. After the fix, arrowing through all 21 options put **zero** out of view.
⛔ **Not `scrollIntoView`** — `block: 'nearest'` walks *every* scrollable ancestor, and this listbox sits
inside a dialog inside a page whose horizontal scroll M17 and M19 spent two increments removing. A new
sibling module, `Combobox/scroll-reveal.ts`, computes a `scrollTop` and returns `null` for "already
visible", so a reader scrolling by wheel or touch is never fought. The watcher is `flush: 'post'` because a
pre-flush watcher measures the row highlighted a moment ago — **consistently**, which reads as "the fix
does not work" rather than as a timing bug.

**(2) The sort chip — the row is right on the merits and WRONG ON ONE CITATION.** The container query is
`DataTable.vue:598`, **not `:657-659`**; `:657` is the empty-row `grid-column` rule *inside* it. That makes
five rows running whose own evidence was wrong somewhere. ⚠️ **The `::before` idiom alone would have left
this half-fixed**: §4.4 asks for 8px between **hit areas**, and a 44px target on a 32px chip overhangs 6px
each way, so two *wrapped* rows at the uniform 8px gap overlapped by **4px of invisible target**. The gap
is now two values (`row-gap` 20px = 8 + 2×6, `column-gap` 8px) with a gate that fails on anyone tidying
them back into one. The horizontal overhang is designed out rather than measured away —
`min-width: calc(44px + 2px)`, where the **2px is the border that `box-sizing: border-box` folds inward**
while an absolutely positioned child's `width: 100%` resolves against the *padding* box. Hit-tested at
320px: overlay **44×44 on every chip**, **8px horizontal / 9px vertical** clearance, **0px overhang past
the frame**, `scrollWidth === clientWidth`.

**(3) The pending ring — see the table above.**

### Claimed files: what was touched, and what was released untouched

Every file in the claim was edited **except one**, and it was claimed on a stated prediction that it might
not be — the M17 `DnsRecordBlock.vue` / M18 three-file precedent. **`tests/e2e/list-layout.spec.ts` is
released untouched**: it was taken because the 44px hit area overhangs its 32px visual box and that spec is
the one that measures the table's own scroll wrapper. The overhang turned out to be **vertical only** — the
`min-width` designs the horizontal one out entirely — so there was nothing for a horizontal-overflow gate
to assert. **One file released untouched on a prediction that was written down first is the claim working.**

**The claim was extended once**, as its own pushed commit before the file was opened, for
`Combobox/scroll-reveal.ts`.

⚠️ **AND THAT EXTENSION IS ITS OWN LESSON, BECAUSE THE FIRST PUSH OF IT LOST EVERY FILE NAME.** The text was
passed to `node -e` inside a double-quoted shell string, so bash ran each backticked path as a command
substitution and committed a paragraph whose entire job is to *name a file* with the names deleted
(`17da814`; repaired in `f3fc136`). **Prose for these files goes through the Write tool, never inline** —
and note the same shell collapses a doubled backslash to a single one, which silently broke two regexes in
a test file until they were rewritten as plain substring reads, and which made a long heredoc of this very
document fail to parse. Lane B's recorded rule — *Write tool for prose, shell for the splice* — is correct
and now has a Lane A sighting.

⚠️ **PAIRED FILES: CHECKED AND UNMOVED.** `clipped-node-containment.test.ts` was run directly — 3 passed,
`KNOWN_UNGUARDED` unchanged in both directions, exactly as the claim predicted.

⚠️ **`packages/design-system/package-lock.json` WAS REVERTED, NOT COMMITTED.** Installing Storybook to run
the axe gate locally (`npm --prefix packages/design-system install`) rewrites that tracked lockfile —
here, dropping `"peer": true` markers on four packages. It is churn, it was not in the claim, and it has
nothing to do with M20. **Anyone running the axe gate locally will hit this; revert it before committing.**

### How the gates were actually run, because two of them cannot run where you would expect

- ⛔ **Storybook axe CANNOT run in `dev_formbuilder_app-node-1`** — that container is musl-based and
  Playwright's Chromium is a glibc build, so the launch fails with `spawn ... ENOENT` **while the binary is
  present and executable**, which reads as a missing browser and is not one. Build Storybook in the node
  container (`npm run ds:storybook:build`) and run the scan in **M19's e2e image** instead, via
  `docker compose run --rm --entrypoint sh e2e`, serving `storybook-static` with `npx http-server` on 6006
  and pointing `npx test-storybook --url` at it. ⚠️ The design-system deps are **not installed in either
  tree by default** — that install is a 4-minute step and is what rewrites the lockfile above.
- ✅ **The same image runs standalone probe scripts**, which is how every number in this record was
  measured: `docker compose run --rm -v "<scratchpad>:/probe" --entrypoint sh e2e`, with
  `createRequire('/work/')` inside the script to resolve `playwright` from the repo's own `node_modules`.
- ⚠️ **`playwright.config.ts` sets `workers: 1`, so the full e2e suite is ~3-4 hours locally** — a full run
  reached 34 of 551 in the first stretch and was stopped deliberately. M20 ran the three specs its diff can
  reach and left the full suite to CI, which is the authority. **Say which one you ran.**
- ⚠️ **A hit-test probe measures one pixel short and it is the probe, not the code.**
  `document.elementFromPoint` treats a box as `[top, bottom)`, so walking outward from an element's edge
  loses the far boundary and a true 44px target reports 43. Read `getComputedStyle(el, '::before')` for the
  authoritative box and keep the walk as an independent reachability check with a 1px tolerance.

## RELEASED — M19, draining `KNOWN_OVERFLOWING` (merged as PR #210, `8ab9ae8`, 6/6 green)

**`KNOWN_OVERFLOWING` is empty.** All four defects fixed, all five entries deleted, and the mechanism
that kept them hidden removed rather than worked around.

⚠️ **TWO CLAIMED FILES WERE RELEASED UNTOUCHED, AND THAT IS THE CLAIM WORKING.** `ConfigPanel.vue` and
`SegmentedControl.vue` were claimed with the reason written down *before* they were opened — *"claimed
on a prediction that may be falsified by the first measurement"* — and the measurement falsified it:
the 30px segment spill is **absorbed** by `.config`'s `overflow-y: auto` and owns none of the five
entries. **A file claimed because it might be written to and then correctly released is the M8/M11/M17
shape, this time flagged in advance rather than discovered.**

⛔ **THE CLAIM WAS INCOMPLETE IN A WAY WORTH RECORDING: `docker/e2e/Dockerfile` WAS CREATED WITHOUT
BEING NAMED.** The claim named `docker-compose.yml` — correctly, as a file in neither lane's column —
and never named the Dockerfile that compose file `build:`s. No collision followed (Lane B's M18 touches
no `docker/`), but the gap is structural rather than lucky: **a claim that names a config file must also
name the files that config brings into existence.** The claim was *not* otherwise extended.

⚠️ **THE CLAIM WAS ALSO NUMBERED WRONG ONCE AND CORRECTED BEFORE PUBLICATION.** It opened as M19 rather
than M18 because Lane B's `m18-sso-domain-verification` landed **between this session's opening `git
fetch` and this claim's push** — the second such race in two days, after M16 lost an ADR number to the
same shape. It cost nothing here only because the claim file was re-read immediately before writing
rather than once at the start of the session.

⛔⛔ **THE ROW'S PREMISE WAS FALSIFIED — 37-FOR-37, AND FOUR ROWS RUNNING WHOSE OWN EVIDENCE WAS WRONG.**
M17 quarantined all five because *"none reproduces on a Windows host"*, after a probe that inlined
**OpenDyslexic** and measured 0. **OpenDyslexic cannot reach the elements that failed**:
`theme-overrides.css` re-points only `--mds-font-family-body`; both failing headings are
`--mds-font-family-display`; the form hub had no personalization at all. The real variable is the
display stack's Linux fallback — `system-ui` → Segoe UI on Windows, **DejaVu Sans** in CI, ~27% wider,
**256px against 324px** for one page title.

✅ **REPRODUCED TO THE PIXEL BEFORE ANY CSS CHANGED — 17 / 24 / 28 — THEN 0 / 0 / 0**, and the two
predictions the claim wrote down were measured rather than explained afterwards:
- *"Vitest and Storybook axe WILL move"* — **wrong.** 35/545, 60/886 and 42/299 are exactly unmoved. A
  `overflow-wrap` declaration touches no source-text gate and adds no story.
- *"The one I most expect to be wrong: that fixing `.builder__title-row` alone retires all three builder
  entries"* — **right**, and verified rather than inferred: `showBuilderPane` returns `false` when the
  switcher is hidden, which would have made two `assertClean` calls vanish rather than pass. Measured
  `switchVisible=true fields=true canvas=true`.

⛔ **THE GATE MISATTRIBUTED TWO OF THE FIVE ENTRIES ITSELF.** It walked descendants of scroll containers
it skipped (naming an absorbed spill that contributes nothing to the asserted number), and it iterated
elements only (so an overflowing line box had nothing to report and the message guessed at "a grid or
flex track"). Both closed, both proved on the defect that exposed them.

⚠️ **AND A FAILURE THAT READ EXACTLY LIKE A FIXTURE PROBLEM WAS MY OWN ORPHANED CONTAINER** — cancelling
a background sweep killed the client, not the container, which ran 21 more minutes and timed out the
next run's login. **`docker ps` before concluding anything about the suite.**

---

## RELEASED — the horizontal-overflow assertion, made able to fail (merged as PR #208, `2279293`, 6/6)

**Every claimed file was edited except one, and that one is the mutation subject.**
`DnsRecordBlock.vue` was claimed *"for the MUTATION ONLY … reverted before the PR opens"*, and it was:
restored from saved bytes, **sha256-identical**, absent from the diff. **A file claimed because it will
be written to and then correctly released untouched is the claim working, not a wasted one** — the
M8/M11 shape, this time by design rather than by surprise. `personalization-axe.spec.ts` was **added to
the claim mid-build** (its labels needed quarantining), as its own pushed commit before the file was
opened. Nothing was spent from either namespace.

⛔ **THE ROW'S OWN PROOF WAS A NO-OP — 36-FOR-36, AND THE SECOND ROW RUNNING WHOSE EVIDENCE WAS WRONG.**
*"Delete `min-width: 0` from `achievements/Index.vue:452-459` and every scan still passes"* proves
nothing: that rule also carries `overflow: hidden`, which already resolves `min-width: auto` to 0.
**343/343 with it and without it.** The gate WAS inert — proven by the clip, not by that demonstration.

✅ **PROVEN WITH A MUTATION THAT DOES SOMETHING.** Deleting `overflow-wrap: anywhere` from `.dns__code`
and scanning `/domains` at 375px: **unfixed gate → 2 PASSED over 312px of real overflow**; **fixed gate
→ 2 FAILED, `Received: 312`**; **reverted → 71 passed.** Leg 1 is the row: the document read **0** while
the content region was overrun by 312px, on a test named *"no horizontal overflow"*.

⚠️⚠️ **THE PREDICTION I FLAGGED AS MOST LIKELY WRONG WAS WRONG, AND THAT IS THE INCREMENT'S BEST
OUTCOME.** The claim said *"a page CI flags and my survey did not is a real defect this gate was built
to find — file or fix it, do not widen the tolerance."* CI flagged **five**, all pre-existing, none
visible to any prior gate: `page-header__title` 17px · `mds-segmented__seg` 30px · `builder__title-row`
24px on **two** panes · the form hub 28px at tablet with **no personalization at all**.

⛔ **48 OF THE FIRST RUN'S 52 FAILURES WERE MINE, AND BOTH WRONG VERSIONS ARE WORTH KEEPING.** v1 asked
*"is `.app-shell` present without `.app-shell__content`?"* — and the **guest runtime has an `.app-shell`
of its own** (`resources/public-runtime/App.vue:457`) with no clip. **A class-name collision across the
lane boundary, structurally invisible to a Lane A grep.** v2 over-corrected to *"does ANY element clip
or hide?"*, which is true on nearly every page (`overflow: hidden` draws every sr-only paragraph and
every scroll lock) — caught locally before pushing. The guard now keys on the single property that
causes the blindness: a shell that is specifically `overflow-x: clip`.

⚠️ **PLAYWRIGHT ABORTS A TEST AT ITS FIRST FAILED ASSERTION, SO EACH QUARANTINE REVEALED THE NEXT
DEFECT.** One test calls `assertClean` three times; the base failure hid `— Add`, which hid `— Form`.
**Three CI runs to drain a test that had reported exactly one problem.** None was ever pre-listed on a
hunch, deliberately: `KNOWN_OVERFLOWING` asserts a quarantined page *still* overflows, so a speculative
entry fails **exactly as loudly** as a missing one. **A symmetric list makes guessing worthless, which
is the property that keeps it honest.**

⛔ **QUARANTINED RATHER THAN FIXED, AND THE REASON IS STATED NOT ASSUMED: NONE REPRODUCES ON WINDOWS.**
A probe inlining OpenDyslexic as a data URI — defeating the recorded cross-origin font trap, with
`document.fonts.check()` returning `true` — measured **0 overflow across all six page × viewport
combinations**, as did a plain tablet probe of the form hub. 17/24/28px is the size of Linux-vs-Windows
font metrics. **Guessing at CSS verifiable only through 20-minute CI round-trips, for overruns nobody
here can see, is how a plausible-but-wrong fix ships.**

⚠️ **AND THE MISLEADING COMMENTS MISATTRIBUTED REAL CATCHES, WHICH IS WORSE THAN OPTIMISM.** Two claimed
specific saves — *"has now caught Domains and the Audit log"*, *"has reddened this gate three times
(H12b, H14, H15b)"*. The clip landed 2026-07-21 (`506ff97`); all three merged 07-26/07-27, six days
later, with the assertion already dead. Those went red on **axe violations**, credited to the
neighbouring check. Corrected **in place rather than deleted**: **a comment citing three specific saves
is exactly what stops the next reader from testing the claim.**

**Gates.** E2E **551 + 10 skipped, no flaky line**; Pest, PHPStan, four lint gates, `openapi.json`,
Vitest and axe unmoved (no `.vue`, no `packages/`, no `resources/`, no PHP in the final diff). Six jobs,
real step counts 16 · 11 · 11 · 18 · 20 · 12.

---

## SUPERSEDED CLAIM (kept for the record) — M17 (`m17-overflow-gate`)

Opened: 2026-08-26, cut from `origin/main` at `e5fe62e`.

Row: `docs/feature-backlog.md:502` — *"`major` · THE END-TO-END HORIZONTAL-OVERFLOW ASSERTION IS
STRUCTURALLY INERT ON EVERY `AppLayout` PAGE — IT CANNOT FAIL, AND HAS NOT BEEN ABLE TO SINCE THE
CLIP LANDED."* The review that filed it calls this **"the single most valuable output of the review:
it invalidates a gate this project has been trusting."**

**Files:** `tests/e2e/support/axe.ts` · `tests/e2e/builder-axe.spec.ts` ·
`tests/e2e/responsive-axe.spec.ts` · `docs/feature-backlog.md` · `PROGRESS.md` (Lane A's block only).
⚠️ **`resources/js/components/domains/DnsRecordBlock.vue` is claimed for the MUTATION ONLY** — it is
Lane A's outright under 7(b), it is edited to prove the gate can fail, and **it is reverted before
the PR opens.** Claimed anyway, because a file that gets written to is a file another lane must not
be holding, whatever my intention for it.

**Shared artefacts taken:** the three `tests/e2e/` files ("claim first" in 7(b)) and
`docs/feature-backlog.md`. **`playwright.config.ts` is NOT taken** — M16 spent it and this row needs
nothing from it.

**Paired files taken:** none. No `clip: rect(0 0 0 0)` is added or removed, so
`clipped-node-containment.test.ts` cannot fire; ⚠️ **and Lane B's M15 is holding that file right now**
(its `KNOWN_UNGUARDED` shrinks with `SyncStatus.vue` in the same PR), which is exactly the collision
this claim exists to avoid. No `NotificationType` and no ability key moves.

**Namespaces spent:** nothing from either. No migration, no ADR — **`0022` stays free** and `0021`
stays Lane B's. The invariant lands in the gate files' own comments, replacing ~40 lines that
currently assert the opposite.

### What is already measured, so the plan is not built on the row's own framing

⛔ **THE ROW'S PROOF MUTATION IS INERT AND CANNOT BE USED.** It offers *"delete `min-width: 0` from
the leaderboard name cell (`achievements/Index.vue:452-459`) and every scan still passes"* as its
evidence. That rule **also** carries `overflow: hidden`, which already resolves `min-width: auto` to
0, so the declaration is redundant: **343/343 with it and without it**, measured in Chromium. The
scan passing is the *correct* result, not a symptom. **The gate is still inert — but that is proven
structurally by the clip, not by this demonstration, and the row's evidence was never evidence.**

✅ **THE SUBSTITUTE, MEASURED AT 268px AGAINST A 1px TOLERANCE** (~90×, against the 3px the
achievements rule manages even with `overflow: hidden` removed): delete `overflow-wrap: anywhere`
from `.dns__code` (`DnsRecordBlock.vue:127`) and scan `/domains` at 375px. Its own comment states why
it is load-bearing — *"The token has no break opportunities of its own"* — `E2eSeeder.php:1017-1047`
guarantees a **64-hex** `verification_token` on an **unverified** domain (the state that renders the
block), and `/domains` is already in the 16-page list. ⚠️ **`min-width: 0` sits beside it there too;
check which half is load-bearing rather than assuming, because that is precisely the shape that made
the achievements mutation a no-op.**

✅ **RECONNAISSANCE: 11 pages × 3 viewports, `.app-shell__content` overflow of 0 EVERYWHERE.** The
fixed gate reddens nothing that exists today — including the `Checklist` 44px `::before` overhang on
`/dashboard`, which `Checklist.vue:222-246` warns about by name and which was the top false-positive
risk. `.app-shell__content` is present on every page, so a selector-drift guard is not vacuous.

### Prediction, written before the run

- **Vitest 129/2,175 · axe 42/299 · Pest 4515/19,161 · PHPStan · four lint gates · `openapi.json`**
  unmoved. ⚠️ **Unless M15 lands first**, which moves `public-runtime` to ~35/765 and the total to
  ~130/~2,196 — **so the Vitest number is re-measured against whatever `origin/main` says at the
  time, never against 129.**
- **E2E `551 passed + 10 skipped`, no flaky line, and no new test count** — the change is to an
  assertion inside existing tests, not new cases.
- ⚠️ **The prediction I most expect to be wrong: that CI agrees with my local survey that nothing
  overflows.** CI renders at a different DPI with different fonts, and the survey ran against
  `DemoSeeder`-plus-`E2eSeeder` data on a slow local stack. **A page CI flags and my survey did not
  is a real defect this gate was built to find, not a false positive — file or fix it, do not widen
  the tolerance.**

---

## RELEASED — the share panel scans mid-transition (merged as PR #206, `9fb1bed`, 6/6 green)

**Namespaces after M16:** nothing spent. ⚠️ **ADR `0021` is LANE B's** (M15's
`0021-respondent-scoped-device-outbox.md`), so **next free overall is `0022`** and Lane A's block is
`0022-0025`. `0010` stays reserved for H1d, `#16` stays free, ADR-0016's next sub-decision stays
`§D34`, migration block `2026_08_17_000109` unspent.

**Every claimed file was edited; none was released untouched** — the M8/M11 shape did not recur — and
**the claim was never extended.** Both `docs/` files, `playwright.config.ts`, `support/axe.ts` and
`builder-axe.spec.ts` all moved. **Nothing was spent from either shared namespace.**

**The prediction held on every gate, including the one it flagged as most likely to be wrong.** CI
Pest **4515 / 19,161**, PHPStan, the four lint gates, `openapi.json`, Vitest **129 / 2,175** and axe
**42 / 299** all unmoved — asserted from the diff (no `.vue`, no `packages/`, no `resources/`, no
PHP) and proved independently by CI's own jobs. **E2E `551 passed + 10 skipped`, no flaky line**, and
`reducedMotion` at context level was **inert for the ten specs that never call `forceTheme`**, which
the claim had named as its least confident line. All six jobs carried real step counts (18 · 12 · 11
· 20 · 11 · 16).

⛔ **THE ROW WAS FALSIFIED AND THE FIX IS NOT THE ONE IT ASKED FOR — 35-FOR-35.** `#1674e9` is not a
token; it is `--mds-primary-600` `#0E6FE8` (4.71:1, **passing**) composited at ~96.5% over white
during `.mds-modal-enter-active`'s 400ms fade. **Retuning the token, which the row invites, would
have darkened a passing colour to hide a broken gate.**

⚠️ **AND MEASURING IT IN THE RUNNING APP MADE IT WORSE THAN TWO CI RUNS HAD SHOWN.** At the instant
`assertClean` hands the page to axe: backdrop **opacity 0.5138**, three animations running, button
composited to `#83b5f3` — **2.13:1**. CI caught it at 96.5% (4.45) purely by where in the fade its
scan landed. ⛔ **So the defect is a CONTINUUM, not an intermittency** — the ratio is a function of
elapsed fade time, from 2.13 to 4.71, and the case passes only after the full 400ms. *That* is why it
read as flaky and why the retry "worked". **A defect that reports a different number every run is not
thereby intermittent; it may simply be sampled at a different point on one curve.**

⚠️ **THE PROJECT'S OWN FIX FOR THIS CLASS WAS ALREADY PRESENT AND STRUCTURALLY COULD NOT REACH IT.**
`forceTheme` has emulated reduced motion since J1e and its docblock names this exact hazard — but
every incident it was written for was a transition **the scan itself started** (a theme flip, an
un-hover), so one more paint sufficed. This one was started by the **test**, on the way in, before
`assertClean` was called at all. **Ask which actor started the animation, not merely whether the code
waits for one.**

✅ **BOTH HALVES PROVED, EACH WITH A CONTROL, BECAUSE THE MERGE RUN COULD PROVE NEITHER.** A green
E2E job is equally green with and without the flag, and a passing scan is equally passing whether or
not the timing fix works. So: the same probe with `reducedMotion` on reads opacity **1**, empty
chain, `#0e6fe8`, **4.71:1**; the real spec ran green locally on *"share panel, live link (light)"* —
the precise case that failed CI twice — in 28.0s; and `failOnFlakyTests` was proved against a
deliberately flaky spec at **red with the flag, green without it, printing `1 flaky` in both**. Full
table in D2.

⚠️ **ONE THING THE FIX'S OWN PROBE JUSTIFIED AFTER THE FACT.** `settleAnimations` filters infinite
animations because awaiting one can never return — designed defensively, before anything was
measured. The post-fix probe then found **exactly one animation still running**, and it is infinite.
**The guard was speculative when written and load-bearing when measured.**

---

## SUPERSEDED CLAIM (kept for the record) — the share panel (`m16-axe-scan-timing`)

Opened: 2026-08-26. **Numbered M16, not M15**: Lane B cut `m15-respondent-session-scope` on
2026-08-25, and although it carries zero commits, two different rows answering to "M15" is the
shared-namespace hazard 7(g) exists for. M17 is reserved for the row below it.

Row: `docs/feature-backlog.md:1461` — *"The builder's share-panel live link fails WCAG AA contrast
at 4.45, and the e2e gate reports it as FLAKY rather than red"* (`major`, Design system).

**Files:** `playwright.config.ts` · `tests/e2e/support/axe.ts` · `tests/e2e/builder-axe.spec.ts` ·
`docs/feature-backlog.md` · `docs/claims/decisions.md` · `PROGRESS.md` (Lane A's block only).

**Shared artefacts taken:** `playwright.config.ts` (repo root, in neither lane's column in 7(b) —
claimed rather than assumed) · `tests/e2e/support/axe.ts` and `tests/e2e/builder-axe.spec.ts` (the
e2e tree, "claim first") · two `docs/`. **`ci.yml` is NOT taken** — the retry policy lands in the
Playwright config, not in the workflow.

**Paired files taken:** none. Nothing here adds a `clip: rect(0 0 0 0)`, so
`clipped-node-containment.test.ts` cannot fire; no `NotificationType` and no ability key moves, so
neither PHP parity gate is reachable. ⚠️ Lane B's `SyncStatus.vue` row still takes the clipped-node
test with it — that is unaffected by this claim and remains Lane B's to take.

**Namespaces spent:** nothing from either. No migration, so Lane B's block stays
`2026_08_17_000109`. No ADR — `0010` stays reserved for H1d, `#16` stays free, ADR-0016's next
sub-decision stays `§D34`. The invariant lands in the two gate files' own docblocks.

⚠️ **AMENDED MINUTES AFTER PUBLISHING, AND THE AMENDMENT IS THE POINT.** This claim first said
*"`0021` stays free"*. It was already false when written: Lane B's M15 claim (`8d49842`) landed
**between my `git fetch` and my `git push`** and spends `0021` on
`0021-respondent-scoped-device-outbox.md`. My own briefing had allocated `0021-0025` to Lane A, so
two lanes each held a correct-looking map to the same number — the ADR-0017 collision shape 7(g)
exists for, arriving live rather than as a cautionary tale. **Lane B pushed first, so `0021` is
theirs; Lane A's block is `0022-0025` and next-free-overall is `0022`.** Nothing was lost only
because this row spends no ADR at all. **A claim that has gone stale is worse than no claim, so it
is corrected at source rather than in a note further down.**

### ⚠️ ONE CROSS-LANE EFFECT LANE B SHOULD KNOW ABOUT, STATED RATHER THAN DISCOVERED

`reducedMotion: 'reduce'` in `playwright.config.ts` `use:` applies to **every** e2e spec, including
`tests/e2e/public-runtime-offline.spec.ts`, which M15's claim records as *"grepped, not edited —
the design preserves all nine of its assertions."* That assertion still holds as far as I can
measure: no e2e test in this repo asserts an animation or a transition (grepped across all sixteen
spec files), and its `:103` reference to a "sync transition" is a state change, not a CSS one. But
**the file is reached by my diff without appearing in it**, which is the paired-file symptom 7(b-bis)
tells us to treat as structural rather than as a flake. Recorded here so that if M15's run reddens
there, the first place to look is this config line and not their own change.

### ⛔ THE ROW'S FRAMING IS FALSIFIED, AND THE FIX IS NOT A COLOUR CHANGE

`#1674e9` **is not a token in this repository.** The button is `--mds-color-action-primary-bg` →
`--mds-primary-600` → `#0E6FE8` (`theme-overrides.css:125`), which is **4.71:1 against white and
passes.** `#1674e9` is what `#0E6FE8` becomes when composited at ~96.5% opacity over the white page:
`0.965·0E + 0.035·FF = 16`, `0.965·6F + 0.035·FF = 74`, `0.965·E8 + 0.035·FF = E9` — to the byte, on
all three channels. That opacity is `.mds-modal-enter-active` (`Modal.vue:481-483`) still running its
`--mds-duration-slow: 400ms` fade when axe samples.

**So this is a scan-timing defect, and retuning the token would darken a passing colour to hide a
gate bug.** `forceTheme` already emulates reduced motion (`support/axe.ts:62`) and its own docblock
at `:59-61` names this exact hazard — but it runs *after* the modal opens, and a CSS transition keeps
the duration it started with, so emulating afterwards cannot collapse one already in flight.

### Prediction, written before the run so the measurement has something to disagree with

- **Vitest 129 files / 2,175 · Storybook axe 42 suites / 299 · PHPStan CI `[OK]` / local 18 by file
  list · four host lint gates 97 · 111 · 31 · 111/119/0 · `openapi.json` byte-identical** — all
  unmoved, and asserted rather than re-measured for the axe and PHP gates, because no `.vue`, no
  `packages/` source, no `resources/`, and no PHP file is touched.
- **CI Pest 4515 / 19,161** unmoved (2 pre-existing warnings), same reason.
- **E2E reads `551 passed + 10 skipped` with no flaky line** — and unlike M13's and M14's identical
  reading, that is an improvement rather than a coin-flip, because `failOnFlakyTests` makes the
  absence of the line load-bearing instead of lucky.
- ⚠️ **The prediction I most expect to be wrong**: that `reducedMotion: 'reduce'` at context level is
  inert for the ten specs that never call `forceTheme`. Grepping found no e2e test asserting motion,
  but "no test asserts it" is not "no test depends on it", and the honest answer comes from the run.

---

## Template

**Moved to [TEMPLATE.md](TEMPLATE.md) in M36, and deliberately not duplicated here.** It lived as
a copy in this file and another in `lane-b.md`, which is two copies of one fact free to drift —
the defect Standing Rule 7(b) records about the lane boundary and `docs/gate-baselines.md` ends
for gate numbers. Restating it here would be the defect, not a convenience.

It gained two required fields in M36: **Evidence verified** and **Remedy verdict**. They are
separate because a row's evidence and its remedy are separately trustworthy, and four consecutive
rows — M30, M31, M32, M34 — had sound evidence and a broken prescribed fix. The reasoning and the
count are in that file.
