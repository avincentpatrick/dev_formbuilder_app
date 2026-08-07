<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\FormBotChallenge;
use App\Http\Middleware\Concerns\ResolvesGuestForm;
use App\Http\Requests\Public\GuestSubmissionRequest;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Api\ApiErrorResponse;
use App\Support\Guest\GuestChallengeService;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a solved proof-of-work challenge before a guest submission is accepted (Increment I8b) — the
 * "bot-challenge" half of PRD Feature #3's last acceptance criterion.
 *
 * ── ⚠️ ATTACHED TO `submissions.store` ONLY ───────────────────────────────────────────────────────────
 * Not to the schema read (the service worker caches it, and a cached challenge is a broken one), not to
 * attachments (one puzzle per uploaded file is a hostile experience for the field worker photographing
 * three documents), not to draft autosave (which fires repeatedly while someone types), and not to the
 * mint route (it creates nothing, and `throttle:guest-mint` already bounds it per IP). Submit is the only
 * write that creates a finalized response, so it is the only place the cost is proportionate.
 *
 * ── Why middleware rather than a guard inside the controller ──────────────────────────────────────────
 * It runs BEFORE {@see GuestSubmissionRequest} validates anything and before
 * {@see SubmissionPipeline}, so a forged request costs one HMAC instead of a
 * full structural validation. It also covers both arms of `GuestSubmissionController::store()` — the
 * draft-promote path and the fresh-pipeline path — without touching a controller that already sits under
 * `scripts/controller-gate.php`'s 250-line / complexity-10 ceiling, and it reads as one anti-abuse layer
 * with {@see EnforceGuestFormRateLimit} in route order: rate limit (cheapest rejection) → challenge →
 * controller.
 *
 * ── ⚠️ A HEADER, NOT A BODY FIELD ─────────────────────────────────────────────────────────────────────
 * `X-Meridian-Challenge` keeps the solution out of the validated payload, out of the stored answers
 * document, out of `openapi.json`'s request-body schema — and, decisively, out of `OutboxRow`. The offline
 * outbox persists the request body; a body field would mean a Dexie schema version bump and a solution
 * captured hours before it is sent. The client fetches and solves at SEND time instead, inside
 * `ApiClient.submit()`, which is what makes the whole design work offline.
 *
 * ── The two error codes ───────────────────────────────────────────────────────────────────────────────
 * `challenge_required` (nothing was sent) and `challenge_failed` (what was sent is not good) mirror the
 * deliberate `invalid_share_token` / `share_token_expired` split. Both mean the same thing to the client —
 * `error-normalizer.ts` maps them to one `'challenge'` kind, which triggers exactly one re-solve — but
 * they mean very different things to an operator reading logs: the first is a client that has not been
 * told a challenge is needed, the second is a client that failed one.
 */
final class VerifyGuestBotChallenge
{
    use ResolvesGuestForm;

    public const HEADER = 'X-Meridian-Challenge';

    public function __construct(private readonly GuestChallengeService $challenges) {}

    public function handle(Request $request, Closure $next): Response
    {
        $form = $this->guestForm($request);

        // The branch every form created before I8b takes, and every form whose author has not opted in.
        if (! $this->challenges->required($form->bot_challenge ?? FormBotChallenge::Off)) {
            return $next($request);
        }

        $header = $request->header(self::HEADER);

        if (! is_string($header) || $header === '') {
            return ApiErrorResponse::make(
                403,
                'challenge_required',
                'This form requires a spam check before it will accept a response.',
            );
        }

        $solution = $this->decode($header);

        if ($solution === null || ! $this->challenges->verify($solution, (string) $form->getKey())) {
            return ApiErrorResponse::make(
                403,
                'challenge_failed',
                'The spam check could not be verified. Please try again.',
            );
        }

        return $next($request);
    }

    /**
     * base64 → JSON. A malformed header is indistinguishable from a failed one to the caller by design;
     * see the class docblock on why the two CODES differ but the two OUTCOMES do not.
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $header): ?array
    {
        $json = base64_decode($header, strict: true);

        if ($json === false) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
