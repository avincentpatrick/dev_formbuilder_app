<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\FormAutomationAction;
use App\Models\Form;
use App\Models\FormAutomation;
use App\Models\FormAutomationRun;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Support\Entitlements\FeatureAdmission;

/**
 * A form's automations as the settings section shows them (M132, `R-b7bc5149`), for both entry points — the builder's
 * Form settings and the hub's Settings tab.
 *
 * ── A WEB ADDRESS IS SHOWN WHOLE ONLY TO WHOEVER MAY MANAGE IT ──────────────────────────────────────────────
 * The address receives the answers, and a hook address is often its own credential (`D83`). So the full `url` goes
 * only to a holder of `webhooks.manage`; any other editor of the form sees its host, that it exists, and its runs —
 * enough to know answers leave the workspace and where to, never enough to send them somewhere else.
 *
 * `can_webhook` is whether this viewer may add one, asked as the route asks it: the permission, and the plan through
 * {@see FeatureAdmission}, so the section never offers a choice whose save would be refused.
 */
final class FormAutomationPresenter
{
    /** How many recent runs each automation shows. */
    private const int RECENT_RUNS = 5;

    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * @return array{can_webhook: bool, max: int, items: list<array<string, mixed>>}
     */
    public function forForm(Form $form, ?User $viewer): array
    {
        $automations = FormAutomation::query()->where('form_id', $form->id)->orderBy('created_at')->get();

        return [
            'can_webhook' => $viewer !== null && $viewer->can('webhooks.manage') && FeatureAdmission::admits($this->entitlements, 'webhooks'),
            'max' => FormAutomationService::MAX_PER_FORM,
            'items' => array_values($automations->map(fn (FormAutomation $automation): array => $this->item($automation, $viewer))->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function item(FormAutomation $automation, ?User $viewer): array
    {
        $isWebhook = $automation->action === FormAutomationAction::Webhook;
        $mayManage = ! $isWebhook || ($viewer !== null && $viewer->can('webhooks.manage'));

        $runs = FormAutomationRun::query()
            ->where('form_automation_id', $automation->id)
            ->latest()
            ->limit(self::RECENT_RUNS)
            ->get();

        return [
            'id' => $automation->id,
            'name' => $automation->name,
            'action' => $automation->action->value,
            'enabled' => $automation->enabled,
            'recipients' => $automation->recipients,
            'url' => $isWebhook && $mayManage ? $automation->url : null,
            'host' => $automation->host(),
            'manageable' => $mayManage,
            'runs' => array_values($runs->map(static fn (FormAutomationRun $run): array => [
                'id' => $run->id,
                'status' => $run->status->value,
                'label' => $run->status->label(),
                'at' => ($run->last_attempted_at ?? $run->created_at)?->toIso8601String(),
                'response_status' => $run->response_status,
                'error_code' => $run->error_code,
            ])->all()),
        ];
    }
}
