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

**§D8 — Replay protection is a server-side ledger, and ADR-0009 §D3's stateless nonce is explicitly NOT reused.** ADR-0009 is self-limiting by its own terms: it declares the stateless `state` insufficient once "a callback gains a side effect beyond writing the connection it names", and **a SAML ACS establishes a session (until §D29 moved that to the completion hop, which does not weaken this argument — the ledger still guards the ACS)** — the largest side effect in the application. An HMAC proves we minted a token; it cannot prove the token has not been presented before, and single-use *is* replay protection. Hence `sso_auth_requests.consumed_at`, consumed as an **atomic conditional UPDATE whose affected-row count is the check** — never read-then-write, which leaves two concurrent replays both seeing NULL. A second, independent mechanism keys a cache ledger on the assertion's own `@ID`, covering an IdP that mints two assertions for one request.

**§D9 — IdP-initiated SSO is refused, permanently.** An unsolicited assertion carries no `InResponseTo`, so there is nothing binding it to a request this SP minted; accepting one is a login-CSRF primitive — an attacker replays their own IdP's assertion at a victim's browser and silently swaps the session. `config/saml.php` sets `allow_unsolicited => false` as a constant rather than an env var, along with `want_assertions_signed` (signing only the envelope is what makes signature wrapping exploitable) and `want_name_id`. These are security decisions, not settings: an operator toggling one would widen the threat model of every tenant at once.

**§D10 — The IdP metadata is a pasted document, imported as a WHOLE HALF, and never fetched by URL.** A partial import is precisely how a stale trust anchor survives a key rotation, so `PUT /settings/sso/idp-metadata` replaces the entire IdP half atomically — entity id, SSO URL, certificates, fingerprint and NameID format together — while `PATCH /settings/sso` amends policy. Keeping them separate is also what makes the ledger legible: with one combined verb, flipping the JIT switch would be indistinguishable from a key rotation, and "the trust anchor changed" is the one event a security reviewer searches for. A "fetch from metadata URL" importer is **refused**: fetching a tenant-supplied URL server-side is SSRF, and this deployment has no egress allow-list (ADR-0012 §D11 already declined to build that authority).

**§D11 — Certificate expiry is a settings-screen concern, not an import refusal, and a rollover pair is HEALTHY.** An IdP legitimately publishes a not-yet-active successor alongside its current key during a rotation; refusing the document would make a correctly-executed rollover fail at the SP. So the parser checks parsability and not validity dates, and the settings page surfaces per-certificate state. The roll-up rule is that **any currently-valid certificate means the connection is fine** — flagging the healthy rollover pair red is how an indicator becomes noise that admins learn to ignore.

**§D12 — The certificate body never leaves the service layer, and `getOriginal()` is the trap.** `idp_certificates` is `encrypted:array` — an **integrity** claim, not a confidentiality one, since a signing certificate is public by construction: anything that can silently rewrite that column can make the application trust assertions minted by a key of the attacker's choosing, which is a total authentication bypass for that tenant. But the column must still stay out of props, logs and audit payloads, and the obvious way to build an audit diff defeats that: **`Model::getOriginal()` maps every attribute through `transformModelValue()`, so it returns the DECRYPTED array**, and `$hidden` guards only `toArray()`/`toJson()`. The repo's own snapshot idiom (`Arr::only($model->getOriginal(), …)`) would therefore write plaintext certificates into `audits`, a table that is append-only by RLS policy and never pruned. So `SsoConnectionService` hand-builds its snapshot, and `AuditRedactor::SECRETS` registers `idp_certificates` as the mechanical backstop — not because it is a secret, but so a later "simplification" produces `[REDACTED]` and a `redacted_fields` entry instead of a silent wall of base64 in an immutable table.

**§D13 — The audit alias is `sso_connection`, keyed on `tenants.id`.** Its own alias rather than folding into `settings`: the branding precedent turns explicitly on branding *rendering inside* `/settings` with no page of its own, and this has its own page, its own lifecycle and the highest blast radius of any tenant-writable configuration. Keyed on the tenant because the row is a per-tenant singleton and delete-then-recreate mints a new uuid — which would split one workspace's SSO history across two `auditable_id` values and make it unqueryable by the index the viewer's per-resource filter rides on. That is the same reasoning the spec's `settings` row states, reached from the same shape.

**§D14 — `P1a` activates the trust anchor; `P1b` activates the login path.** ✅ **DISCHARGED BY P1b (2026-08-13).** The canary named at the end of this paragraph has been rewritten by hand into the assertion it was standing in for — activate → `GET /sso/saml/login` → post a signed assertion at the ACS → authenticated on `/dashboard`. The paragraph is kept as written, because the *reason* for the gap is what is worth carrying forward and because §D15–§D20 below are all consequences of closing it.

 A connection may be made `Active` in P1a, before `/sso/saml/login` and `/sso/saml/acs` exist — deliberately, because publishing SP metadata is the half of the handshake the tenant's admin performs in *someone else's console*, and that takes real change-control time. The gap is inert rather than merely small: §D9 refuses IdP-initiated SSO permanently, and the only legitimate entry point does not exist yet, so no button, link or redirect can reach the missing ACS. The settings page states this in words rather than offering a disabled control, and a test asserts `/sso/saml/login` 404s while a connection is Active — a canary P1b must change by hand.

---

### The P1b sub-decisions (2026-08-13)

