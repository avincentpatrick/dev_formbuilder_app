# CLAUDE.md — the imperatives

**This file is the imperative layer and nothing else.** Every *why* — the incident that produced a
rule, what it cost, who decided it — lives in `PROGRESS.md` under `## Standing Rules`. Neither file
restates the other; when they disagree, `PROGRESS.md` holds the reasoning and this file holds the
instruction.

⛔ **THIS FILE CONTAINS NO NUMBERS, BY CONTRACT.** No increment number, no migration prefix, no
sub-decision id. Every one of them is derived — see *Numbers*, below — and a number written here is a
copy that will be wrong within days. `scripts/tracker-lint.php` R8 enforces it as a merge gate, and
`scripts/state.php --check` reports it locally.

---

## Start every session

```
php scripts/preflight.php --lane=a
php scripts/state.php
```

`preflight` asserts the branch base, whether your claim is published, whether another suite is
running, and whether the tracker is safe to splice. It exits non-zero on anything blocking. Run it
again with `--with-gates --with-pint` before you push.

`state.php` derives the numbers and prints what is stale. Neither is a CI step, and neither belongs
in `composer run quality` — do not "fix" that by registering them.

## The trunk

- `main` is the trunk. `git fetch && git checkout -b <branch> origin/main` — **never** from local `HEAD`.
- PR into `main`. Self-merge on **6/6 green**, with each job's step count parsed individually.
- One worktree per agent. Never two sessions in one checkout. Never `checkout` a branch another
  worktree holds.

## Your claim is a pushed commit

- Write it in `docs/claims/lane-a.md` or `lane-b.md` — **one writer each**, and never the other's.
- `git push origin HEAD:main` **before you open the first file**. An unpushed claim does not exist.
- Before touching any shared or paired artefact, `git fetch` and read **both** claim files in full —
  not their `## Status` lines. A forward queue is a claim and does not live under that heading.
- The template and its required fields are in `docs/claims/TEMPLATE.md`. Point at it; never copy it.
- **Verify the row's evidence, its remedy and its premise separately — three answers, not one.**
  Evidence is whether the citations still resolve. Remedy is whether the prescribed fix works, measured
  before you write the test. **Premise is what the row believes about the world *around* the defect** —
  who owns a file, whether a second copy exists, whether a precondition still holds, which cases it
  carved out — and it is the half that rots while the code stays exactly as described. `preflight`
  refuses an active claim missing any of the three.
- **A row is a floor, not a census:** open every citation and look at what sits next to it.

## Numbers

⛔ **Derive them. Never read them from a sentence, including a sentence in this repository.**

```
php scripts/state.php            # increment · ADR · migration prefix · open rows · decisions
php scripts/state.php --json     # the same, for a machine
php scripts/state.php --check    # exit 1 when a generated declaration disagrees with the tree
```

The increment number comes from the `## RELEASED` headings of both claim files, truncated at each
file's `## Template` heading, and is cross-checked against merged pull-request titles. Reading the
current maximum is **not** a reservation — claim the number and push it before you use it.

⚠️ **The one namespace fact a directory listing cannot carry:** `docs/adr/` has a single gap, and it is
**reserved for H1d, the OCR provider bake-off — not free.** `state.php` names it on every run and
refuses to measure if a second gap appears, because a gap is either a reservation or a deletion and
nothing on disk can tell them apart.

Cite an ADR by **filename**, never by bare number, in any document another lane might also edit.

## Gate numbers

⛔ **They live in `docs/gate-baselines.md`, and a hand-off or claim that restates them is the defect.**
Regenerate it with `php scripts/gate-baselines.php` from a post-merge run on the trunk; reference the
file, never copy out of it. `state.php` reports how far behind the trunk that file has fallen.

## Taking a row

- The queue is `docs/feature-backlog.md`. Read `docs/backlog-triage.md` first for the ranked order —
  and treat its counts as a dated census, not as the tree. `state.php` counts the tree.
- Decisions live in `docs/claims/decisions.md`. When something is genuinely the user's call, **append
  the question, two or three real options and your recommendation, then take the next row in the same
  turn.** Never idle on a question, and never re-ask an open one.
- **A held row is out of scope, not pending.** Never report it, count it, schedule it, or ask about it.
  It re-enters only on the user's explicit signal.
- Compute progress over buildable scope only. When a phase's remaining rows are all held, start the
  next phase without asking.

## Where each gate actually runs

| Gate | Where |
|---|---|
| Pint | **Host, bare `vendor/bin/pint --test`.** The scoped form every hand-off used to prescribe misses `scripts/`, `config/`, `routes/` and `bootstrap/` entirely. |
| PHPStan | Container only, and it scans `app`, `database` and `routes` — a `scripts/`-only or test-only diff cannot move it. Say that instead of quoting an unchanged number. |
| The lint gates | **Host.** Inside the app container `RecursiveDirectoryIterator` descends the Windows bind mount only partially, and a gate with no floor reports `passed` while blind. |
| Vitest | Cannot run on the host. Container, `--pool=forks`. |
| Storybook axe | Cannot run in the musl node container — glibc Chromium fails `ENOENT` with the binary present. |
| E2E | `docker compose run --rm e2e`. A full local run takes hours at one worker, so run the specs your diff reaches and **say which**. |

