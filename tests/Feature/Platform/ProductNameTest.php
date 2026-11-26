<?php

declare(strict_types=1);

use App\Services\Settings\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M252 (`R-54516e0c`, `D236`) — the product is iConnect Collection, and renaming it signs nobody out
|--------------------------------------------------------------------------
| `APP_NAME` used to name three identifiers besides the product: the session cookie, the cache prefix and the Redis
| prefix, each the stock `Str::slug(APP_NAME)` default. Renaming the product on the box would have renamed all three —
| every session signed out at once, BHW Connect's admin sign-in broken (its server forwards the cookie BY NAME,
| `meridian-session`), and every cache key orphaned, `queue:restart`'s signal included. So the three are literals now,
| and these cases load each config file under a DIFFERENT `APP_NAME` to prove it.
|
| The name a person reads has one source: `config('app.name')` and `config('app.short_name')`, shared on every page.
*/

/**
 * Evaluate a config file as it would load with `$env` set — and nothing else that would mask a default.
 *
 * @param  array<string, string|null>  $env  name => value, or null to unset
 * @return array<mixed>
 */
function productNameLoadConfigUnder(string $file, array $env): array
{
    $saved = [];
    foreach ($env as $name => $value) {
        $saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    try {
        return require config_path($file);
    } finally {
        foreach ($saved as $name => [$putenv, $env_, $server]) {
            $putenv === false ? putenv($name) : putenv("{$name}={$putenv}");
            if ($env_ === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env_;
            }
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }
    }
}

it('keeps the session cookie named meridian-session whatever APP_NAME says', function (): void {
    $session = productNameLoadConfigUnder('session.php', ['APP_NAME' => 'iConnect Collection', 'SESSION_COOKIE' => null]);

    expect($session['cookie'])->toBe('meridian-session');
});

it('keeps both cache prefixes whatever APP_NAME says', function (): void {
    $under = ['APP_NAME' => 'iConnect Collection', 'CACHE_PREFIX' => null, 'REDIS_PREFIX' => null];

    expect(productNameLoadConfigUnder('cache.php', $under)['prefix'])->toBe('meridian-cache-')
        ->and(productNameLoadConfigUnder('database.php', $under)['redis']['options']['prefix'])->toBe('meridian-database-');
});

it('still lets the environment name the cookie on purpose', function (): void {
    // The override stays: pinning the DEFAULT is the change, not taking the setting away.
    $session = productNameLoadConfigUnder('session.php', ['SESSION_COOKIE' => 'chosen-session']);

    expect($session['cookie'])->toBe('chosen-session');
});

it('names the product iConnect Collection, and iConnect where only a few letters fit, when the box says nothing', function (): void {
    $app = productNameLoadConfigUnder('app.php', ['APP_NAME' => null, 'APP_SHORT_NAME' => null, 'ANALYTICS_URL' => null]);

    expect($app['name'])->toBe('iConnect Collection')
        ->and($app['short_name'])->toBe('iConnect')
        // Today's address of BHW Connect on this origin, until the box gives iConnect Analytics its own (M253).
        ->and($app['analytics_url'])->toBe('/bhw-connect/');
});

it('shares the name, the short name and the link to iConnect Analytics with every page, a guest page included', function (): void {
    config([
        'app.name' => 'Renamed Product',
        'app.short_name' => 'Renamed',
        'app.analytics_url' => 'https://analytics.example.test/',
    ]);

    $this->withoutVite()
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome', false)
            ->where('app.name', 'Renamed Product')
            ->where('app.short_name', 'Renamed')
            ->where('app.analytics_url', 'https://analytics.example.test/'));
});

it('writes the name into the default maintenance message, not a literal', function (): void {
    config(['app.name' => 'Renamed Product']);

    expect(app(PlatformSettings::class)->maintenanceMessage())
        ->toStartWith('Renamed Product is temporarily unavailable');
});
