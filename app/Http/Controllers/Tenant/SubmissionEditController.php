<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\SubmissionStatus;
use App\Exceptions\Submissions\SubmissionEditException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Submissions\EditSubmissionAnswersRequest;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\User;
use App\Policies\SubmissionPolicy;
use App\Services\Submissions\EncodeFormPresenter;
use App\Services\Submissions\SubmissionAnswerEditService;
use App\Support\Navigation\CrumbTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Post-submission answer editing (Increment I9c) — the correction surface for a submission that has already
 * been finalized. Authorization is `can:update,submission` on both routes
 * ({@see SubmissionPolicy::update()}, the first consumer of `submissions.edit.any/.own`).
 *
 * The page is `submissions/Encode` — the SAME component the keying and resume surfaces use, reached through
 * {@see EncodeFormPresenter::present()}'s third parameter. That was I9b's advertised seam and this is the
 * caller it was built for: an edit needs every control the encode page has (branching, repeat groups, the
 * geo picker, the relevance engine), and a second inline editor on the detail page would be a parallel
 * implementation of all of it, drifting from the first the day any field type changes.
 *
 * ── ⚠️ IT DOES NOT REQUIRE THE VERSION TO STILL BE PUBLISHED, and that is the difference from I9b ────────
 * {@see SubmissionDraftController::edit()} refuses a draft whose pinned version
 * has been superseded, correctly: promoting it would have to produce a NEW submission against a schema no
 * longer accepting responses. An edit produces no new submission — it corrects a record that already exists,
 * against the schema it was captured under. Requiring `published` here would make almost every real
 * submission uneditable, since one republish supersedes the version every prior response is pinned to, and
 * the feature would appear to work only until the form was next edited.
 */
final class SubmissionEditController extends Controller
{
    /**
     * The edit page, hydrated with the stored answers.
     *
     * State refusals are redirects with a toast rather than a bare 403/404: the caller reached this from a
     * button on the detail page, so the useful response is to put them back there with the reason. A 403
     * would also be a lie — they may well hold `submissions.edit.any`; it is the ROW that is not editable.
     */
    public function edit(Request $request, Submission $submission, EncodeFormPresenter $presenter): Response|RedirectResponse
    {
        /** @var User $editor */
        $editor = $request->user();

        // A draft is editable, just not HERE. Its answers belong to I9b's resume page, which has the autosave,
        // the completeness meter and the resume cursor this surface deliberately lacks. Redirecting rather
        // than refusing means a stale "Edit answers" link on a row that was never finalized still lands
        // somewhere useful.
        if ($submission->status === SubmissionStatus::Draft) {
            return redirect()->route('submissions.resume', $submission);
        }

        if (! in_array($submission->status, SubmissionAnswerEditService::EDITABLE, true)) {
            return redirect()
                ->route('submissions.show', $submission)
                ->with('toast', [
                    'type' => 'error',
                    'message' => SubmissionEditException::illegalState($submission->status)->getMessage(),
                ]);
        }

        [$form, $version] = $this->context($submission);

        /*
         * The five-crumb trail, which `Encode.vue` used to build by branching on `isEditing`. Edit mode is
         * the only one that reaches this route, so there is nothing to infer.
         *
         * ⚠️ `$form` HERE IS NEVER TRASHED — `context()` `firstOrFail()`s it, so a soft-deleted form 404s
         * before this line. The `?Form` branch in `CrumbTrail::form()` is therefore unreachable from this
         * caller, deliberately: the guard belongs on the detail page, where the relation really can be null.
         *
         * Cancel returns to the submission, which is the crumb before the tail — derived rather than
         * spelled, because the template names it twice and could drift in either place.
         */
        $crumbs = CrumbTrail::forms($editor)
            ->form($form)
            ->formSubmissions($form)
            ->submission($submission)
            ->current('Edit answers');

        return Inertia::render('submissions/Encode', [
            ...$presenter->present($form, $version, $submission),
            'crumbs' => $crumbs,
            'cancel_url' => CrumbTrail::exitFrom($crumbs),
        ]);
    }

    /**
     * Apply the correction.
     *
     * {@see SubmissionValidationException} is deliberately NOT caught: the central
     * `bootstrap/app.php` render closure turns it into `back()->withErrors()` for a web request, which is
     * exactly what the page wants — per-field messages against the fields that failed. Catching it here would
     * flatten Stage 3's field errors into one toast. Same posture as {@see SubmissionController::store()}.
     *
     * ⚠️ THE REFUSAL CARRIES AN ERRORS BAG AS WELL AS A TOAST, AND THE BAG IS NOT DECORATION — IT IS THE
     * ONLY THING KEEPING THE EDITOR'S TYPED CORRECTIONS ON THE PAGE. `Encode.vue`'s `submitEdit()` asks
     * `preserveState` whether the errors bag is non-empty; a toast-only refusal answered "no", Inertia re-keyed
     * the component, and a page of corrections was replaced by the stored document with no warning. The toast
     * stays because it is what the editor actually reads — the bag is keyed `baseline`, which is not a rendered
     * field, so it would say nothing on its own.
     *
     * `illegalState()` reaches this same arm and gets the same treatment deliberately. It is a different cause
     * — the row may not be corrected at all, rather than not corrected from THIS page — but discarding a page
     * of typed work is not a better answer for it either, and a remount shows the editor nothing the toast has
     * not already told them.
     */
    public function update(
        EditSubmissionAnswersRequest $request,
        Submission $submission,
        SubmissionAnswerEditService $service,
    ): RedirectResponse {
        /** @var User $editor */
        $editor = $request->user();

        [, $version] = $this->context($submission);

        $wasApproved = $submission->status === SubmissionStatus::Approved;

        try {
            $service->edit($submission, $version, $request->answers(), $editor, true, $request->baseline());
        } catch (SubmissionEditException $e) {
            return back()
                ->withErrors(['baseline' => $e->getMessage()])
                ->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        // ⚠️ THE OUTCOME IS NAMED IN THE TOAST, not only pre-warned on the page. The banner's
        // `demotes_on_save` is a PAGE-LOAD snapshot: a reviewer who approves this submission in another tab
        // while the edit page sits open leaves the editor looking at the plain "Editing a recorded response"
        // wording, and Save then withdraws an approval they were never warned about. The pre-warning is the
        // courtesy; this is the receipt, and it is computed from the row as it was read for THIS request.
        return redirect()
            ->route('submissions.show', $submission)
            ->with('toast', [
                'type' => 'success',
                'message' => $wasApproved
                    ? 'Answers updated. The approval was withdrawn and this response is back under review.'
                    : 'Answers updated.',
            ]);
    }

    /**
     * The submission's form and its OWN pinned version.
     *
     * ⚠️ `form_version_id`, NEVER `current_published_version_id`. This is I9b's version-pin bug in its other
     * clothes: resolve the currently-published version and the page renders schema v2 while the stored answer
     * document is keyed to v1, so a renamed field arrives as an unknown key and a deleted one silently
     * disappears from a record it is still part of. The submission is a historical fact about a specific
     * schema, and every read and write in this controller has to agree about which one.
     *
     * Extracted because both actions need it and inlining it twice is how the two drift apart — and because
     * `update()` has to stay under the thin-controller gate's complexity ceiling.
     *
     * @return array{0: Form, 1: FormVersion}
     */
    private function context(Submission $submission): array
    {
        return [
            Form::query()->whereKey($submission->form_id)->firstOrFail(),
            FormVersion::query()->whereKey($submission->form_version_id)->firstOrFail(),
        ];
    }
}
