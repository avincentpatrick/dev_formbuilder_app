<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A section's place on the progress bar — M249 (`progress-chapters`, `D233`):
 *
 *  - `chapter`: the name of the chapter this section STARTS (`D233` answer 1). The sections after it belong to that
 *    chapter until the next section that starts one; NULL starts none. Plain text, never piped.
 *  - `chapter_translations`: the chapter's name per language, filled only by XLSForm import (an outer group's
 *    `label::<lang>`), exactly as `label_translations` is — the builder writes no translations.
 *  - `progress_label`: a short name for the section's page on the progress bar, in place of its title. It has no
 *    translations: in another language the bar uses it only when the title has no translation there.
 *
 * NULL everywhere is today's bar. `SchemaSnapshotSerializer` emits each key only when set, so every version published
 * before these columns — and every one since without them — keeps the checksum it had, and with it the offline drift
 * check and the stamp printed on paper.
 *
 * Typed columns rather than a config bag, as `repeat_layout` and `page_width` are. No new policy: `form_sections`'
 * `draft_child` policies cover new columns, and a tenant extract carries them like every other section setting.
 *
 * ⛔ ADDITIVE ONLY (`D137`): three nullable columns with no default, nothing rewritten, no row touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_sections', function (Blueprint $table): void {
            $table->string('chapter', 60)->nullable()->after('page_width');
            $table->jsonb('chapter_translations')->nullable()->after('chapter');
            $table->string('progress_label', 60)->nullable()->after('chapter_translations');
        });
    }

    public function down(): void
    {
        Schema::table('form_sections', function (Blueprint $table): void {
            $table->dropColumn(['chapter', 'chapter_translations', 'progress_label']);
        });
    }
};
