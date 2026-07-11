<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Submissions\ExportSubmissionsRequest;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Policies\SubmissionPolicy;
use App\Services\Submissions\SubmissionExporter;
use App\Services\Submissions\SubmissionInboxPresenter;
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
    public function index(Request $request, SubmissionInboxPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('submissions/Inbox', $presenter->list($user, [
            'form_id' => $this->query($request, 'form_id'),
            'status' => $this->query($request, 'status'),
            'source' => $this->query($request, 'source'),
        ]));
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

    /** A single string query param, or null (guards against array-shaped `?form_id[]=` input). */
    private function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
