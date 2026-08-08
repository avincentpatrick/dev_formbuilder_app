<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ResourceCapacity;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Notifications\NotificationPresenter;
use App\Services\Submissions\SubmissionAnswerEditService;
use Database\Seeders\RolePermissionSeeder;

/**
 * Authorization for the submissions inbox, review, and export (Increments F4b + F7). Mirrors
 * {@see FormPolicy}'s `$user->can()` posture (Spatie's Gate::before → checkPermissionTo returns false for
 * an unheld OR unseeded permission, never throws — these methods are reached from the inbox presenter and
 * `HandleInertiaRequests::share()` on every render, incl. off-tenant where no catalog/team is set).
 *
 * Two visibility scopes coexist on the single `submissions.view` permission (multi-tenancy-rbac-design.md §5,
 * lines 97–104): Owner/Admin/Viewer see submissions **tenant-wide** (they hold `dashboard.org.view`), while
 * Form Editor/Reviewer see only forms they **collaborate on** (editor OR reviewer capacity). That split lives
 * in {@see hasOrgWideVisibility()} + {@see collaboratesWith()} and is mirrored by {@see Submission::scopeVisibleTo()}
 * so the policy (single-row checks) and the inbox query (list scoping) agree on exactly one rule.
 */
final class SubmissionPolicy
{
    public function __construct(private readonly ResourceGrantResolver $grants) {}

    /**
     * Manual encoding (F4b): submissions.create + published form + per-form scope.
     *
     * G10a tightening (deliberate, flagged): the per-form scope now requires EDITOR capacity, where it
     * previously accepted either capacity. Manual encoding is an authoring act, and once a grant can be
     * made against a scope node a "reviewer" grant on an interior node would otherwise hand out write
     * access to every form beneath it. No existing test asserted the old behaviour.
     */
    public function create(User $user, Form $form): bool
    {
        return $user->can('submissions.create')
            && $form->current_published_version_id !== null
            && ($user->can('forms.edit.any')
                || $this->grants->holdsOnFormId($user, $form->id, ResourceCapacity::Editor));
    }

    /** Reach the inbox list at all — row-level scoping is applied by the query, not here (F7). */
    public function viewAny(User $user): bool
    {
        return $user->can('submissions.view');
    }

    /**
     * View one submission's detail: the permission + tenant-wide OR per-form collaborator scope (F7) OR
     * — since I8a — being the person who submitted it.
     *
     * ── THE RESPONDENT CLAUSE (I8a, filed by I4, `docs/feature-backlog.md` §4) ─────────────────────────
     * `submission_approved` / `submission_returned` are addressed to `respondent_user_id` and the copy
     * tells that person to "open it to see what they asked for" — but before this clause the rule was
     * `submissions.view && (org-wide || collaborates)`, with nothing about having AUTHORED the thing. A
     * Form Editor whose grant was later revoked, or whose form moved to another scope node, kept a
     * notification pointing at a **bare 403 rendered outside the Inertia shell, with no navigation back**.
     * I4 made that honest rather than broken — {@see NotificationPresenter}
     * runs this very Gate and ships `url: null` so the row renders as plain text — and I8a makes it
     * WORKED, so those links resolve. That presenter needs no change; it starts allowing by itself.
     *
     * ⚠️ THIS IS `view()` ONLY, NOT `review()` AND NOT `update()`. Reading back what you yourself
     * submitted is not a privilege — the answers are already yours, and you typed them. Deciding your own
     * submission's outcome, or editing it after review, are different questions with different answers.
     *
     * ~~post-submission editing is I9's vertical and lights up `submissions.edit.any/.own` (seeded since
     * Phase 0 with no code behind them)~~ — **I9c BUILT IT**, and this sentence is struck rather than
     * deleted because the RULE it states is the part that still holds: {@see self::update()} deliberately
     * has NO respondent arm. Do not widen the neighbours "for symmetry". (The strike is exceptions-log #6's
     * lesson applied to this file: a forward-reference that outlives its subject reads as diligence and is
     * trusted for exactly that reason, so it gets closed in the commit that closes the thing.)
     *
     * `submissions.view` is still required, so this grants nothing to a role that holds no read at all —
     * and guest respondents have no `users` row, so `respondent_user_id` is null for them and the clause
     * is inert on the public runtime.
     *
     * MIRRORED IN {@see Submission::scopeVisibleTo()} — this class's docblock pins that the single-row
     * check and the list query must express exactly one rule, and a respondent who could open a row the
     * inbox never lists is precisely the divergence that pin exists to prevent.
     */
    public function view(User $user, Submission $submission): bool
    {
        return $user->can('submissions.view')
            && ($this->hasOrgWideVisibility($user)
                || $this->collaboratesWith($user, $submission->form_id)
                || $this->isRespondent($user, $submission));
    }

    /** Stream a form's export (F7): submissions.export (Viewer lacks it) + the same visibility scope. */
    public function export(User $user, Form $form): bool
    {
        return $user->can('submissions.export')
            && ($this->hasOrgWideVisibility($user) || $this->collaboratesWith($user, $form->id));
    }

    /** Approve/return/transition one submission (F7): review.any tenant-wide, review.own per collaborated form. */
    public function review(User $user, Submission $submission): bool
    {
        return $user->can('submissions.review.any')
            || ($user->can('submissions.review.own') && $this->collaboratesWith($user, $submission->form_id));
    }

