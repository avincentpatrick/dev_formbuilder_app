# End-to-End Testing Guide

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)

**Status:** Living document, written in Increment I6 (2026-08-07) and kept current as increments land. This is
the walkthrough the product owner drives the whole application from: it visits every surface that is built,
in the order a real user would meet them, against the fixture `Database\Seeders\DemoSeeder` creates. It is
also a deliberate record of what is **not** built — §17 lists that, so a missing feature reads as a known
gap rather than a bug you found.

**How to read:** Each chapter is a numbered list of steps. Every step names the **URL**, the **account** to
be signed in as, the **action**, and the **expected result** — so when reality disagrees with the page, the
disagreement is unambiguous and worth reporting. Tick as you go. Where a step exercises a PRD §5 Main
Feature, the feature number is named in the chapter heading.

**If a step cannot be followed as written, that is a defect in this guide, not something for you to work
around.** Note it and move on; the guide is fixed in the same increment that broke it.

---

## 0. Boot, seed, and sign in

Everything runs inside Docker. The Windows host cannot run the PHP or Node toolchains directly — it has no
`pdo_pgsql` extension and no `rolldown` win32 binding — so every command below is `docker compose exec`.

### 0.1 Start the stack

```bash
docker compose up -d
docker compose exec app php artisan migrate:fresh --seed
```

`migrate:fresh --seed` **drops and rebuilds the database**, then seeds the demo fixture. It takes about 30
seconds. Re-running it is always safe, and re-running `php artisan db:seed --class="Database\Seeders\DemoSeeder"`
on an existing database converges rather than duplicating — every row is keyed.

| Service | URL |
|---|---|
| The platform (central host) | <http://localhost:8080> |
| The demo workspace | <http://demo.localhost:8080> |
| A second workspace, for isolation testing | <http://northwind.localhost:8080> |
| Mailpit — every email the app sends | <http://localhost:8025> |

> **If `demo.localhost:8080` does not resolve.** Chrome and Firefox map `*.localhost` to loopback with no
> configuration. Some corporate DNS and some Windows tooling do not. If the browser cannot find it, add this
> line to `C:\Windows\System32\drivers\etc\hosts` and retry:
> `127.0.0.1 demo.localhost northwind.localhost`

### 0.2 The accounts

Every account below uses the same password: **`meridian-demo-2026`**

| Account | Role | Workspace | What it is for |
|---|---|---|---|
| `owner@demo.test` | Owner | demo | Everything. Start here. |
| `admin@demo.test` | Admin | demo | Same as Owner minus ownership transfer. |
| `editor@demo.test` | Form editor | demo | Builds forms. Sees only the 3 forms granted to them. |
| `reviewer@demo.test` | Reviewer | demo | Reviews submissions on those same 3 forms. |
| `viewer@demo.test` | Viewer | demo | Read-only, but org-wide. |
| `consultant@demo.test` | Viewer | **both** | The cross-tenant account §16 uses. |
| `owner@northwind.test` | Owner | northwind | The second workspace. |
| `admin@meridian.test` | Platform super-admin | — (central host) | §15. Needs a TOTP app. |

`invited@demo.test` also exists but **cannot sign in** — it is a pending invitation, so the Members roster has
a pending row to show. That is intended.

### 0.3 First look

1. Open <http://localhost:8080>. **Expect:** the platform landing page — a headline, a "Sign in" button, a
   "Create a workspace" button, and four capability cards. This is the central host; no workspace data is
   reachable from here.
2. Open <http://demo.localhost:8080> while signed out. **Expect:** you are redirected to the sign-in page.
3. Sign in as `owner@demo.test`. **Expect:** you land on `/dashboard` with data already on it.
4. Now revisit <http://demo.localhost:8080> while signed in. **Expect:** you are redirected straight to
   `/dashboard`.

---

## 1. The shell and your preferences — Features #6, #9, #5

1. `/dashboard` · owner. Look at the left sidebar. **Expect:** Forms, Submissions, Dashboard, Analytics,
   Members, Scopes, Audit log, Webhooks, Integrations, Domains, Settings. Every one of them is a real page.
