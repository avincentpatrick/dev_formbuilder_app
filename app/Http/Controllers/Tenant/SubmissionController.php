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
 */
final class SubmissionController extends Controller
{
    public function create(Form $form, EncodeFormPresenter $presenter): Response
    {
        return Inertia::render('submissions/Encode', $presenter->present($form, $this->publishedVersion($form)));
    }

    public function store(EncodeSubmissionRequest $request, Form $form, SubmissionPipeline $pipeline): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $pipeline->submit(new SubmissionPayload(
            version: $this->publishedVersion($form),
            answers: $request->answers(),
            source: SubmissionSource::Manual,
            respondentUserId: $user->id,
        ));

        return redirect()
            ->route('forms.submissions.create', $form)
            ->with('toast', ['type' => 'success', 'message' => 'Submission recorded.']);
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
