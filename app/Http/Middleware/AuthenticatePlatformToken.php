<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\RlsAwareUserProvider;
use App\Models\PlatformApiToken;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens the platform support API (M175, `R-78f43f26`, `D139`, `D141`; ADR-0023) — and nothing else does.
 *
 * ── THE TOKEN IS THE WHOLE AUTHORIZATION, SO EVERY CHECK RUNS ON EVERY CALL ───────────────────────────────
 * The API answers on any platform host, the workspace host included, and carries no session — so neither the
 * console's step-up nor its 2FA prompt ever runs. What stands in for them is here, in order:
 *  1. a bearer token is present and its sha256 matches a row (`platform_api_tokens`; never a Sanctum token,
 *     which lives in another table, and never a browser session — this group has no session middleware);
 *  2. the row is not revoked and not expired, and carries the support ability;
 *  3. its OWNER is still a platform super-admin with two-factor confirmed — re-read now, so demoting the
 *     operator or clearing their 2FA closes the API at once, without anyone remembering to revoke the token.
 * Any failure is an {@see AuthenticationException}: 401 in the API's envelope, with nothing to say which check
 * refused. Then the owner is set as the request's user, and `last_used_at` is stamped.
 *
 * The owner is resolved through the application's user provider ({@see RlsAwareUserProvider}), which
 * reads on the auth connection: `users` is row-level secured, and no tenant context exists on this path.
 */
final class AuthenticatePlatformToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();

        if (! is_string($plain) || ! str_starts_with($plain, PlatformApiToken::PREFIX)) {
            throw new AuthenticationException;
        }

        /** @var PlatformApiToken|null $token */
        $token = PlatformApiToken::query()->where('token_hash', hash('sha256', $plain))->first();

        if ($token === null || ! $token->isUsable() || $token->ability !== PlatformApiToken::ABILITY_FEEDBACK) {
            throw new AuthenticationException;
        }

        $owner = Auth::guard()->getProvider()->retrieveById($token->user_id);

        if (! $owner instanceof User || $owner->is_super_admin !== true || $owner->two_factor_confirmed_at === null) {
            throw new AuthenticationException;
        }

        Auth::guard()->setUser($owner);
        $request->setUserResolver(static fn (): User => $owner);
        $request->attributes->set(PlatformApiToken::class, $token);

        $token->forceFill(['last_used_at' => Carbon::now()])->save();

        return $next($request);
    }
}
