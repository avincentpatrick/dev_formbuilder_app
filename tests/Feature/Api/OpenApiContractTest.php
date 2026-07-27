<?php

declare(strict_types=1);

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
    );

    // The connector surface deliberately exposes no create/update for a grant: a credential may only arrive
    // through the OAuth flow, and an API that accepted one would be a path to writing a token we then act
    // with. Assert the ABSENCE, since a future resource-route reflex would silently add both.
    expect($spec['paths']['/connections'])->not->toHaveKey('post')
        ->and($spec['paths']['/connections/{connection}'])->not->toHaveKey('patch')
        ->and($spec['paths']['/connections/{connection}'])->not->toHaveKey('put');

    // The guest endpoints are unauthenticated (@unauthenticated → security: []), overriding the global
    // sanctumToken requirement — a Sanctum bearer must never be advertised on the public share-token routes.
    expect($spec['paths']['/public/f/{shareToken}']['get']['security'])->toBe([])
        ->and($spec['paths']['/public/f/{shareToken}/submissions']['post']['security'])->toBe([])
        // H9b: the guest draft-save + resume-read are equally unauthenticated (token, not bearer).
        ->and($spec['paths']['/public/f/{shareToken}/draft']['post']['security'])->toBe([])
        ->and($spec['paths']['/public/drafts/{resumeToken}']['get']['security'])->toBe([]);
});
