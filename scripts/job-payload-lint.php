<?php

declare(strict_types=1);

/*
 * Job-payload gate (ADR-0007 "Companion CI gate"; discharges ADR-0002 §D6).
 *
 * ADR-0002 §D6 (`:151`) promised "a static check (or a runtime assertion in the base job class)" that
 * every queued job handling tenant-scoped data declares a tenant_id. Neither existed for three phases:
 * there was no base job class, and scripts/controller-gate.php targets only app/Http/Controllers. H2
 * discharges it twice over — App\Jobs\TenantAwareJob carries the runtime assertion, and this script
 * catches a wrong BASE CLASS before merge. Belt and braces is deliberate: the assertion catches a bad
 * payload at run time, the linter catches a bad shape at review time.
 *
 * Rules:
 *   R1  Every ShouldQueue class extends TenantAwareJob or MaintenanceJob (or is in EXEMPT_JOBS).
 *   R2  Every concrete job carries #[Queue(QueueName::X)] — itself or inherited — from the §D6 catalog.
 *   R3  Every payload property/promoted parameter is typed from a closed SCALAR whitelist (§D5).
 *   R4  $deleteWhenMissingModels is never set true (§D5's named trap).
 *   R5  Nothing under app/Jobs/ calls TenantContext::apply()/forget() — the base class and the §D4
 *       listener own context; a job doing it by hand is the improvisation §D2 exists to end.
 *
 * WHY R3 IS A WHITELIST, NOT A MODEL DETECTOR. "Is this property an Eloquent model" is UNDECIDABLE
 * from a type declaration: SerializesModels::__serialize reflects over every non-static property and
 * SerializesAndRestoresModelIdentifiers branches on the RUNTIME VALUE, so `mixed`, untyped, `object`
 * and interface-typed properties all reach restoreModel() invisibly. Narrowing the accepted language
 * is the only form of §D5 that can actually be enforced. Do not "relax" the untyped/mixed ban — it is
 * what makes the rule decidable at all.
 *
 * Two deliberate departures from scripts/controller-gate.php and scripts/migration-lint.php:
 *   - TWO PASSES. NameResolver resolves names but not ancestry, so pass 1 builds an FQCN → parent map
 *     across app/ and pass 2 applies the rules. A parent that resolves outside app/ fails LOUDLY
 *     rather than passing silently (that is the future queued-Mailable case, which needs a decision,
 *     not a default).
 *   - NEVER ->getLast(). Those two scripts compare short names; here every comparison is against a
 *     fully-qualified name, so a shadow-named `TenantAwareJob` in another namespace cannot pass.
 *
 * Usage: php scripts/job-payload-lint.php
 */

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

require __DIR__.'/../vendor/autoload.php';

const SHOULD_QUEUE_INTERFACES = [
    'Illuminate\Contracts\Queue\ShouldQueue',
    // Once after_commit is global (§D8) a future author may reach for the per-job interface instead;
    // without this it would slip the gate entirely.
    'Illuminate\Contracts\Queue\ShouldQueueAfterCommit',
];

const TENANT_AWARE_JOB = 'App\Jobs\TenantAwareJob';
const MAINTENANCE_JOB = 'App\Jobs\MaintenanceJob';
const QUEUE_ATTRIBUTE = 'Illuminate\Queue\Attributes\Queue';
const QUEUE_NAME_ENUM = 'App\Enums\QueueName';

/**
 * §D6's five names. Duplicated from App\Enums\QueueName deliberately: this script must not boot the
 * framework, and a linter that reads its expectations from the code it lints cannot fail.
 */
const ALLOWED_QUEUE_CASES = [
    'Submissions',
    'Webhooks',
    'Exports',
    'OcrProcessing',
    'ScheduledMaintenance',
];

/** §D5's closed whitelist. Backed enums are additionally allowed (resolved in pass 2). */
const SCALAR_PAYLOAD_TYPES = ['string', 'int', 'float', 'bool', 'array'];

