<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\AuditEvent;
use App\Enums\BillingInterval;
use App\Enums\FeedbackStatus;
use App\Enums\SettingKey;
use App\Enums\TenantStatus;
use App\Exceptions\Admin\SuperAdminException;
use App\Models\Attachment;
use App\Models\Audit;
use App\Models\Concerns\BelongsToTenant;
use App\Models\FeedbackReport;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogPresenter;
use App\Services\Entitlements\EntitlementService;
use App\Services\Settings\PlatformSettings;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\SuperAdminContext;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantIsolation;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one narrow, named super-admin service (RBAC §9 / ADR-0002 §D3): every cross-tenant platform
 * operation routes through here, never through an inline `if ($user->is_super_admin)` branch scattered
 * across controllers. Callers are gated upstream by the `superadmin` + `superadmin.mfa` middleware.
 *
 * Two kinds of operation live here:
 *   - Central-table operations (list/suspend/reactivate tenants) run on the DEFAULT connection —
 *     `tenants` is RLS-exempt (the discriminator table), so the ordinary owner role reaches it with
 *     full privilege. Routing these through the elevated role would need pointless extra GRANTs and is
 *     deliberately NOT done — §9 requires "route through the one service", not "elevate every op".
 *   - Cross-tenant reads of an RLS-protected table (listAllUsers) run inside elevated(), the single
 *     place the `app.is_superadmin_context` GUC is ever opened, transaction-locally on the dedicated
 *     `pgsql_superadmin` connection.
 *
 * Super-admin actions ARE audited (H4), into the AFFECTED tenant's own log (RBAC §9 transparency), by
 * writing the audit UNDER that tenant's context on the default connection — atomically with the status
 * change — so the strict INSERT policy passes with no elevated connection or INSERT bypass. It records the
 * acting super-admin's id (a human acted; `is_system_action` stays false).
 *
 * I5 adds the console's PLATFORM settings write, which is the first operation here that genuinely needs
 * the elevated connection to WRITE rather than to read — see {@see updatePlatformSettings()}.
 *
 * I7a adds the feedback-report console: {@see listFeedback()} reads across tenants through a SELECT-only
 * bypass, while {@see transitionFeedback()} writes through the adopt-context pattern instead — the
 * clearest illustration in this file of the rule its header states, that §9 requires routing through the
 * one service and NOT elevating every operation. Still deferred: the billing console.
 *
 * I7b adds both halves once more, and one genuinely new thing. {@see tenantSnapshot()} is adopt-context —
 * eight RLS-scoped tables reached by one `SET LOCAL` rather than by eight new GRANTs. {@see listPlatformAudits()}
 * is the third elevated read, and the FIRST whose carve-out is NARROWED rather than unrestricted: it uses
 * {@see TenantIsolation::platformRowsBypass()}, whose gate carries `AND tenant_id IS NULL`,
 * because the generic bypass on `audits` would have handed the operator every tenant's complete history.
 * The generalising rule, for the next console surface: a cross-tenant read of a table whose base policy is
 * not `nullable_global` needs a carve-out narrowed to the slice it actually serves.
 */
