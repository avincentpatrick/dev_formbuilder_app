<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\OcrScan;
use App\Services\Submissions\EncodeFormPresenter;

/**
 * The review screen's props (M129 — single-form OCR groundwork 2): the manual-encoding page for the CURRENT
 * version, plus what the scan read.
 *
 * ── A FOURTH MODE OF THE ENCODE PAGE, NOT A SECOND FORM RENDERER ────────────────────────────────────
 * `docs/ocr-pipeline-design.md` §4 asks for "the editable extracted form" beside the scanned image. The
 * encode page already IS the staff surface for entering a response, with the guest runtime's own store, so
 * this wraps {@see EncodeFormPresenter::present()} rather than building a second renderer that would drift
 * from it. ⚠️ Not a staged draft: a draft belongs to one user and is reaped after its TTL, while a scan is
 * the workspace's until someone saves it — so the scan row is the staging record, as `M128` decided.
 *
 * ⚠️ `draft_url` IS NULLED, and the page also gates its autosave on scan mode. Either alone would look
 * sufficient; both are here because a review autosaved down the draft channel would create a draft row the
 * reviewer never asked for, under the uuid the confirmation is about to use.
 */
final class OcrScanReviewPresenter
{
    public function __construct(
        private readonly EncodeFormPresenter $encode,
        private readonly OcrAnswerCarry $carry,
    ) {}

    /** @return array<string, mixed> */
    public function present(Form $form, FormVersion $current, OcrScan $scan): array
    {
        /** @var array<string, mixed> $extraction */
        $extraction = $scan->extraction ?? [];
        $paper = $this->paperVersion($form, $scan) ?? $current;
        $carried = $this->carry->carry($extraction, (array) $paper->schema_snapshot, (array) $current->schema_snapshot);

        return [
            ...$this->encode->present($form, $current),
            'draft_url' => null,
            'scan' => [
                'id' => $scan->id,
                'submit_url' => route('forms.ocr.scans.confirm', ['form' => $form, 'scan' => $scan->id], false),
                'answers' => $carried['answers'],
                'fields' => $carried['fields'],
                'pages' => $this->pages($form, $scan),
                'notices' => $this->notices($extraction, $paper, $current, $carried['dropped']),
                'version' => [
                    'number' => $paper->version_number,
                    'current_number' => $current->version_number,
                ],
            ],
        ];
    }

    /** The version the paper was printed from, among THIS form's versions only. */
    private function paperVersion(Form $form, OcrScan $scan): ?FormVersion
    {
        if ($scan->form_version_id === null) {
            return null;
        }

        return FormVersion::query()->where('form_id', $form->id)->whereKey($scan->form_version_id)->first();
    }

    /** @return list<array{number: int, url: string, mime: string, servable: bool}> */
    private function pages(Form $form, OcrScan $scan): array
    {
        $ids = array_map(static fn (array $page): string => $page['attachment_id'], $scan->pages);
        $files = Attachment::query()->whereIn('id', $ids)->get()->keyBy('id');

        $pages = [];
        foreach ($ids as $index => $id) {
            $file = $files->get($id);
            if (! $file instanceof Attachment) {
                continue;
            }
            $pages[] = [
                'number' => $index + 1,
                'url' => route('forms.ocr.scans.page', ['form' => $form, 'scan' => $scan->id, 'page' => $index + 1], false),
                'mime' => $file->mime_type ?? 'application/octet-stream',
                'servable' => $file->virus_scan_status->servable(),
            ];
        }

        return $pages;
    }

    /**
     * What the reviewer must know before checking a single answer, in the order they should read it.
     *
     * @param  array<string, mixed>  $extraction
     * @param  list<array{key: string, label: string, text: string|null, reason: string, message: string}>  $dropped
     * @return list<array{code: string, tone: string, message: string, items?: list<array{label: string, text: string|null, message: string}>}>
     */
    private function notices(array $extraction, FormVersion $paper, FormVersion $current, array $dropped): array
    {
        $notices = [];
        $warnings = (array) ($extraction['warnings'] ?? []);

        if ($paper->id !== $current->id) {
            $notices[] = [
                'code' => 'old_paper',
                'tone' => 'info',
                'message' => "This paper was printed from version {$paper->version_number}. Its answers have been moved to the current version ({$current->version_number}), question by question.",
            ];
        }

        if (in_array('version_unconfirmed', $warnings, true)) {
            $notices[] = [
                'code' => 'version_unconfirmed',
                'tone' => 'warning',
                'message' => 'The version stamp on this paper could not be read, so the answers were matched by their question labels. Check them against the paper.',
            ];
        }

        if (in_array('pages_beyond_limit', $warnings, true)) {
            $max = (int) config('ocr.upload.max_pages', 5);
            $notices[] = [
                'code' => 'pages_beyond_limit',
                'tone' => 'warning',
                'message' => "Only the first {$max} pages were read. Enter answers from any later page by hand.",
            ];
        }

        if ((int) data_get($extraction, 'counts.read', 0) === 0) {
            $notices[] = [
                'code' => 'nothing_read',
                'tone' => 'warning',
                'message' => 'Nothing could be read from this scan. Enter the answers from the paper.',
            ];
        }

        if ($dropped !== []) {
            $count = count($dropped);
            $notices[] = [
                'code' => 'not_carried',
                'tone' => 'warning',
                'message' => $count === 1
                    ? 'One answer on the paper could not be filled in:'
                    : "{$count} answers on the paper could not be filled in:",
                'items' => array_map(static fn (array $entry): array => [
                    'label' => $entry['label'],
                    'text' => $entry['text'],
                    'message' => $entry['message'],
                ], $dropped),
            ];
        }

        return $notices;
    }
}
