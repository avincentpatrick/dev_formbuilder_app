# ADR-0026: A respondent's own response (the device remembers it; a link changes it until the form closes; only before review)

## Status

**Accepted — 2026-11-21.** Built by `M248`, NHETE recommendation 6a, from the user's answers in planning (`D232`). The NHETE module says: *"Until the closing date, a person can come back on the same device and change answers."* Until this ADR only staff could change a submission, and a guest who reopened a form's link started a blank form.

- **Deciders:** the user (`D232`, and `D118` before it), and the session that built it.
- **Related ADRs:** `0021-respondent-scoped-device-outbox.md`. Its visit gate is relaxed here, behind an explicit choice; a dated amendment there points back to this ADR. `0009-oauth-connector-token-custody.md` cites the guest token service's signing code by line, which is why the amend methods sit below its private helpers.
- **Related docs:** `docs/security-threat-model.md` (the amend link row), `docs/ux/form-filling-ux-flow.md` §5.1 and §9, `docs/data-dictionary.md` (`forms.after_submit`, `submissions.respondent_amended_at`), `docs/webhook-integration-design.md` (`submission.updated`'s `edited_by_respondent`).

---

## Context

1. **Guests do not sign in.** A respondent on a public link is an anonymous visitor. Nothing ties a later visit to an earlier one except the device and whatever link they were given.
2. **The device already keeps a respondent's answers, but hides them across visits.**
   - ADR-0021, as amended by `M21`, refuses to restore a draft another *visit* wrote. A visit is a `sessionStorage` id, so closing the tab ends it.
   - This was deliberate, for shared phones and kiosks.
   - It also meant a respondent who closed the tab, or paused for more than ten minutes, came back to a blank form (`R-8881210a`, `R-d37a38f9`).
3. **A sent response could not be changed by anyone but staff.** `SubmissionAnswerEditService` took a `User`. The guest draft save, resume read, submit and replay all refused a finalized row.
4. **The thank-you page always offered "Submit another response".** `docs/ux/form-filling-ux-flow.md` §9 had specified a one-response option since the first UX pass (`R-aec84b42`).

---

## Decision

### D1 — Continue where you left off, asked before any answer appears (`D118` A)
- When this device holds a draft an earlier visit left on this form, the page asks "Continue where you left off" or "Start over" *before the form renders*. The draft must be fresh, on the same schema and hold at least one answer.
- **Continue** re-stamps the row with the current visit. The existing visit gate (`draftBelongsToVisit()`) then lets the session read it.
- **Start over** opens a blank form. The row is left for the first save to overwrite, as before.
- Silent restore is unchanged. A row is never shown without the choice.

### D2 — Continue carries on the same server draft (`D232` answer 4)
- Once "Save and finish later" has made a server draft, the local row also records that draft's uuid, this device's baseline and the latest resume token.
- Continue then carries on *that* draft through the resume read. The emailed link and the device stay in step, and one response is sent.

### D3 — One "After submitting" setting with three values (`D232` answer 2)
`forms.after_submit` takes one of three values:
- **`another`** — the default, and what every form did before.
- **`amend`** — "Change my answers" until the form closes.
- **`once`** — one response.

`amend` and `once` hold **for one device**. The device remembers it sent a response, in Dexie's `app_state` table, keyed by the form's link, because the service worker's replay must be able to write it too. Reopening the form's link then shows "You've already answered this form" instead of a blank form. Another phone, or a cleared browser, starts afresh. That is the decision, not a gap: sign-in is the only stronger guarantee, and guests have none.

### D4 — The way back is the device plus a link (`D232` answer 1)
- In `amend` mode the guest submit returns an **amend token**, a signed, stateless `typ: 'amend'` token naming the submission, and a link `/f/amend/{token}`.
- The device remembers the token. The thank-you page shows the link to copy or email.
- The email option is a `mailto:` link, so this server sends no mail on an unauthenticated request.
- Anyone holding the link can read and change the answers, exactly as anyone holding a "Save and finish later" link can.

### D5 — Who may change it is decided live, on every request (`D232` answer 3)
`RespondentAmendService` refuses unless all of these hold:
- the token names a **guest** response on the token's own form;
- the form still offers `amend` and still takes guests;
- the form is inside its open window;
- the response is still `submitted`.

So staff reviewing, approving or returning a response freezes it. The token itself carries no form state: its lifetime (365 days by default) is only an upper bound. An author who extends the closing date never kills a link early, and one who switches `amend` off stops every link at once.

### D6 — The change is a staff correction's own write, by the respondent
`SubmissionAnswerEditService::amendAsRespondent()` reuses the staff path:
- the same lost-update baseline and row lock;
- the same validation, against the response's own version, with `submitted_at` as the clock;
- media kept from the stored document;
- the same projections, and one audit row.

The differences:
- the status and the form's conditions are checked again under the lock;
- the audit row has no user and carries `by: respondent`, and the log names it "Respondent";
- `submissions.respondent_amended_at` is stamped;
- `submission.updated` carries `edited_by_respondent: true` with a null `edited_by`;
- no capacity is consumed — a change adds no response.

### D7 — The amend page is never cached
- The amend page's address is the whole credential. The service worker's shell route leaves `/f/amend/` to the network, and no route claims it.
- The read sits under `api/v1/public/submissions/amend/`, outside every cached prefix.
- Changing a sent response needs the server anyway, so nothing is lost offline.

---

## Consequences

- **Accepted shared-device costs, extending `D118`'s.** On a shared phone or kiosk, the next person can:
  - press Continue and see the previous respondent's draft, and change their server draft;
  - be told the form was already answered;
  - in `amend` mode, open and change the previous respondent's sent answers.

  The kiosk-mode row `R-968816fe` is where every one of these is switched off for a shared device.
- **Answers to questions hidden at Submit are gone.** The stored document drops them, as it always has. A respondent who switches such a branch back on while changing their answers sees those questions blank.
- **Not built, each filed `during-testing`:**
  - changing a photo, file or signature after sending (`respondent-amend-media`);
  - changing answers offline (`respondent-amend-offline`);
  - telling anyone that a response changed (`respondent-amend-notify`);
  - a per-submission history view (`submission-history-panel`).
