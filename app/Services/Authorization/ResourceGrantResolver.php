<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Enums\ResourceCapacity;
use App\Enums\ResourceScopeable;
use App\Enums\TenantUserStatus;
use App\Models\Form;
use App\Models\ResourceGrant;
use App\Models\ScopeNode;
use App\Models\Submission;
use App\Models\User;
use App\Policies\FormPolicy;
use App\Policies\SubmissionPolicy;
use App\Services\Scoping\ScopeNodeService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * The single answer to "does this user hold capacity C on this form" (Increment G10a) — the one place
 * `resource_grants` is interpreted. {@see FormPolicy}, {@see SubmissionPolicy}
 * and {@see Submission::scopeVisibleTo()} all delegate here so a single-row check and a list
 * query can never drift into "a row appears in the inbox but 403s when opened".
 *
 * A user reaches a form two ways: a DIRECT grant naming the form, or a grant on the {@see ScopeNode}
 * the form is assigned to — and, when that grant sets `includes_descendants`, on any ancestor of that node.
 *
 * ── Why resolution runs downward ─────────────────────────────────────────────────────────────────────
 * The obvious formulation ("is the granted node an ancestor of this form's node") is
 * `path LIKE '%/' || granted_id || '/%'` — a LEADING-WILDCARD match, which no btree can serve, sitting
 * inside the submissions inbox query. Instead we compare the FORM's path against the GRANT's path as a
 * constant prefix (`path LIKE :granted_path || '%'`), which compiles to range quals against the
 * `(tenant_id, path)` index. That index only serves the predicate under `COLLATE "C"`, which is why the
 * column carries it. Cost is bounded by the user's GRANT COUNT (a handful of prefixes), never by
 * descendant count — no descendant id set is ever materialized into PHP or bound into a `whereIn`
 * (PostgreSQL caps bind parameters at 65535; a region-level grant in a PSGC-shaped tree is ~44k nodes).
 *
 * ── Memoization ──────────────────────────────────────────────────────────────────────────────────────
 * Bound with `scoped()`, not `singleton()`, so an Octane container reset clears the memo between
 * requests. The memo key includes the TENANT as well as the user: Pest's `enterTenant()` is called
 * repeatedly within one PHP process, and a user-only key would bleed grants across tenants — producing
 * authorization tests that pass for the wrong reason.
 */
final class ResourceGrantResolver
{
    /** @var array<string, GrantSet> memo key => resolved grants */
    private array $sets = [];

    /**
     * Keyed by SCOPE NODE id, not form id — see {@see pathFor()} and {@see primeNodePaths()}. That is what
     * makes re-assigning a form to a different node (G10b2's picker) safe without a `forget()`: nothing here
     * is keyed by the thing that changed, and `holds()` reads `forms.scope_node_id` live off the model.
     *
     * @var array<string, ?string> scope node id => its path (null when inactive or unreadable)
     */
    private array $formPaths = [];

    /** @var array<string, list<string>> tenant id => paths of DEACTIVATED nodes (usually empty) */
    private array $inactive = [];

    /** Does the user hold this capacity on this form — directly, or via its node? */
    public function holds(User $user, Form $form, ResourceCapacity $capacity): bool
    {
        return $this->decide($this->setFor($user), $form->getKey(), $form->scope_node_id, $capacity);
    }

    /**
     * The id-only variant, for callers holding a form id rather than the model.
     *
     * Deliberately RE-ANCHORS the id against the database rather than trusting caller provenance: the id
     * arrives from a route parameter or a submission row, and resolving it here means a foreign or
     * nonexistent id yields `false` instead of silently matching a grant. `withTrashed()` because a
     * soft-deleted form must behave exactly as it did before G10a (see {@see formScope()}).
     */
    public function holdsOnFormId(User $user, string $formId, ResourceCapacity $capacity): bool
    {
        return $this->decide($this->setFor($user), $formId, $this->pathNodeIdFor($formId), $capacity);
    }

