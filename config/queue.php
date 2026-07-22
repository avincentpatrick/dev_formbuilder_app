<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    | The framework's "deferred", "background" and "failover" connections were REMOVED here per
    | ADR-0007 §D11. `deferred` is the dangerous one: it runs the job in-process after the response,
    | where the HTTP session GUC is STILL LIVE — so a job that correctly re-establishes tenant context
    | and one that silently free-rides on ambient request context become indistinguishable in testing,
    | and applyLocal() would overwrite the process static without restoring it, corrupting the
    | terminating request. `failover` only existed to reference `deferred`. Deleting them (rather than
    | commenting them as forbidden) means QUEUE_CONNECTION=deferred now throws InvalidArgumentException
    | loudly at boot in every environment — a gate, not prose.
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        // The substrate (ADR-0007 §D1): the `database` driver on the existing Postgres, drained by a
        // plain `queue:work`. No Horizon, no Redis-backed queue — see §D1 for the upgrade path.
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),

            // INVARIANT (ADR-0007 §D7): timeout < retry_after. TenantAwareJob::$timeout is 60, so this
            // must stay strictly above it. Raised 90 → 120 to hold the invariant with headroom; if it
            // ever drops below a job's timeout, the queue hands the SAME job to a second worker while
            // the first is still running it.
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 120),

            // ADR-0007 §D8. Was false, which is a real bug: AttachmentStorageService::store() creates
            // the attachment row and dispatches ScanAttachmentJob with no enclosing transaction at that
            // level, so a worker could reserve the job before the row was visible, take the
            // `$attachment === null` early return, and strand virus_scan_status at `pending` FOREVER.
            // Structurally untestable under QUEUE_CONNECTION=sync, which is why it never surfaced.
            // Deliberately NOT env-overridable: this is a correctness invariant, not a tunable.
            'after_commit' => true,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    // Fallback is 'pgsql', not the skeleton's 'sqlite': this project is Postgres-only (ADR-0001) and
    // a silent sqlite fallback would be a confusing failure rather than a useful default.
    'batching' => [
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',

        // How long a failed job is retained before App\Jobs\Maintenance\PruneFailedJobsJob sweeps it
        // (ADR-0007 §D12). NOTE: `failed_jobs` is RLS-FREE and its payload carries tenant uuids, so
        // retention here is a data-minimisation decision as well as a housekeeping one — any
        // tenant-facing view of this table is a CROSS-TENANT read (§D12) and must go through
        // pgsql_superadmin, never a tenant-scoped query.
        'retention_days' => (int) env('QUEUE_FAILED_RETENTION_DAYS', 14),
    ],

];
