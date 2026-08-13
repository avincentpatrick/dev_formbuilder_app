<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Http\Controllers\Tenant\Sso\SsoMetadataController;
use App\Models\SsoAuthRequest;
use App\Models\SsoConnection;
use App\Models\Tenant;
use DOMDocument;
use DOMElement;
use OneLogin\Saml2\AuthnRequest;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Utils;

/**
 * Composes the `<samlp:AuthnRequest>` this SP sends over the HTTP-Redirect binding (Phase 4, P1b — ADR-0016).
 *
 * ── ⚠️ WHY THIS IS HAND-BUILT WHEN `onelogin/php-saml` SHIPS {@see AuthnRequest} ─────────────────────
 * The library owns the INBOUND half of this slice entirely — {@see Response} performs the XML-signature
 * validation, and that is the one part of SAML nobody should hand-roll. The OUTBOUND half is a fixed
 * fourteen-line document, and two concrete facts rule the library's builder out here:
 *
 *   1. **It mints its own id and offers no seam to supply one.** `Utils::generateUniqueID()` returns
 *      `ONELOGIN_` + a 40-character sha1 — 49 characters — while `sso_auth_requests.request_id` is
 *      `char(33)` and {@see SsoAuthRequest::mintRequestId()} is its documented minter. The id is not
 *      cosmetic: it is the value the assertion's `InResponseTo` is matched against, so it has to be OURS
 *      and it has to fit the column that stores it.
 *   2. **It builds the document by heredoc interpolation**, splicing `Destination="{$destination}"` from
 *      the tenant-controlled `sso_connections.idp_sso_url` with no escaping at all. `SsoMetadataController`
 *      already forbids exactly this for exactly this reason ("XML that a security-critical peer will parse,
 *      and a tenant-controlled value spliced into a template is an injection waiting to happen").
 *      `SsoMetadataParser` runs the URL through `FILTER_VALIDATE_URL` at import, so the hole is closed
 *      upstream today — but that is a property of a *different* class, one refactor away from not holding.
 *
 * So: DOM here, php-saml there. The seam is the wire format, which is what a protocol library is for.
 *
 * ── NO `<samlp:RequestedAuthnContext>`, AND ITS ABSENCE IS A DECISION ────────────────────────────────
 * php-saml defaults `requestedAuthnContext` to TRUE, which emits `PasswordProtectedTransport` with
 * `Comparison="exact"`. Real deployments break on it: an IdP satisfying the login with a passwordless or
 * certificate-based factor legitimately answers with a different context class and `exact` then refuses a
 * successful authentication. This SP has no policy about HOW the IdP authenticated — that is the IdP's
 * job, and P1c's step-up asks the only question we do have (`ForceAuthn`, "do not answer from cache").
 *
 * ── NO `RelayState` ─────────────────────────────────────────────────────────────────────────────────
 * The post-login destination lives on `sso_auth_requests.return_to`, chosen by the SP when it mints the
 * request. `RelayState` is attacker-controllable round-trip data and redirecting to it is a textbook open
 * redirect — the migration's docblock states this, and this class is where it would otherwise be violated.
 */
final class SsoAuthnRequestBuilder
{
    private const NS_SAMLP = 'urn:oasis:names:tc:SAML:2.0:protocol';

    private const NS_SAML = 'urn:oasis:names:tc:SAML:2.0:assertion';

    /**
     * The absolute URL to bounce the browser at — the IdP's SSO endpoint carrying the deflated,
     * base64-encoded, percent-encoded request.
     *
     * `rawurlencode`, not `urlencode`: the HTTP-Redirect binding is URI-encoded per RFC 3986, and base64
     * contains `+`, which `urlencode` would emit as a literal `+` — read back by the IdP as a space, which
     * corrupts the deflate stream. php-saml's own encoder has the same bug shape and gets away with it
     * because most IdPs are lenient; this one is correct rather than lucky.
     */
    public function redirectUrl(Tenant $tenant, SsoConnection $connection, SsoAuthRequest $request): string
    {
        $xml = $this->xml($tenant, $connection, $request);
        $deflated = gzdeflate($xml);

        // gzdeflate returns false only under memory pressure, and this document is a few hundred bytes.
        // Falling back to the raw XML keeps the request WELL-FORMED rather than empty: an IdP that cannot
        // inflate it reports a protocol error the tenant can act on, where a blank SAMLRequest reports
        // nothing at all.
        $encoded = base64_encode($deflated === false ? $xml : $deflated);

        // An IdP SSO endpoint may legitimately already carry a query string (ADFS instance ids, Okta app
        // parameters). Append rather than assume, or the request is silently dropped by the IdP.
        $separator = str_contains($connection->idp_sso_url, '?') ? '&' : '?';

        return $connection->idp_sso_url.$separator.'SAMLRequest='.rawurlencode($encoded);
    }

