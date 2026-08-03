<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SavedReportView;
use App\Models\User;

/**
 * Authorization for the analytics surface (ADR-0011 §D9, Increment H24a).
 *
 * ── This policy does double duty, deliberately ──────────────────────────────────────────────────────────
 * {@see viewAny()} is the gate on the analytics READ routes as well as on the saved-view list. Every
 * `/api/v1` route in this repository uses `can:<method>,<ModelClass>` and there is no `Gate::define`
 * anywhere, so the report and question endpoints — which have no model of their own — need a bound class.
 * `SavedReportView` is the right one: its `viewAny` predicate is exactly the `read:analytics` ability map,
 * so the token scope and the user's real permission check agree by construction.
 *
 * **`can:viewAny,Submission::class` was rejected for those routes.** It authorizes on `submissions.view`,
 * which is a widening of a different capability — the same failure `ApiAbilities` documents twice in prose
 * about abilities, one layer down.
 *
 * ── No new RBAC permission ──────────────────────────────────────────────────────────────────────────────
 * §D9: `dashboard.org.view` and `dashboard.form.view` are already seeded and already in the role matrix, and
 * the org-wide-versus-own-forms split they encode IS the visibility split an analytics read needs. Either
 * grants the read; which forms the reader can actually reach is `AnalyticsFormSet`'s job, not this one's.
 * Note the consequence: a Viewer holds `dashboard.org.view`, so a Viewer can read org-wide analytics.
 * Intended — a Viewer already sees every submission in the inbox.
 *
 * ── Ownership is checked HERE because RLS does not check it ─────────────────────────────────────────────
 * `saved_report_views` uses `strict` isolation, which scopes to the tenant only (§D8 forbids the
 * `belongs_to_user` variant, whose policies carry no tenant predicate at all). So "per user" is enforced by
 * the owner comparison below and by `SavedReportView::scopeOwnedBy()` on list reads — not by the database.
 *
 * Checks go through `$user->can()` rather than `hasPermissionTo()`, which THROWS on an unseeded catalog —
 * the {@see WebhookEndpointPolicy} / {@see ScopeNodePolicy} convention, since policies are reachable
 * off-tenant.
 */
final class SavedReportViewPolicy
{
    /** Also the gate on the analytics report and question routes — see the class docblock. */
    public function viewAny(User $user): bool
    {
        return $user->can('dashboard.org.view') || $user->can('dashboard.form.view');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, SavedReportView $view): bool
    {
        return $this->viewAny($user) && $this->owns($user, $view);
    }

    public function update(User $user, SavedReportView $view): bool
    {
        return $this->view($user, $view);
    }

    public function delete(User $user, SavedReportView $view): bool
    {
        return $this->view($user, $view);
    }

    /**
     * A saved view is private to its author — including from an Owner.
     *
     * Not a hedge: the row is a personal working preference, and the ADR's own framing for §D8 is a
     * consultant who belongs to two tenants. An admin override would need its own decision and its own
     * permission, and neither exists.
     */
    private function owns(User $user, SavedReportView $view): bool
    {
        return $view->user_id === $user->getKey();
    }
}
