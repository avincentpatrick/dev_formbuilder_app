<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('serves the public welcome page through Inertia', function (): void {
    $this->withoutVite()
        ->get('/')
        ->assertOk()
        // Second arg `false` skips Inertia's page-file existence check: a route smoke test
        // shouldn't depend on built assets (the tests job doesn't run `npm build`).
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome', false)
            ->has('appName')
            ->has('phase'));
});
