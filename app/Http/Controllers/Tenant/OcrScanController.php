<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\OcrScanStatus;
use App\Exceptions\Expressions\ExpressionException;
use App\Exceptions\Ocr\OcrException;
use App\Exceptions\Submissions\FormNotAcceptingSubmissionException;
use App\Exceptions\Submissions\SubmissionConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ocr\ConfirmOcrScanRequest;
use App\Http\Requests\Ocr\StoreOcrScanRequest;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\OcrScan;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Ocr\OcrScanConfirmation;
use App\Services\Ocr\OcrScanListPresenter;
use App\Services\Ocr\OcrScanReviewPresenter;
use App\Services\Ocr\OcrScanService;
use App\Services\Settings\TenantSettingRegistry;
use App\Services\Submissions\PrunedAnswerReport;
use App\Support\Attachments\InlineAttachmentResponse;
use App\Support\Navigation\CrumbTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The single-form OCR channel (M128 — groundwork 1; M129 — groundwork 2): take a scan in, report how reading
 * it went, list a form's scans, review one, serve its pages, and save it as a response.
 *
 * Session routes rather than `/api/v1`, the H14 precedent for a staff surface: a session carries no token.
 * The bearer twins the architecture names are a filed row. Every route carries the same gates — `can:create`
 * on a submission for the form (OCR is a way of entering responses), then the module toggle before the plan.
 *
 * ⚠️ `{scan}` IS LOOKED UP UNDER ITS FORM HERE, NOT BY `scopeBindings()`. Laravel would resolve a scoped
 * child through a `scans()` relationship on `Form`, which does not exist, and a scan id from another form
 * — or, under RLS, another workspace — must answer 404 either way. {@see scan()} is that scope, stated once.
 * The routes also constrain `{scan}` to a uuid, because a malformed id reaching Postgres is a 500, not a 404.
 *
 * ⚠️ WHY THE ACTIONS LIVE ON ONE CONTROLLER: a new controller is a new `use` line in `routes/tenant.php`,
 * which shifts every line of that file that a document cites. The actions stay thin; the work is in
 * {@see OcrScanListPresenter}, {@see OcrScanReviewPresenter} and {@see OcrScanConfirmation}.
 */
final class OcrScanController extends Controller
{
    public function store(StoreOcrScanRequest $request, Form $form, OcrScanService $service): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $scan = $service->store($form, $user, $request->pages());

