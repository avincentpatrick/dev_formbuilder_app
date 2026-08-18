<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Http\Controllers\Tenant\Sso\SsoMetadataController;
use App\Models\SsoConnection;
use App\Models\Tenant;
use Closure;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use Throwable;

/**
 * Hands `onelogin/php-saml` a per-tenant configuration, and pins the one piece of global state its
 * validation depends on (Phase 4, P1b — ADR-0016).
 *
 * ── ⚠️ EVERY SECURITY FLAG IS SET EXPLICITLY, NONE ARE LEFT TO THE LIBRARY'S DEFAULTS ────────────────
 * `Settings::_addDefaultValues()` fills in a dozen keys, and the ones that matter here default the WRONG
 * WAY for this application: `wantAssertionsSigned` defaults to **false**,
 * `rejectUnsolicitedResponsesWithInResponseTo` to **false**, `destinationStrictlyMatches` to **false**.
 * Each is a decision `config/saml.php` already recorded as non-negotiable, so each is written out here
 * rather than inherited. A default that happens to agree with us today is not a control; it is a coincidence
 * that no test would notice changing.
 *
 * ── ⚠️ `currentURL` IS SEEDED, AND THIS IS THE DIFFERENCE BETWEEN A REAL DESTINATION CHECK AND A
 *      SELF-SATISFYING ONE ───────────────────────────────────────────────────────────────────────────
 * {@see Response::isValid()} builds `currentURL` from `Utils::getSelfRoutedURLNoQuery()`, which reads
 * `$_SERVER['REQUEST_URI']` and `$_SERVER['HTTP_HOST']` — **superglobals Laravel's test client never
 * populates**, because it builds a `Symfony\Component\HttpFoundation\Request` and does not call
 * `overrideGlobals()`. Left alone, `currentURL` collapses to the bare host (or to the PHPUnit binary's
 * path), and with the library's default `destinationStrictlyMatches => false` the comparison is
 * `strncmp($destination, $currentURL, strlen($currentURL))` — a PREFIX match that passes. The suite would
 * be green and the Destination check would not be running. That is the "test certifying a behaviour that
 * did not exist" shape this repo has already been bitten by once.
 *
 * So {@see at()} pins host, port, protocol and request path from
 * {@see SsoMetadataController::assertionConsumerServiceUrl()} — §D3's single composition point, the same
 * string the metadata document published and the same one the `Recipient` check uses — and
 * `destinationStrictlyMatches` is turned ON so the assertion must name it exactly. Both are restored in a
 * `finally`, because these are process-global statics and a leaked value would silently retarget the next
 * request's check.
 *
 * ── NO SP KEY MATERIAL, DELIBERATELY ────────────────────────────────────────────────────────────────
 * P1 signs no AuthnRequests and decrypts no assertions, so there is no `sp.privateKey` and no
 * `sp.x509cert`. The SP metadata omits `<md:KeyDescriptor>` for the same reason: publishing a key we will
 * not use is how an IdP gets configured to encrypt assertions this application then cannot read.
 */
