<?php

declare(strict_types=1);

namespace App\Support\Connectors;

use App\Enums\ConnectorProviderKey;
use App\Enums\DomainEventType;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Support\Connectors\Providers\GoogleSheetsConnector;
use App\Support\Mapping\ColumnMapping;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

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
     * ── ⚠️ THE COMMENT ABOVE A KEY IS PUBLISHED VERBATIM, SO INTERNAL REASONING GOES HERE INSTEAD ─────────
     * Scramble lifts the comment directly preceding an entry into that property's `description` in
     * `openapi.json` — `spreadsheet_id`'s "Google Sheets (H16a)…" line is already in the shipped contract,
     * and I11a records the same trap shipping internal reasoning into the public spec once before. H16b
     * nearly repeated it: the first draft of `spreadsheet_title` carried five lines about jsonb and length
     * bounds, and the regen diff is the ONLY thing that catches it — the CI contract gate diffs a fresh
     * export against the committed file, so a bad description that is committed is a description that
     * passes. Keep the inline comments publishable; argue here.
     *
     * `spreadsheet_title` (H16b) is validated rather than tolerated as an unknown key because `config` is
     * persisted as whatever survives validation: an unlisted key still reaches jsonb, just without a type or
     * a length bound. It is a CAPTION, so the rules list can render "Q3 Intake · Responses" without a Google
     * round trip per row, and a stale caption after the tenant renames their sheet is the correct outcome —
     * the id is the identity, and re-reading the real title on every page render would trade a harmless
     * staleness for N API calls.
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

            // The tabular destination (H16a). A Google spreadsheet id, or an Airtable base id.
            'config.spreadsheet_id' => ['nullable', 'string', 'max:255'],
            // The tab or table inside it. Omitted means the destination's own first one.
            'config.sheet_name' => ['nullable', 'string', 'max:100'],
            // Airtable (H16c). The table's stable id, so renaming the table cannot break the rule.
            'config.sheet_id' => ['nullable', 'string', 'max:64'],
            // The spreadsheet's name when the rule was saved, shown as a caption. Optional, and never used to
            // identify the destination — `spreadsheet_id` does that.
            'config.spreadsheet_title' => ['nullable', 'string', 'max:200'],
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
     * ── H16c MADE THIS A PER-PROVIDER `match` RATHER THAN AN `isTabular()` BRANCH ─────────────────────────
     * The boolean was right while "tabular" had one member: the two tabular providers share `spreadsheet_id`
     * and `mapping`, but Airtable additionally cannot deliver without `sheet_id`. Keying that off `isTabular()`
     * would have made it required for Sheets, which never sends one — and leaving it optional for Airtable
     * would accept a rule that silently falls back to the table NAME, so a rename turns into a 404 weeks later
     * with nothing pointing at the cause.
     *
     * `default`-less, so a fourth provider has to state its own destination keys instead of inheriting
     * Google's. `SubscriptionConfigRulesTest`'s "every provider declares a required destination key" case is
     * the other half of that guard.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function requiredFor(?ConnectorProviderKey $provider, bool $partial = false): array
    {
        if ($provider === null) {
            return [];
        }

        $present = $partial ? 'required_with:config' : 'required';

        $mapping = [
            'config.spreadsheet_id' => [$present, 'string', 'max:255'],
            'config.mapping' => [$present, 'array'],
            'config.mapping.fingerprint' => [$present, 'string', 'max:64'],
            'config.mapping.columns' => [$present, 'array', 'min:1'],
        ];

        return match ($provider) {
            ConnectorProviderKey::Slack => ['config.channel_id' => [$present, 'string', 'max:64']],
            ConnectorProviderKey::GoogleSheets => $mapping,
            ConnectorProviderKey::Airtable => [...$mapping, 'config.sheet_id' => [$present, 'string', 'max:64']],
        };
    }

    /**
     * The events a rule on `$provider` can actually be delivered (H16b).
     *
     * ── WHY THIS IS VALIDATION AND NOT JUST A UI FILTER ────────────────────────────────────────────────────
     * {@see GoogleSheetsConnector::deliver()} already refuses a
     * non-submission event with `[unsupported_event]`, and refusing it there is correct — an adapter cannot
     * trust that a stored rule was written by our own form. But that refusal happens at DELIVERY time, where
     * `blocked` pauses the rule and emails the owner. So a rule that could never have worked spends its first
     * real event teaching the tenant that their integration is broken, and the fix is to go back and change
     * something the form should not have accepted.
     *
     * The reason is structural rather than a Google quirk: only `submission.*` events carry a submission id,
     * and a tabular destination writes a ROW OF ANSWERS. `form.published` has no answers to write, so the
     * honest options are refuse it or append a row of blanks. This is the same argument the class docblock
     * already makes about a missing destination — "a rule with none can never succeed and accepting it defers
     * a permanent failure to a place where it looks like an outage rather than a mistake".
     *
     * @return list<string>
     */
    public static function deliverableEventTypes(?ConnectorProviderKey $provider): array
    {
        if ($provider === null || ! $provider->isTabular()) {
            return DomainEventType::values();
        }

        return array_values(array_filter(
            DomainEventType::values(),
            static fn (string $value): bool => str_starts_with($value, 'submission.'),
        ));
    }

    /**
     * A validator hook rejecting events the provider cannot deliver.
     *
     * Returned as a closure for the request classes' `after()` rather than folded into `rules()`, and that is
     * deliberate on the same grounds as {@see documentedShape()}: Scramble builds `openapi.json` by reading
     * `rules()` STATICALLY, so a `Rule::in()` computed from the bound connection would degrade the published
     * `event_types` contract to an untyped array — the exact silent regression H16a hit and fixed, which the
     * CI contract gate could not catch because it diffs a fresh export against the committed file and both
     * would be equally wrong. `rules()` keeps its static full-catalog `Rule::in`; this narrows it afterwards.
     *
     * @return Closure(Validator): void
     */
    public static function eventTypeGuard(?ConnectorProviderKey $provider): Closure
    {
        return static function (Validator $validator) use ($provider): void {
            if ($provider === null || ! $provider->isTabular()) {
                return;
            }

            $submitted = $validator->getData()['event_types'] ?? null;

            if (! is_array($submitted)) {
                return;
            }

            $allowed = self::deliverableEventTypes($provider);

            // Reported per INDEX, not as one error on `event_types`: the form renders a checkbox per event,
            // and an error keyed to the group cannot point at the box that caused it.
            foreach (array_values($submitted) as $index => $value) {
                if (is_string($value) && ! in_array($value, $allowed, true)) {
                    $validator->errors()->add(
                        "event_types.{$index}",
                        "A {$provider->containerNoun()} can only receive submission events — a row is a submission’s answers, and the other events have none.",
                    );
                }
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public static function attributesFor(?ConnectorProviderKey $provider): array
    {
        if ($provider !== null && $provider->isTabular()) {
            return [
                'config.spreadsheet_id' => $provider->containerNoun() ?? 'destination',
                'config.sheet_name' => $provider->destinationNoun(),
                'config.sheet_id' => $provider->destinationNoun(),
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
            // ⚠️ THE NOUN IS INTERPOLATED, NOT HARD-CODED (H16c). This block said "spreadsheet" while its only
            // guard was `isTabular()`, which quietly made the two words synonyms — and Airtable is tabular and
            // has no spreadsheets, so every string here would have named the wrong product to the tenant.
            $document = $provider->containerNoun() ?? 'destination';
            $noun = $provider->destinationNoun();

            return [
                'config.spreadsheet_id.required' => "Choose a {$document} to write into.",
                'config.spreadsheet_id.required_with' => "Choose a {$document} to write into.",
                'config.sheet_id.required' => "Choose a {$noun} to write into.",
                'config.sheet_id.required_with' => "Choose a {$noun} to write into.",
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
