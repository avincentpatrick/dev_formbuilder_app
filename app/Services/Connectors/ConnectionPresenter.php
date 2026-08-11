<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorProviderKey;
use App\Enums\ConnectorSubscriptionStatus;
use App\Enums\DomainEventType;
use App\Enums\UsageMetric;
use App\Http\Resources\Api\V1\ConnectionResource;
use App\Http\Resources\Api\V1\ConnectionSubscriptionResource;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\Form;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Entitlements\EntitlementService;
use App\Services\Webhooks\WebhookEndpointPresenter;
use App\Support\Forms\FormHubLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read model for the Integrations UI (H15b) — the session-authed Inertia surface over the H15a connector
 * framework. The controllers stay thin adapters; every prop shape is built here.
 *
 * Field names mirror {@see ConnectionResource} / {@see ConnectionSubscriptionResource} 1:1 so the web and
 * /api/v1 surfaces describe the same records, but the mapping is INLINE rather than reusing those `Api\V1`
 * classes — the {@see WebhookEndpointPresenter} rule: a web presenter must not couple to an API-versioned
 * class, it adds derivations the resources do not (`form_title`, `provider_label`, `connected_by_name`, the
 * `can` maps), and inline mapping keeps credentials out BY CONSTRUCTION rather than by remembering to exclude
 * them.
 *
 * NO TOKEN APPEARS HERE IN ANY FORM, not even masked (ADR-0009 §D1). Unlike a webhook signing secret, which a
 * tenant must copy into their receiver, a connector token has no user-facing consumer at all — the platform is
 * the only thing that ever uses it. The model's `$hidden` is a backstop; the control is that these methods
 * never name those columns.
 *
 * The delivery log is OFFSET-paginated (`->paginate()`), NOT cursor-paginated like the API controller: the
 * `MdsPagination` primitive needs `current_page`/`last_page`/`total`/`per_page`, which a cursor cannot supply.
 * `payload` and `signature` are never mapped (the same default-exclude-sensitive-content posture as H14).
 */
final class ConnectionPresenter
{
    private const DELIVERIES_PER_PAGE = 25;

    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * The provider catalog, every connected workspace with its rules, and the create-modal option catalogs.
     *
     * @return array<string, mixed>
     */
    public function index(User $user): array
    {
        $connections = Connection::query()
            ->with([
                'subscriptions' => fn ($q) => $q->latest('created_at'),
                'subscriptions.form:id,title',
                'connector:id,name',
            ])
            ->latest('created_at')
            ->get();

        $rules = $connections->flatMap(fn (Connection $c): Collection => $c->subscriptions);

        // The reachable subset for THIS reader, resolved ONCE for the page (J2d) — see the note on
        // `ruleRow()` for why this is server-resolved rather than inferred from `form_title` on the client.
        $formUrls = FormHubLink::pathsFor($user, $rules->pluck('form_id')->filter()->values()->all());

        return [
            'providers' => $this->providerCatalog($connections),
            'connections' => $connections->map(fn (Connection $c): array => [
                ...$this->connectionRow($c, $user),
                'rules' => $c->subscriptions->map(fn (ConnectionSubscription $s): array => $this->ruleRow($s, $formUrls))->all(),
            ])->all(),
            'summary' => [
                'rules' => [
                    'active' => $rules->filter(fn (ConnectionSubscription $s): bool => $s->status === ConnectorSubscriptionStatus::Active)->count(),
                    'total' => $rules->count(),
                ],
                // The SHARED outbound quota (H15a generalized `webhook_deliveries` into one ledger), labelled
                // as shared in the UI. There is deliberately no per-tier connection or rule cap to show — the
                // pricing matrix has no such row, so inventing a tile for one would be a lie.
                'deliveries' => [
                    'used' => $this->entitlements->usage(UsageMetric::WebhookDeliveries),
                    'limit' => $this->entitlements->quota(UsageMetric::WebhookDeliveries),
                ],
            ],
            'forms' => $this->formOptions(),
            'eventTypes' => $this->eventTypeOptions(),
            'can' => ['create' => $user->can('create', Connection::class)],
        ];
    }

