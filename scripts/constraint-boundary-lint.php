<?php

declare(strict_types=1);

/*
 * Constraint-boundary linter (ADR-0002 §D5).
 *
 * Fails CI if a migration declares a unique constraint or a foreign key that can span a tenant
 * boundary — a unique whose key omits `tenant_id` on a tenant-scoped table, or an FK into a
 * tenant-scoped table whose key omits it — unless the constraint is recorded in
 * `App\Support\Tenancy\ConstraintBoundaries` with a rationale. §D5's stated reason is the per-tenant
 * extraction path, which `tenants:extract` now walks, and PostgreSQL bypasses row security for
 * referential-integrity checks, so RLS does not confine either shape.
 *
 * ── THIS IS NOT THE AUTHORITY, AND KNOWING WHY IS THE POINT ─────────────────────────────────────────
 * `tests/Feature/Tenancy/ConstraintBoundaryDriftTest.php` is. It reads `pg_index` and `pg_constraint`
 * on a freshly migrated database, so it sees what PostgreSQL actually enforces, both directions, with
 * no inference. This script cannot match that and does not try — it runs where that test cannot (the
 * `static-analysis` CI job has no Postgres service, and this Windows host has no `pdo_pgsql` at all),
 * and it answers the one question the catalog cannot: WHICH MIGRATION AND WHICH LINE wrote the thing.
 * A drift failure names `submissions_form_id_foreign`; this names the file to open.
 *
 * Deliberate blind spots, stated here rather than discovered later. Each is why the test is the
 * authority: a table that gains `tenant_id` in a LATER migration than its unique; `->change()`;
 * renames; constraints created by a package's own migrations; and `down()` bodies, which are skipped
 * on purpose — `generalize_webhook_deliveries_owner` restores a pre-existing constraint in its
 * `down()`, and counting it would report a violation nobody can act on.
 *
 * Standalone (nikic/php-parser), same pattern as scripts/migration-lint.php.
 *
 * Usage: php scripts/constraint-boundary-lint.php
 */

use App\Support\Tenancy\ConstraintBoundaries;
use App\Support\Tenancy\TenantScopedTables;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

require __DIR__.'/../vendor/autoload.php';

/*
 * ⚠️ TWO APP CLASSES ARE LOADED THROUGH THE AUTOLOADER, WITHOUT BOOTING THE FRAMEWORK.
 * `scripts/job-payload-lint.php` duplicates its expectations rather than importing them, on the
 * grounds that "a linter that reads its expectations from the code it lints cannot fail". That
 * argument does not reach here: the code being linted is `database/migrations`, while
 * `ConstraintBoundaries` and `TenantScopedTables` are decision records ABOUT it, each already pinned
 * to the live catalog by its own drift test. Duplicating 42 constraint names into this file would
 * create a third copy that nothing compares against — the failure mode the drift tests exist to
 * prevent. Both classes are plain constants and static methods with no container dependency.
 * (`scripts/regenerate-brand-ramp-fixture.php` is the precedent for reaching an app class this way.)
 */

$root = dirname(__DIR__);
$dir = $root.'/database/migrations';

$parser = (new ParserFactory)->createForNewestSupportedVersion();
$finder = new NodeFinder;

/*
 * A method chain nests INSIDE OUT: `$table->foreignUuid('x')->constrained('t')` parses as a
 * `constrained` call whose `var` is the `foreignUuid` call, so the target table sits in a node that is
 * the PARENT of the one naming the column. Searching a node's own subtree can never reach it, which is
 * why parent links are attached rather than the chain being walked downward only.
 */
$traverser = new NodeTraverser(new ParentConnectingVisitor);

/** Tables known to carry `tenant_id`, plus any a migration under scan declares for the first time. */
$tenantTables = TenantScopedTables::everyTenantIdCarrier();

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
$constraints = 0;
$unresolved = 0;

/** @var list<SplFileInfo> $files */
$files = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() === 'php') {
        $files[] = clone $file;
    }
}