2. Open the account menu (top right) → **Settings**, or go to `/settings`.
3. **Appearance card** — switch the theme mode between Light, Dark and System. **Expect:** the whole app
   re-colours immediately, with no reload and no flash of the wrong theme.
4. Change the **accent colour**. **Expect:** buttons, links and focus rings change together; text contrast
   stays readable in both themes.
5. Change the **text size**. **Expect:** type scales across the app; layout does not break at the largest step.
6. Turn on the **dyslexia-friendly font**. **Expect:** the typeface changes app-wide.
7. Resize the browser to a narrow phone width (or use device emulation at 375px). **Expect:** the sidebar
   collapses to a menu button, nothing overflows horizontally, and every page remains usable. Try this on
   `/submissions` and `/analytics` specifically — they are the densest.

---

## 2. Building a form — Feature #8

1. `/forms` · owner. **Expect:** six forms. *Patient Intake*, *Community Health Survey 2026*, *Field Site
   Assessment*, *Referral Router* and *Staff Feedback 2025* are published; *Quarterly Report (draft)* is not.
2. Open **Quarterly Report (draft)** → **Edit**. This opens the builder on an unpublished form.
3. Add a section, then add fields to it. **Expect:** the field-type picker offers around thirty types grouped
   by kind; each added field appears on the canvas and is selected, with its settings in the right-hand panel.
4. Rename a field's label and change its key. **Expect:** the change is saved without a page reload (watch for
   the saved indicator).
5. Drag a field to reorder it, then do the same with the keyboard alone. **Expect:** both work, and the
   keyboard reorder announces the new position.
6. Duplicate a field. Delete one. **Expect:** both take effect immediately.
7. Open the **Logic** view (the second tab of the centre pane). **Expect:** a map of sections and their
   conditions, with each condition written out as a readable sentence.
8. Now open **Referral Router** in the builder and look at its Logic view. **Expect:** two sections that are
   conditional on the answer to *How were you referred?*, each read back as a sentence rather than as raw
   expression text.
9. Back on `/forms`, use **New from template**. **Expect:** a gallery of platform templates; choosing one
   creates a new draft form pre-populated.
10. In the builder, open the **question library** and insert a saved question. **Expect:** it lands as a fully
    configured field.
11. On a published form, use **Export XLSForm**. **Expect:** an `.xlsx` download. Re-import it into a draft to
    confirm the round trip.

---

## 3. Publishing, sharing and scheduling — Features #3, #8

1. `/forms` · owner → **Quarterly Report (draft)** → **Publish**. **Expect:** it becomes published and gets a
   version number.
2. Edit it again and publish a second time. **Expect:** version history now shows two versions, and you can
   restore the earlier one.
3. Open **Patient Intake** → **Share**. **Expect:** a public link (`/f/patient-intake`), a QR code, and a
   toggle for guest submissions.
4. Download or scan the QR code. **Expect:** it resolves to the same public link.
5. Open **Community Health Survey 2026** → **Schedule**. **Expect:** an open window (it opened 30 days ago and
   closes in 30 days) and a response cap of 200.
6. Set a form's window to close in the past, then visit its public link. **Expect:** the form refuses new
   responses and says so. **Staff Feedback 2025** is already in that state — see §4 step 5.
7. On **Patient Intake**, check the **confirmation message** setting. **Expect:** you can customise what a
   respondent sees after submitting.
8. Confirm **save and resume** is on for Patient Intake and Community Health Survey 2026.

---

## 4. Responding as a guest — Features #3, #5

Do this chapter in a **private/incognito window**, so you are genuinely not signed in.

1. Open <http://demo.localhost:8080/f/patient-intake>. **Expect:** the form renders with no app chrome, no
   sign-in prompt, and a language switcher (it declares English and Spanish).
2. Fill it in and submit. **Expect:** a confirmation screen with a reference number.
3. Start filling it again, then use **Save and finish later**. **Expect:** you are given a resume link. Close
   the tab, open the resume link, and confirm your answers are still there.
4. Open <http://demo.localhost:8080/f/site-assessment>. **Expect:** a map control you can drop a pin on, a
   file upload, and a repeatable "Rooms surveyed" section you can add and remove instances from.
