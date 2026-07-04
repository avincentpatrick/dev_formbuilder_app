<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('serves the public welcome page through Inertia', function (): void {
    $this->withoutVite()
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->has('appName')
            ->has('phase'));
});
