<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\FormAutomationAction;
use App\Enums\FormAutomationRunStatus;
use App\Enums\FormAutomationTrigger;
use App\Events\SubmissionCreated;
use App\Jobs\Automations\DeliverFormAutomationWebhookJob;
use App\Jobs\Automations\SendFormAutomationEmailJob;
use App\Models\FormAutomation;
use App\Models\FormAutomationRun;
use App\Services\Entitlements\EntitlementService;
use App\Services\Webhooks\WebhookEventDispatcher;
use App\Support\Entitlements\FeatureAdmission;
use App\Support\Tenancy\TenantContext;

/**
 * Turns one accepted response into its form's automation runs (M132, `R-b7bc5149`): one run per enabled automation,
 * each handed to the queue (`D62` = A — nothing here sends anything, and nothing here can delay or refuse the
 * response, which is already stored).
 *
 * ── THE TENANT SCOPE IS THE EVENT'S, AND THIS CLASS ESTABLISHES IT ──────────────────────────────────────────
 * {@see TenantContext::runFor()}, for {@see WebhookEventDispatcher}'s reason: the listener is synchronous today, but
 * the query deciding which automations fire must not depend on whatever context the caller happened to leave.
 *
 * ── AT MOST ONE RUN PER AUTOMATION PER EVENT ────────────────────────────────────────────────────────────────
 * `firstOrCreate` on the event's stable id, and only a run created HERE is dispatched — so a re-raised event finds
 * its runs and queues nothing twice (the `WebhookEventDispatcher` rule, by the same mechanism).
 *
 * A web-address automation on a plan that no longer includes webhooks is recorded `skipped` rather than sent, so the
 * section shows why nothing arrived; the check is {@see FeatureAdmission}'s, the predicate the routes use.
 */
final class FormAutomationDispatcher
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function dispatchFor(SubmissionCreated $event): void
    {
        TenantContext::runFor($event->tenantId, function () use ($event): void {
            $automations = FormAutomation::query()
                ->where('form_id', $event->formId)
                ->where('trigger', FormAutomationTrigger::SubmissionCreated->value)
                ->where('enabled', true)
                ->orderBy('created_at')
                ->get();

            foreach ($automations as $automation) {
                $run = FormAutomationRun::query()->firstOrCreate(
                    ['form_automation_id' => $automation->id, 'event_id' => $event->eventId],
                    ['submission_id' => $event->submissionId, 'status' => FormAutomationRunStatus::Pending],
                );

                if (! $run->wasRecentlyCreated) {
                    continue;
                }

                if ($automation->action === FormAutomationAction::Email) {
                    SendFormAutomationEmailJob::dispatch($event->tenantId, (string) $run->id);

                    continue;
                }

                if (! FeatureAdmission::admits($this->entitlements, 'webhooks')) {
                    $run->forceFill(['status' => FormAutomationRunStatus::Skipped, 'error_code' => 'plan_feature'])->save();

                    continue;
                }

                DeliverFormAutomationWebhookJob::dispatch($event->tenantId, (string) $run->id);
            }
        });
    }
}
