<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\SubmissionSource;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\ReadsGuestShareToken;
use App\Http\Requests\Public\GuestDraftRequest;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Notifications\ResumeLinkNotification;
use App\Services\Submissions\SubmissionDraftService;
use App\Services\Submissions\SubmissionPayload;
use App\Support\Api\ApiErrorResponse;
use App\Support\Guest\GuestShareTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Notification;

/**
 * The guest draft upsert channel (Increment H9b) — the HTTP caller of {@see SubmissionDraftService::saveDraft()}.
 * Tenant context is set from the verified share token; a `source = guest` draft is created on the first save and
 * overwritten in place on every later save (the 409 is suspended for drafts). Each response returns a durable
 * RESUME token scoped to the draft's stable `submissions.id`, so a guest can continue on another device or after
 * clearing local storage. When "Save and finish later" is chosen and the draft carries a contact email, the
 * resume link is also emailed (the H3 queued-mail path).
 *
 * Gated on `feature:save_and_resume` (Starter+) at the route — this is a paid convenience, not a respondent's
 * final answer, so gating it does not breach the never-block rule (final submit stays ungated). The same guest
 * guards as the submit channel run first: `allow_guest_submissions` (403 revocation lever) and a superseded
 * version (409 `form_updated` → the SPA re-mints and re-renders).
 */
final class GuestDraftController extends Controller
{
    use ReadsGuestShareToken;

    /**
     * Create or update a guest draft and return a resume token for it.
     *
     * @unauthenticated
     */
    public function store(
        GuestDraftRequest $request,
        SubmissionDraftService $drafts,
        GuestShareTokenService $tokens,
    ): JsonResponse {
        $token = $this->shareToken($request);

        $form = Form::query()->whereKey($token->formId)->firstOrFail();

        if (! $form->allow_guest_submissions) {
            return ApiErrorResponse::make(403, 'guest_disabled', 'Guest submissions are disabled for this form.');
        }

        // The per-form opt-in (H10) — the tenant-plan `feature:save_and_resume` gate at the route says the
        // TENANT may offer save-and-resume; this says THIS FORM does. A form that never turned it on rejects the
        // draft channel outright (the SPA hides the control off the same flag, so this is defence-in-depth).
        if (! $form->save_and_resume) {
            return ApiErrorResponse::make(403, 'save_resume_disabled', 'Save and resume is not enabled for this form.');
        }

        if ($form->current_published_version_id !== $token->formVersionId) {
            return ApiErrorResponse::make(409, 'form_updated', 'This form has been updated. Please reload and try again.');
        }

        $version = FormVersion::query()->whereKey($token->formVersionId)->firstOrFail();

        // Resolve the tenant's configured draft-expiry window (H10). `tenants` is RLS-exempt, so this reads under
        // the guest tenant context; a null (unset) column falls back to the 30-day default inside the service.
        $ttl = Tenant::query()->whereKey($token->tenantId)->value('draft_ttl_days');

        $result = $drafts->saveDraft(new SubmissionPayload(
            version: $version,
            answers: $request->answers(),
            source: SubmissionSource::Guest,
            respondentUserId: null,
            clientSubmissionUuid: $request->clientSubmissionUuid(),
            locale: $request->localeInput(),
            guestToken: $tokens->fingerprint($token->rawToken),
            guestIp: $request->ip(),
            guestUserAgent: $request->userAgent(),
            guestContactEmail: $request->guestContactEmail(),
            deviceId: $request->deviceId(),
            appVersion: $request->appVersion(),
            draftCurrentStep: $request->draftCurrentStep(),
            ttlDays: is_numeric($ttl) ? (int) $ttl : null,
        ));

        $submission = $result->submission;
        $minted = $tokens->mintResume($token->tenantId, $token->formId, $token->formVersionId, $submission->id);
        $resumeUrl = $this->resumeUrl($token->tenantId, $minted->token);

        if ($request->finishLater() && $submission->guest_contact_email !== null) {
            Notification::route('mail', $submission->guest_contact_email)
                ->notify(new ResumeLinkNotification($form->title, $resumeUrl));
        }

        return response()->json([
            'data' => [
                'id' => $submission->id,
                'completeness_percent' => $submission->completeness_percent,
                'resume_token' => $minted->token,
                'resume_url' => $resumeUrl,
                'expires_at' => gmdate('c', $minted->expiresAt),
            ],
        ], $result->created ? 201 : 200);
    }

    /**
     * Build the absolute resume URL on the tenant's own public-runtime host — the {@see
     * \App\Services\Tenancy\TenantMembershipService} invite-URL precedent (the `tenants`/`domains` tables are
     * RLS-exempt, so this reads under the guest tenant context). The web shell at `/f/resume/{token}` opens the SPA.
     */
    private function resumeUrl(string $tenantId, string $resumeToken): string
    {
        // The tenant always exists here — it was resolved from the verified share token and its RLS context
        // is live — so firstOrFail (not a nullable first) keeps the host resolution total.
        $tenant = Tenant::query()->whereKey($tenantId)->firstOrFail();
        $host = $tenant->domains()->value('domain') ?? $tenant->slug;

        return "https://{$host}/f/resume/{$resumeToken}";
    }
}