5. Open <http://demo.localhost:8080/f/staff-feedback-2025>. **Expect:** a full-screen "this form is closed"
   state instead of a fill session. This is correct — its window ended 45 days ago.
6. Open <http://demo.localhost:8080/f/referral-router> and answer *How were you referred?* both ways.
   **Expect:** a different follow-up section appears for each answer, and the irrelevant one is never shown.
7. **Offline test.** On `/f/patient-intake`, let the page load fully, then switch the browser to offline
   (DevTools → Network → Offline). Reload. **Expect:** the form still renders. Fill it in and submit.
   **Expect:** it queues. Go back online. **Expect:** it syncs and appears in the inbox.
8. Still on a public form, look for the install prompt / add-to-home-screen affordance. **Expect:** the form
   is installable as a standalone app.

---

## 5. Manual encoding — Feature #7

1. `/forms` · owner → **Patient Intake** → **New submission** (or `/forms/{id}/submissions/create`).
   **Expect:** the same form, rendered inside the app shell for staff entry rather than as a guest page.
2. Fill it in and save. **Expect:** it appears in the inbox with source *manual* rather than *guest*.
3. Try the same on **Field Site Assessment**. **Expect:** the map control works here too (this page carries a
   different content-security policy specifically so the map tiles load).

---

## 6. The inbox and review — Feature #7

1. `/submissions` · owner. **Expect:** several hundred rows spread over the last 90 days, with a status badge
   on each.
2. Filter by status. **Expect:** every one of *submitted*, *under review*, *approved*, *returned* and
   *archived* returns rows. Note that in-progress **drafts are hidden from the unfiltered list on purpose** —
   the inbox is a work queue, not a log — but selecting the **Draft** status explicitly does surface them,
   with their progress. Check both halves of that.
3. Filter by form, and by date range. **Expect:** both narrow the list; the counts change accordingly.
4. Page through the list. **Expect:** pagination works and the sort order is stable.
5. Open any submission. **Expect:** the full answer document, who submitted it, when, from which channel, and
   which form version it was captured against.
6. On a *submitted* row, **Approve** it. **Expect:** the status changes and the reviewer and timestamp are
   recorded.
7. On another, **Return to respondent** with a reason. **Expect:** the reason is stored and shown.
8. **Archive** a third.
9. From a submission, request a **PDF**. **Expect:** a job is queued and the PDF arrives by email — check
   <http://localhost:8025>.
10. From `/forms`, **export** Patient Intake as CSV and again as XLSX. **Expect:** both download and stream
    rather than time out; the multi-select column is readable and the single-select shows labels.
11. Note the total row count as the Owner (it is around 500). Now sign out and sign in as
    **`reviewer@demo.test`** and open `/submissions` again. **Expect:** roughly **a hundred** rows, not five
    hundred — a reviewer sees only the three forms they hold a grant on, and the biggest form is deliberately
    not one of them. This is the permission model working, and it is meant to be obvious.
12. Sign in as **`viewer@demo.test`**. **Expect:** the *full* list again — a Viewer is read-only but
    org-wide — and no approve/return controls anywhere.
13. As the reviewer or the viewer, try `/audit-log`. **Expect:** a 403 refusal; it is Owner and Admin only.

---

## 7. Dashboard and analytics — Feature #4

1. `/dashboard` · owner. **Expect:** headline counts, a submissions-over-time trend with real shape (not a
   flat line), and a breakdown by form.
2. Change the date range. **Expect:** every tile and chart updates together.
3. Look for days with **zero** submissions in the trend. **Expect:** they render as zero, not as gaps — the
   fixture deliberately contains a few empty days.
4. `/analytics` · owner. **Expect:** the cross-form analytics page loads. (It is Business-tier only, and the
   demo workspace is on Business precisely so this is reachable.)
5. Apply filters — form, date range, status, source, locale. **Expect:** the charts and the table respond.
6. Open the **question explorer**. **Expect:** Patient Intake's indexed questions are offered — *District*
   (categorical), *Age* (with min/max/average/median), *Visit date*, *Consent given* and *Sex*. **Expect** the
   coverage figure for *District* to be **below 100%**: one row in nine deliberately leaves it blank, so you
   can see that the page reports coverage honestly.
