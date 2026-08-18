<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Enums\SsoAuthIntent;
use App\Http\Controllers\Tenant\Sso\SsoAcsController;
use App\Http\Middleware\RequireRecentPassword;
use App\Models\SsoConnection;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * The assertion-consumption sequence, in one readable place (P1b — ADR-0016).
 *
 * {@see SsoAcsController} is the HTTP shell: gate, hand over, log, redirect. Everything that decides
 * whether a session may be created is here, in the order it must happen — because the ORDER is most of the
 * security, and an order stated across two files is an order that drifts.
 *
 * ── THE SEQUENCE, AND WHY EACH STEP IS WHERE IT IS ──────────────────────────────────────────────────
 *  0. Refuse outright if NO stored signing certificate is currently usable (M2 — ADR-0016 §D31). Not a
 *     step in the sequence so much as a precondition of it: there is nothing to be gained by parsing a
 *     document, or consulting either replay ledger, on behalf of a connection that cannot vouch for
 *     anything. It is also the cheapest possible answer to an anonymous POST — no DOM is allocated.
 *  1. Read `InResponseTo` from the unvalidated document. Nothing is trusted yet; this is a lookup key.
 *  2. FIND (do not consume) the live request row. A read, so a garbage POST carrying a guessed id cannot
 *     invalidate somebody's pending sign-in — a denial of service that would cost the attacker one request.
 *  3. Validate, passing THE ROW'S `request_id`. Never the value read in step 1: handing the document's own
 *     attribute back to the validator would be checking a string against itself.
 *  4. CONSUME the row — the atomic conditional UPDATE whose affected-row count is the check.
 *  5. Record the assertion's own `@ID` in the replay ledger.
 *  6. Resolve the identity, then FORK ON THE ROW'S INTENT — provision (login) or match (step-up).
 *  7. MARK the row with what was established, because this request cannot act on it. Neither arm finishes
 *     here: both hand the browser back to a same-site GET on the tenant's own host, which is the only leg of
 *     the flow that carries a cookie. Everything this step writes is what that hop has to read.
 *
 * ── ⚠️ STEP 0 IS HERE RATHER THAN AT THE MINT, THE GATE, OR INSIDE php-saml (M2) ────────────────────
 * php-saml verifies a signature against a stored certificate WITHOUT parsing its validity window, so an
 * expired trust anchor authenticated indefinitely while `/settings/sso` rendered it as expired — the one
 * surface an admin would consult saying a control was failing while the control was in fact absent.
 *
 * Not at {@see SsoLoginController} (the mint): refusing there reaches the same 404 by a second route and
 * puts a second copy of the rule in a second file, which is how two answers to one question start.
 * Not in {@see SsoGate::activeConnection()}: a dead anchor answering "no connection" would hand
 * {@see RequireRecentPassword} a tidy password fallback and 404 this endpoint with
 * NOTHING RECORDED — destroying the failures-panel row that is the whole point of noticing.
 * And not inside {@see SsoSamlSettings::for()} by filtering the certificate set: §D31 records why.
 *
 * ⚠️ THE RULE IS §D11's ROLL-UP, NOT "ANY EXPIRED KEY REFUSES": a rollover pair — one live key plus a
 * successor whose `notBefore` is in the future — is an identity provider doing exactly the right thing,
 * and refusing it would turn an unfinished rotation into a total outage for that workspace.
 *
 * ── ⚠️ THE FORK IS AFTER THE CONSUME, DELIBERATELY (P1c) ────────────────────────────────────────────
 * A step-up that fails its subject check has still had a real, signed, single-use assertion presented
 * against it. Burning the request first means that assertion cannot be re-presented against a second guess,
 * and it means the failure is recorded in the same place every other one is. Refusing before the consume
 * would leave the row live and turn a mismatch into a retry primitive.
 *
 * ── ⚠️ STEPS 4 AND 5 ARE TWO MECHANISMS AND NEITHER SUBSUMES THE OTHER (§D8) ─────────────────────────
 * `consumed_at` makes the REQUEST single-use: one AuthnRequest, one session. The cache ledger makes the
 * ASSERTION single-use, which covers an IdP that mints two assertions for one request — a case the request
 * row cannot see, because both assertions legitimately carry the same `InResponseTo`.
 *
 * The database one runs FIRST, deliberately: it is the mechanism that cannot be flushed. A cache eviction
 * or a cold Redis silently turns step 5 into a no-op, and the design has to still be sound when it does.
 * The reverse order would put the durable check behind the volatile one.
 *
 * ── EVERY REFUSAL IS AN EXCEPTION, AND NONE OF THEM REACH THE CALLER ────────────────────────────────
 * {@see SsoAuthenticationException} carries a machine reason for the log; the ACS turns all of them into
 * one 404. Distinguishing them on the wire would tell an attacker tuning a forgery exactly how far they
 * got — "wrong audience" means the signature verified.
 */
