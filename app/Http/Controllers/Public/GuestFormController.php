<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Exceptions\Guest\ExpiredShareTokenException;
use App\Exceptions\Guest\InvalidShareTokenException;
use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Support\Guest\GuestShareTokenService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The guest runtime entry point (Increment F5 → F6b). Served on the tenant subdomain WITHOUT `auth`: the
 * form is resolved by its per-tenant `public_slug` (RLS-scoped to the subdomain tenant, which is why this
 * endpoint — unlike the token-consuming /api/v1/public endpoints — needs the subdomain), and a stateless,
 * expiring share token is minted for the live published version.
 *
 * All three failure gates return 404 (never 403), so an attacker probing slugs cannot distinguish "no such
 * form" from "guest access disabled" from "not published yet".
 *
 * Content negotiation (F6b): `Accept: application/json` (the SPA's re-mint fetch + the F5 Feature tests)
 * gets the minted token as JSON; a browser navigation gets the standalone SPA shell with the token embedded
 * in the mount node's dataset, from which the SPA drives the schema/submit endpoints same-origin.
 */
final class GuestFormController extends Controller
{
    public function mint(Request $request, string $slug, GuestShareTokenService $tokens): JsonResponse|View
    {
        $form = Form::query()->where('public_slug', $slug)->first();

        abort_if($form === null, 404);
        abort_unless($form->allow_guest_submissions, 404);
        abort_if($form->current_published_version_id === null, 404);

        $minted = $tokens->mint($form->tenant_id, $form->id, $form->current_published_version_id);
        $expiresAt = gmdate('c', $minted->expiresAt);

        if ($request->wantsJson()) {
            return response()->json([
                'shareToken' => $minted->token,
                'expiresAt' => $expiresAt,
                'form' => [
                    'id' => $form->id,
                    'title' => $form->title,
                ],
            ]);
        }

        return view('public-runtime', [
            'shareToken' => $minted->token,
            'expiresAt' => $expiresAt,
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
            ],
            'slug' => $slug,
            'locale' => $form->default_locale,
        ]);
    }

    /**
     * The save-and-resume entry point (Increment H9b). Opens the guest SPA shell from an emailed resume link:
     * the `{resumeToken}` carries the tenant + form + pinned version + draft `submissions.id`. The token is
     * verified before anything else (a bad/expired link 404s rather than leaking); the form is resolved under
     * the SUBDOMAIN's RLS context, so a resume link for another tenant's form simply resolves to null → 404.
     * A fresh share token for the pinned version is embedded so the SPA boots and renders the form immediately;
     * the resume token is embedded alongside it for the SPA to restore the saved answers (that restore step is
     * H10). Not a JSON endpoint — a resume link is always a browser navigation.
     */
    public function resume(string $resumeToken, GuestShareTokenService $tokens): View
    {
        try {
            $token = $tokens->verifyResume($resumeToken);
        } catch (InvalidShareTokenException|ExpiredShareTokenException) {
            abort(404);
        }

        $form = Form::query()->whereKey($token->formId)->first();

        abort_if($form === null, 404);
        abort_unless($form->allow_guest_submissions, 404);

        $minted = $tokens->mint($token->tenantId, $token->formId, $token->formVersionId);

        return view('public-runtime', [
            'shareToken' => $minted->token,
            'expiresAt' => gmdate('c', $minted->expiresAt),
            'resumeToken' => $resumeToken,
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
            ],
            'slug' => $form->public_slug,
            'locale' => $form->default_locale,
        ]);
    }
}
