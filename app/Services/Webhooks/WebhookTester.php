<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Events\DomainEvent;
use App\Exceptions\Webhooks\BlockedWebhookUrlException;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy\TenantContext;
use App\Support\Webhooks\OutboundUrlGuard;
use App\Support\Webhooks\WebhookSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;

/**
 * Sends a synthetic `test.ping` to an endpoint so a tenant can verify it receives + correctly verifies the
 * signature before relying on it for real events (H13b — webhook-integration-design.md §5). SYNCHRONOUS +
 * inline: it runs the SAME SSRF guard + dual-secret signer + no-redirect / bounded-timeout POST as a real
 * delivery ({@see DeliverWebhookJob}), but persists NO `webhook_deliveries` row, never touches the monthly
 * quota or the retry ladder, and returns the outcome to the caller directly (the delivery-log UI is H14).
 *
 * `test.ping` is deliberately NOT a {@see DomainEventType} — it exists only in this request body, never in the
 * delivery-log's `event_type` vocabulary (which a DB CHECK + the model's enum cast pin to the real catalog).
 * The test runs against an endpoint in ANY status, so a tenant can verify a paused endpoint before re-enabling.
 */
final class WebhookTester
{
    public function __construct(
        private readonly OutboundUrlGuard $guard,
        private readonly WebhookSigner $signer,
    ) {}

    /**
     * @return array{delivered: bool, response_status: ?int, response_time_ms: ?int, response_body_excerpt: ?string, error: ?string}
     */
    public function send(WebhookEndpoint $endpoint): array
    {
        $timestamp = Carbon::now()->toIso8601String();
        $eventId = Uuid::uuid7()->toString();
        $payload = [
            'event_id' => $eventId,
            'event_type' => 'test.ping',
            'occurred_at' => $timestamp,
            'tenant_id' => TenantContext::currentTenantId(),
            'api_version' => DomainEvent::API_VERSION,
            'data' => ['message' => 'This is a test event from your form-builder workspace.'],
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        // Same delivery-time SSRF re-validation a real delivery runs — never send to a private/internal host.
        try {
            $this->guard->assertPublic($endpoint->url);
        } catch (BlockedWebhookUrlException $e) {
            return $this->result(false, null, null, null, '['.$e->reason.'] '.$e->getMessage());
        }

        $signature = $this->signer->signatureHeaderFor($endpoint, $timestamp, $rawBody);
        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders([
                WebhookSigner::SIGNATURE_HEADER => $signature,
                WebhookSigner::TIMESTAMP_HEADER => $timestamp,
                WebhookSigner::EVENT_ID_HEADER => $eventId,
                'User-Agent' => 'FormBuilder-Webhooks/1',
            ])
                ->withOptions(['allow_redirects' => false]) // never follow a 3xx into an internal host
                ->connectTimeout((int) config('webhooks.connect_timeout', 5))
                ->timeout((int) config('webhooks.delivery_timeout', 10))
                ->withBody($rawBody, 'application/json')
                ->post($endpoint->url);
        } catch (ConnectionException $e) {
            return $this->result(false, null, $this->elapsedMs($startedAt), null, '[transport_error] '.$e->getMessage());
        }

        return $this->result(
            $response->successful(),
            $response->status(),
            $this->elapsedMs($startedAt),
            $this->excerpt($response->body()),
            null,
        );
    }

    /**
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

    /** Truncate + strip invalid UTF-8 from the response body (mirrors the delivery job's excerpt). */
    private function excerpt(string $body): string
    {
        $clean = mb_convert_encoding($body, 'UTF-8', 'UTF-8');

        return mb_substr($clean, 0, (int) config('webhooks.response_excerpt_bytes', 2000));
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