/*
 * ⚠️ A PRE-PASS FOR DROPPED TABLES, AND IT WAS NOT ANTICIPATED — THE FIRST RUN OF THIS SCRIPT FOUND IT.
 * `form_collaborators` was created in July with a single-column FK into `forms` and dropped four
 * migrations later, so the declaration is still in the tree while the constraint is not in any
 * database. Reporting it would demand a rationale for a constraint nobody can fix, and recording one
 * in `ConstraintBoundaries` would immediately redden the drift test's stale-entry direction, which
 * asks the catalog and is right to.
 *
 * This is the AST/catalog divergence in miniature and the sharpest argument for which of the two is
 * the authority: source text describes what was once written, the catalog describes what is enforced.
 * (Drop-then-recreate would defeat the pre-pass; nothing in this tree does it, and the drift test
 * would catch the resulting constraint anyway.)
 */
$dropped = [];

foreach ($files as $file) {
    $ast = $parser->parse((string) file_get_contents($file->getPathname()));
    if ($ast !== null) {
        $dropped = [...$dropped, ...dropped_table_names(up_method_stmts($ast, $finder), $finder)];
    }
}

foreach ($files as $file) {
    $scanned++;
    $code = (string) file_get_contents($file->getPathname());
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

    $ast = $parser->parse($code);
    if ($ast === null) {
        continue;
    }

    $ast = $traverser->traverse($ast);

    // `up()` only. A migration's `down()` legitimately restores whatever shape preceded it.
    $up = up_method_stmts($ast, $finder);
    if ($up === []) {
        continue;
    }

    // Raw DDL first: `DB::statement('CREATE UNIQUE INDEX …')` sits at the top level of `up()`, outside
    // any Schema block, and nine of this schema's unique indexes are declared that way because a PARTIAL
    // unique is not expressible through Blueprint at all.
    foreach (raw_ddl_constraints($up, $finder) as [$name, $table, $columns, $kind, $target, $line]) {
        if ($kind === 'unreadable') {
            $unresolved++;

            continue;
        }

        if (in_array($table, $dropped, true)) {
            continue;
        }

        // A UNIQUE is judged on the table it sits ON; a FOREIGN KEY on the table it POINTS AT. Getting
        // this the same way round as the Blueprint path below is not cosmetic — see the block there.
        $resolved = null;

        if ($kind === 'unique') {
            if (! in_array($table, $tenantTables, true)) {
                continue;
            }
        } elseif (! in_array('tenant_id', $columns, true)) {
            // Compliance before resolution, for the reason spelled out on the Blueprint path below.
            $resolved = resolve_target($target, $columns, $tenantTables);

            if ($resolved === null) {
                $unresolved++;

                continue;
            }

            if (! in_array($resolved, $tenantTables, true)) {
                continue;
            }
        }

        $constraints++;

        if (in_array('tenant_id', $columns, true)) {
            continue;
        }

        $recorded = $kind === 'unique'
            ? ConstraintBoundaries::reasonForIndex($name)
            : ConstraintBoundaries::reasonForForeignKey($name);

        if ($recorded !== null) {
            continue;
        }

        $violations[] = sprintf(
            '%s:%d: raw DDL declares `%s` as %s on (%s) without `tenant_id` in the key, crossing into the '
                .'tenant-scoped `%s` (ADR-0002 §D5). Add `tenant_id` to the key, or record it in '
                .'`App\Support\Tenancy\ConstraintBoundaries` with a rationale.',
            $relative,
            $line,
            $name,
            $kind === 'unique' ? 'a unique index' : 'a foreign key',
            implode(', ', $columns),
            $kind === 'unique' ? $table : ($resolved ?? $table)
        );
    }

    foreach (schema_blocks($up, $finder) as [$table, $stmts]) {
        if (in_array($table, $dropped, true)) {
            continue; // the table does not survive the migration run — see the pre-pass above
        }

        $carriesTenant = in_array($table, $tenantTables, true)
            || declares_tenant_column($stmts, $finder);

        // ⚠️ A UNIQUE IS JUDGED ON ITS OWN TABLE; A FOREIGN KEY IS JUDGED ON THE TABLE IT POINTS AT, AND
        // THIS BLOCK USED TO SKIP BOTH WHENEVER THE SOURCE HAD NO TENANT COLUMN. A unique index constrains
        // the rows it sits on, so an untenanted table's unique spans nothing — that early return is right
        // for uniques and only for uniques. A foreign key is the other way round: `role_has_permissions`
        // and `tenants` carry no `tenant_id` and both point INTO tenant-scoped tables, which is the exact
        // shape ConstraintBoundaries names as the reason its own predicate is written about the target.
        // Gating on the source made this script blind to all three of them — so removing any of the three
        // from the constant reddened the drift test and left this at exit 0, while the threat model claimed
        // they were "blocked at authoring time". Found by the adversarial pass, after 4 of 6 CI jobs were
        // green.
        foreach (unique_declarations($table, $stmts, $finder) as [$name, $columns, $line]) {
            if (! $carriesTenant) {
                continue;
            }

            $constraints++;

            if (in_array('tenant_id', $columns, true) || ConstraintBoundaries::reasonForIndex($name) !== null) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d: `%s` is unique on (%s) — a tenant-scoped table, but `tenant_id` is not in the key, '
                    .'so PostgreSQL enforces it across every tenant at once and can refuse one tenant a value '
                    .'because of a row it cannot see (ADR-0002 §D5). Add `tenant_id` to the key, or add the '
                    .'index to `App\Support\Tenancy\ConstraintBoundaries::UNIQUE_EXCEPTIONS` with a rationale.',
                $relative,
                $line,
                $name,
                implode(', ', $columns)
            );
        }

        foreach (foreign_key_declarations($table, $stmts, $finder) as [$name, $columns, $target, $line]) {
            // ⚠️ ORDER MATTERS AND GETTING IT WRONG INFLATED `unresolved` FROM 0 TO 27. A key that already
            // carries `tenant_id` is compliant whatever it points at, and resolving the target first sent
            // every COMPOSITE key — two columns, so no `{stem}_id` to match — down the unresolved path.
            // Compliance is cheaper to decide than resolution, so it is decided first.
            if (in_array('tenant_id', $columns, true)) {
                $constraints++;

                continue;
            }

            $resolved = resolve_target($target, $columns, $tenantTables);

            if ($resolved === null) {
                // Counted and reported rather than passed over in silence: an unresolvable target is a
                // gap in THIS script, and a gap nobody can see is indistinguishable from a clean run.
                $unresolved++;

                continue;
            }

            if (! in_array($resolved, $tenantTables, true)) {
                continue; // points at a central table (users, tenants, plans) — no boundary to cross
            }

            $constraints++;

            if (ConstraintBoundaries::reasonForForeignKey($name) !== null) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d: `%s` references the tenant-scoped `%s` on (%s) without `tenant_id` in the key. '
                    .'PostgreSQL bypasses row security for referential-integrity checks, so it will accept a '
                    .'reference to another tenant\'s row and act on the ON DELETE clause (ADR-0002 §D5). '
                    .'Declare the composite shape — foreign([\'tenant_id\', \'%s\'])->references([\'tenant_id\', '
                    .'\'id\'])->on(\'%s\'), which nine constraints already use — or add it to '
                    .'`App\Support\Tenancy\ConstraintBoundaries::FOREIGN_KEY_EXCEPTIONS` with a rationale.',
                $relative,
                $line,
                $name,
                $resolved,
                implode(', ', $columns),
                $columns[0] ?? 'x_id',
                $resolved
            );
        }
    }
}

