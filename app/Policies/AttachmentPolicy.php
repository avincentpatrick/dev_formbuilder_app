<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AttachmentKind;
use App\Enums\ScanStatus;
use App\Models\Attachment;
use App\Models\FormField;
use App\Models\Submission;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;

/**
 * Authorization for the authenticated read-side of a stored file (Increment G6). RLS already scopes every
 * `attachments` query to the current tenant, so a cross-tenant id never resolves (route-model binding 404s
 * before this runs) — this policy adds the role gate AND, since M33, the per-form scope. Serving is
 * additionally gated on the scan status ({@see ScanStatus::servable()}) in the controller, so an
 * unscanned/infected file is withheld regardless of permission.
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
 * ── WHY THE GATE ALSO READS THE OWNER (M33, ADR-0015 §D10) ──────────────────────────────────────────────
 * M29 taught this policy the `kind`; it did not teach it the SCOPE, and it said so. {@see SubmissionPolicy::view()}
 * requires `submissions.view` AND org-wide visibility or per-form collaboration or being the respondent.
 * The default arm here required only the permission, so `GET /attachments/{attachment}` read any stored
 * object in the tenant by id with no per-form check at all — while every surface that LISTS those objects
 * is scoped. The affected roles were exactly `form_editor` and `reviewer`: `hasOrgWideVisibility()` is
 * `dashboard.org.view`, which owner, admin and viewer hold, so for those three `SubmissionPolicy` already
 * granted tenant-wide and there was no gap.
 *
 * ⛔ THE SHARPEST CONSEQUENCE WAS THAT REVOCATION DID NOT REVOKE. Remove a `form_editor` from a form's
 * collaborators and `SubmissionPolicy` refuses them the submission on the next request — while every
 * attachment id they had ever seen kept working through this route, indefinitely. "Hard to guess" is not a
 * mitigation when the ids were legitimately theirs once.
 *
 * ⚠️ THE OWNER IS RESOLVED LOCALLY, AND THAT IS A CONSTRAINT RATHER THAN A STYLE CHOICE. `attachable_type`
 * carries five aliases and only three are in the global morph map: `tenant` (brand logo) and
 * `feedback_report` (screenshot) are DELIBERATELY absent, because registering them would change how
 * Sanctum's `tokenable_type` and Spatie's `model_type` serialize and split existing rows between alias and
 * FQCN — the `enforceMorphMap` break that cost 90 test failures. `BrandingMorphAliasTest` pins that absence
 * and prescribes this exact remedy: "a LOCAL resolution (a match on `kind`, or a dedicated relation), never
 * a global registration". So nothing here touches `$attachment->attachable`.
 *
 * ⚠️ THE `match` IS EXHAUSTIVE OVER THE ENUM WITH NO `default` ARM, DELIBERATELY. A default is what
 * absorbed the seventh kind silently and produced the M29 defect; without one, PHPStan reports the tenth
 * kind as unhandled and the decision has to be taken at this site, once per kind, the way M29 argued.
 */
final class AttachmentPolicy
{
    public function __construct(private readonly ResourceGrantResolver $grants) {}

    public function view(User $user, Attachment $attachment): bool
    {
        return match ($attachment->kind) {
            // ADR-0015 §D6 put this image in the shared table; §D8 marks it PII. It is read under the
            // feedback permission on both routes that serve it, or under neither. (M29)
            AttachmentKind::FeedbackScreenshot => $user->can('feedback.view'),

            // ADR-0015 §D10. A delivery envelope is the full payload of whatever form fired it, so it
            // crosses every per-form boundary at once — there is no form to scope it TO. That makes it
            // tenant infrastructure rather than submission data, and the people entitled to it are the
            // people who configure the endpoint it was sent to. `webhooks.manage` is Owner/Admin.
            // ⚠️ This is a NARROWING from `submissions.view` (all five roles) and is `D4` in
            // `docs/claims/decisions.md`, which names the revert path.
            AttachmentKind::WebhookPayloadArchive => $user->can('webhooks.manage'),

            // Deliberately NOT scoped and deliberately not narrowed: `GET /branding/logo` serves these
            // same bytes UNAUTHENTICATED to email clients (routes/tenant.php's invitation group), because
            // a logo inside a sent message is fetched days later by a client with no session. Tightening
            // this route would protect nothing that is not already public. Every seeded role holds
            // `submissions.view`, so this arm reads "any member of the workspace".
            AttachmentKind::BrandingLogo => $user->can('submissions.view'),

            // Submission-domain media. `submissions.view` is the floor — it states that this is
            // respondent data at all — and the owner check adds the scope that floor never had.
            AttachmentKind::SubmissionFile,
            AttachmentKind::FieldMediaSample,
            AttachmentKind::SignatureCapture,
            AttachmentKind::OcrSourceScan,
            AttachmentKind::ExportArtifact => $user->can('submissions.view')
                && $this->withinOwnerScope($user, $attachment),

            // Wired by nothing today. It fails closed rather than falling into the submission arm, whose
            // scope is meaningless for a file owned by a user: an avatar feature must decide here.
            AttachmentKind::Avatar => false,
        };
    }

    /**
     * The per-form scope, resolved from `attachable_type` without morph resolution (see the class
     * docblock). An alias this method does not know FAILS CLOSED — including `tenant` and
     * `feedback_report`, which the kinds above already answer for and which must never reach here.
     */
    private function withinOwnerScope(User $user, Attachment $attachment): bool
    {
        return match ($attachment->attachable_type) {
            'submission' => $this->scopedToSubmission($user, $attachment->attachable_id),
            'form_field' => $this->scopedToStagedField($user, $attachment->attachable_id),
            default => false,
        };
    }

    /**
     * A persisted file: delegate the whole question to {@see SubmissionPolicy::view()} through the Gate.
     *
     * ⚠️ DELEGATION RATHER THAN A COPY OF THE PREDICATE, AND THAT IS THE POINT OF THE FIX. Re-implementing
     * "org-wide OR collaborates OR respondent" here would create a second copy that can drift from the
     * first — which is the divergence {@see Submission::scopeVisibleTo()}'s mirror-pin already exists to
     * prevent between the single-row check and the list query. This makes a third surface agree by
     * construction instead of by review.
     *
     * A missing row (soft-deleted, or an id whose owner has gone) fails closed.
     */
    private function scopedToSubmission(User $user, string $submissionId): bool
    {
        $submission = Submission::query()->whereKey($submissionId)->first();

        return $submission !== null && $user->can('view', $submission);
    }

    /**
     * A file still STAGED against the form field it answers, before its submission exists — the window
     * `AttachmentStorageService::store()` opens and `SubmissionDraftService` closes by re-pointing the row
     * at the submission.
     *
     * There is no submission to delegate to yet, so the scope is the field's FORM, expressed with the same
     * two terms {@see SubmissionPolicy::view()} uses for a row nobody responded to: tenant-wide visibility,
     * or a grant covering that form in either capacity. The respondent arm has no analogue here — a staged
     * file has no respondent yet, which is the whole reason it is staged.
     */
    private function scopedToStagedField(User $user, string $fieldId): bool
    {
        $formId = FormField::query()->whereKey($fieldId)->first()?->version?->form_id;

        return $formId !== null
            && ($user->can('dashboard.org.view') || $this->grants->holdsAny($user, (string) $formId));
    }
}