    /**
     * Any grant at all on this form, either capacity — the exact rule `SubmissionPolicy` has always used
     * for view/export scoping. Accepts a model (no extra query) or a bare id (re-anchored).
     */
    public function holdsAny(User $user, Form|string $form): bool
    {
        $set = $this->setFor($user);

        [$formId, $nodeId] = $form instanceof Form
            ? [$form->getKey(), $form->scope_node_id]
            : [$form, $this->pathNodeIdFor($form)];

        foreach (ResourceCapacity::cases() as $capacity) {
            if ($this->decide($set, $formId, $nodeId, $capacity)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The LIST twin of {@see holds()} — a subquery of `forms.id` this user may reach. Kept in this class,
     * beside the check it mirrors, precisely so the two cannot drift.
     *
     * @return Builder<Form>
     */
    public function grantedFormIdsQuery(User $user, ?ResourceCapacity $capacity = null): Builder
    {
        [$formIds, $exact, $prefixes] = $this->setFor($user)->flatten($capacity);

        // An explicit empty set, never an unconstrained query. A user with no grants must see NOTHING,
        // and `whereIn('id', [])` degenerating to a bare select would mean "everything".
        if ($formIds === [] && $exact === [] && $prefixes === []) {
            return $this->formScope()->select('forms.id')->whereRaw('false');
        }

        return $this->formScope()->select('forms.id')
            ->where(function (Builder $query) use ($formIds, $exact, $prefixes): void {
                $query->whereIn('forms.id', $formIds);

                if ($exact === [] && $prefixes === []) {
                    return;
                }

                $inactive = $this->inactivePaths();

                $query->orWhereExists(function (QueryBuilder $sub) use ($exact, $prefixes, $inactive): void {
                    $sub->selectRaw('1')
                        ->from('scope_nodes')
                        ->whereColumn('scope_nodes.id', 'forms.scope_node_id')
                        ->where('scope_nodes.is_active', true)
                        ->where(function (QueryBuilder $match) use ($exact, $prefixes): void {
                            foreach ($exact as $path) {
                                $match->orWhere('scope_nodes.path', '=', $path);
                            }

                            foreach ($prefixes as $path) {
                                $match->orWhere('scope_nodes.path', 'like', self::escapeLike($path).'%');
                            }
                        });

                    // The list twin of isUnderInactiveBranch(): a deactivated node cuts off its whole
                    // subtree, not just itself. Omitting this here (while decide() applies it) is exactly
                    // how the inbox and the detail page drift apart.
                    foreach ($inactive as $path) {
                        $sub->where('scope_nodes.path', 'not like', self::escapeLike($path).'%');
                    }
                });
            });
    }

    /**
     * Warm the form => node-path memo for a whole page in one query. Wired into `FormPresenter::list()`.
     *
     * @param  iterable<Form>  $forms
     */
    public function primeNodePaths(iterable $forms): void
    {
        $nodeIds = [];

        foreach ($forms as $form) {
            $nodeId = $form->scope_node_id;

            if ($nodeId !== null && ! array_key_exists($nodeId, $this->formPaths)) {
                $nodeIds[$nodeId] = true;
            }
        }

        if ($nodeIds === []) {
            return;
        }

        /** @var array<string, string> $paths */
        $paths = ScopeNode::query()
            ->whereIn('id', array_keys($nodeIds))
            ->where('is_active', true)
            ->pluck('path', 'id')
            ->all();

        foreach (array_keys($nodeIds) as $nodeId) {
            $this->formPaths[$nodeId] = $paths[$nodeId] ?? null;
        }
    }

    /**
     * How many forms a grant on `$node` would actually reach — the G10b blast-radius preview, and the
     * reason `includes_descendants` defaults to FALSE. `direct` is what a plain grant confers; `descendant`
     * is the extra reach the checkbox buys.
     *
     * Lives here, on the single interpreter of `resource_grants`, rather than in the UI layer or a second
     * query: a preview that disagrees with the resolver is worse than no preview, because it is the number
     * an administrator makes the escalation decision on.
     *
     * Counted through the same `formScope()` (`withTrashed()`) the grant itself resolves through, so the
     * figure equals what the grant confers rather than what the forms list happens to show.
     *
     * @return array{direct: int, descendant: int}
     */
    public function nodeReach(ScopeNode $node): array
    {
        // A grant on a deactivated node — or one anywhere under a deactivated branch — confers nothing.
        // Reporting its raw form count would advertise access the resolver will refuse to honour.
        if ($this->isUnderInactiveBranch($node->path)) {
            return ['direct' => 0, 'descendant' => 0];
        }

        $inactive = $this->inactivePaths();
        $prefix = self::escapeLike($node->path);

        $descendant = $this->formScope()
            ->whereExists(function (QueryBuilder $sub) use ($node, $prefix, $inactive): void {
                $sub->selectRaw('1')
                    ->from('scope_nodes')
                    ->whereColumn('scope_nodes.id', 'forms.scope_node_id')
                    ->where('scope_nodes.id', '!=', $node->getKey())
                    ->where('scope_nodes.is_active', true)
                    ->where('scope_nodes.path', 'like', $prefix.'%');

                foreach ($inactive as $path) {
                    $sub->where('scope_nodes.path', 'not like', self::escapeLike($path).'%');
                }
            })
            ->count();

        return [
            'direct' => $this->formScope()->where('scope_node_id', $node->getKey())->count(),
            'descendant' => $descendant,
        ];
    }

    /** Drop memoized grants after a write. Pass null to clear every user. */
    public function forget(?string $userId = null): void
    {
        if ($userId === null) {
            $this->sets = [];
            $this->formPaths = [];
            $this->inactive = [];

            return;
        }

        $prefix = $userId.'|';

        foreach (array_keys($this->sets) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->sets[$key]);
            }
        }
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────────────

    /** The whole decision, in memory. No query once the grant set is loaded. */
    private function decide(GrantSet $set, string $formId, ?string $nodeId, ResourceCapacity $capacity): bool
    {
        if (isset($set->formIds[$capacity->value][$formId])) {
            return true;
        }

        if ($nodeId === null) {
            return false;
        }

        $formPath = $this->pathFor($nodeId);

        if ($formPath === null || $this->isUnderInactiveBranch($formPath)) {
            return false;
        }

        if (in_array($formPath, $set->exactNodePaths[$capacity->value] ?? [], true)) {
            return true;
        }

        foreach ($set->prefixPaths[$capacity->value] ?? [] as $prefix) {
            if (str_starts_with($formPath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this node beneath a DEACTIVATED ancestor (or deactivated itself)?
     *
     * `is_active` is the revocation control for a branch — there is no `deleted_at` — so deactivating a
     * province must cut off its cities, not merely itself. Checking only the granted node and the form's
     * own node would leave a control that LOOKS like it revokes a branch but silently does not, which is
     * precisely the failure mode the no-`deleted_at` decision exists to avoid.
     *
     * Reuses the prefix machinery rather than walking ancestors: the tenant's inactive node paths are
     * loaded once per request (normally zero rows — deactivation is rare), and containment is then a
     * `str_starts_with` in memory. O(1) queries, and no dependence on tree depth.
     */
    private function isUnderInactiveBranch(string $formPath): bool
    {
        foreach ($this->inactivePaths() as $inactive) {
            if (str_starts_with($formPath, $inactive)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function inactivePaths(): array
    {
        $key = TenantContext::currentTenantId() ?? '-';

        if (isset($this->inactive[$key])) {
            return $this->inactive[$key];
        }

        /** @var list<string> $paths */
        $paths = ScopeNode::query()->where('is_active', false)->pluck('path')->all();

        return $this->inactive[$key] = $paths;
    }

    private function setFor(User $user): GrantSet
    {
        $key = $user->getKey().'|'.(TenantContext::currentTenantId() ?? '-');

        return $this->sets[$key] ??= $this->load($user);
    }

    /**
     * One query per (user, tenant). RLS scopes `resource_grants` AND the joined `scope_nodes`, so a grant
     * pointing at another tenant's node yields a NULL path and is discarded here rather than trusted.
     */
    private function load(User $user): GrantSet
    {
        $rows = ResourceGrant::query()
            ->where('resource_grants.user_id', $user->getKey())
            // Fail-closed allowlist: an alias this build does not know about is ignored, never guessed at.
            ->whereIn('resource_grants.scopeable_type', ResourceScopeable::aliases())
            // A grant only counts while its holder is an ACTIVE member. TenantMembershipService::remove()
            // sets status = removed without touching grants, so without this a removed member's grants
            // survive and silently re-arm the moment they are re-invited.
            ->whereExists(function (QueryBuilder $exists) use ($user): void {
                $exists->selectRaw('1')
                    ->from('tenant_users')
                    ->whereColumn('tenant_users.tenant_id', 'resource_grants.tenant_id')
                    ->where('tenant_users.user_id', $user->getKey())
                    ->where('tenant_users.status', TenantUserStatus::Active->value);
            })
            ->leftJoin('scope_nodes', function (JoinClause $join): void {
                $join->on('scope_nodes.id', '=', 'resource_grants.scopeable_id')
                    ->where('resource_grants.scopeable_type', '=', ResourceScopeable::ScopeNode->value)
                    ->where('scope_nodes.is_active', '=', true);
            })
            ->get([
                'resource_grants.capacity',
                'resource_grants.scopeable_type',
                'resource_grants.scopeable_id',
                'resource_grants.includes_descendants',
                'scope_nodes.path as node_path',
            ]);

        $formIds = [];
        $exact = [];
        $prefixes = [];

        foreach ($rows as $row) {
            // Already a ResourceCapacity — the model casts it; we key the maps by its backing value.
            $capacity = $row->capacity->value;

            if ($row->scopeable_type === ResourceScopeable::Form->value) {
                $formIds[$capacity][(string) $row->scopeable_id] = true;

                continue;
            }

            /** @var ?string $path */
            $path = $row->getAttribute('node_path');

            // Null path => the node is inactive, deleted, or belongs to another tenant. Inert, not fatal.
            if ($path === null) {
                continue;
            }

            if ((bool) $row->includes_descendants) {
                $prefixes[$capacity][] = $path;
            } else {
                $exact[$capacity][] = $path;
            }
        }

        return new GrantSet($formIds, $exact, $prefixes);
    }

    /** The node a form is assigned to, re-read from the database (used by the id-only entry points). */
    private function pathNodeIdFor(string $formId): ?string
    {
        $form = $this->formScope()->whereKey($formId)->first(['id', 'scope_node_id']);

        return $form?->scope_node_id;
    }

    private function pathFor(string $nodeId): ?string
    {
        if (array_key_exists($nodeId, $this->formPaths)) {
            return $this->formPaths[$nodeId];
        }

        /** @var ?string $path */
        $path = ScopeNode::query()
            ->whereKey($nodeId)
            ->where('is_active', true)
            ->value('path');

        return $this->formPaths[$nodeId] = $path;
    }

    /**
     * Both the check side and the list side resolve forms through here, so they cannot diverge.
     *
     * `withTrashed()` is behaviour PRESERVATION, not decoration: `Form` uses SoftDeletes, and the
     * `form_collaborators` subquery this replaces never touched `forms` at all — so a grant-holder could
     * always still see submissions belonging to a soft-deleted form. Dropping that would be an unflagged
     * behaviour change discovered in production, not in review.
     *
     * @return Builder<Form>
     */
    private function formScope(): Builder
    {
        return Form::withTrashed();
    }

    /**
     * Escape a value used as a LIKE prefix so a `%` or `_` in a path cannot widen the match.
     *
     * Public since G10b: {@see ScopeNodeService} re-paths subtrees with the same
     * constant-prefix LIKE and must escape it identically. Two copies of an authorization-critical
     * escaping rule is how they drift.
     */
    public static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
