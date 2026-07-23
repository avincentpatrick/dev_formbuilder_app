<?php

declare(strict_types=1);

use App\Exceptions\Guest\ExpiredShareTokenException;
use App\Exceptions\Guest\InvalidShareTokenException;
use App\Support\Guest\GuestShareTokenService;
use Ramsey\Uuid\Uuid;

/*
|--------------------------------------------------------------------------
| Increment F5 — the stateless HMAC guest share token, unit-level.
|--------------------------------------------------------------------------
| Pure: no container, no Postgres. Proves the security contract the tenant-from-token middleware depends
| on — round-trip fidelity, constant-time signature rejection (tamper + wrong key), expiry, and structural
| hardening — so the Feature tests can trust that a returned GuestShareToken is authentic and unexpired.
*/

const F5_TTL = 3600;

const H9B_RESUME_TTL = 7200;

function shareTokens(string $key = 'unit-test-key', string $resumeKey = 'unit-test-resume-key'): GuestShareTokenService
{
    return new GuestShareTokenService($key, F5_TTL, $resumeKey, H9B_RESUME_TTL);
}

/**
 * Flip the FIRST character of one dot-segment to a different valid base64url char.
 *
 * The first character, not the last: base64url encodes 6 bits per character, and a 32-byte HMAC does not
 * divide evenly (256 / 6 = 42.7), so the trailing character carries only 4 significant bits plus 2 of
 * padding. Several distinct trailing characters therefore decode to the SAME raw bytes — flipping it can
 * leave the signature unchanged, the tamper slips through, and the test fails intermittently. That is the
 * flake this helper used to produce, despite calling itself deterministic. The first character always
 * carries 6 significant bits, so changing it always changes the decoded value.
 *
 * Same reasoning, same fix as GuestRuntimeTest's "401s a tampered token".
 */
function tamperSegment(string $token, int $index): string
{
    $parts = explode('.', $token);
    $segment = $parts[$index];
    $parts[$index] = ($segment[0] === 'A' ? 'B' : 'A').substr($segment, 1);

    return implode('.', $parts);
}

it('round-trips the pinned tenant, form, version and expiry', function (): void {
    $service = shareTokens();
    $tid = Uuid::uuid7()->toString();
    $fid = Uuid::uuid7()->toString();
    $vid = Uuid::uuid7()->toString();

    $minted = $service->mint($tid, $fid, $vid, now: 1_000);
    expect($minted->expiresAt)->toBe(1_000 + F5_TTL);

    $token = $service->verify($minted->token, now: 1_000);
    expect($token->tenantId)->toBe($tid)
        ->and($token->formId)->toBe($fid)
        ->and($token->formVersionId)->toBe($vid)
        ->and($token->expiresAt)->toBe(1_000 + F5_TTL)
        ->and($token->rawToken)->toBe($minted->token);
});

it('accepts a token right up to its expiry and rejects it one second later', function (): void {
    $service = shareTokens();
    $minted = $service->mint(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), now: 1_000);

    // The final valid instant.
    expect($service->verify($minted->token, now: 1_000 + F5_TTL))->not->toBeNull();

    // One second past → expired.
    expect(fn () => $service->verify($minted->token, now: 1_000 + F5_TTL + 1))
        ->toThrow(ExpiredShareTokenException::class);
});

it('rejects a tampered payload segment (signature no longer matches)', function (): void {
    $service = shareTokens();
    $minted = $service->mint(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), now: 1_000);

    expect(fn () => $service->verify(tamperSegment($minted->token, 1), now: 1_000))
        ->toThrow(InvalidShareTokenException::class);
});

it('rejects a tampered signature segment', function (): void {
    $service = shareTokens();
    $minted = $service->mint(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), now: 1_000);

    expect(fn () => $service->verify(tamperSegment($minted->token, 2), now: 1_000))
        ->toThrow(InvalidShareTokenException::class);
});

