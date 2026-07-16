<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\SubmissionSource;
use App\Exceptions\Submissions\SubmissionException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SyncSubmissionRequest;
use App\Http\Resources\Api\V1\SyncSubmissionResultResource;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use Illuminate\Http\JsonResponse;

/**
 * The authenticated offline batch-replay surface (Increment G8b, docs/offline-first-sync-design.md §5) — the
 * Group-B channel that drains a device's outbox as `source = offline_sync`. Each item runs INDEPENDENTLY
 * through the shared {@see SubmissionPipeline} (architecture §4.1 — "batches fan out to N independent pipeline
 * invocations; a partial failure does not roll back the others"), so the response is HTTP 200 carrying a
 * per-item result array. Idempotency (`client_submission_uuid`) makes a duplicate replay a no-op (`duplicate`).
 * The guest PWA replays through the public guest endpoints instead; this surface is for future authenticated
 * encoder clients + integrators.
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
     * Replay one queued submission, translating each pipeline outcome into a per-item result. Never throws:
     * a failure is reported inline so it cannot abort the rest of the batch.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function replayOne(SubmissionPipeline $pipeline, User $user, array $item): array
    {
        $uuid = (string) $item['client_submission_uuid'];

        $version = FormVersion::query()->whereKey($item['form_version_id'])->first();
        if ($version === null) {
            return $this->failure($uuid, 'error', 'form_version_not_found', 'The form version does not exist.');
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
                'status' => $result->created ? 'created' : 'duplicate',
                'submission' => ['id' => $result->submission->id, 'status' => $result->submission->status->value],
                'error' => null,
            ];
        } catch (SubmissionValidationException $e) {
            return $this->failure($uuid, 'invalid', 'submission_invalid', $e->getMessage(), ['fields' => $e->fieldErrors()]);
        } catch (SubmissionException $e) {
            return $this->failure($uuid, 'conflict', 'submission_version_superseded', $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @return array<string, mixed>
     */
    private function failure(string $uuid, string $status, string $code, string $message, ?array $details = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }

        return [
            'client_submission_uuid' => $uuid,
            'status' => $status,
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
