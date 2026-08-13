# ADR-0016: SAML 2.0 Single Sign-On — a per-tenant Service Provider, SP-initiated only, behind a protocol-neutral seam

## Status

**Accepted — 2026-08-13.** Authored across its own code increment (**P1a**, in two commits), on the ADR-0012/H22a, ADR-0013/H25, ADR-0014/H23a1 and ADR-0015/I7a precedent: the genuinely open decisions here are small enough to belong with the code rather than ahead of it, and large enough that a decision made in a controller comment is a decision nobody can find later. The number was already being cited by `P1a (1/2)`'s commit message and by ~15 docblocks before this file existed; this closes those references.

Phase 4's first row. `docs/PRD.md` §5 scopes SSO/SAML to Phase 4 and `docs/pricing-feature-gating-matrix.md:54` puts `sso_saml` on Enterprise alone.

- **Deciders:** Product owner (the four design forks were brought on 2026-08-13 and answered the same day: SAML 2.0 only behind a protocol-neutral seam; step-up re-authenticates via the IdP with `ForceAuthn="true"`; JIT provisioning at a tenant-configured default role with a per-tenant toggle defaulting ON; and `onelogin/php-saml ^4.3` over hand-rolling the assertion layer). Founding engineering (architecture owner).
- **Related ADRs:** **ADR-0002** (shared-DB RLS) — both tables take the strict shape, and §D5 there is why `sso_auth_requests` carries a *composite* foreign key. **ADR-0008** (entitlement & metering) — §D6 seeds Enterprise `is_active = false`, so nothing here can be bought yet; §D4 below inherits the `sso_saml` key rather than coining one. **ADR-0009** (OAuth connector token custody) — §D3 there declares its stateless HMAC nonce insufficient once "a callback gains a side effect beyond writing the connection it names", and §D8 below is the first place that limit actually binds. **ADR-0012** (custom-domain resolution) — §D1 confines custom hosts to the public guest runtime, which is why §D3 below pins the ACS to the canonical subdomain; §D9 is the direct precedent for §D5's gating asymmetry.
- **Related docs:** `docs/data-dictionary.md` (the `sso_connections` / `sso_auth_requests` section, added by this increment — the tables had never had one); `docs/audit-compliance-logging-spec.md` §1 and §2 (the `sso_connection` alias and its redaction entry); `docs/multi-tenancy-rbac-design.md` §5 and §7 (Owner is established only by ownership transfer, which is why §D7 excludes it); `docs/pricing-feature-gating-matrix.md`.

---

## Context

1. **The entitlement key already existed and enforced nothing.** `sso_saml` has been in `PlanCatalog::FEATURE_KEYS` since H5a, granted to Enterprise, and `ToggleableModules` deliberately excludes it from self-service toggles — so `EntitlementService::feature('sso_saml')` already returned the plan grant correctly and needed no change. It had **zero enforcement consumers**. The gate was the only missing part, which is the third time a seeded permission/entitlement key with no consumer has turned out to mark exactly where the work was.

2. **The framework had nowhere to put a login that is not a password.** Every authenticated path resolves a user through `RlsAwareUserProvider` on the pre-auth `pgsql_auth` connection, because the join-shaped FORCE RLS on `users` fails closed before any tenant context exists. SSO adds a second way to establish that session, and it arrives from a third party.

3. **A SAML SP is a small amount of protocol and a large amount of ways to get it wrong.** Signature wrapping, unsolicited assertions, replay, audience confusion and clock skew are each a complete authentication bypass, and each has a documented CVE history across mature implementations. That is an argument for a library at the XML-DSig layer and for writing the posture down rather than leaving it in code.

4. **Two things about this deployment make the standard advice inapplicable.** The tenant is resolved from the **host**, not from anything in the request, and a custom domain may already point at the platform (ADR-0012) — so "the ACS URL" is not a single obvious string. And the OAuth connector seam next door (ADR-0009 §D3) deliberately has **no server-side nonce store**, which is a tempting pattern to reuse and the wrong one here.

