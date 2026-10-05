<?php

declare(strict_types=1);

namespace App\Services\Ocr\Bakeoff;

use finfo;

/**
 * Finds the scans in a bake-off folder (M136), under the same rules an upload is held to (`config/ocr.php`'s `upload`).
 *
 * A file at the top of the folder is one scan; a subfolder is one scan of several pages, read in name order — the two
 * shapes the scans page takes (one to five photos, or one PDF). The type is CONTENT-SNIFFED, as the upload's is. A file
 * the app would refuse is listed with the reason rather than read, because a sample the app refuses is itself a finding.
 *
 * The harness's own files are passed over: names starting with `.` or `_` (the report goes in `_bakeoff`), the cached
 * provider answers beside each scan, and the layout and answer files when they sit in the folder.
 */
final class OcrBakeoffFolder
{
    /** A page's cached provider answer sits beside it under this suffix. The provider is in the name. */
    public const string CACHE_SUFFIX = '.google_vision.json';

    private const array QUIET = ['desktop.ini', 'thumbs.db'];

    /**
     * @param  list<string>  $ignore  paths of the harness's own inputs that may sit in the folder
     * @return array{scans: list<array{name: string, files: list<array{path: string, mime: string}>}>, skipped: list<array{path: string, reason: string}>}
     */
    public static function discover(string $folder, array $ignore = []): array
    {
        $ignored = array_map(static fn (string $p): string => self::same($p), $ignore);
        $sniffer = new finfo(FILEINFO_MIME_TYPE);
        $scans = [];
        $skipped = [];

        foreach (self::entries($folder) as $entry) {
            $path = $folder.DIRECTORY_SEPARATOR.$entry;
            if (self::passOver($entry, $path, $ignored)) {
                continue;
            }

            if (is_dir($path)) {
                $scan = self::pagesIn($path, $entry, $sniffer, $ignored);
                if (is_string($scan)) {
                    $skipped[] = ['path' => $entry, 'reason' => $scan];
                } else {
                    $scans[] = $scan;
                }

                continue;
            }

            $refusal = self::refusal($path, $sniffer);
            if ($refusal !== null) {
                $skipped[] = ['path' => $entry, 'reason' => $refusal];

                continue;
            }

            $scans[] = ['name' => $entry, 'files' => [['path' => $path, 'mime' => self::mime($path, $sniffer)]]];
        }

        return ['scans' => $scans, 'skipped' => $skipped];
    }

    /**
     * One subfolder as one scan, or the reason it cannot be one.
     *
     * @param  list<string>  $ignored
     * @return array{name: string, files: list<array{path: string, mime: string}>}|string
     */
    private static function pagesIn(string $dir, string $name, finfo $sniffer, array $ignored): array|string
    {
        $files = [];
        $bytes = 0;
        foreach (self::entries($dir) as $entry) {
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (self::passOver($entry, $path, $ignored)) {
                continue;
            }
            if (is_dir($path)) {
                return "it holds a folder ({$entry}); a scan of several pages is one folder of images";
            }
            $refusal = self::refusal($path, $sniffer);
            if ($refusal !== null) {
                return "{$entry}: {$refusal}";
            }
            $files[] = ['path' => $path, 'mime' => self::mime($path, $sniffer)];
            $bytes += (int) filesize($path);
        }

        $pdfs = count(array_filter($files, static fn (array $f): bool => $f['mime'] === 'application/pdf'));
        $max = (int) config('ocr.upload.max_pages', 5);

        return match (true) {
            $files === [] => 'it holds no image or PDF',
            $pdfs > 0 && count($files) > 1 => 'a PDF is a scan on its own; the app does not take a PDF with other pages',
            count($files) > $max => count($files)." pages; the app takes at most {$max}",
            $bytes > (int) config('ocr.upload.max_bytes_per_scan') => 'larger than the app takes for one scan ('.number_format($bytes).' bytes)',
            default => ['name' => $name, 'files' => $files],
        };
    }

    /** Why the app would refuse this file as a page, or null when it would take it. */
    private static function refusal(string $path, finfo $sniffer): ?string
    {
        $mime = self::mime($path, $sniffer);
        $accepted = (array) config('ocr.upload.accepted_types', []);
        if (! in_array($mime, $accepted, true)) {
            return "{$mime} is not a type the app takes (JPEG, PNG, WebP or PDF)".(str_contains($mime, 'heic') || str_contains($mime, 'heif') ? '; save the photo as JPEG' : '');
        }

        $size = (int) filesize($path);
        $max = (int) config('ocr.upload.max_bytes_per_file');

        return $size > $max ? 'larger than the app takes for one page ('.number_format($size).' bytes, the limit is '.number_format($max).')' : null;
    }

    /** @param  list<string>  $ignored */
    private static function passOver(string $entry, string $path, array $ignored): bool
    {
        return str_starts_with($entry, '.')
            || str_starts_with($entry, '_')
            || str_ends_with(strtolower($entry), '.json')
            || in_array(strtolower($entry), self::QUIET, true)
            || in_array(self::same($path), $ignored, true);
    }

    /** @return list<string> */
    private static function entries(string $dir): array
    {
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        natcasesort($entries);

        return array_values($entries);
    }

    private static function mime(string $path, finfo $sniffer): string
    {
        $mime = $sniffer->file($path);

        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    /** A path in the form two spellings of the same file share. */
    private static function same(string $path): string
    {
        $real = realpath($path);

        return strtolower(str_replace('\\', '/', $real === false ? $path : $real));
    }
}
