<?php

declare(strict_types=1);

namespace Tests\Support\Sso;

use App\Models\SsoConnection;
use App\Services\Sso\SsoMetadataParser;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Carbon;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;

/**
 * An identity provider that actually signs things (P1b — ADR-0016).
 *
 * ── ⚠️ WHY A REAL SIGNER AND NOT A FIXTURE ──────────────────────────────────────────────────────────
 * The one thing the ACS does that nothing else in this application does is decide whether an XML signature
 * over an attacker-supplied document is valid. A canned base64 blob cannot exercise that: it would expire,
 * it could not be tampered with in a controlled way, and — worst — a test suite built on one tends to
 * assert the shape of the fixture rather than the behaviour of the validator. So this class holds a real
 * RSA keypair, signs the assertion with `xmlseclibs` exactly as an IdP would, and exposes a knob for each
 * way a signature or a condition can be wrong.
 *
 * `SsoConnectionFactory::certificate()` cannot be reused: it generates a keypair and **throws the private
 * key away**, which is all the certificate-inspector suite needs and precisely what signing cannot do
 * without. The two share the memoization discipline instead — `openssl_pkey_new(2048)` costs ~100 ms and
 * this is mounted by every ACS case.
 *
 * ── THE DOCUMENT IS BUILT WITH DOM, AND ELEMENT ORDER IS LOAD-BEARING ───────────────────────────────
 * `SsoSamlSettings` sets `wantXMLValidation`, so php-saml validates the response against
 * `saml-schema-protocol-2.0.xsd` before anything else — and the schema's sequences are strict. Response is
 * `Issuer, Status, Assertion`; Assertion is `Issuer, Signature, Subject, Conditions, AuthnStatement,
 * AttributeStatement`. A builder that emitted them in a plausible-but-wrong order would fail every case
 * with `INVALID_XML_FORMAT` and look like a signing bug.
 *
 * No whitespace between elements, deliberately: the signature is inserted immediately after `<saml:Issuer>`
 * (where the schema requires it), and with a pretty-printed document `$issuer->nextSibling` is a text node.
 */
final class FakeIdp
{
    public const ENTITY_ID = 'https://idp.example.test/saml2';

    public const SSO_URL = 'https://idp.example.test/saml2/sso';

    private const NS_SAMLP = 'urn:oasis:names:tc:SAML:2.0:protocol';

    private const NS_SAML = 'urn:oasis:names:tc:SAML:2.0:assertion';

    private const STATUS_SUCCESS = 'urn:oasis:names:tc:SAML:2.0:status:Success';

    private const CM_BEARER = 'urn:oasis:names:tc:SAML:2.0:cm:bearer';

    /** @var array<string, array{private: string, certificate: string}> */
    private static array $material = [];

    private string $nameId = 'ada@acme.test';

    private ?string $nameIdFormat = null;

    /** @var array<string, list<string>> */
    private array $attributes = [];

    private bool $signAssertion = true;

    private bool $signResponse = false;

    /** Which memoized keypair signs — `foreign` is a well-formed signature from a key we do not trust. */
    private string $signWith = 'primary';

    private ?string $audience = null;

    private ?string $destination = null;

    private ?string $recipient = null;

    private ?string $issuer = null;

    private bool $omitNameId = false;

    private bool $omitConditions = false;

    private bool $wrapped = false;

    /** Seconds from "now" for the Conditions window's two edges; null means the ordinary ±5 minutes. */
    private ?int $conditionsNotBeforeOffset = null;

    private ?int $conditionsNotOnOrAfterOffset = null;

    private int $subjectOffsetSeconds = 0;

    private ?string $assertionId = null;

