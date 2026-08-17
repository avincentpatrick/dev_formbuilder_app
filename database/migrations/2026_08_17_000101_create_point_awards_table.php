<?php

declare(strict_types=1);

use App\Enums\PointRule;
use App\Events\MemberInvited;
use App\Models\PointAward;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The gamification award ledger (gamification-design.md §4, ADR-0020) — Increment K1a.
 *
 * The PK is a **bigint identity** (NOT uuidv7), on the `usage_counters` precedent: these are pure internal
 * aggregation rows, never addressed externally — no route binds one, no API returns one by id — so
 * {@see PointAward} does NOT use HasUuidv7.
 *
 * ── `append_only`, NOT `strict`, AND THAT IS THE ARCHITECTURAL CLAIM MADE ENFORCEABLE ───────────────────
 * The `audits` shape (H4): SELECT + INSERT policies only, so FORCE RLS denies UPDATE and DELETE to every
 * role. {@see PointRule}'s docblock argues that re-weighting a rule must move FUTURE awards only and leave
 * the ledger saying what it said — a leaderboard that rewrites its own history when somebody edits a
 * constant is not a record of anything. Stated in a docblock that is a promise; stated as a policy the
 * database will not let you break it.
 *
 * ── WHY `subject_type`/`subject_id` ARE NOT NULLABLE, AND WHY `subject_id` IS A STRING ──────────────────
 * ⚠️ In PostgreSQL a UNIQUE index treats NULLs as DISTINCT, so a nullable subject would let the same act be
 * awarded twice and the index would happily allow it — the idempotency guard would be decorative. Every
 * rule therefore has a real subject: the form, the form VERSION (so republishing earns again, which is the
 * intent), the submission, or the member.
 *
 * `subject_id` is `string(64)` rather than `uuid` for exactly one rule. {@see MemberInvited}
 * carries an **email address and no user id** — an invitee need not have a `users` row the listener can
 * see — so `member.invited` keys on `hash('sha256', <lowercased email>)`: stable, exactly-once per invitee
 * (re-inviting the same person cannot farm points), and **not the address itself**, which has no business
 * sitting in a scoreboard table.
 *
 * `awarded_at` is deliberately separate from `created_at`: the K1c backfill replays the `audits` ledger, so
 * the row is WRITTEN today and the act HAPPENED months ago. A streak computed off `created_at` would show
 * every historical workspace with a single enormous one-day streak on install day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_awards', function (Blueprint $table): void {
            $table->bigIncrements('id'); // bigint identity — internal aggregation rows (see docblock)
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Single-column FK because `users` is a GLOBAL (tenant-free) table — ADR-0002 §D5's composite
            // (tenant_id, id) form applies to tenant-scoped parents, which this is not. Same reasoning, and
            // the same accepted consequence, as `notifications` and `saved_report_views`: a removed member
            // keeps their `users` row, so their awards survive and are simply visible to nobody.
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('rule', 30);         // App\Enums\PointRule
            $table->integer('points');          // copied from PointRule::points() AT AWARD TIME, never joined
            $table->string('subject_type', 20); // 'form' | 'form_version' | 'submission' | 'member' | 'invite'
            $table->string('subject_id', 64);   // a uuid, or a sha256 for `member.invited` (see docblock)

            $table->timestampTz('awarded_at');  // when the ACT happened — not when the row was written
            $table->timestampsTz();

            // EXPLICIT SHORT NAMES (PostgreSQL NAMEDATALEN = 63), tenant_id-leading (ADR-0002 §D1).
            //
            // THE IDEMPOTENCY GUARD. Listeners are synchronous and post-commit, but "exactly once" cannot
            // rest on that alone: a retried request, a replayed backfill and a double-dispatched event all
            // reach here, and the only thing that can refuse them at the moment two of them race is the
            // index. Every writer treats a 23505 on this index as "already awarded", never as an error.
            $table->unique(
                ['tenant_id', 'user_id', 'rule', 'subject_type', 'subject_id'],
                'point_awards_tenant_user_rule_subject_unique'
            );

            // Totals, the ladder, and the streak walk all read (tenant, member) ordered by day.
            $table->index(['tenant_id', 'user_id', 'awarded_at'], 'point_awards_tenant_user_awarded_idx');
        });

        // Pin the scoring vocabulary at the database, generated from the enum so the two cannot drift.
        $rules = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            PointRule::values()
        ));
        DB::statement(
            'ALTER TABLE point_awards ADD CONSTRAINT point_awards_rule_check '
            ."CHECK (rule IN ({$rules}))"
        );

        // A zero- or negative-value award is not a thing this product has: every PointRule::points() arm is
        // positive, and a row that scored nothing would still occupy the idempotency slot for the act,
        // silently preventing the real award if a weight were later corrected.
        DB::statement(
            'ALTER TABLE point_awards ADD CONSTRAINT point_awards_points_positive_check CHECK (points > 0)'
        );

        withTenantIsolation('point_awards', 'append_only');
    }

    public function down(): void
    {
        Schema::dropIfExists('point_awards');
    }
};
