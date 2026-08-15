<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Models\SsoConnection;
use App\Models\Tenant;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Carbon;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Response;
use Throwable;

/**
 * Turns an attacker-supplied `SAMLResponse` into a validated {@see SsoAssertion}, or refuses (P1b).
 *
 * ── ⚠️ php-saml OWNS THE SIGNATURE, AND NOTHING HERE SECOND-GUESSES IT ──────────────────────────────
 * XML signature validation — canonicalisation, reference resolution, the signature-wrapping defences — is
 * the one part of SAML that must not be hand-rolled, and `OneLogin\Saml2\Response::isValid()` is why this
 * dependency is in the tree. Everything in this class is either BEFORE the library (bounds, so a hostile
 * document never reaches the parser) or AFTER it (a check the library cannot be configured to make).
 *
 * ── ⚠️ THE ONE CHECK THAT IS OURS: CLOCK SKEW ───────────────────────────────────────────────────────
 * `Constants::ALLOWED_CLOCK_DRIFT` is a HARD-CODED 180 seconds. `config/saml.php` documents 60 and says
 * why — "the window is the period in which a captured assertion remains replayable, so generosity here is
 * generosity to an attacker who has already achieved interception". Those two statements cannot both be
 * true unless somebody enforces the tighter one, so {@see assertWithinConditions()} runs a second pass over
 * the assertion's own `Conditions` after the library is satisfied. Without it the configured tolerance is
 * decorative and the file that documents it is lying.
 *
 * ⚠️ SCOPED IN P1d: THE SECOND PASS COVERS `Conditions` AND NOTHING ELSE, WHICH THIS PARAGRAPH DID NOT SAY.
 * `assertWithinConditions()` reads `//saml:Assertion/saml:Conditions`. The library separately judges
 * `SubjectConfirmationData`'s `NotBefore`/`NotOnOrAfter` and `SessionNotOnOrAfter` at its own 180 seconds,
 * and neither is reachable from here. So the effective tolerance is 60s on the element this SP re-checks
 * and up to 180s on the ones it does not — stated rather than left as an unqualified "the window is 60
 * seconds". Bounded in practice by the two replay ledgers, which are what actually make a captured
 * assertion single-use; the skew bound is the second layer, never the first.
 * `docs/security-threat-model.md` §9 item 19.
 *
 * ── THE DOCUMENT IS PARSED TWICE, KNOWINGLY ─────────────────────────────────────────────────────────
 * Once by {@see inResponseTo()} to read the one attribute that says which request this answers — needed
 * BEFORE validation, because `isValid()` takes the expected id as an argument and feeding it a value read
 * from the same document would be checking a string against itself — and once by php-saml. Both parses sit
 * behind the same byte bound, and both refuse a DOCTYPE, which is the line that kills XXE and
 * billion-laughs (the `SsoMetadataParser` posture, on the other document a stranger can hand us).
 */
final class SsoAssertionValidator
{
    private const NS_SAML = 'urn:oasis:names:tc:SAML:2.0:assertion';

    public function __construct(private readonly SsoSamlSettings $settings) {}

    /**
     * The `InResponseTo` this response claims to answer.
     *
     * ⚠️ THIS VALUE IS NOT YET TRUSTED. It is read from an unsigned attribute of an unvalidated document,
     * and all it may be used for is LOOKING UP a candidate request row. The row's own `request_id` — never
     * this string — is what gets handed to {@see validate()}, so a forged value can only ever fail to find
     * anything.
     *
     * @throws SsoAuthenticationException
     */
    public function inResponseTo(string $samlResponse): string
    {
        $document = $this->parseHardened($this->decode($samlResponse));
        $root = $document->documentElement;

        if (! $root instanceof DOMElement || ! $root->hasAttribute('InResponseTo')) {
            // §D9: an unsolicited assertion has nothing binding it to a request this SP minted.
            throw SsoAuthenticationException::unsolicited();
        }

        $value = trim($root->getAttribute('InResponseTo'));

        if ($value === '') {
            throw SsoAuthenticationException::unsolicited();
        }

        return $value;
    }

    /**
     * Validate against the tenant's trust anchor, and return what the assertion said.
     *
     * @param  string  $expectedRequestId  the `request_id` of the live row found by look-up — NOT a value
     *                                     read out of the document being validated
     *
     * @throws SsoAuthenticationException
     */
    public function validate(
        Tenant $tenant,
        SsoConnection $connection,
        string $samlResponse,
        string $expectedRequestId,
    ): SsoAssertion {
        // Bound first: the decoded body is what php-saml is about to hand to a DOM parser.
        $this->decode($samlResponse);

        return $this->settings->at($tenant, function () use ($tenant, $connection, $samlResponse, $expectedRequestId): SsoAssertion {
            try {
                $settings = $this->settings->for($tenant, $connection);
                $response = new Response($settings, $samlResponse);
            } catch (Throwable $exception) {
                // A stored connection that cannot form a valid SP/IdP pair, or a document php-saml refuses
                // to load at all (its own loadXML rejects DOCTYPE too). Either way there is nothing to
                // validate, which is the same outcome as an invalid assertion.
                throw SsoAuthenticationException::malformedResponse($exception->getMessage(), $exception);
            }

            if (! $response->isValid($expectedRequestId)) {
                throw SsoAuthenticationException::invalidAssertion(
                    $response->getError(false) ?? 'unspecified',
                    $response->getErrorException(),
                );
            }

            $this->assertWithinConditions($response);

            try {
                $nameId = (string) $response->getNameId();
                $attributes = $response->getAttributes();
                $assertionId = (string) $response->getAssertionId();
                $nameIdFormat = $response->getNameIdFormat();
                $sessionIndex = $response->getSessionIndex();
            } catch (Throwable $exception) {
                // `getNameId()` throws when the Subject carries none and `wantNameId` is set — a document
                // that passed every structural check and still names nobody.
                throw SsoAuthenticationException::invalidAssertion($exception->getMessage(), $exception);
            }

            if (trim($nameId) === '' || $assertionId === '') {
                throw SsoAuthenticationException::invalidAssertion('the assertion names no subject');
            }

            return new SsoAssertion(
                assertionId: $assertionId,
                nameId: trim($nameId),
                nameIdFormat: $nameIdFormat,
                /** @var array<string, list<string>> $attributes */
                attributes: $attributes,
                sessionIndex: $sessionIndex,
            );
        });
    }

