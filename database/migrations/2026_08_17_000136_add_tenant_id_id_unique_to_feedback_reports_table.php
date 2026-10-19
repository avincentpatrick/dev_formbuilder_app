<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `(tenant_id, id)` unique on `feedback_reports` — M179 (`R-129c44c3`), the target of `feedback_comments`'
 * COMPOSITE foreign key, so a comment can never point at another workspace's report.
 *
 * It duplicates the primary key on purpose, exactly as `2026_08_17_000121` did for `form_versions` and
 * `attachments`: a foreign key must reference a unique key, and only one that leads with `tenant_id` lets the
 * child carry the tenant in its key (ADR-0002 §D5; `ConstraintBoundaries` records the three post-convention
 * single-column keys this repository already regrets, and this one is not to become the fourth).
 *
 * ⛔ ADDITIVE (`D137`): one index; no row is touched. Every row already has a distinct `id`, so the build
 * cannot fail on live data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'feedback_reports_tenant_id_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table): void {
            $table->dropUnique('feedback_reports_tenant_id_id_unique');
        });
    }
};
