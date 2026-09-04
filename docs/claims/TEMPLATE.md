# The claim template

**One authority, referenced rather than copied.** This file exists because the template lived as a
duplicate at the bottom of both `lane-a.md` and `lane-b.md`, and two copies of a fact drift apart —
which is the exact defect Standing Rule 7(b) records about the lane boundary itself, and the exact
defect `docs/gate-baselines.md` exists to end for gate numbers. A lane file that restates this
template is the defect, not a convenience: point at this file instead.

✅ **`lane-b.md`'s duplicate is gone as of `M50`, and the way it went is the point.** This file used
to say Lane B would adopt it *"on its next increment"*. **Lane B had no next increment** — it was
retired before one arrived, so a sentence that read as a plan was in fact a deferral with no owner
and no deadline, which is the `D6` shape exactly. `M50` deleted the copy itself, crossing the
one-writer boundary deliberately and saying so, because the rule that forbade it is the rule that
increment abolished. See `docs/adr/0022-single-lane-development.md`.

⚠️ **`lane-b.md` keeps its `## Template` HEADING even though the template is gone.**
`scripts/state.php` truncates that file at the heading when deriving the increment number, so the
anchor stays and the edit is provably neutral to the numbering — measured before and after.

---

## Opening a claim

```
## Status: ACTIVE CLAIM — <row name> (<branch>)

Taken <date>. Branch <branch>, cut from origin/main at <sha>, PR into main.
Row: <the backlog row, quoted enough to identify it, with its file:line>.

### Evidence verified
<each file:line citation in the row, checked against the MERGED tree — held / moved to X / false.
 Say which, per citation. A row whose citations you have not opened is a row you have not read.>

### Premise verified
<what this row believes about the WORLD AROUND the defect — who owns a file, whether a second copy
 exists, whether a precondition still holds, who may edit what — and whether that is still true.
 NOT the citations; those are the field above. This is the sentence that rots while the code does not.>

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

## Why `Premise verified` is a THIRD field and not a sentence inside the first

Added in **M69**, and — like the two below — the count is the argument, not the tidiness. **A row's
premise is what it believes about the world *around* the defect**, and it is the half that rots while
the code stays exactly as the row describes it:

| Row | Evidence | Remedy | Premise |
|---|---|---|---|
| M45 | held | implementable | **false** — a file framed as *"the second copy of a record that already has a home"* had **zero** overlap with the file it named, so the deletion its framing invited would have destroyed the only copy |
| M60 | held in kind | implementable | **expired** — *"the section has two writers"* was filed 2026-08-29 and falsified on 2026-08-31 when `M50` retired Lane B |
| M67 | held | — | **three of four rows wrong** |
| M68 | held | — | a row's **carve-outs** are part of its premise: it named one route that must stay ungated; there were three |

⛔ **Both existing fields would have been answered "held" in every one of those cases**, because
neither asks *why does this row believe its scope is what it says*. That is the whole reason this is a
heading rather than a clause folded into `Evidence verified`: **a question nobody has to answer
separately is a question nobody answers.** The alternative was costed and rejected — `M43` measured a
structural gate that was fully green and entirely decorative, and a sub-clause of an existing field is
that shape.

⚠️ **IT IS GATED, WHICH IS WHAT KEEPS IT FROM BECOMING THE THING IT WARNS ABOUT.**
`php scripts/preflight.php` refuses an **ACTIVE** claim that is missing any of the three headings, and
a Pest arm asserts this file still declares all three. The gate reads the active block **only** —
`## RELEASED` history is never retro-fitted, both because rewriting a dated record falsifies the log
and because a rule that is red on arrival can never merge (`M40`).

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

`php scripts/preflight.php --lane=a` asserts most of the above mechanically, including the parts
that have been got wrong repeatedly: the branch base, whether the claim is actually published, whether
another suite is running, and whether `PROGRESS.md` is safe to splice.
