<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\FormVersionStatus;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Exceptions\Submissions\FormNotAcceptingSubmissionException;
use App\Exceptions\Submissions\SubmissionConflictException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\GuestDraftController;
use App\Http\Requests\Submissions\EncodeDraftRequest;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\SubmissionPolicy;
use App\Services\Submissions\EncodeFormPresenter;
use App\Services\Submissions\SubmissionDraftService;
use App\Services\Submissions\SubmissionPayload;
use App\Support\Api\ApiErrorResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manual encoding's save-as-draft channel (Increment I9b) — the authenticated twin of
 * {@see GuestDraftController}, closing PRD Feature #7's last gap: a staff member
 * transcribing a paper form had no way to stop and come back.
 *
 * ── IT IS UNGATED, TWICE OVER, AND BOTH ARE LOAD-BEARING ────────────────────────────────────────────────
 * The guest channel carries `feature:save_and_resume` at the route AND checks `$form->save_and_resume`.
 * Neither applies here.
 *
 * The PLAN gate does not, because `docs/ux/form-filling-ux-flow.md` pins manual encoding's autosave as
 * "available on every tier … the one channel never gated behind a paid plan". Losing an hour of a keyer's
 * transcription is data loss, not a missing convenience, and a Free-tier tenant loses it just as badly.
 *
 * The PER-FORM flag does not, because it answers a different question. `forms.save_and_resume` is the
 * author's decision about whether RESPONDENTS may park a half-finished response and return by emailed link —
 * a decision about the public runtime's affordances. Whether staff may avoid retyping is not the same
 * question and was never the author's to make.
 *
 * ── IT ANSWERS IN JSON, NOT INERTIA ─────────────────────────────────────────────────────────────────────
 * `store()` is called by a plain `fetch` from `useServerAutosave`, never by an Inertia visit, so the two
 * failure exceptions are caught HERE and rendered as typed JSON rather than left to `bootstrap/app.php`'s
 * web arms, which return a `back()` redirect the composable cannot read. This is the local-catch shape
 * `SubmissionReviewController` already uses. The codes are the contract the composable keys its
 * stop-permanently behaviour on.
 */
final class SubmissionDraftController extends Controller
{
    /**
     * Create or overwrite the current user's draft for this form.
     *
     * Authorization is `can:create,<Submission>,form` ({@see SubmissionPolicy::create()}) — saving a draft is
     * the same privilege as submitting one, since the draft is a `submissions` row and promoting it is the
     * submission. There is no separate permission and none is seeded.
     */
    public function store(
        EncodeDraftRequest $request,
        Form $form,
        SubmissionDraftService $drafts,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        // The existing draft and the version to write against, or a typed refusal. Extracted because the
        // three cases it decides between (resume mine / refuse a foreign uuid / start a new one) are the
        // controller's real logic, and inlining them pushed store() past the thin-controller gate.
        $resolved = $this->resolveTarget($request, $form, $user);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$version] = $resolved;

        // The tenant's configured draft window; `tenants` is RLS-exempt so this reads under tenant context,
        // and a null column falls back to SubmissionDraftService::DRAFT_TTL_DAYS. Same read the guest
        // controller does — a keyer's draft and a respondent's expire on the same tenant policy.
        $ttl = Tenant::query()->whereKey(TenantContext::currentTenantId())->value('draft_ttl_days');

        try {
            $result = $drafts->saveDraft(new SubmissionPayload(
                version: $version,
                answers: $request->answers(),
                source: SubmissionSource::Manual,
                respondentUserId: $user->id,
                clientSubmissionUuid: $request->clientSubmissionUuid(),
                draftCurrentStep: $request->draftCurrentStep(),
                ttlDays: is_numeric($ttl) ? (int) $ttl : null,
            ));
        } catch (SubmissionConflictException $e) {
            // The two-tab case: this uuid's row was promoted between the last tick and this one, so
            // updateDraft()'s lock re-assert refused. Terminal for the composable — it stops rather than
            // retrying forever against a row that will never be a draft again.
            return ApiErrorResponse::make(409, $e->code(), $e->getMessage());
        } catch (SubmissionValidationException $e) {
            // Structural (Stage 1) only — saveDraft() skips Stage 3 by design, so a half-filled document
            // never 422s here. An unknown answer key or a type mismatch still does, and the composable
            // surfaces it inline and keeps trying, because the next edit may fix it. The envelope is
            // byte-identical to bootstrap/app.php's API arm, so one client shape reads both.
            return ApiErrorResponse::make(422, 'submission_invalid', $e->getMessage(), ['fields' => $e->fieldErrors()]);
        } catch (FormNotAcceptingSubmissionException $e) {
            // Only reachable on the CREATE branch, which runs assertCanStart(): a form that closed before
            // the keyer's first save. An EXISTING draft keeps saving under H12a's grace window.
            return ApiErrorResponse::make($e->status(), $e->code(), $e->getMessage(), $e->details());
        }

        $submission = $result->submission;