7. Note that *Presenting symptoms* is **not** offered. That is correct — it is a multi-select, and only
   single-valued answers are indexed.
8. Save the current filter set as a **saved view**. Reload the page and re-apply it. **Expect:** it restores.
9. **Export** the analytics result. **Expect:** a download whose numbers match what is on screen.

---

## 8. Settings — Feature #10

1. `/settings` · owner. **Expect:** cards for Profile, Appearance, Notifications, Drafts, Access,
   Maintenance, Modules, Custom domains, Branding, Security and About. (Access, Maintenance and Modules are
   Owner/Admin only — they are absent for a Viewer, which §6 step 12 already had you check.)
2. **About** — **Expect:** the app version and build commit.
3. **Access** — the demo workspace is set to *open* registration. Open a private window, go to
   <http://demo.localhost:8080/register>, and create an account. **Expect:** it works, and the new account
   lands in the workspace as a Viewer. Now switch Access to *invitation only* and try again. **Expect:** the
   register page 404s.
4. **Modules** — turn **Webhooks** off. **Expect:** the Webhooks item disappears from the sidebar
   *immediately*, and visiting `/webhooks` directly is refused. Turn it back on and confirm it returns.
5. **Maintenance** — turn it on with a message. In a private window, open
   <http://demo.localhost:8080/f/patient-intake>. **Expect:** a styled maintenance page carrying your message
   instead of the form. Confirm `/dashboard` still works for you as a signed-in member. **Turn it back off.**
6. **Drafts** — change the draft retention period. **Expect:** it saves.
7. **Branding** — set a primary colour and upload a logo. **Expect:** the app re-colours, and the logo appears
   in the shell. Open a public form and confirm the branding reaches the guest page too. Then remove the
   branding and confirm it reverts.
8. After each change above, glance at `/audit-log` — every settings write should be recorded. That is §9.

---

## 9. Audit trail — Feature #12

1. `/audit-log` · owner. **Expect:** a ledger spanning roughly the last 80 days.
2. Filter by **event type**. **Expect:** all eight are present — created, updated, deleted, restored,
   published, archived, exported, permission changed.
3. Filter by **date range**. **Expect:** the range genuinely narrows the result (the fixture is spread over
   time precisely so this is testable).
4. Filter by **actor**.
5. Find a row whose changed values show **`[redacted]`**. **Expect:** at least one exists — personal data is
   stripped from the ledger by design, and you should be able to see that it was stripped rather than simply
   absent.
6. Find a row with no actor, marked as a **system action**. **Expect:** it renders sensibly rather than blank.
7. **Export** the log. **Expect:** a streamed download.
8. Sign in as `viewer@demo.test` and try `/audit-log`. **Expect:** refused — the audit log is Owner and Admin
   only.

---

## 10. Notifications — Feature #13

1. `/dashboard` · owner. **Expect:** the bell in the top bar carries an unread count.
2. Open the bell. **Expect:** a popover listing recent events with a readable sentence, a relative time, and an
   icon per type. There is deliberately no full-page notification list — the bell is the centre.
3. Click a row that links somewhere. **Expect:** it navigates to the target *and* marks itself read.
4. Note the rows that are **not** links. **Expect:** they render as plain text rather than as broken links —
   these point at things you can no longer reach, and that is handled deliberately.
5. Use **Mark all read**. **Expect:** the badge clears.
6. `/settings` → **Notifications** card. Turn off in-app delivery for one event type and email for another.
   **Expect:** both save independently.
7. Trigger a real notification: as `owner@demo.test`, invite a member (§13), or submit a guest response (§4).
   Wait up to a minute — the bell polls rather than pushing. **Expect:** a new row appears.

---

## 11. Feedback — Feature #11

1. Any page · owner. Find the feedback affordance in the app shell.
2. Submit feedback with a comment. **Expect:** a confirmation, and the route you were on is captured with it.
3. There is **no** admin console for reading feedback yet — that is increment I7. The four seeded reports (one
   in each state) exist so that console has data on day one.

---

## 12. Security and two-factor — Feature #14

1. `/settings` · owner → the **Security** card. Enable 2FA. **Expect:** a QR code you can scan with any TOTP
   app, and a set of recovery codes.
