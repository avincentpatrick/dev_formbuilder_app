<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\SubmissionSource;
use App\Enums\SyncResultStatus;
use App\Exceptions\Submissions\FormNotAcceptingSubmissionException;
use App\Exceptions\Submissions\SubmissionConflictException;
use App\Exceptions\Submissions\SubmissionException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SyncSubmissionRequest;
use App\Http\Resources\Api\V1\SyncSubmissionResultResource;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\User;
use App\Policies\SubmissionPolicy;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Submissions\SubmissionReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The authenticated offline batch-replay surface (Increment G8b, docs/offline-first-sync-design.md §5) — the
 * Group-B channel that drains a device's outbox as `source = offline_sync`. Each item runs INDEPENDENTLY
 * through the shared {@see SubmissionPipeline} (architecture §4.1 — "batches fan out to N independent pipeline
 * invocations; a partial failure does not roll back the others"), so the response is HTTP 200 carrying a
 * per-item result array. Idempotency (`client_submission_uuid`) makes a duplicate replay a no-op (`duplicate`).
 * The guest PWA replays through the public guest endpoints instead; this surface is for future authenticated
 * encoder clients + integrators.
 *
 * ── THE ROUTE IS NOT RESOURCE-BOUND, SO THE POLICY GATE LIVES HERE (Increment M13) ──────────────────────
 * `routes/api.php` states the standing rule that a resource-bound Group-B route carries an ability + a `can:`
 * policy gate on the bound resource. This route is **not** resource-bound — the form arrives in each ITEM's
 * `form_version_id`, in the BODY — so there was nothing for `can:` to bind to and the rule silently did not
 * apply: `ability:write:submissions` was the whole of the authorization, and any member holding such a token
 * could create submissions against EVERY form in the tenant. RLS bounded that to the tenant and no further.
 *
 * The gate is therefore applied per item, in {@see self::replayOne()}, against the same
 * {@see SubmissionPolicy::create()} the equivalent web route binds with `can:create,Submission,form`. Per
 * ITEM rather than per REQUEST, because a batch may legitimately name several forms and one refusal must not
 * discard its siblings' results — the contract this controller already keeps for every other failure.
 * {@see FormTemplateApiController} is the precedent rather than the exception: `POST /form-templates` carries
 * the identical body-versus-URL asymmetry and resolves it with an in-controller `Gate::forUser()` call.
 *
 * ⚠️ THE ABILITY IS NOT A SUBSTITUTE FOR THE POLICY, AND THE ROLE THAT PROVES IT IS THE **REVIEWER**.
 * `write:submissions` maps to the `submissions.create` permission, which a Viewer does not hold — so a
 * Viewer can never mint such a token at all. A Reviewer can: they hold `submissions.create`, but
 * {@see SubmissionPolicy::create()} additionally requires `forms.edit.any` or EDITOR capacity on the form
 * (the deliberate G10a tightening), and a reviewer grant is reviewer capacity. So a Reviewer is authorized
 * to encode on ZERO forms through the web app and reached EVERY form through here.
 */
final class SyncSubmissionController extends Controller
{
    /**
     * Replay a batch of queued offline submissions (idempotent; per-item results).
     */
    public function store(SyncSubmissionRequest $request, SubmissionPipeline $pipeline): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $results = array_map(
            fn (array $item): array => $this->replayOne($pipeline, $user, $item),
            $request->submissions(),
        );

        return response()->json([
            'data' => SyncSubmissionResultResource::collection($results),
        ]);
    }

    /**
     * Replay one queued submission, translating each pipeline outcome into a per-item result.
     *
     * ⚠️ NEVER THROWS — AND SINCE M13 THAT SENTENCE IS TRUE. It was written as a statement of intent and
     * read afterwards as a measurement (the M11/M12 shape), while THREE outcomes escaped it and aborted the
     * whole request: an unauthorized form (no gate existed at all), a soft-deleted form, and a form that is
     * closed, not yet open, or at its `max_responses` cap. Each is now a per-item `error`. The last is the
     * sharpest, because {@see FormNotAcceptingSubmissionException} is a SIBLING of {@see SubmissionException}
     * rather than a subclass — every exception in that directory is `final class ... extends RuntimeException`
     * — so the `SubmissionException` arm never caught it and `bootstrap/app.php` rendered it as a top-level
     * 403. A device that collected for a month and replayed after the form closed lost every item's result,
     * which is the opposite of the never-block posture docs/offline-first-sync-design.md is built on.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function replayOne(SubmissionPipeline $pipeline, User $user, array $item): array
    {
        $uuid = (string) $item['client_submission_uuid'];

        $version = FormVersion::query()->whereKey($item['form_version_id'])->first();
        if ($version === null) {
            return $this->failure($uuid, SyncResultStatus::Error, 'form_version_not_found', 'The form version does not exist.');
        }

        // `form_versions` carries no soft delete while `forms` does, so a deleted form's versions stay
        // resolvable and outlive it. Report that per item: the pipeline's own `Form::findOrFail()` would
        // otherwise raise a ModelNotFoundException this method does not catch, 404-ing the WHOLE batch after
        // its earlier items had already committed — and re-raising on every retry, so one poisoned row
        // stalled a device's outbox permanently. A code distinct from `form_version_not_found`, because the
        // version genuinely does exist and a client that stores schemas by version id must not be told
        // otherwise; and resolved WITHOUT `withTrashed()`, because a deleted form is not a form somebody may
        // be authorized against.
        $form = Form::query()->whereKey($version->form_id)->first();
        if ($form === null) {
            return $this->failure($uuid, SyncResultStatus::Error, 'form_not_found', 'The form this version belongs to no longer exists.');
        }

        // The per-form authorization the route cannot express (M13 — see the class docblock). `forbidden` is
        // the code bootstrap/app.php already returns for an AuthorizationException on /api/v1, so "you may
        // not do this" reads identically at the item level and at the envelope level rather than obliging a
        // client to learn a second name for it; `insufficient_ability` stays reserved for the TOKEN-scope
        // 403, which is a different refusal about a different subject.
        //
        // ⚠️ IT RUNS BEFORE THE PIPELINE, WHICH IS AN ORDERING DECISION AND NOT AN ACCIDENT OF PLACEMENT.
        // Everything the pipeline would otherwise reach first is a disclosure to someone who may not read
        // this form: Stage 2a's version status, Stage 2c's open/closed window, and — sharpest — Stage 2b's
        // `client_submission_uuid` resolve, which is an existence probe scoped to the form. So a caller who
        // fails here learns only that the version exists, which is exactly what `can:view,form` already
        // tells them on the bound-model routes. It also means an item that is BOTH unauthorized and on a
        // closed form answers `forbidden` rather than `form_closed`, which is the correct precedence.
        //
        // The cost is one indexed primary-key read per item on top of the pipeline's own, bounded by the
        // request's 100-item cap; the grant lookups behind the policy are memoised per user by
        // {@see \App\Services\Authorization\ResourceGrantResolver}.
        if (! Gate::forUser($user)->allows('create', [Submission::class, $form])) {
            return $this->failure($uuid, SyncResultStatus::Error, 'forbidden', 'You are not authorized to create submissions on this form.');
        }

        try {
            $result = $pipeline->submit(new SubmissionPayload(
                version: $version,
                answers: is_array($item['answers']) ? $item['answers'] : [],
                source: SubmissionSource::OfflineSync,
                respondentUserId: $user->id,
                clientSubmissionUuid: $uuid,
                locale: $this->nullableString($item, 'locale'),
                deviceId: $this->nullableString($item, 'device_id'),
                appVersion: $this->nullableString($item, 'app_version'),
            ));

            return [
                'client_submission_uuid' => $uuid,
                'status' => ($result->created ? SyncResultStatus::Created : SyncResultStatus::Duplicate)->value,
                // Increment J2e — `reference` joins the pair, so an offline client can replace the provisional
                // queue tag it has been showing with the real handle the moment a row settles. Without it the
                // outbox would have to re-fetch, or keep quoting a code the tenant cannot find.
                // ⚠️ This shape IS the /api/v1 contract Scramble exports, so `openapi.json` moves with it.
                'submission' => [
                    'id' => $result->submission->id,
                    'reference' => SubmissionReference::format($result->submission->reference),
                    'status' => $result->submission->status->value,
                ],
                'error' => null,
            ];
        } catch (SubmissionValidationException $e) {
            return $this->failure($uuid, SyncResultStatus::Invalid, 'submission_invalid', $e->getMessage(), ['fields' => $e->fieldErrors()]);
        } catch (SubmissionConflictException $e) {
            return $this->failure($uuid, SyncResultStatus::Conflict, 'submission_conflict', $e->getMessage());
        } catch (SubmissionException $e) {
            return $this->failure($uuid, SyncResultStatus::Conflict, 'submission_version_superseded', $e->getMessage());
        } catch (FormNotAcceptingSubmissionException $e) {
            // Schedule (`form_not_open` / `form_closed`) and the transactional response cap
            // (`max_responses_reached`, raised from inside SubmissionFinalizer). LAST arm, so no existing
            // classification moves; the exception's own code and details ride through unchanged, which is the
            // same payload the guest SPA already renders for these three causes.
            return $this->failure($uuid, SyncResultStatus::Error, $e->code(), $e->getMessage(), $e->details());
        }
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @return array<string, mixed>
     */
    private function failure(string $uuid, SyncResultStatus $status, string $code, string $message, ?array $details = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }

        return [
            'client_submission_uuid' => $uuid,
            'status' => $status->value,
            'submission' => null,
            'error' => $error,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function nullableString(array $item, string $key): ?string
    {
        $value = $item[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