/**
 * ShouldQueue classes exempt from R1, each with a documented rationale — mirroring
 * scripts/migration-lint.php's EXEMPT_TABLES. An allowlist entry is a diff against a shared CI gate,
 * which a reviewer sees; a per-class attribute would let an author silence the gate in their own file.
 * R2/R3/R4 still apply to an exempt class.
 */
const EXEMPT_JOBS = [
    // (none yet — H3's queued mailables are the first expected entries)
];

$root = dirname(__DIR__);
$dir = $root.'/app';

$parser = (new ParserFactory)->createForNewestSupportedVersion();
$finder = new NodeFinder;

$violations = [];
$scanned = 0;

// ── Pass 1: FQCN → {parent, interfaces, file, node} across all of app/ ────────────────────────────
$classes = [];

if (is_dir($dir)) {
    foreach (php_files($dir) as $path) {
        $code = (string) file_get_contents($path);
        $ast = $parser->parse($code);

        if ($ast === null) {
            continue;
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $ast = $traverser->traverse($ast);

        foreach ($finder->findInstanceOf($ast, Class_::class) as $class) {
            if ($class->name === null) {
                continue; // anonymous
            }

            $fqcn = $class->namespacedName?->toString() ?? $class->name->toString();

            $classes[$fqcn] = [
                'node' => $class,
                'parent' => $class->extends?->toString(),
                'interfaces' => array_map(static fn (Node\Name $n): string => $n->toString(), $class->implements),
                'relative' => relative_path($path, $root),
            ];
        }
    }
}

// ── Pass 2: apply the rules to every job ──────────────────────────────────────────────────────────
foreach ($classes as $fqcn => $class) {
    if (! is_job($fqcn, $classes, $violations)) {
        continue;
    }

    $scanned++;
    $relative = $class['relative'];
    $node = $class['node'];
    $abstract = $node->isAbstract();

    // R1 — base class.
    if (! $abstract && ! in_array($fqcn, EXEMPT_JOBS, true) && ! extends_a_base($fqcn, $classes)) {
        $violations[] = sprintf(
            '%s: %s implements ShouldQueue but extends neither App\Jobs\TenantAwareJob nor App\Jobs\MaintenanceJob (ADR-0007 §D2/§D3). Extend the tenant-scoped base, or the cross-tenant one, or add it to EXEMPT_JOBS with a documented rationale.',
            $relative, short($fqcn)
        );
    }

    // R2 — the queue attribute, itself or inherited.
    if (! $abstract) {
        $case = queue_case($fqcn, $classes);

        if ($case === null) {
            $violations[] = sprintf(
                '%s: %s declares no #[Queue(QueueName::…)] attribute (ADR-0007 §D6). Every job must name its queue; note a $queue PROPERTY is a fatal error because Illuminate\Bus\Queueable declares it untyped.',
                $relative, short($fqcn)
            );
        } elseif (! in_array($case, ALLOWED_QUEUE_CASES, true)) {
            $violations[] = sprintf(
                '%s: %s targets QueueName::%s, which is outside the ADR-0007 §D6 catalog (%s).',
                $relative, short($fqcn), $case, implode(', ', ALLOWED_QUEUE_CASES)
            );
        }
    }

    // R3/R4 — payload shape.
    foreach (payload_members($node) as $member) {
        [$name, $type, $line] = $member;

        if ($name === 'deleteWhenMissingModels') {
            continue; // handled below
        }

        if ($type === null) {
            $violations[] = sprintf(
                '%s:%d: %s::$%s has no type declaration (ADR-0007 §D5). Job payloads must be scalars (%s) or backed enums — untyped and mixed are banned BECAUSE a model-typed payload cannot be detected statically.',
                $relative, $line, short($fqcn), $name, implode(', ', SCALAR_PAYLOAD_TYPES)
            );
        } elseif (! is_scalar_payload_type($type)) {
            $violations[] = sprintf(
                '%s:%d: %s::$%s is typed %s, which is not a permitted job payload (ADR-0007 §D5). Carry a scalar id instead — SerializesModels restores model properties BEFORE handle(), under a NULL GUC, so RLS makes it a permanent failure.',
                $relative, $line, short($fqcn), $name, $type
            );
        }
    }

    if (($line = sets_delete_when_missing_models($node, $finder)) !== null) {
        $violations[] = sprintf(
            '%s:%d: %s sets $deleteWhenMissingModels = true (ADR-0007 §D5). That converts a loud RLS-visibility failure into a job that silently deletes itself — silent data loss in place of an error.',
            $relative, $line, short($fqcn)
        );
    }
}

// R5 — no hand-rolled tenant context anywhere under app/Jobs/.
foreach (is_dir($root.'/app/Jobs') ? php_files($root.'/app/Jobs') : [] as $path) {
    $code = (string) file_get_contents($path);

    foreach (['TenantContext::apply(', 'TenantContext::forget('] as $needle) {
        if (str_contains($code, $needle)) {
            $violations[] = sprintf(
                '%s: calls %s… (ADR-0007 §D2/§D4). A job must not manage tenant context by hand: TenantAwareJob::handle() establishes it transaction-scoped via applyLocal(), and the queue listener owns both edges. Session-scoped context on a long-lived worker is the defect this substrate exists to close.',
                relative_path($path, $root), $needle
            );
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "Job payload linter FAILED:\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, "  - {$violation}\n");
    }
    exit(1);
}

fwrite(STDOUT, sprintf("Job payload linter passed (%d job class(es) scanned).\n", $scanned));
exit(0);

/**
 * Every .php file under a directory.
 *
 * @return list<string>
 */
function php_files(string $dir): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

/**
 * Whether a class is a queued job — directly or through any ancestor within app/.
 *
 * An ancestor that resolves OUTSIDE app/ (a framework or package base) cannot be inspected, so the
 * walk stops there. That is reported as a violation rather than silently passing, because it is
 * exactly the case that needs a human decision.
 *
 * @param  array<string, array{node: Class_, parent: ?string, interfaces: list<string>, relative: string}>  $classes
 * @param  list<string>  $violations
 */
function is_job(string $fqcn, array $classes, array &$violations): bool
{
    $seen = [];

    for ($current = $fqcn; $current !== null && isset($classes[$current]); $current = $classes[$current]['parent']) {
        if (isset($seen[$current])) {
            return false; // defensive: cyclic extends is a fatal anyway
        }

        $seen[$current] = true;

        foreach ($classes[$current]['interfaces'] as $interface) {
            if (in_array($interface, SHOULD_QUEUE_INTERFACES, true)) {
                return true;
            }
        }

        $parent = $classes[$current]['parent'];

        if ($parent !== null && ! isset($classes[$parent]) && str_starts_with($parent, 'App\\')) {
            $violations[] = sprintf(
                '%s: %s extends %s, which could not be resolved under app/ (ADR-0007). The linter cannot determine whether this is a queued job; move the base class under app/ or add the class to EXEMPT_JOBS with a documented rationale.',
                $classes[$fqcn]['relative'], short($fqcn), $parent
            );

            return false;
        }
    }

    return false;
}

/**
 * @param  array<string, array{node: Class_, parent: ?string, interfaces: list<string>, relative: string}>  $classes
 */
function extends_a_base(string $fqcn, array $classes): bool
{
    for ($current = $classes[$fqcn]['parent']; $current !== null; $current = $classes[$current]['parent'] ?? null) {
        if ($current === TENANT_AWARE_JOB || $current === MAINTENANCE_JOB) {
            return true;
        }

        if (! isset($classes[$current])) {
            return false;
        }
    }

    return false;
}

/**
 * The QueueName case a class targets via #[Queue], walking ancestors (the framework's
 * ReadsClassAttributes does the same), or null if none is found.
 *
 * @param  array<string, array{node: Class_, parent: ?string, interfaces: list<string>, relative: string}>  $classes
 */
function queue_case(string $fqcn, array $classes): ?string
{
    for ($current = $fqcn; $current !== null && isset($classes[$current]); $current = $classes[$current]['parent']) {
        foreach ($classes[$current]['node']->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->toString() !== QUEUE_ATTRIBUTE) {
                    continue;
                }

                $argument = $attribute->args[0]->value ?? null;

                // Only a ClassConstFetch on App\Enums\QueueName is accepted. A raw string would be
                // unlintable drift; a constant would need cross-class evaluation this cannot do.
                if ($argument instanceof Node\Expr\ClassConstFetch
                    && $argument->class instanceof Node\Name
                    && $argument->class->toString() === QUEUE_NAME_ENUM
                    && $argument->name instanceof Node\Identifier) {
                    return $argument->name->toString();
                }
            }
        }
    }

    return null;
}

