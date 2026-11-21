<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\AfterSubmit;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Exceptions\Submissions\FormNotAcceptingSubmissionException;
use App\Exceptions\Submissions\SubmissionEditException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\Tenant;
use App\Support\Guest\GuestShareToken;
use App\Support\Guest\GuestShareTokenService;
use App\Support\Tenancy\TenantUrl;

/**
 * A respondent changing the response they SENT (M248, `D232`, NHETE recommendation 6a): the link they keep, and the
 * read and write behind it. The controller stays thin; every rule lives here or below.
 *
 * Who may change it, all decided live on every request, never from the token:
 *   - the token names a GUEST response on its own form (a member's response is theirs to fix through staff tools);
 *   - the form offers "Change my answers", takes guests, and is inside its open window ({@see FormAcceptanceGuard::assertCanAmend()});
 *   - the response is still `submitted` — not under review, approved, returned or archived (`D232` answer 3).
 *
 * The write itself is {@see SubmissionAnswerEditService::amendAsRespondent()}, the staff correction's own path, so a
 * respondent's change is validated, indexed and recorded exactly as an editor's is.
 */
final class RespondentAmendService
{
    public function __construct(
        private readonly SubmissionAnswerEditService $edits,
        private readonly FormAcceptanceGuard $guard,
        private readonly GuestShareTokenService $tokens,
    ) {}

    /**
     * The link a respondent keeps for the response they just sent, or null when there is none to give: the form does not
     * offer "Change my answers", or the response was not finalized as `submitted` (a screened-out one has nothing to change).
     *
     * @return array{token: string, url: string, until: ?string}|null
     */
    public function linkFor(Form $form, Submission $submission): ?array
    {
        if ($form->after_submit !== AfterSubmit::Amend || $submission->status !== SubmissionStatus::Submitted) {
            return null;
        }

        $minted = $this->tokens->mintAmend(
            (string) $submission->tenant_id,
            (string) $form->id,
            (string) $submission->form_version_id,
            (string) $submission->id,
        );

        // The tenant always exists here — its RLS context is live — so firstOrFail keeps the host resolution total.
        $tenant = Tenant::query()->whereKey($submission->tenant_id)->firstOrFail();

        return [
            'token' => $minted->token,
            'url' => TenantUrl::toPublic($tenant, "f/amend/{$minted->token}"),
            'until' => $form->closes_at?->toIso8601String(),
        ];
    }

    /**
     * The guest response an amend token names, with its form and pinned version — or null when there is none: a token
     * for a response since deleted, or one that is not a guest's.
     *
     * @return array{0: Form, 1: Submission, 2: FormVersion}|null
     */
    public function find(GuestShareToken $token): ?array
    {
        $submission = Submission::query()
            ->whereKey($token->submissionId)
            ->where('form_id', $token->formId)
            ->where('source', SubmissionSource::Guest)
            ->whereNull('respondent_user_id')
            ->first();

        $form = $submission === null ? null : Form::query()->whereKey($token->formId)->first();

        if ($submission === null || $form === null) {
            return null;
        }

        // ⚠️ The submission's OWN version, never the form's current one: the stored answers are keyed to it.
        return [$form, $submission, FormVersion::query()->whereKey($submission->form_version_id)->firstOrFail()];
    }

    /**
     * Refuse unless the respondent may change this response right now.
     *
     * @throws FormNotAcceptingSubmissionException the form no longer offers it, or is closed
     * @throws SubmissionEditException the response is no longer `submitted`
     */
    public function assertAmendable(Form $form, Submission $submission): void
    {
        $this->guard->assertCanAmend($form);

        if (! in_array($submission->status, SubmissionAnswerEditService::RESPONDENT_AMENDABLE, true)) {
            throw SubmissionEditException::respondentLocked();
        }
    }

    /**
     * Write the respondent's changed answers. The form's conditions are checked again under the response's lock, against
     * a freshly read form row, so switching "Change my answers" off or closing the form mid-request is honoured.
     *
     * @param  array<string, mixed>  $answers
     *
     * @throws FormNotAcceptingSubmissionException
     * @throws SubmissionEditException
     * @throws SubmissionValidationException
     */
    public function amend(Form $form, Submission $submission, FormVersion $version, array $answers, ?string $baseline): Submission
    {
        $this->assertAmendable($form, $submission);

        return $this->edits->amendAsRespondent(
            $submission,
            $version,
            $answers,
            $baseline,
            function () use ($form): void {
                $this->guard->assertCanAmend(Form::query()->whereKey($form->id)->firstOrFail());
            },
        );
    }
}