5. **Nothing here needs a third-party credential.** Unlike the connector rows, a SAML SP is configured entirely by the tenant, against their own IdP. There is no client id to register and no vendor review — so this row was buildable end to end with no input the user alone possesses.

---

## Decision

**A per-tenant SAML 2.0 Service Provider, one connection per tenant, SP-initiated only, gated on the existing `sso_saml` entitlement — with the protocol behind a neutral seam so the second protocol is a new case rather than a migration.**

### The sub-decisions

**§D1 — SAML 2.0 only, behind a protocol-neutral seam.** `sso_connections.protocol` is a `SsoProtocol` enum with one case, CHECK-constrained to it. A one-case enum is not over-engineering: the discriminator has to exist *before* the second protocol, or the first one's assumptions leak into column names and every consumer learns to read `saml_*` columns it must later unlearn. Adding OIDC is a new case plus a new driver, never a table rename. It is deliberately not a boolean or a nullable column, because a two-state string that only ever holds one value still forces every read site to answer "which protocol is this?" — the question that keeps the driver lookup honest when the second case lands.

**§D2 — One connection per tenant.** `sso_connections` is UNIQUE on `tenant_id`. Multi-IdP is not a schema question, it is a *login surface* question — with two IdPs the login page must ask which one, and home-realm discovery is its own design. Deferred rather than half-built. The settings resource is therefore a **singleton**: `/settings/sso` takes no id segment, and the service scopes by RLS alone rather than by a `where`.

**§D3 — The SP entity id and the ACS location are DERIVED at request time from the canonical tenant subdomain, never configured.** `config/saml.php` holds no absolute URL at all. ADR-0012 lets a tenant's public host change; an IdP-registered ACS URL is static, and a custom domain must never appear in either. `SsoMetadataController::entityId()` and `::assertionConsumerServiceUrl()` are the single composition point — every consumer, including the later `Destination` and `Recipient` checks, must call them rather than re-derive, or the check compares a string against itself.

**§D4 — The protocol endpoints answer 404 for every failure; the settings endpoints use the ordinary `feature:` gate.** These are different surfaces and the split is deliberate. `RequireFeature` answers a web request with `back()` plus a toast, which is meaningless for a cross-origin POST from an identity provider; and any answer other than 404 discloses that another organisation has SSO configured, to an unauthenticated caller who supplied nothing but a hostname. So unentitled, unconfigured, draft and disabled are **indistinguishable** on the protocol routes — the `PwaManifestController` posture, on a surface where the disclosure matters more. `SsoGate` is the single place that decides.

**§D5 — The trust-anchor write is gated on `feature:sso_saml`; the read, the STATUS TOGGLE and the delete are not.** ADR-0012 §D9 applied to a trust anchor. A tenant downgraded off Enterprise still has an IdP configured against this SP; gating the read would strand them with a trust relationship they can see nowhere and remove nowhere.

⚠️ **The status toggle is on the ungated side, and the first draft of this decision got it wrong in a way worth recording.** Putting `status` on the gated policy write left a downgraded tenant able to **delete** the connection but not **disable** it — "destroy or nothing", which is the *opposite* of an escape hatch, and directly against `SsoConnectionStatus::Disabled`'s own contract ("retained rather than deleted so the trust anchor and its audit history survive a temporary suspension"). So `PATCH /settings/sso/status` carries `can:tenant.settings.manage` alone, and the service refuses a transition **to** `Active` when the tenant is not entitled. The rule is: **a tenant may always undo, never redo.**

**This asymmetry must not be tidied into consistency** — it is the third instance in this codebase (custom domains, branding, now SSO), each written as a deliberate exception, and each pinned in both directions by a test because making the routes match looks like a cleanup.

**§D6 — There is no navigation entry, and the way in is a `/settings` signpost.** Enterprise is seeded `is_active = false` (ADR-0008 §D6), so a feature-gated sidebar row would be invisible in every environment and every test — an unreachable registry row, which is the no-consumer smell this codebase has been bitten by before. The signpost card renders on **entitled OR already configured**, which is strictly wider than the custom-domains card's `count > 0` and has to be: an entitled tenant with no connection yet still needs a first way in, and a downgraded tenant with one still needs a way out.

