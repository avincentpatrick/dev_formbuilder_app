<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Form;
use App\Models\ResourceGrant;
use App\Models\ScopeNode;
use Illuminate\Database\Eloquent\Model;

/**
 * The morph targets a {@see ResourceGrant} may point at (PRD §6/§7, technical-architecture.md §5 row 11,
 * Increment G10a) — the "assign a resource to one of several types/levels" vocabulary the plan requires
 * be served by a real `morphTo` rather than a PHP switch on a level column.
 *
 * A grant on a {@see Form} is direct per-form access (what `form_collaborators` used to express); a grant
 * on a {@see ScopeNode} covers every form assigned to that node, and — when the grant sets
 * `includes_descendants` — every form under it in the tenant's own hierarchy.
 *
 * This enum is the SINGLE SOURCE OF TRUTH for the alias set. Four consumers read it and must never
 * hand-roll their own list: `AppServiceProvider`'s morph map, the `resource_grants_scopeable_type_check`
 * CHECK constraint, `TenantIsolation::resourceGrantGuard`'s per-alias EXISTS branches, and
 * `ResourceGrantResolver`'s fail-closed allowlist. Adding a third target is one case here plus one
 * migration — never a `switch`.
 *
 * Registered via `Relation::morphMap`, NEVER `enforceMorphMap`: enforcing it breaks Spatie's and
 * Sanctum's unmapped FQCN morphs (PROGRESS_ARCHIVE.md:240 — 90 test failures).
 */
enum ResourceScopeable: string
{
    case Form = 'form';
    case ScopeNode = 'scope_node';

    /** @return list<string> */
    public static function aliases(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Alias => model class, for `Relation::morphMap`.
     *
     * @return array<string, class-string<Model>>
     */
    public static function morphMap(): array
    {
        return [
            self::Form->value => Form::class,
            self::ScopeNode->value => ScopeNode::class,
        ];
    }

    /**
     * Alias => table name, for the RLS guard's per-alias EXISTS branches.
     *
     * @return array<string, string>
     */
    public static function targetTables(): array
    {
        return [
            self::Form->value => 'forms',
            self::ScopeNode->value => 'scope_nodes',
        ];
    }
}
