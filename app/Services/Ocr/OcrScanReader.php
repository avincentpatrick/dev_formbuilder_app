<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Enums\FormVersionStatus;
use App\Enums\OcrScanStatus;
use App\Enums\ScanStatus;
use App\Exceptions\Ocr\OcrProviderException;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\OcrScan;
use App\Services\Forms\BlankFormPrintPresenter;
use App\Services\Forms\CapabilityFlags;
use Illuminate\Support\Facades\Storage;

/**
 * Advances one scan by one step (M128): read the next unread page, or, once every page is read, match the
 * whole scan to its printed form. The reading job calls {@see step()} once per run.
 *
 * ── ONE PAGE PER RUN ────────────────────────────────────────────────────────────────────────────────
 * Every run is a database transaction (`TenantAwareJob::handle()`), and a provider call can take up to the
 * client's timeout. One call per run keeps each transaction short and each run far inside the worker's own
 * limit. Windows PHP has no `pcntl`, so nothing can stop a run that overstays; it has to stay short by
 * construction.
 *
 * ── A RUN NEVER THROWS FOR A PROVIDER FAILURE ───────────────────────────────────────────────────────
 * A throw would roll back the run's own bookkeeping, and `failed()` on the base job is final and only logs,
 * so nothing would ever mark the scan failed. Instead a failure worth retrying counts one `attempts` and
 * returns {@see OcrReadStep::Wait}, and any other failure marks the scan `failed` with the provider
 * exception's code and message. Past `ocr.max_attempts` a retryable failure is final too, so every scan
 * reaches `read` or `failed`. That is `DeliverWebhookJob`'s stance.
 *
 * ── A PAGE'S ANSWER IS WRITTEN BEFORE THE ROW SAYS SO, AND READ BACK BEFORE ANY CALL ────────────────
 * The provider's raw answer for a page goes to a FIXED path beside the page file, and a run checks that
 * path before calling. The file outlives a run killed after the call, which the database rollback does
 * not, so a retry reuses the answer instead of paying for the page twice. Keeping the answer is also what
 * lets the calibration re-run the matcher on real scans without a second call.
 */
final class OcrScanReader
{
    /** The memory limit while the ink is measured, in bytes ({@see withInk()}). */
    private const int INK_MEMORY = 512 * 1024 * 1024;

    public function __construct(
        private readonly GoogleVisionClient $client,
        private readonly VisionDocumentParser $parser,
        private readonly PrintedFormMatcher $matcher,
        private readonly OcrLineBuilder $lines,
    ) {}

    public function step(OcrScan $scan): OcrReadStep
    {
        if ($scan->status->isTerminal()) {
            return OcrReadStep::Done;
        }

        $pages = $scan->pages;
        foreach ($pages as $index => $page) {
            if ($page['response_path'] !== null) {
                continue;
            }

            return $this->readPage($scan, $index);
        }

        $this->match($scan);

        return OcrReadStep::Done;
    }

    /** Seconds before the next try, growing with each try that made no progress. */
    public function backoffFor(OcrScan $scan): int
    {
        // Called after the failed run has already counted itself, so the first retry waits the first delay.
        return [15, 60, 180, 420, 600][min(max($scan->attempts - 1, 0), 4)];
    }

    private function readPage(OcrScan $scan, int $index): OcrReadStep
    {
        $attachmentId = $scan->pages[$index]['attachment_id'];
        $attachment = Attachment::query()->whereKey($attachmentId)->first();

        if ($attachment === null) {
            return $this->fail($scan, 'scan_missing', 'A page of this scan is missing from storage. Upload the scan again.');
        }

        if ($attachment->virus_scan_status === ScanStatus::Infected) {
            return $this->fail($scan, 'infected_upload', 'A page of this scan failed its virus check and was not read.');
        }

        if ($attachment->virus_scan_status === ScanStatus::Pending) {
            return $this->waitOrFail($scan, 'scan_check_pending', 'A page of this scan is still waiting for its virus check. Upload the scan again if this persists.');
        }

        $disk = Storage::disk($attachment->disk);
        $responsePath = $this->responsePath($attachment, $scan, $index);

        if (! $disk->exists($responsePath)) {
            try {
                $answer = $this->client->annotate((string) $disk->get($attachment->path), (string) $attachment->mime_type);
            } catch (OcrProviderException $e) {
                return $e->retryable
                    ? $this->waitOrFail($scan, $e->errorCode, $e->getMessage())
                    : $this->fail($scan, $e->errorCode, $e->getMessage());
            }

            $disk->put($responsePath, (string) json_encode($answer, JSON_UNESCAPED_SLASHES));
        }

        $pages = $scan->pages;
        $pages[$index] = ['attachment_id' => $pages[$index]['attachment_id'], 'response_path' => $responsePath];
        $scan->pages = $pages;
        $scan->status = OcrScanStatus::Reading;
        $scan->save();

        foreach ($pages as $page) {
            if ($page['response_path'] === null) {
                return OcrReadStep::Continue;
            }
        }

        $this->match($scan);

        return OcrReadStep::Done;
    }

