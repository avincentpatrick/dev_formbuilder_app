<?php

declare(strict_types=1);

use App\Services\Forms\TemplateValidationGate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Increment H6a — the author-editable confirmation message (docs/piping-output-encoding-design.md §6.2).
 *
 * `docs/PRD.md`'s Phase-3 scope credits piping with confirmation-screen coverage, but there was no
 * author-editable confirmation message anywhere in the product: the copy is three hardcoded constants in
 * `resources/public-runtime/App.vue`, and no `confirmation_message` column existed in any migration.
 * Piping the respondent's own answers into a thank-you screen — the single most-wanted piping use case —
 * therefore needed storage first.
 *
 * Both columns are TEMPLATE-BEARING (§6's closed list), validated at publish by
 * {@see TemplateValidationGate}. When null the existing hardcoded default stands, so
 * the change is additive for every existing form.
 *
 * Note the consequence §6.2 records: these are `forms` columns, not `form_versions` columns, so they are
 * NOT frozen per version — their holes are validated against the version being published, and a
 * post-publish edit that dangles a reference surfaces at the NEXT publish rather than at the edit (§8).
 *
 * No RLS re-emit: `forms` already carries the strict/FORCE variant, its policies are row predicates, and
 * there are no column-level GRANTs on `forms` (the only GRANTs in database/ are on `users`/`tenant_users`),
 * so additive columns inherit the existing policies — same as 2026_07_23_000010 and 2026_07_25_000001.
 *
 * Alter-only, so `scripts/migration-lint.php` returns early on it: its green here is VACUOUS — no RLS
 * check, no naming check and no column-type check ran. `->after()` is documentation only; Postgres ignores
 * column position.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->text('confirmation_message')->nullable()->after('save_and_resume');
            $table->jsonb('confirmation_message_translations')->nullable()->after('confirmation_message');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->dropColumn(['confirmation_message', 'confirmation_message_translations']);
        });
    }
};
