<?php

declare(strict_types=1);

use App\Enums\BadgeKey;
use App\Models\BadgeAward;
use App\Models\PointAward;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The gamification badge ledger (gamification-design.md §7, ADR-0020) — Increment K1b.
 *
 * The sibling of `point_awards` and deliberately the same shape: a bigint identity PK (the `usage_counters`
 * precedent for internal aggregation rows never addressed externally, so {@see BadgeAward} does NOT use
 * HasUuidv7), a single-column FK into the global `users` table, and `append_only` isolation.
 *
 * ── WHY THIS IS A TABLE AT ALL, WHEN THE ANSWER IS DERIVABLE ───────────────────────────────────────────
 * "Has this member collected 25 responses?" can be answered from {@see PointAward} at any moment, so a
 * derived badge is genuinely tempting and needs no write path. **"When did they earn it?" cannot.** The
 * derived answer changes the instant a threshold moves, and lowering one would retroactively invent an
 * earned-on date that never happened. That is ADR-0020 Context §4's J5 lesson in a second place: a surface
 * that recomputes from live counts can say a thing IS true and is structurally unable to say WHEN it became
 * true.
 *
 * ── `append_only`, FOR THE SAME REASON THE POINTS LEDGER IS ────────────────────────────────────────────
 * SELECT + INSERT policies only, so FORCE RLS denies UPDATE and DELETE to every role including the table's
 * owner. Combined with the paragraph above this makes one property structural rather than intended:
 * **re-thresholding a badge moves future awards only, and the ledger keeps saying what it said.**
 * `BadgeAwardRlsTest` asserts the ABSENCE of those two policies, because their later appearance would not be
 * a refinement — it would be an achievement record somebody can quietly edit.
 *
 * ── NO `threshold` COLUMN, AND THE OMISSION IS THE §D4 QUESTION ANSWERED, NOT DUCKED ────────────────────
 * `point_awards.points` is COPIED at award time because the *value* is the score, so re-weighting must not
 * rewrite history. A badge's value is binary and its identity IS {@see BadgeKey}, so a per-row copy of the
 * threshold would be a PHP constant duplicated on every row with **no reader** — and the property §D4 wants
 * is already delivered by the append-only shape above. Adding it later is additive; adding it now would be
 * a column nothing selects.
 *
 * ── NO SECOND INDEX, MEASURED RATHER THAN OMITTED ──────────────────────────────────────────────────────
 * Every read this engine makes of this table is "which badges does this member hold" and "which badges does
 * this workspace hold", both of which the unique index's leading `(tenant_id, user_id)` already serves. The
 * `point_awards` sibling carries a second index because totals, the ladder AND the streak walk all order by
 * day; nothing here orders by anything. An index nothing reads still costs every INSERT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badge_awards', function (Blueprint $table): void {
            $table->bigIncrements('id'); // bigint identity — internal aggregation rows (see docblock)
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Single-column FK because `users` is a GLOBAL (tenant-free) table — ADR-0002 §D5's composite
            // (tenant_id, id) form applies to tenant-scoped parents, which this is not. Same reasoning, and
            // the same accepted consequence, as `point_awards`, `notifications` and `saved_report_views`: a
            // removed member keeps their `users` row, so their badges survive and are visible to nobody.
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('badge', 40); // App\Enums\BadgeKey

            // When the ACT that crossed the threshold happened — copied from the triggering point award, NOT
            // read from the clock. K1c's backfill replays `audits` months after the fact, and a badge
            // stamped `now()` there would date every historical achievement to install day, which is exactly
            // the fact this table exists to preserve.
            $table->timestampTz('awarded_at');
            $table->timestampsTz();

            // EXPLICIT SHORT NAME (PostgreSQL NAMEDATALEN = 63), tenant_id-leading (ADR-0002 §D1).
            //
            // THE IDEMPOTENCY GUARD, and here it is doing more work than the points one. Evaluation runs on
            // every genuinely-new award of a matching rule, so a member who collects a thousand responses
            // re-offers `first_response` a thousand times; the index is what makes all but the first a no-op
            // rather than a thousand rows. Every writer treats a conflict as "already earned", never as an
            // error. No part of the key is nullable — a NULL would be DISTINCT under a Postgres UNIQUE and
            // the guard would be decorative (ADR-0020 §D3a).
            $table->unique(['tenant_id', 'user_id', 'badge'], 'badge_awards_tenant_user_badge_unique');
        });

        // Pin the catalog at the database, generated from the enum so the two cannot drift.
        $badges = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            BadgeKey::values()
        ));
        DB::statement(
            'ALTER TABLE badge_awards ADD CONSTRAINT badge_awards_badge_check '
            ."CHECK (badge IN ({$badges}))"
        );

        withTenantIsolation('badge_awards', 'append_only');
    }

    public function down(): void
    {
        Schema::dropIfExists('badge_awards');
    }
};
