<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\SubmissionSource;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Submissions\EncodeSubmissionRequest;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Policies\SubmissionPolicy;
use App\Services\Submissions\EncodeFormPresenter;
use App\Services\Submissions\PrunedAnswerReport;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manual encoding — the first Submission Pipeline channel to get a UI (Increment F4b). Authorization is the
 * `can:create,<Submission>,form` route middleware ({@see SubmissionPolicy}: permission +
 * per-form collaborator scope + published). This controller is a thin channel adapter: it resolves the
 * form's published version, hands the raw answers to the one {@see SubmissionPipeline} (the adapter never
 * validates), and lets the pipeline's {@see SubmissionValidationException} bubble
 * to the central bootstrap/app.php render closure (→ `back()->withErrors()` for the web / 422 for the API).
 *
 * Increment H21c — `store()` now READS the {@see SubmissionResult} it used to discard, so the keyer is told
 * what relevance pruned ({@see PrunedAnswerReport}, Doc #27 §7's defect (b)). The `/api/v1` submit channel is
 * deliberately left alone: it already returns the persisted document, its caller is a program rather than a
 * person, and touching it would move `openapi.json`.
 */
final class SubmissionController extends Controller
{
    public function create(Form $form, EncodeFormPresenter $presenter): Response
    {
        return Inertia::render('submissions/Encode', $presenter->present($form, $this->publishedVersion($form)));
    }

    public function store(
        EncodeSubmissionRequest $request,
        Form $form,
        SubmissionPipeline $pipeline,
        PrunedAnswerReport $report,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $version = $this->publishedVersion($form);
        $answers = $request->answers();

        $result = $pipeline->submit(new SubmissionPayload(
            version: $version,
            answers: $answers,
            source: SubmissionSource::Manual,
            respondentUserId: $user->id,
        ));

        // Increment H21c (Doc #27 §7) — tell the keyer what relevance took away. The result carried this all
        // along and was DISCARDED here, which is what made the loss silent: an irrelevant answer is pruned by
        // Stage 3, is never required-checked, and `passed()` is true, so the page said "Submission recorded."
        // over a document missing half of what was typed.
        //
        // `semantic` is null on the idempotent-replay path, which short-circuits before Stage 3. This channel
        // never reaches it — it sends no `client_submission_uuid`, so Stage 2b cannot fire — but the property
        // is nullable for every channel that does, and reporting nothing is the right answer there anyway: a
        // replay created no submission, so it pruned nothing.
        $pruned = $result->semantic === null ? [] : $report->of($version, $answers, $result->semantic);

        if ($pruned === []) {
            return redirect()
                ->route('forms.submissions.create', $form)
                ->with('toast', ['type' => 'success', 'message' => 'Submission recorded.']);
        }

        // The count rides the toast as well as the banner, the shape H21a's publish warnings settled on: a
        // toast alone cannot list them, and a banner alone is missable on a page that has just reset itself.
        $count = count($pruned);

        return redirect()
            ->route('forms.submissions.create', $form)
            ->with('toast', [
                'type' => 'info',
                'message' => "Submission recorded. {$count} ".($count === 1 ? 'answer was' : 'answers were').' not saved.',
            ])
            ->with('prunedAnswers', $pruned);
    }

    /**
     * The version a manual submission is created against. The policy already requires the form to be
     * published before either route is reached, so a missing version here is an invariant violation (404
     * rather than a silent null into the pipeline).
     */
    private function publishedVersion(Form $form): FormVersion
    {
        abort_if($form->current_published_version_id === null, 404);

        return FormVersion::query()->whereKey($form->current_published_version_id)->firstOrFail();
    }
}
