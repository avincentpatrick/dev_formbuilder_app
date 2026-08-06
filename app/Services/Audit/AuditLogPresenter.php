<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditEvent;
use App\Models\Audit;
use App\Models\Form;
use App\Models\User;
use App\Services\Submissions\SubmissionInboxPresenter;
use App\Services\Webhooks\WebhookEndpointPresenter;
use App\Support\Audit\AuditableTypes;
use App\Support\Audit\AuditDiff;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read model for the audit-log viewer (Increment I2, PRD Feature #12) — the first surface in the product
 * that lets a human read the ledger H4 has been writing since July. Mirrors
 * {@see SubmissionInboxPresenter}: flat rows + `meta` + a filter catalog + a `can` map, keeping the
 * controller thin.
 *
 * ── Ordering and pagination, both of which are deliberate ─────────────────────────────────────────────
 * `orderByDesc('id')`, NEVER `created_at`: no index leads with that column, while `audits_tenant_recent_idx`
 * is `(tenant_id, id)` and ids are uuidv7, so the primary key gives recency for free. `AuditApiController`
 * made the same choice for the same reason.
 *
 * OFFSET `paginate()` rather than the API's `cursorPaginate()`, and the cost is known and accepted:
 * `MdsPagination` needs `current_page`/`last_page`/`total`/`per_page`, which a cursor structurally cannot
 * supply (the note {@see WebhookEndpointPresenter} records for `webhook_deliveries`,
 * also unbounded). The price is a `count(*)` over the filtered set of a table that is never pruned. A
 * deep-page cap is a later increment's row, not a reason to ship a paginator the primitive cannot drive.
 *
 * ── No default date window ────────────────────────────────────────────────────────────────────────────
 * `from`/`to` default to null. A compliance ledger's value is "what happened, EVER"; a defaulted window
 * would both make the empty state ambiguous and quietly convince an owner that older events are gone.
 */
final class AuditLogPresenter
{
    private const PER_PAGE = 25;

    /**
     * One page of the tenant's ledger, plus the filter catalogs and the export capability.
     *
     * @param  array{auditable_type?: ?string, event?: ?string, user_id?: ?string, from?: ?CarbonInterface, to?: ?CarbonInterface, from_raw?: ?string, to_raw?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function index(User $user, array $filters): array
    {
        $paginator = Audit::query()
            ->with('user:id,name')
            ->when($filters['auditable_type'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($filters['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('id') // uuidv7 → recency on audits_tenant_recent_idx (tenant_id, id)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var Collection<int, Audit> $items */
        $items = collect($paginator->items());

        $targets = $this->resolveTargets($items);

        return [
            'data' => $items->map(fn (Audit $a): array => [
                'id' => (string) $a->getKey(),
                'created_at' => $a->created_at?->toIso8601String(),
                'event' => $a->event->value,
                'event_label' => $a->event->label(),
                'target' => $this->target($a, $targets),
                'actor' => $this->actorLabel($a),
                // Derived from `user_id`, NOT from the `is_system_action` column: AuditLogger hard-codes
                // that column false and exposes no parameter, so it is false on 100% of rows and any UI
                // branching on it would be unreachable. A null actor is the condition that actually varies.
                'is_system' => $a->user_id === null,
                'ip_address' => $a->ip_address,
                'changes' => AuditDiff::rows($a->old_values, $a->new_values, $a->redacted_fields),
                'redacted_fields' => $a->redacted_fields ?? [],
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
            'filters' => [
                'events' => array_map(
                    fn (AuditEvent $e): array => ['value' => $e->value, 'label' => $e->label()],
                    AuditEvent::cases(),
                ),
                'auditable_types' => AuditableTypes::options(),
                'actors' => $this->actorOptions(),
                'applied' => [
                    'auditable_type' => $filters['auditable_type'] ?? null,
                    'event' => $filters['event'] ?? null,
                    'user_id' => $filters['user_id'] ?? null,
                    // The RAW Y-m-d strings, not the parsed instants: these repopulate two date inputs, and
                    // an input whose value disagrees with the filter the server applied is the page
                    // reporting one thing and doing another.
                    'from' => $filters['from_raw'] ?? null,
                    'to' => $filters['to_raw'] ?? null,
                ],
            ],
            // Server-computed, one prop, one meaning. Inferring "filtered to zero" on the client from
            // `data.length === 0 && someFilterSet` breaks the moment the server defaults or clamps
            // anything — it would tell an owner holding 5,000 rows that nothing was ever recorded.
            'empty_reason' => match (true) {
                $items->isNotEmpty() => null,
                $this->hasAnyFilter($filters) => 'no_matches',
                default => 'no_rows',
            },
            // Always true on a page you can reach, and the key exists anyway: it is the seam a future
            // split between reading and exporting the ledger would need. No separate `audit_log.export`
            // permission was coined, because the audience would be identical — `audit_log.view` is already
            // Owner/Admin only — and inventing one means touching the seeder, the catalog and the RBAC doc
            // for zero change in who can do what. AnalyticsController::export sets the precedent.
            'can' => ['export' => $user->can('viewAny', Audit::class)],
        ];
    }

    /**
     * The actor's display name — THREE states, not two.
     *
     * `users` RLS is the join-shape policy (self + ACTIVE co-tenant members), so a removed member's row is
     * INVISIBLE to a colleague reading the log even though `user_id` is a live, non-null uuid: the eager
     * load simply yields null. That is not the same fact as `user_id IS NULL` — one says the platform
     * acted, the other says a human did and we can no longer name them. Rendering both as "System" would
     * misattribute a person's act to the software, on the one screen built to prevent exactly that.
     */
    private function actorLabel(Audit $audit): string
    {
        if ($audit->user_id === null) {
            return 'System';
        }

        $name = data_get($audit, 'user.name');

        return is_string($name) ? $name : 'Unknown user';
    }

    /**
     * The target descriptor for one row.
     *
     * `label`/`url` are legitimately null, and the page is built for that: the ledger is append-only but
     * its targets are not. `resource_grants` rows are HARD-deleted by design — spec §1 calls the audit
     * "the ONLY record that a revoked grant ever existed" — and half the aliases (`form_version`,
     * `subscription`, `tenant`, `settings`) have no addressable page at all. A client-side alias→URL table
     * would encode "which of these is linkable" as frontend knowledge that breaks silently when a route
     * moves, and would hand an owner a 404 dressed up as a feature.
     *
     * @param  array<string, array{label: string, url: ?string}>  $targets
     * @return array{type: string, type_label: string, id: string, label: ?string, url: ?string}
     */
    private function target(Audit $audit, array $targets): array
    {
        $resolved = $targets[$audit->auditable_type.':'.$audit->auditable_id] ?? null;

        return [
            'type' => $audit->auditable_type,
            'type_label' => AuditableTypes::label($audit->auditable_type),
            'id' => (string) $audit->auditable_id,
            'label' => $resolved['label'] ?? null,
            'url' => $resolved['url'] ?? null,
        ];
    }

    /**
     * Resolve the page's targets to names in ONE query per distinct alias — never per row.
     *
     * Only `form` is resolvable today, and only forms have a page worth linking to. `withTrashed()` is
     * deliberate and matches the H24b1 precedent: a soft-deleted form otherwise renders as a bare uuid on
     * the very row recording that it was archived.
     *
     * @param  Collection<int, Audit>  $items
     * @return array<string, array{label: string, url: ?string}>
     */
    private function resolveTargets(Collection $items): array
    {
        $formIds = $items
            ->where('auditable_type', 'form')
            ->pluck('auditable_id')
            ->unique()
            ->all();

        if ($formIds === []) {
            return [];
        }

        $resolved = [];

        foreach (Form::withTrashed()->whereIn('id', $formIds)->get(['id', 'title']) as $form) {
            $resolved['form:'.$form->getKey()] = [
                'label' => $form->title,
                // The forms index, not a detail route: /forms/{form} is the BUILDER, which a viewer of the
                // ledger may hold no capacity on. Linking somewhere they will bounce off is worse than not
                // linking.
                'url' => '/forms',
            ];
        }

        return $resolved;
    }

    /**
     * The actor filter catalog: everyone who has acted in this tenant's ledger, named.
     *
     * NOT a bare `SELECT DISTINCT user_id` — that is a full scan of an unbounded table (Postgres has no
     * loose index scan over `audits_tenant_user_idx`), and worse, half the ids it returns cannot be
     * resolved to a name for the RLS reason in {@see self::actorLabel()}, so the dropdown would contain raw
     * uuids. Sourcing it from the ledger's distinct actors and INNER-joining names keeps every option
     * nameable; the rows of an unnameable actor still render and are simply not filterable, which is the
     * honest failure direction.
     *
     * @return list<array{value: string, label: string}>
     */
    private function actorOptions(): array
    {
        $actorIds = Audit::query()->whereNotNull('user_id')->distinct()->pluck('user_id')->all();

        if ($actorIds === []) {
            return [];
        }

        return array_values(User::query()
            ->whereIn('id', $actorIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u): array => ['value' => (string) $u->getKey(), 'label' => $u->name])
            ->all());
    }

    /** @param  array<string, mixed>  $filters */
    private function hasAnyFilter(array $filters): bool
    {
        foreach (['auditable_type', 'event', 'user_id', 'from', 'to'] as $key) {
            if (($filters[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }
}