/**
 * Payload members: the class's own public non-static properties plus promoted constructor parameters.
 *
 * @return list<array{0: string, 1: ?string, 2: int}> name, type (null when absent), line
 */
function payload_members(Class_ $node): array
{
    $members = [];

    foreach ($node->getProperties() as $property) {
        if ($property->isStatic() || ! $property->isPublic()) {
            continue;
        }

        foreach ($property->props as $prop) {
            $members[] = [$prop->name->toString(), type_name($property->type), $property->getStartLine()];
        }
    }

    foreach ($node->getMethod('__construct')?->params ?? [] as $param) {
        if ($param->flags === 0 || ! $param->var instanceof Node\Expr\Variable || ! is_string($param->var->name)) {
            continue; // not promoted
        }

        $members[] = [$param->var->name, type_name($param->type), $param->getStartLine()];
    }

    return $members;
}

function type_name(?Node $type): ?string
{
    if ($type instanceof Node\NullableType) {
        return type_name($type->type);
    }

    if ($type instanceof Node\Identifier || $type instanceof Node\Name) {
        return $type->toString();
    }

    return null; // absent, union or intersection — all rejected by R3
}

/**
 * A scalar from the whitelist, or a backed enum (any App\Enums\* type; enums serialize as scalars).
 */
function is_scalar_payload_type(string $type): bool
{
    return in_array(strtolower($type), SCALAR_PAYLOAD_TYPES, true)
        || str_starts_with($type, 'App\Enums\\');
}

