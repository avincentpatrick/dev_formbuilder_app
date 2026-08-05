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
        $present = $partial ? 'required_with:config' : 'required';

        $rules = ['config' => [$partial ? 'sometimes' : 'required', 'array']];

        if ($provider === null) {
            return $rules;
        }

        if (! $provider->isTabular()) {
            // Slack. `channel_name` is nullable and that is load-bearing rather than lax: when the channel
            // list fails to load the modal falls back to a manual channel id, which by definition has no name.
            return $rules + [
                'config.channel_id' => [$present, 'string', 'max:64'],
                'config.channel_name' => ['nullable', 'string', 'max:150'],
            ];
        }

        return $rules + [
            'config.spreadsheet_id' => [$present, 'string', 'max:255'],
            // Google's own default tab name, used when the rule does not name one. Bounded because it is
            // interpolated into an A1-notation range.
            'config.sheet_name' => ['nullable', 'string', 'max:100'],
            'config.mapping' => [$present, 'array'],
            'config.mapping.fingerprint' => [$present, 'string', 'max:64'],
            'config.mapping.columns' => [$present, 'array', 'min:1'],
            'config.mapping.columns.*.header' => ['present', 'string', 'max:255'],
            'config.mapping.columns.*.field_key' => ['nullable', 'string', 'max:100'],
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
