<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditEvent;
use App\Services\Admin\SuperAdminService;
use App\Services\Feedback\FeedbackConsolePresenter;
use App\Support\Audit\AuditableTypes;
use App\Support\Audit\AuditDiff;
use Carbon\CarbonInterface;

/**
 * Read model for the PLATFORM audit viewer (Increment I7b, PRD Feature #12 / RBAC §9) — `audits` rows with
 * `tenant_id IS NULL`, the ones I5's platform-settings writes have been depositing since 2026-08-06 with no
 * surface able to read them.
 *
 * Lives beside {@see AuditLogPresenter} rather than in `Services/Admin/` on the
 * {@see FeedbackConsolePresenter} precedent: a console read model belongs with its
 * domain, and `Services/Admin/` is the one-narrow-service folder RBAC §9 points at. Colocation is also what
 * makes every divergence from the tenant viewer visible in a single folder diff.
 *
 * ── FOUR DIVERGENCES FROM THE TENANT VIEWER, EACH A DECISION ────────────────────────────────────────────
 * 1. **No `event` or `auditable_type` filter.** Every NULL-tenant row today is `updated` / `settings`,
 *    because every other audit writer deliberately adopts the affected tenant's context so its row lands in
 *    that tenant's ledger. Shipping {@see AuditEvent::cases()} would offer seven dead options and
 *    {@see AuditableTypes::options()} fourteen — a dropdown whose selections silently return "no matches"
 *    is worse than no dropdown, because it advertises that the platform ledger records forms and
 *    submissions, which it structurally does not. The rule, generalised: **ship only filters whose option
 *    catalog is definitionally live.** `event` still RENDERS on every row (free, and it is the column that
 *    will differentiate rows once I11's impersonation lands); it is just not offered as a filter.
 *    When I11 adds real platform aliases, add a PLATFORM catalog — the analogue of audit spec §1's table,
 *    not a slice of the tenant one, for the reason in divergence 4.
 * 2. **The actor catalog is the super-admin roster**, from {@see SuperAdminService::listPlatformAudits()}.
 *    {@see AuditLogPresenter::actorOptions()} does NOT port — it reads `users` under tenant RLS with no
 *    context on the console and would return an empty catalog with no error.
 * 3. **No export.** `AuditExporter` is built on `TenantContext`, a tenant-scoped `UsageMeter` and a
 *    self-referential audit row that early-returns when there is no tenant — all three would need
 *    rethinking, and the tracker scopes I7b to the viewer. There is deliberately no `can` key: the tenant
 *    presenter ships `can.export` as the read/export split seam, and inventing one here would imply an
 *    export exists.
 * 4. **`target.label` and `target.url` are ALWAYS null.** There is no addressable page for a platform
 *    settings change, and `auditable_id` is the acting super-admin's own user uuid (audit spec §1's device
 *    for a target with no surrogate key), so linking it anywhere would be a lie. The keys are emitted
 *    anyway because {@see AuditDiff} aside, the whole point is that `data[]` is the SAME
 *    `AuditRow` shape the tenant page uses: `AuditChangeModal.vue` is typed against it, and a narrower type
 *    would force a modal fork to save two null keys.
 *
 * ⚠️ **The empty state is a real state, not a bug.** On a fresh install nothing has ever written a
 * NULL-tenant row until somebody saves on `/admin/settings`, so `no_rows` must say so — an operator reading
 * an empty compliance viewer otherwise reasonably concludes it is broken.
 */
final class PlatformAuditPresenter
{
    public const PER_PAGE = 25;

    public function __construct(private readonly SuperAdminService $superAdmin) {}

    /**
     * @param  array{user_id?: ?string, from?: ?CarbonInterface, to?: ?CarbonInterface, from_raw?: ?string, to_raw?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function index(array $filters, int $page): array
    {
        $result = $this->superAdmin->listPlatformAudits($filters, self::PER_PAGE, $page);

        $rows = array_map(function (array $row): array {
            // from(), not tryFrom(): `audits_event_check` is generated from the enum and the model casts
            // the column, so an unknown value could not have been hydrated to reach this point.
            $event = AuditEvent::from((string) $row['event']);
            $type = (string) $row['auditable_type'];
            $redacted = $this->stringList($row['redacted_fields']);

            return [
                'id' => $row['id'],
                'created_at' => $row['created_at'],
                'event' => $event->value,
                'event_label' => $event->label(),
                'target' => [
                    'type' => $type,
                    // label() fails open, which matters more here than on the tenant page: a hard-deleted
                    // tenant's rows are promoted into this slice by the nullOnDelete FK and arrive carrying
                    // aliases this page never expected.
                    'type_label' => AuditableTypes::label($type),
                    'id' => $row['auditable_id'],
                    'label' => null,
                    'url' => null,
                ],
                'actor' => $row['actor'],
                'acting_as' => $row['acting_as'],
                'is_system' => $row['is_system'],
                'ip_address' => $row['ip_address'],
                'changes' => AuditDiff::rows(
                    is_array($row['old_values']) ? $row['old_values'] : null,
                    is_array($row['new_values']) ? $row['new_values'] : null,
                    $redacted,
                ),
                'redacted_fields' => $redacted,
            ];
        }, $result['rows']);

        return [
            'data' => $rows,
            'meta' => $result['meta'],
            'filters' => [
                'actors' => $result['actors'],
                'applied' => [
                    'user_id' => $filters['user_id'] ?? null,
                    // The RAW Y-m-d strings, not the parsed instants: they repopulate two date inputs, and
                    // an input whose value disagrees with the filter the server applied is the page
                    // reporting one thing and doing another. AuditLogPresenter's rule, unchanged.
                    'from' => $filters['from_raw'] ?? null,
                    'to' => $filters['to_raw'] ?? null,
                ],
            ],
            // Server-computed, one prop, one meaning. Inferring "filtered to zero" on the client breaks the
            // moment the server clamps anything — and the service DOES clamp `page`, which is precisely the
            // case that would otherwise print "nothing was ever recorded" over a populated ledger.
            'empty_reason' => match (true) {
                $rows !== [] => null,
                $this->hasAnyFilter($filters) => 'no_matches',
                default => 'no_rows',
            },
        ];
    }

    /**
     * `audits.redacted_fields` as the `list<string>` {@see AuditDiff::rows()} requires. The column is
     * jsonb, so the service hands it over as an untyped array — and a jsonb array that has had a key
     * removed deserialises with non-sequential integer keys, i.e. not a `list` at all. `array_values`
     * is what makes it one; the cast covers a value that is not already a string.
     *
     * @return list<string>|null
     */
    private function stringList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
    }

    /** @param  array<string, mixed>  $filters */
    private function hasAnyFilter(array $filters): bool
    {
        foreach (['user_id', 'from', 'to'] as $key) {
            if (($filters[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }
}
