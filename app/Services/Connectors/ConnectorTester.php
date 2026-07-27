<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\ConnectionStatus;
use App\Enums\DomainEventType;
use App\Events\DomainEvent;
use App\Exceptions\Connectors\UnknownConnectorProviderException;
use App\Jobs\Connectors\DeliverConnectorMessageJob;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Services\Webhooks\WebhookTester;
use App\Support\Connectors\ConnectorProvider;
use App\Support\Connectors\ConnectorRegistry;
use App\Support\Tenancy\TenantContext;
use Ramsey\Uuid\Uuid;

/**
 * Sends one synthetic `test.ping` through a delivery rule so a tenant can prove the wiring before a real event
 * depends on it (H15b) — the {@see WebhookTester} analogue for native connectors.
 *
 * It matters more here than it does for webhooks. Slack grants `channels:read` over every public channel but
 * `chat:write` only over channels the app has joined, so the single most likely failure is a rule pointed at a
 * channel nobody invited the bot to — and without a test send the tenant discovers that when their first real
 * submission silently fails to arrive.
 *
 * IT GOES THROUGH THE ADAPTER'S OWN {@see ConnectorProvider::deliver()}, not a private
 * copy of it. That is the whole point of a test: exercising the shipped path means it inherits the SSRF guard,
 * the no-redirect option, the bounded timeouts and — critically — Slack's `ok:false`-at-HTTP-200 classification,
 * none of which can then drift away from what a real delivery does. It also means H16's providers get a tester
 * for free.
 *
 * SYNCHRONOUS and side-effect-free by construction: it persists no `webhook_deliveries` row and consumes no
 * quota, because both of those live in {@see DeliverConnectorMessageJob} rather than in `deliver()`.
 *
 * A CREDENTIAL REJECTION IS REPORTED, NOT ACTED ON. The delivery job revokes a grant and pauses its rules when
 * Slack rejects the token, and that stays the job's job: a transient blip during a voluntary test must not be
 * able to tear down a working connection the tenant never asked us to touch.
 *
 * `test.ping` is deliberately NOT a {@see DomainEventType} (the H13b convention) — it exists only in
 * this request body, never in the ledger's `event_type` vocabulary, which a DB CHECK and the model's enum cast
 * pin to the real catalog.
 */
final class ConnectorTester
{
    public function __construct(private readonly ConnectorRegistry $registry) {}

    /**
     * @return array{delivered: bool, response_status: ?int, response_time_ms: ?int, response_body_excerpt: ?string, error: ?string}
     */
    public function send(Connection $connection, ConnectionSubscription $subscription): array
    {
        if ($connection->status !== ConnectionStatus::Active) {
            return $this->result(false, null, null, null, '[connection_inactive] Reconnect this workspace before sending a test message.');
        }

        try {
            $adapter = $this->registry->for($connection->provider);
        } catch (UnknownConnectorProviderException) {
            return $this->result(false, null, null, null, '[provider_unavailable] This integration is not available right now.');
        }

        $startedAt = microtime(true);
        $outcome = $adapter->deliver($connection, $subscription, $this->envelope());
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        // On failure the adapter's excerpt is always a `[marker] message` string, so it goes in `error` — the
        // field the result modal parses for a human reason. Putting it in `response_body_excerpt` (where a
        // receiver's own body belongs) would render it as a raw code block under "the delivery could not be
        // completed", which is exactly the unhelpful outcome the marker convention exists to avoid.
        return $outcome->delivered
            ? $this->result(true, $outcome->responseStatus, $elapsedMs, $outcome->responseExcerpt, null)
            : $this->result(false, $outcome->responseStatus, $elapsedMs, null, $outcome->responseExcerpt);
    }

    /**
     * The synthetic envelope. Shaped like a real {@see DomainEvent} envelope so the provider's formatter takes
     * exactly the path it takes in production, with no `form_id` — a test belongs to the rule, not to a form.
     *
     * @return array<string, mixed>
     */
    private function envelope(): array
    {
        return [
            'event_id' => Uuid::uuid7()->toString(),
            'event_type' => 'test.ping',
            'occurred_at' => now()->toIso8601String(),
            'tenant_id' => TenantContext::currentTenantId(),
            'api_version' => DomainEvent::API_VERSION,
            'data' => ['message' => 'This is a test event from your form-builder workspace.'],
        ];
    }

    /**
     * The {@see WebhookTester::send()} return shape, byte for byte. Not a coincidence and not worth forking:
     * it lets the outcome ride the existing `testResult` flash key, type as the existing `FlashTestResult`, and
     * render through the existing TestResultModal — which already parses the `[marker] message` prefix the
     * Slack adapter emits.
     *
     * @return array{delivered: bool, response_status: ?int, response_time_ms: ?int, response_body_excerpt: ?string, error: ?string}
     */
    private function result(bool $delivered, ?int $status, ?int $ms, ?string $excerpt, ?string $error): array
    {
        return [
            'delivered' => $delivered,
            'response_status' => $status,
            'response_time_ms' => $ms,
            'response_body_excerpt' => $excerpt,
            'error' => $error,
        ];
    }
}
