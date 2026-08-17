<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenant_users.onboarding_dismissed_at` (Increment J5b) — when this person told this workspace to stop
 * showing them the getting-started checklist.
 *
 * ── WHY THE COLUMN EXISTS AT ALL: "ALL DONE" IS NOT A REACHABLE STATE FOR EVERYONE ─────────────────────
 * The checklist retires itself once every row it shows is complete, which is the ordinary exit. But one of
 * its four rows is *invite a teammate*, and a solo Owner running a workspace alone will never finish it —
 * so without a dismissal the card is permanent furniture for exactly the user who has least use for it.
 * Onboarding plan §2 is explicit that the first-run surface must not become something the product makes you
 * click past; a card that cannot be got rid of is that, one increment later.
 *
 * ── WHY `tenant_users` AND NOT `user_ui_preferences` (user decision 2026-08-17) ────────────────────────
 * The obvious cheaper home is the personal preferences table, which needs no census update. It is wrong:
 * that table belongs to the PERSON and carries no `tenant_id` on purpose (*"the same user keeps their theme
 * across every tenant membership"*), so dismissing the checklist in one workspace would silence it in a
 * workspace they have not opened yet — including one where they are the founding Owner and it is the only
 * thing telling them what to do next. `tenant_users` is the row that means *this person, in this workspace*,
 * which is precisely the scope of the fact being stored.
 *
 * The cost of that choice is real and is paid here rather than discovered: `tenant_users` is an EXTRACTED
 * table, so `TenantExtractColumnDriftTest`'s census reddens on this column until someone looks at it and
 * decides. That is the census doing its job — it is a plain, non-derived fact about this tenant's own
 * membership row, so it is **not** added to `TenantExtractColumns::WITHHELD` and it travels with an extract.
 *
 * ── NULLABLE, AND NEVER BACKFILLED ─────────────────────────────────────────────────────────────────────
 * NULL is the honest reading for every existing member: *has not dismissed it*. Backfilling `now()` for
 * pre-existing rows would be the tempting "don't show it to people who already know the product" move, and
 * it hides the surface from the only tenants who could tell us whether it works. A member who has finished
 * all four rows sees nothing anyway, because the service computes done-ness from live counts.
 *
 * ── NO `withTenantIsolation()` RE-EMIT ─────────────────────────────────────────────────────────────────
 * `tenant_users` already carries the strict RLS shape, and RLS policies are ROW predicates, not column
 * lists — a new column is covered the moment it exists (the 2026_07_23_000006 / 2026_08_09_000001 /
 * 2026_08_11_000002 / 2026_08_12_000001 precedent).
 *
 * ⚠️ This migration creates no table, so `scripts/migration-lint.php` skips it and its green here is
 * vacuous rather than evidence. Verified by running the linter, not assumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->timestampTz('onboarding_dismissed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->dropColumn('onboarding_dismissed_at');
        });
    }
};