2. Confirm enrolment with a code from the app. **Expect:** it is accepted.
3. Sign out and sign back in. **Expect:** you are challenged for a 6-digit code.
4. Sign out again and sign in using a **recovery code** instead. **Expect:** it works, and that code is spent.
5. Regenerate the recovery codes. **Expect:** the old ones stop working.
6. Disable 2FA and confirm the challenge stops.
7. Test **password reset**: sign out, use "forgot password", and collect the mail from
   <http://localhost:8025>. **Expect:** the reset link works and the new password signs you in.

> **Not built yet:** step-up re-authentication before sensitive actions (transferring ownership, changing
> roles, assigning plans) and organisation-wide enforced 2FA are increment **I8**. Their absence is expected.

---

## 13. Team, roles, scopes and grants

1. `/members` · owner. **Expect:** six active members and one **pending** invitation
   (`invited@demo.test`), each with a role badge.
2. Invite a new member by email with a role. **Expect:** the invitation is created and an email arrives at
   <http://localhost:8025>. Open the invitation link in a private window and accept it.
3. Change a member's role. Remove a member. **Expect:** both work and both appear in `/audit-log`.
4. Transfer ownership to `admin@demo.test`, then transfer it back. **Expect:** the Owner badge moves.
5. `/scopes` · owner. **Expect:** a hierarchy tree. Create a node, rename it, move it, and delete one.
   **Expect:** the impact of a move or delete is shown before you confirm.
6. Assign a form to a scope node (`/forms` → the form → Scope).
7. Grant `reviewer@demo.test` access to a form they do not currently hold. Sign in as them and confirm the
   form and its submissions are now visible. Revoke it and confirm they disappear again.

---

## 14. Integrations, webhooks and custom domains