if ($scanned < MIN_EXPECTED_MIGRATIONS && $violations === []) {
    // A gate nobody can tell is blind is a gate nobody is running. Mirrors R2 in
    // scripts/component-import-lint.php, which was the only one of the five gates to have a floor.
    fwrite(STDERR, sprintf(
        "Constraint boundary linter FAILED: scanned only %d migration file(s), expected at least %d.\n".
        "  This is a DISCOVERY regression, not a clean run — a scan root has moved, or the gate is\n".
        "  running somewhere its iterator cannot see the whole tree. Run it on the HOST, not inside\n".
        "  the app container — on this host the container reports 86 of 113.\n",
        $scanned,
        MIN_EXPECTED_MIGRATIONS
    ));
    exit(1);
}

if ($violations !== []) {
    fwrite(STDERR, "Constraint boundary linter FAILED:\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, "  - {$violation}\n");
    }
    exit(1);
}

fwrite(STDOUT, sprintf(
    'Constraint boundary linter passed (%d migration file(s) scanned, %d constraint(s) checked, '
        ."%d constraint(s) not statically readable).\n",
    $scanned,
    $constraints,
    $unresolved
));
exit(0);

/**
 * The statements of the migration's `up()` method.
 *
 * @param  array<int, Node>  $ast
 * @return array<int, Node>
 */
