<?php

declare(strict_types=1);

use App\Support\Connectors\Providers\SlackConnector;

/*
|--------------------------------------------------------------------------
| Native OAuth connectors (H15a — ADR-0009, webhook-integration-design.md §4)
|--------------------------------------------------------------------------
|
| The connector framework holds an OAuth grant on a tenant's third-party workspace and pushes domain
| events into it. This is the SECOND first-party outbound-HTTP surface after the webhook engine, and
| the delivery half deliberately reuses `config/webhooks.php`'s retry ladder, attempt cap, timeouts and
| breaker threshold rather than forking a second set of numbers: a connector delivery and a webhook
| delivery share one ledger table, so they should share one failure policy.
|
| >> THE TIMING NUMBERS BELOW ARE UNVALIDATED PLANNING ASSUMPTIONS. <<
|
| No connector traffic has ever existed. The state TTL and the refresh lead time are adopted defaults
| chosen for the shape of the flow (an interactive consent screen; an hourly sweep), not measured
| values — they live in config so retuning is an operational change, not a deploy.
|
| App credentials (client id/secret) are PLATFORM-WIDE and live only in the environment (ADR-0009 §D9),
| never in the database and never per-tenant. They are deliberately NOT read from `config/services.php`'s
| stock `slack` block, which is Laravel's unrelated log-notification channel.
|
*/

return [

    /*
    | provider key => adapter + credentials + scopes. A provider with no entry here (or an unbuildable
    | adapter) 404s every connector route for that key rather than half-working — the registry treats
    | configuration as the enable switch, so an outage or a revoked app registration is an ops change.
    |
    | Slack scopes are least-privilege (ADR-0009 §D8): post a message to a chosen channel, and list
    | channels so the H15b picker can offer them. No history, no user, no file scopes.
    */
    'providers' => [
        'slack' => [
            'adapter' => SlackConnector::class,
            'client_id' => env('SLACK_CONNECTOR_CLIENT_ID'),
            'client_secret' => env('SLACK_CONNECTOR_CLIENT_SECRET'),
            'scopes' => ['chat:write', 'channels:read'],
        ],
    ],

    /*
    | The OAuth `state` parameter (ADR-0009 §D3) — the only carrier of tenant + user identity across the
    | host boundary to the central-domain callback, since the session cookie is host-only.
    |
    | `ttl` is SECONDS and bounds an interactive consent screen, not a shareable link: long enough for a
    | human to read a permissions dialog and pick a workspace, short enough that a leaked callback URL is
    | worthless within minutes. Left null, `key` is derived from APP_KEY with domain separation (the
    | guest share/resume-token convention) so the three token families can never validate as each other;
    | set it explicitly to rotate connector state tokens independently of APP_KEY.
    */
    'state' => [
        'ttl' => (int) env('CONNECTOR_STATE_TTL', 600),
        'key' => env('CONNECTOR_STATE_KEY'),
    ],

    /*
    | How far ahead of `connections.token_expires_at`, in SECONDS, the refresh sweep renews a grant. Must
    | comfortably exceed the sweep interval (hourly) so a token cannot expire between two sweeps: at 7200
    | a grant is refreshed up to two hours early, giving one full retry cycle before anything breaks.
    | A grant with no expiry (Slack's default bot token) or no refresh token is skipped entirely.
    */
    'refresh_lead_seconds' => (int) env('CONNECTOR_REFRESH_LEAD_SECONDS', 7200),

    /*
    | Where the browser lands after the central-domain callback finishes (ADR-0009 §D5). The HOST is
    | always read from the tenant's own `domains` row — never echoed from the request or the state
    | payload — and only this PATH is configurable. The outcome rides `?connected=` / `?connect_error=`
    | because the central host cannot write the tenant host's session cookie, so there is no flash.
    */
    'return_path' => env('CONNECTOR_RETURN_PATH', '/integrations'),

    /*
    | Scheme for the tenant-facing URLs this framework builds (the post-callback return and the deep link
    | in a delivered message). Mirrors the guest resume-URL convention; `http` locally.
    */
    'tenant_url_scheme' => env('CONNECTOR_TENANT_URL_SCHEME', 'https'),

];
