<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

/**
 * Re-serves the Vite-built guest PWA service worker (Increment G8a) from the root path `GET /sw.js`.
 *
 * The bundle lands at `public/build/sw.js` (served at `/build/sw.js`, whose natural max-scope is `/build/`
 * and so could never control the guest form pages at `/f/*`). Serving the same file from `/sw.js` gives it
 * a max-scope of `/`, so `main.ts` can register it with the narrower `{ scope: '/f/' }` — controlling only
 * the guest runtime, never the Inertia admin app on the same tenant-subdomain origin. A `/build/*` static
 * file also can't carry the `Service-Worker-Allowed` header under `artisan serve` (statics bypass Laravel);
 * routing it through the controller lets us set the headers a service worker needs.
 *
 * A 404 (rather than a broken empty body) is returned when the app hasn't been built yet (local `vite` dev),
 * matching the registration helper's best-effort "no offline support if it isn't there" contract.
 */
final class ServiceWorkerController extends Controller
{
    public function __invoke(): Response
    {
        $path = public_path('build/sw.js');

        abort_unless(File::exists($path), 404);

        return response(File::get($path), 200, [
            'Content-Type' => 'text/javascript',
            // Defensive: scope '/f/' is already under this script's '/' max-scope, but a script re-served
            // from an unexpected path would still be permitted to claim it.
            'Service-Worker-Allowed' => '/f/',
            // The SW itself is short-lived; Workbox handles asset/schema freshness via its own caches.
            'Cache-Control' => 'no-cache',
        ]);
    }
}