**§D15 — php-saml owns the INBOUND half; the `AuthnRequest` is built here with DOM.** The library exists in this tree for `Response::isValid()` — canonicalisation, reference resolution and the signature-wrapping defences are not things to implement from the specification, which is what the 2026-08-13 fork decided. The outbound half is a fourteen-line document, and two concrete facts rule the library's builder out. **(1)** `AuthnRequest::__construct` mints its own id via `Utils::generateUniqueID()` — `ONELOGIN_` + a 40-character sha1, **49 characters** — and offers no seam to supply one, while `sso_auth_requests.request_id` is `char(33)` with `SsoAuthRequest::mintRequestId()` as its documented minter. The id is not cosmetic: it is the value `InResponseTo` is matched against, so it must be ours and it must fit the column that stores it. **(2)** The library builds the request by heredoc interpolation, splicing the tenant-controlled `idp_sso_url` raw into `Destination="…"` — exactly what `SsoMetadataController` already forbids ("XML a security-critical peer will parse, and a tenant-controlled value spliced into a template is an injection waiting to happen"). `SsoMetadataParser` closes that hole upstream with `FILTER_VALIDATE_URL` today, but that is a property of a *different* class, one refactor from not holding. **The seam is the wire format, which is what a protocol library is for.**

Two consequences worth stating: no `<samlp:RequestedAuthnContext>` is emitted (php-saml defaults it ON, producing `PasswordProtectedTransport` with `Comparison="exact"`, which refuses a successful passwordless or certificate-based login — this SP has no policy about *how* the IdP authenticated), and `ForceAuthn` is emitted only when asked rather than as a literal `"false"`, because an IdP answering from an existing session is what single sign-on *is*.

**§D16 — php-saml's idea of "the URL this request arrived at" is SEEDED, and this is the difference between a real `Destination` check and a self-satisfying one.** `Response::isValid()` builds `currentURL` from `Utils::getSelfRoutedURLNoQuery()`, which reads `$_SERVER['REQUEST_URI']` and `$_SERVER['HTTP_HOST']` — **superglobals Laravel's test client never populates**, because it constructs a Symfony request and does not call `overrideGlobals()`. Left alone, `currentURL` collapses to the bare host, and with the library's default `destinationStrictlyMatches => false` the comparison is a `strncmp` PREFIX match, which passes. The suite would be green and the check would not be running — the "test certifying a behaviour that did not exist" shape this codebase has already paid for once (H16b's drift excerpt).

So `SsoSamlSettings::at()` pins host, port, protocol and request path from `SsoMetadataController::assertionConsumerServiceUrl()` — §D3's single composition point, the same string the metadata document published — and restores all of it plus `$_SERVER` in a `finally`, because these are process-global statics and a leaked value would silently retarget the next request's check. `destinationStrictlyMatches` is turned **on**, so the assertion must name the ACS exactly rather than merely start with it.

**More generally: every security flag is written out, none are inherited.** `Settings::_addDefaultValues()` defaults `wantAssertionsSigned`, `rejectUnsolicitedResponsesWithInResponseTo` and `destinationStrictlyMatches` all to **false** — three decisions `config/saml.php` already records as non-negotiable. A default that happens to agree with us is not a control; it is a coincidence no test would notice changing.

**§D17 — clock skew is enforced TWICE, because php-saml's allowance is not configurable.** `Constants::ALLOWED_CLOCK_DRIFT` is a hard-coded **180 seconds**; `config/saml.php` documents **60** and says why ("the window is the period in which a captured assertion remains replayable, so generosity here is generosity to an attacker who has already achieved interception"). Both statements cannot be true unless somebody enforces the tighter one, so `SsoAssertionValidator` runs a second pass over the assertion's own `Conditions` **after** the library is satisfied — never instead of it, and never before, since reading timestamps nothing has verified a signature over is the difference between a condition and a claim. Without this pass the configured tolerance is decorative and the file documenting it is lying. Pinned by a document 120 seconds stale — inside the library's 180, outside our 60 — plus its mirror inside the allowance, so the check cannot silently become "refuse everything".

**§D18 — LOOK UP the request, validate, and only then CONSUME it.** `isValid($requestId)` takes the expected id as an argument, so the id must be known before validation — and feeding it a value read from the document being validated would be checking a string against itself. Hence three steps: read `InResponseTo` (untrusted, a lookup key and nothing more); **read** the live, unconsumed row; validate against *the row's* `request_id`; then consume. Consuming at look-up time would let any unauthenticated caller invalidate somebody's pending sign-in by posting a body carrying its `InResponseTo` — a denial of service costing the attacker one request. Both orderings are safe from the *race* precisely because the consume is the conditional UPDATE §D8 requires; what is never safe is a read-then-write, which is why `consumed_at` is not in the model's `$fillable`.

The two mechanisms run database-first: `consumed_at` cannot be flushed, while the assertion-id ledger is a cache and a cold Redis silently turns it into a no-op. The design has to still be sound when it does.

**§D19 — every ACS failure is the same 404, and the cost is recorded rather than hidden.** §D4's posture, extended from the gate to the whole endpoint. An ACS that explains why an assertion failed is an oracle for anyone tuning a forgery: "wrong audience" says the signature verified; "already consumed" says the request id was real. So unknown request, stale assertion, bad signature, suspended member and exhausted seat quota are one indistinguishable response — the `ImpersonationSessionController` posture.

Refusals are written to the **log** with a stable machine token, never to `audits`: that table is append-only by RLS policy and never pruned, so an unauthenticated endpoint writing to it on every rejection is an amplification primitive. **The accepted cost, stated so it is not discovered later: a real employee whose IdP clock has drifted sees a bare 404, and their admin has no in-app view of why.** A tenant-facing "recent SSO sign-in failures" panel is owed work, not an oversight — see *When to Revisit*.