final class SsoSamlSettings
{
    /**
     * Run `$callback` with php-saml's idea of "the URL this request arrived at" pinned to the tenant's
     * canonical ACS location.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function at(Tenant $tenant, Closure $callback): mixed
    {
        $acsUrl = SsoMetadataController::assertionConsumerServiceUrl($tenant);

        $scheme = parse_url($acsUrl, PHP_URL_SCHEME);
        $host = parse_url($acsUrl, PHP_URL_HOST);
        $port = parse_url($acsUrl, PHP_URL_PORT);
        $path = parse_url($acsUrl, PHP_URL_PATH);

        $previousRequestUri = $_SERVER['REQUEST_URI'] ?? null;

        Utils::setSelfProtocol(is_string($scheme) ? $scheme : 'https');
        Utils::setSelfHost(is_string($host) ? $host : '');
        // An explicit 80/443 is what makes `getSelfURLhost()` OMIT the port suffix, reproducing a URL that
        // carries none. Leaving it unset instead falls through to `$_SERVER['SERVER_PORT']`, which is the
        // superglobal this whole method exists to stop depending on. `parse_url` answers `int|false|null`,
        // so the narrowing is `is_int` rather than `??` — `false` is not null and would be passed straight
        // through as a port number of zero.
        Utils::setSelfPort(is_int($port) ? $port : ($scheme === 'http' ? 80 : 443));
        // EMPTY rather than the ACS path: `getSelfRoutedURLNoQuery()` PREPENDS the base path to the route it
        // reads below, so setting both would produce `/sso/saml/acs/sso/saml/acs`. An empty string is how
        // `setBaseURLPath()` spells "none" — it stores null internally.
        Utils::setBaseURLPath('');
        $_SERVER['REQUEST_URI'] = is_string($path) ? $path : '/';

        try {
            return $callback();
        } finally {
            Utils::setBaseURL('');

            if ($previousRequestUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $previousRequestUri;
            }
        }
    }

    /**
     * The php-saml settings for one tenant's trust relationship.
     *
     * @throws Throwable when the stored connection cannot form a valid SP/IdP pair — which the ACS treats
     *                   as any other refusal, because a connection that cannot be configured cannot have
     *                   produced a legitimate assertion either
     */
    public function for(Tenant $tenant, SsoConnection $connection): Settings
    {
        return new Settings([
            // Strict is what turns every check below from advisory into enforced. Without it php-saml
            // accepts unsigned messages and skips destination, audience, timestamp and InResponseTo.
            'strict' => true,
            'debug' => false,

            'sp' => [
                // CALLED, never re-derived (§D3). This value is simultaneously the SP entity id, the URL the
                // metadata is served from, and the audience the assertion is checked against.
                'entityId' => SsoMetadataController::entityId($tenant),
                'assertionConsumerService' => [
                    'url' => SsoMetadataController::assertionConsumerServiceUrl($tenant),
                    'binding' => (string) config('saml.acs_binding'),
                ],
                'NameIDFormat' => $connection->name_id_format,
            ],

            'idp' => [
                'entityId' => $connection->idp_entity_id,
                'singleSignOnService' => [
                    'url' => $connection->idp_sso_url,
                    'binding' => (string) config('saml.idp_binding'),
                ],
                // `x509certMulti.signing`, not `x509cert`: an IdP legitimately publishes a successor
                // alongside its current key during a rollover (§D11), and the whole set has to be trusted
                // or a correctly-executed rotation fails at the SP. php-saml PEM-armours bare base64 DER
                // itself, which is the shape this column stores.
                'x509certMulti' => ['signing' => $connection->idp_certificates],
            ],

            'security' => [
                // ── The three §D9 constants ──────────────────────────────────────────────────────────
                'wantAssertionsSigned' => (bool) config('saml.want_assertions_signed'),
                'wantNameId' => (bool) config('saml.want_name_id'),
                // A response carrying an InResponseTo when none was expected is refused. Belt to the ACS's
                // braces: it always passes an expected id, so this arm should be unreachable — and it is
                // set anyway, because the day it becomes reachable is the day it matters.
                'rejectUnsolicitedResponsesWithInResponseTo' => true,

                // ── Destination ─────────────────────────────────────────────────────────────────────
                // Exact string equality against the seeded currentURL. The library's default is a prefix
                // match, under which an assertion destined for `…/sso/saml/acs.evil` would pass.
                'destinationStrictlyMatches' => true,
                'relaxDestinationValidation' => false,

                // ── Things we neither send nor expect ───────────────────────────────────────────────
                // P1 has no SP key, so requesting encryption would guarantee an unreadable assertion.
                'wantAssertionsEncrypted' => false,
                'wantNameIdEncrypted' => false,
                'nameIdEncrypted' => false,
                'authnRequestsSigned' => false,
                // The enclosing Response MAY be signed and often is; it is not REQUIRED, because the
                // assertion's own signature is the one that matters. Signing only the envelope is what
                // makes signature wrapping exploitable — hence the asymmetry, in this direction only.
                'wantMessagesSigned' => false,
                // php-saml defaults this to true, which emits PasswordProtectedTransport with
                // Comparison="exact" and refuses a successful passwordless login. This SP has no policy
                // about how the IdP authenticated. (Only affects the library's own AuthnRequest builder,
                // which we do not use — set for honesty, so the settings describe the deployment.)
                'requestedAuthnContext' => false,

                // ── Parsing ─────────────────────────────────────────────────────────────────────────
                'wantXMLValidation' => true,
                // A repeated Attribute Name is how one value is smuggled past a reader that takes the
                // first and another that takes the last.
                'allowRepeatAttributeName' => false,

                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
            ],
        ]);
    }
}