    /**
     * Every page is read: decide the version from the stamp, refuse a version that cannot be read, match,
     * and record the per-scan confidence on each page, which is where `docs/data-dictionary.md` §10 keeps it.
     */
    private function match(OcrScan $scan): void
    {
        $form = Form::query()->whereKey($scan->form_id)->first();
        if ($form === null) {
            $this->fail($scan, 'form_missing', 'The form this scan was uploaded to no longer exists.');

            return;
        }

        $pages = [];
        $pageImages = [];
        $totalPages = 0;
        foreach ($scan->pages as $page) {
            $attachment = Attachment::query()->whereKey($page['attachment_id'])->first();
            $raw = $attachment === null || $page['response_path'] === null ? null : Storage::disk($attachment->disk)->get($page['response_path']);
            $body = is_string($raw) ? json_decode($raw, true) : null;
            $body = is_array($body) ? $body : [];
            /** @var array<string, mixed> $body */
            $parsedPages = $this->parser->pages($body);
            foreach ($parsedPages as $parsed) {
                $pages[] = $parsed;
                // A photo is one page with an image to measure the ink in; a PDF's pages have none of their own.
                $pageImages[] = $attachment !== null && str_starts_with((string) $attachment->mime_type, 'image/') ? $attachment : null;
            }
            $totalPages += $this->parser->totalPages($body);
        }

        $candidates = FormVersion::query()
            ->where('form_id', $form->id)
            ->whereIn('status', [FormVersionStatus::Published, FormVersionStatus::Superseded])
            ->orderByDesc('version_number')
            ->get();

        $resolved = $this->matcher->resolveVersion($pages, $candidates, $form->currentPublishedVersion);
        $version = $resolved['version'];

        if ($version === null) {
            $this->fail($scan, 'form_not_published', 'This form has no published version to read the scan against.');

            return;
        }

        if (! CapabilityFlags::isOcrCompatible($version)) {
            $this->fail($scan, 'form_not_eligible', "This scan was printed from version {$version->version_number}, which cannot be read automatically. Key the responses in instead.");

            return;
        }

        // Layout 2 (`M143`, closing `R-d6546409`): the stamp names the schema, the "Layout N" word beside it names
        // the template, and a sheet from an older template would be read against the wrong geometry. Refused
        // only on POSITIVE evidence — a legible running head with no layout word, or a lower number; a head
        // that was not read is a warning, or every badly photographed new sheet would be refused too. The
        // message names the two numbers rather than a date (`M144`): a date was false the day the next layout
        // shipped, and the number is printed on the sheet for the user to check.
        $layout = $this->matcher->layoutOf($pages, $resolved['stamp']);
        if ($layout['evidence'] === 'absent' || ($layout['layout'] !== null && $layout['layout'] < BlankFormPrintPresenter::LAYOUT)) {
            $printed = $layout['layout'] === null ? 'without a layout mark beside its stamp' : 'from layout '.$layout['layout'];
            $this->fail($scan, 'layout_outdated', sprintf(
                'This sheet was printed %s, an older layout of the form: the current paper is layout %d, and the answers on this sheet sit in other places than the reader expects. Key the response in by hand, and print fresh copies for scanning.',
                $printed,
                BlankFormPrintPresenter::LAYOUT,
            ));

            return;
        }

        $matched = $this->matcher->match($form, $version, $pages);
        $matched = $this->withInk($matched, $pages, $pageImages);

        $warnings = [];
        if ($resolved['matched_by'] === 'unconfirmed') {
            $warnings[] = 'version_unconfirmed';
        }
        if ($layout['layout'] !== BlankFormPrintPresenter::LAYOUT) {
            $warnings[] = 'layout_unconfirmed'; // garbled, not read at all, or a higher number — a misread either way
        }
        if ($totalPages > count($pages)) {
            $warnings[] = 'pages_beyond_limit';
        }

        $scan->form_version_id = $version->id;
        $scan->extraction = [
            'version' => [
                'id' => $version->id,
                'number' => $version->version_number,
                'stamp' => $resolved['stamp'],
                'matched_by' => $resolved['matched_by'],
            ],
            'pages' => $matched['pages'],
            'counts' => $matched['counts'],
            'fields' => $matched['fields'],
            'warnings' => $warnings,
        ];
        $scan->status = OcrScanStatus::Read;
        $scan->read_at = now();
        $scan->error_code = null;
        $scan->error_message = null;
        $scan->save();

        $average = $this->averageConfidence($matched['fields']);
        foreach ($scan->pages as $page) {
            Attachment::query()->whereKey($page['attachment_id'])->update(['ocr_confidence_avg' => $average]);
        }
    }

