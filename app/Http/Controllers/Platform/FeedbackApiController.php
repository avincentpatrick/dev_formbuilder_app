<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Enums\AuditEvent;
use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use App\Exceptions\Admin\SuperAdminException;
use App\Http\Controllers\Controller;
use App\Models\PlatformApiToken;
use App\Models\User;
use App\Services\Admin\SuperAdminService;
use App\Services\Feedback\FeedbackConsolePresenter;
use App\Support\Api\ApiErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform support API's feedback endpoints (M175, `R-78f43f26`, `D139`, `D141`; ADR-0023) — what the dev
 * session's `scripts/feedback-pull.php` reads and writes.
 *
 * ── NOTHING NEW CAN BE REACHED FROM HERE ───────────────────────────────────────────────────────────────────
 * Every read goes through {@see SuperAdminService} exactly as the console's does — the list and the single
 * report through the SELECT-only elevated carve-out, the screenshot by borrowing the report's tenant context —
 * and the one write through {@see SuperAdminService::transitionFeedback()}, which adopts the report's tenant
 * context, writes that workspace's own audit row and tells the reporter. The bypass is not widened. Rows are
 * shaped by {@see FeedbackConsolePresenter::decorate()}, so the pull and the console say the same things.
 *
 * Every call also leaves a PLATFORM audit row naming the token (`exported` for a read, `updated` for a move).
 *
 * ⚠️ A REFUSED STEP IS ANSWERED HERE, NOT BY THE EXCEPTION HANDLER. `bootstrap/app.php` registers its
 * catch-all API arm before the {@see SuperAdminException} arm, so left alone a refused transition would come
 * back as a 500 `server_error` — the operator's mistake reported as the platform's.
 */
final class FeedbackApiController extends Controller
{
    private const PER_PAGE_DEFAULT = 50;

    private const PER_PAGE_MAX = 100;

    public function __construct(
        private readonly SuperAdminService $superAdmin,
        private readonly FeedbackConsolePresenter $presenter,
    ) {}

    /** `GET /api/platform/v1/feedback` — filters `status` (comma list of stored values), `type`, `since`. */
    public function index(Request $request): JsonResponse
    {
        $statuses = array_values(array_filter(explode(',', (string) $request->query('status', ''))));
        $request->merge(['statuses' => $statuses]);

        $validated = $request->validate([
            'statuses' => ['array'],
            'statuses.*' => [Rule::enum(FeedbackStatus::class)],
            'type' => ['nullable', Rule::enum(FeedbackType::class)],
            'since' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::PER_PAGE_MAX],
        ]);

        $since = $validated['since'] ?? null;
        $result = $this->superAdmin->listFeedback([
            'statuses' => $statuses,
            'type' => $validated['type'] ?? null,
            'since' => is_string($since) ? Carbon::parse($since) : null,
        ], (int) ($validated['per_page'] ?? self::PER_PAGE_DEFAULT), (int) ($validated['page'] ?? 1));

        $this->audit($request, AuditEvent::Exported, ['endpoint' => 'feedback.index', 'returned' => count($result['rows'])]);

        return response()->json([
            'data' => $this->presenter->decorate($result['rows']),
            'meta' => $result['meta'],
        ]);
    }

    /** `GET /api/platform/v1/feedback/{id}/screenshot` — the bytes, once the virus scan has cleared them. */
    public function screenshot(Request $request, string $feedback): Response
    {
        $report = $this->superAdmin->findFeedback($feedback);
        abort_if($report === null, 404);

        $attachment = $this->superAdmin->feedbackScreenshot($report);
        abort_if($attachment === null, 404);

        if (! $attachment->virus_scan_status->servable()) {
            return ApiErrorResponse::make(409, 'not_yet_available', 'This file is not yet available.');
        }

        $this->audit($request, AuditEvent::Exported, ['endpoint' => 'feedback.screenshot', 'feedback_report_id' => $feedback]);

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_filename,
            ['Content-Type' => $attachment->mime_type ?? 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'],
            'inline',
        );
    }

    /** `PATCH /api/platform/v1/feedback/{id}` — `{status, note?}`; Resolved and Declined tell the reporter. */
    public function update(Request $request, string $feedback): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(FeedbackStatus::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $report = $this->superAdmin->findFeedback($feedback);
        abort_if($report === null, 404);

        /** @var User $actor */
        $actor = $request->user();
        $to = FeedbackStatus::from((string) $validated['status']);
        $note = $validated['note'] ?? null;

        try {
            $written = $this->superAdmin->transitionFeedback($report, $to, $actor, is_string($note) ? $note : null);
        } catch (SuperAdminException $e) {
            return ApiErrorResponse::make(422, 'invalid_transition', $e->getMessage());
        }

        $this->audit($request, AuditEvent::Updated, [
            'endpoint' => 'feedback.update',
            'feedback_report_id' => $feedback,
            'status' => $to->value,
        ]);

        return response()->json(['data' => $this->presenter->decorate([$this->superAdmin->describeFeedback($written)])[0]]);
    }

    /**
     * One platform audit row naming the token behind this call.
     *
     * @param  array<string, mixed>  $detail
     */
    private function audit(Request $request, AuditEvent $event, array $detail): void
    {
        /** @var PlatformApiToken $token */
        $token = $request->attributes->get(PlatformApiToken::class);

        $this->superAdmin->recordPlatformAudit(
            $event,
            'platform_api_tokens',
            (string) $token->getKey(),
            new: $detail,
            actorId: $token->user_id,
        );
    }
}
