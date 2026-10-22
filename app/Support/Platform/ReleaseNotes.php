<?php

declare(strict_types=1);

namespace App\Support\Platform;

use InvalidArgumentException;
use JsonException;

/**
 * The What's new notes as committed — M181 (`R-c7247dbf`, `D124`, `D125` C).
 *
 * Each change a person can see brings its notes in one repository file, newest first, and bumps `VERSION` in the
 * same pull request (`ReleaseNotesFileTest` holds the two equal). Every deploy copies the file into the `releases`
 * table (`releases:sync`), which is what the prompt and the What's new page read — the database is the release history.
 *
 * The file lives in `database/data/`, never under `docs/`: a push that changes only `docs/` takes `deploy.ps1`'s
 * no-window path, which runs no artisan step, so notes there would never reach the table.
 *
 * `load()` refuses the WHOLE file on the first thing wrong with it — a release is never half-copied — and each refusal
 * names the release and the field, because the person reading it is fixing a JSON file by hand.
 */
final class ReleaseNotes
{
    /** What an item says about the release, in the order the page explains them. */
    public const KINDS = ['new', 'improved', 'fixed'];

    private const RELEASE_KEYS = ['version', 'title', 'items'];

    private const ITEM_KEYS = ['kind', 'text', 'href'];

    public function __construct(private readonly ?string $path = null) {}

    public function path(): string
    {
        return $this->path ?? database_path('data/releases.json');
    }

    /**
     * Every release in the file, newest first, each checked.
     *
     * @return list<array{version: string, title: string, items: list<array{kind: string, text: string, href?: string}>}>
     *
     * @throws InvalidArgumentException naming the first thing wrong with the file
     */
    public function load(): array
    {
        $path = $this->path();
        $raw = is_file($path) ? file_get_contents($path) : false;

        if ($raw === false) {
            throw new InvalidArgumentException("The release notes file '{$path}' cannot be read.");
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("The release notes file is not valid JSON: {$e->getMessage()}.");
        }

        if (! is_array($decoded) || ! array_is_list($decoded) || $decoded === []) {
            throw new InvalidArgumentException('The release notes file must be a list of releases, newest first.');
        }

        $releases = [];
        $previous = null;

        foreach ($decoded as $i => $release) {
            $release = $this->release($release, $i);

            if ($previous !== null && ! version_compare($previous, $release['version'], '>')) {
                throw new InvalidArgumentException(sprintf(
                    'Release %s is listed below %s: the list runs newest first, each version once.',
                    $release['version'],
                    $previous,
                ));
            }

            $previous = $release['version'];
            $releases[] = $release;
        }

        return $releases;
    }

    /**
     * @return array{version: string, title: string, items: list<array{kind: string, text: string, href?: string}>}
     */
    private function release(mixed $release, int $index): array
    {
        $where = 'Release #'.($index + 1);

        if (! is_array($release) || array_is_list($release)) {
            throw new InvalidArgumentException("{$where} must be an object with a version, a title and items.");
        }

        $this->onlyKeys($release, self::RELEASE_KEYS, $where);

        $version = $release['version'] ?? null;
        if (! is_string($version) || preg_match(BuildInfo::SEMVER, $version) !== 1) {
            throw new InvalidArgumentException("{$where}: the version must be three numbers, like 0.9.0.");
        }

        $where = "Release {$version}";
        $title = $release['title'] ?? null;
        if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 255) {
            throw new InvalidArgumentException("{$where}: the title must be words, at most 255 characters.");
        }

        $items = $release['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items) || $items === []) {
            throw new InvalidArgumentException("{$where}: items must be a list of at least one change.");
        }

        return [
            'version' => $version,
            'title' => $title,
            'items' => array_map(fn (mixed $item, int $i): array => $this->item($item, "{$where}, item #".($i + 1)), $items, array_keys($items)),
        ];
    }

    /**
     * @return array{kind: string, text: string, href?: string}
     */
    private function item(mixed $item, string $where): array
    {
        if (! is_array($item) || array_is_list($item)) {
            throw new InvalidArgumentException("{$where} must be an object with a kind and a text.");
        }

        $this->onlyKeys($item, self::ITEM_KEYS, $where);

        $kind = $item['kind'] ?? null;
        if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("{$where}: the kind must be one of ".implode(', ', self::KINDS).'.');
        }

        $text = $item['text'] ?? null;
        if (! is_string($text) || trim($text) === '') {
            throw new InvalidArgumentException("{$where}: the text must say what changed.");
        }

        $checked = ['kind' => $kind, 'text' => $text];

        if (array_key_exists('href', $item)) {
            $href = $item['href'];
            // A page of this application — a path from the site's root — never an address elsewhere.
            if (! is_string($href) || ! str_starts_with($href, '/') || str_starts_with($href, '//')) {
                throw new InvalidArgumentException("{$where}: a link must be a page of this site, starting with one /.");
            }
            $checked['href'] = $href;
        }

        return $checked;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $allowed
     */
    private function onlyKeys(array $value, array $allowed, string $where): void
    {
        $unknown = array_diff(array_map('strval', array_keys($value)), $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s has %s, which the notes do not use (they take %s).',
                $where,
                implode(', ', $unknown),
                implode(', ', $allowed),
            ));
        }
    }
}
