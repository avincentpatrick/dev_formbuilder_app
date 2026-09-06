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
`major` rows plus three consecutive increments filing none** (2026-08-28, D5 below).

---

## OPEN

### D21 — `docs/pipeline.md` is merge-gated but sits in no `paths-ignore`, so every close-out now triggers a full CI run. Accept the cost, exempt it, or split the file?

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

---

### D20 — The service worker caches a credential-bearing resume shell, where the credential IS the cache key. Purge it, keep it, or split the difference?

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

### D19 — A Reviewer holds `submissions.create` and can encode on no form. `M77` made every document say so. Should the ROLE now gain encoding, or is documenting the gap the whole answer?

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

### D18 — The proof-of-work solver yields every 5000 candidates against a 120000 search space, and nothing has ever decided that number. Keep 5000, derive it, or make it configurable?

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

### D17 — A local container Pest run silently omits 40 test files. `M76` made that loud, which makes every local run RED. Keep it, soften it, or change how the suite is run?

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
### D15 — `D13`'s one-hub-row cap is now the binding constraint on batch composition, and it is stricter than its own purpose. Keep it, relax it to per-file, or re-derive the hub set per batch?

**Filed 2026-09-05 by Lane A, during `M72`, at the moment the cap decided a batch that value had not.**
Recorded here rather than as a row because `D13` is a user decision and an increment does not re-scope
one of those on its own judgement.

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

---

### D16 — The `npm audit` judge makes a required status check green when the registry is unreachable. Accept it, isolate it, or keep the hard block?

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

### D14 — The compliance spec promised audit events for deleting and restoring a submission, and there is no delete or restore surface at all. Build it, or record it as not built?

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

### D12 — `D5`'s bar is now measurable, and it reads MET by nine increments rather than three. End the M-series, or keep going?

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

### D11 — Two byte-serving routes gate on a subject their own comments question. Leave them, or move one?

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

### D10 — `§9` item 9's escalation has fired. Adopt the value-object forcing device, or keep answering per surface?

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

### D1 — Should the sixteen synchronous dispatch listeners become `ShouldQueue`?

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

### D3 — ADR-0020 §D7 approves *"4th of 12"* for every member. Three other surfaces withhold the twelve. Which moves?

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

### D4 — An archived webhook envelope has no form to be scoped to. Which permission reads it?

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

### D8 — A tracker surgery triggers no post-merge run at all. Which way should `ci.yml` regain the trunk observation?

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

### D9 — Should the legacy client's identity be rewritten out of git history as well? **RECOMMENDED AGAINST.**

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

---

## ANSWERED

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
