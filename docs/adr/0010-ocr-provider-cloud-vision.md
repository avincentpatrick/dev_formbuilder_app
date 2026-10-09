# ADR-0010 — Single-form OCR reads with Google Cloud Vision; Document AI is rejected, and a vision-language model is the measured fallback

- **Status:** Accepted
- **Date:** 2026-10-09
- **Increment:** M152 (H1d, the OCR provider bake-off — the reason this number was held open while ADR-0011 to ADR-0022 were written)
- **Decisions recorded:** `D97` (A — Cloud Vision alone first; a vision-language arm only if G9 is missed; Document AI not
  pursued) and `D104` (A — Cloud Vision as it is for the Oct 12 testing; the arm built and measured during testing), both in
  `docs/claims/decisions.md`
- **Related:** `docs/ocr-pipeline-design.md` §3 (the 90/70 tiers) and §9 (the harness, and Round 1's figures) · `config/ocr.php` ·
  `app/Services/Ocr/GoogleVisionClient.php` · `app/Services/Ocr/PrintedFormMatcher.php` · `app/Services/Ocr/OcrAnswerReader.php` ·
  `docs/PRD.md` G9 (under 15% of fields needing manual correction on a clear, well-lit single-page scan)

---

## Context

Single-form OCR (PRD Feature #1) reads a hand-filled copy of a form's own **Print blank** sheet. Since `I12`, this product prints
the paper it reads (`docs/ocr-pipeline-design.md` §2.5), so the reader never has to guess the layout. It anchors each question
on its printed key stamp or label and reads the answer in the area below. What the provider has to do is narrow: return words,
characters, boxes and confidences.

Two Google products were on the table from the start. **Cloud Vision** (`DOCUMENT_TEXT_DETECTION`) works with the API key the
platform already holds. **Document AI's Form Parser** returns key–value pairs. The design doc left the choice to a bake-off on
real samples, and this ADR's number was reserved until those samples existed.

**Round 1 (`M149`, 2026-10-08).** The user built a 12-question OCR Test Form on the testing server, printed 15 copies from layout 4,
filled them by hand with made-up answers, and photographed both pages of each with a phone. `ocr:bakeoff` scored those 15 forms
(180 fields) against a sheet of the correct answers. It runs the real matcher, with every provider answer cached, so each re-run
below is free and exact:

| Reader | Fields needing correction | Silent errors |
|---|---:|---:|
| Before `M149` | 177 of 180 (98.3%) — 158 "not found" | 0 |
| `M149`: photos turned upright, stamp lines, ticks read as symbols, the handwriting hint | 92 of 180 (51.1%) | 0 |
| `M152`: a tick's confidence (`R-39a5388f`) | **76 of 180 (42.2%)** | **0** |
| `M152`, every form's pages uploaded in reverse | 76 of 180 (42.2%) — 0 "not found" (30 before `R-4aaf3b6f`) | 0 |

After `M152`, the 76 fields break down like this:
- 22 answered choices on which Vision returned no character for the tick at all.
- 19 right answers and 24 wrong ones withheld below the review threshold.
- 6 written answers not readable as their type: a one-stroke `1` dropped as a box wall, and a printed `/` read as a digit.
- 5 wrong answers flagged for review.

By confidence, read values split 90–100: 57 right, 0 wrong; 80–89: 31 right, 4 wrong; 70–79: 13 right, 1 wrong.

## Decision

**§D1 — The provider is Google Cloud Vision's `DOCUMENT_TEXT_DETECTION`, called over REST with a server-only API key restricted to
the Cloud Vision API.**
- The client is `GoogleVisionClient`.
- `config/ocr.php`'s `provider` stays `google_vision`.
- Cost: $1.50 per 1,000 pages after the first free 1,000 a month.
- A key whose Google Cloud project has no billing account is refused with `403 BILLING_DISABLED` on every call, even inside the
  free tier (measured 2026-10-03). The testing server's key lives in a billing-on project (`M135`).

**§D2 — The request carries the handwriting language hint `en-t-i0-handwrit`** (`config/ocr.php`, `google_vision.language_hints`).
- On Round 1 it moved the figure from 57.2% to 51.1%, and from one silent error to none.
- It also stopped Tagalog being read as Cyrillic.
- Google's general advice is to send no hint. That advice is for mixed printed pages, and these are hand-filled forms.

**§D3 — The 90 / 70 thresholds stay as `docs/ocr-pipeline-design.md` §3 set them; Round 1 confirms them.**
- At 90 and above, nothing read was wrong, so 90 is where a value is filled and not flagged.
- Below 70, wrong reads outnumber right ones, so the value is withheld and its text kept.
- The sweep in the report shows lower auto thresholds producing silent errors (one at 85, four at 80). Raising the review
  threshold only withholds more right answers.

**§D4 — A tick seen in a box is shown for review, never accepted on the mark alone** (`OcrAnswerReader::MARK_SEEN`, `R-39a5388f`).
- Vision returns a hand-drawn tick as ☑, X, a Greek chi or 区, with a low confidence in the GLYPH. That is not the question being
  asked: a mark in the box before an option's label is evidence of a tick.
- So a tick's confidence is at least 80, inside the review band.
- It is never auto: Round 1's one wrong tick was a multi-select whose second mark Vision never returned, and only a reviewer
  looking at the page can see that.
- A test holds the floor between the two configured thresholds.

**§D5 — Pages are matched in printed order, whatever order they were uploaded in** (`PrintedFormMatcher::printedOrder()`,
`R-4aaf3b6f`).
- The running head prints no page number (§2.5 — dompdf's page counters need its PHP to be enabled, which this product forbids).
- So each page is placed by the first question it holds.
- A note names a page by its place in the upload, counted from 1, as the review screen numbers its images.

**§D6 — Document AI is rejected** (`D97`):
- Its Form Parser needs a service account. The platform's credential is an API key, and a second credential type is a second
  thing to rotate and leak.
- It costs about $30 per 1,000 pages against Vision's $1.50.
- Its own documentation says it "doesn't reliably parse a KVP with an unfilled value". A blank question is the commonest answer
  on a paper form whose branching hides questions (§2.5.1).
- Its key–value extraction solves a problem this product does not have, because the reader already knows every question's place
  on the page.

The provider seam stays open: a second provider is a client plus a parser that produces `OcrPage`s, and the scorer, the report,
the matcher and the review screen do not change.

**§D7 — The fallback is a vision-language model, and G9 is what triggers it** (`D97`, then `D104` A).
- Cloud Vision alone misses G9: 42.2% needing correction against a bar of under 15%.
- The fallback: Claude through the Anthropic API reads each scanned page, with its output held to the printed options and placed
  behind the reviewer.
- It is built into `ocr:bakeoff` as a second provider and measured on Round 1's fifteen forms during the testing period. The
  queued work is `ocr-vlm-arm`, at the end of `docs/ocr-pipeline-design.md`.
- **It reaches the testing server only if it meets G9.**
- It is blocked on the user for an Anthropic API key and the agency's consent to send scanned pages to a second provider.
- If the arm wins, the cheaper path is a hybrid: the arm reads only what Vision withheld or found blank.

## Consequences

- **Testers on Oct 12 will key roughly two answers in five by hand.** The review screen is mandatory in any case (§3), and what it
  withholds it explains. Silent errors are what this design refuses, and Round 1 has none.
- **G9 is missed, on the record, at 42.2%.** It is not met by lowering the bar or the thresholds. Lowering the review threshold to 0
  would report about 32%, by showing reviewers wrong values pre-filled: the trade §3 forbids.
- **Ticks are now the largest single cause**: 22 marks never returned at all. This is the measurement the arm is judged against,
  together with handwriting misreads in names, emails, phones and remarks.
- **The figures are slightly pessimistic.** A few cells on the answer sheet differ from the paper itself, such as a comma not
  written and a typo.
- **They are phone photos only.** A flatbed scan is expected to do better, and nothing has measured it.
- **What would reopen this ADR:**
  - The arm meeting G9, which amends §D7 into the decision.
  - Google changing Vision's price or its key policy.
  - A second form channel, the linelist (`ocr-linelist`), whose tabular sheets this bake-off did not measure.
