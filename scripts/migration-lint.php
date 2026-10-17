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
 * M176 (`R-a11cc78a`, `D137`) — a second rule: MIGRATIONS ARE ADDITIVE. A migration's `up()` may not drop a table or a
 * column, truncate, or delete rows, unless `DESTRUCTIVE_ALLOWED` names it with an answered decision. The user
 * (2026-10-16): *"always make sure that the staging has data, do not remove data"* — and such a migration passed every
 * gate there was. `deploy.ps1` counts the rows before and after it migrates (the other half of the row); this is the
 * half that refuses the change before it is merged. Only `up()` is read: `down()` undoes what `up()` added, so it drops
 * by design (the trap `constraint-boundary-lint.php` records — scanning `down()` made "the dropped set" the whole schema).
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
 * RLS-protected (ADR-0002 §D1); listed for clarity and so the rule is explicit rather than implicit.
 *
 * ⚠️ THIS BLOCK USED TO CLAIM "None of these declare a bare `tenant_id`", AND THAT WAS FALSE.
 * `domains` declares `tenant_id` NOT NULL and has `relrowsecurity = false` with zero policies — which is
 * correct and deliberate (it is the table read to decide WHICH TENANT a request is, so scoping it by
 * tenant would be circular), but it is exactly the case the sentence denied existed. Corrected by P2a,
 * which found it by sweeping the catalog rather than by re-reading this file.
 *
 * The consequence is worth stating where the exemption lives: any future code that reasons "carries
 * `tenant_id`, therefore RLS is filtering it" is wrong about `domains` specifically, and would read every
 * tenant's hostnames. `App\Support\Tenancy\TenantScopedTables` holds that distinction as data, and
 * `TenantTableClassificationDriftTest` fails if this list and the database ever disagree.
 * (Named in backticks rather than `{@see}` on purpose: this is a standalone script, and Pint's
 * fully_qualified_strict_types fixer turns a `{@see}` here into a real `use` import of an app class.)
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

/**
 * Spatie Laravel-Permission's team-scoped tables (multi-tenancy-rbac-design.md §4). In teams mode
 * every one of these carries the `team_foreign_key` (our `tenant_id`) and MUST get RLS — but Spatie's
 * *stock* published migration names both the table and the column via config variables, so the generic
 * literal-`tenant_id` check above is BLIND to it and the pivots would silently ship with no isolation.
 * This targeted rule closes that gap: any migration that touches the Spatie permission tables must
 * explicitly attach isolation to each of these by literal name.
 */
const SPATIE_TENANT_SCOPED_TABLES = ['roles', 'model_has_roles', 'model_has_permissions'];

/**
 * M176 (`R-a11cc78a`) — the migrations whose `up()` may remove something, each with its reason. ⛔ A NEW ENTRY MUST CITE
 * AN ANSWERED DECISION (`Dn`, checked against `docs/claims/decisions.md`): removing data from the testing server is the
 * user's call, never a migration's (`D137`). The one entry below predates the rule and is guarded by its own row count.
 */
const DESTRUCTIVE_ALLOWED = [
    '2026_07_20_000005_drop_form_collaborators_table.php' => 'history, before D137: drops an empty, never-used table, and refuses to when it holds a row',
];

/** Blueprint and query-builder calls that remove a column, its data, or rows. `drop` of an index or a key is not one. */
const DESTRUCTIVE_METHODS = [
    'dropColumn', 'dropColumns', 'removeColumn', 'dropConstrainedForeignId', 'dropConstrainedForeignIdFor',
    'dropMorphs', 'dropNullableMorphs', 'dropTimestamps', 'dropTimestampsTz', 'dropSoftDeletes', 'dropSoftDeletesTz',
    'dropRememberToken', 'truncate', 'delete', 'forceDelete',
];

/** `Schema::` calls that remove a table. */
const DESTRUCTIVE_SCHEMA_CALLS = ['drop', 'dropIfExists', 'dropColumns', 'dropAllTables', 'dropAllViews'];

