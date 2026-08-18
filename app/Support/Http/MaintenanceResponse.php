<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Http\Middleware\EnforcePlatformMaintenance;
use App\Http\Middleware\EnforceTenantMaintenance;
use App\Support\Api\ApiErrorResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one place a 503 is shaped (Increment I5, PRD Feature #10). Both
 * {@see EnforcePlatformMaintenance} and {@see EnforceTenantMaintenance} call it, so the four client shapes
 * are decided once rather than drifting between the platform and tenant halves.
 *
 * ── FOUR ARMS, AND THE INERTIA ONE IS THE SUBTLE ONE ───────────────────────────────────────────────────
 *  1. **An Inertia XHR** gets a 409 with `X-Inertia-Location`, not a 503 body. Inertia's client treats any
 *     non-Inertia response to an XHR visit as a fatal it renders in a modal iframe — so a maintenance page
 *     returned directly would appear as a debug overlay on top of the app the user is being told they
 *     cannot use. `Inertia::location()` is the protocol's own "leave the SPA" instruction: the browser
 *     hard-navigates to the same URL and lands on arm 4, the real page, with the real status code.
 *  2. **`/api/v1/*`** stays inside api-specification.md §2.3's `{error:{code,message,details}}` envelope,
 *     because an integration parsing that shape must not get bare HTML the one time the platform is down.
 *  3. **Anything else expecting JSON** (the builder's fetch sidecars, the guest SPA's own calls) gets a
 *     plain JSON 503 — same status, no envelope it does not speak.
 *  4. **A browser navigation** gets the styled blade at 503.
 *
 * ⚠️ RETURN, NEVER `abort()`. A thrown HttpException would be re-shaped by bootstrap/app.php's `Throwable`
 * fallback (and, on `/api/v1`, by its 5xx arm into `server_error`) — turning a deliberate, explained
 * refusal into what reads like a crash.
 */
final class MaintenanceResponse
{
    /**
     * `$brand` is a CLOSURE, not an array, and that is the point: only the last arm renders a document, so
     * on the three non-HTML arms the tenant's ramp is never resolved at all. Passing the array eagerly
     * would make every paused API request pay for a branding lookup whose result is discarded.
     *
     * @param  ?callable(): ?array<string, array<string, string>>  $brand
     */
    public static function make(Request $request, string $message, ?callable $brand = null): Response
    {
        if ($request->header('X-Inertia') !== null) {
            return Inertia::location($request->fullUrl());
        }

        if ($request->is('api/v1/*')) {
            return ApiErrorResponse::make(503, 'maintenance_mode', $message);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 503);
        }

        return response()->view('maintenance', [
            'message' => $message,
            'brand' => $brand === null ? null : $brand(),
        ], 503);
    }
}