    /**
     * The tighter timestamp pass, under `config('saml.clock_skew_seconds')`.
     *
     * ⚠️ RUN AFTER `isValid()`, NEVER INSTEAD OF IT. php-saml's own `validateTimestamps()` is a real check;
     * this one narrows it. Reading the document before validation would mean trusting timestamps nothing
     * has verified the signature over — which is the whole difference between a condition and a claim.
     *
     * @throws SsoAuthenticationException
     */
    private function assertWithinConditions(Response $response): void
    {
        $skew = (int) config('saml.clock_skew_seconds');
        $now = Carbon::now();

        $xpath = new DOMXPath($response->getXMLDocument());
        $xpath->registerNamespace('saml', self::NS_SAML);

        $nodes = $xpath->query('//saml:Assertion/saml:Conditions');

        if ($nodes === false || $nodes->length === 0) {
            // php-saml's `checkOneCondition()` already refuses this under strict mode; reaching here would
            // mean that check moved, so refuse rather than silently skipping the window.
            throw SsoAuthenticationException::outsideConditions('the assertion carries no Conditions element');
        }

        $conditions = $nodes->item(0);

        if (! $conditions instanceof DOMElement) {
            throw SsoAuthenticationException::outsideConditions('the Conditions element could not be read');
        }

        $notBefore = $this->samlTime($conditions->getAttribute('NotBefore'));

        if ($notBefore !== null && $notBefore->isAfter($now->copy()->addSeconds($skew))) {
            throw SsoAuthenticationException::outsideConditions(
                "NotBefore {$conditions->getAttribute('NotBefore')} is beyond a {$skew}s allowance"
            );
        }

        $notOnOrAfter = $this->samlTime($conditions->getAttribute('NotOnOrAfter'));

        if ($notOnOrAfter !== null && $notOnOrAfter->isBefore($now->copy()->subSeconds($skew))) {
            throw SsoAuthenticationException::outsideConditions(
                "NotOnOrAfter {$conditions->getAttribute('NotOnOrAfter')} is beyond a {$skew}s allowance"
            );
        }
    }

    /**
     * Base64-decode and bound, in that order and then the reverse.
     *
     * Both directions are checked because they bound different things: the raw field is what an attacker
     * uploads, and the decoded body is what gets parsed. The POST binding is NOT deflated (unlike the
     * redirect binding), so there is no decompression step and no bomb to defend against here — the bound
     * is against a parse cost, which is real: P1a measured 16 MB of well-formed XML peaking at ~38 MB of
     * DOM, comfortably fatal against a 128 MB limit, with no toast and no 422 to show for it.
     *
     * @throws SsoAuthenticationException
     */
    private function decode(string $samlResponse): string
    {
        if (trim($samlResponse) === '') {
            throw SsoAuthenticationException::missingResponse();
        }

        $limit = (int) config('saml.max_response_bytes');

        if ($limit > 0 && strlen($samlResponse) > $limit) {
            throw SsoAuthenticationException::responseTooLarge(strlen($samlResponse));
        }

        $decoded = base64_decode($samlResponse, true);

        if ($decoded === false || $decoded === '') {
            throw SsoAuthenticationException::malformedResponse('the field is not valid base64');
        }

        if ($limit > 0 && strlen($decoded) > $limit) {
            throw SsoAuthenticationException::responseTooLarge(strlen($decoded));
        }

        return $decoded;
    }

    /**
     * Parse with the `SsoMetadataParser` hardening: no network, no DOCTYPE, no leaked libxml state.
     *
     * @throws SsoAuthenticationException
     */
    private function parseHardened(string $xml): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;

            if ($document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA) === false) {
                throw SsoAuthenticationException::malformedResponse('it is not well-formed XML');
            }

            // The single line that kills XXE and billion-laughs. Checked, never assumed — libxml's defaults
            // have changed across versions and "the library probably handles it" is not a threat model.
            if ($document->doctype !== null) {
                throw SsoAuthenticationException::malformedResponse('it declares a DOCTYPE');
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** SAML timestamps are xs:dateTime in UTC. An unparseable one is treated as absent, never as valid. */
    private function samlTime(string $value): ?Carbon
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
