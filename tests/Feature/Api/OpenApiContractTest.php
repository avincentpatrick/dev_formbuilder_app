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
    );

    // The guest endpoints are unauthenticated (@unauthenticated → security: []), overriding the global
    // sanctumToken requirement — a Sanctum bearer must never be advertised on the public share-token routes.
    expect($spec['paths']['/public/f/{shareToken}']['get']['security'])->toBe([])
        ->and($spec['paths']['/public/f/{shareToken}/submissions']['post']['security'])->toBe([]);
});