**§D20 — the four membership outcomes, and the one that is not JIT's to decide.** `Active` → in, with no write at all. **`Suspended` → REFUSED**: an explicit administrative sanction, and a sign-in that silently reversed it would make the sanction unenforceable in exactly the workspaces most likely to rely on it. This is the one status `TenantMembershipService`'s shared attach path would happily reactivate, which is why the check sits in `SsoUserProvisioner` before the call. **`Invited` → activated at the INVITED role**, not at `default_role_name`, and *not* gated on the JIT toggle: an admin who invited somebody as an Admin expressed an intent about that person by name, which is a stronger statement than the toggle makes, and letting the directory's default silently demote them would make the invitation surface untrustworthy. **Absent / `Declined` / `Removed` → JIT territory**, gated on `jit_provisioning_enabled` and landed at `default_role_name`.

A full seat quota **refuses** rather than admitting a seatless member. `joinOpenTenant()` returns null and lets a self-registrant keep an account with no workspace — correct there, because it is a state the product already has; here it is not, because a session with no membership sees an empty workspace through RLS and reads as data loss. The refusal is therefore an exception, and one enclosing transaction is what stops a freshly created user being orphaned by it.

Two smaller decisions inside the same seam. A JIT-created account is stamped `email_verified_at` — the assertion is signed by the tenant's own trust anchor and names that address, there is no verification round trip an SSO user could ever complete, and `MustVerifyEmail` is implemented on this model — but is given **no ToS or privacy stamp**, because recording an acceptance that never happened is a worse default than leaving the absence visible. And the row is written as **one INSERT** rather than a create-then-update: `users` has a permissive INSERT policy but an **own-row UPDATE** policy keyed on `app.current_user_id`, which is still null before `Auth::login()` — so a follow-up `save()` matches no policy, updates zero rows, throws nothing, and leaves the account unverified. That failure surfaces as a lockout with nothing to trace.

**§D21 — `return_to` stays null in P1b, and the sign-in URL is published on the settings screen rather than the login page.** ✅ **`return_to` GAINED ITS FIRST WRITER IN P1c — see §D25.** The column exists so the SP — never the attacker-controllable `RelayState` — chooses the destination, but nothing in the product yet bounces an unauthenticated deep link through SSO, so there is no server-side origin to record; accepting `?return_to=` from the query string would be a plain open redirect wearing a column name. P1c's step-up is its first legitimate writer. Meanwhile the entry point has to be reachable, so `SsoMetadataController::loginUrl()` joins the other two composed URLs and the SP-details card publishes it for an admin to bookmark or paste into their IdP's "sign-in page" field. A "continue with SSO" button on `auth/Login.vue` belongs to the auth vertical being rebuilt in parallel (J3) and is deliberately not shipped from here.

**§D22 — step-up FORKS ON IDENTITY SOURCE; it is never exempted.** P1b made a latent hole reachable for the first time. A JIT-provisioned member's `users.password` is `Hash::make(Str::random(64))`, discarded on the spot by the only process that ever held it, and `auth.password_confirmed_at` had exactly one writer: a form asking for that password. So `PUT /settings/sso/idp-metadata`, role changes, member removal and ownership transfer were all dead ends for precisely the people SSO exists to serve — including, circularly, the one surface an admin would use to fix a broken SSO configuration.

The half-line fix is an exemption: "an SSO session skips step-up". It was rejected because it says the opposite of what the gate is for. PRD Feature #14 asks for *a recent credential confirmation, not just a live session*, and that requirement is identical for an SSO member; what differs is only which authority can answer it. So `RequireRecentPassword` keeps its window, its four routes and its requirement, and changes only the mechanism: an SSO-established session is redirected to `SsoStepUpController`, which mints an AuthnRequest with `intent = StepUp`, a named subject and `ForceAuthn="true"`.

**The fork is keyed on the SESSION, not the account.** The account-level alternatives — an `sso_provisioned_at` column, or inferring "SSO user" from an unusable password — answer a different question and answer it wrongly. A member who registered with a password in a workspace that later enabled SSO still holds that password and should keep the prompt; the same person signing in through the IdP on Monday and with their password on Tuesday is one account and two sessions, and only one of them can be re-proved at the IdP. The inference is also simply unavailable: a hash of 64 random bytes is indistinguishable from a hash of a real password. `App\Support\Sso\SsoSession` writes one key, from the ACS's login arm, after `Auth::login()` has migrated the session.

**§D23 — the ACS has no session, so the stamp cannot happen there. This is a cookie policy, and it shapes the whole flow.** `config/session.php` sets `same_site` to `lax`, and a cross-site top-level POST does not carry a Lax cookie — a fact `SsoAcsController` had recorded since P1b while explaining its CSRF exemption, without anyone drawing the consequence. `Auth::id()` at the ACS is therefore **null even for a member signed in two tabs away**, and the third of §D22's conditions — a `user_id` matching the *currently authenticated* user — is not askable at that endpoint at all.

The alternative was to call `Auth::login()` at the ACS and stamp the fresh session it creates. It works, and it is wrong in four ways at once: it replaces the session the step-up is being performed *for*, discards whatever that session held, leaves the old one live server-side, and downgrades the subject check from a statement about the SESSION to a statement about the ASSERTION — which is exactly the property that makes the check worth having.

