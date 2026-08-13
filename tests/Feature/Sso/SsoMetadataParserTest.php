<?php

declare(strict_types=1);

use App\Services\Sso\SsoMetadataException;
use App\Services\Sso\SsoMetadataParser;

/*
|--------------------------------------------------------------------------
| SsoMetadataParser (Phase 4, P1a) — the IdP-metadata import path, asserted directly.
|
| This parser is an attack surface: the XML is pasted by a tenant admin from whatever their identity provider
| handed them, so it is untrusted input arriving through a semi-trusted actor. The negative cases below are
| the point of the file — the happy path is one test and the refusals are eight, which is the correct ratio
| for a security boundary.
|--------------------------------------------------------------------------
*/

/** A minimal but realistic IdP metadata document. */
function idpMetadata(array $overrides = []): string
{
    $entityId = $overrides['entityId'] ?? 'https://idp.example.com/saml2';
    $ssoUrl = $overrides['ssoUrl'] ?? 'https://idp.example.com/saml2/sso';
    $certificate = $overrides['certificate'] ?? testSigningCertificate();
    $protocols = $overrides['protocols'] ?? 'urn:oasis:names:tc:SAML:2.0:protocol';
    $keyUse = $overrides['keyUse'] ?? ' use="signing"';

    return <<<XML
    <?xml version="1.0"?>
    <md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="{$entityId}">
      <md:IDPSSODescriptor protocolSupportEnumeration="{$protocols}">
        <md:KeyDescriptor{$keyUse}>
          <ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
            <ds:X509Data><ds:X509Certificate>{$certificate}</ds:X509Certificate></ds:X509Data>
          </ds:KeyInfo>
        </md:KeyDescriptor>
        <md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>
        <md:SingleSignOnService
            Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"
            Location="{$ssoUrl}"/>
      </md:IDPSSODescriptor>
    </md:EntityDescriptor>
    XML;
}

/** A real, self-signed certificate generated once per process — openssl_x509_read() must accept it. */
function testSigningCertificate(): string
{
    static $certificate = null;

    if ($certificate !== null) {
        return $certificate;
    }

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'idp.example.com'], $key);
    $signed = openssl_csr_sign($csr, null, $key, 365);
    openssl_x509_export($signed, $pem);

    // Strip the PEM armour — metadata carries bare base64 DER.
    $certificate = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem) ?? '';

    return $certificate;
}

beforeEach(function (): void {
    $this->parser = new SsoMetadataParser;
});

it('parses a well-formed identity provider document', function (): void {
    $result = $this->parser->parse(idpMetadata());

    expect($result['idp_entity_id'])->toBe('https://idp.example.com/saml2')
        ->and($result['idp_sso_url'])->toBe('https://idp.example.com/saml2/sso')
        ->and($result['idp_certificates'])->toHaveCount(1)
        ->and($result['idp_certificates'][0])->toBe(testSigningCertificate())
        ->and($result['name_id_format'])->toBe('urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress')
        ->and($result['idp_metadata_sha256'])->toHaveLength(64)
        ->and($result['idp_certificates_fingerprint'])->toHaveLength(64);
});

it('accepts a KeyDescriptor with no use attribute, because an omitted use means every purpose', function (): void {
    // Real IdPs publish this shape, and excluding it would reject them for being schema-correct.
    $result = $this->parser->parse(idpMetadata(['keyUse' => '']));

    expect($result['idp_certificates'])->toHaveCount(1);
});

/*
| ── The refusals ────────────────────────────────────────────────────────────────────────────────────
*/

it('refuses a document declaring a DOCTYPE, which is the XXE and billion-laughs kill switch', function (): void {
    // The entity here is benign; the point is that the DOCTYPE never reaches the expander at all. If this
    // assertion ever flips to a parse of the expanded value, external entity resolution is live again.
    $xml = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe "bar">]>'
        .'<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="&xxe;"/>';

    expect(fn () => $this->parser->parse($xml))
        ->toThrow(SsoMetadataException::class, 'DOCTYPE');
});

it('refuses service-provider metadata with a message naming the confusion', function (): void {
    $xml = '<?xml version="1.0"?>'
        .'<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="https://sp.example">'
        .'<md:SPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol"/>'
        .'</md:EntityDescriptor>';

    expect(fn () => $this->parser->parse($xml))
        ->toThrow(SsoMetadataException::class, 'service-provider metadata');
});

it('refuses a federation aggregate rather than silently binding to the first provider', function (): void {
    $single = idpMetadata();
    $body = preg_replace('/^<\?xml[^>]*\?>/', '', $single) ?? '';
    $xml = '<?xml version="1.0"?><md:EntitiesDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata">'
        .$body.$body.'</md:EntitiesDescriptor>';

    expect(fn () => $this->parser->parse($xml))
        ->toThrow(SsoMetadataException::class, 'more than one identity provider');
});

it('refuses an identity provider that does not advertise SAML 2.0', function (): void {
    expect(fn () => $this->parser->parse(idpMetadata(['protocols' => 'urn:oasis:names:tc:SAML:1.1:protocol'])))
        ->toThrow(SsoMetadataException::class, 'SAML 2.0');
});

it('refuses metadata with no signing certificate, because there would be no trust anchor', function (): void {
    $xml = preg_replace('#<md:KeyDescriptor.*</md:KeyDescriptor>#s', '', idpMetadata()) ?? '';

    expect(fn () => $this->parser->parse($xml))
        ->toThrow(SsoMetadataException::class, 'no signing certificate');
});

it('refuses a certificate OpenSSL cannot parse', function (): void {
    expect(fn () => $this->parser->parse(idpMetadata(['certificate' => 'bm90LWEtY2VydGlmaWNhdGU='])))
        ->toThrow(SsoMetadataException::class, 'could not be parsed');
});

it('refuses an identity provider with no HTTP-Redirect SSO endpoint', function (): void {
    $xml = str_replace(
        'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
        'urn:oasis:names:tc:SAML:2.0:bindings:SOAP',
        idpMetadata()
    );

    expect(fn () => $this->parser->parse($xml))
        ->toThrow(SsoMetadataException::class, 'HTTP-Redirect');
});

it('refuses a plaintext http SSO endpoint outside local development', function (): void {
    app()['env'] = 'production';

    expect(fn () => $this->parser->parse(idpMetadata(['ssoUrl' => 'http://idp.example.com/sso'])))
        ->toThrow(SsoMetadataException::class, 'https');
});

it('refuses empty and malformed input', function (): void {
    expect(fn () => $this->parser->parse('   '))
        ->toThrow(SsoMetadataException::class, 'empty')
        ->and(fn () => $this->parser->parse('<md:EntityDescriptor'))
        ->toThrow(SsoMetadataException::class, 'well-formed');
});

/*
| ── The fingerprint ─────────────────────────────────────────────────────────────────────────────────
*/

it('fingerprints a certificate set independently of order', function (): void {
    // An IdP may reorder its KeyDescriptors between publications without changing which keys it signs with.
    // A fingerprint that moved on a reorder would report a rotation that did not happen — and an admin who
    // learns to ignore that alert will ignore the real one.
    expect(SsoMetadataParser::fingerprint(['alpha', 'beta']))
        ->toBe(SsoMetadataParser::fingerprint(['beta', 'alpha']));
});

it('changes the fingerprint when the certificate set actually changes', function (): void {
    expect(SsoMetadataParser::fingerprint(['alpha']))
        ->not->toBe(SsoMetadataParser::fingerprint(['alpha', 'beta']));
});
