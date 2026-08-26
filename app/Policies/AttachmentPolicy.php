<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AttachmentKind;
use App\Enums\ScanStatus;
use App\Models\Attachment;
use App\Models\User;

/**
 * Authorization for the authenticated read-side of a stored file (Increment G6). RLS already scopes every
 * `attachments` query to the current tenant, so a cross-tenant id never resolves (route-model binding 404s
 * before this runs) — this policy only adds the role gate. Serving is additionally gated on the scan status
 * ({@see ScanStatus::servable()}) in the controller, so an unscanned/infected file is withheld
 * regardless of permission.
 *
 * (Respondent-facing preview needs no server round-trip — a just-captured file previews from a local
 * `blob:` URL — so there is no guest read path in G6.)
 *
 * ── WHY THE GATE READS THE KIND (M29) ───────────────────────────────────────────────────────────────────
 * G6 wrote this as a flat `submissions.view` check that never touched its `$attachment` argument. That was
 * true of every kind G6 could produce — they were all respondent media, and `submissions.view` is the
 * permission that reads respondent media. It stopped being true the moment ADR-0015 §D6 filed a feedback
 * screenshot into the same shared table: the tenant surface gates that image on `feedback.view`
 * (Owner/Admin only) and `FeedbackController::screenshot()` says in its
 * own docblock that it declines to route through this controller precisely so a workspace revoking
 * `submissions.view` does not lose its feedback screenshots. The coupling it refused was live in the other
 * direction anyway: viewer, reviewer and form_editor all hold `submissions.view` and none holds
 * `feedback.view`, so the id-addressed sibling route served the same PII bytes to exactly the roles the
 * dedicated route refuses. A gate nobody asserts is indistinguishable from a gate nobody wrote — and this
 * one had no test of any kind until M29 wrote the first.
 *
 * The `match` is deliberate rather than an `if`: it makes "which permission reads this kind" a decision
 * taken at the site, once per kind, instead of a default that silently absorbs the tenth kind the way it
 * absorbed the seventh.
 *
 * ⚠️ THIS CLOSES ONE KIND, NOT THE CLASS, AND THE REMAINDER IS FILED RATHER THAN FORGOTTEN. The default arm
 * is still flat where {@see SubmissionPolicy::view()} is scoped: that policy requires `submissions.view`
 * AND org-wide visibility or per-form collaboration, this one requires only the permission — so a reviewer
 * or form_editor still reads submission media, submission PDF export artifacts and archived webhook
 * payloads belonging to forms they do not collaborate on. That is a real defect, it is larger than one
 * enum arm, and replacing the default with the scoped predicate needs each kind's owner resolved through
 * the morph map first. It has its own row in `docs/feature-backlog.md`.
 */
final class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $user->can(match ($attachment->kind) {
            // ADR-0015 §D6 put this image in the shared table; §D8 marks it PII. It is read under the
            // feedback permission on both routes that serve it, or under neither.
            AttachmentKind::FeedbackScreenshot => 'feedback.view',
            default => 'submissions.view',
        });
    }
}
