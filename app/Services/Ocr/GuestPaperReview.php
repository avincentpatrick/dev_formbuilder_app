<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Enums\OcrScanSource;
use App\Enums\OcrScanStatus;
use App\Exceptions\Guest\ExpiredShareTokenException;
use App\Exceptions\Guest\InvalidShareTokenException;
use App\Exceptions\Ocr\OcrException;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\OcrScan;
use App\Services\Forms\LinkedChoiceService;
use App\Support\Guest\GuestShareToken;
use App\Support\Guest\GuestShareTokenService;

/**
 * What a guest who sent a filled paper copy sees of it (M166, `R-309e4dfa`, `D116`): whether it has been read yet,
 * and once it has, the form's answers as read — to check, correct and submit as their own response.
 *
 * ── THE PAPER TOKEN IS THE ONLY WAY IN ───────────────────────────────────────────────────────────────
 * The upload hands its sender a short-lived signed token naming the one scan ({@see GuestShareTokenService::mintPaper()}).
 * The reference the guest is shown is a display handle and never resolves anything. Every read here verifies the
 * token, then requires it to name the SAME workspace and form as the share token the request already carries, then
 * finds the scan under that form and only among guest uploads — so a token cannot be replayed onto another form,
 * nor reach a scan a member uploaded.
 *
 * ── THE ANSWERS ARE CARRIED ONTO THE VERSION THE GUEST'S PAGE SHOWS ──────────────────────────────────
 * `OcrAnswerCarry` is the staff review's own rule (`D74`): an answer moves only onto the same question, of the same
 * type, with a value it can hold. The target is the version the share token pins, which is the schema the guest's page
 * rendered; if the form was republished since, their Submit meets the ordinary `form_updated` and the page reloads.
 *
 * ── NOTHING HERE IS CACHED ───────────────────────────────────────────────────────────────────────────
 * The answers and the pages are a respondent's handwriting. The routes live off the `f/` prefix the service worker
 * caches, and the controller marks every answer `private, no-store`.
 */
final class GuestPaperReview
{
    public function __construct(
        private readonly GuestShareTokenService $tokens,
        private readonly OcrAnswerCarry $carry,
        private readonly LinkedChoiceService $linked,
    ) {}

    /**
     * The scan a paper token names, under the share token's own form.
     *
     * @throws OcrException when the token is forged, expired, for another form, or names no guest scan here
     */
    public function scan(GuestShareToken $share, string $paperToken): OcrScan
    {
        try {
            $paper = $this->tokens->verifyPaper($paperToken);
        } catch (ExpiredShareTokenException) {
            throw OcrException::paperTokenExpired();
        } catch (InvalidShareTokenException) {
            throw OcrException::paperTokenInvalid();
        }

        if ($paper->tenantId !== $share->tenantId || $paper->formId !== $share->formId || $paper->scanId === null) {
            throw OcrException::paperTokenInvalid();
        }

        $scan = OcrScan::query()
            ->where('form_id', $share->formId)
            ->where('source', OcrScanSource::Guest->value)
            ->whereKey($paper->scanId)
            ->first();

        if (! $scan instanceof OcrScan) {
            throw OcrException::paperTokenInvalid();
        }

        return $scan;
    }

    /**
     * Where the scan stands, for the guest: `reading`, `failed`, `submitted`, or `read` with the answers to check.
     *
     * @return array<string, mixed>
     */
    public function present(OcrScan $scan, GuestShareToken $share, string $paperToken): array
    {
        if ($scan->submission_id !== null) {
            return ['status' => 'submitted'];
        }

        if ($scan->status === OcrScanStatus::Failed) {
            // Never the scan's own `error_message`, which is written for staff (a provider key, a billing switch).
            return ['status' => 'failed'];
        }

        if ($scan->status !== OcrScanStatus::Read) {
            return ['status' => 'reading'];
        }

        $target = FormVersion::query()->where('form_id', $share->formId)->whereKey($share->formVersionId)->firstOrFail();
        $paper = $scan->form_version_id === null
            ? $target
            : (FormVersion::query()->where('form_id', $share->formId)->whereKey($scan->form_version_id)->first() ?? $target);

        // M184: a linked question's written answer is checked against the list the guest's page draws.
        $linked = $target->form instanceof Form ? $this->linked->valuesFor($target->form, $target) : [];
        $carried = $this->carry->carry((array) $scan->extraction, (array) $paper->schema_snapshot, (array) $target->schema_snapshot, $linked);

        return [
            'status' => 'read',
            'version_id' => $target->id,
            // The form's answers as read, by question key; always an object, never a list.
            'answers' => (object) $carried['answers'],
            'fields' => (object) $carried['fields'],
            'pages' => $this->pages($scan, $share, $paperToken),
        ];
    }

    /**
     * One page of the scan, while it is still the scan's — once its sender has submitted it, the pages belong to the
     * response and are no longer the paper token's to read.
     */
    public function page(OcrScan $scan, int $number): Attachment
    {
        $entry = $scan->pages[$number - 1] ?? null;
        abort_if($entry === null || $scan->submission_id !== null, 404);

        return Attachment::query()
            ->whereKey($entry['attachment_id'])
            ->where('attachable_type', 'ocr_scan')
            ->where('attachable_id', $scan->id)
            ->firstOrFail();
    }

    /** @return list<array{number: int, url: string, mime: string, servable: bool}> */
    private function pages(OcrScan $scan, GuestShareToken $share, string $paperToken): array
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
                'url' => route('api.v1.public.paper.page', ['shareToken' => $share->rawToken, 'paperToken' => $paperToken, 'page' => $index + 1], false),
                'mime' => $file->mime_type ?? 'application/octet-stream',
                'servable' => $file->virus_scan_status->servable(),
            ];
        }

        return $pages;
    }
}