**§D7 — JIT provisioning writes on the DEFAULT connection at a tenant-configured role, and can never mint an Owner.** The first plan said `pgsql_privileged`, reasoning from the FORCE RLS on `users`. That premise is real and the prescription was wrong: `users_app_insert` is `WITH CHECK (true)`, so the app connection inserts fine with no context — the live precedents are `CreateNewUser` and `TenantMembershipService::resolveOrCreateUser()`, which resolve an existing identity on `pgsql_auth` and hop the model back before writing. `default_role_name` is CHECK-constrained to the seeded role catalog **minus `owner`**, because RBAC §5 establishes Owner only by ownership transfer and an IdP attribute must never be a path to it.

**§D8 — Replay protection is a server-side ledger, and ADR-0009 §D3's stateless nonce is explicitly NOT reused.** ADR-0009 is self-limiting by its own terms: it declares the stateless `state` insufficient once "a callback gains a side effect beyond writing the connection it names", and **a SAML ACS establishes a session** — the largest side effect in the application. An HMAC proves we minted a token; it cannot prove the token has not been presented before, and single-use *is* replay protection. Hence `sso_auth_requests.consumed_at`, consumed as an **atomic conditional UPDATE whose affected-row count is the check** — never read-then-write, which leaves two concurrent replays both seeing NULL. A second, independent mechanism keys a cache ledger on the assertion's own `@ID`, covering an IdP that mints two assertions for one request.

**§D9 — IdP-initiated SSO is refused, permanently.** An unsolicited assertion carries no `InResponseTo`, so there is nothing binding it to a request this SP minted; accepting one is a login-CSRF primitive — an attacker replays their own IdP's assertion at a victim's browser and silently swaps the session. `config/saml.php` sets `allow_unsolicited => false` as a constant rather than an env var, along with `want_assertions_signed` (signing only the envelope is what makes signature wrapping exploitable) and `want_name_id`. These are security decisions, not settings: an operator toggling one would widen the threat model of every tenant at once.

**§D10 — The IdP metadata is a pasted document, imported as a WHOLE HALF, and never fetched by URL.** A partial import is precisely how a stale trust anchor survives a key rotation, so `PUT /settings/sso/idp-metadata` replaces the entire IdP half atomically — entity id, SSO URL, certificates, fingerprint and NameID format together — while `PATCH /settings/sso` amends policy. Keeping them separate is also what makes the ledger legible: with one combined verb, flipping the JIT switch would be indistinguishable from a key rotation, and "the trust anchor changed" is the one event a security reviewer searches for. A "fetch from metadata URL" importer is **refused**: fetching a tenant-supplied URL server-side is SSRF, and this deployment has no egress allow-list (ADR-0012 §D11 already declined to build that authority).

**§D11 — Certificate expiry is a settings-screen concern, not an import refusal, and a rollover pair is HEALTHY.** An IdP legitimately publishes a not-yet-active successor alongside its current key during a rotation; refusing the document would make a correctly-executed rollover fail at the SP. So the parser checks parsability and not validity dates, and the settings page surfaces per-certificate state. The roll-up rule is that **any currently-valid certificate means the connection is fine** — flagging the healthy rollover pair red is how an indicator becomes noise that admins learn to ignore.

**§D12 — The certificate body never leaves the service layer, and `getOriginal()` is the trap.** `idp_certificates` is `encrypted:array` — an **integrity** claim, not a confidentiality one, since a signing certificate is public by construction: anything that can silently rewrite that column can make the application trust assertions minted by a key of the attacker's choosing, which is a total authentication bypass for that tenant. But the column must still stay out of props, logs and audit payloads, and the obvious way to build an audit diff defeats that: **`Model::getOriginal()` maps every attribute through `transformModelValue()`, so it returns the DECRYPTED array**, and `$hidden` guards only `toArray()`/`toJson()`. The repo's own snapshot idiom (`Arr::only($model->getOriginal(), …)`) would therefore write plaintext certificates into `audits`, a table that is append-only by RLS policy and never pruned. So `SsoConnectionService` hand-builds its snapshot, and `AuditRedactor::SECRETS` registers `idp_certificates` as the mechanical backstop — not because it is a secret, but so a later "simplification" produces `[REDACTED]` and a `redacted_fields` entry instead of a silent wall of base64 in an immutable table.

