<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| R-aa133bab (M121) — no GET route may answer under a service-worker cache prefix unless it is meant to.
|--------------------------------------------------------------------------
| The service worker (resources/public-runtime/sw.ts) caches by PATH PREFIX: `/build/`, `/api/v1/public/f/`,
| `/f/` and, since M130, `/api/v1/public/content-images/`. The resume READ — `api.v1.public.drafts.resume`, which answers with a respondent's saved
| answers — escapes that cache only because its path begins `drafts/` rather than `f/`. `D20` was answered to
| keep it out, and until now the only thing keeping it out was a comment beside the route.
|
| ⛔ THE VITEST ARM IN `__tests__/sw.test.ts` (M78) CANNOT SEE THIS HALF, AND THAT IS WHY THIS FILE EXISTS.
| It runs the real matchers against a HARD-CODED URL, so it catches sw.ts widening its prefix and is blind
| to the hazard the route comment actually names: the ROUTE moving under `f/`, or the two public route
| groups being merged. A renamer would update that literal and stay green. This test reads the prefixes out
| of sw.ts and the routes out of the router, so either side moving reddens it.
|
| ⚠️ WHAT IT DOES NOT MODEL, said so rather than discovered: matcher predicates other than `startsWith`
| (the count floor below refuses to pass over one), the `navigate`/`GET` qualifiers on two of the routes
| (treated conservatively — every GET under the prefix counts), and `precacheAndRoute`'s build manifest.
|
| ⚠️ IT IS GREEN ON ARRIVAL BY CONSTRUCTION — the exposure is closed today. Its proof is the mutations
| recorded in the M121 release (the route moved under `f/`, a parameter-first URI, the sw.ts prefix widened,
| a fourth cache route, a stale allow-list entry, the route name changed), each performed and restored.
*/

/**
 * The `startsWith` literals of sw.ts's runtime-cache routes, without their leading slash.
 *
 * @return list<string>
 */
function swCachePrefixLiterals(): array
{
    $source = (string) file_get_contents(base_path('resources/public-runtime/sw.ts'));
    preg_match_all('#url\.pathname\.startsWith\(\'(/[^\']+)\'\)#', $source, $matches);

    return array_map(static fn (string $prefix): string => ltrim($prefix, '/'), $matches[1]);
}

/**
 * The GET routes each cached prefix is ALLOWED to answer, with the reason. Exact — an entry nothing answers
 * is as wrong as an answer nobody listed.
 *
 * @return array<string, list<string>>
 */
function swCachedGetAllowList(): array
{
    return [
        // Hashed Vite assets. No Laravel route may answer here at all.
        'build/' => [],
        // The pinned schema this cache exists for (sw.ts, the `guest-schema` cache).
        'api/v1/public/f/' => ['api.v1.public.forms.schema'],
        // The shell. `guest.form.resume` IS the credential-bearing shell D20 = 2 decided to keep caching
        // (R-68656155 owns that); the manifest is not a navigation, and is listed because this gate counts
        // every GET under the prefix rather than modelling the navigate-only predicate.
        'f/' => ['guest.form.manifest', 'guest.form.mint', 'guest.form.resume'],
        // A note's images (M130, `R-c9f50df2`): their own cache, `guest-content-images`, OUTSIDE the schema's prefix
        // so an image can neither evict a cached schema nor be mistaken for one.
        'api/v1/public/content-images/' => ['api.v1.public.content-images.show'],
    ];
}

/**
 * Every GET route a request under $prefix could reach: its URI starts with the prefix, or its static head
 * — the part before the first `{` — is a prefix OF the prefix, so a parameter-first URI is caught too.
 *
 * @return list<string>
 */
function swGetRouteNamesUnder(string $prefix): array
{
    $names = [];

    /** @var RoutingRoute $route */
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = $route->uri();
        $brace = strpos($uri, '{');
        $head = $brace === false ? null : substr($uri, 0, $brace);

        if (str_starts_with($uri, $prefix) || ($head !== null && str_starts_with($prefix, $head))) {
            $names[] = $route->getName() ?? '(unnamed) '.$uri;
        }
    }

    sort($names);

    return $names;
}

it('parses one startsWith prefix per runtime-cache route out of sw.ts, and every one has an allow-list', function (): void {
    $source = (string) file_get_contents(base_path('resources/public-runtime/sw.ts'));
    $prefixes = swCachePrefixLiterals();
    $expected = array_keys(swCachedGetAllowList());

    sort($prefixes);
    sort($expected);

    // The floor: a cache route whose matcher is not a `startsWith` literal would otherwise pass unseen.
    expect($prefixes)->toHaveCount(substr_count($source, 'registerRoute('))
        ->and($prefixes)->toHaveCount(4)
        ->and($prefixes)->toBe($expected);
});

it('finds the resume READ by name, and it answers under no cached prefix', function (): void {
    $resume = Route::getRoutes()->getByName('api.v1.public.drafts.resume');

    // The anti-vacuity floor: renaming the route must fail here, not pass over nothing below.
    expect($resume)->not->toBeNull()
        ->and($resume?->methods())->toContain('GET');

    // ⛔ NOT `->not->toContain($name, $message)`: toContain() is VARIADIC, so the message becomes a second
    // needle, the pair is never contained, and the negation passes over anything. That form shipped here
    // first and was VACUOUS — the M2a mutation (the route moved under `f/`) left this case green.
    foreach (swCachePrefixLiterals() as $prefix) {
        expect(in_array('api.v1.public.drafts.resume', swGetRouteNamesUnder($prefix), true))
            ->toBeFalse("the resume READ answers under the cached prefix {$prefix}");
    }
});

it('holds the GET routes under each cached prefix to exactly the allow-listed ones', function (): void {
    $allow = swCachedGetAllowList();

    foreach (swCachePrefixLiterals() as $prefix) {
        expect(swGetRouteNamesUnder($prefix))->toBe($allow[$prefix] ?? [], "GET routes under {$prefix}");
    }
});
