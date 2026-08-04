<?php

declare(strict_types=1);

use App\Models\Domain;
use App\Models\Tenant;
use App\Support\Tenancy\Uuid7Generator;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager;

return [
    // Our own uuid Tenant model (extends stancl's base) + UUIDv7 ids (Data Dictionary PK strategy).
    'tenant_model' => Tenant::class,
    'id_generator' => Uuid7Generator::class,

    // H22a — our own Domain model (extends stancl's base). This one key repoints BOTH
    // HasDomains::domains() and stancl's DomainTenantResolver, which is what lets the verification casts
    // and the ResolvableDomainScope reach every reader in the app without touching a single call site.
    'domain_model' => Domain::class,

    /**
     * The list of domains hosting your central app (login/signup/billing — runs OUTSIDE any tenant
     * context, ADR-0002 §D4). Tenants resolve on subdomains of the app host. For local dev, add the
     * wildcard host (e.g. *.meridian.test) to your hosts file / Laragon.
     */
    'central_domains' => [
        '127.0.0.1',
        'localhost',
        env('CENTRAL_DOMAIN', 'meridian.test'),
    ],

    /**
     * The single canonical central host, used to constrain the super-admin console route group
     * (routes/admin.php, Increment B2c) so it is not served on tenant subdomains. Read from config
     * rather than env() directly in the route file so it survives `route:cache`.
     */
    'central_domain' => env('CENTRAL_DOMAIN', 'meridian.test'),

    /**
     * Custom domains (H22a / ADR-0012). A tenant proves control of a hostname by publishing a TXT
     * record; an OPERATOR then activates it, by policy only after installing a certificate by hand,
     * because per-domain TLS issuance is structurally Track B and Track B is deferred.
     */
    'custom_domains' => [
        // RFC 8552 underscore-prefixed leaf. A `_`-prefixed name cannot collide with a real hostname,
        // and it keeps the challenge out of the apex TXT set, which registrar UIs are prone to merging
        // or replacing wholesale (SPF, DMARC, other vendors' *-site-verification records live there).
        'txt_record_name' => env('CUSTOM_DOMAIN_TXT_NAME', '_meridian-challenge'),

        // Prefixed so a TXT set shared with other vendors stays unambiguous.
        'txt_value_prefix' => 'meridian-domain-verification=',

        // How long an unverified claim reserves its hostname before the sweep releases it. `domain` is
        // globally unique, so without expiry one tenant could squat a name it does not control forever.
        'claim_ttl_hours' => (int) env('CUSTOM_DOMAIN_CLAIM_TTL_HOURS', 168),

        // Bounds the sweep. dns_get_record() takes NO timeout, so a batch of dead nameservers is the
        // one way this job can blow its 300s budget; with $tries = 1 that means failed_jobs every tick,
        // forever. Oldest-checked-first ordering makes the bound fair rather than starving.
        'sweep_batch' => (int) env('CUSTOM_DOMAIN_SWEEP_BATCH', 50),

        // A verified/live domain is re-checked no more often than this — the dangling-DNS re-read.
        'recheck_minutes' => (int) env('CUSTOM_DOMAIN_RECHECK_MINUTES', 60),
    ],

    /**
     * SINGLE-DATABASE MODE (ADR-0002 §D4): tenant isolation is enforced by PostgreSQL Row-Level
     * Security (Increment A), NOT by swapping to a per-tenant database. So NO bootstrappers run —
     * in particular DatabaseTenancyBootstrapper is deliberately absent (it would try to switch the
     * DB connection per tenant). Our EstablishTenantDatabaseContext middleware sets the RLS session
     * variables instead. The TenantCreated → CreateDatabase job pipeline is likewise removed from
     * App\Providers\TenancyServiceProvider.
     */
    'bootstrappers' => [],

    /**
     * Database tenancy config. Used by DatabaseTenancyBootstrapper.
     */
    'database' => [
        'central_connection' => env('DB_CONNECTION', 'central'),

        /**
         * Connection used as a "template" for the dynamically created tenant database connection.
         * Note: don't name your template connection tenant. That name is reserved by package.
         */
        'template_tenant_connection' => null,

        /**
         * Tenant database names are created like this:
         * prefix + tenant_id + suffix.
         */
        'prefix' => 'tenant',
        'suffix' => '',

        /**
         * TenantDatabaseManagers are classes that handle the creation & deletion of tenant databases.
         */
        'managers' => [
            'sqlite' => SQLiteDatabaseManager::class,
            'mysql' => MySQLDatabaseManager::class,
            'mariadb' => MySQLDatabaseManager::class,
            'pgsql' => PostgreSQLDatabaseManager::class,

        /**
         * Use this database manager for MySQL to have a DB user created for each tenant database.
         * You can customize the grants given to these users by changing the $grants property.
         */
            // 'mysql' => Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager::class,

        /**
         * Disable the pgsql manager above, and enable the one below if you
         * want to separate tenant DBs by schemas rather than databases.
         */
            // 'pgsql' => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager::class, // Separate by schema instead of database
        ],
    ],

    /**
     * Cache tenancy config. Used by CacheTenancyBootstrapper.
     *
     * This works for all Cache facade calls, cache() helper
     * calls and direct calls to injected cache stores.
     *
     * Each key in cache will have a tag applied on it. This tag is used to
     * scope the cache both when writing to it and when reading from it.
     *
     * You can clear cache selectively by specifying the tag.
     */
    'cache' => [
        'tag_base' => 'tenant', // This tag_base, followed by the tenant_id, will form a tag that will be applied on each cache call.
    ],

    /**
     * Filesystem tenancy config. Used by FilesystemTenancyBootstrapper.
     * https://tenancyforlaravel.com/docs/v3/tenancy-bootstrappers/#filesystem-tenancy-boostrapper.
     */
    'filesystem' => [
        /**
         * Each disk listed in the 'disks' array will be suffixed by the suffix_base, followed by the tenant_id.
         */
        'suffix_base' => 'tenant',
        'disks' => [
            'local',
            'public',
            // 's3',
        ],

        /**
         * Use this for local disks.
         *
         * See https://tenancyforlaravel.com/docs/v3/tenancy-bootstrappers/#filesystem-tenancy-boostrapper
         */
        'root_override' => [
            // Disks whose roots should be overridden after storage_path() is suffixed.
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],

        /**
         * Should storage_path() be suffixed.
         *
         * Note: Disabling this will likely break local disk tenancy. Only disable this if you're using an external file storage service like S3.
         *
         * For the vast majority of applications, this feature should be enabled. But in some
         * edge cases, it can cause issues (like using Passport with Vapor - see #196), so
         * you may want to disable this if you are experiencing these edge case issues.
         */
        'suffix_storage_path' => true,

        /**
         * By default, asset() calls are made multi-tenant too. You can use global_asset() and mix()
         * for global, non-tenant-specific assets. However, you might have some issues when using
         * packages that use asset() calls inside the tenant app. To avoid such issues, you can
         * disable asset() helper tenancy and explicitly use tenant_asset() calls in places
         * where you want to use tenant-specific assets (product images, avatars, etc).
         */
        'asset_helper_tenancy' => true,
    ],

    /**
     * Redis tenancy config. Used by RedisTenancyBootstrapper.
     *
     * Note: You need phpredis to use Redis tenancy.
     *
     * Note: You don't need to use this if you're using Redis only for cache.
     * Redis tenancy is only relevant if you're making direct Redis calls,
     * either using the Redis facade or by injecting it as a dependency.
     */
    'redis' => [
        'prefix_base' => 'tenant', // Each key in Redis will be prepended by this prefix_base, followed by the tenant id.
        'prefixed_connections' => [ // Redis connections whose keys are prefixed, to separate one tenant's keys from another.
            // 'default',
        ],
    ],

    /**
     * Features are classes that provide additional functionality
     * not needed for tenancy to be bootstrapped. They are run
     * regardless of whether tenancy has been initialized.
     *
     * See the documentation page for each class to
     * understand which ones you want to enable.
     */
    'features' => [
        // Stancl\Tenancy\Features\UserImpersonation::class,
        // Stancl\Tenancy\Features\TelescopeTags::class,
        // Stancl\Tenancy\Features\UniversalRoutes::class,
        // Stancl\Tenancy\Features\TenantConfig::class, // https://tenancyforlaravel.com/docs/v3/features/tenant-config
        // Stancl\Tenancy\Features\CrossDomainRedirect::class, // https://tenancyforlaravel.com/docs/v3/features/cross-domain-redirect
        // Stancl\Tenancy\Features\ViteBundler::class,
    ],

    /**
     * Should tenancy routes be registered.
     *
     * Tenancy routes include tenant asset routes. By default, this route is
     * enabled. But it may be useful to disable them if you use external
     * storage (e.g. S3 / Dropbox) or have a custom asset controller.
     */
    'routes' => true,

    /**
     * Parameters used by the tenants:migrate command.
     */
    'migration_parameters' => [
        '--force' => true, // This needs to be true to run migrations in production.
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

    /**
     * Parameters used by the tenants:seed command.
     */
    'seeder_parameters' => [
        '--class' => 'DatabaseSeeder', // root seeder class
        // '--force' => true, // This needs to be true to seed tenant databases in production
    ],
];
