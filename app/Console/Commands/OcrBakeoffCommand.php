<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Ocr\OcrProviderException;
use App\Services\Forms\BlankFormPrintPresenter;
use App\Services\Forms\CapabilityFlags;
use App\Services\Ocr\Bakeoff\OcrAnswerSheet;
use App\Services\Ocr\Bakeoff\OcrBakeoffAnswers;
use App\Services\Ocr\Bakeoff\OcrBakeoffFolder;
use App\Services\Ocr\Bakeoff\OcrBakeoffLayout;
use App\Services\Ocr\Bakeoff\OcrBakeoffQuestion;
use App\Services\Ocr\Bakeoff\OcrBakeoffReport;
use App\Services\Ocr\Bakeoff\OcrBakeoffScan;
use App\Services\Ocr\Bakeoff\OcrBakeoffScorer;
use App\Services\Ocr\GoogleVisionClient;
use App\Services\Ocr\PrintedFormMatcher;
use App\Services\Ocr\VisionDocumentParser;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * The OCR bake-off harness (M136, H1d's preparation): read a folder of scans with the real reader and score it against
 * a sheet of correct answers — the fields needing manual correction (PRD G9) at every confidence threshold.
 *
 * ── THE READER IS THE PRODUCT'S OWN, END TO END ────────────────────────────────────────────────────────────
 * Each page goes through {@see GoogleVisionClient::annotate()}, {@see VisionDocumentParser::pages()} and
 * {@see PrintedFormMatcher} — the version from the page's stamp, then the match — exactly as the reading job reads an
 * upload. Two things differ, both deliberate. The form comes from a layout FILE (`ocr:bakeoff-layout`) as unsaved
 * models, so no database is needed and the run can happen on whichever machine holds a key that reads. And the match
 * runs with both thresholds at zero, so no value is withheld; the scorer then applies every threshold it sweeps with the
 * matcher's own tier rule.
 *
 * ── A PAGE IS PAID FOR ONCE ────────────────────────────────────────────────────────────────────────────────
 * The provider's raw answer is cached beside each page (`<file>.google_vision.json`) and read back on every later run,
 * so re-scoring after a matcher change or a corrected answer sheet costs nothing; `--offline` forbids any call at all.
 * A refused credential (no key, billing off, a rejected key) stops further calls for the run — every page would be
 * refused the same way — and each scan says why it was not read. That is `OcrScanReader`'s stance: a provider failure is
 * the scan's state, never a crash.
 *
 * ⛔ THE EXIT CODE SAYS WHETHER THE FIGURE CAN BE TRUSTED. It is a failure when no scan was read, when an answer cell
 * could not be understood, when an answer row names no scan in the folder, or when answers were given and nothing was
 * scored — each of those means the G9 figure is measured over fewer fields than the sheet claims, or over none. The
 * report is written either way.
 */
final class OcrBakeoffCommand extends Command
{
    protected $signature = 'ocr:bakeoff
        {folder : The folder of scans — each image or PDF in it is one scan, each subfolder one scan of several pages}
        {--layout= : The layout file from ocr:bakeoff-layout (default: layout.json in the folder)}
        {--answers= : The sheet of correct answers, .xlsx or .csv (the template ocr:bakeoff-layout writes)}
        {--out= : Where to write report.md and fields.csv (default: _bakeoff in the folder)}
        {--offline : Never call the provider; read only the answers already cached beside the scans}';

    protected $description = 'Read a folder of scanned forms with the OCR reader and score it against the correct answers (PRD G9, per confidence threshold)';

    /** A refusal that every later page would get too. */
    private const array CREDENTIAL_CODES = ['provider_not_configured', 'provider_billing_disabled', 'provider_unauthorized'];

    private ?string $refusal = null;

    public function handle(GoogleVisionClient $client, VisionDocumentParser $parser, PrintedFormMatcher $matcher, BlankFormPrintPresenter $presenter, OcrBakeoffScorer $scorer): int
    {
        $folder = rtrim((string) $this->argument('folder'), '/\\');
        if (! is_dir($folder)) {
            $this->error("No folder at {$folder}.");

            return self::FAILURE;
        }

        $layoutPath = is_string($this->option('layout')) ? $this->option('layout') : $folder.DIRECTORY_SEPARATOR.'layout.json';
        $answersPath = is_string($this->option('answers')) ? $this->option('answers') : null;
        $out = is_string($this->option('out')) ? rtrim($this->option('out'), '/\\') : $folder.DIRECTORY_SEPARATOR.'_bakeoff';
        $offline = $this->option('offline') === true;

        try {
            $layout = OcrBakeoffLayout::fromFile($layoutPath);
            $questionsByVersion = [];
            $allQuestions = [];
            foreach ($layout->versions as $version) {
                $questionsByVersion[$version->version_number] = $layout->questions($version, $presenter);
                foreach ($questionsByVersion[$version->version_number] as $question) {
                    $allQuestions[$question->key] ??= $question;
                }
            }
            $sheet = $answersPath === null ? null : OcrAnswerSheet::read($answersPath, array_values($allQuestions));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $found = OcrBakeoffFolder::discover($folder, array_values(array_filter([$layoutPath, $answersPath])));
        if ($found['scans'] === []) {
            $this->error("No scan in {$folder} that the app would take.");
            foreach ($found['skipped'] as $skipped) {
                $this->line("  {$skipped['path']} — {$skipped['reason']}");
            }

            return self::FAILURE;
        }

        $scans = [];
        $problems = [];
        $usedRows = [];
        $withoutAnswers = [];

        foreach ($found['scans'] as $entry) {
            $this->line("  {$entry['name']}");
            $files = array_column($entry['files'], 'path');
            $pages = [];
            $failure = null;

            foreach ($entry['files'] as $file) {
                $body = $this->answerFor($file['path'], $file['mime'], $client, $offline, $failure);
                if ($body === null) {
                    break;
                }
                array_push($pages, ...$parser->pages($body));
            }

            if ($failure !== null) {
                $scans[] = new OcrBakeoffScan($entry['name'], $files, $failure[0], $failure[1]);

                continue;
            }

            $resolved = $matcher->resolveVersion($pages, $layout->versions, $layout->current());
            $version = $resolved['version'] ?? $layout->current();
            if (! CapabilityFlags::isOcrCompatible($version)) {
                $scans[] = new OcrBakeoffScan($entry['name'], $files, 'not_eligible', "Printed from version {$version->version_number}, which cannot be read automatically.", null, $version->version_number, $resolved['matched_by']);

                continue;
            }

            // The same gate the reading job applies (M143): a sheet from an older layout is listed, not scored.
            $printedLayout = $matcher->layoutOf($pages, $resolved['stamp']);
            if ($printedLayout['evidence'] === 'absent' || ($printedLayout['layout'] !== null && $printedLayout['layout'] < BlankFormPrintPresenter::LAYOUT)) {
                $scans[] = new OcrBakeoffScan($entry['name'], $files, 'old_layout', 'Printed from layout '.($printedLayout['layout'] ?? 1).'; the reader expects layout '.BlankFormPrintPresenter::LAYOUT.'. Print the samples from the current build.', null, $version->version_number, $resolved['matched_by']);

                continue;
            }

            $fields = $matcher->match($layout->form, $version, $pages, ['auto' => 0, 'review' => 0])['fields'];

            $row = $sheet?->rowFor($entry['name']);
            $expected = null;
            if ($sheet !== null && $row !== null) {
                $usedRows[$row['row']] = true;
                $expected = $this->expected($row, $questionsByVersion[$version->version_number] ?? [], $sheet->columns, $problems);
            } elseif ($sheet !== null) {
                $withoutAnswers[] = $entry['name'];
            }

            $scans[] = new OcrBakeoffScan($entry['name'], $files, 'read', null, $row['condition'] ?? null, $version->version_number, $resolved['matched_by'], $fields, $expected);
        }

        $orphans = [];
        foreach ($sheet === null ? [] : $sheet->rows as $row) {
            if (! isset($usedRows[$row['row']]) && ! $this->isScanNamed($row['scan'], $scans)) {
                $orphans[] = "row {$row['row']}: {$row['scan']}";
            }
        }

        $result = $scorer->score($scans, PrintedFormMatcher::configuredThresholds());

        $current = $layout->current();
        $unscored = [];
        if ($sheet !== null) {
            foreach ($questionsByVersion[$current->version_number] ?? [] as $question) {
                if (! isset($sheet->columns[$question->key])) {
                    $unscored[] = "{$question->key} ({$question->label})";
                }
            }
        }

        $meta = [
            'title' => $layout->form->title,
            'generated_at' => now()->format('Y-m-d H:i T'),
            'provider' => 'google_vision',
            'folder' => $folder,
            'versions' => array_map(static fn ($v): string => 'v'.$v->version_number, $layout->versions),
            'answers' => $answersPath,
            'offline' => $offline,
            'problems' => $problems,
            'orphan_rows' => $orphans,
            'skipped' => array_map(static fn (array $s): string => "{$s['path']} — {$s['reason']}", $found['skipped']),
            'unknown_headers' => $sheet === null ? [] : $sheet->unknownHeaders,
            'unscored_questions' => $unscored,
            'scans_without_answers' => $withoutAnswers,
        ];

        if (! is_dir($out) && ! mkdir($out, 0775, true) && ! is_dir($out)) {
            $this->error("Cannot create {$out}.");

            return self::FAILURE;
        }
        file_put_contents($out.DIRECTORY_SEPARATOR.'report.md', OcrBakeoffReport::markdown($result, $meta));
        OcrBakeoffReport::writeFieldsCsv($out.DIRECTORY_SEPARATOR.'fields.csv', $result['observations']);

        return $this->summarise($result, $meta, $out, $sheet !== null);
    }

    /**
     * The provider's answer for one page file: the cached one, else a fresh call (cached before it is used). Null, with
     * `$failure` set to the scan's status and message, when there is none.
     *
     * @param  array{0: string, 1: string}|null  $failure
     * @return array<string, mixed>|null
     */
    private function answerFor(string $path, string $mime, GoogleVisionClient $client, bool $offline, ?array &$failure): ?array
    {
        $cache = $path.OcrBakeoffFolder::CACHE_SUFFIX;

        if (is_file($cache)) {
            $body = json_decode((string) file_get_contents($cache), true);
            if (is_array($body)) {
                /** @var array<string, mixed> $body */
                return $body;
            }
            $failure = ['failed', 'The cached answer '.basename($cache).' is not JSON. Delete it and run again to read the page afresh.'];

            return null;
        }

        if ($offline) {
            $failure = ['not_read', 'No cached answer for '.basename($path).', and --offline forbids calling the provider.'];

            return null;
        }

        if ($this->refusal !== null) {
            $failure = ['failed', 'Not sent: '.$this->refusal];

            return null;
        }

        try {
            $body = $client->annotate((string) file_get_contents($path), $mime);
        } catch (OcrProviderException $e) {
            if (in_array($e->errorCode, self::CREDENTIAL_CODES, true)) {
                $this->refusal = $e->getMessage();
            }
            $failure = ['failed', "{$e->errorCode}: {$e->getMessage()}"];

            return null;
        }

        file_put_contents($cache, (string) json_encode($body, JSON_UNESCAPED_SLASHES));

        return $body;
    }

    /**
     * One answer row turned into stored-shape values for the questions of the version the scan was printed from. A cell
     * that cannot be understood is left out and listed in `$problems`.
     *
     * @param  array{row: int, scan: string, condition: string|null, cells: array<string, mixed>}  $row
     * @param  list<OcrBakeoffQuestion>  $questions
     * @param  array<string, string>  $columns
     * @param  list<string>  $problems
     * @return array<string, mixed>
     */
    private function expected(array $row, array $questions, array $columns, array &$problems): array
    {
        $expected = [];
        foreach ($questions as $question) {
            if (! array_key_exists($question->key, $row['cells'])) {
                continue;
            }
            $parsed = OcrBakeoffAnswers::expected($question, $row['cells'][$question->key]);
            if ($parsed['ok'] === true) {
                $expected[$question->key] = $parsed['value'];
            } else {
                $typed = OcrBakeoffReport::value(is_scalar($row['cells'][$question->key]) ? $row['cells'][$question->key] : '(a date or time cell)');
                $problems[] = "row {$row['row']} ({$row['scan']}), column {$columns[$question->key]}: \"{$typed}\" — {$parsed['reason']}";
            }
        }

        return $expected;
    }

    /** @param  list<OcrBakeoffScan>  $scans */
    private function isScanNamed(string $name, array $scans): bool
    {
        foreach ($scans as $scan) {
            if (OcrAnswerSheet::stem($scan->name) === OcrAnswerSheet::stem($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $meta
     */
    private function summarise(array $result, array $meta, string $out, bool $hasAnswers): int
    {
        /** @var array<string, mixed> $headline */
        $headline = $result['headline'];
        /** @var array{auto: int, review: int} $thresholds */
        $thresholds = $result['thresholds'];
        /** @var list<array<string, mixed>> $scanRows */
        $scanRows = $result['scans'];

        $this->newLine();
        $read = count(array_filter($scanRows, static fn (array $s): bool => $s['status'] === 'read'));
        $this->line(count($scanRows).' scan(s): '.$read.' read.');

        $fields = is_int($headline['fields'] ?? null) ? $headline['fields'] : 0;
        if ($fields > 0) {
            $rate = is_float($headline['rate'] ?? null) ? $headline['rate'] : 0.0;
            $line = sprintf('G9: %d of %d scored fields need correction (%.1f%%) at auto %d / review %d — %s. Silent errors: %d. Flagged: %d.',
                is_int($headline['needs_correction'] ?? null) ? $headline['needs_correction'] : 0, $fields, 100 * $rate, $thresholds['auto'], $thresholds['review'],
                ($headline['g9_pass'] ?? false) === true ? 'PASS' : 'FAIL', is_int($headline['silent'] ?? null) ? $headline['silent'] : 0, is_int($headline['flagged'] ?? null) ? $headline['flagged'] : 0);
            if (($headline['g9_pass'] ?? false) === true) {
                $this->info($line);
            } else {
                $this->warn($line);
            }
        } elseif ($hasAnswers) {
            $this->warn('Nothing was scored, so G9 is not measured.');
        }

        $problems = is_array($meta['problems'] ?? null) ? $meta['problems'] : [];
        $orphans = is_array($meta['orphan_rows'] ?? null) ? $meta['orphan_rows'] : [];
        foreach ([...$problems, ...$orphans] as $problem) {
            $this->error('  '.(is_string($problem) ? $problem : ''));
        }

        $this->line("Report: {$out}".DIRECTORY_SEPARATOR.'report.md · every field: '.$out.DIRECTORY_SEPARATOR.'fields.csv');

        if ($read === 0) {
            $this->error('No scan was read — see each scan\'s reason in the report.');

            return self::FAILURE;
        }

        if ($problems !== [] || $orphans !== [] || ($hasAnswers && $fields === 0)) {
            $this->error('The answer sheet was not fully used, so the figure above is not the whole measure. Fix the sheet and run again — cached pages cost nothing.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
