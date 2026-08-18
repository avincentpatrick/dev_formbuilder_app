<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ResourceCapacity;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\Analytics\AnalyticsFormSet;
use App\Services\Authorization\ResourceGrantResolver;

/**
 * Per-form authorization (multi-tenancy-rbac-design.md §5/§8) — the `.any`/`.own` composition. `.any`
 * (Owner/Admin) is a pure tenant-wide Spatie permission check; `.own` (Form Editor) additionally requires
 * an editor grant covering THIS form. The coarse Spatie permission answers "may this role ever do this
 * action"; this policy adds the per-form resource check.
 *
 * Since Increment G10a that resource check is delegated to {@see ResourceGrantResolver}, which resolves
 * both a direct grant on the form and a grant on the scope node the form is assigned to. This policy no
 * longer knows the storage shape — which is what keeps it in agreement with
 * {@see Submission::scopeVisibleTo()}, the list-side twin.
 *
 * Permission checks go through `$user->can()` (Spatie's Gate::before → checkPermissionTo, which returns
 * false for an unheld OR unseeded permission) rather than hasPermissionTo() — the latter THROWS
 * PermissionDoesNotExist when the catalog isn't present, and this policy is reached from
 * HandleInertiaRequests::share() on every response (incl. the central admin console, where no tenant
 * catalog/team is set).
 */
final class FormPolicy
{
    public function __construct(private readonly ResourceGrantResolver $grants) {}

    public function viewAny(User $user): bool
    {
        return $user->can('forms.create')
            || $user->can('forms.edit.any')
            || $user->can('forms.edit.own');
    }

    public function view(User $user, Form $form): bool
    {
        return $this->canEdit($user, $form);
    }

    /**
     * May this user open the form's HUB — the read-only overview at `GET /forms/{form}` (Increment J2b)?
     *
     * ── WHY THIS IS NOT `view()` ────────────────────────────────────────────────────────────────────────
     * {@see self::view()} delegates to {@see self::canEdit()}, so `can:view,form` and `can:update,form`
     * admit the identical set today: Owner, Admin, and a Form Editor holding an editor grant. A Reviewer and
     * a Viewer are refused, which `FormAnalyticsGateTest` pins deliberately for `/forms/{form}/analytics`
     * (PRD.md:198 scopes that page to the Form Owner/Editor) — and that test must keep passing UNEDITED,
     * which is the proof this ability did not leak sideways into the analytics gate.
     *
     * But the hub exists precisely for the roles `view()` refuses. PRD §3.7 makes "every object links to its
     * related objects; no dead-end pages" a decision of record, and the dead ends J2 removes are all
     * traversed by exactly those roles: a Reviewer reading a submission, and a Viewer reading the audit
     * ledger, both see a form's TITLE with nowhere to click. `AuditLogPresenter` says so in its own words —
     * it links form targets to `/forms` because "/forms/{form} … a viewer of the ledger may hold no capacity
     * on". Widening `view()` itself would silently widen the analytics page too; a separate ability is what
     * keeps the two questions separate.
     *
     * ── THE RULE IS NOT NEW, AND THAT IS THE POINT (user decision, 2026-08-11) ──────────────────────────
     * This is byte-for-byte the split the app already applies to a submission: org-wide roles see
     * everything, collaborator-scoped roles see what they hold a grant on. Compare
     * {@see Submission::scopeVisibleTo()} (`dashboard.org.view` → unrestricted, else
     * `grantedFormIdsQuery`) and {@see AnalyticsFormSet::visible()} (identical).
     * `docs/multi-tenancy-rbac-design.md` §5 has listed `dashboard.form.view` against all five roles —
     * "✓ (own forms)" for Editor and Reviewer — since Phase 0.
     *
     * So it coins **no new permission key**; the catalog stays closed at 29. And it discloses nothing new: a
     * Viewer already reads every submission in the tenant (`submissions.view` + `dashboard.org.view`) and
     * every org-wide KPI on `/dashboard`. What they gain is a destination, not a fact.
     *
     * ⚠️ THE `dashboard.form.view` CONJUNCT IS A FAIL-CLOSED GUARD, AND NO SHIPPED ROLE CAN OBSERVE IT.
     * Stated precisely because an earlier version of this docblock claimed `FormHubGateTest` "mutates it out
     * and requires that case to redden" — and when the mutation was actually run, all twelve cases stayed
     * GREEN. All five seeded roles hold `dashboard.form.view` (§5), and the only product path that changes a
     * member's authority (`PATCH /members/{user}/role`, on `tenant.roles.assign`) assigns one of those five,
     * so nothing a user can do today distinguishes the two implementations.
     *
     * It stays because `resource_grants` is a CAPACITY store, not a permission store: a grant says "editor
     * capacity on this form", never "may read this tenant's forms". Drop the conjunct and the day a custom
     * permission set becomes reachable — a custom-role feature, or a role losing that key — the hub becomes
     * readable on the strength of a grant alone, silently. `FormHubGateTest` now pins it with a synthetic
     * member, labelled there as unreachable-today rather than presented as a live rule.
     *
     * ⚠️ `holdsAny()`, never `holds(Editor)`: a REVIEWER's grant is reviewer capacity, so an editor-capacity
     * check would refuse the single role this ability was widened for. Also mutation-covered.
     */
    public function viewOverview(User $user, Form $form): bool
    {
        return $user->can('dashboard.form.view')
            && ($user->can('dashboard.org.view') || $this->grants->holdsAny($user, $form));
    }

    public function create(User $user): bool
    {
        return $user->can('forms.create');
    }

    public function update(User $user, Form $form): bool
    {
        return $this->canEdit($user, $form);
    }

    public function restore(User $user, Form $form): bool
    {
        return $this->canEdit($user, $form);
    }

    public function publish(User $user, Form $form): bool
    {
        return $user->can('forms.publish.any')
            || ($user->can('forms.publish.own') && $this->holdsEditorGrant($user, $form));
    }

    public function delete(User $user, Form $form): bool
    {
        return $user->can('forms.delete');
    }

    public function manageCollaborators(User $user, Form $form): bool
    {
        return $user->can('forms.collaborators.manage');
    }

    private function canEdit(User $user, Form $form): bool
    {
        return $user->can('forms.edit.any')
            || ($user->can('forms.edit.own') && $this->holdsEditorGrant($user, $form));
    }

    /** Editor capacity on this form — directly granted, or inherited from its scope node (G10a). */
    private function holdsEditorGrant(User $user, Form $form): bool
    {
        return $this->grants->holds($user, $form, ResourceCapacity::Editor);
    }
}