/**
 * The line where $deleteWhenMissingModels is set true — as a property default OR an assignment —
 * or null. Both forms are checked: a property default is the obvious one, an assignment in a
 * constructor or method is the one a reviewer misses.
 */
function sets_delete_when_missing_models(Class_ $node, NodeFinder $finder): ?int
{
    foreach ($node->getProperties() as $property) {
        foreach ($property->props as $prop) {
            if ($prop->name->toString() === 'deleteWhenMissingModels'
                && $prop->default instanceof Node\Expr\ConstFetch
                && strtolower($prop->default->name->toString()) === 'true') {
                return $property->getStartLine();
            }
        }
    }

    /** @var list<Node\Expr\Assign> $assignments */
    $assignments = $finder->findInstanceOf($node->stmts, Node\Expr\Assign::class);

    foreach ($assignments as $assign) {
        if ($assign->var instanceof Node\Expr\PropertyFetch
            && $assign->var->name instanceof Node\Identifier
            && $assign->var->name->toString() === 'deleteWhenMissingModels'
            && $assign->expr instanceof Node\Expr\ConstFetch
            && strtolower($assign->expr->name->toString()) === 'true') {
            return $assign->getStartLine();
        }
    }

    return null;
}

/** Repo-relative path with a consistent separator (the scan mixes `/` and the platform's). */
function relative_path(string $path, string $root): string
{
    return str_replace('\\', '/', substr($path, strlen($root) + 1));
}

function short(string $fqcn): string
{
    $position = strrpos($fqcn, '\\');

    return $position === false ? $fqcn : substr($fqcn, $position + 1);
}
