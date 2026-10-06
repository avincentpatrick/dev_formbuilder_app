<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\AuditEvent;
use App\Enums\FormAutomationAction;
use App\Enums\FormAutomationTrigger;
use App\Exceptions\Webhooks\BlockedWebhookUrlException;
use App\Models\Form;
use App\Models\FormAutomation;
use App\Models\User;
use App\Services\Webhooks\WebhookEndpointService;
use App\Support\Audit\AuditLogger;
use App\Support\Webhooks\OutboundUrlGuard;
use App\Support\Webhooks\WebhookSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * The only writer of a form's automations (M132, `R-b7bc5149`): create, change, delete — each audited under the
 * `form_automation` alias inside the write's transaction — and the "Send a test" a web-address automation offers.
 *
 * ── WHAT THE AUDIT ROW CARRIES, AND WHAT IT NEVER DOES ──────────────────────────────────────────────────────
 * The name, the action, whether it is on, the recipients (redacted as PII by `AuditRedactor`) and, for a web
 * address, its HOST. Never the full address and never the secret: a web address that receives answers is itself a
 * credential (`D83`), and the alias is registered in `AuditRedactor::SECRETS` for both as the backstop.
 *
 * ── THE SECRET IS RETURNED ONCE ──────────────────────────────────────────────────────────────────────────
 * {@see create()} hands the new secret back to its caller, which shows it to the author once; the model hides it from
 * serialization, and nothing else ever returns it. Minted exactly as a workspace webhook's is.
 */
