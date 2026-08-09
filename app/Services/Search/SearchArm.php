<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\SearchEntity;
use App\Models\User;
use App\Support\Search\SearchTerms;

/**
 * One entity's slice of global search (Increment J1b).
 *
 * ⚠️ WHY THIS IS AN INTERFACE WITH ONE CLASS PER ENTITY RATHER THAN A `match` INSIDE ONE SERVICE.
 * RLS gives tenant isolation and NOTHING ELSE, so every entity needs a second, DIFFERENT predicate — forms
 * key on `forms.edit.any` + Editor grants, submissions on `dashboard.org.view` + any-capacity grants + a
 * respondent clause, members on `tenant.members.invite`. A shared method with a `match` inside it is one
 * refactor away from an arm silently inheriting a neighbour's rule, and `SubmissionPolicy` already names that
 * failure shape in writing: the leak comes "through the one door nobody thought to test".
 *
 * Separate classes make an arm's predicate impossible to reach from another arm, and make "which rule does
 * this entity use" a filename rather than a branch.
 */
interface SearchArm
{
    public function entity(): SearchEntity;

    /**
     * May this user use this arm AT ALL — the coarse gate, evaluated before any row query.
     *
     * A false here means the section is ABSENT from the response, not empty: an empty section is a claim
     * ("there are none"), and a count of 0 rendered to a user who may not search that entity discloses the
     * shape of what they cannot see.
     */
    public function allowed(User $user): bool;

    public function search(User $user, SearchTerms $terms, int $limit): SearchArmResult;

    /**
     * The visible total.
     *
     * ⚠️ IMPLEMENTATIONS MUST DERIVE THIS FROM THE SAME BUILDER `search()` USES. A count taken from a
     * keyword-only query would leak the tenant-wide total to a user who can see three rows — the count is a
     * side channel with exactly the same reach as the rows themselves. The interface cannot enforce it, so
     * every implementation routes both through one private `builder()` and `SearchCountLeakTest` mutates it.
     */
    public function count(User $user, SearchTerms $terms): int;
}
