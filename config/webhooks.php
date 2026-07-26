<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Outbound webhook delivery (H13a — technical-architecture.md §7.4,
| webhook-integration-design.md)
|--------------------------------------------------------------------------
|
| Tunables for the webhook engine: the retry ladder, the delivery timeout, the circuit-breaker
| threshold, and the SSRF allow-list. These govern first-party OUTBOUND HTTP — the only such call
| site in the app (DeliverWebhookJob).
|
| >> EVERY NUMBER BELOW IS AN UNVALIDATED PLANNING ASSUMPTION. <<
|
| No webhook traffic has ever existed (webhook-integration-design.md §1 says so explicitly), so the
| ladder, timeout, and breaker threshold are adopted Phase-1 defaults, not empirically validated
| values — revisit once real delivery-failure data exists. They live in config so retuning is an
| operational change, not a code deploy.
|
*/

return [

    /*
    | The retry ladder, in MINUTES from the failed attempt to the next one — the 9 delays between the
    | 10 total attempts (initial + 9 retries), spanning ~7 days: 1m → 5m → 30m → 2h → 6h → 12h → 24h →
    | 48h → 72h. Driven by the `webhook_deliveries.next_retry_at` column and swept by SweepWebhookRetriesJob
    | on `scheduled-maintenance` — deliberately NOT via job $tries/backoff() (webhook-integration-design.md
    | §1): a customer endpoint being down for hours is an expected business condition warranting a ~7-day
    | ladder, whereas a job failing three times is a defect. next_retry_at = now + retry_ladder[attempt_count-1].
    |
    | @var list<int>
    */
    'retry_ladder' => [1, 5, 30, 120, 360, 720, 1440, 2880, 4320],

    /*
    | Total attempts before a delivery is dead-lettered (initial + 9 retries). Matches the ladder length
    | + 1 and the data-dictionary `webhook_deliveries.max_attempts` default of 10. Stamped onto each new
    | delivery row so an in-flight delivery keeps the schedule it was born with.
    */
    'max_attempts' => (int) env('WEBHOOK_MAX_ATTEMPTS', 10),

    /*
    | Per-attempt timeouts, in SECONDS (technical-architecture.md §7.4: "10 seconds, connect + response").
    | A 2xx within `delivery_timeout` counts as success; anything else — including a timeout — is a failure.
    */
    'connect_timeout' => (int) env('WEBHOOK_CONNECT_TIMEOUT', 5),
    'delivery_timeout' => (int) env('WEBHOOK_DELIVERY_TIMEOUT', 10),

    /*
    | The per-endpoint circuit breaker: after this many CONSECUTIVE failures the endpoint auto-pauses
    | (status = 'paused', disabled_reason = 'too_many_failures'); re-enabling is a manual admin action that
    | resets `consecutive_failure_count`. A single 2xx resets the counter. (technical-architecture.md §7.4.)
    */
    'breaker_threshold' => (int) env('WEBHOOK_BREAKER_THRESHOLD', 20),

    /*
    | How many bytes of the receiver's response body to retain in `response_body_excerpt` for the
    | delivery-observability log. Also the cap on the synthetic `[marker]` messages recorded for the
    | SSRF-blocked and quota-exceeded outcomes.
    */
    'response_excerpt_bytes' => (int) env('WEBHOOK_RESPONSE_EXCERPT_BYTES', 2000),

    /*
    | SSRF allow-list (technical-architecture.md §7.4: private/internal ranges are "blocked by default;
    | explicitly allow-listable for enterprise/on-prem integration cases"). Exact lowercase hostnames whose
    | resolved private/internal IPs OutboundUrlGuard will permit anyway. Empty by default — the safe posture.
    |
    | @var list<string>
    */
    'ssrf_allowlist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WEBHOOK_SSRF_ALLOWLIST', ''))
    ))),

];
