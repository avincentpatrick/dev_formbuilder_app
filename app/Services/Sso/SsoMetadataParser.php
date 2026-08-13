<?php

declare(strict_types=1);

namespace App\Services\Sso;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parses an IdP's SAML 2.0 metadata XML into the fields of an `sso_connections` row (Phase 4, P1a).
 *
 * ── THIS PARSER IS AN ATTACK SURFACE AND IS WRITTEN AS ONE ───────────────────────────────────────────
 * The XML arrives from a tenant admin pasting whatever their IdP handed them, so it is untrusted input from
 * a semi-trusted actor. Three hardening measures, none of which may be relaxed:
 *
 *   · `$doc->doctype !== null` → refuse. This ONE LINE kills both XXE (external entity expansion reading
 *     local files or making outbound requests) and the billion-laughs entity-expansion DoS. It is checked
 *     explicitly rather than trusting libxml's defaults, because those defaults have changed across versions
 *     and "the library probably handles it" is not a threat-model statement.
 *   · `LIBXML_NONET` — no network access during parse, so even a surviving entity cannot dial out.
 *   · `libxml_use_internal_errors(true)` with the previous state restored, so a malformed document produces
 *     our exception rather than PHP warnings interleaved into a response body.
 *
 * ── IMPORT IS A PUT OF THE WHOLE IdP HALF, NEVER A MERGE ─────────────────────────────────────────────
 * Every field this returns replaces its stored counterpart together. A partial import is precisely how a
 * stale trust anchor survives a key rotation: the new SSO URL lands, the old certificate stays, and the
 * tenant is left trusting a key their IdP has retired.
 */
final class SsoMetadataParser
{
    private const NS_MD = 'urn:oasis:names:tc:SAML:2.0:metadata';

    private const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const PROTOCOL_SAML2 = 'urn:oasis:names:tc:SAML:2.0:protocol';

    private const BINDING_REDIRECT = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect';