So the flow gains one same-site hop. The ACS validates, marks `sso_auth_requests.verified_at`, and redirects to a GET on the tenant's own host; `SameSite=Lax` **does** send cookies on a top-level GET navigation, so that request arrives on the original session. The step-up arm of the ACS therefore calls no `Auth::login()`, runs no provisioning (a step-up is defined against a session that already exists, so an unknown subject is a mismatch and never a new joiner), and does not stamp `last_login_at` — that column is what an admin reads to answer "is sign-in working", and a working step-up keeping it fresh would let a broken login path look healthy for weeks.

**§D24 — two new columns, and neither reuses `consumed_at`.** `verified_at` is the ACS's mark; `completed_at` makes the hop single-use, redeemed by a conditional UPDATE whose affected-row count is the check — the same shape as `consume()`, for the same reason a read-then-write is never safe here. Overloading `consumed_at` would collapse "an assertion answered this request" and "the browser came back and the clock was stamped" into one column, which is the mistake the table's own docblock already rejects when it explains why rows are retained rather than deleted. A CHECK constraint pins the order at the database, because `verified_at` is the only evidence a signed assertion was ever presented and a redemption that could precede it would let anyone holding a `request_id` — a value that travels in a URL — stamp the clock.

The row is found **by its `request_id`, never by being the newest**: two step-ups begun inside one second are a tie and PostgreSQL breaks ties by physical order, which is the defect P1b found in its own test helper. The id in the URL is safe precisely because it stamps nothing on its own — the session must also be the one the row names — and `saml.step_up_completion_ttl_seconds` (90s, two orders of magnitude tighter than the AuthnRequest TTL, because it covers one 302 being followed rather than a human authenticating) bounds how long it is worth anything in a history entry or a referrer header.

**§D25 — `return_to` stores a PATH, and nothing compares hosts.** Its first writer is the step-up, which takes the destination from the server's own `url.intended` — written by `Redirect::guest()` when the gate bounced the member — and never from a query string. `App\Support\Sso\SsoReturnTo` then reduces it to a string beginning with exactly one `/`, applied on the way in and again on the way out.

The rule is a **shape, not a comparison**, and that is the decision. A host comparison is the check that keeps being got wrong: `https://evil.test\@ours.test`, a case difference, a trailing dot, a port, an IDN homograph. A browser resolves a path against the origin it is already on, so the destination is the current tenant's host by construction and there is nothing left to get wrong. The stated consequence: a foreign absolute URL contributes only its path — `https://evil.test/steal` becomes `/steal` on *our* host, not a refusal — which is same-origin and harmless, and which can only arise from a foreign `Referer` on a request that already passed CSRF. `//evil.test/x` and `/\evil.test/x` are refused outright, because a protocol-relative URL leaves the origin while reading, in a log and in a review, exactly like a path.

**§D26 — the failures panel exists because the store is bounded, not because the posture softened.** §D19 accepted that an employee whose IdP clock has drifted sees a bare 404 and their admin has no in-app view of why, and the revisit trigger named the obstacle exactly: the obvious table is append-only and an anonymous endpoint must not be able to fill it. `sso_auth_failures` is the answer to that obstacle first and a UI second.

**The bound is on the WRITE path, not on the scheduler.** `SsoAuthFailureRecorder` trims in the same call as the insert, to both a per-tenant row cap (`saml.failure_log_max_rows`) and a retention window (`saml.failure_retention_days`). A nightly prune job was rejected on a measured fact rather than a preference: `routes/console.php` records that nothing runs the scheduler on the production box yet, so that bound would exist in the repository and not on the machine — for a table a stranger appends to. The cap is expressed as "not among the newest N" rather than "older than the Nth", because a grinder writes dozens of rows inside one second and a timestamp comparison keeps every tied row, i.e. fails at exactly the volume it exists for. The two limits are independent on purpose: the cap answers a grinder, the window answers the fact that these rows carry an IP address and sometimes an email.