function up_method_stmts(array $ast, NodeFinder $finder): array
{
    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
        if ($method->name->toString() === 'up') {
            return $method->stmts ?? [];
        }
    }

    return [];
}

/**
 * Constraints declared in raw SQL passed to `DB::statement()` / `DB::unprepared()`, as
 * [name, table, columns, 'unique'|'foreign', target|null, line]. A `null` name signals SQL that could
 * not be folded, which the caller counts rather than discards.
 *
 * ⚠️ THIS RULE WAS SPECIFIED, NOT WRITTEN, AND THE MUTATION PASS IS WHAT NOTICED. Removing
 * `settings_platform_key_unique` from the constant reddened the drift test and left this script at
 * exit 0 — because that index, like eight others here, is a PARTIAL unique, which Blueprint cannot
 * express, so it is raw DDL the Blueprint walk never saw. A gate silent on nine of the schema's
 * unique indexes would have shipped looking complete.
 *
 * Reads the string LITERALS, not the file text, which is what keeps two `CREATE UNIQUE INDEX`
 * occurrences inside docblocks in `add_reference_to_submissions` from being counted as DDL — the
 * argument for AST over grep, made concrete.
 *
 * ⚠️ `preg_match_all`, AND EVERY STATEMENT SPLIT ON `;` FIRST. `DB::unprepared()` exists to carry more
 * than one statement, and a single-match parse would check the first `CREATE UNIQUE INDEX` in a string
 * and silently pass the second. Splitting also keeps `raw_altered_table()` honest, which otherwise
 * attributes every `ADD CONSTRAINT` in a multi-statement string to the FIRST `ALTER TABLE` in it.
 *
 * @param  array<int, Node>  $nodes
 * @return list<array{0: string|null, 1: string, 2: list<string>, 3: string, 4: string|null, 5: int}>
 */
function raw_ddl_constraints(array $nodes, NodeFinder $finder): array
{
    $found = [];

    foreach ($finder->findInstanceOf($nodes, StaticCall::class) as $call) {
        if (! $call->class instanceof Node\Name || $call->name instanceof Node\Expr) {
            continue;
        }

        if ($call->class->getLast() !== 'DB'
            || ! in_array($call->name->toString(), ['statement', 'unprepared'], true)) {
            continue;
        }

        $first = $call->args[0] ?? null;
        $sql = $first instanceof Node\Arg ? fold_string($first->value) : null;

        if ($sql === null) {
            // ⚠️ REPORTED, NOT SKIPPED — BUT ONLY WHEN IT COULD PLAUSIBLY BE A CONSTRAINT. This branch
            // used to `continue` in silence, which is the failure this script's own header names twice;
            // 27 interpolated `DB::statement("… {$var} …")` calls exist in this tree. Reporting all 27 as
            // a gap is the opposite failure: a permanently-nonzero blind-spot count is one nobody reads,
            // and calling them "unresolved FK targets" was wrong twice over — they are neither FKs nor
            // targets. So the literal fragments are inspected: if the parts the parser CAN see contain no
            // constraint keyword, the statement is not constraint DDL and is not this gate's business.
            // What survives is the honest residue — SQL that looks like a constraint and cannot be read.
            $skeleton = $first instanceof Node\Arg ? sql_skeleton($first->value) : '';

            if (preg_match('/CREATE\s+UNIQUE\s+INDEX|FOREIGN\s+KEY/i', $skeleton) === 1) {
                $found[] = [null, '', [], 'unreadable', null, $call->getLine()];
            }

            continue;
        }

        foreach (explode(';', $sql) as $statement) {
            if (preg_match_all('/CREATE\s+UNIQUE\s+INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)\s+ON\s+(\w+)\s*\(([^)]*)\)/is', $statement, $ms, PREG_SET_ORDER) > 0) {
                foreach ($ms as $m) {
                    $found[] = [$m[1], $m[2], sql_column_list($m[3]), 'unique', null, $call->getLine()];
                }
            }

            if (preg_match_all('/ADD\s+CONSTRAINT\s+(\w+)\s+FOREIGN\s+KEY\s*\(([^)]*)\)\s*REFERENCES\s+(\w+)/is', $statement, $ms, PREG_SET_ORDER) > 0) {
                foreach ($ms as $m) {
                    $found[] = [
                        $m[1],
                        raw_altered_table($statement) ?? '',
                        sql_column_list($m[2]),
                        'foreign',
                        $m[3],
                        $call->getLine(),
                    ];
                }
            }
        }
    }

    return $found;
}

