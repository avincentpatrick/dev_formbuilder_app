<?php

declare(strict_types=1);

use App\Enums\ConnectorProviderKey;
use App\Exceptions\Connectors\InvalidConnectorStateException;
use App\Support\Connectors\ConnectorOAuthStateService;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| ConnectorOAuthStateService (H15a / ADR-0009 §D3) — the signed `state` that carries tenant + user identity
| across the host boundary to the central-domain callback. No database: this is pure token arithmetic, and
| the whole point of the design is that it verifies BEFORE anything touches RLS.
*/

function stateService(string $key = 'unit-test-state-key', int $ttl = 600): ConnectorOAuthStateService
{
    return new ConnectorOAuthStateService($key, $ttl);
}

it('round-trips the tenant, user and provider it was minted for', function (): void {
    $service = stateService();
    $tenantId = (string) Str::uuid();
    $userId = (string) Str::uuid();

    $state = $service->mint($tenantId, $userId, ConnectorProviderKey::Slack);
    $verified = $service->verify($state, ConnectorProviderKey::Slack);

    expect($verified->tenantId)->toBe($tenantId)
        ->and($verified->userId)->toBe($userId)
        ->and($verified->provider)->toBe(ConnectorProviderKey::Slack);
});

it('mints a distinct token for each flow even with identical claims', function (): void {
    $service = stateService();
    $tenantId = (string) Str::uuid();
    $userId = (string) Str::uuid();

    // The nonce is what makes two flows started in the same second different tokens.
    expect($service->mint($tenantId, $userId, ConnectorProviderKey::Slack))
        ->not->toBe($service->mint($tenantId, $userId, ConnectorProviderKey::Slack));
});

it('rejects a tampered payload', function (): void {
    $service = stateService();
    $state = $service->mint((string) Str::uuid(), (string) Str::uuid(), ConnectorProviderKey::Slack);

    // Swap the payload segment for one naming a different tenant, keeping the original signature.
    [$version, , $signature] = explode('.', $state);
    $forgedPayload = rtrim(strtr(base64_encode((string) json_encode([
        'tid' => (string) Str::uuid(),
        'uid' => (string) Str::uuid(),
        'prov' => ConnectorProviderKey::Slack->value,
        'nonce' => (string) Str::uuid(),
        'exp' => time() + 600,
    ])), '+/', '-_'), '=');

    expect(fn () => $service->verify("{$version}.{$forgedPayload}.{$signature}", ConnectorProviderKey::Slack))
        ->toThrow(InvalidConnectorStateException::class);
});

it('rejects a state signed with a different key', function (): void {
    $state = stateService('someone-elses-key')->mint((string) Str::uuid(), (string) Str::uuid(), ConnectorProviderKey::Slack);

    expect(fn () => stateService()->verify($state, ConnectorProviderKey::Slack))
        ->toThrow(InvalidConnectorStateException::class);
});

it('rejects an expired state', function (): void {
    $service = stateService(ttl: 600);
    $mintedAt = time() - 3600;

    $state = $service->mint((string) Str::uuid(), (string) Str::uuid(), ConnectorProviderKey::Slack, $mintedAt);

    // Authentic signature, but `exp` is an hour in the past.
    expect(fn () => $service->verify($state, ConnectorProviderKey::Slack))
        ->toThrow(InvalidConnectorStateException::class);

    // ...and it verified fine while it was live, so the refusal is the expiry and nothing else.
    expect($service->verify($state, ConnectorProviderKey::Slack, $mintedAt + 60)->provider)
        ->toBe(ConnectorProviderKey::Slack);
});

it('rejects a malformed state', function (string $malformed): void {
    expect(fn () => stateService()->verify($malformed, ConnectorProviderKey::Slack))
        ->toThrow(InvalidConnectorStateException::class);
})->with([
    'empty' => [''],
    'not three segments' => ['v1.abc'],
    'wrong version' => ['v2.abc.def'],
    'unsigned garbage' => ['v1.!!!.!!!'],
]);

it('rejects a state whose claims are structurally wrong', function (): void {
    $service = stateService();

    // Signed by us, but `tid` is not a uuid — the claim-shape check runs after the MAC, not instead of it.
    $payload = rtrim(strtr(base64_encode((string) json_encode([
        'tid' => 'not-a-uuid',
        'uid' => (string) Str::uuid(),
        'prov' => ConnectorProviderKey::Slack->value,
        'nonce' => (string) Str::uuid(),
        'exp' => time() + 600,
    ])), '+/', '-_'), '=');
    $body = 'v1.'.$payload;
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, 'unit-test-state-key', true)), '+/', '-_'), '=');

    expect(fn () => $service->verify($body.'.'.$signature, ConnectorProviderKey::Slack))
        ->toThrow(InvalidConnectorStateException::class);
});

