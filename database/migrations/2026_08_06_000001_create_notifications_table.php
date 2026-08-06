<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Models\Notification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per in-app notification delivered to a user's notification center (data-dictionary §22, PRD
 * Feature #13, Increment I3). Email delivery of the same event is recorded by `emailed_at` on this row
 * rather than by a second row — one notification, one record, two possible channels.
 *
 * ── STRICT RLS, even though every row carries a `user_id` ───────────────────────────────────────────────
 * The obvious move — a table whose rows are "this person's notifications" — is the `belongs_to_user`
 * isolation variant. It is wrong here for the reason `saved_report_views` records at length:
 * `TenantIsolation::belongsToUserSql()` emits policies keyed ONLY on
 * `user_id = current_setting('app.current_user_id')`, **with no tenant predicate at all**. For a consultant
 * who belongs to two tenants that is a cross-tenant leak — and §23 explicitly contemplates that person,
 * since it lets them hold different preferences per tenant. `scripts/migration-lint.php` cannot catch the
 * mistake: it checks only that SOME `withTenantIsolation()` call exists, never which variant. Hence this
 * paragraph.
 *
 * Under `strict`, an Owner's `SELECT * FROM notifications` returns every member's rows. "Mine" is therefore
 * an APPLICATION predicate — {@see Notification::scopeForUser()}, applied on every read path —
 * not something the database is doing for you.
 *
 * ── `notifications` IS ALSO LARAVEL'S OWN TABLE NAME ───────────────────────────────────────────────────
 * `App\Models\User` uses `Illuminate\Notifications\Notifiable`, whose `notifications()` /
 * `unreadNotifications()` are a `morphMany` onto `Illuminate\Notifications\DatabaseNotification` — which
 * declares `$table = 'notifications'` and expects `notifiable_type` / `notifiable_id`. This table has
 * `user_id` instead, and §22 names it, so it keeps the name.
 *
 * The consequence is a failure mode that gets WORSE with this migration, not better: before it, those
 * framework accessors threw "relation notifications does not exist", which nobody could misread; after it
 * they throw `SQLSTATE 42703: column notifications.notifiable_type does not exist`, which reads like a bug
 * in this increment. Nothing calls them today (verified: zero uses of `unreadNotifications`,
 * `DatabaseNotification`, or a `via()` returning `'database'` anywhere in the repo) and the rule is that
 * nothing may: **`'database'` is a forbidden notification channel repo-wide**, `App\Models\Notification` is
 * the only reader of this table, and `tests/Feature/Notifications/NotificationTableGuardTest.php` pins both.
 * The trait's own method is deliberately NOT overridden — pointing a framework accessor at a
 * differently-shaped model is worse than leaving one nothing calls.
 *
 * ── Not `append_only` ──────────────────────────────────────────────────────────────────────────────────
 * `audits` is append-only because a compliance ledger that can be edited is not a ledger. This table is the
 * opposite: `read_at` is written by the recipient every time they open the center (I4), so it needs the
 * ordinary strict UPDATE policy.
 *
 * ── What may go in `data` ──────────────────────────────────────────────────────────────────────────────
 * Identifiers and display labels needed to render the row and deep-link from it — `{"submission_id": …,
 * "form_id": …, "form_title": …}`. §22 marks it conditional PII because a tenant-defined form title can
 * carry anything, which is the same posture `webhook_deliveries.payload` holds. **Never a token, a signed
 * URL, or any other credential:** these rows are readable by every member of the tenant under the strict
 * policy above, and nothing redacts them (the table is deliberately unaudited — see
 * `docs/audit-compliance-logging-spec.md` §1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // `users` is a global (tenant-free) table, so a single-column FK is correct — the composite
            // (tenant_id, …) form of ADR-0002 §D5 applies to tenant-scoped parents, which this is not.
            // Matches saved_report_views. cascadeOnDelete: a notification dies with its recipient.
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('type', 40); // App\Enums\NotificationType
            $table->jsonb('data')->default('{}');
            $table->timestampTz('read_at')->nullable();    // NULL = unread; drives the bell's badge
            $table->timestampTz('emailed_at')->nullable(); // NULL = no email copy was sent
            $table->timestampsTz();

            // tenant_id LEADS (ADR-0002 §D1). This is the index the bell's unread count and the center's
            // first page both hit: "this tenant, this person, unread first, newest first".
            $table->index(['tenant_id', 'user_id', 'read_at'], 'notifications_tenant_user_read_idx');
            $table->index(['tenant_id', 'user_id', 'created_at'], 'notifications_tenant_user_created_idx');
        });

        // Pin the type vocabulary at the database, generated from the enum so the two cannot drift.
        $types = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            NotificationType::values()
        ));

        DB::statement(
            'ALTER TABLE notifications ADD CONSTRAINT notifications_type_check '
            ."CHECK (type IN ({$types}))"
        );

        withTenantIsolation('notifications'); // strict — NOT belongs_to_user; see the docblock
    }

    public function down(): void
    {
        // Dropping the table drops its policies and its CHECK with it.
        Schema::dropIfExists('notifications');
    }
};
