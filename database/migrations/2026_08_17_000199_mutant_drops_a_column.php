<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M176 MUTANT (R-a11cc78a) — committed, then reverted: a migration that removes data. migration-lint must refuse it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table): void {
            $table->dropColumn('page_title');
        });
    }

    public function down(): void
    {
    }
};
