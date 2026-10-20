<?php

declare(strict_types=1);

use App\Support\Forms\FormClock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New forms start on Asia/Manila (M180, `D153`). `forms.timezone` is the zone a form's clock is stamped in, so it is
 * the calendar day `today()` reads ({@see FormClock}); on UTC, as it defaulted, a respondent in Manila
 * met yesterday's date until 08:00.
 *
 * Only the column DEFAULT changes — no column, row or constraint is dropped (additive, `D137`). The forms already on UTC
 * are switched by the NEXT migration, in its own file: a privileged session's UPDATE here would wait on this
 * transaction's lock on `forms` (the `..._000113` self-deadlock).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE forms ALTER COLUMN timezone SET DEFAULT 'Asia/Manila'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE forms ALTER COLUMN timezone SET DEFAULT 'UTC'");
    }
};
