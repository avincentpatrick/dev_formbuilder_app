<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\TenantUserStatus;
use App\Http\Controllers\Controller;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who is signed in (M207, ADR-0024 §D5, `D178`) — the one contract between Meridian and BHW Connect, which
 * shares this origin at `/bhw-connect`. BHW's server forwards the browser's `meridian-session` cookie here and
 * reads the answer; BHW's own `admin_users` then decides whether that person administers BHW, so nothing
 * about BHW enters Meridian's permission tables.
 *
 * It sits in Group A, so only a session reaches it — a bearer token cannot — and it inherits that group's
 * `verified` and tenant-2FA gates: an unverified or un-enrolled member is refused here exactly as at the mint.
 * The answer is the three fields the shell already shares with every page (`auth.user`), never more.
 *
 * ⚠️ NOTHING IN GROUP A ASKS WHETHER THE ACCOUNT BELONGS TO THIS WORKSPACE — users are global, and RLS is what
 * keeps a non-member's reads empty. That is enough for the mint (a non-member's key trims to nothing) and not
 * enough here: a removed member's session that is still open would keep answering "signed in", and BHW would
 * keep honouring an `admin_users` row for them. So this asks the one question itself.
 */
final class WhoamiController extends Controller
{
    /**
     * The signed-in member's id, name and email. A guest gets the 401 envelope from the group's `auth`; an
     * account that is not an active member of this workspace gets the 403 one.
     *
     * @throws AuthorizationException
     */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $isActiveMember = TenantUser::query()
            ->where('user_id', $user->getKey())
            ->where('status', TenantUserStatus::Active)
            ->exists();

        // An AuthorizationException, not abort(403): the API's `forbidden` envelope is rendered for the
        // AccessDeniedHttpException the handler converts it to, and a bare 403 falls through to `request_failed`.
        throw_unless($isActiveMember, AuthorizationException::class);

        return response()
            ->json(['data' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ]])
            // The answer is one person's: no cache may keep it. (A `Vary: Cookie` would say the same to a
            // shared cache, but the `web` group's Inertia middleware replaces Vary on the way out — measured.)
            ->header('Cache-Control', 'no-store, private');
    }
}