**§D13 — The audit alias is `sso_connection`, keyed on `tenants.id`.** Its own alias rather than folding into `settings`: the branding precedent turns explicitly on branding *rendering inside* `/settings` with no page of its own, and this has its own page, its own lifecycle and the highest blast radius of any tenant-writable configuration. Keyed on the tenant because the row is a per-tenant singleton and delete-then-recreate mints a new uuid — which would split one workspace's SSO history across two `auditable_id` values and make it unqueryable by the index the viewer's per-resource filter rides on. That is the same reasoning the spec's `settings` row states, reached from the same shape.

**§D14 — `P1a` activates the trust anchor; `P1b` activates the login path.** A connection may be made `Active` in P1a, before `/sso/saml/login` and `/sso/saml/acs` exist — deliberately, because publishing SP metadata is the half of the handshake the tenant's admin performs in *someone else's console*, and that takes real change-control time. The gap is inert rather than merely small: §D9 refuses IdP-initiated SSO permanently, and the only legitimate entry point does not exist yet, so no button, link or redirect can reach the missing ACS. The settings page states this in words rather than offering a disabled control, and a test asserts `/sso/saml/login` 404s while a connection is Active — a canary P1b must change by hand.

---

## Consequences

**Accepted:**

- **Enterprise cannot be bought**, so no tenant can reach any of this in production until Track B (ADR-0008 §D6). Built and gated, as with custom domains before it.
- **A tenant can activate SSO before the login path exists** (§D14). Stated in the UI, the ADR and a test; unreachable by any user-facing route.
- **Single Logout is out of scope.** SLO is inconsistently implemented across IdPs and a partial implementation is worse than none — a logout that silently fails to propagate is a security claim the product cannot honour. Local session termination only.
- **SCIM auto-provisioning is out of scope** and is its own backlog row. JIT covers the common case; deprovisioning stays manual, which must be said plainly rather than implied by the presence of SSO.
- **A NameID format other than `emailAddress` will not resolve to a user.** This application's identity key *is* the email address. The settings screen says so rather than letting it present as an intermittent login failure.
- **`typecast`-style schema side effects: none here** — unlike the Airtable connector, an SP writes nothing into the IdP.

**Rejected alternatives:**

- **Hand-rolling the assertion layer.** Signature wrapping is not a thing to implement from the specification; `onelogin/php-saml ^4.3` (SAML-Toolkits) carries the XML-DSig burden. User-chosen 2026-08-13.
- **Reusing ADR-0009 §D3's stateless nonce for `InResponseTo`.** §D8 — ADR-0009 rules it out itself.
- **A `feature:sso_saml` middleware on the protocol routes.** §D4 — wrong response shape for an IdP, and it leaks another tenant's plan.
- **Multiple IdPs per tenant.** §D2 — a login-surface question wearing a schema costume.
- **Auto-activating on a successful import.** It would erase the one moment at which "this workspace has decided to trust this IdP" is recordable, and it would activate before the admin has configured their side.

---

## When to Revisit

- **A second protocol (OIDC).** §D1's seam is the whole point; adding a case plus a driver should touch no table and no column name. If it cannot, the seam failed and this ADR was wrong.
- **A customer needs more than one IdP** (a merger, or contractors on a separate directory). That reopens §D2 *and* home-realm discovery together, not separately.
- **SCIM, or any deprovisioning requirement with a compliance deadline.** JIT creates; nothing removes.
- **If Single Logout stops being inconsistently implemented**, or a customer's security review makes it contractual.
- **The first time this row holds a real secret** — an SP private key for signed AuthnRequests or encrypted assertions, or an OIDC client secret. §D12's redaction entry stops being a backstop and becomes load-bearing, and the SP metadata gains the `<md:KeyDescriptor>` P1a deliberately omits.
