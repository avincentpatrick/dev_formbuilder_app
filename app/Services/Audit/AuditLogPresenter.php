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
use App\Support\Audit\AuditFilterQuery;
use App\Support\Audit\AuditLogger;
use App\Support\Forms\FormHubLink;
use App\Support\Search\ListEmptyReason;
use App\Support\Search\SearchTerms;
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
     * @param  array{auditable_type?: ?string, event?: ?string, user_id?: ?string, from?: ?CarbonInterface, to?: ?CarbonInterface, from_raw?: ?string, to_raw?: ?string, q?: ?SearchTerms}  $filters
     * @return array<string, mixed>
     */
    public function index(User $user, array $filters): array
    {
        $query = Audit::query()
            // ⚠️ `actingAsUser` is deliberately NOT eager-loaded, and adding it would be a defect rather
            // than an optimisation: `actingAsLabel()` renders a fixed string and never reads the operator's
            // name, precisely so the tenant cannot learn it. Loading the relation would put that name in
            // memory on this page for no reader, and would invite the next person to "use what's already
            // there" — which is exactly how the first draft leaked it.
            ->with('user:id,name');

        // ⚠️ THE FILTER CHAIN IS SHARED WITH THE EXPORT (J1e) — see {@see AuditFilterQuery}, which also
        // holds the reason `q` narrows to target and actor and is NEVER a text search over the redacted
        // jsonb diff. Until J1e this page and `AuditExporter::baseQuery()` each spelled the clauses out,
        // while the page built the export's URL from its own filter params: one chain is what makes
        // "I exported what I was looking at" true rather than merely intended.
        $paginator = AuditFilterQuery::apply($query, $filters)
            ->orderByDesc('id') // uuidv7 → recency on audits_tenant_recent_idx (tenant_id, id)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var Collection<int, Audit> $items */
        $items = collect($paginator->items());

        $targets = $this->resolveTargets($items, $user);

        return [
            'data' => $items->map(fn (Audit $a): array => [
                'id' => (string) $a->getKey(),
                'created_at' => $a->created_at?->toIso8601String(),
                'event' => $a->event->value,
                'event_label' => $a->event->label(),
                'target' => $this->target($a, $targets),
                'actor' => $this->actorLabel($a),
                // I11a — null on every ordinary row; a label only when platform staff were driving.
                'acting_as' => $this->actingAsLabel($a),
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
                    // Same rule, one layer down: `SearchTerms::raw()` is the CLAMPED, trimmed string the
                    // server actually acted on, so a 300-character paste re-renders as the 200 characters
                    // that ran. Echoing back the untouched input would put a box on screen disagreeing with
                    // the result set beneath it.
                    'q' => ($filters['q'] ?? null)?->raw(),
                ],
            ],
            // Server-computed, one prop, one meaning. Inferring "filtered to zero" on the client from
            // `data.length === 0 && someFilterSet` breaks the moment the server defaults or clamps
            // anything — it would tell an owner holding 5,000 rows that nothing was ever recorded.
            'empty_reason' => ListEmptyReason::for($items->isNotEmpty(), AuditFilterQuery::hasAnyFilter($filters)),
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
     * The operator behind an impersonated action, or null when there was none (I11a).
     *
     * ⚠️ A FIXED LABEL, NEVER THE NAME — AND THIS IS A POLICY, NOT A CONSEQUENCE OF RLS. The first version
     * of this method read `data_get($audit, 'actingAsUser.name')` and fell back to the label, on the stated
     * grounds that the name "is not reachable anyway": platform staff hold no membership, so the join-shape
     * `users` policy hides them. **That reasoning was wrong, and adversarial review disproved it against a
     * live database.** `TenantIsolation::usersVisibilitySql()` is
     * `id = app.current_user_id OR EXISTS(active tenant_users row in app.current_tenant_id)` — it does not
     * mention `is_super_admin`, and NOTHING in the schema or the app prevents an operator from also holding
     * an active membership of the tenant they are impersonating into. For such an operator the relation
     * resolved and this method returned their real name to every Owner of that workspace.
     *
     * So the disclosure is now decided here rather than left to whether a row happens to be visible. RBAC
     * §9's transparency requirement is that the tenant can see THAT platform staff acted in their
     * workspace; naming an individual employee to every tenant Owner is a different thing, and one that
     * would have varied by an unrelated fact about that employee's memberships.
     *
     * Because the name is never read, `actingAsUser` is deliberately NOT eager-loaded for this page.
     */
    private function actingAsLabel(Audit $audit): ?string
    {
        return $audit->acting_as_user_id === null ? null : 'Platform operator';
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
     * ⚠️ TWO QUERIES, AND THE SPLIT IS THE WHOLE POINT (Increment J2d). The `withTrashed()` query above
     * answers **what is this row's target CALLED**; {@see FormHubLink::pathsFor()} answers **can this reader
     * open it**, which excludes trashed rows because `/forms/{form}` binds through the default scope.
     * Collapsing them back into one — linking every id the label query resolved — would put a live hyperlink
     * to a 404 on exactly the `form.archived` and `form.deleted` rows, the rows a ledger exists for.
     *
     * @param  Collection<int, Audit>  $items
     * @return array<string, array{label: string, url: ?string}>
     */
    private function resolveTargets(Collection $items, User $reader): array
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

        // The reachable subset — absent for a trashed form, so `?? null` below is the whole guard.
        $urls = FormHubLink::pathsFor($reader, $formIds);

        foreach (Form::withTrashed()->whereIn('id', $formIds)->get(['id', 'title']) as $form) {
            $resolved['form:'.$form->getKey()] = [
                'label' => $form->title,
                // The FORM'S OWN HUB (J2d), replacing a hard-coded `/forms`.
                //
                // ⚠️ THE COMMENT THAT STOOD HERE WAS TRUE WHEN WRITTEN AND FALSE BY THE TIME IT WAS READ. It
                // said "/forms/{form} is the BUILDER, which a viewer of the ledger may hold no capacity on"
                // — J2b moved the builder to `/forms/{form}/builder` and made `/forms/{form}` the hub,
                // gated on `viewOverview`, which every reader of this ledger holds (`/audit-log` is
                // `audit_log.view` = Owner/Admin, and both hold `dashboard.org.view`). The argument for
                // `/forms` had quietly stopped applying, which is the whole reason this row was on J2d's
                // list: a stale rationale reads exactly like a live one.
                'url' => $urls[$form->getKey()] ?? null,
            ];
        }

        return $resolved;
    }

    /**
     * The actor filter catalog: this tenant's visible members, named.
     *
     * ── It reads the ROSTER and never touches `audits`, and that is a correctness result, not a shortcut ──
     * The obvious implementation is `SELECT DISTINCT user_id FROM audits`, and it is wrong twice over.
     *
     * It is wrong on COST for the reason {@see AuditableTypes} already records for `auditable_type`:
     * Postgres has no loose index scan, so `audits_tenant_user_idx` cannot serve a DISTINCT — measured on a
     * 400k-row tenant slice, the planner chooses a **Parallel Seq Scan (6,168 buffers, ~35 ms) to return
     * twelve rows**, and that cost grows linearly on a table that is never pruned, on every page render.
     *
     * It is wrong on RESULT for a subtler reason, and this is the half that makes the roster strictly
     * better rather than merely cheaper: `users` RLS is the join-shape policy (self + ACTIVE co-tenant
     * members), so **every id the DISTINCT returns that the roster does not already contain is an id this
     * process cannot resolve to a name** — see {@see self::actorLabel()}. Those ids were being dropped by
     * the `whereIn` anyway. The scan's only actual effect was to NARROW the roster to members who happen to
     * have acted; removing it also lists a member who has not, and "no rows for Alice" is a legitimate and
     * useful answer to ask for.
     *
     * The accepted limitation, stated because it is the honest failure direction for a compliance log: a
     * departed member is not a filter OPTION, while their rows still render (as "Unknown user") and are
     * still exported. Losing a filter is recoverable; a dropdown of bare uuids is not.
     *
     * @return list<array{value: string, label: string}>
     */
    private function actorOptions(): array
    {
        // `pluck` rather than `->get()->map(fn (User $u) => $u->name)`: `User::$name` is one of the
        // documented PHPStan phantoms (the model declares no property for it), and reading it through the
        // model here makes the whole closure's return type unresolvable — four new level-8 errors for a
        // string this query can hand back directly.
        $options = [];

        foreach (User::query()->orderBy('name')->pluck('name', 'id') as $id => $name) {
            $options[] = [
                'value' => (string) $id,
                'label' => is_string($name) && $name !== '' ? $name : 'Unknown user',
            ];
        }

        return $options;
    }
}
