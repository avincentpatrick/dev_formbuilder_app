<?php

declare(strict_types=1);

use App\Support\Connectors\Providers\GoogleSheetsConnector;
use App\Support\Connectors\Providers\SlackChannelLister;
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
    |
    | `channel_lister` is the OPTIONAL H15b destination-picker capability. It is a separate key from `adapter`
    | because not every provider has one and the concept is not shared: H16's Google Sheets enumerates
    | spreadsheets, not channels. Absent (or unbuildable) means "this provider offers no picker" — the rule
    | modal falls back to a manual destination id, and nothing 404s, because the CONNECTION is still valid.
    */
    'providers' => [
        'slack' => [
            'adapter' => SlackConnector::class,
            'channel_lister' => SlackChannelLister::class,
            'client_id' => env('SLACK_CONNECTOR_CLIENT_ID'),
            'client_secret' => env('SLACK_CONNECTOR_CLIENT_SECRET'),
            'scopes' => ['chat:write', 'channels:read'],
        ],

        /*
        | Google Sheets (H16a — webhook-integration-design.md §4 row 4). The second provider, and the first
        | that READS from the third party: drift detection has to see the destination's header row before it
        | may append to it, which fires ADR-0009 §D8's own revisit trigger ("any feature that wants to read
        | from the provider (Sheets' drift detection, H16a) — that is a genuine scope expansion").
        |
        | THE SCOPE SET IS `drive.file` ALONE, and that is narrower than the plan of record proposed. Google's
        | own scope table (verified 2026-08-05 at developers.google.com/workspace/sheets/api/scopes) classifies
        | `drive.file` as **Recommended, Non-sensitive** for the Sheets API and `spreadsheets` as **Sensitive**
        | — so adding `spreadsheets` would grant "see, edit, create and delete ALL your spreadsheets" AND move
        | this app into Google's sensitive-scope verification tier, in exchange for nothing `drive.file` does
        | not already permit on a file the tenant picked or we created. `drive.readonly` (Restricted) was
        | refused outright. The consequence is deliberate and is H16b's problem, not a gap here: with per-file
        | access we cannot ENUMERATE a tenant's existing spreadsheets server-side, so there is no
        | `channel_lister` entry — the destination is created by us or named by id, and the registry's
        | null-not-throw contract for a missing lister covers exactly this case.
        |
        | `auth_params` are the two the offline flow cannot work without, kept in config rather than in the
        | adapter because they are policy, not protocol: `access_type=offline` is what makes Google issue a
        | refresh token AT ALL (without it there is no refresh path and every grant dies in an hour), and
        | `prompt=consent` forces re-issue on a re-connect, because Google returns a refresh token only on the
        | FIRST authorization for a given user+client and a tenant re-connecting after a revoke would otherwise
        | receive an access token with no refresh token and silently become a one-hour connection.
        */
        'google_sheets' => [
            'adapter' => GoogleSheetsConnector::class,
            'client_id' => env('GOOGLE_CONNECTOR_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CONNECTOR_CLIENT_SECRET'),
            'scopes' => ['https://www.googleapis.com/auth/drive.file'],
            'auth_params' => ['access_type' => 'offline', 'prompt' => 'consent'],
        ],
    ],

    /*
    | How many pages of `conversations.list` the picker will read before it stops and reports the list as
    | partial. At Slack's 200-per-page maximum this is 1,000 channels, which is far past the point where a
    | native <select> stops being the right control — the cap exists so one enormous workspace cannot turn a
    | modal open into a dozen sequential round trips, not because 1,000 is a meaningful number.
    |
    | >> ALSO AN UNVALIDATED PLANNING ASSUMPTION. <<
    */
    'channel_page_limit' => (int) env('CONNECTOR_CHANNEL_PAGE_LIMIT', 5),

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
    | H16a — the DELIVERY-TIME pre-flight lead, in SECONDS, and a deliberate amendment to ADR-0009 §D6's
    | "proactive, never lazy" rule. §D6 named its own revisit trigger: "a provider whose tokens expire faster
    | than the sweep interval, which would force a lazy pre-flight refresh after all." Google is that provider
    | — its access tokens live about an hour against an HOURLY sweep, so a grant minted just after one sweep
    | and delivered against before the next can be dead on arrival, and no amount of retuning the sweep closes
    | a window that a single missed run reopens.
    |
    | This is MUCH smaller than the sweep's lead on purpose. It is a last-resort guard for a token that is
    | already expired or about to be, not a second refresh policy: at 120s a healthy Google connection is
    | still renewed by the sweep and never touches this path, so the stampede §D6 warns about (every queued
    | delivery refreshing at once during a provider outage) needs the sweep to have failed first.
    |
    | >> ALSO AN UNVALIDATED PLANNING ASSUMPTION. <<
    */
    'delivery_refresh_lead_seconds' => (int) env('CONNECTOR_DELIVERY_REFRESH_LEAD_SECONDS', 120),

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