## Prove a gate you just wrote

⛔ **A green gate proves nothing about a gate you have just written. Only a deliberate defect that
turns it red does.** Use `php scripts/mutate.php` — it takes its tokens from files so no shell can eat
them, aborts unless the sha256 moves, refuses to run beside a live suite, and restores by byte
comparison rather than `git checkout --`.

**Commit the mutation.** Left in the working tree, a diff-based check still sees the unmutated file at
the parent commit and accidentally gives the right answer.

## Traps on this host

- **`HEAD~1`, never `HEAD` followed by a caret.** PHP's `exec()` runs through `cmd.exe`, where the
  caret is the escape character, so the caret form silently resolves to `HEAD` itself — and
  `rev-parse --verify` still succeeds, so no guard fires.
- **`wc -l`, never `grep -c`** — `grep -c` exits non-zero on zero matches and reads as a failure.
- **A pipe hides the exit status.** Capture it, or read `PIPESTATUS`.
- **`/tmp` is shared between lanes and is invisible to Windows PHP** — use the session scratchpad.
- **The tool layer collapses doubled backslashes**, so build an escape as a character code rather than
  writing one. A quoted heredoc is otherwise safe; verify the bytes afterwards.
- **Never split lines on the regex newline class without the unicode modifier.** It matches a byte that
  occurs inside common UTF-8 emoji, and silently shifts every line number after the first one.

## Merging

- **6/6 green, each job's step count read individually.** A job reporting no steps never acquired a
  runner and proves nothing.
- **A push filtered out by `paths-ignore` produces no run at all.** Read that as *correctly skipped* —
  never as *pending*. Require a positive count of six completed checks, never merely "none incomplete".
- ⛔ **A squash merge must land the surgery marker on a line of its own, and neither default does it.**
  An empty `--body` discards the commit bodies outright; GitHub's *default* body renders each commit
  subject as `* <subject>`, which demotes the marker off line start — the trunk then carries the text
  and the gate matches nothing. **Pass an explicit `--body` whose first content line is the marker.**
  The gate also accepts it behind a single `* `, `- ` or `+ `, so a web-UI merge arms it too; an
  indented or mid-sentence mention still does not, deliberately.
- ⛔ **Never put the marker in the PR title.** The merged-title cross-check in `scripts/state.php`
  anchors on the increment-number prefix, so a marker in front of it silently drops that pull request
  out of the second, independent source for the increment number — trading the surgery gate for a
  numbering collision.

## The tracker

- Status bullets are **pointers**. An increment's full record belongs in the claim file and in
  `PROGRESS_ARCHIVE.md`.
- Each lane edits **only its own status block and its own hand-off line**, and never reformats the
  other's.
- ⛔ **A large removal from `PROGRESS.md` requires the surgery marker at the start of a line in the
  commit message, and it must survive onto the trunk.** Mentioning it mid-sentence deliberately does
  not count. **Large is measured in bytes as well as lines, and the byte half is the one that catches
  this file** — its hand-off and status bullets are single lines thousands of bytes long, so a few
  dozen of them outweigh hundreds of ordinary ones. `scripts/tracker-lint.php` holds both limits and
  prints both deltas on every run; read them there rather than restating either here.
- **Split by pre-measured line index, never by search.** These files contain verbatim examples of their
  own anchors; that is how a search once deleted a thousand lines and merged green.
- ⛔ **Prove the move with `scripts/tracker-surgery.php`, and never by hand.** It holds the assertions —
  a counted multiset of line hashes, exact byte conservation with the added bytes stated rather than
  inferred, the paths touched, and an independent slice hash — and it **refuses with a distinct exit
  status rather than passing** when it cannot measure. Run it while both files are still uncommitted:
  the paths arm reads the working tree, so it sees nothing once the change is committed. Every surgery
  before it hand-rolled this check and threw it away, and most of those checks were wrong on their first
  run against a correct tree. Never prove a move by "the archive got bigger", and never with a tolerance.
- **Do not cite line numbers in a file you are editing.** Counts and filenames are stable; line numbers
  are not.

## Closing out

1. Close the row in `docs/feature-backlog.md`, and **file anything you decided not to fix, the moment
   you decide it.**
2. Release your claim in `docs/claims/lane-a.md`, recording how the prediction fared — including the
   parts that were wrong.
3. Regenerate `docs/gate-baselines.md` from your own post-merge run.
4. Update only your own status block, then `php scripts/next.php --lane=a --write`.
5. End with a three-to-five bullet status and the bare next-prompt line.

## Signalling

- Before **any** pause, say in one line what is running, roughly how long, and that nothing is needed
  from the user yet. Use the word *wait*.
- Say **done** when it returns, before the results. Mark **your turn** when the turn is genuinely
  theirs. Never end ambiguous.
- The next prompt is owed when the **work** is done, not when the user asks a question. A question is
  not a stop instruction.

## Design

- One shared design system across every page — app shell, component library, tokens. Any exception
  needs a written rationale.
- Every page ships fully styled from shared components. Do not re-ask "styled or unstyled?".