    /**
     * The match with every tick question Vision left blank read again from the ink in its printed boxes (M160, `D112`,
     * `D113`): Cloud Vision returns no mark at all for most ticks standing alone in a box. Each such answer is filled at
     * review confidence with `read_by` = `ink`, which the review screen names. Without GD nothing changes.
     *
     * ⚠️ THE MEMORY LIMIT IS RAISED FOR THIS STEP ONLY. GD holds a 16-megapixel photo in 72 MB (measured), and the worker
     * runs under a 128M `memory_limit` (`docs/deployment-infrastructure.md` §8 step 1); a page over
     * {@see GdInkImage::MAX_PIXELS} is not decoded at all. Each image is released before the next, and the limit restored.
     *
     * @param  array{fields: array<string, array<string, mixed>>, counts: array<string, int>, pages: int, labels: array<string, list<array{value: string, label: string, word: OcrWord, page: int}>>}  $matched
     * @param  list<OcrPage>  $pages
     * @param  list<Attachment|null>  $pageImages
     * @return array{fields: array<string, array<string, mixed>>, counts: array<string, int>, pages: int, labels: array<string, list<array{value: string, label: string, word: OcrWord, page: int}>>}
     */
    private function withInk(array $matched, array $pages, array $pageImages): array
    {
        $open = OcrInkReader::opener();
        $imageFor = static function (int $index) use ($pageImages, $open): ?OcrInkImage {
            $attachment = $pageImages[$index] ?? null;
            $bytes = $attachment === null ? null : Storage::disk($attachment->disk)->get($attachment->path);

            return is_string($bytes) ? $open($bytes) : null;
        };

        $limit = (string) ini_get('memory_limit');
        $raised = $limit !== '-1' && ini_parse_quantity($limit) < self::INK_MEMORY && ini_set('memory_limit', (string) self::INK_MEMORY) !== false;
        try {
            $result = OcrInkReader::fromConfig()->fillBlanks($matched['fields'], $matched['labels'], $pages, $imageFor, $this->lines, PrintedFormMatcher::configuredThresholds());
        } finally {
            if ($raised) {
                ini_set('memory_limit', $limit);
            }
        }

        $matched['fields'] = $result['fields'];
        $filled = count($result['filled']);
        $matched['counts']['blank'] = ($matched['counts']['blank'] ?? 0) - $filled;
        $matched['counts']['read'] = ($matched['counts']['read'] ?? 0) + $filled;

        return $matched;
    }

    /**
     * The mean confidence of the fields that were read, or null when none was — the per-scan figure
     * `attachments.ocr_confidence_avg` documents.
     *
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function averageConfidence(array $fields): ?float
    {
        $values = [];
        foreach ($fields as $field) {
            if (($field['state'] ?? null) === 'read' && is_int($field['confidence'] ?? null)) {
                $values[] = $field['confidence'];
            }
        }

        return $values === [] ? null : round(array_sum($values) / count($values), 2);
    }

    private function waitOrFail(OcrScan $scan, string $code, string $message): OcrReadStep
    {
        $scan->attempts++;

        if ($scan->attempts >= (int) config('ocr.max_attempts', 5)) {
            return $this->fail($scan, $code, $message);
        }

        $scan->save();

        return OcrReadStep::Wait;
    }

    private function fail(OcrScan $scan, string $code, string $message): OcrReadStep
    {
        $scan->status = OcrScanStatus::Failed;
        $scan->error_code = $code;
        $scan->error_message = $message;
        $scan->save();

        return OcrReadStep::Done;
    }

    /**
     * Where a page's raw answer lives: beside the page file, named by scan and page so a retry finds it.
     */
    private function responsePath(Attachment $attachment, OcrScan $scan, int $index): string
    {
        return dirname((string) $attachment->path)."/{$scan->id}-p{$index}.vision.json";
    }
}
