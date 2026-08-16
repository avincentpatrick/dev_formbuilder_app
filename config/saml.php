<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SAML 2.0 Service Provider (Phase 4, P1a — ADR-0016)
|--------------------------------------------------------------------------
|
| Platform-wide protocol constants. Everything TENANT-specific — the IdP entity id, its SSO URL, its signing
| certificates, the attribute map, the JIT policy — lives on the `sso_connections` row, never here: one file
| cannot hold per-tenant trust anchors, and a config cache would freeze them.
|
| ⚠️ EVERY ABSOLUTE URL IS DERIVED, NEVER CONFIGURED. The SP entity id and the ACS location are composed by
| `TenantUrl::to($tenant, …)` at request time, because they must be the tenant's CANONICAL subdomain and a
| custom domain must never appear in either (ADR-0012 §D1 confines custom hosts to the public guest runtime).
| Putting them here would invite an operator to set one host for every tenant, which is precisely the bug the
| per-tenant AudienceRestriction check exists to catch.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Clock skew
    |--------------------------------------------------------------------------
    |
    | Tolerance applied to `NotBefore` / `NotOnOrAfter` when validating assertion conditions. SAML deployments
    | are notoriously sensitive to clock drift between the IdP and the SP, and the failure mode is an
    | intermittent, unreproducible "login worked ten minutes ago" report.
    |
    | 60 seconds is the conventional allowance and is deliberately SMALL: the window is the period in which a
    | captured assertion remains replayable against the (separate) replay ledger, so generosity here is
    | generosity to an attacker who has already achieved interception. Do not raise it to paper over a host
    | whose clock is actually wrong — fix NTP.
    |
    */
    'clock_skew_seconds' => (int) env('SAML_CLOCK_SKEW_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | AuthnRequest lifetime
    |--------------------------------------------------------------------------
    |
    | How long a minted `sso_auth_requests` row stays consumable. This bounds the window between "we redirected
    | you to your IdP" and "your IdP posted an assertion back", which includes a human typing a password and
    | completing MFA — so it cannot be tight. Ten minutes matches the interactive-login budget most IdPs
    | themselves assume.
    |
    | Expired rows are NOT deleted on read: `consumed_at`/`expires_at` are both retained so the ACS can tell
    | "expired" from "already used" from "never existed", which are three different security events and only
    | one of them is an attack.
    |
    */
    'authn_request_ttl_seconds' => (int) env('SAML_AUTHN_REQUEST_TTL_SECONDS', 600),

    /*
    |--------------------------------------------------------------------------
    | AuthnRequest store bounds (P1d) — the prune this file used to describe and nobody had written
    |--------------------------------------------------------------------------
    |
    | ⚠️ THIS TABLE'S OWN MIGRATION SAID "a scheduled prune drops rows well past `expires_at`" AND NO SUCH
    | COMMAND, JOB OR SCHEDULE ENTRY HAS EVER EXISTED. `routes/console.php` records that
    | nothing runs the scheduler on the production box in any case, so even a written one would have been a
    | bound living in this repository and not on the machine — for a table `GET /sso/saml/login` appends to,
    | unauthenticated, one row per hit. Corrected by bounding the WRITE path instead, on the
    | `SsoAuthFailureRecorder` and `google_auth_requests` pattern: the two newer tables of this family both
    | got this and the oldest did not.
    |
    | ⚠️⚠️ ONLY ALREADY-DEAD ROWS ARE ELIGIBLE, AND THE LIVENESS PREDICATE IS THE OUTER `AND` RATHER THAN A
    | REFINEMENT. J3c2 ported a rank-only cap of exactly this shape onto a table holding LIVE rows and
    | produced unauthenticated denial of *authentication*: anyone could mint enough rows to push a member's
    | pending sign-in past the cap while they sat on a consent screen, after which their sign-in failed and
    | the log blamed a replay. Here a row becomes eligible only once it is consumed or expired, so no
    | in-flight sign-in can be evicted by anybody, at any volume.
    |
    | Deleting a spent row cannot re-enable a replay: `findLive()` requires the row to EXIST and be
    | unconsumed and unexpired, so an absent row is refused exactly as a consumed one is. What it costs is
    | resolution in the LOG — a replay of a pruned request reads as "never minted" rather than "already
    | used" — which is why the window is days rather than minutes even though a request dies in ten.
    |
    | The two limits are independent for the same reason they are on the failure log: the cap answers a
    | grinder, the retention window answers the fact that these rows carry an IP address.
    |
    */
    'authn_request_max_rows' => (int) env('SAML_AUTHN_REQUEST_MAX_ROWS', 500),
    'authn_request_retention_days' => (int) env('SAML_AUTHN_REQUEST_RETENTION_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Step-up completion window (P1c)
    |--------------------------------------------------------------------------
    |
    | How long a step-up stays redeemable AFTER its assertion has been validated — i.e. between the ACS
    | marking `sso_auth_requests.verified_at` and the browser arriving at the same-site completion hop that
    | actually stamps `auth.password_confirmed_at`.
    |
    | Deliberately two orders of magnitude tighter than `authn_request_ttl_seconds` above, because it covers
    | something completely different: not a human authenticating at their IdP, but a single 302 being
    | followed. Ninety seconds is generous for one network hop and short enough that a `request_id` left in
    | a browser's history, a referrer header or a proxy log stops being worth anything almost immediately.
    |
    | It is a SECOND bound, never the only one: redemption also requires the row to be unredeemed and the
    | session to belong to the user the row names, and neither of those expires.
    |
    */
    'step_up_completion_ttl_seconds' => (int) env('SAML_STEP_UP_COMPLETION_TTL_SECONDS', 90),

    /*
    |--------------------------------------------------------------------------
    | Login completion window (P1e)
    |--------------------------------------------------------------------------
    |
    | The same bound, on the other arm: how long a validated LOGIN stays redeemable between the ACS marking
    | `verified_at` and the browser arriving at the same-site hop that actually signs
    | the member in — on the DEFAULT connection, never through `Auth::loginUsingId()`, for the reason
    | `SsoLoginCompletionController` gives at length.
    | Same default, same reasoning — it covers one 302 being followed, not a human authenticating.
    |
    | ⚠️ ITS OWN KEY RATHER THAN A REUSE OF THE STEP-UP'S, AND THAT IS NOT BOOKKEEPING. A knob named for one
    | arm silently governing the other is how an operator's change stops meaning what they read it to mean —
    | the shared-bucket mistake the rate limiters above already refuse by name. Renaming the existing key to
    | cover both would have been worse still: a config rename is a production behaviour change with NO error,
    | reverting to the default on any box that sets the old variable, and this bound is a security window.
    |
    | It is a SECOND bound, never the only one: redemption also requires the row to be unredeemed, to carry
    | the subject its assertion resolved to, and to be named by a flow the arriving browser actually started.
    |
    */
    'login_completion_ttl_seconds' => (int) env('SAML_LOGIN_COMPLETION_TTL_SECONDS', 90),

    /*
    |--------------------------------------------------------------------------
    | Sign-in failure log (P1c) — the two bounds that make it safe to keep at all
    |--------------------------------------------------------------------------
    |
    | ADR-0016 §D19 accepted that a member whose IdP clock has drifted sees a bare 404 and their admin has no
    | in-app view of why, and named the fix: a tenant-scoped failures panel fed by "a bounded, prunable
    | store, because the obvious table is append-only and an anonymous endpoint must not be able to fill it".
    |
    | ⚠️ BOTH LIMITS ARE ENFORCED ON THE WRITE PATH, NOT BY A SCHEDULED JOB, and that is not a shortcut.
    | `routes/console.php` records that nothing runs the scheduler on the production box yet, so a nightly
    | prune would be a bound that exists in this repository and not on the machine — for a table an
    | UNAUTHENTICATED endpoint appends to. `SsoAuthFailureRecorder` trims in the same call as the insert.
    |
    | The two are independent on purpose. The row cap answers "a stranger is grinding the ACS": it holds
    | whatever the volume. The retention window answers "these rows carry an IP address and sometimes an
    | email": they go on age even when the cap is nowhere near, which is data minimisation rather than
    | housekeeping. 200 is roughly a screenful an admin might page through; a workspace generating more
    | than that has a configuration problem the newest rows already describe.
    |
    */
    'failure_log_max_rows' => (int) env('SAML_FAILURE_LOG_MAX_ROWS', 200),
    'failure_retention_days' => (int) env('SAML_FAILURE_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Assertion replay ledger
    |--------------------------------------------------------------------------
    |
    | ⚠️ A SERVER-SIDE STORE IS MANDATORY HERE, AND THE PLAN'S FIRST INSTINCT WAS WRONG.
    |
    | The connector OAuth seam (ADR-0009 §D3) deliberately avoids a nonce store by deriving state statelessly
    | under an HMAC. That reasoning DOES NOT TRANSFER, and ADR-0009 says so itself: §D3's stateless `state` is
    | explicitly declared insufficient once "a callback gains a side effect beyond writing the connection it
    | names". A SAML ACS ESTABLISHES A SESSION — the largest side effect in the application. An HMAC proves we
    | minted a token; it cannot prove the token has not been presented before, and single-use is the entire
    | content of replay protection.
    |
    | Two independent mechanisms, both required:
    |   · `sso_auth_requests.consumed_at` — a conditional UPDATE whose affected-row count is the atomic
    |     single-use check for the REQUEST (see SsoAcsController).
    |   · this cache ledger — keyed on the ASSERTION's own `@ID`, covering the case where an IdP mints two
    |     assertions for one request. Retained for the assertion's own validity window plus skew.
    |
    */
    'assertion_replay_ttl_seconds' => (int) env('SAML_ASSERTION_REPLAY_TTL_SECONDS', 3600),

    /*
    |--------------------------------------------------------------------------
    | Transport limits
    |--------------------------------------------------------------------------
    |
    | An upper bound on the `SAMLResponse` form field, enforced BEFORE base64-decoding and again after, so a
    | decompression/parse bomb is refused without ever reaching the XML parser. 512 KiB is far above any real
    | assertion (a large one with many attributes is a few KiB) and far below anything that threatens the
    | process.
    |
    */
    'max_response_bytes' => (int) env('SAML_MAX_RESPONSE_BYTES', 512 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Metadata document limit
    |--------------------------------------------------------------------------
    |
    | The same posture as `max_response_bytes` above, on the other document a tenant can hand us. A real
    | identity-provider metadata document is a few KB; a federation aggregate is refused by the parser on
    | its own terms (§D10). This bound exists because there was none, and the cost is measurable: 16 MB of
    | well-formed, DOCTYPE-free XML peaks at ~38 MB of DOM on top of the source string, so a multi-MB paste
    | is a plausible fatal against a 128 MB memory_limit — and a fatal has no toast and no 422.
    |
    | ⚠️ Enforced TWICE, and the duplication is the point (the StoreBrandingLogoRequest posture): the form
    | request bounds it so the tenant gets a field error, and `SsoMetadataParser::parse()` re-checks before
    | `loadXML()` so a second caller that forgot cannot reach the parser. Both count BYTES — Laravel's
    | `max:` rule on a string counts characters, which is not the same thing for a UTF-8 document.
    |
    */
    'max_metadata_bytes' => (int) env('SAML_MAX_METADATA_BYTES', 256 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Protocol posture — the three answers that are NOT configurable
    |--------------------------------------------------------------------------
    |
    | These are constants rather than env-driven settings on purpose. Each one is a security decision recorded
    | in ADR-0016, and an operator toggling any of them would silently widen the threat model of every tenant
    | at once. They live here so the values are readable and testable in one place, not so they can be changed.
    |
    |   · allow_unsolicited — IdP-initiated SSO is REFUSED. An unsolicited assertion carries no `InResponseTo`,
    |     so there is nothing to bind it to a request this SP minted; accepting one is a login-CSRF primitive
    |     (an attacker replays their own IdP's assertion at a victim's browser and silently swaps the session).
    |   · want_assertions_signed — an assertion with no valid signature over ITS OWN element is refused, even
    |     when the enclosing Response is signed. Signing only the envelope is what makes signature-wrapping
    |     (XSW) exploitable.
    |   · want_name_id — the Subject must carry a NameID; it is the identity the whole flow resolves.
    |
    */
    'allow_unsolicited' => false,
    'want_assertions_signed' => true,
    'want_name_id' => true,

    /*
    |--------------------------------------------------------------------------
    | Bindings we advertise and accept
    |--------------------------------------------------------------------------
    |
    | Exactly one each, and the asymmetry is the specification's, not ours: the AuthnRequest goes out over
    | HTTP-Redirect (a GET with the deflated request in the query string) and the assertion comes back over
    | HTTP-POST (a form post, because an assertion is far too large for a URL).
    |
    */
    'idp_binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
    'acs_binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',

    /*
    |--------------------------------------------------------------------------
    | Default NameID format
    |--------------------------------------------------------------------------
    |
    | Overridable per connection (`sso_connections.name_id_format`). emailAddress is the default because this
    | application's identity key IS the email address — `users.email` is what a JIT provision matches on and
    | what an invitation was addressed to. An IdP configured to send a persistent opaque identifier instead
    | will not resolve to a user here, which the settings screen must say plainly.
    |
    */
    'default_name_id_format' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',

];
