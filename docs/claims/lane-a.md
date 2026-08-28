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

## Status: NO ACTIVE CLAIM — `M43` is merged and the lane holds nothing forward

**`M43` is merged (PR #233, `c09c7ef`, 6/6 green).** Lane A holds no active row and pre-claims no forward
number. The next row is taken under Rule 7(f), and the claim is written here and **pushed** before the
first file is opened.

⛔ **RUN `php scripts/state.php` FOR EVERY NUMBER.** Increment, ADR, migration prefix, exceptions-log
entry, open rows, open decisions, and how far behind the trunk `docs/gate-baselines.md` has fallen.
Nothing in this file or in `PROGRESS.md` is the authority for any of them any more.

✅ **`CLAUDE.md` IS THE IMPERATIVE LAYER AND IS AUTO-LOADED.** Read it before this file. It carries no
numbers at all, and `tracker-lint` R8 keeps it that way.

⛔ **A REMOVAL OF MORE THAN 200 LINES FROM `PROGRESS.md` NEEDS `[tracker-surgery]` AT THE START OF A
LINE IN THE COMMIT MESSAGE.** Mentioning it mid-sentence deliberately does not count.

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
  **"11+"**. **Measured: 17 occurrences of `dev_pk_new` / "Purok Kalusugan" across 9 tracked files** —
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
