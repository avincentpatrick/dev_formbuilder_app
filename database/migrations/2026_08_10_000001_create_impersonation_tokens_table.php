<?php

declare(strict_types=1);

use App\Services\Admin\ImpersonationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cross-host handoff for super-admin impersonation — Increment I11b, `multi-tenancy-rbac-design.md`
 * §9's resolved decision 1. One row per attempt; consumed once, then dead.
 *
 * ── WHY A TABLE AT ALL, WHEN A SESSION FLIP WOULD BE ONE LINE ───────────────────────────────────────────
 * `SESSION_DOMAIN` is null, so the session cookie is HOST-ONLY. A super-admin authenticated on the central
 * host has no session on `acme.…` — `routes/admin.php`:18 and `routes/tenant.php`:75-81 both record the
 * correction, and I10e's console e2e needs a second browser context for exactly this reason. So there is no
 * session to flip: the operator must ARRIVE on the tenant host carrying proof, and this table is that proof.
 *
 * stancl/tenancy ships a `UserImpersonation` feature of precisely this shape. It is commented out in
 * `config/tenancy.php` and is not adoptable: it calls `loginUsingId()` and stores nothing about who the
 * operator was, which is the exact failure `audits.acting_as_user_id` (I11a) exists to prevent, and its
 * token column is a plaintext `Str::random(128)` with no RLS shape.
 *
 * ── HASHED AT REST, LIKE EVERY OTHER BEARER STRING IN THIS SCHEMA ───────────────────────────────────────
 * `token_hash` stores `hash('sha256', $plain)`; the plaintext exists only in the URL handed to the browser
 * and is never persisted. Same posture as `tenant_users.invite_token` (§7) and `GuestShareTokenService`. A
 * leaked database backup therefore yields no usable token, which matters more here than for an invite: this
 * one authenticates as an existing member rather than offering a seat.
 *
 * ── SINGLE-USE **AND** SHORT-TTL, WHICH ARE NOT THE SAME GUARANTEE ──────────────────────────────────────
 * `consumed_at` closes replay; `expires_at` closes the window in which a token intercepted in transit (a
 * proxy log, a referrer header, shoulder-surfing the address bar) is worth anything. Sixty seconds is a
 * HANDOFF, not a credential: the only legitimate holder redirects into it immediately. Neither column is
 * load-bearing alone — a single-use token with no expiry stays valid in a log file forever, and a TTL'd one
 * without `consumed_at` can be used twice inside its minute.
 *
 * Rows are deliberately NOT deleted on consume. A consumed row is the forensic record of a handoff that
 * actually happened, and it pairs with the `impersonation_started` audit row rather than duplicating it: the
 * audit says who did what, this says which grant authorised it and from which IP.
 *
 * ── STRICT RLS, AND THE CONSUME PATH IS WHY IT WORKS ────────────────────────────────────────────────────
 * Strict (`tenant_id = ctx`) on every command. Both ends satisfy it without a bypass:
 *  · the MINT runs on the console, which has no ambient tenant context — but `SuperAdminService` already
 *    owns the idiom for that (`tenantSnapshot()`: adopt the tenant inside a `DB::transaction` with
 *    `TenantContext::applyLocal()` and restore in a `finally`). No elevated connection is needed, and that
 *    matters: `pgsql_superadmin` exists to read across tenants, and reaching for it here would widen a
 *    surface that has a narrower correct answer.
 *  · the CONSUME runs on the tenant subdomain, where `InitializeTenancyBySubdomain` and
 *    `EstablishTenantDatabaseContext` have already set the GUC — BEFORE `auth`, which is the same property
 *    that makes the strict-RLS invite row visible to a not-yet-member (`routes/tenant.php`'s invitation
 *    group). A token minted for another tenant simply does not resolve.
 *
 * ⚠️ Strict RLS means "this tenant's rows", NOT "rows about me". Any member of the tenant would see these
 * rows given raw SQL, exactly as they would see `notifications`. That is acceptable here and it is not an
 * oversight: every column is either a hash, an id the tenant already learns from its own `/audit-log`, or a
 * timestamp — and §9's whole posture is that the affected tenant is TOLD about platform access, not shielded
 * from knowing. There is no app surface that reads this table for a tenant user regardless.
 *
 * ── NO `acting_as`-STYLE CHECK PAIRING operator AND target ──────────────────────────────────────────────
 * "Never yourself" is enforced in {@see ImpersonationService} at mint AND re-checked at
 * consume, and `audits_acting_as_not_self_check` catches the audit row that would result. A CHECK here as
 * well was considered and rejected: both FKs are `cascadeOnDelete`, so unlike the audits column there is no
 * NULL state to reason about, and a third enforcement point would be a third place to keep in step for a
 * rule the ledger already refuses to record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // `users` is a global (tenant-free) table, so single-column FKs are correct here — ADR-0002 §D5's
            // composite `(tenant_id, …)` form applies to tenant-scoped parents. Matches `notifications`.
            //
            // cascadeOnDelete on BOTH, which is the opposite of the choice `audits` makes and deliberately
            // so. A ledger row must survive its actor (nullOnDelete, so history is not rewritten); a
            // 60-second grant must not. A token whose operator or target no longer exists is unusable by
            // construction, and keeping it would leave a row asserting a relationship between two accounts
            // one of which is gone.
            $table->foreignUuid('operator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('target_user_id')->constrained('users')->cascadeOnDelete();

            // sha256 hex. Unique GLOBALLY rather than per tenant: the consume path looks the token up by
            // hash within its tenant, but a collision across tenants would be a second row a future
            // cross-tenant read could match, and there is no reason to permit one.
            $table->char('token_hash', 64)->unique();

            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable(); // NULL = never used
            $table->string('ip_address', 45)->nullable();   // the OPERATOR's IP at mint (INET6 max length)
            $table->timestampsTz();

            // tenant_id LEADS (ADR-0002 §D1). Serves the only query the app makes besides the unique
            // lookup: "unconsumed, unexpired grants in this workspace", which is what an operator-facing
            // "already in flight" check and any future console list would ask.
            $table->index(['tenant_id', 'consumed_at', 'expires_at'], 'impersonation_tokens_tenant_live_idx');
        });

        withTenantIsolation('impersonation_tokens'); // strict — see the docblock for why both ends satisfy it
    }

    public function down(): void
    {
        // Dropping the table drops its policies and indexes with it.
        Schema::dropIfExists('impersonation_tokens');
    }
};