it('rejects a token signed with a different key', function (): void {
    $minted = shareTokens('key-a')->mint(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), now: 1_000);

    expect(fn () => shareTokens('key-b')->verify($minted->token, now: 1_000))
        ->toThrow(InvalidShareTokenException::class);
});

it('rejects structurally malformed tokens', function (string $bad): void {
    expect(fn () => shareTokens()->verify($bad, now: 1_000))->toThrow(InvalidShareTokenException::class);
})->with([
    'no dots' => 'not-a-token',
    'too few segments' => 'v1.onlyone',
    'too many segments' => 'v1.a.b.c',
    'wrong version prefix' => 'v2.eyJ0aWQiOiJ4In0.sig',
    'empty' => '',
]);

it('rejects an authentically-signed token carrying a non-uuid tenant id', function (): void {
    // A validly-signed token whose payload is structurally wrong (mint does not constrain the ids) must still
    // be refused by verify()'s uuid guard — the defensive branch behind the signature check.
    $minted = shareTokens()->mint('not-a-uuid', Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), now: 1_000);

    expect(fn () => shareTokens()->verify($minted->token, now: 1_000))
        ->toThrow(InvalidShareTokenException::class);
});

it('produces a stable 64-hex fingerprint', function (): void {
    $service = shareTokens();
    $minted = $service->mint(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), now: 1_000);

    $fingerprint = $service->fingerprint($minted->token);
    expect($fingerprint)->toMatch('/^[0-9a-f]{64}$/')
        ->and($service->fingerprint($minted->token))->toBe($fingerprint);
});

/*
|--------------------------------------------------------------------------
| Increment H9b — the resume token (the share-token variant scoped to a draft submissions.id).
|--------------------------------------------------------------------------
*/

it('round-trips a resume token carrying the draft submission id and the resume TTL', function (): void {
    $service = shareTokens();
    $tid = Uuid::uuid7()->toString();
    $fid = Uuid::uuid7()->toString();
    $vid = Uuid::uuid7()->toString();
    $sid = Uuid::uuid7()->toString();

    $minted = $service->mintResume($tid, $fid, $vid, $sid, now: 1_000);
    expect($minted->expiresAt)->toBe(1_000 + H9B_RESUME_TTL); // the LONGER resume TTL, not the share TTL

    $token = $service->verifyResume($minted->token, now: 1_000);
    expect($token->tenantId)->toBe($tid)
        ->and($token->formId)->toBe($fid)
        ->and($token->formVersionId)->toBe($vid)
        ->and($token->submissionId)->toBe($sid)
        ->and($token->expiresAt)->toBe(1_000 + H9B_RESUME_TTL);
});

it('will not accept a share token as a resume token (key + claim separation)', function (): void {
    $service = shareTokens();
    $shareToken = $service->mint(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), now: 1_000);

    // A share token is signed with the share key and carries no `sid`/`typ=resume` — it must never verify as one.
    expect(fn () => $service->verifyResume($shareToken->token, now: 1_000))
        ->toThrow(InvalidShareTokenException::class);
});

it('will not accept a resume token on the share verify path', function (): void {
    $service = shareTokens();
    $resume = $service->mintResume(
        Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), now: 1_000,
    );

    expect(fn () => $service->verify($resume->token, now: 1_000))
        ->toThrow(InvalidShareTokenException::class);
});

it('expires a resume token one second past its (longer) TTL', function (): void {
    $service = shareTokens();
    $minted = $service->mintResume(
        Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), now: 1_000,
    );

    expect($service->verifyResume($minted->token, now: 1_000 + H9B_RESUME_TTL))->not->toBeNull();
    expect(fn () => $service->verifyResume($minted->token, now: 1_000 + H9B_RESUME_TTL + 1))
        ->toThrow(ExpiredShareTokenException::class);
});

it('rejects a resume token signed with a different resume key', function (): void {
    $minted = shareTokens('shared', 'resume-a')->mintResume(
        Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), now: 1_000,
    );

    expect(fn () => shareTokens('shared', 'resume-b')->verifyResume($minted->token, now: 1_000))
        ->toThrow(InvalidShareTokenException::class);
});
