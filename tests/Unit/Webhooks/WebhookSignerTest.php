<?php

declare(strict_types=1);

use App\Support\Webhooks\WebhookSigner;

// The HMAC-SHA256 webhook signature (H13a) is over "{timestamp}.{raw_body}" (Stripe convention), so a
// captured delivery cannot be replayed with a different timestamp. Pure unit — no app/DB.

it('signs over "{timestamp}.{body}" with the endpoint secret (hex HMAC-SHA256)', function (): void {
    $signer = new WebhookSigner;
    $secret = 'whsec_test';
    $ts = '2026-07-26T10:00:00+00:00';
    $body = '{"event_id":"abc"}';

    $expected = hash_hmac('sha256', $ts.'.'.$body, $secret);

    expect($signer->sign($secret, $ts, $body))->toBe($expected)
        ->and($signer->signatureHeader($secret, $ts, $body))->toBe('sha256='.$expected);
});

it('changes the signature when the timestamp changes (replay is not reusable)', function (): void {
    $signer = new WebhookSigner;
    $body = '{"a":1}';

    $one = $signer->sign('s', '2026-07-26T10:00:00+00:00', $body);
    $two = $signer->sign('s', '2026-07-26T10:05:00+00:00', $body);

    expect($one)->not->toBe($two);
});

it('changes the signature when the secret changes', function (): void {
    $signer = new WebhookSigner;
    $ts = '2026-07-26T10:00:00+00:00';
    $body = '{"a":1}';

    expect($signer->sign('secret-a', $ts, $body))->not->toBe($signer->sign('secret-b', $ts, $body));
});
