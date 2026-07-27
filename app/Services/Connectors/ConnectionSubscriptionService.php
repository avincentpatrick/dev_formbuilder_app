<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\AuditEvent;
use App\Enums\ConnectorSubscriptionStatus;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\User;
use App\Services\Webhooks\WebhookEndpointService;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Create / update / soft-delete the routing rules on a connection (ADR-0009, H15a) — the
 * {@see WebhookEndpointService} analogue for native connectors, minus the secret
 * lifecycle (credentials belong to the connection, not the rule).
 *
 * No quota assert: unlike webhook endpoints there is no per-tier subscription cap in the pricing matrix, and
 * the real cost — actual sends — is already bounded by the shared monthly delivery quota. Recorded as a
 * deliberate narrowing rather than an omission.
 *
 * Audited under the `connection_subscription` alias. Nothing here is credential-bearing, so no redaction
 * entry is needed — `config` is a destination (a channel id), never a token.
 */
final class ConnectionSubscriptionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  the validated create payload (name, event_types, config, form_id?)
     */
    public function create(Connection $connection, array $data, User $creator): ConnectionSubscription
    {
        return DB::transaction(function () use ($connection, $data, $creator): ConnectionSubscription {
            $subscription = new ConnectionSubscription;
            $subscription->forceFill([
                'connection_id' => $connection->getKey(),
                'form_id' => $data['form_id'] ?? null,
                'name' => (string) $data['name'],
                'event_types' => array_values((array) ($data['event_types'] ?? [])),
                'config' => (array) ($data['config'] ?? []),
                'status' => ConnectorSubscriptionStatus::Active,
                'created_by' => $creator->getKey(),
            ]);
            $subscription->save();

            $this->audit->record(
                AuditEvent::Created,
                'connection_subscription',
                (string) $subscription->getKey(),
                null,
                $this->auditValues($subscription),
            );

            return $subscription;
        });
    }

    /**
     * @param  array<string, mixed>  $data  the validated partial update (any of name, event_types, config, form_id, status)
     */
    public function update(ConnectionSubscription $subscription, array $data): ConnectionSubscription
    {
        return DB::transaction(function () use ($subscription, $data): ConnectionSubscription {
            $old = $this->auditValues($subscription);

            foreach (['name', 'form_id'] as $key) {
                if (array_key_exists($key, $data)) {
                    $subscription->{$key} = $data[$key];
                }
            }

            if (isset($data['event_types'])) {
                $subscription->event_types = array_values((array) $data['event_types']);
            }

            if (isset($data['config'])) {
                $subscription->config = (array) $data['config'];
            }

            if (isset($data['status'])) {
                $status = ConnectorSubscriptionStatus::from((string) $data['status']);
                $subscription->status = $status;

                // Re-enabling is the manual breaker reset (the webhook-endpoint convention): a recovered
                // subscription starts from zero consecutive failures rather than one send from re-pausing.
                if ($status === ConnectorSubscriptionStatus::Active) {
                    $subscription->consecutive_failure_count = 0;
                }
            }

            $subscription->save();

            $this->audit->record(
                AuditEvent::Updated,
                'connection_subscription',
                (string) $subscription->getKey(),
                $old,
                $this->auditValues($subscription),
            );

            return $subscription;
        });
    }

    public function delete(ConnectionSubscription $subscription): void
    {
        DB::transaction(function () use ($subscription): void {
            $old = $this->auditValues($subscription);

            $subscription->delete(); // soft-delete

            $this->audit->record(
                AuditEvent::Deleted,
                'connection_subscription',
                (string) $subscription->getKey(),
                $old,
                null,
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(ConnectionSubscription $subscription): array
    {
        return [
            'connection_id' => $subscription->connection_id,
            'form_id' => $subscription->form_id,
            'name' => $subscription->name,
            'event_types' => $subscription->event_types,
            'config' => $subscription->config,
            'status' => $subscription->status->value,
        ];
    }
}
