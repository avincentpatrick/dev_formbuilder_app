<?php

declare(strict_types=1);

namespace App\Support\Migrations;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Move every form still on UTC to Asia/Manila (M180, `D153`) — the work of
 * `2026_08_17_000142_switch_utc_forms_to_asia_manila.php`, connection-agnostic so the test can drive it on the app
 * connection under a live tenant (the {@see SubmissionReferenceBackfill} precedent: a `migrate:fresh` database holds no
 * forms when the migration runs).
 *
 * The user's answer (2026-10-20): existing forms still on UTC switch, so "Today" in their formulas is Manila's date. A
 * form an author set to another zone is left alone. A schedule's `opens_at`/`closes_at` are absolute instants, so a
 * window does not move — it is only shown in Manila time. Nothing is deleted.
 *
 * ⛔ `forms` carries FORCE ROW LEVEL SECURITY: on the app connection with no tenant set, this UPDATE matches nothing
 * and raises nothing (`project_postgres_write_traps`). The migration therefore runs it on `pgsql_privileged`, and this
 * re-counts afterwards on the same connection and throws if any form is still on UTC — a green migration that
 * changed nothing is the failure this guard exists for.
 */
final class FormTimeZoneSwitch
{
    public const string FROM = 'UTC';

    public const string TO = 'Asia/Manila';

    /** @return int the forms switched */
    public function __invoke(ConnectionInterface $connection): int
    {
        $switched = $connection->update('UPDATE forms SET timezone = ? WHERE timezone = ?', [self::TO, self::FROM]);

        $left = $connection->table('forms')->where('timezone', self::FROM)->count();

        if ($left > 0) {
            throw new RuntimeException(
                "The time-zone switch landed nothing: {$left} form(s) are still on UTC. The UPDATE was almost certainly "
                .'refused by row-level security — run it on pgsql_privileged.'
            );
        }

        return $switched;
    }
}
