<?php

declare(strict_types=1);

use App\Support\Platform\BuildInfo;
use App\Support\Platform\ReleaseNotes;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| M181 (`R-c7247dbf`, `D124`, `D125` C) — the What's new notes, as committed
|--------------------------------------------------------------------------
| Each change brings its notes in one repository file (`database/data/releases.json`, newest first) and every deploy
| copies them into the `releases` table (`releases:sync`), which is what the prompt and the page read. The release rule:
| a change a person can see bumps `VERSION` and adds its entry in the same pull request — so the newest entry IS the
| committed version, and a pull request that bumps one without the other is refused here.
*/

it('reads the committed notes file without a complaint', function (): void {
    expect((new ReleaseNotes)->load())->not->toBeEmpty();
});

it('opens with the release the VERSION file names', function (): void {
    $newest = (new ReleaseNotes)->load()[0]['version'];

    expect($newest)->toBe((new BuildInfo)->fileVersion(), sprintf(
        'VERSION names %s but the newest What\'s new entry is %s: bump VERSION and add its notes in the same pull request.',
        (string) (new BuildInfo)->fileVersion(),
        $newest,
    ));
});

it('lists the releases newest first, each version once', function (): void {
    $versions = array_column((new ReleaseNotes)->load(), 'version');

    expect($versions)->toBe(array_values(array_unique($versions)));

    foreach (array_slice($versions, 1) as $i => $older) {
        expect(version_compare($versions[$i], $older, '>'))->toBeTrue("{$versions[$i]} is listed above {$older}");
    }
});

it('gives every item a known kind and words to read', function (): void {
    foreach ((new ReleaseNotes)->load() as $release) {
        expect($release['title'])->not->toBe('')
            ->and($release['items'])->not->toBeEmpty();

        foreach ($release['items'] as $item) {
            expect($item['kind'])->toBeIn(ReleaseNotes::KINDS)
                ->and(trim($item['text']))->not->toBe('');
        }
    }
});

it('links an item only to a page that exists', function (): void {
    $pages = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => in_array('GET', $route->methods(), true))
        ->map(fn (RoutingRoute $route): string => '/'.ltrim($route->uri(), '/'))
        ->all();

    expect($pages)->toContain('/help');

    foreach ((new ReleaseNotes)->load() as $release) {
        foreach ($release['items'] as $item) {
            if (! isset($item['href'])) {
                continue;
            }

            $path = (string) strtok($item['href'], '#');

            expect($pages)->toContain($path, "{$release['version']} links to {$item['href']}, which no GET route serves.");
        }
    }
});
