<?php

declare(strict_types=1);

use App\Models\Release;
use App\Support\Platform\BuildInfo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M181 (`R-c7247dbf`, `D125` C) — `releases:sync` copies the notes into the table on every deploy
|--------------------------------------------------------------------------
| "So every release, we know what changed, what version it is" (the user, 2026-10-15). The table is the release
| history and what the app reads: the sync adds a new release, takes a corrected note, and NEVER deletes — a release
| dropped from the file stays — and the date a release went live is stamped by the sync that first copied it, never
| rewritten. A malformed file is refused whole, before anything is written: `deploy.ps1` runs this inside its window.
*/

/** A throwaway notes file holding `$contents` — an array is written as JSON, a string verbatim. */
function releaseNotesFile(array|string $contents): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'releases');
    file_put_contents($path, is_string($contents) ? $contents : json_encode($contents, JSON_THROW_ON_ERROR));

    return $path;
}

/** @return list<array<string, mixed>> */
function twoReleases(): array
{
    return [
        ['version' => '0.10.0', 'title' => 'Grids', 'items' => [['kind' => 'new', 'text' => 'A grid question.']]],
        ['version' => '0.9.0', 'title' => 'Versions', 'items' => [
            ['kind' => 'new', 'text' => 'A version number.'],
            ['kind' => 'fixed', 'text' => 'Help opens.', 'href' => '/help'],
        ]],
    ];
}

/** @return array<string, array{0: string, 1: string, 2: string}> version => [id, released_at, updated_at] */
function releaseSnapshot(): array
{
    return Release::query()->get()->mapWithKeys(fn (Release $release): array => [$release->version => [
        (string) $release->getKey(),
        $release->released_at->toIso8601String(),
        $release->updated_at?->toIso8601String() ?? '',
    ]])->all();
}

it('copies every release in the file into the table', function (): void {
    $this->artisan('releases:sync', ['--file' => releaseNotesFile(twoReleases())])->assertSuccessful();

    $release = Release::query()->where('version', '0.9.0')->firstOrFail();

    expect(Release::query()->pluck('version')->sort()->values()->all())->toBe(['0.10.0', '0.9.0'])
        ->and($release->title)->toBe('Versions')
        // toEqual, not toBe: `jsonb` keeps an object's keys in its own order, not the file's.
        ->and($release->items)->toEqual(twoReleases()[1]['items'])
        ->and($release->released_at)->not->toBeNull();
});

it('changes nothing when it runs again, the date each went live included', function (): void {
    $file = releaseNotesFile(twoReleases());
    $this->artisan('releases:sync', ['--file' => $file])->assertSuccessful();
    $before = releaseSnapshot();

    $this->travel(2)->days();
    $this->artisan('releases:sync', ['--file' => $file])
        ->expectsOutputToContain('0 added, 0 updated, 2 unchanged')
        ->assertSuccessful();

    expect(releaseSnapshot())->toBe($before);
});

it('takes a corrected note and keeps the date the release went live', function (): void {
    $this->artisan('releases:sync', ['--file' => releaseNotesFile(twoReleases())])->assertSuccessful();
    $wentLive = Release::query()->where('version', '0.9.0')->firstOrFail()->released_at->toIso8601String();

    $corrected = twoReleases();
    $corrected[1]['title'] = 'Version numbers';
    $this->travel(2)->days();
    $this->artisan('releases:sync', ['--file' => releaseNotesFile($corrected)])
        ->expectsOutputToContain('0 added, 1 updated, 1 unchanged')
        ->assertSuccessful();

    $release = Release::query()->where('version', '0.9.0')->firstOrFail();

    expect($release->title)->toBe('Version numbers')
        ->and($release->released_at->toIso8601String())->toBe($wentLive);
});

it('never deletes: a release dropped from the file stays in the table', function (): void {
    $this->artisan('releases:sync', ['--file' => releaseNotesFile(twoReleases())])->assertSuccessful();

    $this->artisan('releases:sync', ['--file' => releaseNotesFile([twoReleases()[0]])])->assertSuccessful();

    expect(Release::query()->count())->toBe(2)
        ->and(Release::query()->where('version', '0.9.0')->exists())->toBeTrue();
});

it('refuses a malformed file and writes nothing', function (array|string $contents): void {
    $this->artisan('releases:sync', ['--file' => releaseNotesFile($contents)])->assertFailed();

    expect(Release::query()->count())->toBe(0);
})->with([
    'not JSON' => ['{"version": '],
    'not a list' => [['version' => '0.9.0', 'title' => 'x', 'items' => [['kind' => 'new', 'text' => 'x']]]],
    'an empty list' => [[]],
    'a version that is not three numbers' => [[['version' => '0.9', 'title' => 'x', 'items' => [['kind' => 'new', 'text' => 'x']]]]],
    'oldest first' => [array_reverse(twoReleases())],
    'a version twice' => [[twoReleases()[1], twoReleases()[1]]],
    'no title' => [[['version' => '0.9.0', 'title' => ' ', 'items' => [['kind' => 'new', 'text' => 'x']]]]],
    'no items' => [[['version' => '0.9.0', 'title' => 'x', 'items' => []]]],
    'an unknown kind' => [[['version' => '0.9.0', 'title' => 'x', 'items' => [['kind' => 'removed', 'text' => 'x']]]]],
    'an item with no words' => [[['version' => '0.9.0', 'title' => 'x', 'items' => [['kind' => 'new', 'text' => '']]]]],
    'a link off the site' => [[['version' => '0.9.0', 'title' => 'x', 'items' => [['kind' => 'new', 'text' => 'x', 'href' => 'https://example.com']]]]],
    'a misspelt key' => [[['version' => '0.9.0', 'title' => 'x', 'items' => [['kind' => 'new', 'txt' => 'x']]]]],
]);

it('refuses a malformed file without touching what is already there', function (): void {
    $this->artisan('releases:sync', ['--file' => releaseNotesFile(twoReleases())])->assertSuccessful();

    $broken = twoReleases();
    $broken[1]['title'] = 'Changed';
    $broken[0]['items'][0]['kind'] = 'removed';
    $this->artisan('releases:sync', ['--file' => releaseNotesFile($broken)])->assertFailed();

    expect(Release::query()->where('version', '0.9.0')->value('title'))->toBe('Versions');
});

it('reads database/data/releases.json when given no file', function (): void {
    $this->artisan('releases:sync')->assertSuccessful();

    expect(Release::query()->pluck('version')->all())->toContain((new BuildInfo)->fileVersion());
});

it('holds the version to three numbers at the database too', function (): void {
    expect(fn () => DB::table('releases')->insert([
        'id' => (string) str()->uuid(),
        'version' => 'banana',
        'title' => 'x',
        'items' => '[]',
        'released_at' => now(),
    ]))->toThrow(QueryException::class, 'releases_version_check');
});
