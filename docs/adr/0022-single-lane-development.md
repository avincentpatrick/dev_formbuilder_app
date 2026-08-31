# ADR-0022 — Development collapses to a single lane, and the claim protocol outlives the parallelism that created it

- **Status:** Accepted
- **Date:** 2026-08-31
- **Increment:** M50
- **Supersedes / amends:** **Standing Rule 7** in `PROGRESS.md` — *"TWO AGENTS = TWO WORKTREES, ONE
  DATABASE"* (added 2026-08-12, user decision) — which is superseded **in place** rather than deleted,
  and `docs/claims/TEMPLATE.md`'s note about Lane B adopting the shared template *"on its next
  increment"*.
- **Related:** `scripts/tracker-lint.php` (R6) · `scripts/preflight.php` · `scripts/next.php` ·
  `scripts/state.php` (`CLAIM_FILES`, deliberately unchanged) · `docs/claims/lane-b.md` (retained as an
  archive) · `docs/feature-backlog.md` (the `fb-lane-c` row this closes)

---

## Context

Standing Rule 7 made parallel work safe by giving each agent its own worktree and its own claim file,
with one writer each. It worked: two lanes ran concurrently for roughly two weeks and released
increments in both. The rule is not being retired because it failed.

It is being retired because **the parallelism stopped and the machinery did not.** Lane B's last
release was `M35` on 2026-08-27; every increment since has been Lane A's. What remained was a protocol
whose costs were still being paid daily and whose benefit had gone to zero:

**Three worktrees, one of which had never been a lane at all.** `git worktree list` returned
`dev_formbuilder_app`, `fb-lane-b` and `fb-lane-c`. Lane C was cut at an `M14`-era commit and never
used: no `docs/claims/lane-c.md` anywhere, `preflight` accepting only `a|b`, no `M`-series release
attributable to it, and — measured this increment — **`lane-c-bootstrap` never existed on the remote
either.** It was noise in the one command the protocol told every session to run before numbering, and
that command had decided the increment number three times running.

**`main` was checked out in `fb-lane-b`.** Git refuses to check out one branch in two worktrees, so
every `gh pr merge` run from Lane A's worktree errored locally — *after the merge had already landed
on GitHub*. A merge that reports failure while having succeeded is the worst available outcome, and it
was a permanent, structural property of the layout rather than an intermittent fault.

**The protocol's own incident list had grown longer than its benefit.** `M33` recorded two Lane B
writers sharing one worktree, with a `checkout` nobody issued relocating live uncommitted work, and
concluded that *"Rule 7 assumes ONE WORKTREE PER LANE: the claim file protects the FILES, and nothing
protects the CHECKOUT."* `M48` recorded `git push origin HEAD:main` — Rule 7(g)'s own prescribed
command — pushing a whole branch and putting a tracker surgery on the trunk with no squash merge.

⛔ **AND THE INCIDENT RECURRED WHILE THIS VERY INCREMENT WAS BEING PLANNED, WHICH IS THE ARGUMENT IN
ITS STRONGEST FORM.** A second session (PID 24104) was live in Lane A's worktree finishing `M49`'s
close-out when this session started. It wrote `docs/claims/lane-a.md` and `PROGRESS.md` minutes after
this session's first read, and moved `HEAD` from `m49-r7-event-base` to `m49-closeout` underneath it.
`git status` was clean at session start and was not clean shortly after. **Nothing was lost, only
because that session committed before it switched** — which is precisely the coin-flip `M33` described.
The protocol that exists to make concurrent work safe had, by this point, produced three separate
incidents and zero concurrent increments in four days.

## Decision

**One lane. One worktree. One writer.**

- `c:\laragon\www\dev_formbuilder_app` is the only worktree. `fb-lane-b` and `fb-lane-c` are removed.
- `docs/claims/lane-a.md` is the only claim file that is *written*.
- Standing Rule 7 is **superseded in place, not deleted.** It carries a banner at its head and its
  text stays, because `CLAUDE.md` holds the instruction and `PROGRESS.md` holds the reasoning — and the
  reasoning is what makes the next person hesitate before reintroducing two lanes.

### ⛔ `docs/claims/lane-b.md` IS KEPT, AND DELETING IT WOULD HAVE BEEN A NUMBERING DEFECT

`scripts/state.php` derives the increment number from the `## RELEASED` headings of **both** claim
files, cross-checked against merged pull-request titles. `lane-b.md` holds ten releases — `M15`, `M18`,
`M21`, `M22`, `M24`, `M26`, `M29`, `M33`, `M34`, `M35` — that exist in no other machine-readable place.

**Removing it would have silently lowered the derived maximum**, and a mis-truncated scan fails in
exactly that direction: it returns a *lower* number, and a low maximum is a collision rather than an
error. `state.php`'s `CLAIM_FILES` therefore still names both files and is unchanged by this ADR.
`lane-b.md` becomes a **read-only archive**: it is still read for numbering, and it is never written.

Its own copy of the claim template is deleted and replaced with a pointer to `docs/claims/TEMPLATE.md`
— the one thing `TEMPLATE.md` always said Lane B would do on its next increment. There is no next
increment, so this one does it.

## Consequences

**A retired lane is now enforceable rather than conventional.** `tracker-lint.php` R6 took its lanes
from a hardcoded `['A', 'B']`; it now takes them from a `HANDOFF_LANES` const, **and additionally
compares the whole set** — it counts every `**LANE <X> NEXT PROMPT` marker and requires the total to
equal the list. The per-lane loop alone is blind to a marker for a lane that no longer exists, because
it only ever looks for the lanes it already knows about. Both halves were proven by deliberate defect,
in both directions, before and after the change.

⚠️ **The coupling that would have reddened the trunk is the shape this project keeps meeting.** R6
required the Lane B marker *exactly once*, so removing Lane B's hand-off without amending R6 in the
**same commit** takes `main` red — the identical shape as `EXPECTED_CROSS_FILE_NEXT_SESSION`, which
`M41`'s surgery had to lower in its own commit for the same reason. It was measured rather than
reasoned about: deleting the line against the unedited gate failed R6 alone, one check in one rule
group, with R3 untouched.

**`preflight.php` and `next.php` each carried their own two-entry `LANES` map** and now carry one.
`--lane=b` is refused rather than silently accepted. The lane column in the hand-off is gone; the
hand-off itself is still generated, never hand-written.

**What this ADR does not do.** It does not rewrite history, delete `lane-b.md`, or change how the
increment number is derived. It does not touch `state.php`. It does not claim the two-lane protocol was
a mistake — it was a correct answer to a question the project no longer asks, and if parallel work
resumes, this ADR is the thing to supersede.

⚠️ **The claim protocol survives the parallelism that created it, and that is deliberate.** A claim
being a *pushed* commit is now doing a different job: not preventing two lanes from colliding, but
preventing **two sessions in one lane** from colliding — which is the only collision that has actually
happened in the last four days, three times. Rule 7(g) is retained in full and is load-bearing.
