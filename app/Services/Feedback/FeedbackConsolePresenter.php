<?php

declare(strict_types=1);

namespace App\Services\Feedback;

use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use App\Models\Tenant;
use App\Services\Admin\SuperAdminService;
use App\Support\Authorization\AssignableRoles;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read model for the PLATFORM support console (Increment I7a, PRD Feature #11's "dedicated internal view"
 * / RBAC §9 console scope). The cross-tenant read itself belongs to {@see SuperAdminService::listFeedback()}
 * — §9 requires one named service for that — so this class owns only prop shape: the tenant-name join, the
 * filter catalogs, the per-row legal verbs, and the `empty_reason`.
 *
 * ── TENANT NAMES ARE RESOLVED HERE, ON THE ORDINARY CONNECTION ──────────────────────────────────────────
 * `tenants` is central and RLS-exempt: the app role reads it in full with no elevation, so joining it
 * inside the elevated transaction would mean granting `meridian_superadmin` a privilege it otherwise has
 * no reason to hold. One `whereIn` over the page's distinct tenant ids, mapped in PHP.
 *
 * ── EVERY ROW CARRIES ITS OWN ALLOWED VERBS ─────────────────────────────────────────────────────────────
 * Straight from {@see FeedbackStatus::allowedTransitions()}, the same source
 * {@see SuperAdminService::transitionFeedback()} guards with — so the buttons a page offers and the verbs
 * the server accepts cannot drift apart. The `Pages/submissions/Show.vue` precedent computes its map on
 * the client from the status; here it is shipped, because the console renders a whole page of rows in
 * different states at once.
 */
final class FeedbackConsolePresenter
{
    public const PER_PAGE = 25;

    public function __construct(private readonly SuperAdminService $superAdmin) {}

    /**
     * @param  array{status?: ?string, tenant_id?: ?string, type?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function index(array $filters, int $page): array
    {
        $result = $this->superAdmin->listFeedback($filters, self::PER_PAGE, $page);

        $tenants = $this->tenantCatalog();
        $rows = $this->decorate($result['rows'], array_column($tenants, 'label', 'value'));

        return [
            'data' => $rows,
            'meta' => $result['meta'],
            'filters' => [
                'statuses' => FeedbackStatus::options(),
                'tenants' => $tenants,
                'types' => FeedbackType::options(),
                'applied' => [
                    'status' => $filters['status'] ?? null,
                    'tenant_id' => $filters['tenant_id'] ?? null,
                    'type' => $filters['type'] ?? null,
                ],
            ],
            'empty_reason' => match (true) {
                $rows !== [] => null,
                ($filters['status'] ?? null) !== null
                    || ($filters['tenant_id'] ?? null) !== null
                    || ($filters['type'] ?? null) !== null => 'no_matches',
                default => 'no_rows',
            },
        ];
    }

    /**
     * The rows of {@see SuperAdminService::listFeedback()} in the shape the console AND the platform support
     * API both serve (M175): the labels, the workspace name, the role in words and the legal next steps. One
     * shape on purpose — the pull script and the console then describe a report in the same words.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>|null  $tenantNames  id => name; looked up when not given
     * @return list<array<string, mixed>>
     */
    public function decorate(array $rows, ?array $tenantNames = null): array
    {
        $tenantNames ??= array_column($this->tenantCatalog(), 'label', 'value');

        return array_map(function (array $row) use ($tenantNames): array {
            $status = FeedbackStatus::from((string) $row['status']);

            $type = is_string($row['type'] ?? null) ? FeedbackType::tryFrom($row['type']) : null;

            return [
                ...$row,
                'status_label' => $status->label(),
                // Null on a report sent before the kind existed (M175) — no kind is shown, not a guess.
                'type_label' => $type?->label(),
                // The role in words (M175); null on a report sent before the role was recorded.
                'reporter_role_label' => is_string($row['reporter_role'] ?? null)
                    ? AssignableRoles::label($row['reporter_role'])
                    : null,
                'tenant_name' => $tenantNames[(string) $row['tenant_id']] ?? 'Unknown workspace',
                'transitions' => array_map(
                    static fn (FeedbackStatus $to): array => ['value' => $to->value, 'label' => $to->label()],
                    $status->allowedTransitions(),
                ),
            ];
        }, $rows);
    }

    /**
     * Every workspace, for the filter dropdown — the full list rather than only those appearing on this
     * page, so narrowing to a workspace with no reports yields an honest "no matches" instead of an option
     * that silently does not exist.
     *
     * @return list<array{value: string, label: string}>
     */
    private function tenantCatalog(): array
    {
        /** @var Collection<int, Tenant> $tenants */
        $tenants = Tenant::query()->orderBy('name')->get(['id', 'name']);

        return array_values($tenants->map(fn (Tenant $t): array => [
            'value' => (string) $t->getKey(),
            'label' => $t->name,
        ])->all());
    }
}
