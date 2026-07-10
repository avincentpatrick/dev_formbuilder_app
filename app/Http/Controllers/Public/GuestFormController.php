<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Support\Guest\GuestShareTokenService;
use Illuminate\Http\JsonResponse;

/**
 * The guest runtime entry point (Increment F5). Served on the tenant subdomain WITHOUT `auth`: the form is
 * resolved by its per-tenant `public_slug` (RLS-scoped to the subdomain tenant, which is why this endpoint
 * — unlike the token-consuming /api/v1/public endpoints — needs the subdomain), and a stateless, expiring
 * share token is minted for the live published version.
 *
 * All three failure gates return 404 (never 403), so an attacker probing slugs cannot distinguish "no such
 * form" from "guest access disabled" from "not published yet". Serving the guest SPA shell at this URL is
 * Increment F6; here we return the minted token as JSON for the SPA to carry to the schema/submit endpoints.
 */
final class GuestFormController extends Controller
{
    public function mint(string $slug, GuestShareTokenService $tokens): JsonResponse
    {
        $form = Form::query()->where('public_slug', $slug)->first();

        abort_if($form === null, 404);
        abort_unless($form->allow_guest_submissions, 404);
        abort_if($form->current_published_version_id === null, 404);

        $minted = $tokens->mint($form->tenant_id, $form->id, $form->current_published_version_id);

        return response()->json([
            'shareToken' => $minted->token,
            'expiresAt' => gmdate('c', $minted->expiresAt),
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
            ],
        ]);
    }
}
