<?php

declare(strict_types=1);

namespace App\Support\Connectors;

use App\Enums\ConnectorProviderKey;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Support\Mapping\ColumnMapping;
use Illuminate\Http\Request;

/**
 * The per-provider validation rules for a delivery rule's `config` payload (H16a).
 *
 * ── WHY THIS CLASS EXISTS ────────────────────────────────────────────────────────────────────────────────
 *
 * H15a's four request classes — the /api/v1 store+update and their byte-identical tenant-web twins — each
 * hard-coded `'config.channel_id' => ['required', 'string', 'max:64']`. That reads as provider-agnostic
 * validation and is not: it is SLACK'S destination shape, sitting in the shared layer, and it means a Google
 * Sheets rule is rejected by validation before any adapter is consulted. The framework looked ready for a
 * second provider and, at exactly this seam, was not.
 *
 * The rules therefore dispatch on the CONNECTION's provider, resolved from the route binding. Putting the
 * dispatch here rather than a `match` in each of the four classes is the same argument the rest of the
 * framework already makes: `ConnectorRegistry` exists so that "nothing else needs a `match` over providers",
 * and four copies of one would drift the moment a third provider lands with a shape only two of them learn.
 *
 * ── WHY NOT ON `ConnectorProvider` ───────────────────────────────────────────────────────────────────────
 *
 * A fifth interface method was rejected for the reason that interface's own docblock gives: it is pinned at
 * "four responsibilities, deliberately no more", and validating an HTTP request is not one of them — an
 * adapter that never sees a request should not be asked to describe one. The shape being validated is a
 * property of the DESTINATION KIND, which is why {@see ConnectorProviderKey::isTabular()} is the axis.
 *
 * ── WHAT IS VALIDATED, AND WHAT IS DELIBERATELY NOT ──────────────────────────────────────────────────────
 *
 * Shape only, never existence — H15a's rule, unchanged and for the same reason: confirming a spreadsheet is
 * reachable needs an API call this request has no business making, and a rule whose destination has gone away
 * is a DELIVERY-time condition the adapter already reports as `blocked`. The one thing that is enforced is
 * that a destination is present at all, because a rule with none can never succeed and accepting it defers a
 * permanent failure to a place where it looks like an outage rather than a mistake.
 *
 * The `mapping` payload is validated as a nested shape rather than through
 * {@see ColumnMapping::fromArray()}. Reusing the parser here would be tempting and wrong:
 * it throws `InvalidArgumentException`, which is a 500, where a bad payload owes the caller a 422 naming the
 * field. The parser stays the authority at USE time; this is the request-shaped gate in front of it.
 */
