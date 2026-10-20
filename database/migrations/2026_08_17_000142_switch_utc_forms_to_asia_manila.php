<?php

declare(strict_types=1);

use App\Support\Migrations\FormTimeZoneSwitch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move the forms still on UTC to Asia/Manila (M180, `D153`) — {@see FormTimeZoneSwitch} holds the work, the guard and
 * the reasons. Its own file, after `..._000141`'s DDL, for the `..._000113` reason: the write goes to `pgsql_privileged`
 * (`forms` forces row-level security), a separate session that would deadlock on a lock this connection's migration
 * transaction still held.
 */
return new class extends Migration
{
    /** The write runs in AUTOCOMMIT on `pgsql_privileged`, so a transaction on the default connection would roll back nothing. */
    public $withinTransaction = false;

    public function up(): void
    {
        (new FormTimeZoneSwitch)(DB::connection('pgsql_privileged'));
    }

    public function down(): void
    {
        // Intentionally irreversible: a form on Asia/Manila cannot be told apart from one an author set there, and
        // guessing would move a form its author chose. Re-running up() is a no-op.
    }
};
