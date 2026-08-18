<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Settings\PlatformSettings;
use App\Support\Http\MaintenanceResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform-wide maintenance mode (Increment I5, PRD Feature #10: "a separate platform-level maintenance
 * mode blocks the entire product with its own configurable message, for planned platform-wide maintenance
 * windows").
 *
 * ── MOUNTED GLOBALLY, NOT ON THE `web` GROUP ───────────────────────────────────────────────────────────
 * "The entire product" means the tenant app, the guest runtime, `/api/v1` and the connector callbacks —
 * and `routes/api.php` declares its own middleware stacks while `routes/connectors.php` sits outside `web`
 * altogether. A group mount would leave those holes open, and each would look like it worked right up
 * until someone tried the one path that was not covered. It is NOT added to `$middleware->priority()`:
 * that list reorders ROUTE middleware, and global middleware already runs ahead of all of it.
 *
 * ⚠️ EXEMPT BY PATH, NEVER BY ROUTE NAME. Global middleware runs BEFORE routing, so `$request->route()` is
 * null here and `$request->routeIs(...)` answers false for everything — a name-based exemption list would
 * be silently empty, and the first thing it would fail to exempt is the admin console, locking the
 * operator out of the only page that can switch maintenance off. `EnforcePlatformMaintenanceTest` asserts
 * the console and `/up` stay reachable precisely because that failure is invisible until it happens.
 *
 * ── WHAT IS EXEMPT, AND WHAT DELIBERATELY IS NOT ───────────────────────────────────────────────────────
 *  · `admin`, `admin/*` — the console itself. Not optional; see above.
 *  · `login`, `logout`, `two-factor-challenge` — how an operator whose session has expired REACHES the
 *    console. Exempting sign-in during a maintenance window is not a leak: every page they could then
 *    reach is still blocked.
 *  · `up` — the health check. A load balancer must be able to tell "deliberately in maintenance" from
 *    "dead", and this is also the one hot path that must never pay for the settings query.
 * NOT exempt: `register` (nobody signs up during a maintenance window) and `forgot-password` (a wider
 * exempt surface for a path that is not how anyone reaches the console).
 *
 * The settings read is skipped entirely on an exempt path, so the exempt routes cost nothing. A
 * database error here is deliberately NOT caught: failing open on a transient blip would serve a product
 * an operator has taken offline, which is worse than the 500 that already exists for every other query.
 */
final class EnforcePlatformMaintenance
{
    /**
     * @var list<string>
     */
    private const EXEMPT = [
        'up',
        'admin',
        'admin/*',
        'login',
        'logout',
        'two-factor-challenge',
    ];

    public function __construct(private readonly PlatformSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is(...self::EXEMPT)) {
            return $next($request);
        }

        if (! $this->settings->maintenanceEnabled()) {
            return $next($request);
        }

        return MaintenanceResponse::make($request, $this->settings->maintenanceMessage());
    }
}