    /**
     * One rule's detail plus its offset-paginated delivery log and the edit-modal option catalogs.
     *
     * @return array<string, mixed>
     */
    public function ruleShow(User $user, ConnectionSubscription $rule): array
    {
        $rule->loadMissing('form:id,title');

        // withTrashed() is REQUIRED, not defensive: disconnect() soft-deletes the connection while leaving its
        // rules alive (paused), and the rule routes are flat — so this page is reachable for a rule whose grant
        // is trashed, and the plain relation would resolve to null under the SoftDeletes global scope.
        $connection = $rule->grant()->withTrashed()->with('connector:id,name')->first();

        $paginator = WebhookDelivery::query()
            ->where('connection_subscription_id', $rule->getKey())
            ->latest('created_at')
            ->paginate(self::DELIVERIES_PER_PAGE)
            ->withQueryString();

        /** @var Collection<int, WebhookDelivery> $items */
        $items = collect($paginator->items());

        return [
            'connection' => $connection instanceof Connection ? $this->connectionRow($connection, $user) : null,
            'rule' => $this->ruleDetail($rule, $user),
            'deliveries' => [
                'data' => $items->map(fn (WebhookDelivery $d): array => $this->deliveryRow($d))->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                ],
            ],
            'forms' => $this->formOptions(),
            'eventTypes' => $this->eventTypeOptions(),
            'can' => [
                'update' => $user->can('update', $rule),
                'delete' => $user->can('delete', $rule),
            ],
        ];
    }

    /**
     * The providers a tenant can connect. `configured` is false when this deployment has no app credentials for
     * the provider — the UI then disables the button and says so, rather than bouncing the tenant off a consent
     * screen that fails with an opaque provider error. It is also what makes the page render sanely in CI,
     * where no Slack credentials exist.
     *
     * @param  Collection<int, Connection>  $connections
     * @return list<array<string, mixed>>
     */
    private function providerCatalog(Collection $connections): array
    {
        // Compared as strings rather than enum cases. This was originally because a single-case enum made an
        // enum-to-enum identity check narrow to a constant that PHPStan (correctly) called always-true; H16a
        // adds a second case, so the reason has lapsed — the comparison is left alone anyway because changing
        // it buys nothing and `$live` is a value list either way.
        $live = $connections
            ->filter(fn (Connection $c): bool => $c->status === ConnectionStatus::Active)
            ->map(fn (Connection $c): string => $c->provider->value)
            ->all();

        return array_map(function (ConnectorProviderKey $key) use ($live): array {
            $clientId = config("connectors.providers.{$key->value}.client_id");
            $scopes = config("connectors.providers.{$key->value}.scopes", []);

            return [
                'key' => $key->value,
                'label' => $key->label(),
                'description' => $this->providerDescription($key),
                'scopes' => is_array($scopes) ? array_values(array_map(strval(...), $scopes)) : [],
                'configured' => is_string($clientId) && $clientId !== '',
                'connect_url' => '/integrations/'.$key->value.'/connect',
                'connected' => in_array($key->value, $live, true),
            ];
        }, ConnectorProviderKey::cases());
    }

    /**
     * `default`-less on purpose — the H8 forcing device. A new {@see ConnectorProviderKey} case with no arm
     * here is an `UnhandledMatchError` the first time anyone opens Integrations, which is a loud failure at
     * the moment the case is added rather than a silent blank card discovered by a tenant later.
     */
    private function providerDescription(ConnectorProviderKey $key): string
    {
        return match ($key) {
            ConnectorProviderKey::Slack => 'Post new submissions and form status changes straight into a Slack channel.',
            ConnectorProviderKey::GoogleSheets => 'Append every new submission as a row in one of your Google Sheets. We only get access to the spreadsheets you pick.',
        };
    }

    /**
     * A connected workspace. Carries no token in any form — see the class docblock.
     *
     * @return array<string, mixed>
     */
    private function connectionRow(Connection $connection, User $user): array
    {
        return [
            'id' => $connection->id,
            'provider' => $connection->provider->value,
            'provider_label' => $connection->provider->label(),
            'external_account_id' => $connection->external_account_id,
            'external_account_label' => $connection->external_account_label,
            'scopes' => $connection->scopes,
            'status' => $connection->status->value,
            // True once disconnect() has soft-deleted the grant. The rule page still renders for its orphaned
            // rules, so it needs to say why nothing will be delivered.
            'disconnected' => $connection->trashed(),
            'token_expires_at' => $this->iso($connection->token_expires_at),
            'last_refreshed_at' => $this->iso($connection->last_refreshed_at),
            'last_error' => $connection->last_error,
            'last_error_at' => $this->iso($connection->last_error_at),
            'connected_by_name' => $this->connectorName($connection),
            'created_at' => $this->iso($connection->created_at),
            'can' => [
                'update' => $user->can('update', $connection),
                'delete' => $user->can('delete', $connection),
            ],
        ];
    }

    /**
     * A list-row projection of a delivery rule. `config` is projected into an explicit destination pair rather
     * than shipped whole, so a later key in that blob cannot leak to the client by default.
     *
     * @param  array<string, string>  $formUrls  id => hub path, for the ids this reader may open
     * @return array<string, mixed>
     */
    private function ruleRow(ConnectionSubscription $rule, array $formUrls = []): array
    {
        $config = $rule->config;

        return [
            'id' => $rule->id,
            'connection_id' => $rule->connection_id,
            'name' => $rule->name,
            'event_types' => $rule->event_types,
            'form_id' => $rule->form_id,
            'form_title' => $this->formTitle($rule),
            // ⚠️ SERVER-RESOLVED, NOT INFERRED FROM `form_title === null` ON THE CLIENT (Increment J2d) —
            // the twin of the note in `WebhookEndpointPresenter::row()`, and it exists twice because the
            // tempting shortcut exists twice. An absent key means "no link", never "no form".
            'form_url' => $rule->form_id === null ? null : ($formUrls[$rule->form_id] ?? null),
            'channel_id' => is_string($config['channel_id'] ?? null) ? $config['channel_id'] : null,
            'channel_name' => is_string($config['channel_name'] ?? null) ? $config['channel_name'] : null,
            'status' => $rule->status->value,
            'consecutive_failure_count' => $rule->consecutive_failure_count,
            'last_success_at' => $this->iso($rule->last_success_at),
            'last_failure_at' => $this->iso($rule->last_failure_at),
            'created_at' => $this->iso($rule->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleDetail(ConnectionSubscription $rule, User $user): array
    {
        return [
            ...$this->ruleRow($rule, FormHubLink::pathsFor($user, array_filter([$rule->form_id]))),
            'updated_at' => $this->iso($rule->updated_at),
        ];
    }

    /**
     * A delivery-log row from the SHARED ledger. Mirrors {@see WebhookEndpointPresenter}'s field list with the
     * connector owner column substituted; `payload` + `signature` are intentionally absent (potential PII /
     * credential-adjacent).
     *
     * @return array<string, mixed>
     */
    private function deliveryRow(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'connection_subscription_id' => $delivery->connection_subscription_id,
            'event_id' => $delivery->event_id,
            'event_type' => $delivery->event_type->value,
            'status' => $delivery->status->value,
            'attempt_count' => $delivery->attempt_count,
            'max_attempts' => $delivery->max_attempts,
            'next_retry_at' => $this->iso($delivery->next_retry_at),
            'last_attempted_at' => $this->iso($delivery->last_attempted_at),
            'response_status_code' => $delivery->response_status_code,
            'response_body_excerpt' => $delivery->response_body_excerpt,
            'response_time_ms' => $delivery->response_time_ms,
            'created_at' => $this->iso($delivery->created_at),
            'updated_at' => $this->iso($delivery->updated_at),
        ];
    }

    /**
     * The tenant's forms as scope options for the rule modal (the tenant-wide null choice is added by the
     * client). RLS-scoped, so only this tenant's forms appear.
     *
     * @return list<array{value: string, label: string}>
     */
    private function formOptions(): array
    {
        return array_values(Form::query()
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn (Form $f): array => ['value' => $f->id, 'label' => $f->title])
            ->all());
    }

    /**
     * The subscribable event catalog with the SAME human labels the webhook UI uses — one catalog, one set of
     * words, whichever channel a tenant is configuring. Since I3 that is structural rather than promised:
     * both surfaces read {@see DomainEventType::label()}.
     *
     * @return list<array{value: string, label: string}>
     */
    private function eventTypeOptions(): array
    {
        return array_map(
            static fn (DomainEventType $t): array => ['value' => $t->value, 'label' => $t->label()],
            DomainEventType::cases(),
        );
    }

    private function formTitle(ConnectionSubscription $rule): ?string
    {
        $title = data_get($rule, 'form.title');

        return is_string($title) ? $title : null;
    }

    private function connectorName(Connection $connection): ?string
    {
        $name = data_get($connection, 'connector.name');

        return is_string($name) ? $name : null;
    }

    private function iso(?Carbon $value): ?string
    {
        return $value?->toIso8601String();
    }
}