**§D19's 404 is untouched, because this is a different audience rather than a looser answer.** The opacity is about the unauthenticated wire, where "wrong audience" tells a forger the signature verified. The panel is behind `auth` plus `can:tenant.settings.manage` on the reader's own workspace, answering "why can my colleague not sign in?" — nothing an attacker could reach without already being an admin of the tenant they are attacking. Two disclosure rules follow it in: `subject_email` is recorded **only** for post-validation refusals (before a signature verifies there is no address anyone should be shown, and recording one would let a stranger write chosen text into a tenant's database), and the claimed `InResponseTo` is stored only when it matches the shape this SP mints — otherwise an over-long value would make the INSERT throw, the recorder swallow it, and the panel go silent for as long as somebody kept sending them, which is a suppression primitive rather than a validation nicety.

`SsoFailureReason` promotes P1b's bare tokens into a backed enum with `label()` and `hint()`, CHECK-constrained from `values()` (the `SsoAuthIntent` precedent), so the database constraint, the log line and the sentence an admin reads all come from one place. The hints are instructions rather than restatements, and several are deliberately unalarming: an invite-only workspace produces `jit_disabled` every time somebody new tries, and a panel that reads as an incident log for its own configuration teaches admins to ignore it.

### The P1e sub-decisions (2026-08-16)

**§D27 — the LOGIN arm gains the same-site completion hop, because §D23 was right about the cookie and wrong about which arm it mattered for.** §D23 recorded that `SameSite=Lax` withholds the session cookie from the ACS and concluded, for the login arm, that this was "invisible". It was not. Creating the session at the ACS binds it to whichever browser POSTed, and nothing required that to be the browser that STARTED the flow — so an attacker holding an account at the tenant's own identity provider could mint a flow, authenticate as themselves, capture the auto-POST form without submitting it, and induce a victim's browser to submit it instead. The victim then worked inside the attacker's account. `request_id` and `InResponseTo` prove that this SP minted this flow and say nothing about who is holding it, which is ADR-0009 §D3's provenance-read-as-origin error on a second surface.

**Google's fix could not be borrowed, and the reason is structural rather than stylistic.** ADR-0019 §D7 closes the identical defect with one session key compared at the callback, and that works because both of Google's session-creating hops share a host with their mint. The ACS shares a host with nothing it can read: it is a genuinely cross-site POST, receives no cookie at all, and cannot compare one. So the login arm gets the shape the step-up arm has had since §D23 — the ACS marks the row and redirects to a same-site GET, and `SameSite=Lax` DOES send cookies on a top-level navigation.

**What the mint now writes, and why it is a comparison rather than a presence check.** `GET /sso/saml/login` records that flow's OWN `request_id` in the session, and the hop refuses unless the arriving browser holds THAT id. Requiring merely that the session hold *some* pending flow would be defeated by first steering the victim through `/sso/saml/login` — a plain top-level GET, which Lax happily sends the cookie with — and this is the refinement ADR-0019 §D7 already names on the sibling flow. The value is the `request_id` itself rather than a second secret: it is already 128 bits, already single-use, and the step-up hop already accepts the identical exposure in a URL for the reason §D24 gives, that on its own it authorises nothing.

**The comparison runs AFTER the redeem, which is the Google completion hop's ordering and not an accident.** The row is burned either way, so an assertion tried in the wrong browser cannot then be retried in the right one — and an attacker who induces a victim to submit their assertion destroys their own sign-in rather than merely failing to steal the victim's.

**A LIST of pending flows, capped, which diverges from `google.flow_sid`'s single value deliberately.** The step-up arm is concurrent for free because its binding is `user_id`; a single-valued key here would make the login arm strictly *less* concurrent than the step-up arm, so a second tab would silently kill the first and the member would meet a bare 404 *after* their identity provider had already said yes — the one failure §D19 guarantees is never explained to them. Capped because `GET /sso/saml/login` is unauthenticated and writes one entry per hit. **Stated cost:** start more sign-ins in one browser than the cap without finishing any, and the oldest stops being completable.

**§D28 — the subject travels on a NEW column, and `sso_auth_requests_step_up_user_check` is not widened.** The hop has to sign somebody in and arrives after the assertion is gone, so the resolved subject must be durable. `user_id` cannot carry it: the P1a CHECK forbids a login row from holding one, and its own comment says why — "a login request WITH one would let a consumed login assertion masquerade as a step-up". Widening it to admit a post-validation subject re-opens precisely that, and three docblocks cite that constraint by name and assert what it forbids. So `resolved_user_id`, which is §D24's argument applied to a subject instead of a timestamp: `user_id` is **who we asked about**, written before the redirect by a session that already knew; `resolved_user_id` is **who the assertion turned out to name**, written after validation on behalf of a caller who was anonymous at mint. A question and an answer are not one column.

Two CHECKs keep the bad states unrepresentable. The first keeps a resolved subject off a step-up row and off any row no assertion answered. The second — the `google_auth_requests_identity_check` posture — says a **verified login row carries its subject**, so the hop can never be entitled to redeem a row and have nobody to sign in. The constraint and the single UPDATE that satisfies it were designed together, which is why `SsoAuthRequestService::markVerified()` writes both columns in one statement and cannot be split.

**`verified_at` and `completed_at` ARE reused, and that is the same test answered the other way.** §D24 forbids collapsing two different EVENTS into one column; it does not forbid two intents sharing a fact they genuinely share. Both arms mark the same two moments in the same order, so a parallel `login_*` pair would be one column wearing two names and would fork the ordering CHECK, the trim's predicate and every future reader's model of this table on `intent`, for no fact gained.

**The subject is re-read on the DEFAULT connection, not through `Auth::loginUsingId()`.** That would resolve on `pgsql_auth`, whose select policy is `USING (true)` — every account in the deployment, with no membership predicate at all. It is the right connection for restoring a session that has already proved who it is and the wrong one for deciding who may be signed in. The default connection's `users_visibility` policy is `id = app.current_user_id OR EXISTS(an ACTIVE tenant_users row for app.current_tenant_id)`; nobody is signed in here, so the second arm is the whole predicate and RLS refuses a subject who is not a live member of this workspace — including one suspended in the ninety seconds since the ACS. The wrong-tenant refusal is RLS rather than a comparison, exactly as ADR-0019 §D7 has it.

**`last_login_at` moves to the hop**, extending §D23's argument to the arm it was written to protect: that column answers "is sign-in working", and a verified assertion whose browser never came back is not a sign-in. Left at the ACS it would let a broken completion hop look healthy for weeks.

**§D29 — the ACS creates no session at all, and while it was inside `web` it was DESTROYING one.** Measured against a running stack rather than reasoned: inside the stateful stack `StartSession` runs on the ACS, finds no cookie, generates a fresh session id, and `addCookieToResponse()` emits it unconditionally — its only guard is that a session driver is configured. The response carried a `Set-Cookie` for a new empty session plus a regenerated `XSRF-TOKEN`, host-only and same-path, so a browser **replaces** the member's real cookie and then follows the 302 carrying the empty one.

**The consequence is that §D23's completion hop had never worked in a real browser.** A member completing a step-up arrived with no session and was bounced to the sign-in page by `auth`, having lost the session the step-up was being performed for. Nothing in the test suite could see it — the Pest client never feeds `Set-Cookie` into the next request and the session store is memoised per process, so the harness models the session as a process global while a browser models it as a cookie. Worse, the breakage and P1e's own security refusal share one observable, a 404 at the hop, so an acceptance test would have passed either way. The ACS is therefore outside the stateful stack entirely, which is what its own docblock had claimed since P1b, and the assertion that pins it is a response-header assertion.

**§D30 — a consumed request stopped meaning a finished one, so §D8's trim had to follow.** P1d bounded `sso_auth_requests` and promised in writing that no in-flight sign-in was evictable at any volume. That was already false: the predicate called a row dead on `consumed_at IS NOT NULL`, but the ACS consumes a row and only then hands the browser to a completion hop, so between those two requests a row is consumed and still needed. Reachable rather than theoretical, because `issued_at` is the MINT — up to `authn_request_ttl_seconds` before the consume — so a member who spends three minutes at their identity provider owns a row ranked three minutes down the eviction ordering at the instant it becomes eligible, and the mint endpoint is unauthenticated. Corrected to three arms: redeemed; or never reached a signed assertion and its request window has closed; or reached one and its completion window has closed. The deadline is the LATER of the two arms' windows, because a trim reading one arm's knob would turn an operator raising the other's into unauthenticated denial of authentication. **The honest bound gains a third term:** N spent rows, plus one request TTL of limiter, plus one completion window of whatever the tenant's own identity provider signs.

⚠️ The corrected shape is the Google request store's, which had been ported FORWARD to the sibling table when J3c2 hit this defect and never back to the table it came from.

### The M2 sub-decision (2026-08-18)

**§D31 — §D11's roll-up rule is applied on the LOGIN PATH, and §D11's own sentence was true about the
settings screen while being silent about the thing that mattered.** §D11 settled that certificate expiry is
"a settings-screen concern, not an import refusal", and it was right about the import: an IdP legitimately
publishes a not-yet-active successor during a rotation, so refusing the document would make a correct
rollover fail at the SP. What nobody drew from it is that **nothing then checked validity at sign-in
either.** `SsoCertificateInspector` had exactly one consumer — the settings presenter — while php-saml
verifies an XML signature against a stored certificate **without ever parsing its `notBefore`/`notAfter`**.
So an expired trust anchor kept minting sessions indefinitely, and `/settings/sso` displayed it as expired
the whole time: **the one surface an admin would consult said the control was failing while the control was
in fact absent.** A tenant's IdP rotates, nobody re-imports (and nothing prompts them to, because sign-in
keeps working), and the retired private key — recovered from an HSM backup, a key-escrow archive or a
departed administrator's copy — mints an assertion for any address. Recorded as a Residual in
`docs/security-threat-model.md` §8 and §9 item 18 from P1a, with the correct shape already named there;
M2 is the increment that owed shape was waiting for.

