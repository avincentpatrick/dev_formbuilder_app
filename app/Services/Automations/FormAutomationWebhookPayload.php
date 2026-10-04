<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\FormAutomationTrigger;
use App\Events\DomainEvent;
use App\Models\FormAutomation;
use App\Models\FormAutomationRun;
use App\Models\Submission;
use App\Services\Submissions\SubmissionRowProjector;
use App\Support\Connectors\Providers\GoogleSheetsConnector;
use App\Support\Submissions\SubmissionReference;
use Illuminate\Support\Carbon;

/**
 * The body a web-address automation sends (M132, `R-b7bc5149`, `D83` = the answers): the event, the automation, the
 * form, the response's reference and metadata, and its ANSWERS keyed by question key.
 *
 * ── THE ANSWERS ARE THE EXPORT'S, BY THE SAME PROJECTOR ─────────────────────────────────────────────────────
 * {@see SubmissionRowProjector} is what the CSV export and the {@see GoogleSheetsConnector} write, so a value
 * reads the same in every place a response leaves the app: a choice by its label, in the FORM's default language,
 * a date as the export formats it. Rebuilt at send time from the stored response, never stored on the run, so no
 * answer sits in a ledger nobody prunes.
 *
 * The envelope keeps the workspace webhooks' names — `event_id`, `event_type`, `occurred_at`, `api_version` — so a
 * receiver that verifies one verifies the other.
 */
final class FormAutomationWebhookPayload
{
    public function __construct(private readonly SubmissionRowProjector $projector) {}

    /**
     * @return array<string, mixed>|null null when the response no longer exists, so there is nothing to send
     */
    public function build(FormAutomationRun $run, FormAutomation $automation): ?array
    {
        $submission = Submission::query()->with(['answers', 'formVersion.form'])->find($run->submission_id);
        $version = $submission?->formVersion;
        $form = $version?->form;

        if ($submission === null || $version === null || $form === null) {
            return null;
        }

        $locale = $form->default_locale ?? 'en';
        [, $fieldMeta] = $this->projector->resolveColumns(collect([$version]), $locale);

        return [
            'event_id' => $run->event_id,
            'event_type' => FormAutomationTrigger::SubmissionCreated->value,
            'occurred_at' => ($run->created_at ?? Carbon::now())->toIso8601String(),
            'api_version' => DomainEvent::API_VERSION,
            'automation' => ['id' => $automation->id, 'name' => $automation->name],
            'form' => ['id' => $form->id, 'title' => $form->title],
            'submission' => [
                'id' => $submission->id,
                'reference' => SubmissionReference::format($submission->reference),
                'status' => $submission->status->value,
                'source' => $submission->source->value,
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
            ],
            // An object even when empty, so a receiver always reads `answers` as a map.
            'answers' => (object) $this->projector->answerValues($submission, $fieldMeta, $locale),
        ];
    }
}
