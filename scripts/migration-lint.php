<?php

declare(strict_types=1);

/*
 * Tenant-isolation migration linter (ADR-0002 §D6).
 *
 * Fails CI if a migration creates a table carrying a `tenant_id` column but never attaches
 * Row-Level Security via withTenantIsolation() (or TenantIsolation::) — the highest-blast-radius
 * mistake possible in this schema, and the one no code-review convention should be trusted to catch.
 * Tenant-scoped tables on the explicit exemption list (ADR-0002 §D1) are allowed to skip isolation.
 *
 * Standalone (nikic/php-parser), same pattern as scripts/controller-gate.php.
 *
 * Usage: php scripts/migration-lint.php
 */

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

require __DIR__.'/../vendor/autoload.php';

/**
 * Tables that legitimately carry (or resemble) a tenant discriminator yet are intentionally NOT
 * RLS-protected (ADR-0002 §D1). None of these declare a bare `tenant_id`; listed for clarity and so
 * the rule is explicit rather than implicit.
 */
const EXEMPT_TABLES = [
    'tenants',              // the central discriminator table itself
    'domains',             // stancl/tenancy subdomain->tenant lookup: read BEFORE tenant context
    // exists (it's what resolves the tenant), so RLS-scoping it is circular
    'sessions',            // framework-internal, swept without tenant context
    'password_reset_tokens',
    'cache', 'cache_locks',
    'jobs', 'job_batches', 'failed_jobs',
    'migrations',
];

$root = dirname(__DIR__);
$dir = $root.'/database/migrations';

$parser = (new ParserFactory)->createForNewestSupportedVersion();
$finder = new NodeFinder;

$violations = [];
$scanned = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $scanned++;
    $code = (string) file_get_contents($file->getPathname());
    $relative = substr($file->getPathname(), strlen($root) + 1);

    $ast = $parser->parse($code);
    if ($ast === null) {
        continue;
    }

    $createdTables = created_table_names($ast, $finder);
    if ($createdTables === []) {
        continue; // an alter-only migration; not our concern
    }

    $nonExempt = array_values(array_diff($createdTables, EXEMPT_TABLES));
    if ($nonExempt === [] || ! declares_tenant_column($ast, $finder)) {
        continue;
    }

    if (! attaches_isolation($ast, $finder)) {
        $violations[] = sprintf(
            '%s: creates tenant-scoped table(s) [%s] with a `tenant_id` column but never calls '
                .'withTenantIsolation()/TenantIsolation:: (ADR-0002 §D2). Add the RLS helper, or add '
                .'the table to EXEMPT_TABLES with a documented rationale.',
            $relative,
            implode(', ', $nonExempt)
        );
    }
}

if ($violations !== []) {
    fwrite(STDERR, "Migration linter FAILED:\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, "  - {$violation}\n");
    }
    exit(1);
}

fwrite(STDOUT, sprintf("Migration linter passed (%d migration file(s) scanned).\n", $scanned));
exit(0);

/**
 * Table names passed to Schema::create('name', …) in this migration.
 *
 * @param  array<int, Node>  $ast
 * @return list<string>
 */
function created_table_names(array $ast, NodeFinder $finder): array
{
    $names = [];

    foreach ($finder->findInstanceOf($ast, StaticCall::class) as $call) {
        if (! $call->class instanceof Node\Name || $call->name instanceof Node\Expr) {
            continue;
        }

        if ($call->class->getLast() !== 'Schema' || $call->name->toString() !== 'create') {
            continue;
        }

        $first = $call->args[0] ?? null;
        if ($first instanceof Node\Arg && $first->value instanceof String_) {
            $names[] = $first->value->value;
        }
    }

    return $names;
}

/**
 * True if any Blueprint column method (`->uuid('tenant_id')`, `->foreignUuid('tenant_id')`, …) names
 * a `tenant_id` column.
 *
 * @param  array<int, Node>  $ast
 */
function declares_tenant_column(array $ast, NodeFinder $finder): bool
{
    foreach ($finder->findInstanceOf($ast, MethodCall::class) as $call) {
        $first = $call->args[0] ?? null;
        if ($first instanceof Node\Arg && $first->value instanceof String_ && $first->value->value === 'tenant_id') {
            return true;
        }
    }

    return false;
}

/**
 * True if the migration attaches RLS, via the global withTenantIsolation() helper or a direct
 * TenantIsolation::* call.
 *
 * @param  array<int, Node>  $ast
 */
function attaches_isolation(array $ast, NodeFinder $finder): bool
{
    foreach ($finder->findInstanceOf($ast, FuncCall::class) as $call) {
        if ($call->name instanceof Node\Name && $call->name->toString() === 'withTenantIsolation') {
            return true;
        }
    }

    foreach ($finder->findInstanceOf($ast, StaticCall::class) as $call) {
        if ($call->class instanceof Node\Name && $call->class->getLast() === 'TenantIsolation') {
            return true;
        }
    }

    return false;
}