**THE RULE IS THE ROLL-UP, NOT "ANY EXPIRED KEY REFUSES", AND THE DIFFERENCE IS AN OUTAGE.**
`SsoLoginService::consumeAssertion()` calls `SsoCertificateInspector::signingState()` and refuses when the
answer is outside `USABLE_STATES` — that is, only when **no** certificate in the set is currently usable.
A rollover pair is a live key beside a successor and stays fully functional, which is §D11's own reasoning
about noise applied to availability instead of to an indicator. `expiring_soon` is usable on purpose: it is
a warning, and a warning that refused would be a refusal.

**WHERE THE CHECK SITS, AND THE THREE PLACES IT DELIBERATELY DOES NOT.** It is **step 0** in
`SsoLoginService`'s numbered sequence — a precondition of the sequence rather than a step in it, since
there is nothing to gain by parsing a document, or consulting either replay ledger, on behalf of a
connection that cannot vouch for anything; it is also the cheapest available answer to an anonymous POST,
allocating no DOM. **Not at the mint** (`SsoLoginController`): refusing there reaches the same 404 by a
second route and puts a second copy of one rule in a second file. **Not in `SsoGate::activeConnection()`**:
a dead anchor answering "no connection" would hand `RequireRecentPassword` a tidy password fallback and
404 the ACS **with nothing recorded**, destroying the failures-panel row that is the whole point of
noticing. **And not by filtering `x509certMulti.signing` in `SsoSamlSettings::for()`** — see below.
It covers **both intents**, because `consumeAssertion()` is shared: an assertion signed by an anchor nobody
can vouch for is no more trustworthy for a re-authentication than for a login.

**⚠️ THE REJECTED ALTERNATIVE IS RECORDED BECAUSE IT IS THE STRICTLY STRONGER ONE, AND THE REASON IT LOSES
IS A CLOCK.** Narrowing the certificate set handed to php-saml to its currently-valid members would also
stop an *expired sibling* signing while a valid one is present — the residual this decision keeps. It is
rejected on two grounds. **(a) It makes clock skew into an availability control.** During a rotation a
successor's `notBefore` is legitimately minutes away, so filtering on *this SP's* clock would refuse
signatures the identity provider considers current — precisely the rollover-is-not-a-fault principle §D11
exists to state. **(b) The exposure it closes is already closed by §D10.** Metadata is imported as a whole
half, atomically, so a re-import *removes* the retired certificate rather than adding beside it; the
dangerous state is "nobody re-imported at all", which is an expired-**only** set, which this decision
refuses outright. The surviving residual is written into the threat model with its own revisit trigger —
the first tenant observed holding a mixed set — rather than left implied, and
`SsoAcsWebTest` asserts it as a passing sign-in so that narrowing it later shows up as a failing test.

