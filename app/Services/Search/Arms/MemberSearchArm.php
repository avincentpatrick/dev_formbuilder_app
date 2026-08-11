<?php

declare(strict_types=1);

namespace App\Services\Search\Arms;

use App\Enums\SearchEntity;
use App\Enums\TenantUserStatus;
use App\Models\User;
use App\Services\Search\SearchArm;
use App\Services\Search\SearchArmResult;
use App\Support\Search\KeywordFilter;
use App\Support\Search\SearchTerms;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Workspace members, matched on name and email (Increment J1c).
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * ⚠️ THIS ARM RUNS ON THE DEFAULT CONNECTION, AND THAT IS THE WHOLE REASON J1 WAS SPLIT AT THIS SEAM.
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * `TenantMembershipService::listMembers()` resolves identities through `User::on('pgsql_auth')`. That is
 * correct THERE and would be a cross-tenant leak HERE, and the difference is not the connection — it is
 * what the query is filtered by.
 *
 * `users` has NO `tenant_id` column: it is a global identity table. On the app connection the join-shape
 * policy `users_visibility` is the tenant boundary — a row is visible to itself, or to anyone sharing an
 * ACTIVE membership with it. On `pgsql_auth` that policy is OR'd away by
 * `users_auth_select ... TO meridian_auth USING (true)`, which exists so the pre-auth login path can
 * resolve an identity when no tenant context exists yet. There is no tenant predicate of any kind on that
 * connection. `listMembers()` is safe only because it derives its id set from an RLS-bounded `tenant_users`
 * read BEFORE hopping — the ids are already tenant-bounded, and it adds no predicate of its own.
 *
 * A search does the opposite: it takes a predicate straight from the user. Measured on the seeded corpus
 * rather than argued — a demo-scoped `email ILIKE '%o%'`:
 *
 *   on pgsql_auth   -> 8 rows, INCLUDING owner@northwind.test  (another tenant's user)
 *   on the app conn -> 6 rows, demo's active members only
 *
 * **THE STANDING RULE, AND IT IS BROADER THAN THIS FILE: no user-supplied predicate may ever run on
 * `pgsql_auth`.** `SearchMemberConnectionTest` pins it three ways, including a directory sweep that will
 * catch a seventh arm written by someone who never read this docblock.
 *
 * ── WHY `whereExists` WHEN RLS ALREADY DOES THIS ─────────────────────────────────────────────────────────
 * Belt and braces, deliberately. Without it this file would contain NO tenant boundary at all — the
 * boundary would live only in a 2026-07 migration — and `SearchArm`'s premise that "which rule does this
 * entity use" is answerable by reading one class would break for the one arm where it matters most.
 * ⚠️ Expect this predicate to SURVIVE mutation: deleting it leaves the tests green because RLS holds the
 * line underneath. That is a recorded survivor, not a reason to delete it; the structural guard in
 * `SearchMemberConnectionTest` is what compensates.
 *
 * It also earns its keep in a way that only showed up under mutation, and is worth stating because it is
 * the opposite of belt-and-braces being redundant: `meridian_auth` is granted `SELECT, UPDATE` on `users`
 * **and nothing else**, so this `whereExists` over `tenant_users` cannot even execute on that connection.
 * Swapping this arm to `pgsql_auth` therefore fails LOUDLY (11 cases red) instead of silently returning
 * every tenant's members — which is precisely the failure mode a future arm written WITHOUT this predicate
 * would have. That asymmetry is the argument for keeping it.
 *
 * ── PENDING INVITES ARE NOT SEARCHABLE, AND THAT IS A DECISION WITH A MEASUREMENT BEHIND IT ──────────────
 * `users_visibility` admits only `tu.status = 'active'`, and RLS applies at EVERY reference to `users` —
 * so a `tenant_users`-first join does not rescue an invited row either. Verified on the seeded corpus:
 * joining `tenant_users` to `users` under demo's context returns the 6 active members and drops
 * `invited@demo.test`, whose `tenant_users` row is perfectly visible. `MemberSearchArm` therefore searches
 * ACTIVE members only. Pending invites remain visible on `/members`, which already holds their identities,
 * and J1e's roster filter can find them there.
 *
 * **If that ever needs to change, the fix is a policy decision about `users_visibility` — NEVER a
 * connection hop.** Widening the policy would change every `users` read in the product (feedback reporter
 * names, audit actors, ownership transfer) to serve one search surface, which is why it was not done here.
 *
 * ── ROLE OUTCOMES ────────────────────────────────────────────────────────────────────────────────────────
 *   Owner / Admin (`tenant.members.invite`)   every active member of this workspace
 *   Form Editor / Reviewer / Viewer           arm REFUSED — they cannot reach `/members` either
 */
final readonly class MemberSearchArm implements SearchArm
{
    public function entity(): SearchEntity
    {
        return SearchEntity::Member;
    }

    public function allowed(User $user): bool
    {
        // The same key `/members` uses. `routes/tenant.php` already resolves this question in writing:
        // the permission catalog is closed at 29 keys and there is deliberately no `members.view`, so
        // viewing the roster IS the management surface. No thirtieth key is coined here.
        return Gate::forUser($user)->allows('tenant.members.invite');
    }

    public function search(User $user, SearchTerms $terms, int $limit): SearchArmResult
    {
        $rows = $this->builder($user, $terms)
            ->select(['users.id', 'users.name', 'users.email'])
            // Name, then id: `users` has no per-tenant ordering column, and ranking by relevance would
            // need a tsvector this arm deliberately does not have. Alphabetical is what /members shows.
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->limit($limit + 1)
            ->get()
            ->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'title' => $member->name,
                // The email is the disambiguator — two people can share a name, and the isolation test
                // asserts on this field for exactly that reason.
                'subtitle' => $member->email,
                // The roster FILTERED TO THIS PERSON (J2d), not the bare roster. Choosing "Ada Lovelace"
                // from the palette and landing on an unfiltered list of two hundred people is a result that
                // answers a different question from the one asked.
                //
                // ⚠️ EMAIL, NOT NAME, and it is the same reason the subtitle is: two members can share a
                // name, so filtering by it can still leave the reader hunting. `TenantMembershipService`
                // matches `q` against BOTH fields, so the email narrows to exactly one row.
                //
                // ⚠️ `rawurlencode`, NOT `urlencode` — the latter encodes a space as `+`, and `+` is legal in
                // an email local part (`ada+forms@…`), so the round trip would silently search for a
                // different address than the one displayed.
                'url' => '/members?q='.rawurlencode((string) $member->email),
            ])
            ->all();

        return SearchArmResult::fromOverfetch($this->entity(), array_values($rows), $limit);
    }

    public function count(User $user, SearchTerms $terms): int
    {
        return $this->builder($user, $terms)->count();
    }

    /**
     * The single source of both the rows and the count.
     *
     * ⚠️ NO `withTrashed()`. `listMembers()` needs it so every membership renders a name; search does not,
     * and adding it would surface deactivated accounts the product treats as gone.
     *
     * @return Builder<User>
     */
    private function builder(User $user, SearchTerms $terms): Builder
    {
        return User::query()
            ->whereExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('tenant_users')
                    ->whereColumn('tenant_users.user_id', 'users.id')
                    ->where('tenant_users.tenant_id', TenantContext::currentTenantId())
                    ->where('tenant_users.status', TenantUserStatus::Active->value);
            })
            ->tap(fn (Builder $q) => KeywordFilter::applyLike($q, $terms, ['users.name', 'users.email']));
    }
}