    /**
     * The request document itself.
     *
     * Public so a test can assert the XML directly rather than inferring it from a URL, and so the
     * `AssertionConsumerServiceURL` it advertises can be compared against the metadata document's — the two
     * come from {@see SsoMetadataController::assertionConsumerServiceUrl()} precisely so they cannot drift.
     */
    public function xml(Tenant $tenant, SsoConnection $connection, SsoAuthRequest $request): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        $authn = $document->createElementNS(self::NS_SAMLP, 'samlp:AuthnRequest');
        $authn->setAttribute('ID', $request->request_id);
        $authn->setAttribute('Version', '2.0');
        // SAML time is UTC with no offset and no fractional seconds. Taken from the row rather than a fresh
        // clock read so the document and the SP's memory of it agree to the second.
        $authn->setAttribute('IssueInstant', $request->issued_at->utc()->format('Y-m-d\TH:i:s\Z'));
        $authn->setAttribute('Destination', $connection->idp_sso_url);
        $authn->setAttribute('ProtocolBinding', (string) config('saml.acs_binding'));
        $authn->setAttribute('AssertionConsumerServiceURL', SsoMetadataController::assertionConsumerServiceUrl($tenant));

        // ForceAuthn is emitted ONLY when asked. On a plain login it is absent rather than "false", because
        // an IdP answering from an existing session is the entire point of single sign-on. P1c's step-up is
        // the caller that sets it, and the ACS requires it to have been set before it will stamp the
        // password-confirmation clock — see SsoAuthIntent.
        if ($request->force_authn) {
            $authn->setAttribute('ForceAuthn', 'true');
        }

        $document->appendChild($authn);

        $issuer = $document->createElementNS(self::NS_SAML, 'saml:Issuer');
        // The SP entity id, CALLED and never re-derived (§D3). It is also the audience the returning
        // assertion is checked against, so a second derivation here would eventually compare two strings
        // that agree until the day one of them changes.
        $issuer->appendChild($document->createTextNode(SsoMetadataController::entityId($tenant)));
        $authn->appendChild($issuer);

        $authn->appendChild($this->nameIdPolicy($document, $connection));

        return $document->saveXML() ?: '';
    }

    /**
     * Ask for the NameID format the tenant's connection is configured for.
     *
     * `AllowCreate="true"` because a JIT-provisioning SP is by definition willing for the IdP to establish a
     * new identifier for a subject it has not sent us before. With it absent, some IdPs refuse to issue a
     * persistent identifier for a first-time user and the very login JIT exists to serve is the one that
     * fails.
     */
    private function nameIdPolicy(DOMDocument $document, SsoConnection $connection): DOMElement
    {
        $policy = $document->createElementNS(self::NS_SAMLP, 'samlp:NameIDPolicy');
        $policy->setAttribute('Format', $connection->name_id_format);
        $policy->setAttribute('AllowCreate', 'true');

        return $policy;
    }

    /**
     * Reverse the transport encoding — the inverse of {@see redirectUrl()}.
     *
     * Lives here rather than in a test helper because it is the definition of the encoding this class
     * applies, and a decoder written separately in a test would certify the test's idea of the format
     * rather than this one's. {@see Utils} has no public counterpart that takes a raw parameter.
     */
    public static function decodeTransport(string $samlRequest): string
    {
        $raw = base64_decode($samlRequest, true);

        if ($raw === false) {
            return '';
        }

        $inflated = @gzinflate($raw);

        return $inflated === false ? $raw : $inflated;
    }
}