    /**
     * @param  string  $inResponseTo  the `request_id` of the row this answers; null-able only through
     *                                {@see unsolicited()}, which is the IdP-initiated case §D9 refuses
     */
    public function __construct(
        private readonly string $acsUrl,
        private readonly string $spEntityId,
        private ?string $inResponseTo,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Key material — memoized per process, generated never hard-coded
    |--------------------------------------------------------------------------
    */

    /** The signing certificate as bare base64 DER, the shape `sso_connections.idp_certificates` stores. */
    public static function certificate(string $which = 'primary'): string
    {
        return self::material($which)['certificate'];
    }

    /**
     * An Active connection whose trust anchor is this IdP.
     *
     * Requires `enterTenant()` first — `BelongsToTenant` fills `tenant_id` from the ambient context, and
     * under strict FORCE RLS forgetting that writes zero rows rather than erroring.
     */
    public static function connection(array $overrides = []): SsoConnection
    {
        $certificate = self::certificate();

        return SsoConnection::factory()->active()->create(array_merge([
            'idp_entity_id' => self::ENTITY_ID,
            'idp_sso_url' => self::SSO_URL,
            'idp_certificates' => [$certificate],
            'idp_certificates_fingerprint' => SsoMetadataParser::fingerprint([$certificate]),
        ], $overrides));
    }

    /*
    |--------------------------------------------------------------------------
    | Knobs — one per way a response can be wrong
    |--------------------------------------------------------------------------
    */

    public function as(string $nameId): self
    {
        $this->nameId = $nameId;

        return $this;
    }

    public function withNameIdFormat(?string $format): self
    {
        $this->nameIdFormat = $format;

        return $this;
    }

    /** @param array<string, string|list<string>> $attributes */
    public function withAttributes(array $attributes): self
    {
        foreach ($attributes as $name => $value) {
            $this->attributes[$name] = is_array($value) ? array_values($value) : [$value];
        }

        return $this;
    }

    /** No signature anywhere — the baseline every SAML SP must refuse. */
    public function unsigned(): self
    {
        $this->signAssertion = false;
        $this->signResponse = false;

        return $this;
    }

    /**
     * Sign only the enclosing Response, leaving the assertion bare.
     *
     * This is the shape `want_assertions_signed` exists for: signing only the envelope is what makes
     * signature wrapping exploitable, because the assertion an SP reads is then not the thing covered by
     * the signature it checked.
     */
    public function responseSignedOnly(): self
    {
        $this->signAssertion = false;
        $this->signResponse = true;

        return $this;
    }

    /** A structurally perfect signature made with a key this tenant has never trusted. */
    public function signedByAnUntrustedKey(): self
    {
        $this->signWith = 'foreign';

        return $this;
    }

    public function withAudience(string $audience): self
    {
        $this->audience = $audience;

        return $this;
    }

    public function withDestination(string $destination): self
    {
        $this->destination = $destination;

        return $this;
    }

    public function withRecipient(string $recipient): self
    {
        $this->recipient = $recipient;

        return $this;
    }

    public function issuedBy(string $issuer): self
    {
        $this->issuer = $issuer;

        return $this;
    }

    public function withInResponseTo(string $requestId): self
    {
        $this->inResponseTo = $requestId;

        return $this;
    }

    /** The IdP-initiated case: an assertion with nothing binding it to a request this SP minted (§D9). */
    public function unsolicited(): self
    {
        $this->inResponseTo = null;

        return $this;
    }

    /**
     * Pin the assertion's own `@ID`.
     *
     * The knob the cache ledger exists for: an IdP that mints TWO assertions carrying one id, each
     * answering a DIFFERENT live request. `consumed_at` cannot see that — both requests are legitimately
     * distinct rows — so the second mechanism is the only thing standing between it and two sessions.
     */
    public function withAssertionId(string $id): self
    {
        $this->assertionId = $id;

        return $this;
    }

    public function withoutNameId(): self
    {
        $this->omitNameId = true;

        return $this;
    }

    public function withoutConditions(): self
    {
        $this->omitConditions = true;

        return $this;
    }

    /**
     * Put the `Conditions` window's CLOSING edge `$seconds` in the past, WITHOUT touching
     * `SubjectConfirmationData`.
     *
     * ⚠️ TWO THINGS ARE LOAD-BEARING HERE, AND THE FIRST DRAFT GOT THE ARITHMETIC WRONG. (1) It must move
     * the EDGE, not slide the whole ±5-minute window — sliding a ten-minute window by two minutes leaves it
     * wide open, which is how a "refuses a stale assertion" case quietly asserts nothing. (2) The subject
     * confirmation must stay valid, or php-saml rejects the document on ITS timestamps first and the case
     * proves nothing about the check it was written for.
     *
     * The gap this exists to exercise: php-saml validates with a HARD-CODED
     * `Constants::ALLOWED_CLOCK_DRIFT` of 180 seconds, while `config('saml.clock_skew_seconds')` is 60.
     * Anything between them passes the library and must be refused by our own pass.
     */
    public function conditionsStaleBy(int $seconds): self
    {
        $this->conditionsNotOnOrAfterOffset = -$seconds;

        return $this;
    }

    /** The other edge: a window that has not opened yet. Same 60-vs-180 gap, opposite direction. */
    public function conditionsNotYetValidFor(int $seconds): self
    {
        $this->conditionsNotBeforeOffset = $seconds;

        return $this;
    }

    public function subjectStaleBy(int $seconds): self
    {
        $this->subjectOffsetSeconds = -$seconds;

        return $this;
    }

    /**
     * Signature wrapping: a validly signed assertion demoted into the `<saml:Advice>` of an unsigned one
     * carrying the attacker's identity.
     *
     * The signature still verifies — it covers the original element, which is untouched — so a validator
     * that only asks "is there a valid signature?" reads the attacker's NameID. Defence in depth catches
     * this at more than one layer, and the test asserts the refusal rather than which layer produced it.
     */
    public function wrappedAround(string $victimNameId): self
    {
        $this->wrapped = true;
        $this->attributes['__wrapped_name_id'] = [$victimNameId];

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Emission
    |--------------------------------------------------------------------------
    */

    /** The `SAMLResponse` form field: base64 of the response document, exactly as the POST binding sends it. */
    public function response(): string
    {
        return base64_encode($this->xml());
    }

    public function xml(): string
    {
        $now = Carbon::now();
        $document = new DOMDocument('1.0', 'UTF-8');

        $response = $document->createElementNS(self::NS_SAMLP, 'samlp:Response');
        $response->setAttribute('ID', '_'.bin2hex(random_bytes(16)));
        $response->setAttribute('Version', '2.0');
        $response->setAttribute('IssueInstant', self::samlTime($now));
        $response->setAttribute('Destination', $this->destination ?? $this->acsUrl);

        if ($this->inResponseTo !== null) {
            $response->setAttribute('InResponseTo', $this->inResponseTo);
        }

        $document->appendChild($response);

        // Schema order: Issuer, Status, Assertion. The Signature slot sits between Issuer and Status, and
        // insertSignature() below places it there.
        $response->appendChild($this->issuerElement($document));
        $response->appendChild($this->status($document));

        $assertion = $this->assertion($document, $now);
        $response->appendChild($assertion);

        if ($this->signAssertion) {
            $this->sign($assertion);
        }

        if ($this->wrapped) {
            $assertion = $this->wrap($document, $response, $assertion, $now);
        }

        if ($this->signResponse) {
            $this->sign($response);
        }

        return $document->saveXML() ?: '';
    }

    /*
    |--------------------------------------------------------------------------
    | Document construction
    |--------------------------------------------------------------------------
    */

    private function assertion(DOMDocument $document, Carbon $now): DOMElement
    {
        $assertion = $document->createElementNS(self::NS_SAML, 'saml:Assertion');
        $assertion->setAttribute('ID', $this->assertionId ?? '_'.bin2hex(random_bytes(16)));
        $assertion->setAttribute('Version', '2.0');
        $assertion->setAttribute('IssueInstant', self::samlTime($now));

        $assertion->appendChild($this->issuerElement($document));
        $assertion->appendChild($this->subject($document, $now, $this->nameId));

        if (! $this->omitConditions) {
            $assertion->appendChild($this->conditions($document, $now));
        }

        $assertion->appendChild($this->authnStatement($document, $now));

        if ($this->attributes !== []) {
            $assertion->appendChild($this->attributeStatement($document));
        }

        return $assertion;
    }

    private function issuerElement(DOMDocument $document): DOMElement
    {
        $issuer = $document->createElementNS(self::NS_SAML, 'saml:Issuer');
        $issuer->appendChild($document->createTextNode($this->issuer ?? self::ENTITY_ID));

        return $issuer;
    }

    private function status(DOMDocument $document): DOMElement
    {
        $status = $document->createElementNS(self::NS_SAMLP, 'samlp:Status');
        $code = $document->createElementNS(self::NS_SAMLP, 'samlp:StatusCode');
        $code->setAttribute('Value', self::STATUS_SUCCESS);
        $status->appendChild($code);

        return $status;
    }

    private function subject(DOMDocument $document, Carbon $now, string $nameId): DOMElement
    {
        $subject = $document->createElementNS(self::NS_SAML, 'saml:Subject');

        if (! $this->omitNameId) {
            $element = $document->createElementNS(self::NS_SAML, 'saml:NameID');
            $element->setAttribute('Format', $this->nameIdFormat ?? (string) config('saml.default_name_id_format'));
            $element->appendChild($document->createTextNode($nameId));
            $subject->appendChild($element);
        }

        $confirmation = $document->createElementNS(self::NS_SAML, 'saml:SubjectConfirmation');
        $confirmation->setAttribute('Method', self::CM_BEARER);

        $data = $document->createElementNS(self::NS_SAML, 'saml:SubjectConfirmationData');
        $data->setAttribute('NotOnOrAfter', self::samlTime($now->copy()->addMinutes(5)->addSeconds($this->subjectOffsetSeconds)));
        $data->setAttribute('Recipient', $this->recipient ?? $this->acsUrl);

        if ($this->inResponseTo !== null) {
            $data->setAttribute('InResponseTo', $this->inResponseTo);
        }

        $confirmation->appendChild($data);
        $subject->appendChild($confirmation);

        return $subject;
    }

    private function conditions(DOMDocument $document, Carbon $now): DOMElement
    {
        $conditions = $document->createElementNS(self::NS_SAML, 'saml:Conditions');
        $conditions->setAttribute('NotBefore', self::samlTime(
            $now->copy()->addSeconds($this->conditionsNotBeforeOffset ?? -300)
        ));
        $conditions->setAttribute('NotOnOrAfter', self::samlTime(
            $now->copy()->addSeconds($this->conditionsNotOnOrAfterOffset ?? 300)
        ));

        $restriction = $document->createElementNS(self::NS_SAML, 'saml:AudienceRestriction');
        $audience = $document->createElementNS(self::NS_SAML, 'saml:Audience');
        // The SP entity id — per tenant, which is what makes the audience check a cross-tenant isolation
        // boundary by construction rather than by bookkeeping.
        $audience->appendChild($document->createTextNode($this->audience ?? $this->spEntityId));
        $restriction->appendChild($audience);
        $conditions->appendChild($restriction);

        return $conditions;
    }

    private function authnStatement(DOMDocument $document, Carbon $now): DOMElement
    {
        $statement = $document->createElementNS(self::NS_SAML, 'saml:AuthnStatement');
        $statement->setAttribute('AuthnInstant', self::samlTime($now));
        $statement->setAttribute('SessionIndex', '_session'.bin2hex(random_bytes(8)));

        $context = $document->createElementNS(self::NS_SAML, 'saml:AuthnContext');
        $reference = $document->createElementNS(self::NS_SAML, 'saml:AuthnContextClassRef');
        $reference->appendChild($document->createTextNode('urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport'));
        $context->appendChild($reference);
        $statement->appendChild($context);

        return $statement;
    }

    private function attributeStatement(DOMDocument $document): DOMElement
    {
        $statement = $document->createElementNS(self::NS_SAML, 'saml:AttributeStatement');

        foreach ($this->attributes as $name => $values) {
            if ($name === '__wrapped_name_id') {
                continue;
            }

            $attribute = $document->createElementNS(self::NS_SAML, 'saml:Attribute');
            $attribute->setAttribute('Name', $name);

            foreach ($values as $value) {
                $element = $document->createElementNS(self::NS_SAML, 'saml:AttributeValue');
                $element->appendChild($document->createTextNode($value));
                $attribute->appendChild($element);
            }

            $statement->appendChild($attribute);
        }

        return $statement;
    }

    /**
     * Demote the signed assertion into an unsigned one's `<saml:Advice>`.
     *
     * `Advice` is schema-legal and may contain an Assertion, so the document still validates — which is
     * exactly why this shape is worth a test rather than a comment.
     */
    private function wrap(DOMDocument $document, DOMElement $response, DOMElement $signed, Carbon $now): DOMElement
    {
        $victim = $this->attributes['__wrapped_name_id'][0] ?? 'attacker@acme.test';

        $evil = $document->createElementNS(self::NS_SAML, 'saml:Assertion');
        $evil->setAttribute('ID', '_'.bin2hex(random_bytes(16)));
        $evil->setAttribute('Version', '2.0');
        $evil->setAttribute('IssueInstant', self::samlTime($now));
        $evil->appendChild($this->issuerElement($document));
        $evil->appendChild($this->subject($document, $now, $victim));
        $evil->appendChild($this->conditions($document, $now));

        // Schema order puts Advice between Conditions and the statements.
        $advice = $document->createElementNS(self::NS_SAML, 'saml:Advice');
        $response->removeChild($signed);
        $advice->appendChild($signed);
        $evil->appendChild($advice);

        $evil->appendChild($this->authnStatement($document, $now));
        $response->appendChild($evil);

        return $evil;
    }

    /*
    |--------------------------------------------------------------------------
    | Signing
    |--------------------------------------------------------------------------
    */

    private function sign(DOMElement $element): void
    {
        $material = self::material($this->signWith);

        $signature = new XMLSecurityDSig;
        $signature->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $signature->addReference(
            $element,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N],
            // `overwrite => false` keeps the element's own `ID`, which is what php-saml's
            // `processSignedElements()` compares the Reference URI against. Letting xmlseclibs mint a new
            // one would make every signature "invalid signed element" and look like a signing failure.
            ['id_name' => 'ID', 'overwrite' => false],
        );

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($material['private'], false);
        $signature->sign($key);
        $signature->add509Cert(SsoMetadataParser::pem($material['certificate']), true);

        // Immediately after <Issuer>: the schema's sequence puts Signature there, and php-saml's
        // ASSERTION_SIGNATURE_XPATH expects it as a direct child.
        $issuer = $element->getElementsByTagNameNS(self::NS_SAML, 'Issuer')->item(0);
        $signature->insertSignature($element, $issuer instanceof DOMElement ? $issuer->nextSibling : $element->firstChild);
    }

    /**
     * @return array{private: string, certificate: string}
     */
    private static function material(string $which): array
    {
        if (isset(self::$material[$which])) {
            return self::$material[$which];
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $which.'.idp.example.test'], $key);

        if ($key === false || is_bool($csr)) {
            throw new RuntimeException('Could not generate the fake identity provider signing key.');
        }

        $signed = openssl_csr_sign($csr, null, $key, 365);

        if ($signed === false || openssl_x509_export($signed, $certificatePem) === false) {
            throw new RuntimeException('Could not sign the fake identity provider certificate.');
        }

        if (openssl_pkey_export($key, $privatePem) === false) {
            throw new RuntimeException('Could not export the fake identity provider private key.');
        }

        return self::$material[$which] = [
            'private' => (string) $privatePem,
            'certificate' => (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $certificatePem),
        ];
    }

    /** SAML time is UTC, no offset, no fractional seconds. */
    private static function samlTime(Carbon $moment): string
    {
        return $moment->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
