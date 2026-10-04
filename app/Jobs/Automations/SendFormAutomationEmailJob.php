<?php

declare(strict_types=1);

namespace App\Jobs\Automations;

use App\Enums\FormAutomationAction;
use App\Enums\FormAutomationRunStatus;
use App\Enums\QueueName;
use App\Jobs\TenantAwareJob;
use App\Models\FormAutomationRun;
use App\Models\Submission;
use App\Models\Tenant;
use App\Notifications\Automations\FormAutomationNotification;
use App\Support\Branding\BrandPalette;
use App\Support\Submissions\SubmissionReference;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Sends one response's notice to one email automation's addresses (M132, `R-b7bc5149`, `D82`): the form, the
 * reference number and the time, with a link that opens the response. **Never an answer** — the link leads into the
 * app, where `can:view,submission` decides who may read it.
 *
 * Everything the message shows is resolved HERE, under the tenant context, and handed to a scalar-only queued
 * notification — one per address, so no recipient sees another's address. The link is {@see TenantUrl::to()}, the
 * APP host and never a custom domain, which serves only the guest pages. `succeeded` means handed to the mail queue,
 * which is all any email in this app can know.
 */
#[Queue(QueueName::Mail)]
final class SendFormAutomationEmailJob extends TenantAwareJob
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $runId,
    ) {}

    protected function handleForTenant(): void
    {
        $run = FormAutomationRun::query()->with('automation.form')->find($this->runId);

        if ($run === null || $run->status !== FormAutomationRunStatus::Pending) {
            return;
        }

        $automation = $run->automation;

        if ($automation === null || $automation->action !== FormAutomationAction::Email) {
            return;
        }

        if (! $automation->enabled) {
            $this->finish($run, FormAutomationRunStatus::Skipped, 'disabled');

            return;
        }

        $submission = Submission::query()->find($run->submission_id);
        $form = $automation->form;
        $tenant = Tenant::query()->find($this->tenantId); // RLS-exempt central table

        if ($submission === null || $form === null || $tenant === null) {
            $this->finish($run, FormAutomationRunStatus::Failed, 'submission_missing');

            return;
        }

        $timezone = $form->timezone ?: 'UTC';
        $at = ($submission->submitted_at ?? $run->created_at ?? Carbon::now())->copy()->setTimezone($timezone);
        $brand = BrandPalette::forTenantId($this->tenantId);

        foreach ($automation->recipients ?? [] as $address) {
            Notification::route('mail', $address)->notify(
                (new FormAutomationNotification(
                    formTitle: $form->title,
                    automationName: $automation->name,
                    reference: SubmissionReference::format($submission->reference),
                    submittedAt: $at->format('j M Y, g:i A').' ('.$timezone.')',
                    responseUrl: TenantUrl::to($tenant, 'submissions/'.$submission->id),
                ))->withBrand($brand)
            );
        }

        $this->finish($run, FormAutomationRunStatus::Succeeded, null);
    }

    private function finish(FormAutomationRun $run, FormAutomationRunStatus $status, ?string $code): void
    {
        $run->forceFill([
            'status' => $status,
            'attempt_count' => $run->attempt_count + 1,
            'error_code' => $code,
            'last_attempted_at' => Carbon::now(),
        ])->save();
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function failureContext(): array
    {
        return ['form_automation_run_id' => $this->runId];
    }
}
