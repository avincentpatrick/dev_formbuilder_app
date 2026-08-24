<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Exceptions\Submissions\SubmissionConflictException;
use App\Http\Controllers\Public\GuestSubmissionController;
use App\Jobs\Submissions\ReapTenantDraftsJob;
use App\Models\Submission;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Increment M11 — the ONE resolve of a `client_submission_uuid`, and the ONE refusal for a uuid that this
 * caller may not write to.
 *
 * ⚠️ THE UNIQUENESS DOMAIN AND THE RESOLVE SCOPE ARE DIFFERENT, AND THE GAP BETWEEN THEM IS WHERE EVERY
 * DEFECT THIS CLASS EXISTS TO CLOSE LIVED. `submissions_tenant_client_uuid_unique` is
 * `(tenant_id, client_submission_uuid)` — tenant-wide. The scope a caller is entitled to resolve WITHIN is
 * narrower: one form and one author. I9b wrote that invariant down in
 * {@see SubmissionDraftService} and implemented it in exactly one of the three places
 * that needed it; {@see SubmissionPipeline} kept filtering on the uuid alone and
 * {@see GuestSubmissionController} on uuid + status. Consolidating the query
 * here is the whole point: a fourth copy cannot drift from the invariant if there is only one copy, and
 * `ClientUuidScopeTest` fails the build if one is written.
 *
 * A uuid that exists in the tenant but does not {@see resolve()} under that scope is neither a resolve nor
 * an insert — it is an explicit {@see assertUnclaimed()} refusal. Left as an insert it violates the
 * tenant-wide index, and a 23505 no recovery arm can classify is an unauthenticated 500 on the guest
 * channels.
 */
final class ClientUuidResolver
{
    /**
     * Resolve the submission this uuid names, WITHIN one form and one author.
     *
     * ⚠️ THE SCOPING IS AUTHORIZATION, NOT TIDINESS (the argument is I9b's, moved here intact). The lookup
     * used to be `where('client_submission_uuid', $uuid)` alone, which is safe only while every caller's
     * form is pinned by something the caller cannot choose — true of the guest channel, where the share
     * token fixes the form and there is no author. It stopped being true the moment an endpoint took the
     * form from the URL (or the share token) and the uuid from the request body: those are two independent,
     * caller-influenced inputs, so a caller authorized on form A could send form B's uuid and have the read
     * — or, via `SubmissionController`'s promote backstop, the WRITE — land on B. RLS bounds the blast
     * radius to the tenant and no further.
     *
     * `$respondentUserId` narrows it again, because a draft genuinely belongs to the person keying it. Null
     * is the guest case and matches guest rows (`respondent_user_id IS NULL`) — hence `whereNull`, because
     * `where(col, null)` compiles to `= NULL` and never matches anything.
     */
    public static function resolve(string $uuid, string $formId, ?string $respondentUserId): ?Submission
    {
        return Submission::query()
            ->where('client_submission_uuid', $uuid)
            ->where('form_id', $formId)
            ->when(
                $respondentUserId === null,
                fn ($q) => $q->whereNull('respondent_user_id'),
                fn ($q) => $q->where('respondent_user_id', $respondentUserId),
            )
            ->first();
    }

    /**
     * Is this uuid already spent anywhere in the tenant?
     *
     * ⚠️ EXISTENCE ONLY — IT RETURNS A BOOL AND MUST KEEP RETURNING A BOOL. The whole defect was a foreign
     * row's `id`, `reference` and `status` being serialized back to whoever replayed its uuid. This answers
     * the one question a caller is entitled to an answer to ("is this identifier still free?") and discloses
     * no column of the row behind it.
     *
     * ⚠️ `withTrashed()` IS LOAD-BEARING, NOT DEFENSIVE. The partial unique index filters on
     * `client_submission_uuid IS NOT NULL` and NOT on `deleted_at IS NULL`, so a soft-delete tombstone keeps
     * the uuid reserved while the {@see SoftDeletes} global scope hides it from
     * every ordinary read — which is precisely why {@see ReapTenantDraftsJob}
     * hard-deletes. Nothing soft-deletes a submission today; probing the tombstones is what stops that from
     * silently becoming a 500 the day something does.
     */
    public static function isClaimed(string $uuid): bool
    {
        return Submission::withTrashed()
            ->where('client_submission_uuid', $uuid)
            ->exists();
    }

    /**
     * Refuse a uuid the tenant has already spent on a row this caller cannot resolve.
     *
     * The throwing half of {@see isClaimed()}, for the callers that let the exception handler build the
     * envelope. A caller that must return its own typed envelope — {@see
     * \App\Http\Controllers\Tenant\SubmissionDraftController::resolveTarget()}, whose route is a web route
     * whose composable cannot read a `back()` redirect — asks `isClaimed()` and reads the message off the
     * same factory, so there is still exactly one wording.
     *
     * @throws SubmissionConflictException when the uuid is already spent outside the caller's scope
     */
    public static function assertUnclaimed(string $uuid): void
    {
        if (self::isClaimed($uuid)) {
            throw SubmissionConflictException::clientUuidClaimed();
        }
    }
}
