<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether other forms of the workspace may use this form's answers as their choices (M133, `R-5da4a30f` —
 * Connect project v1). This is the SOURCE side of the two-key consent: a destination's choice question links
 * here, and its list is served only while this switch is on and only from the questions named.
 *
 * ON `forms`, NOT IN THE VERSION SNAPSHOT, for the redirect's reason (`2026_08_17_000118`): consent is a live
 * fact the author changes without a republish, and withdrawing it must reach the next request for the list at
 * once rather than wait for a version.
 *
 * ⛔ `data_sharing_field_keys` IS NULL FOR "EVERY SHAREABLE QUESTION" AND NEVER `[]`. KoboToolbox's equivalent
 * stores `fields: []` for "all" while computing an empty intersection as "none", and its own source carries a
 * warning comment about the collision. Here the empty list is unconstructible: the CHECK refuses it, and
 * `UpdateDataSharingRequest` refuses it before the database has to.
 *
 * No `withTenantIsolation()` here: `forms` already carries strict RLS, and adding columns does not weaken a row
 * predicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->boolean('data_sharing_enabled')->default(false);
            $table->jsonb('data_sharing_field_keys')->nullable();
        });

        DB::statement(
            'ALTER TABLE forms ADD CONSTRAINT forms_data_sharing_field_keys_check '
            ."CHECK (data_sharing_field_keys IS NULL OR (jsonb_typeof(data_sharing_field_keys) = 'array' "
            .'AND jsonb_array_length(data_sharing_field_keys) > 0))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE forms DROP CONSTRAINT IF EXISTS forms_data_sharing_field_keys_check');

        Schema::table('forms', function (Blueprint $table): void {
            $table->dropColumn(['data_sharing_enabled', 'data_sharing_field_keys']);
        });
    }
};
