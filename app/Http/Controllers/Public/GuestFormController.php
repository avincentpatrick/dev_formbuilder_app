<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Exceptions\Guest\ExpiredShareTokenException;
use App\Exceptions\Guest\InvalidShareTokenException;
use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Services\Branding\GuestBrandingPresenter;
use App\Support\Forms\FormSlug;
use App\Support\Guest\GuestShareTokenService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
 *
 * H23b — BOTH shell-rendering actions pass the tenant's branding into the view, and the JSON arm of
 * {@see self::mint()} deliberately does NOT: it is the SPA's re-mint fetch, which renders no document.
 * Both read it from {@see GuestBrandingPresenter} rather than composing it locally — two call sites
 * deriving one answer independently is how a resume link ends up branded differently from a share link.
 *
 * M61 — THE SLUG IS RESOLVED CASE-INSENSITIVELY AND THE URL IS THEN CANONICALIZED WITH A 301. Five things
 * about that are load-bearing and each is a defect if it is undone:
 *
 * 1. ⛔ **THE REDIRECT SITS AFTER THE THREE 404 GATES, NOT BEFORE THEM.** Hoisted above them — into a
 *    middleware, say, where it would cost no extra throttle hit — a `301` followed by a `404` becomes an
 *    existence oracle, and distinguishing "no such form" from "guest access disabled" is precisely what the
 *    paragraph above says these gates exist to prevent. It also sits before {@see GuestShareTokenService::mint},
 *    because minting an HMAC that is about to be discarded is pointless.
 * 2. **THE URL IS CANONICALIZED, NOT MERELY TOLERATED, BECAUSE IT IS A STORAGE KEY IN FOUR SYSTEMS** — the
 *    service worker's `guest-shell-html` Cache Storage entry (Workbox keys by full request URL), the Dexie
 *    `draft_answers` compound primary key `[form_version_id, local_draft_id]`, the outbox row's `slug`
 *    column, and the manifest's `id`/`start_url`/`scope`. Resolving a mis-cased URL to a 200 *without*
 *    redirecting would fork all four: install from `/f/Clinic-Intake` and the shell caches under that URL
 *    while `start_url` points at `/f/clinic-intake`, so the installed app is a cache miss offline.
 * 3. **THE QUERY STRING IS PRESERVED**, because H7 URL prefill rides it. A query-dropping redirect would
 *    silently deliver an un-prefilled form — worse than the 404 it replaces, because it produces wrong data
 *    rather than a visible error. Built from `route()` + `getQueryString()` and never by rewriting
 *    `fullUrl()`, which would also rewrite a slug-shaped substring elsewhere in the URL.
 * 4. **BOTH ARMS REDIRECT — no branching on `Accept`.** A legacy outbox row carrying a mis-cased slug
 *    reaches the server only through the JSON arm, and `fetch()` follows a 301 on a GET with its headers
 *    intact. Two arms deriving one answer independently is the defect the H23b paragraph above records.
 * 5. **`301` RATHER THAN `302`.** Storage is lowercase — `FormService::setShareSettings()` lowers what it
 *    is handed and the share request's regex refuses uppercase before that — so the target is
 *    `lower(path)`, a pure function of the URL, independent of database state, and a cached redirect can
 *    therefore never become wrong. A `302` would re-cost a `throttle:guest-mint` hit on every mis-cased
 *    entry instead of once per device. ⚠️ The redirect and the request that follows it do share that
 *    per-IP bucket, which is the shape the `guest-challenge` limiter exists to avoid — two of
 *    thirty, once per device, and hoisting it out to save them is the oracle in (1).
 */
final class GuestFormController extends Controller
{
    public function mint(Request $request, string $slug, GuestShareTokenService $tokens, GuestBrandingPresenter $branding): JsonResponse|RedirectResponse|View
    {
        $form = Form::query()->where('public_slug', FormSlug::forLookup($slug))->first();

        abort_if($form === null, 404);
        abort_unless($form->allow_guest_submissions, 404);
        abort_if($form->current_published_version_id === null, 404);

        if ($slug !== $form->public_slug) {
            $query = $request->getQueryString();

            return redirect()->to(
                route('guest.form.mint', ['slug' => $form->public_slug]).($query === null ? '' : '?'.$query),
                301,
            );
        }

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
            // The CANONICAL value, not the request path — matching self::resume() below. Everything the shell
            // derives from this is a storage key; see (2) in the class docblock. After the redirect above the
            // two are always equal, so this is defence-in-depth rather than the fix.
            'slug' => $form->public_slug,
            'locale' => $form->default_locale,
            'brand' => $branding->forGuest(),
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
    public function resume(string $resumeToken, GuestShareTokenService $tokens, GuestBrandingPresenter $branding): View
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
            'brand' => $branding->forGuest(),
        ]);
    }
}