**TWO CONSEQUENCES THAT ARE NOT THE REFUSAL ITSELF.** A new `SsoFailureReason::IdpCertificateUnusable`
covers all three unusable states — `expired`, `not_yet_valid`, `unreadable` — because the admin's action is
identical in every one (re-import the metadata) and the certificate card beside the row already shows which
key and when; the specific state goes to the operator's log line instead. It carries a **null**
`subject_email`, which is the §D26 disclosure rule holding under a case that tempts otherwise: the assertion
does name an address and its signature may well be valid, but nothing has verified it at the moment this
refusal fires, and an unverified address on an admin's screen is attacker-chosen text. And the new case
needs `2026_08_17_000105`, because `2026_08_15_000002` CHECK-constrains the column to
`SsoFailureReason::values()` — without it the guard would raise a 23514 **while being recorded**, turning
the uniform 404 into a 500 on the one endpoint anyone on the internet can post to, which is itself the §D4
disclosure that posture exists to prevent.

**AND ONE PIECE OF COPY IS A CORRECTION RATHER THAN A POLISH.** The settings card's expired warning read
*"Your identity provider has almost certainly published a replacement — re-import its metadata to pick it
up"*: true, and an errand, because sign-in genuinely kept working. It no longer does, so that warning and
the two other unusable states now open with **"Sign-in is refused"**. `expiring_soon` is deliberately
untouched — that tenant can still sign in, and telling them otherwise is the same error pointing the other
way. The `invalid_assertion` hint was narrowed for the same reason: it claimed to be what "an expired or
rotated signing certificate looks like", and the expired half was never true, since an expired anchor
produced a session rather than that row.

### The M7 sub-decision (2026-08-20)

**§D32 — a SAML sign-in does not challenge a member's PERSONAL second factor, and until now that was
a decision this document had never made.** *User decision of record, 2026-08-20, ratifying what P1b built.*
A member with a confirmed TOTP who arrives through the identity provider is signed in on the spot:
`SsoLoginCompletionController` calls `Auth::login()` with no `hasEnabledTwoFactorAuthentication()` fork,
where `GoogleSessionStarter` deliberately does fork. **The IdP is the authentication authority for this
door.** A workspace administrator chose that trust anchor, configured it, and can require whatever factors
they want at it — which is exactly the property ADR-0019 §D11 says a *consumer* Google account lacks, and
why these two doors are allowed to answer differently.

**THIS IS NOT A NEW POSITION; IT IS §D22's POSITION AT THE OTHER END OF THE SAME FLOW.** §D22 already gives
an SSO session's **re-authentication** to the identity provider — `ForceAuthn="true"` against the anchor,
never a local credential — on the reasoning that only one authority can re-prove an SSO member and it is
not this application. Login is that principle at the same door one step earlier. Reading the two together
is what makes the as-built behaviour coherent rather than an omission somebody forgot to close.

**⚠️ WHAT THIS DECIDES AND WHAT IT DOES NOT, WRITTEN OUT BECAUSE A *CONSEQUENCES* BULLET HAS ALREADY BEEN
READ AS DECIDING IT ONCE.** There are **two** second-factor controls in this product and they are answered
in opposite directions:

- **The WORKSPACE's control** — `security.require_two_factor`, enforced by `EnforceTenantTwoFactor`. **Not
  exempted for SSO arrivals**, and untouched by this decision. That is the Consequences bullet below, and it
  is the one carrying the sentence *"a workspace whose IdP already performs MFA turns the setting off"*.
- **The PERSON's control** — their own enrolled TOTP. **Not challenged at this door**, which is this
  section, and which had no home in this document before it.

**THE CONFUSION WAS REAL AND IT REACHED THE CODE.** ADR-0019 §D11 explained its Google divergence by
quoting that Consequences bullet and attributing it to **§D22** — a section about step-up which says the
opposite, that step-up *"is never exempted"*. Twelve citations across seven files inherited the
attribution, including a docblock in `SsoLoginCompletionController` justifying live behaviour by it. The
bullet was never wrong; it was answering the *other* control, and nothing in this document answered this
one. **A decision with no § heading cannot be cited, so it gets cited by proxy and the proxy drifts** —
7(g)'s "cite the FILENAME, never the bare number" one level further down, and the reason this section
exists as a numbered decision rather than as a comment.

**THE RESIDUAL, STATED RATHER THAN LEFT TO BE REDISCOVERED A THIRD TIME.** In a workspace that has switched
`security.require_two_factor` **on**, an enrolled member arriving through SAML satisfies the gate on the
**enrolment flag alone** and never presents the factor at this door. That is not new and not a defect: the
middleware is an enrolment nudge by its own docblock, which is also why the `/api/v1` token-mint row in
`docs/feature-backlog.md` was downgraded from `blocker` to `minor` on the identical reasoning. It is written
here so the next reader gets it from the decision rather than from re-deriving it.

**THE DECISION IS PINNED BY A TEST, WHICH IS WHAT MAKES IT FALSIFIABLE.** Before M7 the SAML polarity was
asserted **nowhere** — `tests/Feature/Sso/` greps zero for `two_factor_confirmed_at` — while the Google
polarity has been pinned since J3c2. A later "consistency fix" flipping this door would have passed the
whole suite green. `SsoLoginCompletionWebTest`'s *"signs an enrolled member straight in, because the
identity provider is the authentication authority"* asserts what the system **ends up doing** — authenticated
on `/dashboard`, with Fortify's `login.id` handoff absent from the session — rather than that a fork was
skipped.