final class SsoLoginService
{
    public function __construct(
        private readonly SsoCertificateInspector $certificates,
        private readonly SsoAssertionValidator $validator,
        private readonly SsoAuthRequestService $requests,
        private readonly SsoIdentityResolver $identities,
        private readonly SsoUserProvisioner $provisioner,
        private readonly SsoStepUpService $stepUps,
    ) {}

    /**
     * @throws SsoAuthenticationException
     */
    public function consumeAssertion(Tenant $tenant, SsoConnection $connection, string $samlResponse): SsoAuthOutcome
    {
        $signingState = $this->certificates->signingState($connection->idp_certificates);

        if (! in_array($signingState, SsoCertificateInspector::USABLE_STATES, true)) {
            // Both intents, and that is deliberate rather than incidental: an assertion signed by an
            // anchor nobody can vouch for is no more trustworthy for a re-authentication than for a login.
            throw SsoAuthenticationException::idpCertificateUnusable($signingState);
        }

        $inResponseTo = $this->validator->inResponseTo($samlResponse);
        $authRequest = $this->requests->findLive($inResponseTo);

        if ($authRequest === null) {
            // Never minted, minted by another tenant, already consumed, or expired. Four security events,
            // one answer — and the row survives so the distinction is recoverable from the ledger later.
            throw SsoAuthenticationException::unknownRequest($inResponseTo);
        }

        $assertion = $this->validator->validate($tenant, $connection, $samlResponse, $authRequest->request_id);

        if (! $this->requests->consume($authRequest)) {
            throw SsoAuthenticationException::requestReplayed($authRequest->request_id);
        }

        $this->rememberAssertion($tenant, $assertion);

        $identity = $this->identities->resolve($connection, $assertion);

        if ($authRequest->intent === SsoAuthIntent::StepUp) {
            // Marks the row itself, once its own three conditions hold. Kept inside that method rather than
            // hoisted here, because on that arm the mark means "the assertion agreed with who we asked about"
            // and there is nothing to record beyond the timestamp.
            $user = $this->stepUps->matchSubject($authRequest, $identity);
        } else {
            $user = $this->provisioner->provision($tenant, $connection, $identity);

            // ⚠️ STEP 7, AND P1e's WHOLE REASON FOR EXISTING. This request cannot create a session — it is a
            // cross-site POST with no cookie — so the only thing it can do with the identity it has just
            // established is write it down for the same-site hop that CAN. Without this the hop would be
            // entitled to redeem a row and have nobody to sign in, which is why the database refuses a
            // verified login row that carries no subject rather than leaving it to this line.
            $this->requests->markVerified($authRequest, $user);
        }

        return new SsoAuthOutcome($authRequest->intent, $user, $authRequest);
    }

    /**
     * Claim this assertion id, or refuse.
     *
     * `Cache::add()` — an atomic add-if-absent — rather than `has()` followed by `put()`, which is the same
     * read-then-write race `consumed_at` is written to avoid, one layer up. Two concurrent replays of one
     * assertion would both see "absent" and both proceed.
     *
     * Keyed per tenant even though a SAML `@ID` is unique by construction: this cache is shared across
     * every workspace in the deployment, and a key namespace that depends on somebody else's id generator
     * being well-behaved is one hostile IdP away from letting one tenant deny sign-ins to another.
     *
     * @throws SsoAuthenticationException
     */
    private function rememberAssertion(Tenant $tenant, SsoAssertion $assertion): void
    {
        $key = 'sso:assertion:'.$tenant->getKey().':'.hash('sha256', $assertion->assertionId);
        $ttl = (int) config('saml.assertion_replay_ttl_seconds');

        if (! Cache::add($key, true, $ttl)) {
            throw SsoAuthenticationException::assertionReplayed($assertion->assertionId);
        }
    }
}
