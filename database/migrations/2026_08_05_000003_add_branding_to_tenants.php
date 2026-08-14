<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\Tenant;
use App\Support\Branding\BrandRampGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Increment H23a2 — tenant branding storage (ADR-0014; data-dictionary §114–115, which specified two of
 * these three columns long before anything built them).
 *
 * **Why the derived ramp is a COLUMN and not a re-computation.** `brand_ramp` holds the twelve literal
 * hexes {@see BrandRampGenerator} derives from `primary_color`, plus their measured ratios and the engine
 * version that produced them. It exists because three of the five surfaces that consume a ramp **cannot
 * resolve a CSS custom property at all** — mail clients strip `<style>` and ignore `var()`, and dompdf
 * (the H17 PDF renderer) supports neither custom properties reliably nor `oklch()`. Those surfaces need
 * literal hexes at render time, so the ramp has to exist as data regardless; once it does, deriving it a
 * second time on read is duplication with a drift hazard attached (ADR-0014 §D8).
 *
 * `engine_version` inside the payload is what keeps that honest: a later change to a target ratio or a
 * ground token would silently repaint every live tenant if ramps were re-derived on read, and becomes an
 * explicit, auditable re-derivation because they are not.
 *
 * **`primary_color` keeps data-dictionary §115's name deliberately.** It stores the tenant's INPUT — what
 * they typed — not a derived value, because the picker shows it beside the derived fill (the snap
 * disclosure). Inventing a second name here would leave the dictionary describing a column that does not
 * exist beside a column it never mentions.
 *
 * `tenants` is the central, RLS-exempt discriminator table (ADR-0002 §D1), so no `withTenantIsolation()`
 * is needed and the migration linter skips this alter-only migration — **which means this migration's own
 * CI green is vacuous and the H23a2 test pack is the only thing standing over these columns** (the H25
 * lesson, restated because it applies verbatim).
 *
 * **THE TRAP, and it fails SILENTLY: every real column here MUST also be listed in
 * {@see Tenant::getCustomColumns()}** or stancl/tenancy relocates the value into its `data` json
 * virtual-column store and reads it back as **null** — no error, no warning, just a tenant whose branding
 * never applies. `TenantColumnWhitelistTest` pins that list by set equality (this line cited
 * `TenantCustomColumnsTest` until P2a — a file that has never existed), and this migration ships the three additions in
 * the same commit for exactly that reason.
 *
 * **`logo_attachment_id` is `ON DELETE SET NULL`, and the H25 finding applies:** PostgreSQL runs a
 * referential action as an ordinary UPDATE through SPI, which bypasses RLS but NOT a trigger. `tenants`
 * carries no trigger today, so hard-deleting a logo attachment cleanly nulls the pointer; the alternative
 * (`RESTRICT`) would have made an attachment un-deletable and turned GDPR erasure of a stored object into
 * a constraint violation on an unrelated table.
 *
 * **BUT THE FK IS NOT WHAT PROTECTS THE RENDER PATH, and assuming it does is the easy mistake here.**
 * {@see Attachment} uses `SoftDeletes`, so an ordinary `delete()` leaves the row in place and
 * this referential action NEVER FIRES — `logo_attachment_id` goes on pointing at a trashed row. What
 * actually stops a deleted logo rendering is the SoftDeletes global scope on
 * {@see Tenant::logoAttachment()}, which resolves it to null. The FK earns its keep only on
 * `forceDelete()`. `BrandingStorageTest` asserts both halves separately, because they disagree and
 * either one alone would teach a reader the wrong model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            // The tenant's INPUT hex (#RRGGBB, 7 chars incl. the hash). NULL ⇒ no brand colour set.
            $table->string('primary_color', 7)->nullable()->after('draft_ttl_days');

            // The derived ramp: {input, engine_version, tokens{light,dark}{6 roles}, measurements[17]}.
            // NULL ⇒ never derived. Written only together with primary_color; a row with one and not the
            // other is a bug, and TenantBrandingService is the only writer of either.
            $table->jsonb('brand_ramp')->nullable()->after('primary_color');

            $table->foreignUuid('logo_attachment_id')
                ->nullable()
                ->after('brand_ramp')
                ->constrained('attachments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('logo_attachment_id');
            $table->dropColumn(['primary_color', 'brand_ramp']);
        });
    }
};
