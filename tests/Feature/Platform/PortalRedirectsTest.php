<?php

declare(strict_types=1);

use App\Support\Platform\PortalRedirects;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| M253 (`R-43038865`, `D236`) — the portal's redirects for links from before the switch
|--------------------------------------------------------------------------
| After the switch, `iconnect.com.de` is the portal, and a link from before it — a shared form link, a printed QR
| code, a bookmark, an old BHW Connect page — must still arrive. The block is generated from the route table, so these
| cases drive the generated patterns over real paths: every path this app served on a workspace host moves to
| `collection.`, `/bhw-connect` moves to the portal or `analytics.`, and what is the portal's own stays.
*/

/**
 * The block's rules as [pattern, target, flags], read from the generated text.
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function portalRedirectRules(): array
{
    $block = app(PortalRedirects::class)->apacheBlock('collection.iconnect.com.de', 'analytics.iconnect.com.de');
    preg_match_all('/^RewriteRule "([^"]+)" "([^"]+)" \[([^\]]+)\]$/m', $block, $m, PREG_SET_ORDER);

    return array_map(static fn (array $rule): array => [$rule[1], $rule[2], $rule[3]], $m);
}

/** Where the generated block sends `$path` (the first rule that matches, as Apache's `L` flag stops there), or null. */
function portalRedirectTarget(string $path): ?string
{
    foreach (portalRedirectRules() as [$pattern, $target]) {
        if (preg_match('#'.$pattern.'#', $path, $groups) === 1) {
            $target = str_replace('%{REQUEST_URI}', $path, $target);

            return preg_replace_callback('/\$(\d)/', static fn (array $g): string => $groups[(int) $g[1]] ?? '', $target);
        }
    }

    return null;
}

it('is scanning something', function (): void {
    // Anti-vacuity: an empty route table or a broken parse would make every "stays" case pass.
    expect(portalRedirectRules())->toHaveCount(5)
        ->and(app(PortalRedirects::class)->collectionPrefixes())->toContain('f', 'login', 'dashboard', 'forms', 'api/v1');
});

it('covers every first path segment this app serves on a workspace host', function (): void {
    $prefixes = app(PortalRedirects::class)->collectionPrefixes();

    $missing = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => $route->getDomain() === null)
        ->map(fn (RoutingRoute $route): string => explode('/', trim($route->uri(), '/'))[0])
        ->reject(fn (string $first): bool => in_array($first, [...PortalRedirects::STAYS, 'api'], true))
        ->reject(fn (string $first): bool => in_array($first, $prefixes, true))
        ->unique()
        ->values()
        ->all();

    expect($missing)->toBe([]);
});

it('moves a shared form link, a QR code\'s link and a bookmark to the same path on collection.', function (string $path): void {
    expect(portalRedirectTarget($path))->toBe('https://collection.iconnect.com.de'.$path);
})->with([
    'a form' => '/f/household-survey',
    'a resume link' => '/f/resume/abc123',
    'sign-in' => '/login',
    'the dashboard' => '/dashboard',
    'a form in the builder' => '/forms/9a1b',
    'the guest API' => '/api/v1/public/f/household-survey/schema',
    'whoami' => '/api/v1/auth/whoami',
    'the support API' => '/api/platform/v1/feedback',
    'a built asset' => '/build/assets/app.js',
    'two-factor' => '/two-factor-challenge',
    'under the old Explore' => '/explore/charts/5',
    'under the old Feedback' => '/feedback/mine',
]);

it('keeps the API\'s method across the move', function (): void {
    $api = collect(portalRedirectRules())->first(fn (array $rule): bool => str_contains($rule[0], 'api/v1'));

    expect($api[2])->toContain('R=308');
});

it('sends BHW Connect\'s home to the portal and its pages to analytics.', function (): void {
    expect(portalRedirectTarget('/bhw-connect'))->toBe('/')
        ->and(portalRedirectTarget('/bhw-connect/'))->toBe('/')
        ->and(portalRedirectTarget('/bhw-connect/ask-the-data'))->toBe('https://analytics.iconnect.com.de/ask-the-data')
        ->and(portalRedirectTarget('/bhw-connect/place/region/01'))->toBe('https://analytics.iconnect.com.de/place/region/01');
});

it('leaves the portal\'s own paths and the console alone', function (string $path): void {
    expect(portalRedirectTarget($path))->toBeNull();
})->with([
    'the portal' => '/',
    'the console' => '/admin/tenants',
    'Google sign-in' => '/auth/google/redirect',
    'the retiring worker' => '/sw.js',
    'the portal\'s robots' => '/robots.txt',
    'the portal\'s icon' => '/favicon.ico',
    'the portal\'s own Explore' => '/explore',
    'the portal\'s own Feedback' => '/feedback',
    'a BHW page' => '/ask-the-data',
    'BHW\'s assets' => '/_next/static/chunk.js',
    'BHW\'s API' => '/api/feedback',
]);

it('refuses a host that is not a bare host name', function (): void {
    $this->artisan('portal:redirects', ['--collection' => 'https://collection.iconnect.com.de/'])
        ->assertFailed();

    $this->artisan('portal:redirects')->assertSuccessful();
});
