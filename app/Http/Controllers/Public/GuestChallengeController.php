<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\ReadsGuestShareToken;
use App\Support\Guest\GuestChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issues a proof-of-work challenge for a guest form (Increment I8b).
 *
 * ⚠️ POST, NOT GET, AND THAT IS NOT A REST QUIBBLE. `resources/public-runtime/sw.ts` registers a
 * `NetworkFirst` strategy for **GET** requests whose path starts with `/api/v1/public/f/` — so a GET
 * challenge would be cached by the service worker and re-served, and the replay guard would then reject
 * every reuse. The failure would appear only after the SW activated, on a second visit, which is the
 * worst kind of bug to own. A POST is never matched by that route, needs no `sw.ts` edit, and is honest
 * anyway: issuing a challenge burns a slot in the replay-guard namespace, so it is not nullipotent.
 *
 * Bound to the FORM the share token names — not to the token itself. `replay.ts` re-mints the share token
 * immediately before every outbox POST, so a token binding would reject exactly the offline replays this
 * product exists to support. See {@see GuestChallengeService}.
 *
 * Issued unconditionally, even for a form with `bot_challenge = off`: the client asks before it knows, a
 * challenge nobody verifies is harmless, and branching here would leak each form's configuration to an
 * unauthenticated caller for no gain.
 */
final class GuestChallengeController extends Controller
{
    use ReadsGuestShareToken;

    /**
     * Mint a spam-check challenge for this share link.
     *
     * @unauthenticated
     */
    public function __invoke(Request $request, GuestChallengeService $challenges): JsonResponse
    {
        $token = $this->shareToken($request);

        return response()->json(['data' => $challenges->mint($token->formId)]);
    }
}
