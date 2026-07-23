<?php

declare(strict_types=1);

namespace App\Jobs\Submissions;

use App\Enums\QueueName;
use App\Enums\SubmissionStatus;
use App\Jobs\Maintenance\ReapExpiredDraftsJob;
use App\Jobs\TenantAwareJob;
use App\Models\Submission;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Carbon;

/**
 * The per-tenant child of {@see ReapExpiredDraftsJob} (Increment H9b) — one dispatched per active tenant, so
 * cleaning up expired drafts is the §D3 cross-tenant fan-out shape (never a single-tenant `applyLocal` job,
 * which would silently no-op on every tenant but one). Runs inside the base's transaction under the tenant's
 * adopted context, so the DELETE below is RLS-scoped to that tenant.
 *
 * HARD-deletes (`forceDelete()`, not `delete()`): `Submission` uses SoftDeletes, but the partial-unique index
 * `submissions_tenant_client_uuid_unique` filters only on `client_submission_uuid IS NOT NULL` — a soft-delete
 * tombstone would keep the uuid reserved and a later re-use of the same uuid would collide (23505). A hard
 * delete frees the uuid; the `submission_answers` row cascades via its `ON DELETE CASCADE` FK (a draft has no
 * typed-index/geo rows — those are projected only at promotion).
 */
#[Queue(QueueName::ScheduledMaintenance)]
final class ReapTenantDraftsJob extends TenantAwareJob
{
    public function __construct(public readonly string $tenantId) {}

    protected function handleForTenant(): void
    {
        Submission::query()
            ->where('status', SubmissionStatus::Draft)
            ->where('draft_expires_at', '<', Carbon::now())
            ->forceDelete();
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function failureContext(): array
    {
        return ['queue' => QueueName::ScheduledMaintenance->value];
    }
}
