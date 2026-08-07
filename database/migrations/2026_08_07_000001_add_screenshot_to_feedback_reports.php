<?php

declare(strict_types=1);

use App\Models\Attachment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The deferred half of `feedback_reports` (Increment I7a) — the screenshot pointer PRD Feature #11 has
 * always specified and data-dictionary §21 has always documented. `2026_07_06_000100`'s own docblock says
 * it in as many words: the `screenshot_attachment_id` FK "lands in Phase 1 with the `attachments` table
 * and the support UI". This is that migration; C3 built only the tenant-side submit path.
 *
 * ── SHAPE ───────────────────────────────────────────────────────────────────────────────────────────────
 * A direct, nullable FK to `attachments` — deliberately NOT a second polymorphic reader. It mirrors
 * `tenants.logo_attachment_id` (2026_08_05_000003) and `form_templates.cover_image_attachment_id` exactly,
 * which is the convention data-dictionary:1060 names for the attachments table's non-form owners: the
 * polymorphic `attachable_*` columns record who OWNS the file, and a real FK in the other direction is how
 * the owner FINDS it.
 *
 * **`ON DELETE SET NULL`, and the H25/branding finding transfers verbatim: the FK is not what protects the
 * render path.** {@see Attachment} uses SoftDeletes, so an ordinary `delete()` leaves the row
 * in place and this referential action NEVER FIRES — the pointer goes on referencing a trashed row. What
 * stops a deleted screenshot rendering is the SoftDeletes global scope on the `screenshot()` relation,
 * which resolves it to null. The FK earns its keep only on `forceDelete()`, which is precisely the GDPR
 * erasure path; `RESTRICT` would have turned erasing a stored object into a constraint violation on an
 * unrelated table.
 *
 * ── THE INDEX IS NOT INCIDENTAL ─────────────────────────────────────────────────────────────────────────
 * The table shipped with ONE index, `(tenant_id, status)` — correct for the tenant-side triage read that
 * was all C3 needed. I7a adds the cross-tenant platform console, whose whole list is ordered by recency
 * ACROSS tenants, so `tenant_id` cannot lead and that index cannot serve it. Without `submitted_at` the
 * console's every page render is a seq scan + sort of the whole table. Same class of finding as I2's
 * `SELECT DISTINCT` full scan, caught before it shipped rather than after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table): void {
            $table->foreignUuid('screenshot_attachment_id')
                ->nullable()
                ->after('browser_info')
                ->constrained('attachments')
                ->nullOnDelete();

            // The platform console's ordering (newest first, all tenants). DESC is not specified: a
            // btree scans either direction, so one ascending index serves both orders.
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table): void {
            $table->dropIndex(['submitted_at']);
            $table->dropConstrainedForeignId('screenshot_attachment_id');
        });
    }
};
