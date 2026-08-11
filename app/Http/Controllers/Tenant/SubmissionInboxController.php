<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Concerns\ReadsKeywordFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Submissions\ExportSubmissionsRequest;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Policies\SubmissionPolicy;
use App\Services\Submissions\SubmissionExporter;
use App\Services\Submissions\SubmissionInboxPresenter;
use App\Services\Submissions\SubmissionPdfRequestService;
use App\Support\Forms\FormTabSet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The submissions inbox (Increment F7) — the authenticated read surface over every Submission Pipeline
 * channel. `index`/`show` render Inertia pages from {@see SubmissionInboxPresenter} (row-level visibility is
 * the presenter's `visibleTo` scope); `export` streams a form's responses through {@see SubmissionExporter}.
 * Authorization is the `can:` route middleware ({@see SubmissionPolicy}); this controller stays a thin adapter.
 */
final class SubmissionInboxController extends Controller
{
    use ReadsKeywordFilter;

    public function index(Request $request, SubmissionInboxPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('submissions/Inbox', $presenter->list($user, [
            'form_id' => $this->query($request, 'form_id'),
            'status' => $this->query($request, 'status'),
            'source' => $this->query($request, 'source'),
            'q' => $this->keyword($request),
        ]));
    }

    /**
     * One form's responses — `GET /forms/{form}/submissions` (Increment J2c), the Responses tab of the form
     * hub's strip.
     *
     * The SAME presenter method as {@see index()}, given the bound form; the page it renders is the same
     * Inertia component too. That is the whole design: J1e's audit-export defect was one filter chain
     * spelled twice, and a per-form inbox is the most tempting place in this codebase to spell a third.
     *
     * The tab set is composed HERE rather than in the presenter, following {@see FormAnalyticsController}:
     * every tab is a gate question, and the presenter deliberately reads nothing about who is asking beyond
     * the row-visibility scope it already applies.
     *
     * ⚠️ TWO GATES ON THE ROUTE, AND THE SECOND ONE IS NOT DECORATION. `can:viewAny,Submission` is the
     * inbox's own gate but says nothing about WHICH form, so alone it would let any member with
     * `submissions.view` open this page for any form in the tenant and read its TITLE above an empty list.
     * `can:viewOverview,form` is the bound-form half. See `routes/tenant.php`.
     */
    public function forForm(Request $request, Form $form, SubmissionInboxPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('submissions/Inbox', [
            ...$presenter->list($user, [
                'status' => $this->query($request, 'status'),
                'source' => $this->query($request, 'source'),
                'q' => $this->keyword($request),
            ], $form),
            'form' => ['id' => $form->id, 'title' => $form->title],
            'tabs' => FormTabSet::for($form, $user),
        ]);
    }

    public function show(Request $request, Submission $submission, SubmissionInboxPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('submissions/Show', $presenter->detail($user, $submission));
    }

    public function export(ExportSubmissionsRequest $request, Form $form, SubmissionExporter $exporter): StreamedResponse
    {
        return $exporter->stream($form, $request->filters(), $request->exportFormat());
    }

    /**
     * Queue a PDF of one submission (Increment H17).
     *
     * A separate route rather than a third `format` on `export`: that endpoint returns a
     * `StreamedResponse` synchronously and its request object validates `in:csv,xlsx`, whereas
     * this returns a redirect and a toast because the work happens on a worker. Bolting `pdf`
     * onto the format enum would give one endpoint two response shapes.
     *
     * Three things happen HERE rather than in the job, and each has a reason:
     *   - The AUDIT row, because `AuditLogger` hard-codes `is_system_action = false` and resolves
     *     a null actor off a worker. H12a hit exactly this and chose to skip the audit entirely;
     *     writing it at enqueue, where `Auth::id()` is live, is how H17 gets a well-formed row
     *     instead. This is the first call site of `AuditEvent::Exported`, which has existed unused.
     *   - The METER, matching `SubmissionExporter`'s "meter the request, not the completion".
     *   - The QUOTA PRE-FLIGHT, so a tenant already at its storage ceiling gets the 402-rendered
     *     toast in-request instead of an email twenty seconds later. The job re-checks with the
     *     real byte count; this only catches the already-full case, which is the common one.
     */
    public function generatePdf(Request $request, Submission $submission, SubmissionPdfRequestService $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $service->queue($submission, $user);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Generating the PDF. We will email you a link when it is ready.',
        ]);
    }

    /** A single string query param, or null (guards against array-shaped `?form_id[]=` input). */
    private function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
