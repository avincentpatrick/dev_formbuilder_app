# The claim template

**One authority, referenced rather than copied.** This file exists because the template lived as a
duplicate at the bottom of both `lane-a.md` and `lane-b.md`, and two copies of a fact drift apart —
which is the exact defect Standing Rule 7(b) records about the lane boundary itself, and the exact
defect `docs/gate-baselines.md` exists to end for gate numbers. A lane file that restates this
template is the defect, not a convenience: point at this file instead.

⚠️ **`lane-b.md` still carries its own copy at the time of writing, and that is deliberate.** Lane A
may not edit Lane B's claim file — one writer each is what makes a claim conflict structurally
impossible rather than merely unlikely. Lane B adopts this file on its next increment by deleting its
copy and linking here.

---

## Opening a claim

```
## Status: ACTIVE CLAIM — <row name> (<branch>)

Taken <date>. Branch <branch>, cut from origin/main at <sha>, PR into main.
Row: <the backlog row, quoted enough to identify it, with its file:line>.

### Evidence verified
<each file:line citation in the row, checked against the MERGED tree — held / moved to X / false.
 Say which, per citation. A row whose citations you have not opened is a row you have not read.>

### Remedy verdict
<the row's PRESCRIBED fix, measured: works / wrong (and how) / structurally impossible / none offered.
 Measure it BEFORE writing the test, not after.>

Files: <every file to be edited, repo-relative>.
Shared artefacts taken: <docs/…, openapi.json, phpunit.xml, PROGRESS.md — or "none">.
Paired files taken: <7(b-bis) entries, and the other half of each — or "none">.
Namespaces spent: <migration prefix / ADR number / §D<n> — or "nothing from either namespace">.
Prediction: <what you expect the gates to do, written BEFORE the run so it can be measured
             against rather than explained afterwards. Name the one you most expect to be wrong.>
```

## Releasing it

```
## RELEASED — <row name> (merged as PR #<n>, <sha>, 6/6)

<what was actually taken; whether every claimed file was edited; anything the claim was extended to
 mid-build, each of which was its own pushed commit before the file was opened; and how the
 prediction fared, including the parts that were wrong.>
```

---

## Why `Evidence verified` and `Remedy verdict` are separate fields

They were added in **M36**, and the count is the argument. **A row's evidence and its remedy are
separately trustworthy**, and the remedy is the half nobody checks:

| Row | Evidence | Prescribed remedy |
|---|---|---|
| M30 | held | **wrong in both stated mechanisms** |
| M31 | held | **wrong in its probe** |
| M32 | held | **wrong — `assertPushed` is at-least-one-match**, known before the first line was written |
| M34 | every citation held | **structurally impossible on two of three routes** |
| M36 | held, and the row **understated itself** — four gates, not two | none offered, so nothing to disprove |

Four consecutive rows with sound evidence and a broken remedy. A claim that records only "the row is
real" has checked the half that keeps being right.

⚠️ **AND CHECK WHETHER THE ROW UNDERSTATES ITSELF.** M25 named one exposed page and there were four.
M34's row named one route and a sibling instance sat beside it. M36's named two gates and there were
four. **The row is a floor, not a census** — three of these were found by opening the citation and
looking at what sat next to it.

## The rules a claim is asserting

The protocol is **Standing Rule 7**, not this file. In one line: **a claim is a *pushed* commit** —
write it, `git push origin HEAD:main`, and only then open the first file. An unpushed claim does not
exist; M14 proved that by writing a perfect one nobody could see.

Before opening any shared or paired artefact, `git fetch` and read **both** lane files in full — not
their `## Status` lines, because a forward queue is a claim and does not live under that heading.

`php scripts/preflight.php --lane=a|b` asserts most of the above mechanically, including the parts
that have been got wrong repeatedly: the branch base, whether the claim is actually published, whether
another suite is running, and whether `PROGRESS.md` is safe to splice.
