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

        // ONE grouped query for the whole page, never one per row. `ruleRow()` is called N times and a
        // per-row lookup would make a page that issues a handful of queries today issue N more — the same 3N
        // trap the JR3 review caught on the forms list.
        $pausedReasons = $this->pausedReasons($rules);

        return [
            'providers' => $this->providerCatalog($connections),
            'connections' => $connections->map(fn (Connection $c): array => [
                ...$this->connectionRow($c, $user),
                'rules' => $c->subscriptions->map(fn (ConnectionSubscription $s): array => $this->ruleRow($s, $formUrls, $pausedReasons, $c->provider))->all(),
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
            // The provider comes from the withTrashed() lookup above, so a rule whose grant was disconnected
            // still renders its destination rather than losing it exactly when the tenant went looking.
            'rule' => $this->ruleDetail($rule, $user, $connection instanceof Connection ? $connection->provider : null),
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
                // H16b — a standing condition of the DEPLOYMENT, not of this request, so the page states it
                // rather than waiting for the weekly failure to imply it. Null once the app is published.
                'notice' => $this->providerNotice($key),
            ];
        }, ConnectorProviderKey::cases());
    }

    /**
     * `default`-less on purpose — the H8 forcing device. A new {@see ConnectorProviderKey} case with no arm
     * here is an `UnhandledMatchError` the first time anyone opens Integrations, which is a loud failure at
     * the moment the case is added rather than a silent blank card discovered by a tenant later.
     */
    /**
     * A standing caveat about the provider's own configuration, or null when there is nothing to say.
     *
     * ── WHY THIS SENTENCE EXISTS AT ALL (H16b) ─────────────────────────────────────────────────────────────
     * While the Google Cloud project is in `testing`, Google expires every refresh token after SEVEN DAYS.
     * `access_type=offline` does not prevent it and the hourly `RefreshConnectorTokensJob` cannot save it —
     * a token Google has already expired cannot be refreshed. So the connection dies weekly, and the tenant
     * sees `statusVariant('refresh_failed')`'s bare **"Reconnect needed"**: a red badge, every week, with no
     * cause given. The reasonable conclusion from that is that our token handling is broken.
     *
     * The fix is not a better badge, it is naming the cause. This is the difference between a product that
     * looks unreliable and one that is being honest about a deployment state its user cannot see.
     *
     * Read from config so publishing the app removes it by flipping one env var, with no template edit and
     * nothing left behind to go stale. `default`-less on the enum for the H8 forcing-device reason the
     * method below records.
     */
    private function providerNotice(ConnectorProviderKey $key): ?string
    {
        return match ($key) {
            ConnectorProviderKey::Slack => null,
            ConnectorProviderKey::GoogleSheets => config('connectors.providers.google_sheets.publishing_status') === 'testing'
                ? 'Google hasn’t published this app yet, so it treats every connection as a test and expires the sign-in after 7 days. Someone will need to reconnect weekly until then. That’s Google’s policy for apps in testing, not a fault in Meridian.'
                : null,
            // H16c. Airtable has no verification review and no equivalent of Google's 7-day testing expiry, so
            // there is no standing deployment condition to warn about. Null because there is nothing true to
            // say, not because nobody looked.
            ConnectorProviderKey::Airtable => null,
        };
    }

    private function providerDescription(ConnectorProviderKey $key): string
    {
        return match ($key) {
            ConnectorProviderKey::Slack => 'Post new submissions and form status changes straight into a Slack channel.',
            ConnectorProviderKey::GoogleSheets => 'Append every new submission as a row in one of your Google Sheets. We only get access to the spreadsheets you pick.',
            ConnectorProviderKey::Airtable => 'Add every new submission as a record in one of your Airtable tables. We can read your table names and add records — we can’t change how your bases are built.',
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
            // ── H16c: THE SHAPE OF THIS GRANT'S DESTINATION, RESOLVED HERE RATHER THAN RE-DERIVED CLIENT-SIDE.
            // `RuleFormModal` gated three behaviours on `props.provider === 'google_sheets'` — the ONLY
            // provider literal in the whole front end — which would have given an Airtable grant Slack's
            // channel picker, Slack's event list and Slack's `config` shape. Every other provider fact on this
            // surface is already server-resolved (`destination_label`, `providerDescription()`), for the reason
            // this file's own docblocks give: resolving a provider is the server's job.
            //
            // ONE key, not a capability bag. A first draft also shipped `enumerable`, `creatable` and the two
            // nouns; nothing on the client read them, and an unread prop is the `--block`-with-no-consumer
            // smell this repo has been bitten by before. They arrive when something needs them.
            'destination_kind' => $connection->provider->isTabular() ? 'tabular' : 'channel',
        ];
    }

    /**
     * A list-row projection of a delivery rule. `config` is projected into an explicit destination pair rather
     * than shipped whole, so a later key in that blob cannot leak to the client by default.
     *
     * @param  array<string, string>  $formUrls  id => hub path, for the ids this reader may open
     * @param  array<string, string>  $pausedReasons  rule id => the reason its last delivery was blocked
     * @param  ?ConnectorProviderKey  $provider  the owning grant's provider, passed in — see destinationLabel()
     * @return array<string, mixed>
     */
    private function ruleRow(ConnectionSubscription $rule, array $formUrls = [], array $pausedReasons = [], ?ConnectorProviderKey $provider = null): array
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
            // ── H16b: the tabular destination, projected key by key like the Slack pair above ─────────────
            // Same rule, same reason: `config` is never shipped whole, so a key added to that blob later
            // cannot reach the client by default.
            'spreadsheet_id' => is_string($config['spreadsheet_id'] ?? null) ? $config['spreadsheet_id'] : null,
            'sheet_name' => is_string($config['sheet_name'] ?? null) ? $config['sheet_name'] : null,
            // H16c. Airtable's stable table id; null for Sheets, which has no equivalent the editor uses.
            'sheet_id' => is_string($config['sheet_id'] ?? null) ? $config['sheet_id'] : null,
            // Composed here rather than in the client. The id is opaque and the canonical URL form is a
            // deployment fact, not a rendering choice — the same argument `form_url` above records.
            'spreadsheet_url' => $this->destinationUrl($provider, $config),
            'mapping' => $this->mappingProjection($config),
            // ONE string the row can render whatever the provider is, so the destination column does not need
            // a `provider === 'x'` branch per cell. `ConnectionPresenter` already learned this lesson from
            // `providerDescription()`'s default-less match: the place to resolve a provider is the server.
            'destination_label' => $this->destinationLabel($rule, $provider),
            'status' => $rule->status->value,
            // Why a paused rule is paused. Without it the UI can only say "Paused", which for the two causes
            // that actually occur — a drifted header row, and a destination we can no longer reach — is the
            // whole of the information the tenant needs and none of what they get.
            'paused_reason' => $pausedReasons[(string) $rule->getKey()] ?? null,
            'consecutive_failure_count' => $rule->consecutive_failure_count,
            'last_success_at' => $this->iso($rule->last_success_at),
            'last_failure_at' => $this->iso($rule->last_failure_at),
            'created_at' => $this->iso($rule->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleDetail(ConnectionSubscription $rule, User $user, ?ConnectorProviderKey $provider): array
    {
        return [
            ...$this->ruleRow(
                $rule,
                FormHubLink::pathsFor($user, array_filter([$rule->form_id])),
                $this->pausedReasons(collect([$rule])),
                $provider,
            ),
            'updated_at' => $this->iso($rule->updated_at),
        ];
    }

    /**
     * Rule id => why its most recent blocked delivery was blocked, for the rules given.
     *
     * ── ONE QUERY FOR THE WHOLE PAGE ───────────────────────────────────────────────────────────────────────
     * `DISTINCT ON` is Postgres's answer to "latest row per group" and this schema is Postgres-only by
     * decision (ADR-0001), so there is no portability cost to pay. The alternative shapes were both worse: a
     * lookup inside `ruleRow()` is N queries on a page that renders every rule of every connection, and a
     * window-function subquery buys nothing here because only one column is wanted.
     *
     * ⚠️ READS THE LEDGER RATHER THAN A NEW COLUMN, DELIBERATELY. The obvious alternative is
     * `connection_subscriptions.paused_reason`, written by `finishBlocked()`. It was rejected because it
     * duplicates state that already exists and can therefore disagree with it: the delivery row is what the
     * detail page's log renders, and a second copy would need clearing on resume, on re-map, on manual pause,
     * and on every future path that changes status — four chances to leave a stale sentence under a healthy
     * rule.
     *
     * The scan is bounded by the ledger's own retention and keyed by `connection_subscription_id`, which is
     * indexed as the ledger's owner column.
     *
     * @param  Collection<int, ConnectionSubscription>  $rules
     * @return array<string, string>
     */
    private function pausedReasons(Collection $rules): array
    {
        $ids = $rules
            ->filter(fn (ConnectionSubscription $r): bool => $r->status !== ConnectorSubscriptionStatus::Active)
            ->map(fn (ConnectionSubscription $r): string => (string) $r->getKey())
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return WebhookDelivery::query()
            ->select(['connection_subscription_id', 'response_body_excerpt'])
            ->whereIn('connection_subscription_id', $ids)
            ->whereNotNull('response_body_excerpt')
            // Only a `blocked` outcome carries an explanation a human can act on. An ordinary transport
            // failure's excerpt is the provider's own response body, which is exactly the unreviewed
            // third-party text `ConnectorChannelDirectory::message()` refuses to put on a page.
            ->where('response_body_excerpt', 'like', '[%]%')
            ->orderBy('connection_subscription_id')
            ->orderByDesc('created_at')
            ->distinct('connection_subscription_id')
            ->get()
            ->mapWithKeys(fn (WebhookDelivery $d): array => [
                (string) $d->connection_subscription_id => (string) $d->response_body_excerpt,
            ])
            ->all();
    }

    /**
     * The tenant-facing name of wherever this rule delivers, provider-agnostic.
     *
     * Null rather than a placeholder when a rule has no destination configured: `MdsDataTable`'s cell renders
     * an em dash for an absent value, and inventing "Not set" here would put a second vocabulary for the same
     * state beside the one the design system already has.
     */
    private function destinationLabel(ConnectionSubscription $rule, ?ConnectorProviderKey $provider): ?string
    {
        $config = $rule->config;

        // ⚠️ THE PROVIDER IS PASSED IN, NEVER READ AS `$rule->grant?->provider`. Both callers already hold the
        // Connection — `index()` is inside its own map over it, `ruleShow()` resolved it withTrashed() at the
        // top — and the relation is NOT eager-loaded on the index query, so touching it here would lazy-load
        // one parent per rule: N queries added to the page this presenter's grouped `pausedReasons()` exists
        // to keep at one. It would also resolve to null on this page's most interesting case, because the
        // SoftDeletes global scope hides a disconnected grant.
        if ($provider?->isTabular() === true) {
            $title = is_string($config['spreadsheet_title'] ?? null) ? $config['spreadsheet_title'] : null;
            $sheet = is_string($config['sheet_name'] ?? null) && $config['sheet_name'] !== '' ? $config['sheet_name'] : null;

            // The title is stored at authoring time as a convenience label, so a renamed document shows a stale
            // name rather than costing a provider round trip on every page render. The id is the identity;
            // this is only ever the caption.
            $fallback = $provider === ConnectorProviderKey::Airtable ? 'Base' : 'Spreadsheet';
            $name = $title ?? (is_string($config['spreadsheet_id'] ?? null) && $config['spreadsheet_id'] !== '' ? $fallback : null);

            if ($name === null) {
                return null;
            }

            return $sheet === null ? $name : $name.' · '.$sheet;
        }

        $channelName = is_string($config['channel_name'] ?? null) && $config['channel_name'] !== '' ? $config['channel_name'] : null;
        $channelId = is_string($config['channel_id'] ?? null) && $config['channel_id'] !== '' ? $config['channel_id'] : null;

        return $channelName !== null ? '#'.$channelName : $channelId;
    }

    /**
     * The stored mapping, shaped for the editor.
     *
     * Returned as-stored rather than re-derived: the fingerprint is what {@see MappingDriftDetector} compares
     * against, so anything that reshapes it here would make the client's idea of the mapping and the
     * delivery path's idea of it two different things.
     *
     * @param  array<string, mixed>  $config
     * @return array{fingerprint: string, columns: list<array{header: string, field_key: ?string}>}|null
     */
    private function mappingProjection(array $config): ?array
    {
        $mapping = $config['mapping'] ?? null;

        if (! is_array($mapping) || ! is_array($mapping['columns'] ?? null)) {
            return null;
        }

        $columns = [];

        foreach ($mapping['columns'] as $column) {
            if (! is_array($column)) {
                continue;
            }

            $columns[] = [
                'header' => is_scalar($column['header'] ?? null) ? (string) $column['header'] : '',
                'field_key' => is_string($column['field_key'] ?? null) && $column['field_key'] !== '' ? $column['field_key'] : null,
            ];
        }

        return [
            'fingerprint' => is_string($mapping['fingerprint'] ?? null) ? $mapping['fingerprint'] : '',
            'columns' => $columns,
        ];
    }

    /**
     * A deep link to the rule's destination, composed rather than stored.
     *
     * ⚠️ THIS HARD-CODED THE GOOGLE DOCS URL UNTIL H16c, so an Airtable rule would have offered its owner an
     * "open the spreadsheet" link into `docs.google.com/spreadsheets/d/appXXXX` — a 404 on the wrong product.
     * Composed rather than read from a stored `destination_url` on purpose: a URL saved at authoring time is a
     * third thing that can go stale beside the title, and both providers' URL shapes are stable and public.
     *
     * `default`-less on the enum, the forcing device the rest of this surface uses.
     *
     * @param  array<string, mixed>  $config
     */
    private function destinationUrl(?ConnectorProviderKey $provider, array $config): ?string
    {
        $id = $config['spreadsheet_id'] ?? null;

        if ($provider === null || ! is_string($id) || $id === '') {
            return null;
        }

        return match ($provider) {
            ConnectorProviderKey::Slack => null,
            ConnectorProviderKey::GoogleSheets => 'https://docs.google.com/spreadsheets/d/'.rawurlencode($id).'/edit',
            // Table-scoped when the id is known: Airtable resolves a base-only URL to whichever table the
            // viewer last had open, which is not necessarily the one this rule writes into.
            ConnectorProviderKey::Airtable => is_string($config['sheet_id'] ?? null) && $config['sheet_id'] !== ''
                ? 'https://airtable.com/'.rawurlencode($id).'/'.rawurlencode($config['sheet_id'])
                : 'https://airtable.com/'.rawurlencode($id),
        };
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