/** Raw SQL that removes data. `DROP CONSTRAINT`, `DROP NOT NULL`, `DROP INDEX` and `ON DELETE` are not matched. */
const DESTRUCTIVE_SQL = '/\b(DROP\s+(?:TABLE|COLUMN)|TRUNCATE|DELETE\s+FROM)\b/i';

$root = dirname(__DIR__);
$dir = $root.'/database/migrations';

$parser = (new ParserFactory)->createForNewestSupportedVersion();
$finder = new NodeFinder;

/**
 * A plausible floor for the discovery check below (M36).
 *
 * The tree held 113 migration file(s) when this floor was set; it sits well below that so ordinary deletion does
 * not trip it, and well above zero so a broken or renamed scan root does.
 *
 * Measured rather than guessed: this gate run INSIDE the app container on this host reports
 * 86, because RecursiveDirectoryIterator descends the Windows bind mount only partially while
 * `find` on the same path sees every file. An under-scan here is a reproducible event today,
 * not a hypothetical one — which is why the floor is a hard failure and not a warning.
 */
const MIN_EXPECTED_MIGRATIONS = 65;

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

    // M176 (`R-a11cc78a`): the additive rule — every migration, before any early return below.
    $removes = destructive_operations($ast, $finder);
    if ($removes !== []) {
        $allowed = DESTRUCTIVE_ALLOWED[basename($relative)] ?? null;
        if ($allowed === null) {
            $violations[] = sprintf(
                '%s: its up() removes data (%s). Migrations are additive (D137): never drop a table or a column, truncate or '
                    .'delete rows on the testing server. If it truly must, the user decides: record the answer in '
                    .'docs/claims/decisions.md and name it in DESTRUCTIVE_ALLOWED.',
                $relative,
                implode('; ', $removes)
            );
        } elseif (! str_starts_with($allowed, 'history, before D137') && ! cites_answered_decision($allowed, $root)) {
            $violations[] = sprintf(
                '%s: DESTRUCTIVE_ALLOWED lets it remove data, but its reason cites no answered decision (a `Dn` under '
                    .'## ANSWERED in docs/claims/decisions.md): "%s".',
                $relative,
                $allowed
            );
        }
    }

    // Spatie-pivot rule — runs BEFORE the literal-table early-return below, because Spatie's stock
    // migration creates its tables via config variables (created_table_names() would see none of them).
    if (is_spatie_permission_migration($code)) {
        foreach (SPATIE_TENANT_SCOPED_TABLES as $spatieTable) {
            if (! attaches_isolation_for($ast, $finder, $spatieTable)) {
                $violations[] = sprintf(
                    '%s: touches the Spatie permission tables but never attaches RLS to `%s` by name '
                        .'(RBAC §4). Stock Spatie ships this team-scoped pivot with NO isolation and the '
                        .'generic linter cannot see its config-variable `tenant_id` column. Call '
                        ."TenantIsolation::strict('%s') (or nullableGlobal for `roles`) in this migration.",
                    $relative,
                    $spatieTable,
                    $spatieTable
                );
            }
        }
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

if ($scanned < MIN_EXPECTED_MIGRATIONS && $violations === []) {
    // A gate nobody can tell is blind is a gate nobody is running. Mirrors R2 in
    // scripts/component-import-lint.php, which was the only one of the five gates to have a floor.
    fwrite(STDERR, sprintf(
        "Migration linter FAILED: scanned only %d migration file(s), expected at least %d.\n".
        "  This is a DISCOVERY regression, not a clean run — a scan root has moved, or the gate is\n".
        "  running somewhere its iterator cannot see the whole tree. Run it on the HOST, not inside\n".
        "  the app container — on this host the container reports 86 of 113.\n",
        $scanned,
        MIN_EXPECTED_MIGRATIONS
    ));
    exit(1);
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

/**
 * True if this migration source touches Spatie's permission tables. Detected by the raw presence of
 * the pivot table-name strings, which appear whether they are literal `Schema::create('model_has_roles')`
 * calls (our customized migration) or `$tableNames['model_has_roles']` array-key accesses (Spatie's
 * stock stub) — so the rule fires in both the maintained and the accidentally-republished case.
 */
function is_spatie_permission_migration(string $code): bool
{
    return str_contains($code, 'model_has_roles') && str_contains($code, 'model_has_permissions');
}

/**
 * True if the migration attaches RLS to a SPECIFIC table by literal name — i.e. a
 * `withTenantIsolation('<table>', …)` or `TenantIsolation::<method>('<table>', …)` whose first argument
 * is the literal `$table`. Stricter than attaches_isolation(): that only asks "is ANY isolation call
 * present", which the generic file-level rule already trusts; this pins isolation to the exact pivot.
 *
 * @param  array<int, Node>  $ast
 */
function attaches_isolation_for(array $ast, NodeFinder $finder, string $table): bool
{
    foreach ($finder->findInstanceOf($ast, FuncCall::class) as $call) {
        if ($call->name instanceof Node\Name && $call->name->toString() === 'withTenantIsolation'
            && first_arg_is($call, $table)) {
            return true;
        }
    }

    foreach ($finder->findInstanceOf($ast, StaticCall::class) as $call) {
        if ($call->class instanceof Node\Name && $call->class->getLast() === 'TenantIsolation'
            && first_arg_is($call, $table)) {
            return true;
        }
    }

    return false;
}

/**
 * True if a call's first argument is the given string literal.
 *
 * @param  FuncCall|StaticCall  $call
 */
function first_arg_is(Node $call, string $literal): bool
{
    $first = $call->args[0] ?? null;

    return $first instanceof Node\Arg && $first->value instanceof String_ && $first->value->value === $literal;
}

/**
 * M176 (`R-a11cc78a`) — what a migration's `up()` would remove, in words. Empty when it only adds or changes.
 *
 * @param  list<Node\Stmt>  $ast
 * @return list<string>
 */
function destructive_operations(array $ast, NodeFinder $finder): array
{
    $up = null;
    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
        if ($method->name->toString() === 'up') {
            $up = $method->stmts ?? [];
        }
    }
    if ($up === null) {
        return [];
    }

    $found = [];
    foreach ($finder->findInstanceOf($up, StaticCall::class) as $call) {
        if ($call->class instanceof Node\Name && $call->class->getLast() === 'Schema' && $call->name instanceof Node\Identifier
            && in_array($call->name->toString(), DESTRUCTIVE_SCHEMA_CALLS, true)) {
            $found[] = 'Schema::'.$call->name->toString().'()';
        }
    }
    foreach ($finder->findInstanceOf($up, MethodCall::class) as $call) {
        if ($call->name instanceof Node\Identifier && in_array($call->name->toString(), DESTRUCTIVE_METHODS, true)) {
            $found[] = '->'.$call->name->toString().'()';
        }
    }
    $strings = array_merge(
        array_map(static fn (String_ $s): string => $s->value, $finder->findInstanceOf($up, String_::class)),
        array_map(
            static fn (Node\InterpolatedStringPart $p): string => $p->value,
            $finder->findInstanceOf($up, Node\InterpolatedStringPart::class)
        ),
    );
    foreach ($strings as $sql) {
        if (preg_match(DESTRUCTIVE_SQL, $sql, $match) === 1) {
            $found[] = 'SQL '.strtoupper((string) preg_replace('/\s+/', ' ', $match[1]));
        }
    }

    return array_values(array_unique($found));
}

/** Whether `$reason` names a decision answered in `docs/claims/decisions.md` (a `### Dn — ` heading under ANSWERED). */
function cites_answered_decision(string $reason, string $root): bool
{
    if (preg_match_all('/\bD(\d+)\b/', $reason, $ids) < 1) {
        return false;
    }
    $decisions = (string) @file_get_contents($root.'/docs/claims/decisions.md');
    $answered = strstr($decisions, "\n## ANSWERED");
    if ($answered === false) {
        return false;
    }
    foreach ($ids[1] as $id) {
        if (preg_match('/^### D'.$id.' — /mu', $answered) === 1) {
            return true;
        }
    }

    return false;
}
