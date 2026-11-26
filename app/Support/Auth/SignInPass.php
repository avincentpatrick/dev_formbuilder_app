<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Support\Tenancy\PlatformHost;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The shared sign-in pass — M253 (`R-43038865`, `D236`, ADR-0024 §D5 as amended).
 *
 * iConnect Collection (this app) and iConnect Analytics (BHW Connect, `apps/bhw-connect/`) move to two hosts under
 * `iconnect.com.de`. Analytics has always known who is signed in by forwarding Collection's session cookie to
 * `GET /api/v1/auth/whoami`, and that worked only because both shared one origin. Collection's session cookie stays
 * host-only (`SESSION_DOMAIN=null`, ADR-0009, ADR-0019, runbook §8.2 step 3), because widening it would put every
 * workspace's session, and the console's, on every host. So Collection ALSO sets this: a small cookie for the parent
 * domain that says who, in which workspace, under which live session — and nothing honours it but `whoami`.
 *
 * ⛔ IT IS NOT A SESSION. It cannot open a page, mint a key or call the API; the only route that reads it is
 * `whoami`, through the `signin-pass` guard registered beside `web` (`auth:web,signin-pass`). Its worst case, in
 * another host's hands, is the three fields `whoami` answers with.
 *
 * ⛔ IT DIES WITH THE SESSION. It names the session it was issued under, and is honoured only while that session's
 * row is still in the `sessions` table for that user and inside the session lifetime — so signing out (which deletes
 * the row), an expired session, or a revoked one ends it, whatever the cookie's own expiry says. That check needs the
 * `database` session driver (the box's, `.env.example`'s); under any other driver the pass is refused outright rather
 * than trusted unchecked.
 *
 * Off unless `SIGNIN_PASS_DOMAIN` is set (`config('session.signin_pass_domain')`): nothing is issued or honoured on a
 * laptop, in CI, or on the box before the switch. The value is Laravel-encrypted by `EncryptCookies` like every other
 * cookie, so another host can forward it but neither read nor forge it.
 */
final class SignInPass
{
    public const COOKIE = 'iconnect-signin';

    public const GUARD = 'signin-pass';

    /** The parent domain the pass is set for, or null when the feature is off. */
    public function domain(): ?string
    {
        $domain = config('session.signin_pass_domain');

        return is_string($domain) && trim($domain) !== '' ? trim($domain) : null;
    }

    /** How long a pass lives: the session's own lifetime, since it is worth nothing longer. */
    public function lifetimeMinutes(): int
    {
        return max(1, (int) config('session.lifetime', 120));
    }

    /**
     * The cookie to send with this response, or null when none is owed.
     *
     * Re-issued only when the request carries none, carries one bound to another user, workspace or session (a fresh
     * sign-in regenerates the session id), or carries one past half its life — so an ordinary page sends no
     * Set-Cookie at all.
     */
    public function issueFor(Request $request, string $userId, string $tenantId): ?Cookie
    {
        $domain = $this->domain();

        if ($domain === null || ! $request->hasSession()) {
            return null;
        }

        $sessionId = $request->session()->getId();
        $minutes = $this->lifetimeMinutes();
        $now = time();
        $held = $this->read($request);

        if ($held !== null
            && $held['u'] === $userId
            && $held['t'] === $tenantId
            && $held['s'] === $sessionId
            && $minutes * 30 < $held['x'] - $now) {
            return null;
        }

        try {
            $value = json_encode(['u' => $userId, 't' => $tenantId, 's' => $sessionId, 'x' => $now + $minutes * 60], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return new Cookie(self::COOKIE, $value, $now + $minutes * 60, '/', $domain, true, true, false, Cookie::SAMESITE_LAX);
    }

    /** The cookie that clears the pass at sign-out, or null when the feature is off. */
    public function forget(): ?Cookie
    {
        $domain = $this->domain();

        return $domain === null
            ? null
            : new Cookie(self::COOKIE, '', 1, '/', $domain, true, true, false, Cookie::SAMESITE_LAX);
    }

    /**
     * The user a valid pass names, on a request to the workspace host it was issued for — or null.
     *
     * Read by the `signin-pass` request guard, which `auth` may run before tenancy is initialised (it is ahead of every
     * non-priority middleware), so the workspace is resolved from the HOST here, never from the tenant context.
     */
    public function userFrom(Request $request, ?UserProvider $users): ?Authenticatable
    {
        if ($this->domain() === null || $users === null || config('session.driver') !== 'database') {
            return null;
        }

        $held = $this->read($request);

        if ($held === null || $held['x'] <= time()) {
            return null;
        }

        $tenant = PlatformHost::tenantFor($request->getHost());

        if ($tenant === null || (string) $tenant->getKey() !== $held['t']) {
            return null;
        }

        $connection = config('session.connection');
        $live = DB::connection(is_string($connection) && $connection !== '' ? $connection : null)
            ->table((string) config('session.table', 'sessions'))
            ->where('id', $held['s'])
            ->where('user_id', $held['u'])
            ->where('last_activity', '>=', time() - $this->lifetimeMinutes() * 60)
            ->exists();

        return $live ? $users->retrieveById($held['u']) : null;
    }

    /**
     * The pass this request carries, decrypted by `EncryptCookies` already, in its one valid shape — or null.
     *
     * @return array{u: string, t: string, s: string, x: int}|null
     */
    private function read(Request $request): ?array
    {
        $raw = $request->cookie(self::COOKIE);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($data)
            || ! is_string($data['u'] ?? null) || $data['u'] === ''
            || ! is_string($data['t'] ?? null) || $data['t'] === ''
            || ! is_string($data['s'] ?? null) || $data['s'] === ''
            || ! is_int($data['x'] ?? null)) {
            return null;
        }

        return ['u' => $data['u'], 't' => $data['t'], 's' => $data['s'], 'x' => $data['x']];
    }
}
