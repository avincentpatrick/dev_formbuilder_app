<?php

declare(strict_types=1);

use App\Enums\DomainEventType;

/*
 | Increment E — a cheap in-suite guard on the committed OpenAPI contract (openapi.json). The CI
 | `contract-tests` job does the full Redocly validation + drift-diff against a fresh Scramble export;
 | this asserts the essentials inside the normal test run so a corrupted/forgotten spec fails fast. No DB.
 */

it('ships a valid OpenAPI 3.1 contract covering the /api/v1 surface', function (): void {
    $path = base_path('openapi.json');
    expect(file_exists($path))->toBeTrue();

    /** @var array<string, mixed> $spec */
    $spec = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($spec['openapi'])->toBe('3.1.0')
        ->and($spec['info']['title'])->toBe('Form-Builder SaaS API')
        ->and($spec['components']['securitySchemes'])->toHaveKey('sanctumToken')
        ->and($spec['security'])->toBe([['sanctumToken' => []]]);

    expect(array_keys($spec['paths']))->toContain(
        '/forms',
        '/forms/{form}',
        '/forms/{form}/versions',
        '/forms/{form}/versions/{version}',
        '/forms/{form}/versions/{version}/publish',
        '/auth/tokens',
        '/auth/tokens/{id}',
        '/tenant',
        // Increment F5 — the public guest runtime surface.
        '/public/f/{shareToken}',
        '/public/f/{shareToken}/submissions',
        // Increment H9b — the save-and-resume surface (guest draft upsert + resume-read + encoder promote).
        '/public/f/{shareToken}/draft',
        '/public/drafts/{resumeToken}',
        '/submissions/{submission}/promote',
        // Increment G8b — the authenticated offline-sync surface.
        '/sync/manifest',
        '/sync/submissions',
        // Increment G9a/G9b — the reusable-content surfaces (templates + question library).
        '/form-templates',
        '/field-library',
        // Increment G10b — the scoping hierarchy + the grant write surface.
        '/scopes',
        '/scopes/{scopeNode}',
        '/scopes/{scopeNode}/move',
        '/scopes/{scopeNode}/impact',
        '/resource-grants',
        '/resource-grants/{resourceGrant}',
        // H4 — the read-only audit-log surface.
        '/audits',
        // H13a — the webhook engine management + delivery-log surface.
        '/webhooks',
        '/webhooks/{webhookEndpoint}',
        '/webhooks/{webhookEndpoint}/deliveries',
        // H13b — send-test, dual-secret rotation, and manual redeliver.
        '/webhooks/{webhookEndpoint}/test',
        '/webhooks/{webhookEndpoint}/rotate-secret',
        '/webhooks/{webhookEndpoint}/deliveries/{webhookDelivery}/redeliver',
        // H15a — native connectors: the OAuth grants (read + disconnect only; a grant can be created ONLY
        // by the interactive flow) and the delivery rules on them, with the shared delivery log.
        '/connections',
        '/connections/{connection}',
        '/connections/{connection}/subscriptions',
        '/connections/{connection}/subscriptions/{subscription}',
        '/connections/{connection}/subscriptions/{subscription}/deliveries',
        // H22a — custom domains: claim, list, on-demand verify, release. H22b adds /primary.
        '/domains',
        '/domains/{domain}',
        '/domains/{domain}/verify',
        '/domains/{domain}/primary',
    );

    // The connector surface deliberately exposes no create/update for a grant: a credential may only arrive
    // through the OAuth flow, and an API that accepted one would be a path to writing a token we then act
    // with. Assert the ABSENCE, since a future resource-route reflex would silently add both.
    expect($spec['paths']['/connections'])->not->toHaveKey('post')
        ->and($spec['paths']['/connections/{connection}'])->not->toHaveKey('patch')
        ->and($spec['paths']['/connections/{connection}'])->not->toHaveKey('put');

    // H22a — the same shape of deliberate absence, twice over, both worth asserting because a resource-route
    // reflex would add them without anyone noticing what they mean:
    //
    //  · A DOMAIN IS IMMUTABLE. Editing the hostname would silently invalidate the verification that was
    //    granted for the old one, so there is no patch/put — you release and re-claim.
    //  · THERE IS NO ACTIVATE ENDPOINT ANYWHERE ON THE API. Putting a verified domain into service is
    //    `php artisan domains:activate`, run by whoever installed the TLS certificate. Per-domain TLS is
    //    structurally Track B and Track B is deferred, so a tenant able to activate its own domain could
    //    put its respondents on an origin with no certificate for it (ADR-0012).
    //
    // H22b's `/domains/{domain}/primary` is NOT that endpoint arriving under another name, and the reason
    // it is safe to add beside this assertion is mechanical rather than editorial: it refuses any domain
    // that is not ALREADY live, and `domains_primary_requires_live_chk` refuses it again underneath. It
    // chooses among hosts an operator activated; it cannot produce one.
    expect($spec['paths']['/domains/{domain}'])->not->toHaveKey('patch')
        ->and($spec['paths']['/domains/{domain}'])->not->toHaveKey('put')
        ->and(array_keys($spec['paths']))->not->toContain('/domains/{domain}/activate');

    // The guest endpoints are unauthenticated (@unauthenticated → security: []), overriding the global
    // sanctumToken requirement — a Sanctum bearer must never be advertised on the public share-token routes.
    expect($spec['paths']['/public/f/{shareToken}']['get']['security'])->toBe([])
        ->and($spec['paths']['/public/f/{shareToken}/submissions']['post']['security'])->toBe([])
        // H9b: the guest draft-save + resume-read are equally unauthenticated (token, not bearer).
        ->and($spec['paths']['/public/f/{shareToken}/draft']['post']['security'])->toBe([])
        ->and($spec['paths']['/public/drafts/{resumeToken}']['get']['security'])->toBe([]);
});

it('keeps the published event_types enum in step with DomainEventType', function (): void {
    // ⚠️ WRITTEN AFTER I9c SHIPPED THE SPEC STALE. The increment added `submission.updated` to the enum, the
    // CHECK constraint, the labels and the connector arms — and did not regenerate `openapi.json`, so four
    // `event_types` enums still advertised seven values while eight FormRequests accepted eight. A generated
    // client would have rejected a value the API takes. The `contract-tests` CI job compares a fresh export
    // byte-for-byte and would have caught it there; this catches it in the ordinary suite, which is where
    // the person who added the case is actually looking.
    /** @var array<string, mixed> $spec */
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    $expected = DomainEventType::values();
    sort($expected);

    // Locate every enum that carries the catalog and assert it carries ALL of it.
    $enums = [];
    $walk = static function (array $node) use (&$walk, &$enums): void {
        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            if ($key === 'enum' && in_array('submission.created', $value, true)) {
                $enums[] = $value;
            }
            $walk($value);
        }
    };
    $walk($spec);

    expect($enums)->not->toBeEmpty('no event_types enum found in openapi.json');

    foreach ($enums as $enum) {
        $actual = $enum;
        sort($actual);
        expect($actual)->toBe($expected);
    }
});
