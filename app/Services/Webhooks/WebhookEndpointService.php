<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Enums\AuditEvent;
use App\Enums\UsageMetric;
use App\Enums\WebhookEndpointStatus;
use App\Http\Controllers\Api\V1\WebhookEndpointController;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Entitlements\QuotaGuard;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\AuditRedactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create / update / soft-delete webhook endpoints (H13a) — the orchestration behind
 * {@see WebhookEndpointController}, keeping it thin. Creation hard-blocks the
 * per-tier endpoint COUNT cap ({@see UsageMetric::WebhookEndpointsCount}) and mints the signing secret
 * server-side (returned in plaintext once, `encrypted` at rest, masked thereafter). Every mutation writes an
 * audit row under the `webhook_endpoint` alias, whose `secret` {@see AuditRedactor} strips.
 */
final class WebhookEndpointService
{
    public function __construct(
        private readonly QuotaGuard $quota,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the validated create payload (name, url, event_types, form_id?)
     */
    public function create(array $data, User $creator): WebhookEndpoint
    {
        return DB::transaction(function () use ($data, $creator): WebhookEndpoint {
            $this->quota->assertCanCreate(UsageMetric::WebhookEndpointsCount);

            $eventTypes = array_values((array) ($data['event_types'] ?? []));

            $endpoint = new WebhookEndpoint;
            $endpoint->forceFill([
                'form_id' => $data['form_id'] ?? null,
                'name' => (string) $data['name'],
                'url' => (string) $data['url'],
                'secret' => self::generateSecret(),
                'event_types' => $eventTypes,
                'status' => WebhookEndpointStatus::Active,
                'signing_algorithm' => 'hmac_sha256',
                'created_by' => $creator->getKey(),
            ]);
            $endpoint->save();

            $this->audit->record(
                AuditEvent::Created,
                'webhook_endpoint',
                (string) $endpoint->getKey(),
                null,
                $this->auditValues($endpoint),
            );

            return $endpoint;
        });
    }

    /**
     * @param  array<string, mixed>  $data  the validated partial update (any of name, url, event_types, form_id, status)
     */
    public function update(WebhookEndpoint $endpoint, array $data): WebhookEndpoint
    {
        return DB::transaction(function () use ($endpoint, $data): WebhookEndpoint {
            $old = $this->auditValues($endpoint);

            foreach (['name', 'url', 'form_id'] as $key) {
                if (array_key_exists($key, $data)) {
                    $endpoint->{$key} = $data[$key];
                }
            }

            if (isset($data['event_types'])) {
                $endpoint->event_types = array_values((array) $data['event_types']);
            }

            if (isset($data['status'])) {
                $status = WebhookEndpointStatus::from((string) $data['status']);
                $endpoint->status = $status;

                // Re-enabling is the manual breaker reset (webhook-integration-design.md §1): clear the
                // consecutive-failure count + reason so a recovered endpoint starts fresh.
                if ($status === WebhookEndpointStatus::Active) {
                    $endpoint->consecutive_failure_count = 0;
                    $endpoint->disabled_reason = null;
                }
            }

            $endpoint->save();

            $this->audit->record(
                AuditEvent::Updated,
                'webhook_endpoint',
                (string) $endpoint->getKey(),
                $old,
                $this->auditValues($endpoint),
            );

            return $endpoint;
        });
    }

    public function delete(WebhookEndpoint $endpoint): void
    {
        DB::transaction(function () use ($endpoint): void {
            $old = $this->auditValues($endpoint);

            $endpoint->delete(); // soft-delete

            $this->audit->record(
                AuditEvent::Deleted,
                'webhook_endpoint',
                (string) $endpoint->getKey(),
                $old,
                null,
            );
        });
    }

    /** A high-entropy signing secret. Prefixed for recognisability, mirroring common webhook-secret formats. */
    public static function generateSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }

    /**
     * The audit snapshot of an endpoint. Includes `secret` deliberately — {@see AuditRedactor}
     * strips it (and records the strip in `redacted_fields`), so the plaintext never reaches the ledger.
     *
     * @return array<string, mixed>
     */
    private function auditValues(WebhookEndpoint $endpoint): array
    {
        return [
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'form_id' => $endpoint->form_id,
            'event_types' => $endpoint->event_types,
            'status' => $endpoint->status->value,
            'secret' => $endpoint->secret,
        ];
    }
}
