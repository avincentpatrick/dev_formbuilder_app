# Access Matrix

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)

**Status:** Living reference. **Every value below was read from the live database and the running
containers on 2026-08-14** — not copied from another document. It answers two questions:
*who can sign in, and where* — and *what can they reach once they are in*.

**Relationship to [`TESTING-GUIDE.md`](TESTING-GUIDE.md):** that guide is the walkthrough you drive the
application from, in the order a real user meets it. This is the reference behind it — the credential
card, the role grid, and the per-destination gates. When the two disagree, re-verify both against the
database; whichever was written later is not automatically right.

---

> ## ⚠️ WHICH SEEDER IS LOADED CHANGES EVERY CREDENTIAL ON THIS PAGE
>
> Two disjoint fixtures exist and the database holds whichever ran last. `DemoSeeder` owns
> `demo` + `northwind`; `E2eSeeder` owns `acme`, with entirely different emails and passwords. A
> `migrate:fresh` from a test run can swap them underneath you.
>
> One command settles it before you quote anything here:
>
> ```bash
> docker exec dev_formbuilder_app-postgres-1 psql -U meridian -d meridian -c "select email from users"
> ```
>
> This warning is first because the failure it prevents has already happened: an earlier notes file
> asserted the `acme` credentials long after the box had been reseeded, and every URL and login in it
> was dead.

---

## 1. Stacks and URLs

> ⛔ **STACK B IS GONE AS OF `M50` (2026-08-31). THERE IS ONE APPLICATION AND NOW ONE STACK — USE
> STACK A ON PORT 8080.** The `fb-lane-b` worktree and its containers were removed when the project
> collapsed to a single lane; see `docs/adr/0022-single-lane-development.md`. **The Stack B column
> below is kept as a decommissioning reference, not as somewhere you can log in.** Nothing answers on
> 8081, 5433, 6380 or 8026 any more.
>
> ⚠️ **ONE LEFTOVER SURVIVES ON PURPOSE: the Docker volume `fb-lane-b_pgdata`.** The stack was brought
> down with `docker compose down` and **without `-v`**, so its database volume was deliberately not
> destroyed — a removal that is easy to do and impossible to undo was left for a human. Remove it with
> `docker volume rm fb-lane-b_pgdata` if you want the disk back; nothing in this repository needs it.
> The `.env` files from both retired worktrees were copied to `C:/tmp/m50-lane-c-rescue/` before the
> directories were deleted, because they are gitignored and were the only copies.
>
> **Why it existed at all**, since the reasoning still matters if parallel work ever resumes: Pest
> runs `migrate:fresh`, which **drops the schema**, so two lanes sharing one database would wipe each
> other mid-run (Standing Rule 7c). Isolation was the whole point of the second stack.

The two copies share nothing at runtime — not a port, not a database, not a fixture:

| | **Stack A — the only stack** | **Stack B — REMOVED by `M50`** |
|---|---|---|
| Compose project | `dev_formbuilder_app-*` | `fb-lane-b-*` |
| Worktree | `C:/laragon/www/dev_formbuilder_app` | `C:/laragon/www/fb-lane-b` |
| Branch | `j3c2-google-signin` | `p2b-tenant-extraction` |
| Fixture loaded | `DemoSeeder` | `E2eSeeder` |
| Application | <http://localhost:8080> | <http://localhost:8081> |
| Workspaces | `demo`, `northwind` | `acme` |
| Mailpit (all outbound email) | <http://localhost:8025> | <http://localhost:8026> |
| Postgres | `localhost:5432` | `localhost:5433` |
| Redis | `localhost:6379` | `localhost:6380` |
| Vite dev server | <http://localhost:5173> | — |

**Stack A hosts**

| Host | What it serves | Sign in here? |
|---|---|---|
| <http://localhost:8080> | Central/platform host — landing, register, password reset, and the super-admin console. **No workspace data is reachable here.** | **Super-admin only** |
| <http://demo.localhost:8080> | The `demo` workspace (the main one) | ✅ **Yes — start here** |
| <http://northwind.localhost:8080> | The `northwind` workspace, for cross-tenant isolation testing | ✅ Yes |

