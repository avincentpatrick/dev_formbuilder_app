<?php

declare(strict_types=1);

use App\Enums\FeedbackType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What kind a feedback report is, and the page it came from (M175, `R-2ce2f274`, `D127`; data-dictionary §21).
 *
 * `type` is the reporter's own answer to "bug, suggestion or question" — nullable, so every report sent
 * before it existed keeps its place untouched; `StoreFeedbackRequest` requires it on every new one. Its CHECK is
 * generated from {@see FeedbackType::values()} and holds over the live rows by construction: they are all NULL,
 * and a CHECK passes NULL.
 *
 * `page_url` is the full address (the old `route` is the path alone) and `page_title` the title the browser
 * showed. Both lists render the title as a link to the address, which is why the request accepts a web address
 * only.
 *
 * ⛔ ADDITIVE ONLY (`D137`). Three nullable columns and one CHECK; no existing row is rewritten or deleted. The
 * table-level `GRANT SELECT` to the elevated role covers new columns, so the console's read needs nothing more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table): void {
            $table->string('type', 15)->nullable();
            $table->string('page_url', 2048)->nullable();
            $table->string('page_title', 255)->nullable();
        });

        $types = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            FeedbackType::values()
        ));

        DB::statement(
            'ALTER TABLE feedback_reports ADD CONSTRAINT feedback_reports_type_check '
            ."CHECK (type IN ({$types}))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE feedback_reports DROP CONSTRAINT IF EXISTS feedback_reports_type_check');

        Schema::table('feedback_reports', function (Blueprint $table): void {
            $table->dropColumn(['type', 'page_url', 'page_title']);
        });
    }
};