    /**
     * @return array{
     *     idp_entity_id: string,
     *     idp_sso_url: string,
     *     idp_certificates: list<string>,
     *     idp_certificates_fingerprint: string,
     *     idp_metadata_sha256: string,
     *     name_id_format: ?string
     * }
     *
     * @throws SsoMetadataException
     */
    public function parse(string $xml): array
    {
        $document = $this->parseHardened($xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('md', self::NS_MD);
        $xpath->registerNamespace('ds', self::NS_DS);

        $descriptor = $this->locateIdpDescriptor($xpath, $document);
        $entityId = $this->entityIdFor($descriptor);
        $certificates = $this->signingCertificates($xpath, $descriptor);

        return [
            'idp_entity_id' => $entityId,
            'idp_sso_url' => $this->singleSignOnUrl($xpath, $descriptor),
            'idp_certificates' => $certificates,
            'idp_certificates_fingerprint' => self::fingerprint($certificates),
            'idp_metadata_sha256' => hash('sha256', $xml),
            'name_id_format' => $this->nameIdFormat($xpath, $descriptor),
        ];
    }

    /**
     * The stable identity of a certificate SET, order-independent.
     *
     * Sorted before hashing because an IdP may reorder its `KeyDescriptor` elements between publications
     * without changing which keys it signs with — and a fingerprint that changes on a reorder would report a
     * rotation that did not happen, training an admin to ignore the one alert that matters.
     *
     * @param  list<string>  $certificates  base64 DER, no PEM armour
     */
    public static function fingerprint(array $certificates): string
    {
        sort($certificates);

        return hash('sha256', implode("\n", $certificates));
    }

    /** @throws SsoMetadataException */
    private function parseHardened(string $xml): DOMDocument
    {
        if (trim($xml) === '') {
            throw new SsoMetadataException('The metadata document is empty.');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);

            if ($loaded === false) {
                throw new SsoMetadataException('The metadata is not well-formed XML.');
            }

            // The single line that kills XXE and billion-laughs. Checked, never assumed.
            if ($document->doctype !== null) {
                throw new SsoMetadataException('The metadata declares a DOCTYPE, which is not accepted.');
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @throws SsoMetadataException */
    private function locateIdpDescriptor(DOMXPath $xpath, DOMDocument $document): DOMElement
    {
        $descriptors = $xpath->query('//md:EntityDescriptor/md:IDPSSODescriptor');

        if ($descriptors === false || $descriptors->length === 0) {
            throw new SsoMetadataException(
                'No IDPSSODescriptor found. This looks like service-provider metadata rather than '
                .'identity-provider metadata.'
            );
        }

        // An EntitiesDescriptor aggregate (a federation feed) can carry hundreds of IdPs, and silently
        // picking the first would bind the tenant to an arbitrary one. Refuse and make them narrow it.
        if ($descriptors->length > 1) {
            throw new SsoMetadataException(
                'The metadata describes more than one identity provider. Paste the metadata for the single '
                .'provider you want to connect.'
            );
        }

        $descriptor = $descriptors->item(0);

        if (! $descriptor instanceof DOMElement) {
            throw new SsoMetadataException('The IDPSSODescriptor could not be read.');
        }

        $protocols = $descriptor->getAttribute('protocolSupportEnumeration');

        if (! in_array(self::PROTOCOL_SAML2, preg_split('/\s+/', trim($protocols)) ?: [], true)) {
            throw new SsoMetadataException('The identity provider does not advertise support for SAML 2.0.');
        }

        return $descriptor;
    }

    /** @throws SsoMetadataException */
    private function entityIdFor(DOMElement $descriptor): string
    {
        $entity = $descriptor->parentNode;

        $entityId = $entity instanceof DOMElement ? trim($entity->getAttribute('entityID')) : '';

        if ($entityId === '') {
            throw new SsoMetadataException('The metadata has no entityID.');
        }

        return $entityId;
    }

    /** @throws SsoMetadataException */
    private function singleSignOnUrl(DOMXPath $xpath, DOMElement $descriptor): string
    {
        $services = $xpath->query(
            'md:SingleSignOnService[@Binding="'.self::BINDING_REDIRECT.'"]',
            $descriptor
        );

        if ($services === false || $services->length === 0) {
            throw new SsoMetadataException(
                'The identity provider publishes no HTTP-Redirect SingleSignOnService endpoint, which is the '
                .'binding this application uses to send authentication requests.'
            );
        }

        $service = $services->item(0);
        $location = $service instanceof DOMElement ? trim($service->getAttribute('Location')) : '';

        if ($location === '' || filter_var($location, FILTER_VALIDATE_URL) === false) {
            throw new SsoMetadataException('The SingleSignOnService Location is not a valid URL.');
        }

        // Plaintext http would put the AuthnRequest — and the user's browser — on the wire unprotected.
        // Permitted only in local development, where there is no certificate authority to satisfy.
        if (! str_starts_with($location, 'https://') && app()->environment() !== 'local') {
            throw new SsoMetadataException('The SingleSignOnService Location must use https.');
        }

        return $location;
    }

    /**
     * @return list<string>
     *
     * @throws SsoMetadataException
     */
    private function signingCertificates(DOMXPath $xpath, DOMElement $descriptor): array
    {
        // `use` is optional in the schema; an omitted `use` means the key serves every purpose, so a
        // KeyDescriptor with no `use` IS a signing key and excluding it breaks real IdPs.
        $nodes = $xpath->query(
            'md:KeyDescriptor[not(@use) or @use="signing"]//ds:X509Certificate',
            $descriptor
        );

        if ($nodes === false || $nodes->length === 0) {
            throw new SsoMetadataException('The metadata contains no signing certificate.');
        }

        $certificates = [];

        foreach ($nodes as $node) {
            // `DOMNodeList` is typed as `DOMNameSpaceNode|DOMNode`, and only `DOMElement` carries the text
            // we want — narrowing here is both what satisfies static analysis and what this query means.
            if (! $node instanceof DOMElement) {
                continue;
            }

            // Metadata is routinely pretty-printed, so the base64 arrives wrapped across lines.
            $raw = preg_replace('/\s+/', '', $node->textContent) ?? '';

            if ($raw === '') {
                continue;
            }

            $this->assertParsable($raw);
            $certificates[] = $raw;
        }

        if ($certificates === []) {
            throw new SsoMetadataException('The metadata contains no usable signing certificate.');
        }

        return array_values(array_unique($certificates));
    }

    /**
     * Rejects anything OpenSSL cannot read as a certificate.
     *
     * ⚠️ EXPIRY IS DELIBERATELY NOT CHECKED HERE. An identity provider legitimately publishes a
     * not-yet-active successor alongside a current key during a rollover, and refusing the document would
     * make a correctly-executed rotation fail at the SP. Expiry is surfaced in the settings UI instead, where
     * a human can act on it.
     *
     * @throws SsoMetadataException
     */
    private function assertParsable(string $base64Der): void
    {
        $pem = "-----BEGIN CERTIFICATE-----\n"
            .chunk_split($base64Der, 64, "\n")
            ."-----END CERTIFICATE-----\n";

        $previous = libxml_use_internal_errors(true);

        try {
            if (@openssl_x509_read($pem) === false) {
                throw new SsoMetadataException('A signing certificate in the metadata could not be parsed.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function nameIdFormat(DOMXPath $xpath, DOMElement $descriptor): ?string
    {
        $nodes = $xpath->query('md:NameIDFormat', $descriptor);

        if ($nodes === false) {
            return null;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $format = trim($node->textContent);

            // Prefer the email format when the IdP advertises it, because it is the identity this
            // application actually resolves on. Anything else is stored but will not match a user.
            if ($format === config('saml.default_name_id_format')) {
                return $format;
            }
        }

        $first = $nodes->item(0);

        return $first instanceof DOMElement ? (trim($first->textContent) ?: null) : null;
    }
}
