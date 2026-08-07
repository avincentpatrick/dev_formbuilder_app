<?php

declare(strict_types=1);

namespace App\Services\Feedback;

use App\Enums\FeedbackStatus;
use App\Models\FeedbackReport;
use App\Services\Audit\AuditLogPresenter;
use Illuminate\Support\Collection;

/**
 * Read model for the TENANT-side feedback list (Increment I7a, PRD Feature #11) — the surface that finally
 * consumes `feedback.view`, a permission the role catalog has granted Owner and Admin since Phase 0 with
 * no code behind it (RBAC §matrix). Mirrors {@see AuditLogPresenter}: flat rows +
 * `meta` + a filter catalog + a server-computed `empty_reason`.
 *
 * ── WHAT THIS PAGE IS, AND WHAT IT IS NOT ───────────────────────────────────────────────────────────────
 * It is a workspace's record of what its own people reported, so an Owner can see that a colleague already
 * raised a problem and what became of it. It is **read-only, deliberately**: PRD Feature #11 gives the
 * status lifecycle to the platform support team ("visible in aggregate to the platform support team via a
 * dedicated internal view"), and a tenant that could mark its own report Resolved would be resolving it in
 * the operator's queue. No transition verbs are offered here and none are routed.
 *
 * RLS does the scoping — the strict policy on `feedback_reports` means this query cannot see another
 * tenant's rows even if the filter were wrong — so there is no `where('tenant_id', …)` here, exactly as
 * there is none in the audit viewer.
 */
final class FeedbackPresenter
{
    private const PER_PAGE = 25;

    /**
     * One page of this tenant's feedback.
     *
     * @param  array{status?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function index(array $filters): array
    {
        $paginator = FeedbackReport::query()
            ->with(['user:id,name', 'resolver:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('submitted_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var Collection<int, FeedbackReport> $items */
        $items = collect($paginator->items());

        return [
            'data' => $items->map(fn (FeedbackReport $r): array => [
                'id' => (string) $r->getKey(),
                'route' => $r->route,
                'remarks' => $r->remarks,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'submitted_at' => $r->submitted_at->toIso8601String(),
                'resolved_at' => $r->resolved_at?->toIso8601String(),
                // Three states, not two — the `users` join-shape policy hides a REMOVED member's row from
                // a colleague, so a live non-null `user_id` can still resolve to null here. Rendering that
                // as "System" would misattribute a person's report to the software; the page renders
                // "Former member" instead. Same distinction AuditLogPresenter::actorLabel() draws.
                'reporter' => $r->user?->name,
                'has_screenshot' => $r->screenshot_attachment_id !== null,
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
            'filters' => [
                'statuses' => FeedbackStatus::options(),
                'applied' => ['status' => $filters['status'] ?? null],
            ],
            // Server-computed, one prop, one meaning — the I2 rule. "Filtered to zero" inferred on the
            // client breaks the moment the server defaults or clamps anything.
            'empty_reason' => match (true) {
                $items->isNotEmpty() => null,
                ($filters['status'] ?? null) !== null => 'no_matches',
                default => 'no_rows',
            },
        ];
    }
}