/** The table named by an `ALTER TABLE x …` statement. */
function raw_altered_table(string $sql): ?string
{
    return preg_match('/ALTER\s+TABLE\s+(?:ONLY\s+)?(\w+)/is', $sql, $m) === 1 ? $m[1] : null;
}

/**
 * Column names from a parenthesised SQL list.
 *
 * @return list<string>
 */
function sql_column_list(string $inner): array
{
    $columns = [];

    foreach (explode(',', $inner) as $part) {
        $name = trim($part);
        if ($name !== '') {
            $columns[] = $name;
        }
    }

    return $columns;
}

/**
 * The literal fragments of a string expression, with interpolations blanked out.
 *
 * Used only to ask "could this statement be constraint DDL at all". A `"ALTER TABLE {$t} ADD
 * CONSTRAINT {$c} CHECK (…)"` yields a skeleton with no constraint keyword and is correctly ignored;
 * one containing the literal words `FOREIGN KEY` is reported as unreadable even though its table and
 * columns cannot be recovered.
 */
function sql_skeleton(Node $node): string
{
    if ($node instanceof String_) {
        return $node->value;
    }

    if ($node instanceof Node\Expr\BinaryOp\Concat) {
        return sql_skeleton($node->left).' '.sql_skeleton($node->right);
    }

    if ($node instanceof Node\Scalar\InterpolatedString) {
        $text = '';

        foreach ($node->parts as $part) {
            $text .= $part instanceof Node\InterpolatedStringPart ? $part->value : ' ';
        }

        return $text;
    }

    return '';
}

/**
 * Constant-fold a string expression: a literal, or a chain of them concatenated.
 *
 * Non-interpolated heredocs parse as plain `String_`, so they fold for free. Anything carrying a
 * variable returns null and is skipped rather than half-read — a partially folded statement would
 * produce a constraint name that matches nothing in the constant and read as a violation.
 */
function fold_string(Node $node): ?string
{
    if ($node instanceof String_) {
        return $node->value;
    }

    if (! $node instanceof Node\Expr\BinaryOp\Concat) {
        return null;
    }

    $left = fold_string($node->left);
    $right = fold_string($node->right);

    return $left === null || $right === null ? null : $left.$right;
}

/**
 * Table names dropped by a migration's `up()`.
 *
 * ⚠️ `up()` ONLY, AND THE FIRST VERSION OF THIS FUNCTION SCANNED THE WHOLE FILE. Its comment argued
 * that including `down()` "cannot miss a drop written unconventionally" and cost nothing. It cost
 * everything: every create-migration's `down()` drops the table its `up()` just created, so the
 * dropped set became the entire schema and the linter skipped all 101 files. It still exited 0.
 *
 * The only reason that was caught is that the pass message prints how many constraints it CHECKED,
 * and the number was zero. A gate that reports only its verdict cannot distinguish "nothing is wrong"
 * from "nothing was examined" — which is the same lesson P2b's manifest records as measuring rather
 * than asserting, arriving here one increment later.
 *
 * @param  array<int, Node>  $nodes
 * @return list<string>
 */