final class FormAutomationService
{
    /** The most automations one form may have — a list, not a programme. The settings section reads it. */
    public const int MAX_PER_FORM = 10;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AutomationCondition $conditions,
    ) {}

    /**
     * @param  list<string>|null  $recipients
     * @return array{0: FormAutomation, 1: string|null} the automation, and the secret to show once (web address only)
     *
     * @throws ValidationException when the form already has the most automations it may, or the condition (M142) cannot be used
     */
    public function create(Form $form, string $name, FormAutomationAction $action, ?array $recipients, ?string $url, User $actor, ?string $condition = null): array
    {
        return DB::transaction(function () use ($form, $name, $action, $recipients, $url, $actor, $condition): array {
            // The form row lock serializes two tabs adding the tenth and the eleventh at once.
            $locked = Form::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();
            $max = self::MAX_PER_FORM;

            if (FormAutomation::query()->where('form_id', $locked->id)->count() >= $max) {
                throw ValidationException::withMessages([
                    'name' => ["A form can have at most {$max} automations. Delete one to add another."],
                ]);
            }

            $secret = $action === FormAutomationAction::Webhook ? WebhookEndpointService::generateSecret() : null;

            $automation = new FormAutomation([
                'name' => $name,
                'trigger' => FormAutomationTrigger::SubmissionCreated,
                'action' => $action,
                'recipients' => $action === FormAutomationAction::Email ? $recipients : null,
                'url' => $action === FormAutomationAction::Webhook ? $url : null,
                'enabled' => true,
                'condition' => $this->conditions->normalize($locked, $condition),
            ]);
            $automation->forceFill([
                'form_id' => $locked->id,
                'secret' => $secret,
                'created_by' => $actor->getKey(),
            ])->save();

            $this->record(AuditEvent::Created, $automation, null, $this->auditValues($automation), $actor);

            return [$automation, $secret];
        });
    }

    /**
     * Change an automation's name, its on/off state, its condition (M142), its recipients (email) or its address (web
     * address). The action itself never changes: that is a different automation.
     *
     * @param  array{name?: string, enabled?: bool, recipients?: list<string>, url?: string, condition?: string|null}  $changes
     */
    public function update(FormAutomation $automation, array $changes, User $actor): FormAutomation
    {
        return DB::transaction(function () use ($automation, $changes, $actor): FormAutomation {
            $old = $this->auditValues($automation);

            $fill = array_intersect_key($changes, array_flip(['name', 'enabled']));
            // M142 — named here, because this whitelist silently drops any key it does not name.
            if (array_key_exists('condition', $changes)) {
                $fill['condition'] = $this->conditions->normalize($automation->form()->firstOrFail(), $changes['condition']);
            }
            if ($automation->action === FormAutomationAction::Email && array_key_exists('recipients', $changes)) {
                $fill['recipients'] = $changes['recipients'];
            }
            if ($automation->action === FormAutomationAction::Webhook && array_key_exists('url', $changes)) {
                $fill['url'] = $changes['url'];
            }

            $automation->fill($fill)->save();

            $this->record(AuditEvent::Updated, $automation, $old, $this->auditValues($automation), $actor);

            return $automation;
        });
    }

    public function delete(FormAutomation $automation, User $actor): void
    {
        DB::transaction(function () use ($automation, $actor): void {
            $old = $this->auditValues($automation);
            $automation->delete();
            $this->record(AuditEvent::Deleted, $automation, $old, null, $actor);
        });
    }

    /**
     * Send one signed test request to a web-address automation, now, and report what came back — so an author can
     * see the address works before a respondent's answers depend on it. No answers are in it: the body says it is a
     * test. Not recorded as a run and not metered, as the workspace webhook test ping is not.
     *
     * @return array{ok: bool, status: int|null, message: string}
     */
    public function test(FormAutomation $automation): array
    {
        if ($automation->action !== FormAutomationAction::Webhook || $automation->url === null || $automation->secret === null) {
            return ['ok' => false, 'status' => null, 'message' => 'Only an automation that sends to a web address can be tested.'];
        }

        try {
            app(OutboundUrlGuard::class)->assertPublic($automation->url);
        } catch (BlockedWebhookUrlException $e) {
            return ['ok' => false, 'status' => null, 'message' => $e->getMessage()];
        }

        $occurredAt = Carbon::now()->toIso8601String();
        $payload = [
            'event_id' => Uuid::uuid7()->toString(),
            'event_type' => 'automation.test',
            'occurred_at' => $occurredAt,
            'test' => true,
            'automation' => ['id' => $automation->id, 'name' => $automation->name],
            'form' => ['id' => $automation->form_id, 'title' => (string) $automation->form?->title],
            'submission' => null,
            'answers' => (object) [],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        try {
            $response = Http::withHeaders([
                WebhookSigner::SIGNATURE_HEADER => app(WebhookSigner::class)->signatureHeader($automation->secret, $occurredAt, $body),
                WebhookSigner::TIMESTAMP_HEADER => $occurredAt,
                WebhookSigner::EVENT_ID_HEADER => $payload['event_id'],
                'User-Agent' => 'FormBuilder-Automations/1',
            ])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout((int) config('webhooks.connect_timeout', 5))
                ->timeout((int) config('webhooks.delivery_timeout', 10))
                ->withBody($body, 'application/json')
                ->post($automation->url);
        } catch (ConnectionException) {
            return ['ok' => false, 'status' => null, 'message' => 'The address did not answer. Check it and try again.'];
        }

        return $response->successful()
            ? ['ok' => true, 'status' => $response->status(), 'message' => "The address answered {$response->status()}."]
            : ['ok' => false, 'status' => $response->status(), 'message' => "The address answered {$response->status()}, which is not a success."];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(FormAutomation $automation): array
    {
        return [
            'name' => $automation->name,
            'action' => $automation->action->value,
            'enabled' => $automation->enabled,
            'recipients' => $automation->recipients,
            'host' => $automation->host(),
            // M142 — whether there is one, never its text: a condition's values can echo a respondent's own words.
            'has_condition' => $automation->condition !== null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function record(AuditEvent $event, FormAutomation $automation, ?array $old, ?array $new, User $actor): void
    {
        $this->audit->record($event, 'form_automation', (string) $automation->getKey(), old: $old, new: $new, actorId: (string) $actor->getKey());
    }
}