final class SuperAdminService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Every tenant on the platform, display-ready (central, RLS-exempt table — default connection).
     *
     * @return list<array{id: string, name: string, slug: string, status: ?string}>
     */
    public function listTenants(): array
    {
        /** @var Collection<int, Tenant> $tenants */
        $tenants = Tenant::query()->orderBy('name')->get();

        return array_values($tenants->map(fn (Tenant $t): array => [
            'id' => (string) $t->getKey(),
            'name' => $t->name,
            'slug' => $t->slug,
            'status' => $t->status,
        ])->all());
    }

    /** Suspend a tenant (RBAC §9 console scope: `tenants.status`). Central table — a plain update. */
    public function suspendTenant(Tenant $tenant, User $actor): void
    {
        if ($tenant->status === TenantStatus::Suspended->value) {
            throw SuperAdminException::alreadySuspended();
        }

        $this->changeStatus($tenant, $actor, TenantStatus::Active->value, TenantStatus::Suspended->value);
    }

    /** Reactivate a suspended tenant. Central table — a plain update. */
    public function reactivateTenant(Tenant $tenant, User $actor): void
    {
        if ($tenant->status === TenantStatus::Active->value) {
            throw SuperAdminException::alreadyActive();
        }

        $this->changeStatus($tenant, $actor, TenantStatus::Suspended->value, TenantStatus::Active->value);
    }

    /**
     * Flip the tenant's status and audit it into the AFFECTED tenant's own log (RBAC §9 transparency), in
     * ONE transaction. `tenants` is central/RLS-exempt so the update needs no context; the audit is written
     * UNDER the affected tenant's context so the strict append-only INSERT policy (`tenant_id = ctx`) passes
     * — no elevated connection or INSERT bypass. The affected tenant's context is adopted only for this write
     * and BOTH the DB GUC and the PHP mirror are restored after (via applyLocal of the saved pair), so
     * nothing leaks into the rest of the central-host request — belt-and-braces, since a nested test
     * savepoint would not reset a transaction-local GUC on its own.
     */
    private function changeStatus(Tenant $tenant, User $actor, string $from, string $to): void
    {
        DB::transaction(function () use ($tenant, $actor, $from, $to): void {
            $tenant->forceFill(['status' => $to])->save();

            $savedTenant = TenantContext::currentTenantId();
            $savedUser = TenantContext::currentUserId();
            TenantContext::applyLocal((string) $tenant->getKey());

            try {
                $this->audit->record(
                    AuditEvent::Updated,
                    'tenant',
                    (string) $tenant->getKey(),
                    old: ['status' => $from],
                    new: ['status' => $to],
                    actorId: (string) $actor->getKey(),
                );
            } finally {
                TenantContext::applyLocal($savedTenant, $savedUser);
            }
        });
    }

    /**
     * Assign (or change) a tenant's plan (ADR-0008 §D1/§D10) — admin-assigned, no Cashier. Upserts the
     * tenant's primary `default` subscription and emits a `subscription.updated` audit through the H4
     * {@see AuditLogger} (the FIRST new consumer of that substrate).
     *
     * Uses the H4 adopt-tenant-context pattern (see {@see changeStatus}), NOT an elevated connection: in ONE
     * transaction the affected tenant's context is adopted, so both the strict `subscriptions` write (`tenant_id`
     * auto-fills to ctx, WITH CHECK passes) and the audit INSERT succeed on the app connection with no bypass
     * and no extra GRANT — then the prior context is restored in `finally`. Records the acting super-admin's
     * `user_id` (a human acted; `is_system_action` stays false).
     */
    public function assignPlan(
        Tenant $tenant,
        Plan $plan,
        User $actor,
        BillingInterval $interval = BillingInterval::Monthly,
    ): void {
        DB::transaction(function () use ($tenant, $plan, $actor, $interval): void {
            $savedTenant = TenantContext::currentTenantId();
            $savedUser = TenantContext::currentUserId();
            TenantContext::applyLocal((string) $tenant->getKey());

            try {
                // The tenant's primary subscription (RLS-scoped to the adopted tenant). Upsert it: change the
                // plan on an existing row, or create the row on first assignment.
                $subscription = Subscription::query()->where('name', 'default')->first();

                $old = $subscription === null ? null : [
                    'plan_id' => $subscription->plan_id,
                    'billing_interval' => $subscription->billing_interval->value,
                    'stripe_status' => $subscription->stripe_status,
                ];

                $subscription ??= new Subscription;
                // forceFill: quantity is deliberately omitted — a new row takes the DB default (1) and an
                // existing row keeps its value. tenant_id auto-fills from the adopted context (BelongsToTenant).
                $subscription->forceFill([
                    'plan_id' => $plan->getKey(),
                    'name' => 'default',
                    'stripe_status' => 'active',
                    'billing_interval' => $interval,
                ])->save();

                $this->audit->record(
                    AuditEvent::Updated,
                    'subscription',
                    (string) $subscription->getKey(),
                    old: $old,
                    new: [
                        'plan_id' => $plan->getKey(),
                        'plan_code' => $plan->code->value,
                        'billing_interval' => $interval->value,
                        'stripe_status' => 'active',
                    ],
                    actorId: (string) $actor->getKey(),
                );

                // Drop any memoized plan/usage for this tenant (H5b — the first caller of H5a's forget()
                // seam) so a later read in the same request resolves the newly-assigned plan, not a stale one.
                app(EntitlementService::class)->forget((string) $tenant->getKey());
            } finally {
                TenantContext::applyLocal($savedTenant, $savedUser);
            }
        });
    }

    /**
     * Everything about ONE tenant that only that tenant's own context can answer (I7b) — its entitlement
     * snapshot, its governing subscription, and its owner. The read behind `GET /admin/tenants/{tenant}`.
     *
     * ── WHY THIS IS ADOPT-CONTEXT AND NOT elevated() ────────────────────────────────────────────────────
     * Every table involved (`subscriptions`, `forms`, `attachments`, `tenant_users`, `webhook_endpoints`,
     * `usage_counters`, `legacy_overrides`, `users`) is reachable under the ordinary app role once the
     * affected tenant's context is adopted — the {@see feedbackScreenshot()} argument applied to eight
     * tables instead of one. Elevating instead would have needed eight new GRANTs and eight new bypass
     * policies to read what one `SET LOCAL` already reaches, and §9 requires routing through the one
     * service, not elevating every operation.
     *
     * ⚠️ **THE TRANSACTION IS MANDATORY AND EVERY FAILURE MODE HERE IS SILENT.**
     * {@see TenantContext::applyLocal()} is `SET LOCAL` — outside a transaction it is a no-op, and
     * {@see EntitlementService::snapshot()} then returns **null** (it early-returns off-tenant) while every
     * gauge reads 0 and the owner reads null. Nothing throws. Move any line below out of the closure and
     * the page renders a plausible, wrong tenant.
     *
     * ⚠️ **THE OWNER IS ONLY READABLE FROM INSIDE.** `users` has the join-shape visibility policy
     * ({@see TenantIsolation::usersVisibilitySql()}), whose second disjunct matches
     * every ACTIVE member of the current tenant — so the owner resolves under the adopted context and
     * returns null everywhere else. Null is also the honest answer when the owner is no longer an active
     * member; the presenter says so rather than rendering an em dash.
     *
     * **`forget()` is called on BOTH sides, and the reason for the leading one is not the obvious one.**
     * It is not about leaking into the shared Inertia props: inertia-laravel's middleware calls
     * `Inertia::share()` BEFORE the controller runs, and `EntitlementService::currentPlan()` early-returns
     * on a null tenant id before it ever consults the memo, so that leak is structurally impossible. The
     * real reason is the test process: the service is bound `scoped()`, and only the queue worker calls
     * `forgetScopedInstances()` — so one instance survives every `$this->get()`/`$this->post()` in a Pest
     * test, and a test that renders, mutates, then re-renders would read a memoized plan and assert a stale
     * answer IN THE PASSING DIRECTION. The trailing one is hygiene: the request-scoped service ends holding
     * no foreign tenant's plan, gauges or overrides.
     *
     * @return array{
     *     entitlements: array{plan: array{code: string, name: string}, features: array<string, bool>, quotas: array<string, array{limit: int|null, used: int}>}|null,
     *     subscription: array{plan_id: string, plan_code: ?string, plan_name: ?string, name: string, stripe_status: string, billing_interval: string, assigned_at: ?string}|null,
     *     owner: array{name: string, email: string}|null,
     * }
     */
    public function tenantSnapshot(Tenant $tenant): array
    {
        $tenantId = (string) $tenant->getKey();
        $entitlements = app(EntitlementService::class);

        /** @var array{entitlements: array{plan: array{code: string, name: string}, features: array<string, bool>, quotas: array<string, array{limit: int|null, used: int}>}|null, subscription: array{plan_id: string, plan_code: ?string, plan_name: ?string, name: string, stripe_status: string, billing_interval: string, assigned_at: ?string}|null, owner: array{name: string, email: string}|null} $result */
        $result = DB::transaction(function () use ($tenant, $tenantId, $entitlements): array {
            $savedTenant = TenantContext::currentTenantId();
            $savedUser = TenantContext::currentUserId();
            TenantContext::applyLocal($tenantId);

            try {
                $entitlements->forget($tenantId);

                // The GOVERNING subscription — deliberately the same row EntitlementService::resolvePlan()
                // picks (active, latest), NOT `where('name', 'default')` which is the row assignPlan()
                // upserts. The two selectors can disagree, and this codebase has already been bitten by
                // that; the presenter ships `name` so a divergence is visible instead of silently wrong.
                $subscription = Subscription::query()->active()->with('plan')->latest('created_at')->first();

                $owner = $tenant->owner_user_id === null
                    ? null
                    : User::query()->find($tenant->owner_user_id);

                $snapshot = $entitlements->snapshot();

                return [
                    'entitlements' => $snapshot,
                    'subscription' => $subscription === null ? null : [
                        'plan_id' => (string) $subscription->plan_id,
                        'plan_code' => $subscription->plan?->code->value,
                        'plan_name' => $subscription->plan?->name,
                        'name' => $subscription->name,
                        'stripe_status' => $subscription->stripe_status,
                        'billing_interval' => $subscription->billing_interval->value,
                        'assigned_at' => $subscription->updated_at?->toIso8601String(),
                    ],
                    'owner' => $owner === null ? null : ['name' => $owner->name, 'email' => $owner->email],
                ];
            } finally {
                TenantContext::applyLocal($savedTenant, $savedUser);
                $entitlements->forget($tenantId);
            }
        });

        return $result;
    }

    /**
     * Write one or more PLATFORM (NULL-tenant) settings — the signup toggle and the platform maintenance
     * pair (I5, PRD Feature #10). The console's only write besides tenant status and plan assignment.
     *
     * ── WHY THIS CANNOT BE AN ORDINARY UPDATE ──────────────────────────────────────────────────────────
     * `settings` is nullable_global: the SELECT policy reveals platform rows to everyone, but INSERT and
     * UPDATE stay STRICT (`tenant_id = ctx`) so no tenant connection can author one. The failure mode is
     * the quiet one — a strict UPDATE that matches no row affects ZERO rows and raises nothing, so a naive
     * implementation would look like it worked and change nothing forever. `SettingsRlsTest` pins that
     * no-op; this method is the reason it never happens in production code.
     *
     * So both the settings write AND its audit run inside {@see elevated()}, on the `pgsql_superadmin`
     * connection with the transaction-local `app.is_superadmin_context` GUC open — through the two bypass
     * policies added in 2026_08_06_000002 (settings INSERT/UPDATE) and 2026_08_06_000003 (audits INSERT).
     * The audit row carries `tenant_id = NULL` because there is no tenant context on the central console
     * and {@see BelongsToTenant} only fills what it can see. That makes the row
     * invisible to every tenant's /audit-log, which is the point: the ledger is tenant-scoped and a tenant
     * must not read the operator's platform actions.
     *
     * `updateOrCreate` needs the SELECT grant as well as INSERT/UPDATE — it reads before it writes, and
     * without it every save would insert a duplicate and hit `settings_platform_key_unique`.
     *
     * @param  array<string, bool|string>  $values  key ⇒ new value, already validated by the caller
     */
    public function updatePlatformSettings(array $values, User $actor): void
    {
        if ($values === []) {
            return;
        }

        $this->elevated(function () use ($values, $actor): void {
            $before = $this->platformValues();
            $old = [];

            foreach ($values as $key => $value) {
                $known = SettingKey::tryFrom($key);
                $old[$key] = array_key_exists($key, $before) ? $before[$key] : $known?->default();

                Setting::on(SuperAdminContext::CONNECTION)->updateOrCreate(
                    ['tenant_id' => null, 'key' => $key],
                    ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_by' => $actor->getKey()],
                );
            }

            $this->audit->record(
                AuditEvent::Updated,
                'settings',
                (string) $actor->getKey(),
                old: $old,
                new: $values,
                actorId: (string) $actor->getKey(),
                connection: SuperAdminContext::CONNECTION,
            );
        });

        // The read side is memoized per request; without this the console's own redirect would re-render
        // the page it just changed from a stale map.
        app(PlatformSettings::class)->forget();
    }

    /**
     * The platform rows as they stand, decoded — read on the ELEVATED connection inside {@see elevated()}
     * so the "before" snapshot and the write see the same transaction.
     *
     * `auditable_id` on the emitted row is the acting super-admin's user id, not a settings-row uuid. That
     * is audit spec §1's standing device (used for role grants and for domain events since I2): when the
     * target has no single addressable uuid — here it is a set of platform-wide keys, not one record — the
     * row is keyed on the owning tenant or user, and the payload carries what changed.
     *
     * @return array<string, mixed>
     */
    private function platformValues(): array
    {
        /** @var array<string, mixed> $rows */
        $rows = Setting::on(SuperAdminContext::CONNECTION)
            ->whereNull('tenant_id')
            ->pluck('value', 'key')
            ->map(static fn (mixed $raw): mixed => is_string($raw) ? json_decode($raw, true) : $raw)
            ->all();

        return $rows;
    }

    /**
     * Every user across every tenant, display-ready — the flagship demonstration of the elevated
     * carve-out. The join-shape `users` RLS hides users from non-co-tenants; this reads them through the
     * `superadmin_bypass` policy, visible only while the elevated context is open.
     *
     * @return list<array{id: string, name: string, email: string}>
     */
    public function listAllUsers(): array
    {
        return $this->elevated(function (): array {
            /** @var Collection<int, User> $users */
            $users = User::on(SuperAdminContext::CONNECTION)->orderBy('email')->get();

            return array_values($users->map(fn (User $u): array => [
                'id' => (string) $u->getKey(),
                'name' => $u->name,
                'email' => $u->email,
            ])->all());
        });
    }

    /**
     * One page of feedback reports across EVERY tenant (I7a, PRD Feature #11 / RBAC §9 console scope:
     * "the internal `feedback_reports` (§21) review queue"). The second elevated read after
     * {@see self::listAllUsers()}, and the first that joins two bypassed tables.
     *
     * ── THREE DELIBERATE CHOICES ────────────────────────────────────────────────────────────────────────
     * 1. **Reporter names are fetched with a second explicit query, not `with('user')`.** Eager loading
     *    does propagate the parent's connection (`newRelatedInstance()` sets it), so the elegant version
     *    very probably works — but its failure mode if that ever changes is silent and wrong rather than
     *    loud: the related query would run on the app connection, which on the central host has no tenant
     *    context, so every reporter would simply render as null. An explicit `User::on(CONNECTION)` cannot
     *    fail that way.
     * 2. **Tenant names are resolved OUTSIDE the elevated connection**, on the default one. `tenants` is
     *    central and RLS-exempt, so the ordinary role reads it in full; joining it here would mean granting
     *    the elevated role a privilege it has no other reason to hold.
     * 3. **`orderByDesc('submitted_at')`**, served by the index added in 2026_08_07_000001. `id` would give
     *    recency for free (uuidv7) as the audit viewer exploits — but rows are created per tenant and read
     *    across all of them, and `submitted_at` is the column the operator is actually sorting by.
     *
     * ⚠️ **`withoutGlobalScope($tenantScope)` IS MANDATORY HERE, AND ITS ABSENCE FAILS SILENTLY.** The RLS
     * carve-out is only the DATABASE half. {@see BelongsToTenant} also injects a PHP-side
     * `where tenant_id = <context>` into every query, and with no context — which is exactly the console's
     * situation, since it runs on the central host — that predicate becomes the NO_TENANT_SENTINEL, so the
     * query asks for rows belonging to a tenant that cannot exist. The result is an empty list with no
     * error, on a page whose empty state reads "No feedback yet". {@see listAllUsers()} needs no such call
     * only because `users` has no tenant column and does not use the trait; every future cross-tenant read
     * of a tenant-scoped table does.
     *
     * @param  array{status?: ?string, tenant_id?: ?string}  $filters
     * @return array{rows: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int, per_page: int}}
     */
    public function listFeedback(array $filters, int $perPage, int $page): array
    {
        /** @var array{rows: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int, per_page: int}} $result */
        $result = $this->elevated(function () use ($filters, $perPage, $page): array {
            $query = FeedbackReport::on(SuperAdminContext::CONNECTION)
                ->withoutGlobalScope(FeedbackReport::$tenantScope)
                ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                ->when($filters['tenant_id'] ?? null, fn ($q, $v) => $q->where('tenant_id', $v))
                ->orderByDesc('submitted_at');

            $total = (clone $query)->count();
            $lastPage = max(1, (int) ceil($total / $perPage));
            $current = min(max(1, $page), $lastPage);

            /** @var Collection<int, FeedbackReport> $reports */
            $reports = $query->forPage($current, $perPage)->get();

            $names = $this->reporterNames($reports);

            return [
                'rows' => array_values($reports->map(fn (FeedbackReport $r): array => [
                    'id' => (string) $r->getKey(),
                    'tenant_id' => $r->tenant_id,
                    'route' => $r->route,
                    'remarks' => $r->remarks,
                    'browser_info' => $r->browser_info,
                    'status' => $r->status->value,
                    'submitted_at' => $r->submitted_at->toIso8601String(),
                    'resolved_at' => $r->resolved_at?->toIso8601String(),
                    'reporter' => $names[$r->user_id] ?? null,
                    'resolver' => $r->resolved_by === null ? null : ($names[$r->resolved_by] ?? null),
                    'has_screenshot' => $r->screenshot_attachment_id !== null,
                ])->all()),
                'meta' => [
                    'current_page' => $current,
                    'last_page' => $lastPage,
                    'total' => $total,
                    'per_page' => $perPage,
                ],
            ];
        });

        return $result;
    }

    /**
     * Reporter + resolver display names for one page, keyed by user id — one query through the `users`
     * SELECT bypass (2026_07_05_040100), inside the caller's already-open elevated transaction.
     *
     * @param  Collection<int, FeedbackReport>  $reports
     * @return array<string, array{name: string, email: string}>
     */
    private function reporterNames(Collection $reports): array
    {
        $ids = $reports
            ->flatMap(fn (FeedbackReport $r): array => array_filter([$r->user_id, $r->resolved_by]))
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, User> $users */
        $users = User::on(SuperAdminContext::CONNECTION)->whereIn('id', $ids)->get(['id', 'name', 'email']);

        /** @var array<string, array{name: string, email: string}> $map */
        $map = $users->mapWithKeys(fn (User $u): array => [
            (string) $u->getKey() => ['name' => $u->name, 'email' => $u->email],
        ])->all();

        return $map;
    }

    /**
     * One page of the PLATFORM ledger — `audits` rows with `tenant_id IS NULL` (I7b, PRD Feature #12).
     * The third elevated read, and the only one whose policy is narrowed rather than unrestricted
     * ({@see TenantIsolation::platformRowsBypass()}). A super-admin action against a
     * SPECIFIC tenant is deliberately not here: it lands in that tenant's own ledger (RBAC §9).
     *
     * ⚠️ **`withoutGlobalScope(Audit::$tenantScope)` IS MANDATORY AND ITS ABSENCE FAILS SILENTLY** — the
     * same trap {@see listFeedback()} documents, now on a second surface. {@see BelongsToTenant} injects a
     * PHP-side `where tenant_id = <context ?? NO_TENANT_SENTINEL>` into every query independently of RLS,
     * and `Model::on()` does not remove it. On the console there is no context, so the sentinel matches
     * nothing and the page renders "No platform activity yet" over a populated ledger.
     *
     * ⚠️ **`whereNull('tenant_id')` IS SEPARATELY MANDATORY — it is THREE things and only one of them is
     * belt-and-braces.** Dropping a scope does not add a predicate, so:
     *   1. it is the ORM half of the isolation, keeping the two-layer agreement {@see BelongsToTenant}'s
     *      docblock says this project keeps rather than making the application depend on the database for
     *      a correctness property;
     *   2. it is the query plan. `audits_tenant_select` carries no `TO` clause, so it defaults to PUBLIC
     *      and applies to the elevated role too, and Postgres OR-composes same-command permissive policies.
     *      The planner cannot know at plan time that the tenant branch is unsatisfiable here, so an OR over
     *      two branches costs the ordered index path. A user-level `IS NULL` on a plain column is leakproof,
     *      may be pushed below the security barrier, and restores the backward scan on
     *      `audits_tenant_recent_idx (tenant_id, id)` with `id DESC` satisfied and no sort;
     *   3. it is what makes the surface's contract legible at the call site.
     * "The policy already does that" is exactly the reasoning that would delete it.
     *
     * **Plain arrays, never a paginator and never live models.** `paginate()` would work on this connection
     * inside this transaction, but (a) it does not clamp `page`, and `?page=999` on a three-row ledger
     * returns nothing while `empty_reason` computes `no_rows` — printing "nobody has saved a change on the
     * Platform page" over a non-empty compliance ledger; and (b) the GUC is transaction-local, so ANY model
     * returned out of {@see elevated()} carries dead elevation: a later `load()`/`refresh()` would hit the
     * strict base policies and return nothing, silently. Arrays cannot fail that way.
     *
     * **`orderByDesc('id')`, never `created_at`** — uuidv7 gives recency for free and no index leads with
     * the timestamp. **Actor names via an explicit second query, not `with('user:id,name')`** — reason 1 of
     * {@see listFeedback()}, and here the silent failure would render every row's actor as "Unknown user"
     * on the one screen built to attribute actions to people.
     *
     * @param  array{user_id?: ?string, from?: ?CarbonInterface, to?: ?CarbonInterface}  $filters
     * @return array{rows: list<array<string, mixed>>, actors: list<array{value: string, label: string}>, meta: array{current_page: int, last_page: int, total: int, per_page: int}}
     */
    public function listPlatformAudits(array $filters, int $perPage, int $page): array
    {
        /** @var array{rows: list<array<string, mixed>>, actors: list<array{value: string, label: string}>, meta: array{current_page: int, last_page: int, total: int, per_page: int}} $result */
        $result = $this->elevated(function () use ($filters, $perPage, $page): array {
            $query = Audit::on(SuperAdminContext::CONNECTION)
                ->withoutGlobalScope(Audit::$tenantScope)
                ->whereNull('tenant_id')
                ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
                ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
                ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))
                ->orderByDesc('id');

            $total = (clone $query)->count();
            $lastPage = max(1, (int) ceil($total / $perPage));
            $current = min(max(1, $page), $lastPage);

            /** @var Collection<int, Audit> $rows */
            $rows = $query->forPage($current, $perPage)->get();

            $names = $this->platformActorNames($rows);

            return [
                'rows' => array_values($rows->map(fn (Audit $a): array => [
                    'id' => (string) $a->getKey(),
                    'created_at' => $a->created_at?->toIso8601String(),
                    'event' => $a->event->value,
                    'auditable_type' => $a->auditable_type,
                    'auditable_id' => (string) $a->auditable_id,
                    // Three states, matching AuditLogPresenter::actorLabel(). `is_system` is derived from
                    // a null user_id, NOT from the is_system_action column, which AuditLogger hard-codes
                    // false on every row.
                    'actor' => $a->user_id === null ? 'System' : ($names[$a->user_id] ?? 'Unknown user'),
                    'is_system' => $a->user_id === null,
                    'ip_address' => $a->ip_address,
                    'old_values' => $a->old_values,
                    'new_values' => $a->new_values,
                    'redacted_fields' => $a->redacted_fields ?? [],
                ])->all()),
                'actors' => $this->platformOperators(),
                'meta' => [
                    'current_page' => $current,
                    'last_page' => $lastPage,
                    'total' => $total,
                    'per_page' => $perPage,
                ],
            ];
        });

        return $result;
    }

    /**
     * Actor display names for one page of the platform ledger, keyed by user id — one query through the
     * `users` SELECT bypass, inside the caller's already-open elevated transaction.
     *
     * @param  Collection<int, Audit>  $rows
     * @return array<string, string>
     */
    private function platformActorNames(Collection $rows): array
    {
        /** @var list<string> $ids */
        $ids = $rows->pluck('user_id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, User> $users */
        $users = User::on(SuperAdminContext::CONNECTION)->whereIn('id', $ids)->get(['id', 'name']);

        /** @var array<string, string> $map */
        $map = $users->mapWithKeys(fn (User $u): array => [(string) $u->getKey() => $u->name])->all();

        return $map;
    }

    /**
     * The actor-filter catalog for the platform ledger: the PLATFORM OPERATOR roster, not every user.
     *
     * This is {@see AuditLogPresenter::actorOptions()}'s argument — read the roster,
     * never `SELECT DISTINCT` on `audits` — transplanted to the correct roster for this surface. That
     * method itself does NOT port: it runs `User::query()` under tenant RLS, which on the console has
     * neither a tenant context nor the elevated connection, and would return an empty catalog with no
     * error. {@see listAllUsers()} is equally wrong here for the opposite reason — it returns every user
     * in the deployment, hundreds of options of which none but a super-admin can ever match a row.
     *
     * Accepted limitation, stated so it is not later read as a bug: a person whose `is_super_admin` flag
     * was revoked stops being a filter OPTION while their existing rows still render under their real name
     * ({@see platformActorNames()} looks up by id, unconditionally). Losing a filter entry is recoverable;
     * a dropdown of bare uuids is not.
     *
     * @return list<array{value: string, label: string}>
     */
    private function platformOperators(): array
    {
        /** @var Collection<int, User> $users */
        $users = User::on(SuperAdminContext::CONNECTION)
            ->where('is_super_admin', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return array_values($users->map(fn (User $u): array => [
            'value' => (string) $u->getKey(),
            'label' => $u->name !== '' ? $u->name : 'Unknown user',
        ])->all());
    }

    /**
     * One feedback report by id, read across tenants (I7a). Used by the console's screenshot route, which
     * must learn the report's `tenant_id` before it can adopt that tenant's context to reach the
     * attachment row. Returns null when the id is unknown — the caller 404s.
     *
     * The returned model is bound to the ELEVATED connection and its elevation is already gone (the GUC is
     * transaction-local). Treat it as a read-only value object: never `save()` it.
     *
     * Drops the ORM tenant scope for the reason {@see listFeedback()} spells out — without it this returns
     * null for every id and the console 404s reports that plainly exist.
     */
    public function findFeedback(string $reportId): ?FeedbackReport
    {
        /** @var ?FeedbackReport $report */
        $report = $this->elevated(fn (): ?FeedbackReport => FeedbackReport::on(SuperAdminContext::CONNECTION)
            ->withoutGlobalScope(FeedbackReport::$tenantScope)
            ->find($reportId));

        return $report;
    }

    /**
     * The {@see Attachment} row behind a report's screenshot, read for the console (I7a).
     *
     * **This is why `attachments` needs no super-admin bypass of its own, and must not get one.** The
     * report already names its tenant, so the attachment is reachable through the H4 adopt-context pattern:
     * one transaction with that tenant's context adopted, the ordinary strict SELECT policy passing
     * unaided, context restored in `finally`. A bypass on `attachments` would have handed the operator
     * every respondent's uploaded file and every OCR source scan in the deployment — an enormous
     * widening, bought to read one image the platform is already entitled to see.
     *
     * Returns null when the report has no screenshot, or when the row is soft-deleted (the SoftDeletes
     * global scope, which is what actually stops a deleted screenshot rendering — not the FK).
     */
    public function feedbackScreenshot(FeedbackReport $report): ?Attachment
    {
        if ($report->screenshot_attachment_id === null) {
            return null;
        }

        return DB::transaction(function () use ($report): ?Attachment {
            $savedTenant = TenantContext::currentTenantId();
            $savedUser = TenantContext::currentUserId();
            TenantContext::applyLocal($report->tenant_id);

            try {
                /** @var ?Attachment $attachment */
                $attachment = Attachment::query()->find($report->screenshot_attachment_id);

                return $attachment;
            } finally {
                TenantContext::applyLocal($savedTenant, $savedUser);
            }
        });
    }

    /**
     * Move a feedback report through its lifecycle (I7a) — the console's one write, and the event
     * `docs/audit-compliance-logging-spec.md` §1 registers for this table: *"`updated` (status transitions
     * by platform support) — support-team accountability for how a tenant's feedback report was handled."*
     *
     * ── NO ELEVATED WRITE, BY DESIGN ────────────────────────────────────────────────────────────────────
     * The bypass on `feedback_reports` is SELECT-only. A transition names exactly one report whose tenant
     * is already known, so this uses the H4 adopt-tenant-context pattern ({@see self::changeStatus()}):
     * one transaction on the ORDINARY connection with the affected tenant's context adopted, so both the
     * UPDATE and its audit INSERT pass the strict policies unaided, and the prior context is restored in
     * `finally`. The audit therefore lands in the AFFECTED TENANT'S OWN LEDGER — RBAC §9 transparency: the
     * workspace that reported a problem can see, on its own /audit-log, that the operator handled it. An
     * elevated write would have produced a `tenant_id = NULL` row invisible to exactly the people entitled
     * to see it.
     *
     * ⚠️ **The auditable type is `'feedback_reports'` — PLURAL.** `AuditRedactor::PII` and
     * `AuditableTypes::LABELS` are both keyed that way for this table, unlike the singular `'submission'`
     * and `'attachment'`. Emitting `'feedback_report'` would still write a perfectly valid-looking row,
     * and would silently switch OFF redaction of `remarks`/`browser_info` for it.
     *
     * @throws SuperAdminException on a transition the lifecycle does not offer
     */
    public function transitionFeedback(FeedbackReport $report, FeedbackStatus $to, User $actor): void
    {
        $from = $report->status;

        if (! $from->canTransitionTo($to)) {
            throw SuperAdminException::invalidFeedbackTransition($from, $to);
        }

        DB::transaction(function () use ($report, $from, $to, $actor): void {
            $savedTenant = TenantContext::currentTenantId();
            $savedUser = TenantContext::currentUserId();
            TenantContext::applyLocal($report->tenant_id);

            try {
                /** @var FeedbackReport $row */
                $row = FeedbackReport::query()->findOrFail($report->getKey());

                $old = [
                    'status' => $from->value,
                    'resolved_at' => $row->resolved_at?->toIso8601String(),
                    'resolved_by' => $row->resolved_by,
                ];

                // Re-opening CLEARS the resolution stamp rather than preserving it: those two columns mean
                // "when, and by whom, was this closed", and a re-opened report is not closed. The history
                // is not lost — it is in the audit row this method writes.
                $resolvedAt = $to->isTerminal() ? now() : null;
                $resolvedBy = $to->isTerminal() ? (string) $actor->getKey() : null;

                $row->forceFill([
                    'status' => $to,
                    'resolved_at' => $resolvedAt,
                    'resolved_by' => $resolvedBy,
                ])->save();

                $this->audit->record(
                    AuditEvent::Updated,
                    'feedback_reports',
                    (string) $row->getKey(),
                    old: $old,
                    new: [
                        'status' => $to->value,
                        'resolved_at' => $resolvedAt?->toIso8601String(),
                        'resolved_by' => $resolvedBy,
                    ],
                    actorId: (string) $actor->getKey(),
                );
            } finally {
                TenantContext::applyLocal($savedTenant, $savedUser);
            }
        });
    }

    /**
     * Open the elevated super-admin RLS context for exactly one transaction on the dedicated
     * connection, run the callback, and let the context die on commit/rollback (is_local = true). The
     * ONLY place elevation is ever opened.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function elevated(Closure $callback): mixed
    {
        return DB::connection(SuperAdminContext::CONNECTION)->transaction(function () use ($callback) {
            SuperAdminContext::applyLocal();

            return $callback();
        });
    }
}
