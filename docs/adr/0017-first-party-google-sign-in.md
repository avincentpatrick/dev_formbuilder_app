# ADR-0017: First-Party Google Sign-In (identity linkage, one central callback, a single-use tenant handoff)

## Status

**Proposed — 2026-08-14.** Increment J3c2 adds "Continue with Google" to the two front doors. The product
already has three doors into a workspace — self-registration, invitation, and SAML JIT provisioning
(ADR-0016) — and this is the fourth. It differs from all three in one way that drives every decision
below: **the identity is asserted by a consumer account the end user chose, not by a trust anchor a
workspace administrator configured.** That is why this ADR exists rather than an amendment to ADR-0016,
and why §D11 below deliberately diverges from ADR-0016 §D22's answer to the same question.
**Decision: take one central callback and a signed stateless `state` from ADR-0009 §D2/§D3, add a
hashed single-use DB handoff for the leg that creates a session, key the local identity on Google's `sub`
with the email as a one-time joining hint that may only link onto an already-verified account, reuse
ADR-0016 §D20's membership outcomes verbatim with `RegistrationGate` in place of the SSO JIT toggle, and
keep personal two-factor authentication in force.**

- **Deciders:** product owner (the three decisions of record dated 2026-08-14), engineering
- **Related ADRs:** **ADR-0009** (its §D2/§D3/§D4 are taken and its Socialite rejection is carved out
  there, not here) · **ADR-0016** (§D20's membership outcomes are reused; §D22's second-factor answer is
  deliberately NOT) · **ADR-0002** (the RLS shape that makes the central arm row-free)
- **Related docs:** `docs/multi-tenancy-rbac-design.md` §5 · `docs/security-threat-model.md` §8

---

## Context

1. **There are no social columns anywhere.** `users` carries `id, name, email, email_verified_at,
   password (NOT NULL), remember_token, two_factor_*, is_super_admin, last_active_tenant_id,
   tos_accepted_at, privacy_policy_accepted_at, timestamps, deleted_at`. No `google_id`, no `provider`,
   no `identities` table.
2. **The session cookie is host-only.** `SESSION_DOMAIN` is null, so a session established on
   `demo.example.com` is not sent to the central host. ADR-0009 §Context 3 records this, and two route
   files once claimed the opposite.
3. **Google rejects wildcard redirect URIs** for an identity client exactly as for an API client, so the
   callback host is fixed and central — ADR-0009 §D2's premise, unchanged.
4. **Registration is already gated twice**, by `registration.open_signup` at the platform and
   `registration.invite_only` per workspace, and `RegistrationGate` exists precisely so the route guard
   and the login page's link cannot disagree.
5. **Live Google credentials are an input only the product owner can supply**, and the build was not
   permitted to wait on them.
