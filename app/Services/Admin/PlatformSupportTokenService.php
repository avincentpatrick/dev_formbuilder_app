<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\AuditEvent;
use App\Models\PlatformApiToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Issues, lists and revokes platform support tokens (M175, `R-78f43f26`, `D139`, `D141`; ADR-0023) — the one
 * owner of their rules, so the console and the tests can never mint one that breaks them.
 *
 * ── THE RULES ─────────────────────────────────────────────────────────────────────────────────────────────
 *  · The plaintext is `msp_` + 48 random characters, returned ONCE by {@see self::issue()} and stored only as
 *    its sha256. Nothing can show it again; a lost token is revoked and replaced.
 *  · An expiry is REQUIRED and at most {@see self::MAX_DAYS} days. The token carries no session, so the
 *    console's step-up never re-asks; its lifetime is the only clock on it.
 *  · One ability, {@see PlatformApiToken::ABILITY_FEEDBACK}.
 *  · Issuing and revoking are PLATFORM audit rows (`tenant_id` NULL) through
 *    {@see SuperAdminService::recordPlatformAudit()}, so the elevated connection still opens in one place.
 */
final class PlatformSupportTokenService
{
    /** The choices the console offers; anything else is refused. */
    public const ALLOWED_DAYS = [30, 90];

    public const MAX_DAYS = 90;

    public function __construct(private readonly SuperAdminService $superAdmin) {}

    /**
     * @return array{token: PlatformApiToken, plain: string}
     */
    public function issue(User $owner, string $name, int $days): array
    {
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new InvalidArgumentException('A support token expires within '.self::MAX_DAYS.' days.');
        }

        $plain = PlatformApiToken::PREFIX.Str::random(48);

        $token = new PlatformApiToken;
        $token->forceFill([
            'user_id' => (string) $owner->getKey(),
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'ability' => PlatformApiToken::ABILITY_FEEDBACK,
            'expires_at' => Carbon::now()->addDays($days),
        ])->save();

        $this->superAdmin->recordPlatformAudit(
            AuditEvent::Created,
            'platform_api_tokens',
            (string) $token->getKey(),
            new: [
                'name' => $token->name,
                'ability' => $token->ability,
                'expires_at' => $token->expires_at->toIso8601String(),
            ],
            actorId: (string) $owner->getKey(),
        );

        return ['token' => $token, 'plain' => $plain];
    }

    /** Revoke at once; a second revoke is a no-op rather than a second audit row. */
    public function revoke(PlatformApiToken $token, User $actor): void
    {
        if ($token->revoked_at !== null) {
            return;
        }

        $token->forceFill(['revoked_at' => Carbon::now()])->save();

        $this->superAdmin->recordPlatformAudit(
            AuditEvent::Updated,
            'platform_api_tokens',
            (string) $token->getKey(),
            old: ['revoked_at' => null],
            new: ['revoked_at' => $token->revoked_at?->toIso8601String()],
            actorId: (string) $actor->getKey(),
        );
    }

    /**
     * Every token, newest first, with its owner's name and email and NO secret — the console's list.
     *
     * @return list<array{id: string, name: string, owner: array{name: string, email: string}|null, created_at: string, expires_at: string, last_used_at: string|null, revoked_at: string|null, state: string}>
     */
    public function list(): array
    {
        /** @var Collection<int, PlatformApiToken> $tokens */
        $tokens = PlatformApiToken::query()->orderByDesc('created_at')->get();

        $owners = $this->superAdmin->userNames(array_values(array_unique($tokens->pluck('user_id')->all())));

        return array_values($tokens->map(fn (PlatformApiToken $t): array => [
            'id' => (string) $t->getKey(),
            'name' => $t->name,
            'owner' => $owners[$t->user_id] ?? null,
            'created_at' => $t->created_at->toIso8601String(),
            'expires_at' => $t->expires_at->toIso8601String(),
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'revoked_at' => $t->revoked_at?->toIso8601String(),
            'state' => $t->state(),
        ])->all());
    }
}