        return response()->json(['data' => $this->present($scan)], 202);
    }

    public function show(Form $form, string $scan): JsonResponse
    {
        return response()->json(['data' => $this->present($this->scan($form, $scan))]);
    }

    /** The scans page: the upload, and this form's newest scans. */
    public function index(Request $request, Form $form, OcrScanListPresenter $presenter): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        return Inertia::render('ocr/Scans', [
            ...$presenter->present($form),
            'crumbs' => CrumbTrail::forms($user)->form($form)->formSubmissions($form)->current('Scanned forms'),
        ]);
    }

    /**
     * One scan: still being read, unreadable, ready to review, or already saved.
     *
     * A scan ready for review renders the manual-encoding page in its scan mode, for the form's CURRENT
     * version — the only version a response can be saved against (`D74`).
     */
    public function review(
        Request $request,
        Form $form,
        string $scan,
        OcrScanReviewPresenter $presenter,
        EntitlementService $entitlements,
        TenantSettingRegistry $settings,
    ): Response|RedirectResponse {
        $user = $request->user();
        assert($user instanceof User);

        $row = $this->scan($form, $scan);
        if ($row->isConfirmed()) {
            return redirect('/submissions/'.$row->submission_id)
                ->with('toast', ['type' => 'info', 'message' => 'This scan has already been saved as a response.']);
        }

        $trail = CrumbTrail::forms($user)->form($form)->formSubmissions($form)->ocrScans($form, $entitlements, $settings);

        if ($row->status !== OcrScanStatus::Read) {
            return Inertia::render('ocr/ScanStatus', [
                'form' => ['id' => $form->id, 'title' => $form->title],
                'scan' => [
                    'id' => $row->id,
                    'status' => $row->status->value,
                    'status_label' => OcrScanListPresenter::label($row->status),
                    'pages' => count($row->pages),
                    'error_message' => $row->status === OcrScanStatus::Failed ? $row->error_message : null,
                    'poll_url' => route('forms.ocr.scans.show', ['form' => $form, 'scan' => $row->id], false),
                ],
                'encode_url' => route('forms.submissions.create', $form, false),
                'scans_url' => route('forms.ocr.scans.index', $form, false),
                'crumbs' => $trail->current('Reading a scan'),
            ]);
        }

        $crumbs = $trail->current('Review a scan');

        return Inertia::render('submissions/Encode', [
            ...$presenter->present($form, $this->currentVersion($form), $row),
            'crumbs' => $crumbs,
            'cancel_url' => CrumbTrail::exitFrom($crumbs),
        ]);
    }

    /**
     * One page file of a scan, by its position on the scan (1-based). The file must still be this scan's — its
     * own until the scan is saved, the saved response's after — so no attachment id travels in the URL.
     */
    public function page(Form $form, string $scan, string $page): StreamedResponse
    {
        $row = $this->scan($form, $scan);
        $entry = $row->pages[((int) $page) - 1] ?? null;
        abort_if($entry === null, 404);

        $file = Attachment::query()
            ->whereKey($entry['attachment_id'])
            ->where(static function (Builder $owner) use ($row): void {
                $owner->where(static fn (Builder $q) => $q->where('attachable_type', 'ocr_scan')->where('attachable_id', $row->id));
                if ($row->submission_id !== null) {
                    $owner->orWhere(static fn (Builder $q) => $q->where('attachable_type', 'submission')->where('attachable_id', $row->submission_id));
                }
            })
            ->firstOrFail();

        return InlineAttachmentResponse::for($file);
    }

    /**
     * Save the reviewed answers as a response, through the pipeline (`docs/ocr-pipeline-design.md` §4).
     *
     * ⛔ THE ERRORS BAG IS THE POINT. A conflict, a closed form or a rule the server could not evaluate reaches
     * the central renderer as a toast-only `back()`, and the review page keeps its state only when the page
     * comes back carrying errors — so each is caught here under the `scan` key, and the reviewer's corrections
     * stay on screen. ⚠️ NEVER `baseline`: the encode page reads that key as an editing conflict. A validation
     * refusal is left to the central renderer, which already sends its errors bag.
     */
    public function confirm(
        ConfirmOcrScanRequest $request,
        Form $form,
        string $scan,
        OcrScanConfirmation $confirmation,
        PrunedAnswerReport $report,
    ): RedirectResponse {
        $user = $request->user();
        assert($user instanceof User);

        $row = $this->scan($form, $scan);
        if ($row->isConfirmed()) {
            return redirect('/submissions/'.$row->submission_id)
                ->with('toast', ['type' => 'info', 'message' => 'This scan has already been saved as a response.']);
        }

        $version = $this->currentVersion($form);

        try {
            $result = $confirmation->confirm($version, $row, $user, $request->answers());
        } catch (OcrException|SubmissionConflictException|FormNotAcceptingSubmissionException $e) {
            return $this->refuse($e->getMessage());
        } catch (ExpressionException $e) {
            report($e); // catching silences it, and a broken published rule is a fault the operator must hear about

            return $this->refuse("This response can't be saved because one of this form's rules couldn't be checked. Your answers are still on the page.");
        }

        $pruned = $result->semantic === null ? [] : $report->of($version, $request->answers(), $result->semantic);
        $count = count($pruned);

        return redirect('/submissions/'.$result->submission->id)->with('toast', $count === 0
            ? ['type' => 'success', 'message' => 'Response saved from the scan.']
            : ['type' => 'info', 'message' => "Response saved from the scan. {$count} ".($count === 1 ? 'answer did not apply and was' : 'answers did not apply and were').' not saved.']);
    }

    private function scan(Form $form, string $id): OcrScan
    {
        return OcrScan::query()->where('form_id', $form->id)->whereKey($id)->firstOrFail();
    }

    /** The policy already requires a published form, so a missing version is an invariant breach, not a null. */
    private function currentVersion(Form $form): FormVersion
    {
        abort_if($form->current_published_version_id === null, 404);

        return FormVersion::query()->whereKey($form->current_published_version_id)->firstOrFail();
    }

    private function refuse(string $message): RedirectResponse
    {
        return back()
            ->withErrors(['scan' => $message])
            ->with('toast', ['type' => 'error', 'message' => $message]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OcrScan $scan): array
    {
        return [
            'id' => $scan->id,
            'status' => $scan->status->value,
            'pages' => count($scan->pages),
            'form_version_id' => $scan->form_version_id,
            'extraction' => $scan->extraction,
            'error' => $scan->error_code === null ? null : ['code' => $scan->error_code, 'message' => $scan->error_message],
            'created_at' => $scan->created_at->toIso8601String(),
            'read_at' => $scan->read_at?->toIso8601String(),
        ];
    }
}
