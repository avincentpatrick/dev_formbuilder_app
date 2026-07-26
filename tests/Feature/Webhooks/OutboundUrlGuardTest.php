<?php

declare(strict_types=1);

use App\Exceptions\Webhooks\BlockedWebhookUrlException;
use App\Support\Webhooks\OutboundUrlGuard;

// The SSRF guard (H13a) used at BOTH endpoint-save and every delivery attempt. No DB — but it reads
// config('webhooks.ssrf_allowlist'), so it runs as a Feature test (booted app) rather than a bare Unit test.
// Cases use literal IPs / localhost so no external DNS is needed.

beforeEach(function (): void {
    config()->set('webhooks.ssrf_allowlist', []);
});

it('allows a public IP destination', function (): void {
    (new OutboundUrlGuard)->assertPublic('https://8.8.8.8/hooks/inbound');
})->throwsNoExceptions();

it('blocks private, loopback, and link-local addresses', function (string $url): void {
    expect(fn () => (new OutboundUrlGuard)->assertPublic($url))
        ->toThrow(BlockedWebhookUrlException::class);
})->with([
    'private 10/8' => ['http://10.0.0.1/hook'],
    'private 192.168/16' => ['http://192.168.1.10/hook'],
    'loopback 127/8' => ['http://127.0.0.1/hook'],
    'link-local metadata' => ['http://169.254.169.254/latest/meta-data'],
    'localhost (resolves to 127.0.0.1)' => ['http://localhost/hook'],
]);

it('blocks a non-http(s) scheme', function (): void {
    try {
        (new OutboundUrlGuard)->assertPublic('ftp://8.8.8.8/x');
        $this->fail('expected a BlockedWebhookUrlException');
    } catch (BlockedWebhookUrlException $e) {
        expect($e->reason)->toBe('invalid_scheme');
    }
});

it('blocks a malformed URL with no host', function (): void {
    expect(fn () => (new OutboundUrlGuard)->assertPublic('not a url'))
        ->toThrow(BlockedWebhookUrlException::class);
});

it('tags a private resolution as dns_rebinding_blocked', function (): void {
    try {
        (new OutboundUrlGuard)->assertPublic('http://127.0.0.1/hook');
        $this->fail('expected a BlockedWebhookUrlException');
    } catch (BlockedWebhookUrlException $e) {
        expect($e->reason)->toBe('dns_rebinding_blocked');
    }
});

it('permits an allow-listed host even when it resolves privately', function (): void {
    config()->set('webhooks.ssrf_allowlist', ['localhost']);

    (new OutboundUrlGuard)->assertPublic('http://localhost/on-prem-hook');
})->throwsNoExceptions();
