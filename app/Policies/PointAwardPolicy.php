<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PointAward;
use App\Models\User;
use App\Support\Api\ApiAbilities;

/**
 * Authorization for the gamification ladder (ADR-0020 §D7, Increment K1d).
 *
 * ── ⚠️ ONE METHOD, AND WHAT IT GATES IS NARROWER THAN THE MODEL IT HANGS ON ─────────────────────────────
 * {@see viewAny()} authorizes reading **other people's** awards — the named workspace ladder. It is
 * deliberately NOT the gate on a member's own standing, which needs no permission at all: §D7 splits the
 * feature exactly there, and `GET /gamification/me` therefore carries no `can:` gate. A future `view()`
 * arm for a single award would be a different question and should be argued on its own.
 *
 * The bound class is {@see PointAward} because every `/api/v1` route in this repository uses
 * `can:<method>,<ModelClass>` and there is no `Gate::define` anywhere — the {@see SavedReportViewPolicy}
 * situation one increment later. It is the honest choice here rather than a convenient one: the thing being
 * authorized genuinely IS "may this person read the tenant's point awards", so unlike the analytics routes
 * this policy is not doing double duty for an endpoint with no model.
 *
 * ── NO THIRTIETH PERMISSION, AND THAT IS A DECISION OF RECORD ───────────────────────────────────────────
 * §D7 (product decision, 2026-08-17): the 29-permission catalog is closed, and *"who may see workspace-wide
 * numbers about other people"* is a question `dashboard.org.view` already answers for the dashboard and for
 * submissions. Minting `gamification.view_leaderboard` would additionally mean deciding which of five roles
 * hold it — re-litigating a matrix that already encodes the answer. So: Owner, Admin and Viewer see the
 * named list; Form Editor and Reviewer see only themselves.
 *
 * ⚠️ **`dashboard.form.view` IS NOT ACCEPTED HERE, AND THE ASYMMETRY WITH {@see ApiAbilities} IS
 * INTENTIONAL.** The `read:gamification` ability maps to EITHER dashboard key, so all five roles can mint a
 * token for these surfaces — that is what lets a Form Editor read their own standing over the API. This
 * gate is strictly `dashboard.org.view`, because a per-form permission says nothing about whether somebody
 * may read a workspace-wide ranking of their colleagues. The ability scopes the token; this decides which
 * half of the feature the caller sees.
 *
 * Checks go through `$user->can()` rather than `hasPermissionTo()`, which THROWS on an unseeded catalog —
 * the {@see SavedReportViewPolicy} / {@see WebhookEndpointPolicy} convention, since policies are reachable
 * off-tenant.
 */
final class PointAwardPolicy
{
    /** May this member read the NAMED ladder — everyone else's points, by name? See the class docblock. */
    public function viewAny(User $user): bool
    {
        return $user->can('dashboard.org.view');
    }
}
