<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Submissions\EncodeSubmissionRequest;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\User;
use App\Policies\SubmissionPolicy;
use App\Services\Submissions\EncodeFormPresenter;
use App\Services\Submissions\PrunedAnswerReport;
use App\Services\Submissions\SubmissionDraftService;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Navigation\CrumbTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manual encoding — the first Submission Pipeline channel to get a UI (Increment F4b). Authorization is the
 * `can:create,<Submission>,form` route middleware ({@see SubmissionPolicy}: permission +
 * per-form collaborator scope + published). This controller is a thin channel adapter: it hands the raw
 * answers to the submissions services (the adapter never validates), and lets the pipeline's
 * {@see SubmissionValidationException} bubble to the central bootstrap/app.php render closure
 * (→ `back()->withErrors()` for the web / 422 for the API).
 *
 * ── TWO CLAIMS IN THIS PARAGRAPH STOPPED BEING TRUE IN I9b, so they are corrected rather than left ──────
 * It no longer "resolves the form's published version" unconditionally: a Submit carrying the uuid of an
 * existing draft is finalized against THAT DRAFT'S PINNED version, because `updateDraft()` writes the
 * payload's version onto the answer row and the currently-published one would leave the two halves
 * disagreeing. And it is no longer a pipeline-only adapter: `store()` routes a resumed draft through
 * {@see SubmissionDraftService::promote()} instead, which is the ONLY thing standing between this channel
 * and a "Submission recorded." toast over a row that stays `draft` forever — `SubmissionPipeline::submit()`
 * returns a same-uuid draft as an idempotent no-op. `GuestSubmissionController` carries the same branch for
 * the same reason; this is that code rather than a variation on it.
 *
 * Increment H21c — `store()` now READS the {@see SubmissionResult} it used to discard, so the keyer is told
 * what relevance pruned ({@see PrunedAnswerReport}, Doc #27 §7's defect (b)). The `/api/v1` submit channel is
 * deliberately left alone: it already returns the persisted document, its caller is a program rather than a
 * person, and touching it would move `openapi.json`.
 */
final class SubmissionController extends Controller
{
    /**
     * ⚠️ THE MODE IS KNOWN HERE BY CONSTRUCTION, which is why the trail is composed in the controller.
     * `Encode.vue` used to re-derive it client-side from `isEditing` / `draft === null` and branch three
     * crumbs on the answer — a conditional that could disagree with the route that rendered it. This route
     * is the "new response" one; there is nothing to infer.
     */
    public function create(Request $request, Form $form, EncodeFormPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        $crumbs = CrumbTrail::forms($user)->form($form)->current('New response');

        return Inertia::render('submissions/Encode', [
            ...$presenter->present($form, $this->publishedVersion($form)),
            'crumbs' => $crumbs,
            'cancel_url' => CrumbTrail::exitFrom($crumbs),
        ]);
    }

    public function store(
        EncodeSubmissionRequest $request,
        Form $form,
        SubmissionPipeline $pipeline,
        SubmissionDraftService $drafts,
        PrunedAnswerReport $report,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $answers = $request->answers();
        $uuid = $request->clientSubmissionUuid();

        // ⚠️ THE DRAFT BRANCH IS NOT OPTIONAL ONCE THIS CHANNEL SENDS A UUID (Increment I9b).
        // `SubmissionPipeline::submit()`'s Stage 2b resolves a same-uuid row and returns it as an idempotent
        // no-op — FOR A DRAFT TOO. Without this branch, Submit on a resumed draft would flash "Submission
        // recorded." while the row stayed `draft` forever: a clean success end to end, no exception, no log
        // line, and the response never finalized. `GuestSubmissionController::store()` is the only thing that
        // has ever prevented it, with exactly this shape; this is that code, not a variation on it.
        // Scoped to THIS form and THIS user, not to the uuid alone. The route gate (`can:create` on the bound
        // {form}) authorizes the FORM; the uuid arrives in the body and is chosen by the caller, so an
        // unscoped resolve would let a member authorized on form A promote a draft belonging to form B — a
        // form they may hold no grant on — because `promote()` is invoked directly and never re-runs
        // `SubmissionPolicy::promote()`. RLS bounds this to the tenant and no further.
        $draft = $uuid === null ? null : Submission::query()
            ->where('client_submission_uuid', $uuid)
            ->where('form_id', $form->id)
            ->where('respondent_user_id', $user->id)
            ->where('status', SubmissionStatus::Draft)
            ->first();

        // ⚠️ AND THE VERSION IS ASYMMETRIC. On the draft branch the payload must carry the DRAFT'S OWN
        // version, never the form's currently-published one: `updateDraft()` writes `form_version_id` and
        // `answers_schema_checksum` onto the answer row FROM THE PAYLOAD, so passing v2 for a v1-pinned draft
        // leaves a head row on v1 with an answer row on v2 — a mismatch nothing rejects, which surfaces months
        // later as a wrong PDF or a wrong export column. The guest channel avoids this only by accident of its
        // version coming from the share token, which happens to be the draft's.
        $version = $draft === null
            ? $this->publishedVersion($form)
            : FormVersion::query()->whereKey($draft->form_version_id)->firstOrFail();

        $payload = new SubmissionPayload(
            version: $version,
            answers: $answers,
            source: SubmissionSource::Manual,
            respondentUserId: $user->id,
            clientSubmissionUuid: $uuid,
            // Increment P3a — consumed only by the draft branch below (saveDraft's update path); the
            // pipeline's submit() ignores both, so setting them here is safe for either branch.
            checkBaseline: $request->claimsBaseline(),
            baseContentChecksum: $request->baseContentChecksum(),
        );

        if ($draft !== null) {
            // Capture the final edits (Stage 1 only), then finalize the SAME row in place via promote()
            // (full Stage 3). The submission keeps its id, so a resume link, an audit row or an outbox entry
            // pointing at the draft still points at the submission.
            $drafts->saveDraft($payload);
            $result = $drafts->promote($draft, actorId: (string) $user->id);
        } else {
            $result = $pipeline->submit($payload);

            // ⚠️ THE RACE BACKSTOP, and without it R1 survives the branch above. The `$draft` lookup and the
            // pipeline's own Stage 2b both run BEFORE any autosave transaction commits, so a Submit clicked
            // ~1.5s after the last keystroke can have both miss: the insert then hits the
            // (tenant_id, client_submission_uuid) partial-unique index, the 23505 catch resolves the row —
            // which is the DRAFT the autosave just committed — and returns it `created: false`. The response
            // is a success toast over a row that stays `draft` forever, exactly the shape the draft branch
            // exists to prevent. Re-checking the RESULT rather than the pre-state is what closes it, because
            // the result is the only value that has seen the committed row.
            if ($result->submission->status === SubmissionStatus::Draft) {
                $result = $drafts->promote($result->submission, actorId: (string) $user->id);
            }
        }

        // Increment H21c (Doc #27 §7) — tell the keyer what relevance took away. The result carried this all
        // along and was DISCARDED here, which is what made the loss silent: an irrelevant answer is pruned by
        // Stage 3, is never required-checked, and `passed()` is true, so the page said "Submission recorded."
        // over a document missing half of what was typed.
        //
        // `semantic` is null on the idempotent-replay path, which short-circuits before Stage 3.
        // ⚠️ AMENDED IN I9b — the old comment here said "This channel never reaches it — it sends no
        // `client_submission_uuid`, so Stage 2b cannot fire". Both halves are now false: the page sends a
        // uuid, so Stage 2b IS live on this channel and a double-clicked Submit resolves to one submission
        // instead of two (a genuine improvement, and the reason the uuid is worth sending at all beyond
        // drafts). Reporting nothing on that path is still right: a replay created no submission, so it
        // pruned nothing. `promote()` returns its own `semantic`, so the draft branch reports normally.
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
