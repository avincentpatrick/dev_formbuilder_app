<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A user's per-tenant, per-type choice of which channels a notification is delivered on (data-dictionary
 * §23, PRD Feature #13, Increment I3).
 *
 * ── ABSENCE MEANS DEFAULT — the table is deliberately sparse ────────────────────────────────────────────
 * No row for a `(tenant_id, user_id, notification_type)` triple means the platform default applies, and the
 * default lives in code ({@see NotificationType::defaultInApp()} / {@see NotificationType::defaultEmail()}),
 * resolved at READ time. Nothing is seeded when a user joins a tenant: a row appears only when someone
 * diverges. This is the posture `user_ui_preferences` already holds, and it is what keeps "the platform
 * default changed" from meaning "backfill every row for every member of every tenant".
 *
 * The column defaults (`true`/`true`) therefore describe what an INSERT that names neither channel means,
 * not what an absent row means. The two agree everywhere except {@see NotificationType::SubmissionReceived},
 * whose email default is off per the PRD — so a preference row written for that type must always state both
 * channels explicitly rather than lean on the column default.
 *
 * ── STRICT RLS, not `belongs_to_user` ──────────────────────────────────────────────────────────────────
 * Same argument as the `notifications` table it accompanies, and here §23 makes it explicit: "a user in
 * multiple tenants may set different preferences per tenant". `belongs_to_user` emits policies keyed only
 * on `user_id` with no tenant predicate, which would let that consultant read — and the unique index treat
 * as colliding — preferences across both. Per-user privacy is the application predicate
 * {@see NotificationPreference::scopeForUser()}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('notification_type', 40); // App\Enums\NotificationType
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('email_enabled')->default(true);
            $table->timestampsTz();

            // §23's stated uniqueness. A duplicate raises 23505; the write path is an updateOrCreate keyed
            // on exactly this triple, so it cannot be reached by the UI — only by a racing double submit.
            $table->unique(
                ['tenant_id', 'user_id', 'notification_type'],
                'notification_prefs_tenant_user_type_unique'
            );

            // tenant_id LEADS (ADR-0002 §D1). Every read is "this tenant, this person, all types" — the
            // resolver loads the whole (small) set once per delivery pass rather than one row per type.
            $table->index(['tenant_id', 'user_id'], 'notification_prefs_tenant_user_idx');
        });

        // Pin the type vocabulary at the database, generated from the enum so the two cannot drift.
        $types = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            NotificationType::values()
        ));

        DB::statement(
            'ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_type_check '
            ."CHECK (notification_type IN ({$types}))"
        );

        withTenantIsolation('notification_preferences'); // strict — NOT belongs_to_user; see the docblock
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