> ## ⚠️ SIGN IN AT THE WORKSPACE URL, NOT THE PLATFORM URL — THE LANDING PAGE'S "Sign in" BUTTON IS A DEAD END FOR A WORKSPACE MEMBER
>
> **Measured 2026-08-14.** Signing in as `owner@demo.test` at `http://localhost:8080/login` **succeeds**
> — and then loops you straight back to the landing page:
>
> 1. `POST /login` authenticates fine.
> 2. Fortify redirects to its configured home, `/dashboard` — the `home` key in `config/fortify.php` — **still on the
>    central host**.
> 3. `/dashboard` is a tenant route, and the tenant group's **first** middleware is
>    `InitializeTenancyBySubdomain`. On the central host `localhost` there is no subdomain to resolve, so
>    it throws `NotASubdomainException`, and `bootstrap/app.php`'s renderer for that exception returns
>    `redirect(config('app.url'))` — a 302 back to `/`. That is the loop, and there is no error message
>    anywhere in it. ⚠️ **Corrected by Increment M46 (2026-08-29): this step used to blame
>    `PreventAccessFromCentralDomains`.** That middleware is registered one position later and never
>    executes here — and it could not produce this symptom anyway, because its refusal is `abort(404)`,
>    not a redirect. **The observable was right and the named cause was wrong**, which is the worse of the
>    two failures: it sends the next reader to a middleware that is behaving correctly.
> 4. **Walking to the subdomain afterwards does not rescue it.** `SESSION_DOMAIN=null` makes the session
>    cookie **host-only for `localhost`**, so the browser never sends it to `demo.localhost`; that host
>    302s to `/login` as an anonymous visitor.
>
> **Use <http://demo.localhost:8080> instead** — `/login` is served on workspace subdomains too
> (`PlatformHost::isPlatformHost()` accepts the central domain *and* its subdomains, rejecting only a
> tenant's custom domain). Verified: `GET demo/login` 200 → `POST demo/login` 200 → `GET demo/dashboard`
> **200**.
>
> The landing page's own footer already hints at this — *"Already a member of a workspace? Go straight to
> it at `your-workspace.localhost`"* — but its primary **Sign in** button does not, which is what makes
> the trap easy to fall into. The central host's sign-in is correct for exactly one account:
> `admin@meridian.test` (verified — lands on `/admin/tenants` → `/admin/two-factor`, the TOTP gate).

The app is subdomain-multitenant and the two directions are both enforced:
`PreventAccessFromCentralDomains` rejects tenant routes on the bare central host, and
`RequirePlatformHost` rejects the auth pages and the admin console on a tenant subdomain.

> **If `demo.localhost:8080` does not resolve.** Chrome and Firefox map `*.localhost` to loopback with
> no configuration; some Windows tooling does not. Add to `C:\Windows\System32\drivers\etc\hosts`:
> `127.0.0.1 demo.localhost northwind.localhost`

> **Pages take 6–20 seconds to render in dev** (Vite dev server + SSR). A 10-second `curl` timeout
> returns `000` on every URL and looks exactly like an outage. Raise the timeout before diagnosing;
> `docker compose logs app` prints per-request timings.

---

## 2. Accounts — Stack A (`DemoSeeder`)

Every account below uses the same password: **`meridian-demo-2026`**

| Email | Role | Workspace | Notes |
|---|---|---|---|
| `owner@demo.test` | Owner | demo | Everything. Start here. |
| `admin@demo.test` | Admin | demo | Owner minus billing management and ownership transfer |
| `editor@demo.test` | Form editor | demo | `.own` scoped — granted `editor` on 4 forms (see §3) |
| `reviewer@demo.test` | Reviewer | demo | `.own` scoped — granted `reviewer` on 3 forms (see §3) |
| `viewer@demo.test` | Viewer | demo | Read-only, but org-wide |
| `consultant@demo.test` | Viewer | **demo AND northwind** | The cross-tenant isolation account |
| `owner@northwind.test` | Owner | northwind | The second workspace |
| `admin@meridian.test` | Platform super-admin | — (central host only) | Needs a TOTP app — see §6 |
| `invited@demo.test` | — | demo (`invited`) | **Cannot sign in.** A pending invitation, so the Members roster has a pending row. Intended, not a bug. |
| *(your own sign-up address)* | — | none | Whatever address you registered with yourself. Email unverified and **no `tenant_users` row**, so it reaches no workspace. ⚠️ **Deliberately not written out: this repository is PUBLIC, and a real address in a table headed "every account below uses the same password" is an exposure, not a fixture.** Look it up in your own dev database if you need it. |

## 2b. Accounts — Stack B (`E2eSeeder`, port 8081)

**Not a separate product — the same app, running from the parallel worktree (see §1).** Workspace
`acme` → <http://acme.localhost:8081>. This is the Playwright fixture; `E2eSeederIdempotencyTest` pins
its row counts exactly. Nothing here is a credential for the app you are testing on 8080.

| Email | Password | Notes |
|---|---|---|
| `demo@meridian.test` | `meridian-e2e-2026` | Owner of `acme` |
| `reviewer@meridian.test` | `meridian-e2e-2026` | Reviewer |
| `pending@meridian.test` | `meridian-e2e-2026` | Unverified placeholder (J3b invitation fixture) |
| `console@meridian.test` | `meridian-console-2026` | Platform super-admin |

> ⚠️ `twofactor@meridian.test` is defined in main's `E2eSeeder` but is **absent from lane B's
> database** — that stack was seeded from an older branch. Do not expect it there.

---

## 3. Per-form grants (`resource_grants`)

The four `.own` permissions are additionally gated per resource. Stack A's current grants:

| Account | Capacity | Forms |
|---|---|---|
| `editor@demo.test` | `editor` | Community Health Survey 2026 · Field Site Assessment · Referral Router · Quarterly Report (draft) |
| `reviewer@demo.test` | `reviewer` | Community Health Survey 2026 · Field Site Assessment · Referral Router |
| `owner@demo.test` | `editor` | Community Health Survey 2026 · Patient Intake · Referral Router · Staff Feedback 2025 |
| `owner@northwind.test` | `editor` | Referral Log |

Owner and Admin do not need a grant to act — they hold the `.any` permissions and work tenant-wide.
The grants on `owner@demo.test` exist so the *Collaborators* surface has rows to show.

---

## 4. Role → permission grid

The catalog is **closed**: five roles, twenty-nine permissions, defined once in
[`RolePermissionSeeder::MATRIX`](../database/seeders/RolePermissionSeeder.php) as global rows
(`tenant_id IS NULL`) shared by every tenant. No UI ever inserts a sixth role.

**O** = Owner · **A** = Admin · **E** = Form editor · **R** = Reviewer · **V** = Viewer

### Tenant administration

| Permission | O | A | E | R | V |
|---|:-:|:-:|:-:|:-:|:-:|
| `tenant.settings.manage` | ✅ | ✅ | — | — | — |
| `tenant.billing.manage` | ✅ | — | — | — | — |
| `tenant.billing.view` | ✅ | ✅ | — | — | — |
| `tenant.members.invite` | ✅ | ✅ | — | — | — |
| `tenant.members.remove` | ✅ | ✅ | — | — | — |
| `tenant.roles.assign` | ✅ | ✅ | — | — | — |
| `tenant.ownership.transfer` | ✅ | — | — | — | — |
| `scopes.manage` | ✅ | ✅ | — | — | — |

### Forms

| Permission | O | A | E | R | V |
|---|:-:|:-:|:-:|:-:|:-:|
| `forms.create` | ✅ | ✅ | ✅ | — | — |
| `forms.edit.any` | ✅ | ✅ | — | — | — |
| `forms.edit.own` | — | — | ✅ | — | — |
| `forms.publish.any` | ✅ | ✅ | — | — | — |
| `forms.publish.own` | — | — | ✅ | — | — |
| `forms.delete` | ✅ | ✅ | — | — | — |
| `forms.collaborators.manage` | ✅ | ✅ | — | — | — |

### Submissions

| Permission | O | A | E | R | V |
|---|:-:|:-:|:-:|:-:|:-:|
| `submissions.create` | ✅ | ✅ | ✅ | ✅ | — |
| `submissions.edit.any` | ✅ | ✅ | — | — | — |
| `submissions.edit.own` | — | — | ✅ | — | — |
| `submissions.review.any` | ✅ | ✅ | — | — | — |
| `submissions.review.own` | — | — | — | ✅ | — |
| `submissions.export` | ✅ | ✅ | ✅ | ✅ | — |
| `submissions.view` | ✅ | ✅ | ✅ | ✅ | ✅ |

### Dashboards and operations

| Permission | O | A | E | R | V |
|---|:-:|:-:|:-:|:-:|:-:|
| `dashboard.org.view` | ✅ | ✅ | — | — | ✅ |
| `dashboard.form.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `webhooks.manage` | ✅ | ✅ | — | — | — |
| `integrations.manage` | ✅ | ✅ | — | — | — |
| `audit_log.view` | ✅ | ✅ | — | — | — |
| `feedback.submit` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `feedback.view` | ✅ | ✅ | — | — | — |

**Totals:** Owner 25 · Admin 23 · Form editor 9 · Reviewer 6 · Viewer 4.

Owner deliberately holds **none** of the four `.own` permissions — it acts tenant-wide through `.any`.
Admin is exactly Owner minus `tenant.billing.manage` and `tenant.ownership.transfer`.

---

## 5. Destination → who can reach it

Nav visibility is `gate && feature` — a **permission** *and*, for four items, a **plan feature**. Both
resolve fail-closed off-tenant. Sources: [`nav-model.ts`](../resources/js/components/shell/nav-model.ts)
and [`ShellAbilities::for()`](../app/Support/Authorization/ShellAbilities.php).

All URLs are relative to a workspace host, e.g. `http://demo.localhost:8080/forms`.

| Destination | Ability key | Resolves to | Plan feature | O | A | E | R | V |
|---|---|---|---|:-:|:-:|:-:|:-:|:-:|
| `/dashboard` | — | (authenticated) | — | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/forms` | `manageForms` | `FormPolicy::viewAny` = `forms.create` ∨ `forms.edit.any` ∨ `forms.edit.own` | — | ✅ | ✅ | ✅ | — | — |
| `/submissions` | `viewSubmissions` | `submissions.view` | — | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/analytics` | `viewAnalytics` | `SavedReportViewPolicy::viewAny` = `dashboard.org.view` ∨ `dashboard.form.view` | `advanced_analytics` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/members` | `manageMembers` | `tenant.members.invite` | — | ✅ | ✅ | — | — | — |
| `/scopes` | `manageScopes` | `ScopeNodePolicy::viewAny` = `scopes.manage` | — | ✅ | ✅ | — | — | — |
| `/audit-log` | `viewAuditLog` | `AuditPolicy::viewAny` = `audit_log.view` | — | ✅ | ✅ | — | — | — |
| `/feedback` | `viewFeedback` | `feedback.view` | — | ✅ | ✅ | — | — | — |
| `/webhooks` | `manageWebhooks` | `WebhookEndpointPolicy::viewAny` = `webhooks.manage` | `webhooks` | ✅ | ✅ | — | — | — |
| `/integrations` | `manageIntegrations` | `ConnectionPolicy::viewAny` = `integrations.manage` | `native_connectors` | ✅ | ✅ | — | — | — |
| `/domains` | `manageDomains` | `tenant.settings.manage` | `custom_domain` (nav only) | ✅ | ✅ | — | — | — |
| `/settings` | — | (authenticated; panels gate on `tenant.settings.manage`) | — | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/search`, `/notifications` | — | (authenticated) | — | ✅ | ✅ | ✅ | ✅ | ✅ |

Two ability keys have no nav item of their own and gate in-page controls instead:
`assignRoles` (`tenant.roles.assign` — the Members page's per-row role control) and
`transferOwnership` (`tenant.ownership.transfer` — **Owner only**).

### 5.1 Plan features gate the same nav as the role does

| Feature | Free | Starter | Professional | Business | Enterprise |
|---|:-:|:-:|:-:|:-:|:-:|
| `webhooks` | — | ✅ | ✅ | ✅ | ✅ |
| `native_connectors` | — | ✅ | ✅ | ✅ | ✅ |
| `advanced_analytics` | — | — | — | ✅ | ✅ |
| `custom_domain` | — | — | — | ✅ | ✅ |
| `gamification` | ✅ | ✅ | ✅ | ✅ | ✅ |

⚠️ **`gamification` (K1a) is the one row here that gates nothing, and it is listed precisely so nobody
concludes it was forgotten.** It is granted on every tier by a product decision of record (ADR-0020 §D6);
it exists as a plan key only because the Settings → Modules card may offer nothing the plan catalog does
not know. The tenant's own module toggle is the only control over it — so a workspace where points are
missing has switched them off, and no upgrade will bring them back.

Current subscriptions: **`demo` = Business** · **`northwind` = Starter**.

> ⚠️ **`owner@northwind.test` cannot see Analytics or Domains** even though the Owner role grants both.
> Starter lacks `advanced_analytics` and `custom_domain`. This is the single most common way to
> misread this document: a role column of ✅ is necessary, not sufficient.

Business and Enterprise are seeded `is_active: false` (they are held from sale), so plan-gated items
are **hidden rather than locked with an upgrade CTA** — an upsell would point at a plan nobody can buy
(ADR-0011 §D9).

### 5.2 Where the nav gate is narrower than the route gate

- **`/domains`** — the nav item requires `custom_domain`, but the page's reads and deletes carry **no**
  `feature:` middleware, deliberately: a tenant downgraded off Business keeps a live, resolving
  hostname and must still be able to take it down (ADR-0012 §D9). `Settings/Index.vue` links the page
  once the tenant holds a domain, so losing the nav item is not the end of the path.
- **`/analytics`, `/webhooks`, `/integrations`** — a direct visit without the plan feature bounces off
  the route's `feature:` middleware. The nav simply never offers the destination.

---

## 6. Super-admin console — central host only

`http://localhost:8080/admin/*`, never on a workspace subdomain. Sign in as `admin@meridian.test`.

| URL | Page |
|---|---|
| `/admin/tenants` | Tenant list |
| `/admin/tenants/{tenant}` | Tenant detail (+ impersonation) |
| `/admin/users` | Platform user list |
| `/admin/audit-log` | Platform-wide audit ledger |
| `/admin/feedback` | Feedback reports across tenants |
| `/admin/settings` | Platform settings |
| `/admin/two-factor` | TOTP enrolment — the gate below |

Three middleware stack up: `EnsureSuperAdmin` → `EnsureSuperAdminMfa` → `RequireRecentPassword`.

> ## ⚠️ THE SUPER-ADMIN NEEDS A REAL AUTHENTICATOR APP — DO NOT "FIX" THIS
>
> `admin@meridian.test` is seeded **unenrolled** (`two_factor_confirmed_at IS NULL`) on purpose.
> Signing in works, then `/admin/tenants` redirects to `/admin/two-factor`. Scan the QR with any
> authenticator to get in.
>
> A pre-enrolled seed would carry a placeholder secret no authenticator can reproduce, locking you out
> **permanently** rather than saving a step — `DemoSeeder::ensureSuperAdmin()` says so in a comment.
> To reset, reseed (§9).

Impersonation lands on `/impersonate/{token}` inside the tenant. The tenant shell then shows a banner
carrying a boolean and an exit URL only — **never the operator's identity** (I11a finding S2).

---

## 7. Unauthenticated surfaces

**Public forms — no login at all.** `http://demo.localhost:8080/f/<slug>`:

| Slug | Form | Note |
|---|---|---|
| `patient-intake` | Patient Intake | |
| `site-assessment` | Field Site Assessment | |
| `community-survey` | Community Health Survey 2026 | |
| `referral-router` | Referral Router | |
| `staff-feedback-2025` | Staff Feedback 2025 | **Renders the "form is closed" state on purpose** |

Also public: `/f/resume/{resumeToken}` (save-and-resume) and `/f/{slug}/manifest.webmanifest`.
`demo` additionally holds one unpublished draft, and `northwind`'s single form (Referral Log) has no
`public_slug` — neither is publicly reachable.

**Auth pages — central host only** (`RequirePlatformHost`), at `http://localhost:8080`:
`/` (landing) · `/login` · `/register` · `/forgot-password` · `/reset-password/{token}` ·
`/two-factor-challenge` · `/email/verify` · `/user/confirm-password`.

**Developer surface:** `/docs/api` and `/docs/api.json` — the Scramble OpenAPI UI, behind
`RestrictedDocsAccess`.

---

## 8. API token abilities

Personal access tokens carry abilities from a fixed set; a user may only mint an ability they already
hold the RBAC permission for ([`ApiAbilities::ABILITY_TO_PERMISSION`](../app/Support/Api/ApiAbilities.php)).
Holding **any one** of the listed permissions grants the ability.

| Ability | Entitled by | O | A | E | R | V |
|---|---|:-:|:-:|:-:|:-:|:-:|
| `read:forms` | `forms.create` ∨ `forms.edit.any` ∨ `forms.edit.own` | ✅ | ✅ | ✅ | — | — |
| `write:forms` | the above ∨ `forms.publish.any` ∨ `forms.publish.own` | ✅ | ✅ | ✅ | — | — |
| `read:submissions` | `submissions.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `write:submissions` | `submissions.create` | ✅ | ✅ | ✅ | ✅ | — |
| `review:submissions` | `submissions.review.any` ∨ `.own` | ✅ | ✅ | — | ✅ | — |
| `export:submissions` | `submissions.export` | ✅ | ✅ | ✅ | ✅ | — |
| `read:analytics` | `dashboard.org.view` ∨ `dashboard.form.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `read:audit_log` | `audit_log.view` | ✅ | ✅ | — | — | — |
| `manage:webhooks` | `webhooks.manage` | ✅ | ✅ | — | — | — |
| `manage:integrations` | `integrations.manage` | ✅ | ✅ | — | — | — |
| `manage:scopes` | `scopes.manage` ∨ `forms.collaborators.manage` | ✅ | ✅ | — | — | — |
| `manage:settings` | `tenant.settings.manage` | ✅ | ✅ | — | — | — |
| `manage:domains` | `tenant.settings.manage` | ✅ | ✅ | — | — | — |
| `read:gamification` *(K1d)* | `dashboard.org.view` ∨ `dashboard.form.view` | ✅ | ✅ | ✅ | ✅ | ✅ |

⚠️ **`read:gamification` is the one row above whose ability and whose ACCESS are not the same shape,
and the table cannot show it.** Every role can mint it, because every role may see their **own** points,
streak and standing — that is ADR-0020 §D7, which mints no thirtieth permission. What the ability does
not decide is the **named** ladder: `GET /gamification/leaderboard` additionally carries
`can:viewAny,PointAward`, whose single arm is `dashboard.org.view`, so **Owner / Admin / Viewer see
colleagues by name and Form Editor / Reviewer see only themselves.** The ability scopes the token; the
policy decides which half of the feature the caller gets.

⚠️ **And a Free tenant reaches neither route**, because the whole `/api/v1` group carries
`feature:api_access`, which the Free tier does not grant — while `gamification` itself is granted on
every tier (§5.1). So on Free the feature exists and its API does not; the in-app surface is the only
door. Recorded because the two facts sit in different tables and read as a contradiction otherwise.

---

## 9. Authentication caveats

> ### ⚠️ "Continue with Google" is OFF on this machine — and that is a supported state
>
> `.env` has no `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`, so `GoogleSignInGate::configured()` is
> false: **the button does not render and `/auth/google/redirect` returns 404.** Live Google
> credentials are an input only the product owner can supply, so the whole feature is
> absent-by-default; every behaviour behind it is still exercised in tests against
> `FakeGoogleIdentityProvider`.
>
> The `GOOGLE_CONNECTOR_CLIENT_ID` that *is* present in `.env` is the **Google Sheets connector** — a
> different credential for a different feature. Setting it does not turn on sign-in.

Other things that will look like bugs and are not:

- **Personal 2FA still applies to a Google-linked account, and deliberately does NOT after a SAML
  sign-in.** Two doors, two decisions: ADR-0019 §D11 for Google (the J3c decision — a consumer
  credential the end user chose, which this product cannot know is protected by anything), ADR-0016
  §D32 for SAML (an enterprise trust anchor the workspace administrator configured, so the identity
  provider is the authentication authority at that door). Google linking happens only onto an
  already-verified account.
- **`invited@demo.test` cannot sign in** — it is a pending invitation with a random discarded password.
- **`CENTRAL_DOMAIN=localhost`** in `.env`, diverging from `meridian.test`, because the super-admin
  console is pinned to `config('tenancy.central_domain')` and `meridian.test` has no DNS on this box.
  Revert it (and add hosts entries for `meridian.test` + `acme.meridian.test`) before running the
  Playwright specs, which default to `acme.meridian.test:8000`.
- **Counting rows via Eloquent on the default connection returns 0 for RLS tables** with no tenant
  context. Query as the `meridian` superuser in psql instead — every figure in this document was.

---

## 10. Resetting the fixture

```bash
# Converges on an existing database — idempotent, every row is keyed.
docker compose exec app php artisan db:seed --class="Database\Seeders\DemoSeeder"

# Full reset — drops and rebuilds (~30 s). Also clears the super-admin's TOTP enrolment.
docker compose exec app php artisan migrate:fresh --seed
```

Everything runs inside Docker: the Windows host has no `pdo_pgsql` extension and no `rolldown` win32
binding. Never run two Pest processes at once — `migrate:fresh` drops the schema underneath the other.

---

## Verifying this document

If any table above looks wrong, these five checks settle it against the running system.

```bash
# 1. Accounts, workspaces and roles (§2)
docker exec dev_formbuilder_app-postgres-1 psql -U meridian -d meridian -c \
  "select u.email, t.slug, r.name, tu.status from tenant_users tu
     join users u on u.id = tu.user_id
     join tenants t on t.id = tu.tenant_id
     left join model_has_roles mhr on mhr.model_id = u.id and mhr.tenant_id = t.id
     left join roles r on r.id = mhr.role_id order by t.slug, u.email"

# 2. Plan per workspace (§5.1)
docker exec dev_formbuilder_app-postgres-1 psql -U meridian -d meridian -c \
  "select t.slug, p.code from subscriptions s
     join tenants t on t.id = s.tenant_id join plans p on p.id = s.plan_id"

# 3. Google sign-in is off (§9) — expect 404
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/auth/google/redirect
```

4. Sign in at <http://demo.localhost:8080/login> as `viewer@demo.test`. ⚠️ **The workspace host, not the
   platform host — corrected by Increment M46 (2026-08-29), which found this step sending the reader to
   `localhost:8080/login`, the exact dead end the warning block at the top of this document measures.**
   Step 5 below already used a workspace host, so the inconsistency read as intentional. The sidebar must
   show exactly Submissions, Dashboard, **Achievements**, Analytics and Settings — no Forms, Members,
   Scopes, Audit log, Feedback, Webhooks, Integrations or Domains (§5). ⚠️ **Achievements is on this list
   because it is the one
   destination with no permission gate at all** — it is keyed on the `gamification` module switch only, by
   design (every member sees their own points and standing; only the NAMED ranked list is gated, on
   `dashboard.org.view`, and it is withheld inside the page rather than by hiding the page).
5. Sign in as `owner@northwind.test` at <http://northwind.localhost:8080>. Analytics and Domains must
   be **absent** despite the Owner role — that is §5.1 proving itself.