**REJECTED: making this door fork like `GoogleSessionStarter`.** It reads as the consistent choice and is
the wrong one twice over — it overrides an enterprise trust anchor with a factor the administrator who owns
that anchor did not configure, and it contradicts §D22's mechanism at the same door one step later.
**Revisit trigger:** a per-workspace *"our IdP already performs MFA"* setting, which is the same trigger
ADR-0019 §D11 and this document's own revisit list already name, and which would turn this constant into a
policy. Building it was considered here and rejected as scope: a new `SettingKey`, a migration and a
settings control, for a row filed as a documentation defect.

---

## Consequences

**Accepted:**

- **Enterprise cannot be bought**, so no tenant can reach any of this in production until Track B (ADR-0008 §D6). Built and gated, as with custom domains before it.
- ~~**A tenant can activate SSO before the login path exists** (§D14). Stated in the UI, the ADR and a test; unreachable by any user-facing route.~~ **Closed by P1b** — both endpoints are routed and the canary is now the round trip itself.
- ~~**A failed sign-in tells the person nothing, and tells their admin nothing in-app** (§D19). The 404 is deliberate and the log line is the only record. This is the sharpest edge P1b ships, and it is a UX cost accepted for a security property rather than an omission.~~ **HALF-CLOSED BY P1c (§D26): the admin's half is answered by the failures panel; the PERSON still sees a bare 404, and always will, because they are the unauthenticated caller §D19 is about.**
- **A member who signs in through SSO is still subject to org-level 2FA enforcement.** `EnforceTenantTwoFactor` guards the authenticated group and is not exempted here: "require 2FA for all tenant members" is a policy an admin switched on, and inferring an exemption from the presence of SSO would silently drop it. A workspace whose IdP already performs MFA turns the setting off — that is their decision to make, not this controller's. ⚠️ **THIS IS THE WORKSPACE'S CONTROL, AND THE PERSON'S OWN SECOND FACTOR IS §D32** — the two are answered in OPPOSITE directions, and this sentence was quoted as deciding the other one for six increments (ADR-0019 §D11 and eleven citations behind it, repaired in M7). Nothing here says anything about an enrolled member's own TOTP.
- **The only user-facing entry point is a URL on the settings screen** (§D21). Deliberate lane hygiene, not a design preference; the login-page affordance is owed by the auth vertical.
- **An admin whose SSO is broken cannot step up through SSO, and the escape hatch is `/user/confirm-password`** (§D22). Fortify routes it on the tenant subdomain (`domain => null` plus `RequirePlatformHost`, which admits subdomains), and a member with no usable password reaches it through password reset — which is why `SsoUserProvisioner` creates a real account rather than a special case. Two things make this survivable rather than a lockout: the fork falls back to the password prompt whenever SSO cannot serve at all (disabled, draft, downgraded), and `GET /settings/sso` carries no step-up gate, so the admin can still read the failures panel that tells them what broke. **A LINK to the confirm-password page from the step-up flow is owed by the auth vertical** — `auth/ConfirmPassword.vue` is being rebuilt in the design lane (J3) and shipping into it from here is how two lanes lose an afternoon.
- **A session established before P1c deployed carries no identity-source marker**, so an SSO member on one gets the password prompt — the pre-P1c behaviour. It heals on their next sign-in. Nothing backfills it, because the fact was never recorded.
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
- ~~**The first support ticket that reads "SSO just stopped working."** §D19 accepted a uniform 404 with no tenant-facing explanation. The moment an admin cannot self-diagnose a clock drift or an expired signing key, the answer is a *tenant-scoped* failures panel fed by something other than `audits` — a bounded, prunable store, because the reason that surface does not exist yet is that the obvious table is append-only and an anonymous endpoint must not be able to fill it.~~ ✅ **DISCHARGED BY P1c (§D26)**, built to that description: `sso_auth_failures`, trimmed on every write to a row cap and a retention window.
- **Anything that would make php-saml build the `AuthnRequest`.** §D15's two reasons are load-bearing: widening `request_id` to fit a vendor's id generator, or accepting an interpolated `Destination`, would each be a real regression wearing a cleanup's clothes.
- ~~**P1c — protocol-aware step-up.** `RequireRecentPassword` compares `auth.password_confirmed_at`, which an SSO user with no usable password can never stamp, so it must fork on identity source.~~ ✅ **BUILT (§D22–§D25).** The one thing the row did not anticipate: the third condition could not be evaluated where the row assumed, because `SameSite=Lax` means the ACS has no session — see §D23.
- **`SESSION_SAME_SITE` ever being set to `none`.** §D23's completion hop exists solely because the ACS receives no cookie, and since P1e there are TWO such hops (§D27). Loosening that setting would make them look redundant, and removing either would silently drop the only place its arm asks who is holding the browser — `user_id` against the signed-in member on the step-up arm, the pending flow id against the arriving browser on the login arm. If the setting changes, this ADR is the reason both hops stay. ⚠️ Note the login hop would still be needed even then: it is also where `resolved_user_id` is turned into a session, and the ACS deliberately creates none (§D29).
- **Anything that gives `TenantUserStatus::Suspended` a producer.** P1c added the refusal in `attachMember()` as a latent guard; the day an admin can actually suspend a member, that guard becomes live and both doors (self-registration and SSO JIT) need a test that exercises the real surface rather than the service directly.
- **A workspace asking for "our IdP already performs MFA", or for its opposite.** §D32 is a constant today: the identity provider is the authentication authority at this door and a member's own TOTP is not challenged. A tenant that wants either half configurable turns it into a per-workspace setting — the same trigger ADR-0019 §D11 names from the other side, so the two doors would become one policy with two defaults rather than two decisions.
