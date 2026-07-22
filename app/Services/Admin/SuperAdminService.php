<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\AuditEvent;
use App\Enums\TenantStatus;
use App\Exceptions\Admin\SuperAdminException;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\SuperAdminContext;
use App\Support\Tenancy\TenantContext;
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
 * acting super-admin's id (a human acted; `is_system_action` stays false). Still deferred to later
 * increments (tables don't exist yet): platform `settings` / billing / feedback-report consoles, and
 * cross-tenant `audits` SEARCH.
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
