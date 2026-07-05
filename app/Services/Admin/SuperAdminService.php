<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\TenantStatus;
use App\Exceptions\Admin\SuperAdminException;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\SuperAdminContext;
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
 * Deferred to Phase 1 (their tables don't exist yet): platform `settings` / billing / feedback-report
 * consoles, cross-tenant `audits` search, and Auditable logging of these actions (RBAC §9 requires the
 * log; there is no `audits` table until Phase 1 — see the TODO below, same posture as
 * TenantMembershipService::transferOwnership).
 */
final class SuperAdminService
{
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
    public function suspendTenant(Tenant $tenant): void
    {
        if ($tenant->status === TenantStatus::Suspended->value) {
            throw SuperAdminException::alreadySuspended();
        }

        $tenant->forceFill(['status' => TenantStatus::Suspended->value])->save();
        // TODO(audits, Phase 1): emit an audit entry for this super-admin action (RBAC §9, transparency
        // decision: surfaced in the affected tenant's own Audit Log) once the `audits` table lands.
    }

    /** Reactivate a suspended tenant. Central table — a plain update. */
    public function reactivateTenant(Tenant $tenant): void
    {
        if ($tenant->status === TenantStatus::Active->value) {
            throw SuperAdminException::alreadyActive();
        }

        $tenant->forceFill(['status' => TenantStatus::Active->value])->save();
        // TODO(audits, Phase 1): emit an audit entry (see suspendTenant).
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
