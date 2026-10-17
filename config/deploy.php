<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The deploy's data guard — M176 (`R-a11cc78a`, `D137`, `D143`, `D144`)
|--------------------------------------------------------------------------
| `deploy.ps1` opens its maintenance window, then runs `deploy:backup` and `deploy:census --write` before it
| replaces anything, and `deploy:census --compare` after `migrate`. A failed backup or count brings the site back
| up on the old code and stops; a table that holds fewer rows after the migration leaves the site down (`D144`).
| See `docs/deployment-infrastructure.md` for the box's side and how to restore.
*/

return [

    /*
    | `pg_dump`: this path, else the newest `C:\Program Files\PostgreSQL\<n>\bin\pg_dump.exe`, else `pg_dump` on PATH.
    | The box's install is PostgreSQL 15, but its exact path was never read off the box — hence the search.
    */
    'pg_dump' => env('MERIDIAN_PG_DUMP'),

    /*
    | Where the deploy's dumps and its row census go. The default sits inside the live checkout's storage, which the
    | deploy runner can always write (it rewrites the caches there on every deploy); `git reset --hard` leaves the
    | git-ignored storage alone. The nightly backup task keeps its own dumps elsewhere and is not touched.
    */
    'backup_dir' => env('MERIDIAN_DEPLOY_BACKUP_DIR', storage_path('app/deploy-backups')),

    /** How many of the deploy's own dumps are kept, newest first. Older `deploy-*.dump` files are removed after a good one. */
    'keep' => (int) env('MERIDIAN_DEPLOY_BACKUPS_KEPT', 10),

    /** A dump smaller than this is no dump: the schema alone is larger. */
    'min_bytes' => 1024,

    /** A superuser, as `migrate` runs: row security hides nothing from the dump or the count. */
    'connection' => 'pgsql_privileged',

    /*
    | Tables that empty themselves by design, so a smaller count after a migration is no loss. Each was measured
    | against what writes it while the site is down; every other base table is counted, a new one included.
    */
    'census_exempt' => [
        'jobs',                  // a job already running when the window opens still finishes and deletes its row
        'job_batches',           // pruned with the jobs they group
        'cache', 'cache_locks',  // expire and are swept on read
        'sessions',              // expire; nobody signs in while the site is down, but expired rows go on a read
        'password_reset_tokens', // single-use, deleted when used or expired
        'google_auth_requests',  // a sign-in's one-time state, deleted when the sign-in completes or expires
        'sso_auth_requests',     // the same, for single sign-on
        'impersonation_tokens',  // single-use, deleted when used
    ],

];