function dropped_table_names(array $nodes, NodeFinder $finder): array
{
    $names = [];

    foreach ($finder->findInstanceOf($nodes, StaticCall::class) as $call) {
        if (! $call->class instanceof Node\Name || $call->name instanceof Node\Expr) {
            continue;
        }

        if ($call->class->getLast() !== 'Schema'
            || ! in_array($call->name->toString(), ['drop', 'dropIfExists'], true)) {
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
 * Every `Schema::create('t', …)` / `Schema::table('t', …)` block, as [table, statements].
 *
 * @param  array<int, Node>  $nodes
 * @return list<array{0: string, 1: array<int, Node>}>
 */
function schema_blocks(array $nodes, NodeFinder $finder): array
{
    $blocks = [];

    foreach ($finder->findInstanceOf($nodes, StaticCall::class) as $call) {
        if (! $call->class instanceof Node\Name || $call->name instanceof Node\Expr) {
            continue;
        }

        if ($call->class->getLast() !== 'Schema'
            || ! in_array($call->name->toString(), ['create', 'table'], true)) {
            continue;
        }

        $first = $call->args[0] ?? null;
        if (! $first instanceof Node\Arg || ! $first->value instanceof String_) {
            continue;
        }

        $blocks[] = [$first->value->value, [$call]];
    }

    return $blocks;
}

/**
 * True if a Blueprint column method in these statements names a `tenant_id` column.
 *
 * @param  array<int, Node>  $nodes
 */
function declares_tenant_column(array $nodes, NodeFinder $finder): bool
{
    foreach ($finder->findInstanceOf($nodes, MethodCall::class) as $call) {
        $first = $call->args[0] ?? null;
        if ($first instanceof Node\Arg && $first->value instanceof String_
            && $first->value->value === 'tenant_id') {
            return true;
        }
    }

    return false;
}

/**
 * Unique declarations in a Schema block, as [name, columns, line].
 *
 * Laravel derives an unnamed index's name as `{table}_{columns joined by _}_unique`, which is the
 * name the catalog will carry and therefore the key `ConstraintBoundaries` is written against.
 *
 * @param  array<int, Node>  $nodes
 * @return list<array{0: string, 1: list<string>, 2: int}>
 */
function unique_declarations(string $table, array $nodes, NodeFinder $finder): array
{
    $found = [];

    foreach ($finder->findInstanceOf($nodes, MethodCall::class) as $call) {
        if ($call->name instanceof Node\Expr || $call->name->toString() !== 'unique') {
            continue;
        }

        $columns = string_list($call->args[0] ?? null);
        if ($columns === []) {
            // `->string('x')->unique()` — the fluent form, whose column is the chain's own.
            $columns = fluent_column($call);
        }

        if ($columns === []) {
            continue;
        }

        $found[] = [
            explicit_name($call->args[1] ?? null) ?? $table.'_'.implode('_', $columns).'_unique',
            $columns,
            $call->getLine(),
        ];
    }

    return $found;
}

/**
 * Foreign-key declarations in a Schema block, as [name, columns, explicit target or null, line].
 *
 * Two shapes: `->foreign([cols], 'name')->…->on('t')` and the fluent
 * `->foreignUuid('x_id')->constrained('t')`. Laravel names both `{table}_{columns}_foreign`.
 *
 * @param  array<int, Node>  $nodes
 * @return list<array{0: string, 1: list<string>, 2: string|null, 3: int}>
 */
function foreign_key_declarations(string $table, array $nodes, NodeFinder $finder): array
{
    $found = [];

    foreach ($finder->findInstanceOf($nodes, MethodCall::class) as $call) {
        if ($call->name instanceof Node\Expr) {
            continue;
        }

        $method = $call->name->toString();

        if ($method === 'foreign') {
            $columns = string_list($call->args[0] ?? null);
            if ($columns === []) {
                continue;
            }

            $found[] = [
                explicit_name($call->args[1] ?? null) ?? $table.'_'.implode('_', $columns).'_foreign',
                $columns,
                chained_string_arg($call, 'on', $finder),
                $call->getLine(),
            ];

            continue;
        }

        // The fluent form only declares an FK once `->constrained()` is chained onto it.
        if (! in_array($method, ['foreignUuid', 'foreignId', 'foreignUlid'], true)) {
            continue;
        }

        $columns = string_list($call->args[0] ?? null);
        if ($columns === [] || ! chains_constrained($call, $finder)) {
            continue;
        }

        $found[] = [
            $table.'_'.implode('_', $columns).'_foreign',
            $columns,
            chained_string_arg($call, 'constrained', $finder),
            $call->getLine(),
        ];
    }

    return $found;
}

/**
 * The table a foreign key points at: the explicit `->on('t')` / `->constrained('t')` argument, or
 * Laravel's own inference from a `{stem}_id` column name.
 *
 * ⚠️ NO PLURALISER. The inference matches the stem against the KNOWN table names rather than
 * generating one, so `form_version_id` resolves by finding `form_versions` in the list. A stem that
 * matches nothing returns null and is counted as unresolved — `attachable_id` on a polymorphic
 * column has no single target and must not be guessed at.
 *
 * @param  list<string>  $columns
 * @param  list<string>  $tenantTables
 */
function resolve_target(?string $explicit, array $columns, array $tenantTables): ?string
{
    if ($explicit !== null) {
        return $explicit;
    }

    if (count($columns) !== 1 || ! str_ends_with($columns[0], '_id')) {
        return null;
    }

    $stem = substr($columns[0], 0, -3);

    foreach ([$stem.'s', $stem, $stem.'es'] as $candidate) {
        if (in_array($candidate, $tenantTables, true)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * True if `->constrained()` appears anywhere in this call's method chain.
 *
 * @param  MethodCall  $call
 */
function chains_constrained(Node $call, NodeFinder $finder): bool
{
    return chained_call($call, 'constrained', $finder) !== null;
}

/**
 * The first string argument of a named call in this expression's chain, if any.
 *
 * @param  MethodCall  $call
 */
function chained_string_arg(Node $call, string $method, NodeFinder $finder): ?string
{
    $target = chained_call($call, $method, $finder);
    if ($target === null) {
        return null;
    }

    $first = $target->args[0] ?? null;

    return $first instanceof Node\Arg && $first->value instanceof String_ ? $first->value->value : null;
}

/**
 * Find a named call wrapping this one. `$table->foreignUuid('x')->constrained('t')` parses as a
 * `constrained` MethodCall whose `var` is the `foreignUuid` MethodCall, so the chain is walked from
 * the OUTSIDE in — searching this node's own subtree would never reach its callers.
 *
 * @param  MethodCall  $call
 */
function chained_call(Node $call, string $method, NodeFinder $finder): ?MethodCall
{
    $parent = $call->getAttribute('parent');

    while ($parent instanceof MethodCall) {
        if (! $parent->name instanceof Node\Expr && $parent->name->toString() === $method) {
            return $parent;
        }

        $parent = $parent->getAttribute('parent');
    }

    return null;
}

/**
 * A string literal argument, or a list of them.
 *
 * @return list<string>
 */
function string_list(?Node $arg): array
{
    if (! $arg instanceof Node\Arg) {
        return [];
    }

    if ($arg->value instanceof String_) {
        return [$arg->value->value];
    }

    if (! $arg->value instanceof Node\Expr\Array_) {
        return [];
    }

    $values = [];

    foreach ($arg->value->items as $item) {
        if ($item instanceof Node\ArrayItem && $item->value instanceof String_) {
            $values[] = $item->value->value;
        }
    }

    return $values;
}

/** An explicitly supplied constraint name, if the argument is a string literal. */
function explicit_name(?Node $arg): ?string
{
    return $arg instanceof Node\Arg && $arg->value instanceof String_ ? $arg->value->value : null;
}

/**
 * The column a fluent `->…('col')->unique()` chain applies to.
 *
 * @param  MethodCall  $call
 * @return list<string>
 */
function fluent_column(Node $call): array
{
    $inner = $call->var ?? null;

    while ($inner instanceof MethodCall) {
        $first = $inner->args[0] ?? null;
        if ($first instanceof Node\Arg && $first->value instanceof String_) {
            return [$first->value->value];
        }

        $inner = $inner->var;
    }

    return [];
}
