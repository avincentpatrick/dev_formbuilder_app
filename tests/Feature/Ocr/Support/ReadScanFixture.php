<?php

declare(strict_types=1);

namespace Tests\Feature\Ocr\Support;

use App\Enums\OcrScanStatus;
use App\Enums\ScanStatus;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\OcrScan;
use App\Models\User;
use App\Services\Attachments\AttachmentStorageService;
use Illuminate\Http\UploadedFile;
use Ramsey\Uuid\Uuid;

/**
 * A scan that has already been read (M129 — the review screen and the save), without the reading job.
 *
 * The extraction is written in `PrintedFormMatcher`'s own shapes — numbers as text, a value at every tier (`D108`) —
 * and the page goes through the real write path, `storeOcrScanPage()`, so the review screen serves a file
 * that was sniffed, keyed and given a virus-check status exactly as an upload's is.
 */
final class ReadScanFixture
{
    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /**
     * @param  array<string, array<string, mixed>>  $fields  the extraction's `fields`, by key
     * @param  array<string, mixed>  $overrides  scan columns to override (`status`, `form_version_id`, …)
     * @param  list<string>  $warnings
     */
    public static function make(
        Form $form,
        User $uploader,
        array $fields,
        array $overrides = [],
        array $warnings = [],
        ?FormVersion $printedFrom = null,
        bool $servable = true,
        ?UploadedFile $page = null,
    ): OcrScan {
        $version = $printedFrom ?? FormVersion::query()->whereKey($form->current_published_version_id)->firstOrFail();
        $id = Uuid::uuid7()->toString();

        $file = app(AttachmentStorageService::class)->storeOcrScanPage(
            $page ?? UploadedFile::fake()->createWithContent('page.png', (string) base64_decode(self::PNG)),
            (string) $form->tenant_id,
            $id,
            (string) $uploader->id,
        );
        if ($servable) {
            Attachment::query()->whereKey($file->id)->update(['virus_scan_status' => ScanStatus::Skipped->value]);
        }

        $counts = ['read' => 0, 'blank' => 0, 'unreadable' => 0, 'not_found' => 0, 'skipped' => 0];
        foreach ($fields as $entry) {
            $counts[(string) $entry['state']] = ($counts[(string) $entry['state']] ?? 0) + 1;
        }

        // `forceFill`, because an override may name a column the model does not mass-assign (`created_at`, to
        // order a list) and a silently dropped override would make an ordering test pass by insertion luck.
        $scan = (new OcrScan)->forceFill(array_merge([
            'tenant_id' => (string) $form->tenant_id,
            'form_id' => $form->id,
            'form_version_id' => $version->id,
            'uploaded_by' => $uploader->id,
            'status' => OcrScanStatus::Read,
            'provider' => 'google_vision',
            'pages' => [['attachment_id' => (string) $file->id, 'response_path' => null]],
            'extraction' => [
                'version' => ['id' => $version->id, 'number' => $version->version_number, 'stamp' => substr((string) $version->checksum, 0, 8), 'matched_by' => 'stamp'],
                'pages' => 1,
                'counts' => $counts,
                'fields' => $fields,
                'warnings' => $warnings,
            ],
            'read_at' => now(),
        ], $overrides));
        $scan->id = $id;
        $scan->save();

        return $scan;
    }

    /** @return array<string, mixed> */
    public static function read(string $type, mixed $value, string $text, int $confidence, string $tier = 'auto'): array
    {
        return [
            'type' => $type, 'state' => 'read', 'value' => $value, 'text' => $text,
            'confidence' => $confidence, 'tier' => $tier, 'page' => 1, 'anchored_by' => 'key',
        ];
    }

    /** @return array<string, mixed> */
    public static function blank(string $type): array
    {
        return ['type' => $type, 'state' => 'blank', 'value' => null, 'text' => null, 'confidence' => null, 'tier' => null, 'page' => 1, 'anchored_by' => 'key'];
    }

    /** @return array<string, mixed> */
    public static function unreadable(string $type, string $text, int $confidence): array
    {
        return ['type' => $type, 'state' => 'unreadable', 'value' => null, 'text' => $text, 'confidence' => $confidence, 'tier' => null, 'page' => 1, 'anchored_by' => 'label'];
    }
}