final class SubscriptionConfigRules
{
    /**
     * The `config.*` rules for whichever provider owns this request's connection.
     *
     * An UNRESOLVED provider (no binding on the route, or a route model that failed to bind) falls back to
     * requiring only that `config` is an array. That is deliberate: the alternative is guessing a provider,
     * and every route reaching here already carries an authorization gate on the same binding, so an
     * unresolved provider means the request is being rejected anyway.
     *
     * `$partial` is the PATCH shape: `config` itself becomes optional, but every key inside it becomes
     * `required_with:config`, because `config` is replaced WHOLESALE when present (a deep merge would make
     * "clear this key" unexpressible). A partial update that sends `config` without its destination would
     * otherwise erase the destination and leave a rule that validates and can never deliver.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?ConnectorProviderKey $provider, bool $partial = false): array
    {
        // Later duplicate keys win in a PHP array literal, so the provider's presence rules OVERRIDE the
        // union's `nullable` for the same field. See documentedShape() for why the union is there at all.
        return [...self::documentedShape($partial), ...self::requiredFor($provider, $partial)];
    }

    /**
     * Every `config` key ANY provider accepts, with its type and length rules, all optional.
     *
     * ── WHY A UNION EXISTS AT ALL ────────────────────────────────────────────────────────────────────────
     *
     * Two reasons, and the second is the one that is easy to lose.
     *
     * **(1) It is real validation.** Type and length are provider-independent: a `sheet_name` longer than 100
     * characters is malformed whoever sent it, and it is interpolated into an A1-notation range.
     *
     * **(2) IT IS WHAT KEEPS `openapi.json` HONEST.** Scramble builds the API contract by statically reading
     * `rules()`. It cannot evaluate a method call, so when the whole `config` shape was returned dynamically
     * the exported contract degraded `config` to `{"type": "array", "items": {"type": "string"}}` — the
     * destination shape vanished from the published API docs entirely, and the CI contract gate did NOT catch
     * it, because that gate only diffs a fresh export against the committed file: both were equally wrong.
     * A static literal here is what Scramble can read.
     *
     * The cost is stated rather than hidden: the exported contract shows every destination key as optional,
     * because which ones are required depends on the connection being posted to. Both request classes say so
     * in the description Scramble publishes.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function documentedShape(bool $partial = false): array
    {
        return [
            'config' => [$partial ? 'sometimes' : 'required', 'array'],

            // Slack. `channel_name` is nullable and that is load-bearing rather than lax: when the channel
            // list fails to load the modal falls back to a manual channel id, which by definition has no name.
            'config.channel_id' => ['nullable', 'string', 'max:64'],
            'config.channel_name' => ['nullable', 'string', 'max:150'],

            // Google Sheets (H16a). `sheet_name` omitted means Google's own default first tab.
            'config.spreadsheet_id' => ['nullable', 'string', 'max:255'],
            'config.sheet_name' => ['nullable', 'string', 'max:100'],
            'config.mapping' => ['nullable', 'array'],
            'config.mapping.fingerprint' => ['nullable', 'string', 'max:64'],
            'config.mapping.columns' => ['nullable', 'array', 'min:1'],
            'config.mapping.columns.*.header' => ['present', 'string', 'max:255'],
            'config.mapping.columns.*.field_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Only the PRESENCE rules — which of the union's keys this provider cannot do without.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function requiredFor(?ConnectorProviderKey $provider, bool $partial = false): array
    {
        if ($provider === null) {
            return [];
        }

        $present = $partial ? 'required_with:config' : 'required';

        if (! $provider->isTabular()) {
            return ['config.channel_id' => [$present, 'string', 'max:64']];
        }

        return [
            'config.spreadsheet_id' => [$present, 'string', 'max:255'],
            'config.mapping' => [$present, 'array'],
            'config.mapping.fingerprint' => [$present, 'string', 'max:64'],
            'config.mapping.columns' => [$present, 'array', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributesFor(?ConnectorProviderKey $provider): array
    {
        if ($provider !== null && $provider->isTabular()) {
            return [
                'config.spreadsheet_id' => 'spreadsheet',
                'config.sheet_name' => 'tab',
                'config.mapping' => 'column mapping',
            ];
        }

        return [
            'config.channel_id' => 'channel',
            'config.channel_name' => 'channel name',
        ];
    }

    /**
     * The default "config.spreadsheet id field is required" is unreadable, and the destination is the field a
     * tenant is most likely to leave empty.
     *
     * @return array<string, string>
     */
    public static function messagesFor(?ConnectorProviderKey $provider): array
    {
        // Both the `.required` and `.required_with` keys are supplied because the create and PATCH shapes use
        // different rules for the same field, and a message keyed to the wrong one silently falls back to
        // Laravel's "config.spreadsheet id field is required" — which is the string this method exists to
        // avoid, reappearing on exactly one of the two surfaces.
        if ($provider !== null && $provider->isTabular()) {
            return [
                'config.spreadsheet_id.required' => 'Choose a spreadsheet to write into.',
                'config.spreadsheet_id.required_with' => 'Choose a spreadsheet to write into.',
                'config.mapping.required' => 'Set up which form field goes in which column.',
                'config.mapping.required_with' => 'Set up which form field goes in which column.',
                'config.mapping.columns.required' => 'Set up which form field goes in which column.',
                'config.mapping.columns.required_with' => 'Set up which form field goes in which column.',
            ];
        }

        return [
            'config.channel_id.required' => 'Choose a channel to deliver into.',
            'config.channel_id.required_with' => 'Choose a channel to deliver into.',
        ];
    }

    /**
     * The provider that owns this request's rule, from whichever binding the route carries.
     *
     * Both shapes exist and neither is wrong: create routes bind the `{connection}` the rule is being made
     * ON, while the tenant-web update/delete routes are deliberately FLAT (`/integrations/rules/{rule}`),
     * because a nested `{connection}` would 404 after a disconnect — which soft-deletes the grant while
     * KEEPING its rules paused. Resolving both here is what lets all four request classes stay one line.
     */
    public static function providerFor(Request $request): ?ConnectorProviderKey
    {
        $connection = $request->route('connection');

        if ($connection instanceof Connection) {
            return $connection->provider;
        }

        foreach (['connectionSubscription', 'subscription'] as $parameter) {
            $subscription = $request->route($parameter);

            if ($subscription instanceof ConnectionSubscription) {
                // `grant`, not `connection` — Eloquent's base Model already declares a protected `$connection`
                // (the database connection name), so the relation is named for the domain word ADR-0009 uses.
                return $subscription->grant?->provider;
            }
        }

        return null;
    }
}