it('rejects a state minted for another provider', function (): void {
    // The provider binding is what stops a state minted for one flow being replayed at another provider's
    // callback. Forged directly because the enum has a single case today — the check must survive the second.
    $service = stateService();
    $payload = rtrim(strtr(base64_encode((string) json_encode([
        'tid' => (string) Str::uuid(),
        'uid' => (string) Str::uuid(),
        'prov' => 'google_sheets',
        'nonce' => (string) Str::uuid(),
        'exp' => time() + 600,
    ])), '+/', '-_'), '=');
    $body = 'v1.'.$payload;
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, 'unit-test-state-key', true)), '+/', '-_'), '=');

    expect(fn () => $service->verify($body.'.'.$signature, ConnectorProviderKey::Slack))
        ->toThrow(InvalidConnectorStateException::class);
});

it('derives its key with domain separation from APP_KEY by default', function (): void {
    // The container binding must not reuse a guest-token key: a share, resume or state token validating as
    // another would break the separation ADR-0009 §D3 relies on.
    config()->set('connectors.state.key', null);

    $service = app(ConnectorOAuthStateService::class);
    $state = $service->mint((string) Str::uuid(), (string) Str::uuid(), ConnectorProviderKey::Slack);

    $expectedKey = hash_hmac('sha256', 'connector-oauth-state.v1', (string) config('app.key'));

    expect($service->verify($state, ConnectorProviderKey::Slack)->provider)->toBe(ConnectorProviderKey::Slack)
        ->and(fn () => stateService($expectedKey)->verify($state, ConnectorProviderKey::Slack))->not->toThrow(InvalidConnectorStateException::class);
});

/*
|--------------------------------------------------------------------------
| The PKCE verifier (H16c / ADR-0009 §D3 as extended). Derived from the state rather than stored, because the
| authorize and the exchange happen in different requests on different hosts and there is nowhere to remember
| it. These cases pin the three properties Airtable's token endpoint actually depends on.
*/

it('derives the same code verifier for a given state every time', function (): void {
    // The whole design rests on this: the authorize half and the exchange half are separate REQUESTS, and the
    // only thing they share is the state string. If the derivation were not a pure function of it, the
    // challenge published at the consent screen and the verifier sent at the exchange would not match, and
    // Airtable would refuse with an error indistinguishable from a replayed code.
    $service = stateService();
    $state = $service->mint((string) Str::uuid(), (string) Str::uuid(), ConnectorProviderKey::Airtable);

    expect($service->codeVerifierFor($state))->toBe($service->codeVerifierFor($state))
        // Across INSTANCES too, not just calls — the two halves resolve the service independently.
        ->and(stateService()->codeVerifierFor($state))->toBe($service->codeVerifierFor($state));
});

it('derives a different code verifier for every flow', function (): void {
    $service = stateService();
    $tenantId = (string) Str::uuid();
    $userId = (string) Str::uuid();

    $first = $service->mint($tenantId, $userId, ConnectorProviderKey::Airtable);
    $second = $service->mint($tenantId, $userId, ConnectorProviderKey::Airtable);

    // Two flows for the SAME tenant, user and provider — the nonce is what makes the states differ, and the
    // verifier has to inherit that. A verifier shared across a tenant's flows would let a code intercepted
    // from one be redeemed against another, which is the whole thing PKCE is for.
    expect($service->codeVerifierFor($first))->not->toBe($service->codeVerifierFor($second));
});

it('derives a code verifier under the signing key, not from the state alone', function (): void {
    // The state travels in a URL and is visible to anyone who sees the redirect. What makes the verifier a
    // secret is the KEY, so two deployments must not derive the same verifier from the same state.
    $state = stateService()->mint((string) Str::uuid(), (string) Str::uuid(), ConnectorProviderKey::Airtable);

    expect(stateService('another-deployment-key')->codeVerifierFor($state))
        ->not->toBe(stateService()->codeVerifierFor($state));
});

it('derives a code verifier RFC 7636 accepts', function (): void {
    $service = stateService();
    $verifier = $service->codeVerifierFor(
        $service->mint((string) Str::uuid(), (string) Str::uuid(), ConnectorProviderKey::Airtable)
    );

    // §4.1: 43–128 characters from the unreserved set. Airtable enforces both bounds, and a verifier that is
    // one character short fails at the token endpoint with an opaque error — after the tenant has already
    // consented, which is the most expensive place to discover a formatting bug.
    expect(mb_strlen($verifier))->toBe(43)
        ->and($verifier)->toMatch('/^[A-Za-z0-9\-._~]+$/');
});

it('separates the verifier derivation from the state signature under one key', function (): void {
    // Both hang off the same secret, so the prefix is what stops the service answering two questions with one
    // computation. Asserted against the signature bytes of the same token rather than "they differ", which
    // would pass for the wrong reason if the prefix were dropped and the inputs merely happened to differ.
    $service = stateService();
    $state = $service->mint((string) Str::uuid(), (string) Str::uuid(), ConnectorProviderKey::Airtable);

    [$version, $payload] = explode('.', $state);
    $signature = rtrim(strtr(base64_encode(
        hash_hmac('sha256', $version.'.'.$payload, 'unit-test-state-key', true)
    ), '+/', '-_'), '=');

    expect($service->codeVerifierFor($state))->not->toBe($signature);
});
