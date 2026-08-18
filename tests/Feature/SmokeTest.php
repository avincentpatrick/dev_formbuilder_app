<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('serves the public welcome page through Inertia', function (): void {
    // The `phase` prop this used to assert was the walking skeleton's ("Phase 0 — Foundations"); I6 replaced
    // the stub with a real landing page, so the props are now `appName`, `registrationOpen` and
    // `centralHost`. Host-by-host behaviour lives in `PlatformLandingTest`; this stays a bare route smoke.
    $this->withoutVite()
        ->get('/')
        ->assertOk()
        // Second arg `false` skips Inertia's page-file existence check: a route smoke test
        // shouldn't depend on built assets (the tests job doesn't run `npm build`).
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome', false)
            ->has('appName')
            ->has('registrationOpen')
            ->has('centralHost'));
});