    /**
     * Correct a finalized submission's answers (Increment I9c) — `docs/PRD.md` §6's "post-submission answer
     * editing" fast-follow (NOT a numbered PRD feature; #12 is the audit trail, which five other files use
     * that number for), filed at `docs/feature-backlog.md` §0. It is the first code ever to consume
     * `submissions.edit.any` / `submissions.edit.own`, seeded to Owner/Admin and Form Editor respectively
     * since Phase 0 ({@see RolePermissionSeeder}) and documented in multi-tenancy-rbac-design.md §5 with
     * nothing behind them. Third dormant-key occurrence, after `feedback.view` (I7a) and
     * `tenant.roles.assign` (I8a).
     *
     * Shaped like {@see self::review()} — an `.any` arm that is tenant-wide and an `.own` arm scoped to a
     * collaborated form — with ONE deliberate difference: the `.own` arm requires EDITOR capacity, where
     * review()'s accepts either. Editing answers is an AUTHORING act on someone else's response, which is
     * the same argument G10a used to tighten {@see self::create()} from "either capacity" to Editor: once a
     * grant can be made against an interior scope node, accepting reviewer capacity here would hand write
     * access to every submission on every form beneath that node to someone granted only the right to read
     * and decide. `reviewer` holds neither edit key, so that role cannot reach this at all.
     *
     * ⚠️ NO RESPONDENT ARM, and its absence is the decision rather than an omission — {@see self::view()}
     * argues it at length. I8a gave respondents `view()` so their notification links resolve; letting them
     * rewrite an answer a reviewer has already read and approved is a different claim entirely, and the
     * `.own` in `submissions.edit.own` means "forms I collaborate on", never "submissions I authored" — see
     * the `submissions.edit.own` row of multi-tenancy-rbac-design.md §5's permission table. (Cited by ROW,
     * not by line number: an earlier draft said "line 99", and the same edit that closed that table's
     * fast-follow annotation moved it.)
     *
     * ⚠️ `submissions.view` IS A CONJUNCT, and it is not redundant belt-and-braces. This is the ONLY gate on
     * `GET /submissions/{submission}/edit`, which renders the entire stored answer document — so here the
     * WRITE gate is also the READ gate, which is not true of {@see self::review()} (a PATCH that returns no
     * data) that this method is otherwise shaped after. It costs nothing against the seeded roles, because
     * every role holding either edit key already holds `submissions.view`. What it prevents is the next
     * narrow role: a "Data Correction" role granted `submissions.edit.any` WITHOUT `dashboard.org.view`
     * would otherwise gain a tenant-wide read of every answer on every form, reachable only by typing the
     * edit URL — the inbox would list nothing and `/submissions/{id}` would 403, so the leak would be
     * through the one door nobody thought to test.
     *
     * ⚠️ WHICH STATUSES MAY BE EDITED IS **NOT** DECIDED HERE. This answers "may this user edit at all"; the
     * legal source states live in {@see SubmissionAnswerEditService}, the same split
     * review()/`$from` already uses. A FormRequest cannot know them either — the row has to be read under a
     * lock first, or two concurrent edits both pass a pre-check that was true when they read it.
     */
    public function update(User $user, Submission $submission): bool
    {
        return $user->can('submissions.edit.any')
            || ($user->can('submissions.edit.own')
                && $this->grants->holdsOnFormId($user, $submission->form_id, ResourceCapacity::Editor));
    }

    /**
     * Finalize a draft (Increment H9b) — the encoder-confirm seam (an OCR-staged or manually-saved draft is
     * promoted to `submitted`). Mirrors {@see create()}'s authorization: it is a submission-authoring act on
     * the draft's form, so it needs `submissions.create` plus tenant-wide edit OR editor-capacity collaboration
     * on that form. A draft is already pinned to a published version, so the published-version check create()
     * makes is unnecessary here.
     */
    public function promote(User $user, Submission $submission): bool
    {
        return $user->can('submissions.create')
            && ($user->can('forms.edit.any')
                || $this->grants->holdsOnFormId($user, $submission->form_id, ResourceCapacity::Editor));
    }

    /**
     * Tenant-wide submission visibility. `dashboard.org.view` is the one permission that separates the
     * org-wide read roles (Owner/Admin/Viewer) from the collaborator-scoped ones (Form Editor/Reviewer,
     * who hold only `dashboard.form.view`) — multi-tenancy-rbac-design.md §5.
     */
    private function hasOrgWideVisibility(User $user): bool
    {
        return $user->can('dashboard.org.view');
    }

    /**
     * Any grant covering this form (editor OR reviewer capacity) scopes a `.own` role to it — the exact
     * rule this policy has always applied. Since G10a the grant may name the form directly or name the
     * scope node it belongs to; {@see ResourceGrantResolver} owns that distinction so this policy and
     * {@see Submission::scopeVisibleTo()} cannot disagree about it.
     */
    private function collaboratesWith(User $user, string $formId): bool
    {
        return $this->grants->holdsAny($user, $formId);
    }

    /**
     * Did this user submit this submission? (I8a — see {@see self::view()} for the reasoning.)
     *
     * ⚠️ THE NULL CHECK IS LOAD-BEARING, NOT DEFENSIVE. `respondent_user_id` is NULL on every guest
     * submission — the public runtime has no user to record — and `$user->getKey()` is never null on a
     * resolved User, so a bare `===` would already be false. But the explicit guard states the rule the
     * comparison only implies: an ANONYMOUS submission belongs to nobody, and must never become readable
     * by falling out of a comparison between two nulls if either side's typing ever loosens.
     */
    private function isRespondent(User $user, Submission $submission): bool
    {
        return $submission->respondent_user_id !== null
            && $submission->respondent_user_id === $user->getKey();
    }
}