1. `/webhooks` · owner. **Expect:** the endpoint list and a delivery log.
2. Create an endpoint pointing at any URL you control (<https://webhook.site> works well) and subscribe it to
   *submission created*.
3. Use **Send test**. **Expect:** an immediate pass/fail result on screen.
4. Submit a guest response (§4). **Expect:** a delivery appears in the log with its status code and timing.
5. Redeliver a past delivery. **Expect:** a new attempt is recorded.
6. **Rotate the signing secret.** **Expect:** the new secret is shown once and never again.
7. `/integrations` · owner. **Expect:** a provider catalogue. Slack is connectable; Google Sheets and Airtable
   are backend-only for now (see §17).
8. `/domains` · owner. **Expect:** the custom-domain surface. Claim a domain. **Expect:** a DNS TXT record to
   add and a **Verify** button. Verification will not succeed unless you actually control the domain — that is
   correct. **Do not expect to activate one**: activation is an operator command run after a certificate is
   installed, deliberately not a button.

---

## 15. The super-admin console

The console lives on the **central host**, not on a workspace, and it requires two-factor authentication.

1. Sign in at <http://localhost:8080/login> as **`admin@meridian.test`** / `meridian-demo-2026`.
2. Go to <http://localhost:8080/admin/tenants>. **Expect:** you are redirected to
   <http://localhost:8080/admin/two-factor>, the enrolment page. **This is correct, not a bug** — the platform
   console refuses to open without MFA. (There is no bare `/admin` index page; the console's entry point is
   the tenants list.)
3. Scan the QR code with any TOTP app and enter the six-digit code. **Expect:** you are let through. From your
   *next* sign-in onward you will be challenged at the login screen itself.

   > The seed deliberately leaves this account **unenrolled**. A pre-enrolled one would carry a placeholder
   > secret that no authenticator can reproduce, which would lock you out permanently rather than save you a
   > step. If you ever need to reset it, re-run `php artisan migrate:fresh --seed`.

4. `/admin/tenants`. **Expect:** both workspaces listed. Suspend one and confirm its members are locked out;
   reactivate it.
5. Assign a different **plan** to `northwind`. **Expect:** its available features change accordingly.
6. `/admin/users`. **Expect:** users across all workspaces — this is the one place that reads across tenants.
7. `/admin/settings`. **Expect:** a platform signup toggle and platform maintenance mode.
8. Turn **platform signup** off. Open <http://localhost:8080> in a private window. **Expect:** the
   "Create a workspace" button is gone from the landing page. Turn it back on and confirm it returns.
9. Turn **platform maintenance** on. In a private window, open <http://localhost:8080> and a workspace.
   **Expect:** both show the maintenance page — while `/admin` stays reachable for you, so you can turn it
   back off. **Turn it back off.**

---

## 16. Cross-tenant isolation

This is the chapter that checks the thing a multi-tenant product must never get wrong.

1. Sign in at <http://northwind.localhost:8080> as **`consultant@demo.test`** — the same account that is a
   member of the demo workspace.
2. `/forms`. **Expect:** exactly one form, *Referral Log*. **None** of the demo workspace's six forms.
3. `/submissions`. **Expect:** roughly a dozen rows, none of them from the demo workspace.
4. `/audit-log`, `/dashboard`, the notification bell. **Expect:** all scoped to Northwind only.
5. Check the sidebar. **Expect:** **no Analytics item** — Northwind is on Starter, which does not include
   advanced analytics. It is hidden, not shown-and-locked. Now type
   <http://northwind.localhost:8080/analytics> directly. **Expect:** you are turned away rather than shown
   the page — hiding the link is not the whole of the gate.
6. **Expect no Webhooks item either**, and the same result if you navigate to `/webhooks` directly. This one
   is a *different mechanism* from step 5 and worth seeing beside it: Starter **does** include webhooks, but
   the module is switched **off** in this workspace's settings. Sign in as `owner@northwind.test`, turn it
   back on under `/settings` → Modules, and confirm the item and the route both return.
7. Now switch back to <http://demo.localhost:8080> in the same browser. **Expect:** you must sign in again —
   sessions are per-host by design — and once in, you see the demo workspace's data and none of Northwind's.
8. Try to reach a demo workspace record by ID from the Northwind host (paste a submission URL). **Expect:**
   not found. It must never render.

---

## 17. What is *not* built yet

Everything above is built. These are known, deliberate gaps — if you go looking for them, their absence is
expected and is not a defect.

| Area | Status |
|---|---|
| **OCR upload, single form (Feature #1)** | Not built. Blocked on filled paper-form samples and ground-truth data that only you can supply. |
| **OCR upload, linelist/batch (Feature #2)** | Not built, same blocker. |
| **Payments and self-serve billing** | Cut to Phase 4 by decision. Plans are assigned by the super-admin console instead. |
| **Real-time notification push** | The bell polls on an interval rather than pushing over a socket. Deliberate; the socket layer is deployment-track work. |
| **Google Sheets connector UI** | The backend and the shared column-mapping engine are built; the connect-and-map screens are increment H16b. |
| **Airtable connector** | Increment H16c. |
| **Feedback admin console** | Increment I7. The seeded feedback reports exist so it has data when it lands. |
| **Step-up re-authentication, org-wide enforced 2FA** | Increment I8. |
| **Post-submission answer editing, screened-out status** | Increment I9. |
| **Production deployment** | Track B, after the application is otherwise complete. |

Anything else that does not work as this guide describes **is** worth reporting.

---

## Appendix A — resetting and re-seeding

| I want to… | Command |
|---|---|
| Start completely fresh | `docker compose exec app php artisan migrate:fresh --seed` |
| Re-seed without dropping data | `docker compose exec app php artisan db:seed --class="Database\Seeders\DemoSeeder"` |
| See queued jobs run | They run automatically — the `worker` container is always up. |
| Read outgoing email | <http://localhost:8025> |
| Watch the logs | `docker compose logs -f app worker` |

Three things worth knowing if you go beyond this guide:

- **The end-to-end browser fixture is a different dataset.** `E2eSeeder` builds a workspace called `acme` for
  the automated browser tests. `migrate:fresh --seed` does *not* create it, and `DemoSeeder` never touches it.
- **The PHP test suite is safe to run while you are testing by hand.** `phpunit.xml` pins it to a separate
  `meridian_testing` database, so `php artisan test` cannot disturb the demo data in `meridian`.
- **The Playwright browser suite is not.** It serves the app from `.env`, so it runs against *this* database
  and its setup step seeds the `acme` fixture into it. Don't run it mid-walkthrough; if you already have,
  `php artisan migrate:fresh --seed` puts the demo back.
