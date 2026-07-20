<?php

declare(strict_types=1);

use App\Support\Migrations\CollaboratorBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copy every `form_collaborators` row into `resource_grants` as a direct `form` grant (Increment G10a).
 * The full rationale — why this cannot run on the app connection, why the id is reused, why the count
 * guard uses `<` — lives in {@see CollaboratorBackfill}, which also makes it testable without a database.
 *
 * ── Why this is its own migration file ───────────────────────────────────────────────────────────────
 * Laravel wraps each migration in a transaction on `pgsql` (PostgresGrammar supports schema
 * transactions), and the SEPARATE privileged session cannot see the PRECEDING migration's uncommitted
 * `CREATE TABLE resource_grants`. That, not operator convenience, is the binding constraint. Splitting
 * also releases this statement's ACCESS SHARE lock on `form_collaborators` before the next migration
 * requests ACCESS EXCLUSIVE to drop it, and lets an operator verify counts between the two steps.
 *
 * ── Rollback ─────────────────────────────────────────────────────────────────────────────────────────
 * `down()` is restorative, not a no-op — but it is LOSSY once G10b ships: a `scope_node` grant and a
 * `forms.scope_node_id` assignment have no representation in the old schema and are discarded. The loss
 * direction is fail-closed (access removed, never granted). **G10a is forward-only in production**;
 * `down()` exists for local `migrate:refresh`. Because `migrate --step` puts each migration in its own
 * batch, unwinding G10a is `php artisan migrate:rollback --step=5`, not a bare `migrate:rollback`.
 */
return new class extends Migration
{
    /**
     * The writes go to `pgsql_privileged` in AUTOCOMMIT, so the transaction Laravel opens on the DEFAULT
     * connection would roll back NOTHING here — leaving it on would only create the illusion of
     * atomicity. A thrown guard therefore aborts the migration RUN, not the COPY; recovery is
     * fail-FORWARD (fix the data, re-run), which is safe precisely because the INSERT is idempotent.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        (new CollaboratorBackfill)(DB::connection('pgsql_privileged'));
    }

    public function down(): void
    {
        DB::connection('pgsql_privileged')->statement(CollaboratorBackfill::RESTORE_SQL);
    }
};
