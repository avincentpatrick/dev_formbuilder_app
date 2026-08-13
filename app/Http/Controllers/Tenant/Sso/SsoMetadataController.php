<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Sso;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Sso\SsoGate;
use App\Support\Tenancy\TenantUrl;
use DOMDocument;
use DOMElement;
use Illuminate\Http\Response;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * Publishes this tenant's SAML Service Provider metadata (Phase 4, P1a — ADR-0016).
 *
 * A tenant admin gives this URL to their identity provider, which reads the SP's entity id and the location
 * it should post assertions to. Unauthenticated by necessity — an IdP's configuration screen fetches it with
 * no session — and 404 for any tenant that is not entitled and active, via {@see SsoGate}.
 *
 * ── THE SP ENTITY ID IS PER TENANT, AND THAT IS A SECURITY PROPERTY ──────────────────────────────────
 * Each tenant is a distinct SP registration in its IdP, identified by its own canonical subdomain. This makes
 * the `AudienceRestriction` check a cross-tenant isolation boundary by CONSTRUCTION: an assertion minted for
 * tenant A names A's entity id as its audience, so replaying it at tenant B's ACS fails on the audience check
 * before any replay ledger is consulted. A single shared platform entity id would have made that assertion
 * structurally valid everywhere and left cross-tenant isolation resting entirely on runtime bookkeeping.
 *
 * ── EVERY URL COMES FROM `TenantUrl::to()`, NEVER `route()` OR CONCATENATION ─────────────────────────
 * `TenantUrl::to()` is the APP arm, which never returns a custom domain. That matters twice over: ADR-0012
 * §D1 confines custom hosts to the public guest runtime, so an ACS advertised on one would 302 to the central
 * app or 404 under `RequirePlatformHost` — and because the ACS location published here is the same string the
 * assertion's `Destination` is later checked against, deriving the two differently would mean checking a
 * string against itself and calling it validation.
 *
 * The document is built with DOM, never string interpolation: this is XML that a security-critical peer will
 * parse, and a tenant-controlled value spliced into a template is an injection waiting to happen.
 */
final class SsoMetadataController extends Controller
{
    private const NS_MD = 'urn:oasis:names:tc:SAML:2.0:metadata';

    private const PROTOCOL_SAML2 = 'urn:oasis:names:tc:SAML:2.0:protocol';

    private const BINDING_POST = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST';

    public function __construct(private readonly SsoGate $gate) {}

    public function __invoke(): Response
    {
        $connection = $this->gate->activeConnectionOrAbort();
        $tenant = $this->tenant();

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $entity = $document->createElementNS(self::NS_MD, 'md:EntityDescriptor');
        $entity->setAttribute('entityID', self::entityId($tenant));
        $document->appendChild($entity);

        $sp = $document->createElementNS(self::NS_MD, 'md:SPSSODescriptor');
        $sp->setAttribute('protocolSupportEnumeration', self::PROTOCOL_SAML2);
        // We sign no AuthnRequests in P1 (the redirect binding carries no signature here) and we require the
        // assertion itself to be signed — advertising both honestly is what lets an IdP's admin configure the
        // matching side without guessing.
        $sp->setAttribute('AuthnRequestsSigned', 'false');
        $sp->setAttribute('WantAssertionsSigned', 'true');
        $entity->appendChild($sp);

        $nameIdFormat = $document->createElementNS(self::NS_MD, 'md:NameIDFormat');
        $nameIdFormat->appendChild($document->createTextNode($connection->name_id_format));
        $sp->appendChild($nameIdFormat);

        $sp->appendChild($this->assertionConsumerService($document, $tenant));

        // Deliberately NO <md:KeyDescriptor>. P1 neither signs AuthnRequests nor decrypts assertions, and
        // publishing a key we will not use is how an IdP gets configured to encrypt assertions this
        // application then cannot read — a failure that presents as "SSO silently stopped working".

        return response($document->saveXML() ?: '', 200, [
            'Content-Type' => 'application/samlmetadata+xml',
            // The trust relationship is configuration, not a page; caching it in a shared proxy across
            // tenants is exactly the mistake the per-tenant entity id exists to prevent.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** The SP entity id for a tenant — also the URL this document is served from. */
    public static function entityId(Tenant $tenant): string
    {
        return TenantUrl::to($tenant, '/sso/saml/metadata');
    }

    /**
     * The one place the ACS location is composed.
     *
     * Every consumer — this document, the `Destination` check, and the `Recipient` check — must call this,
     * or the checks compare independently-built strings that can drift apart.
     */
    public static function assertionConsumerServiceUrl(Tenant $tenant): string
    {
        return TenantUrl::to($tenant, '/sso/saml/acs');
    }

    private function assertionConsumerService(DOMDocument $document, Tenant $tenant): DOMElement
    {
        $acs = $document->createElementNS(self::NS_MD, 'md:AssertionConsumerService');
        $acs->setAttribute('Binding', self::BINDING_POST);
        $acs->setAttribute('Location', self::assertionConsumerServiceUrl($tenant));
        $acs->setAttribute('index', '0');
        $acs->setAttribute('isDefault', 'true');

        return $acs;
    }

    /** The subdomain-resolved current tenant — stancl binds it under the Tenant contract. */
    private function tenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = app(TenantContract::class);

        return $tenant;
    }
}
