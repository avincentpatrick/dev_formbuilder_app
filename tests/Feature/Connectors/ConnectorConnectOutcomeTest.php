<?php

declare(strict_types=1);

use App\Support\Connectors\ConnectorConnectOutcome;

/*
|--------------------------------------------------------------------------
| ConnectorConnectOutcome (H15b) — the query-parameter → toast map that closes the OAuth loop. Pure: no DB, no
| container. The property that matters most is the LAST one: the error code arrives from a third-party redirect
| and is attacker-influenceable, so it must only ever be a lookup key, never content.
|--------------------------------------------------------------------------
*/

it('returns null when neither outcome parameter is present', function (): void {
    expect(ConnectorConnectOutcome::toast(null, null, null))->toBeNull()
        ->and(ConnectorConnectOutcome::toast('', 'slack', ''))->toBeNull();
});

it('announces a success with the provider label', function (): void {
    $toast = ConnectorConnectOutcome::toast('slack', null, null);

    expect($toast)->not->toBeNull()
        ->and($toast['type'])->toBe('success')
        ->and($toast['message'])->toContain('Slack connected');
});

it('maps a declined consent screen to non-blaming copy', function (): void {
    $toast = ConnectorConnectOutcome::toast(null, 'slack', 'access_denied');

    expect($toast['type'])->toBe('error')
        ->and($toast['message'])->toBe('You cancelled the authorization — nothing was connected.');
});

it('maps a replayed or expired authorization code to a retry instruction', function (): void {
    foreach (['invalid_code', 'bad_verification_code', 'invalid_grant'] as $code) {
        expect(ConnectorConnectOutcome::toast(null, 'slack', $code)['message'])
            ->toContain('already been used or had expired');
    }
});

it('points a credential rejection at the administrator, not the tenant', function (): void {
    expect(ConnectorConnectOutcome::toast(null, 'slack', 'invalid_client_id')['message'])
        ->toContain('Contact your administrator');
});

it('never echoes an unrecognised provider error code', function (): void {
    // The callback forwards the provider's own `error` verbatim, and anyone with the callback URL can put a
    // string there — so an unknown code must fall through to our own words, not become them.
    $hostile = '<script>alert(1)</script>';

    $toast = ConnectorConnectOutcome::toast(null, 'slack', $hostile);

    expect($toast['type'])->toBe('error')
        ->and($toast['message'])->not->toContain('script')
        ->and($toast['message'])->toBe('Slack couldn’t be connected. Please try again, or contact support if this keeps happening.');
});

it('degrades an unknown provider to a neutral noun instead of echoing it', function (): void {
    $toast = ConnectorConnectOutcome::toast(null, 'evilcorp', 'access_denied');

    expect($toast['message'])->not->toContain('evilcorp');

    // ...including in the branch that names the provider.
    expect(ConnectorConnectOutcome::toast(null, 'evilcorp', 'missing_code')['message'])
        ->toBe('The integration didn’t send an authorization code. Please try connecting again.');
});

it('names the provider in a success even when the provider parameter is absent', function (): void {
    // Success carries the provider in `connected`, not `provider` — the redirector builds the two differently.
    expect(ConnectorConnectOutcome::toast('slack', null, null)['message'])->toStartWith('Slack connected');
});

it('lets an error win when a crafted link carries both parameters', function (): void {
    // The real callback emits one or the other, but the return URL is just a link. Announcing "connected" for
    // a grant that does not exist is the failure worth engineering against, so the error branch goes first.
    $toast = ConnectorConnectOutcome::toast('slack', 'slack', 'access_denied');

    expect($toast['type'])->toBe('error')
        ->and($toast['message'])->not->toContain('connected. Add a delivery rule');

    // ...and it still names the provider when only `connected` carried it.
    expect(ConnectorConnectOutcome::toast('slack', null, 'missing_code')['message'])
        ->toStartWith('Slack didn’t send an authorization code');
});
