<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove the duplicated `created_at`/`updated_at` keys from `tenants.data` (Phase 4, P2a).
 *
 * ── THE DEFECT, MEASURED RATHER THAN INFERRED ──────────────────────────────────────────────────────────
 * `App\Models\Tenant` extends stancl's virtual-column model: `encodeAttributes()` moves every attribute NOT
 * in {@see Tenant::getCustomColumns()} into the `data` json blob on save, and `decodeVirtualColumn()` then
 * calls `setAttribute()` for every key it finds in `data` on read — **overwriting the real column**.
 *
 * The timestamps were never on that whitelist, so each save copied them into `data`, and every subsequent
 * read served the copy. Because Eloquent stamps `updated_at` AFTER the `saving` event that does the
 * encoding, the copy is always one save behind: on the dev database the `acme` row held `updated_at`
 * 19:54:38 in the column and 19:54:11 in `data`, and `$tenant->updated_at` returned the stale one.
 * `created_at` was equally duplicated but never diverged, because it never changes — which is exactly why
 * nothing noticed for a year of increments.
 *
 * P2a adds both columns to the whitelist. That alone fixes NEW writes and does nothing for existing rows:
 * the decode loop iterates whatever is IN `data`, so a row written before the fix keeps overwriting its own
 * column from a stale key no matter what the whitelist says. Hence this migration.
 *
 * ── SCOPE, DELIBERATELY NARROW ─────────────────────────────────────────────────────────────────────────
 * `- 'created_at' - 'updated_at'` removes exactly two keys and leaves every other virtual attribute intact.
 * A blanket rewrite of `data` would be the tempting one-liner and would silently discard any key a future
 * column has not claimed yet.
 *
 * `tenants` is the RLS-EXEMPT discriminator table, so this runs on the ordinary connection with no tenant
 * context — the same footing as `ActivateCustomDomainCommand`'s reads. It is data-only (no `Schema::`
 * call), so `scripts/migration-lint.php` — which keys on `Schema::create` — does not inspect it, and there
 * is nothing here for it to require.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')
            ->whereNotNull('data')
            // Cast through `jsonb` and back. The column is `json` (stancl's stock migration), and the
            // key-removal operator `-` exists only for `jsonb` — on `json` it is `operator does not exist:
            // json - unknown`, which fails the migration rather than silently doing nothing.
            ->update(['data' => DB::raw("(data::jsonb - 'created_at' - 'updated_at')::json")]);
    }

    public function down(): void
    {
        // Intentionally irreversible. The keys this removed were duplicates of the real columns and were
        // STALE by construction — restoring them would restore the defect, and there is no value in `data`
        // that is not already, and more correctly, in the column beside it.
    }
};
