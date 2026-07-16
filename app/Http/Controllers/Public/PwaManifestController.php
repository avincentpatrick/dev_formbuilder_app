<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Form;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Serves a per-form web app manifest (Increment G8a) at `GET /f/{slug}/manifest.webmanifest`, linked from
 * the guest runtime shell so the form is installable ("Add to Home Screen").
 *
 * The manifest is per-form on purpose: each share link is a distinct URL, so `id`/`start_url`/`scope` all
 * pin to `/f/{slug}` and `name` is the form title — an installed icon is named after the form and opens
 * that form. The `scope` `/f/{slug}` sits under the service worker's `/f/` scope, so installed-app
 * navigations are SW-controlled and render offline.
 *
 * Resolution + the three 404 gates mirror {@see GuestFormController::mint} exactly (resolve by per-tenant
 * `public_slug`, RLS-scoped to the subdomain tenant; 404 — never 403 — for missing / guest-disabled /
 * unpublished so slug-probing can't distinguish them). No share token is needed: like the mint route this
 * resolves the form from the subdomain, unlike the token-consuming /api/v1/public endpoints.
 */
final class PwaManifestController extends Controller
{
    private const THEME_COLOR = '#1B5E5E';      // --mds-accent-teal-600

    private const BACKGROUND_COLOR = '#F3F4F1'; // --mds-neutral-50

    public function __invoke(string $slug): JsonResponse
    {
        $form = Form::query()->where('public_slug', $slug)->first();

        abort_if($form === null, 404);
        abort_unless($form->allow_guest_submissions, 404);
        abort_if($form->current_published_version_id === null, 404);

        $scope = '/f/'.$slug;

        return response()->json([
            'id' => $scope,
            'name' => $form->title,
            'short_name' => Str::limit($form->title, 12, ''),
            'start_url' => $scope,
            'scope' => $scope,
            'display' => 'standalone',
            'orientation' => 'portrait',
            'theme_color' => self::THEME_COLOR,
            'background_color' => self::BACKGROUND_COLOR,
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json',
        ]);
    }
}
