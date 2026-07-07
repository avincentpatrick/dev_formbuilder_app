<?php

/*
 * Scramble (OpenAPI 3.1 generation) — Increment E. Scramble is a DEV dependency, so this always-loaded
 * config file must reference NO Scramble classes (it would fatal on a production `--no-dev` install where
 * the package is absent). The security scheme + any document transformers are registered in
 * AppServiceProvider::boot() behind a class_exists(Scramble::class) guard. Missing top-level keys fall
 * back to the package defaults via mergeConfigFrom.
 */

return [
    // Document only the versioned API surface (routes/api.php). A single static include ⇒ Scramble emits
    // the server as /api/v1 and lists paths relative to it (/forms, /tenant, …).
    'api_path' => 'api/v1',

    // Default target for `scramble:export`; the CI contract job passes --path explicitly.
    'export_path' => 'openapi.json',

    'info' => [
        'version' => '1.0',
        'title' => 'Form-Builder SaaS API',
        'description' => 'The versioned REST API for the Meridian form-builder platform. Served per tenant on '
            .'the tenant subdomain (https://{tenant}.meridian.test/api/v1) and authenticated with Sanctum '
            .'personal-access-token API keys. See docs/api-specification.md.',
    ],

    'security_strategy' => null,
];
