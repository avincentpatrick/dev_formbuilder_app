# ADR-0023 — A platform support API for the testers' feedback, opened by a platform support token

- **Status:** Accepted
- **Date:** 2026-10-16
- **Increment:** M175 (F of the testers' round 2)
- **Decisions:** `D139` (feedback is pulled automatically at every session start), `D141` (the API answers on the workspace host)
- **Rows:** `R-78f43f26` (the API and the pull script), with `R-9bfd832e` (statuses, the reply, the reporter told) and `R-f3fb2522` (the session-start routine)
- **Related:** ADR-0018 §D1 (per-tenant extraction kept OFF HTTP — the precedent this departs from, and why) ·
  ADR-0002 §D3 (the elevated carve-out the reads go through) · ADR-0015 (the feedback screenshot and its
  policy) · `docs/data-dictionary.md` §21 and §39 · `docs/api-specification.md` (the platform support API) ·
  `docs/security-threat-model.md` · `routes/platform-api.php` · `scripts/feedback-pull.php`

---

## Context

The testers send feedback from the button in every workspace's top bar. Until this increment the reports reached
the people fixing them only through the platform console — central host, session, 2FA, step-up — so a report
waited until the user opened the console and copied it into the development session by hand. The user asked
(2026-10-16) for the session to **pull every report automatically at its start**, triage it against the work
queue, and tell the tester what became of it (`D139`).

That needs something outside the signed-in console to read feedback across every workspace — a **cross-tenant
read over HTTP**. ADR-0018 §D1 refused exactly that shape for per-tenant extraction: a route would need a new
authorization path, and would put a whole-workspace read behind the weakest credential that reaches it. Both
objections apply here and are answered below rather than waived.

One more fact shaped it. On the testing server the central host (`pitahc.gov.ph`) is the agency's own website:
only the box itself reaches the console, through its hosts file. A central-host-only API could never be reached
from the development machine. Asked, the user chose to answer on the workspace host too (`D141` A), opened only
by the support token.

## Decision

### §D1 — A separate, platform-owned token, not Sanctum

`platform_api_tokens` (data-dictionary §39): the sha256 of a `msp_`-prefixed secret shown once, its owner (a
platform super-admin), ONE ability (`feedback:support`), a REQUIRED expiry of at most 90 days, `last_used_at`
and `revoked_at`. It is not a Sanctum `personal_access_tokens` row because that table is strictly
tenant-isolated and read only under a tenant context — a support token belongs to the platform and is used with
none. No `tenant_id`, no RLS, and **the elevated `meridian_superadmin` role is not granted the table**: the
cross-workspace reads still go through the existing SELECT-only carve-out, which this does not touch.

### §D2 — The token is the whole authorization, so every check runs on every call

The API carries no session, so the console's step-up and 2FA prompt never run. `AuthenticatePlatformToken`
stands in for them, in order, on every request: a bearer token whose hash matches a row; not revoked, not
expired, carrying the ability; its OWNER still a super-admin with two-factor confirmed — re-read now, so
demoting the operator or clearing their 2FA closes the API without anyone remembering to revoke. Any failure is
a 401 that does not say which check refused. A session — even a super-admin's — and a tenant API key are not
credentials here; the group has no `web` middleware at all.

The answer to ADR-0018's "weakest credential" objection is that the token is the only credential, it is narrow
(one ability), it dies on its own (90 days at most), it can be killed at once (revoke), and it leaves a trail
(§D4).

### §D3 — Nothing new is reachable

*(Amended by §D7, M179: the relay is the one endpoint the console does not also have, and a reply is a second
write. Everything below still holds for the three endpoints it names.)*

Three endpoints, `routes/platform-api.php`: list (filters: a status set, kind, since), one report's screenshot,
and a status change with a reply. Every read goes through `SuperAdminService` exactly as the console's does — the
list and the single report through the elevated SELECT-only carve-out, the screenshot by borrowing the report's
tenant context (no bypass on `attachments`) — and the one write through `transitionFeedback()`, which adopts the
report's tenant context, writes the workspace's own audit row and tells the reporter. The rows are shaped by the
console's presenter, so the pull and the console describe a report in the same words.

### §D4 — Every call is audited as a platform row

`exported` for a read and `updated` for a status change, `auditable_type = platform_api_tokens`, the token's id,
`tenant_id` NULL, written through `SuperAdminService::recordPlatformAudit()` on the elevated connection — the
second caller of `AuditLogger::record()`'s `$connection`, after the platform settings. A status change also
leaves the reporting workspace's own `feedback_reports` row, so the workspace sees how its report was handled
(RBAC §9). Issuing and revoking a token are `created` and `updated` rows of the same kind. The hash is an
unconditional audit secret.

### §D5 — On any platform host, but never a custom domain

No `->domain()`: the routes answer on the central host and on every workspace subdomain (`D141`).
`RequirePlatformHost` refuses a custom domain, as on every platform surface, and the platform's maintenance mode
applies (the pull is paused with everything else, and says so in the envelope). The throttle runs before the
token lookup — 60 a minute per token (keyed on a hash of it) and 120 a minute per address — so guessing spends
its own budget. Errors use the `/api/v1` envelope (`{error:{code,message,details}}`), and a refused status
change is answered `422 invalid_transition` by the controller itself: the exception handler's catch-all arm
would otherwise have answered it 500.

### §D6 — Issued from the console, used from the development machine

The token is issued, listed and revoked on the console's Settings page, behind the console's own gates. The
user saves it on the development machine in `%USERPROFILE%\.meridian\feedback-pull.json` — outside the
repository — and `scripts/feedback-pull.php` (host PHP, the curl extension, no `vendor/`) reads it. The script
downloads New and Ongoing reports and screenshots into the session's scratchpad only, and writes back only what
`D139` lets a session send on its own: Ongoing with no note, and Resolved with the stock "Fixed in version X,
live on staging." Any other reply and every Declined need `--user-approved`.

### §D7 — Two more writes, the same single ability: the relay and the reply (M179, `R-e727ca94`, `D150`, `D151`)

The user relayed 31 comments by hand — in chat and in a PDF — and asked for the testers to follow them and check
the fixes inside Feedback (`D150`), and for a tester's "still a problem" to reach us (`D151`). Two endpoints join the
three, under the same `feedback:support` ability, the same per-call checks (§D2), the same per-call platform audit
(§D4, `created`) and the same throttle:

- **`POST /feedback/relayed`** — loads a batch (at most 100 items) of RELAYED reports into one workspace, named by
  slug or id; the reporter is an active member of it named by email, else its owner. **Create or update by
  reference** (`R1-7a`, `U4` — unique within a workspace): a new reference becomes a report in the status the item
  gives (Confirmed only at creation, for a fix the user already checked); an existing one has its WORDS updated —
  title, remarks, source line — and **never its status**, which after the load belongs to the lifecycle, so
  running the same file twice changes nothing. One transaction under the workspace's adopted context; every item
  audited in that workspace's own ledger; **no notification** (a bulk load of history, not news).
- **`POST /feedback/{id}/comments`** — the support team's reply in a report's thread, through
  `SuperAdminService::replyToFeedback()` (adopt-context, as the status change): it moves nothing, is audited in
  the workspace's ledger with its words redacted, and tells the reporter and everyone who wrote in the thread
  (`feedback_reply`).

**This makes §D3's "nothing new is reachable" false for the relay**, and the ADR says so rather than leaving §D3
to contradict it: the console has no "load relayed comments" page — a one-off load did not need one, and the
user's approval of the words travels with the file (`--user-approved`), not with a click. The list also gains
`source`, `reference` and `commented_since` (reports with a MEMBER's message newer than that — our own replies are
never news), and every row its thread.

## Consequences

**Accepted:**

- **A leaked token can now WRITE, not only read (M179, §D7).** Besides moving a report, it could plant relayed
  reports that every member of a workspace sees, and post a support reply that is emailed to a report's reporter
  and thread — a phishing vector in the platform's own name. Bounded by the same checks: §D2 on every call, the
  90-day ceiling, revocation from the console, the per-call audit in both ledgers, the rate limit. The client
  sends both only with `--user-approved`, which the server cannot enforce.

- **A cross-workspace read reachable from the internet** on the testing server's workspace host. A leaked token
  reads every workspace's feedback — remarks, page addresses, reporter names and emails, screenshots — until it
  expires or is revoked. Bounded by §D2's checks, the 90-day ceiling, the per-call audit and the rate limit; and
  on the testing server there is one workspace and the data is the testers' own.
- **The development machine holds the secret** in a file outside the repository, and the session's scratchpad
  holds downloaded screenshots for the session's life. Both are recorded in the threat model.
- **No OpenAPI document.** Scramble documents `api/v1` only; this surface's single client is in the repository,
  and its contract is this ADR plus `docs/api-specification.md`.

**Rejected:**

- **Sanctum with a carve-out** on `personal_access_tokens` — widening a strictly isolated table's policy for a
  credential that is not a tenant's.
- **The console only, copied by hand** — the status quo `D139` replaced.
- **An artisan export run on the server** (ADR-0018's shape) — nothing exposed, but nothing automatic either;
  offered as `D141` C and declined.
- **An IP lock on the token** — offered as `D141` B; declined because the office address can change and would
  silently stop the pull.