        return response()->json([
            'data' => [
                'id' => $submission->id,
                'completeness_percent' => $submission->completeness_percent,
                'last_saved_at' => $submission->last_saved_at?->toIso8601String(),
                'expires_at' => $submission->draft_expires_at?->toIso8601String(),
            ],
        ], $result->created ? 201 : 200);
    }

    /**
     * Resume a saved draft: the encode page, hydrated with the stored answers.
     *
     * Gated on `can:promote,submission` and NOT on a new ability — {@see SubmissionPolicy::promote()}'s own
     * docblock already covers "an OCR-staged or manually-saved draft", and "may resume this" and "may
     * finalize this" are the same claim about the same row. Minting a third ability for one route would put
     * two answers in the tree for one question.
     */
    public function edit(Submission $submission, EncodeFormPresenter $presenter): Response|RedirectResponse
    {
        // A state guard, not an authorization one — hence 404 rather than 403. A finalized submission has no
        // resume surface at all; it is not that this user may not resume it.
        abort_unless($submission->status === SubmissionStatus::Draft, 404);

        $form = Form::query()->whereKey($submission->form_id)->firstOrFail();

        // ⚠️ THE DRAFT'S OWN VERSION, NEVER `current_published_version_id`. A draft is pinned to the schema it
        // was keyed against, and the author may have republished since. Presenting the current version would
        // render one schema, save answers keyed to it, and then hit promote()'s Stage-2a re-assert at Submit
        // — a guaranteed exception after the keyer has spent ten minutes. Resolving the pinned version turns
        // that certain failure into an honest message AT OPEN, which is the only point it is still cheap.
        $version = FormVersion::query()->whereKey($submission->form_version_id)->firstOrFail();

        if ($version->status !== FormVersionStatus::Published) {
            return redirect()
                ->route('submissions.show', $submission)
                ->with('toast', [
                    'type' => 'error',
                    'message' => 'This form has been updated since the draft was saved, so the draft can no longer be submitted.',
                ]);
        }

        return Inertia::render('submissions/Encode', $presenter->present($form, $version, $submission));
    }

    /**
     * Decide what this autosave writes against: the caller's own in-progress draft, or a fresh one.
     *
     * ⚠️ THE EXISTING DRAFT'S VERSION WINS, and the first draft of this controller got that wrong.
     * `SubmissionDraftService::updateDraft()` writes `form_version_id` and `answers_schema_checksum` onto the
     * answer row FROM THE PAYLOAD. Resolve the currently-published version unconditionally and every tick
     * after a republish writes a head row on v1 with an answer row claiming v2, normalises against a schema
     * the draft was never keyed against, and turns a renamed field into a 422 the keyer is told is retryable.
     *
     * ⚠️ THE LOOKUP IS SCOPED TO THIS FORM AND THIS USER. On an authenticated endpoint the form comes from
     * the URL and the uuid from the body — two independent, caller-influenced inputs — so an unscoped resolve
     * lets a member authorized on form A write into form B's draft. See
     * {@see SubmissionDraftService::findByClientUuid()} for the same argument at the service layer.
     *
     * ⚠️ AND THE UNIQUENESS DOMAIN IS THE TENANT: `submissions_tenant_client_uuid_unique` is
     * `(tenant_id, client_submission_uuid)`. So "this uuid is not mine" can never mean "create a new draft" —
     * the INSERT would violate that index and surface as a 500. It is an explicit conflict, and the two causes
     * are reported separately because the client renders them differently: mine-but-already-submitted is the
     * two-tab race, anything else was never mine to write to.
     *
     * @return array{0: FormVersion}|JsonResponse the version to write against, or the refusal to return
     */
    private function resolveTarget(EncodeDraftRequest $request, Form $form, User $user): array|JsonResponse
    {
        $uuid = $request->clientSubmissionUuid();

        $existing = Submission::query()
            ->where('client_submission_uuid', $uuid)
            ->where('form_id', $form->id)
            ->where('respondent_user_id', $user->id)
            ->where('status', SubmissionStatus::Draft)
            ->first();

        if ($existing !== null) {
            $version = FormVersion::query()->whereKey($existing->form_version_id)->firstOrFail();

            // A superseded pin is terminal, and it is reported the way the GUEST channel reports it rather
            // than as a generic failure: `saveDraft()` would throw `SubmissionException::versionNotPublished()`,
            // which this controller does not catch and whose global web arm is a redirect the autosave
            // `fetch` cannot read. A typed 409 is what lets the composable stop permanently and say why.
            return $version->status === FormVersionStatus::Published
                ? [$version]
                : ApiErrorResponse::make(
                    409,
                    'form_updated',
                    'This form has been updated since the draft was saved, so the draft can no longer be edited.',
                );
        }

        $claimed = Submission::query()
            ->where('client_submission_uuid', $uuid)
            ->first(['id', 'form_id', 'respondent_user_id']);

        if ($claimed === null) {
            return [$this->publishedVersion($form)];
        }

        return $claimed->form_id === $form->id && $claimed->respondent_user_id === $user->id
            ? ApiErrorResponse::make(
                409,
                'draft_already_finalized',
                'This draft has already been submitted and can no longer be saved as a draft.',
            )
            : ApiErrorResponse::make(
                409,
                'submission_conflict',
                'This draft identifier already belongs to another response.',
            );
    }

    /**
     * The version a NEW draft is created against. The policy already requires the form to be published, so a
     * missing version is an invariant violation. Mirrors `SubmissionController::publishedVersion()`.
     */
    private function publishedVersion(Form $form): FormVersion
    {
        abort_if($form->current_published_version_id === null, 404);

        return FormVersion::query()->whereKey($form->current_published_version_id)->firstOrFail();
    }
}