6. **A pre-auth write with no `app.current_user_id` silently affects zero rows.** This has now bitten the
   product three times (PR #147's six endpoints, J3c1's challenge read, J3c1's recovery-code rotation).
   Anything this flow writes before `Auth::login()` must be treated as suspect by default.

**The core tension.** A sign-in is the largest side effect the application has, and it must be completed
on a host that the callback cannot reach with a cookie. Everything below is a consequence of taking that
seriously while refusing to widen either the session cookie's scope or the meaning of an RLS context.

---

## Decision

> **Google sign-in reuses ADR-0009's topology for a different reason, keys identity on `sub` rather than
> on the address, links onto an existing account only when that account is already verified, and creates
> the session only on a same-site hop redeemed exactly once.**

| Concern | Choice |
|---|---|
| Where the local identity lives | `users.google_id`, unique, nullable (§D1) |
| What identifies the person | Google's `sub`; the email is a joining hint only (§D2) |
| What makes the assertion trustworthy | `email_verified === true`, checked twice (§D3) |
| Linking to an existing account | Only onto a verified account with no other `google_id` (§D4) |
| Keeping the address current | Never — `users.email` is not rewritten (§D5) |
| Crossing the host boundary | Signed stateless `state` out, hashed single-use row back (§D6, §D7) |
| Workspace membership | ADR-0016 §D20 verbatim, gated by `RegistrationGate` (§D8) |
| What a refusal looks like | One indistinguishable bounce; the log, never `audits` (§D9) |
| The Google client | Socialite behind `GoogleIdentityProvider` (§D10, carve-out in ADR-0009) |
| A user's own second factor | Still enforced — diverges from ADR-0016 §D22 (§D11) |
| The central host | Same flow, no handoff, no row (§D12) |

### The twelve sub-decisions

- **D1 — A `google_id` column on `users`, not a `social_identities` table.** The account-existence check
  must run on the `pgsql_auth` connection (`CreateNewUser.php`'s `Rule::unique('pgsql_auth.users','email')`
  records why: as `meridian_app` with no user context, fail-closed RLS on `users` HIDES an existing row,
  so a duplicate surfaces as a raw unique-index 500 instead of a clean outcome). A column rides the
  existing table-level `GRANT SELECT, UPDATE ON users TO meridian_auth` and the existing `TO meridian_auth`
  policy for free; a second table needs a new GRANT and a new policy **on the most sensitive role in the
  deployment**, to buy generality for a second provider that scope has explicitly ruled out. Converting
  later is one table plus a mechanical backfill. **Revisit trigger:** a second identity provider, or a
  requirement to link two Google accounts to one user.

- **D2 — The `sub` is the identity; the address is a one-time joining hint.** Google's `sub` is stable for
  the life of the account. The address is not: a Workspace administrator can reassign
  `alice@company.com` to a new employee after Alice leaves. Keying an account off the address would make
  a staff change into an account takeover. So the email is consulted **only** on the first sign-in, to
  find an account to link, under §D4's conditions; every subsequent sign-in resolves on `google_id`.

- **D3 — `email_verified === true` is this flow's signature check, and it is asserted twice.** It is the
  entire basis for believing the address belongs to the account holder — without it, anyone who can
  attach an unverified alias to a Google account can sign in as that address. Strict identity comparison,
  never a cast: the claim arrives in a raw JSON array where it may be absent, `null`, the string
  `"true"`, or `1`, and `(bool) "false"` is `true`. Checked at the callback **and** again in the
  provisioner, because the two are separated by a host boundary and a stored row. **Method note:** the
  seven fail-closed shapes are pinned in `tests/Unit/Auth/GoogleIdentityTest.php` rather than argued here.

- **D4 — Auto-link only onto an account that is already verified and carries no other `google_id`.**
  *User decision of record, 2026-08-14.* An unverified local account is **refused** — otherwise anyone
  who registers an unverified account on your address, then signs in with a Google account bearing that
  address, is handed your account. A local account already bound to a **different** `sub` is also refused,
  which is what closes the reassigned-Workspace-address path §D2 describes. **Accepted cost:** a person
  who registered with a password and never verified must verify (or use their password) before Google
  will link, and the refusal is indistinguishable from every other refusal per §D9, so they are not told
  which. That is the disclosure posture working as designed, and it is a real usability cost.

- **D5 — `users.email` is NEVER rewritten from Google.** The address is a joining hint (§D2), the local
  row is the record of what this product knows, and a silently-updated address changes who receives
  password resets and workspace notifications. A person whose Google address changes keeps their account
  and their local address; nothing breaks, because `google_id` is what resolves them.

- **D6 — One central callback, and the outbound `state` stays a stateless signed HMAC.** ADR-0009 §D2 and
  §D3 taken as they are, and amended there rather than restated here. Replaying the outbound leg only
  re-opens a consent screen, and the authorization `code` behind it is single-use at Google — §D3's
  original bound, still sound for the leg it was written about.

  > **⚠ D6a — THE MINT ROUTE TAKES FORTIFY'S PIPELINE, NOT THE TENANT ONE, AND THE FIRST PLAN HAD THIS
  > WRONG.** The obvious placement for `GET /auth/google/redirect` is `routes/tenant.php` beside the SAML
  > login path, behind `InitializeTenancyBySubdomain` + `PreventAccessFromCentralDomains`. **That cannot
  > serve §D12's central arm**, which is a decision of record: `routes/tenant.php` declares no
  > `->domain()`, so a central-host request matches the route and dies in identification. Nor can the URI
  > simply be registered in both files — `routes/google-auth.php` is loaded from `withRouting(then:)`
  > while `routes/tenant.php` is mapped later from `TenancyServiceProvider::mapRoutes()` inside
  > `booted()`, so the tenant copy would be **dead code that no test notices**. And ADR-0009 §D2's
  > `Route::domain(config('tenancy.central_domain'))` is already ruled out here because it does not match
  > `localhost`, which would make the flow unexercisable against the fake on a dev box.
  >
  > So the mint takes `/login`'s own shape: `web` + `RequirePlatformHost`, **no** `Route::domain()`, ONE
  > registration serving the central host and every tenant subdomain, 404 on exactly one class of host —
  > a custom domain, which is the phishing surface a domain constraint would actually be for (H22a). The
  > workspace is resolved from the HOST by `PlatformHost::tenantFor()`, exactly as `RegistrationGate`
  > already resolves it for `/register` with no tenancy middleware at all.
  >
  > **Accepted cost, and it is a real one:** that route therefore has no ambient RLS context, and
  > `google_auth_requests` is strict-RLS — so `GoogleAuthRequestService::mint()` must BORROW a context
  > inside a transaction (`TenantSettingRegistry::forTenant()`'s idiom) or the INSERT is refused. A
  > refusal is the safe direction, but it is one more place where a missing GUC is the failure mode. Only
  > the completion hop is tenant-only, and it keeps the full pipeline and a real GUC.
  >
  > **Revisit trigger:** `routes/tenant.php` ever gaining a `->domain()`, which would make the collision
  > argument above obsolete and the tenant placement viable again.

- **D7 — The handoff back to the tenant host is a hashed, 60-second, single-use DB row.** This is the leg
  §D3's revisit trigger fires against, because it is the one that creates a session. `google_auth_requests`
  stores the SHA-256 of a token that travels in a URL (the `impersonation_tokens` precedent), and
  redemption is a **conditional UPDATE whose affected-row count is the check** — never a read-then-write,
  the shape this codebase has now ruled out three times. Ordering is pinned at the database by CHECK
  constraints (`handoff_hash IS NULL OR consumed_at IS NOT NULL`; `completed_at IS NULL OR handoff_hash
  IS NOT NULL`), so a row cannot claim to have been redeemed before it was issued. The table is bounded
  **on the write path** — a per-tenant row cap plus a retention window, trimmed in the same call as the
  insert — because `routes/console.php` records that nothing runs the scheduler on the production box, so
  a nightly prune would be a bound that exists in the repository and not on the machine. ⚠️ The cap is
  **"not among the newest N"**, never "older than the Nth": an unauthenticated mint endpoint can write
  many rows inside one second, and a timestamp comparison keeps every tied row — i.e. fails at exactly
  the volume it exists for.

- **D8 — Membership reuses ADR-0016 §D20 verbatim, with `RegistrationGate` where SSO has its JIT toggle.**
  Active → in, no write. **Suspended → REFUSED** (an administrative sanction a fourth door must not
  quietly reverse). **Invited → activated at the INVITED role**, not the default. Absent/Declined/Removed
  → gated: SSO asks `jit_provisioning_enabled`; this door asks `RegistrationGate`, which is the same
  answer the login page uses to decide whether to show the button at all — one gate, two consumers, which
  is that class's stated reason for existing. A full seat quota **refuses**, inside the transaction, so no
  orphaned `users` row survives. **Stated consequence:** on a default workspace (`invite_only` is
  fail-closed TRUE) Google works for existing members and invited people only, and a stranger is refused.
  That is correct, and it is not a bug report.

- **D9 — One indistinguishable bounce, to the log, never to `audits`.** Every refusal — unknown handoff,
  expired, replayed, wrong tenant, unverified email, suspended member, registration closed, quota full —
  produces the same `?google=failed`. The value is **closed**; no provider or provisioner string is ever
  echoed, which would be both a disclosure oracle and a reflected-content sink. Refusals are logged and
  never written to `audits`: that table is append-only by RLS policy and never pruned, so an
  unauthenticated endpoint writing to it on every rejection is an amplification primitive (ADR-0016 §D19).
  **No `google_auth_failures` table.** `sso_auth_failures` exists because a tenant admin configures a SAML
  trust anchor and must debug their own certificate; Google sign-in has no tenant-side configuration to
  get wrong, so those rows would feed no surface. **Revisit trigger:** any tenant-visible configuration
  for this flow.

- **D10 — Socialite, behind `GoogleIdentityProvider`, faked in tests.** ADR-0009 rejects Socialite by
  name; the carve-out answering that rejection's three reasons individually lives in **ADR-0009's own
  Alternatives Considered**, where the rejection is, rather than here. The interface exists because live
  credentials are a product-owner input and the build could not wait on them: every downstream behaviour
  is exercised against a recording fake. **Accepted cost:** Socialite pulls four transitive packages, three
  of which serve OAuth1 providers this product will never use.

- **D11 — A user's own second factor still applies, and this DIVERGES FROM ADR-0016 §D22 ON PURPOSE.**
  *User decision of record, 2026-08-14.* A member with confirmed 2FA who signs in with Google is handed
  to `/two-factor-challenge` rather than logged straight in. ADR-0016 decided the opposite for SAML —
  "the IdP is the authentication authority; a workspace whose IdP performs MFA turns the setting off" —
  and that reasoning does not transfer: **SAML is an enterprise trust anchor a workspace administrator
  chose and configured; a Google account is a consumer credential the end user chose**, and the product
  has no way to know whether it is protected by anything. A user who deliberately enrolled a TOTP would
  otherwise find it silently bypassed by a button on the same page. Mechanically this reuses Fortify's own
  shape — write `login.id`, redirect to the challenge — which is why J3c1 had to make that page reachable
  first. **Revisit trigger:** a per-workspace setting expressing "our IdP already performs MFA", which
  would make this a policy rather than a constant.

- **D12 — The central host runs the same flow with no handoff and no row.** Callback and session share a
  host there, so the hop has nothing to carry. It also *cannot* have a row:
  `TenantIsolation::nullableGlobalSql()` widens SELECT only — *"every write stays strict: a tenant
  connection cannot create/alter a global row"* — so a tenant-less row is unwritable on the app
  connection, and reaching for the privileged connection on an unauthenticated endpoint to get one would
  be a far worse trade. Its replay bound is therefore §D6's: Google's single-use `code` plus the state's
  expiry. A central-host Google sign-up produces an account with no workspace, which is exactly what
  central-host registration produces today. **Revisit trigger:** any requirement that makes the central
  arm's callback non-idempotent beyond creating the account it names.

**Method note.** §D1, §D7 and §D12 are consequences of measured RLS behaviour and are cited to the SQL
that produces them. §D4 and §D11 are product decisions with security consequences, taken by the product
owner on 2026-08-14 and recorded here so they are not re-litigated as engineering preferences. §D3's
seven fail-closed shapes are asserted in tests, not argued.

---

## Consequences (chosen path: **one callback, one handoff row, `sub` as the key**)

### Positive
- A Google sign-in cannot be used to take over an account that never proved it owns its address (§D3, §D4).
- A reassigned Workspace address cannot inherit the previous holder's account (§D2, §D4).
- Personal 2FA means what the user thought it meant on every door (§D11).
- The whole flow is testable with no Google credentials, so the increment shipped without a blocked input.
- Membership behaviour cannot drift from SSO's, because it is literally the same method (§D8).

### Negative / accepted trade-offs
- **A person with an unverified local account on the same address is refused and not told why** (§D4 + §D9).
- **Socialite's OAuth1 transitive dependencies are carried for nothing** (§D10).
- **A `google_id` column is provider-specific** and a second provider means a migration (§D1).
- **`google_auth_requests` grows on an unauthenticated endpoint** and is bounded only by its own
  write-path trim (§D7); a mistake there is a disk-space issue, not a correctness one.
- **On a default workspace the button appears for people it will refuse** — the gate governs sign-*up*,
  and an existing member signing *in* is a different question the button cannot ask in advance (§D8).

### Risks & Mitigations
| Risk | Mitigation |
|---|---|
| The link write silently affects zero rows (Context 6) | It runs on `pgsql_auth` and **asserts the affected-row count is 1**; a test reads it back on that connection |
| Two concurrent redemptions of one handoff | Conditional UPDATE, affected-row count is the check (§D7) |
| A handoff minted for tenant A redeemed on tenant B | The row names its tenant and the redeeming host must match |
| `email_verified` regressing to a loose check | Seven fail-closed shapes pinned as unit cases (§D3) |
| The 2FA fork being "simplified" away later | §D11 states the divergence and its reason; a test drives an enrolled user to the challenge |

---

## Alternatives Considered

- **A `social_identities` table** — rejected per §D1 for now: it needs a new GRANT and a new RLS policy on
  `meridian_auth`, the most sensitive role in the deployment, to generalise for a provider that scope has
  ruled out. Named as the conversion path if a second provider is ever taken.
- **Auto-linking on any Google-verified address** — rejected per §D4: it hands an account to whoever
  registered an unverified local row on that address first.
- **Rewriting `users.email` from Google on each sign-in** — rejected per §D5: it silently moves where
  password resets go.
- **A stateless HMAC handoff with no row** — rejected per §D7: an HMAC proves we minted a token; it cannot
  prove the token has not been presented before, and single-use requires remembering.
- **Widening `SESSION_DOMAIN` so the callback can read the tenant session** — rejected exactly as ADR-0009
  rejected it: a permanent cross-host cookie to solve a problem that arises once per sign-in.
- **Per-tenant Google OAuth clients** — rejected: Google requires exact pre-registered redirect URIs, and
  it would make every workspace configure a Google Cloud project to use a consumer sign-in button.
- **Treating a Google sign-in as satisfying two-factor** — rejected per §D11, against the SAML precedent
  and with the reason for the divergence stated.

## When to Revisit
- **A second identity provider is requested** — §D1's column becomes a table; §D10's seam already isolates
  the driver.
- **A workspace asks for "our IdP already performs MFA"** — §D11 becomes a setting.
- **A real reassigned-address incident** — evidence about whether §D4's refusal is calibrated correctly.
- **Any tenant-visible configuration for this flow** — §D9's "no failures table" is then wrong.
- **Google sign-in ever needing to hold a Google API token** — it stops being this ADR and becomes
  ADR-0009's, in full.

## Related Decisions
ADR-0002 (RLS, and §D12's write-strictness) · ADR-0009 (topology, and the Socialite carve-out) ·
ADR-0016 (§D20 reused, §D22 diverged from)

## References
- `app/Support/Auth/GoogleIdentity.php` — §D3's strict claim check and its fallback rules.
- `app/Support/Tenancy/TenantIsolation.php` — `nullableGlobalSql()`, the source of §D12.
- `app/Actions/Fortify/CreateNewUser.php` — why any existence check resolves on `pgsql_auth` (§D1).
- `app/Services/Tenancy/TenantMembershipService.php` — `attachMember()`, the §D8 outcomes.
- `docs/security-threat-model.md` §8 — the authentication rows J3c1 added, which §D11 extends.
