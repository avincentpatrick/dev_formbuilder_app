<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Auth\SignInPass;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issues the shared sign-in pass on the workspace's signed-in pages — M253 (`D236`, {@see SignInPass}).
 *
 * Mounted LAST in the authenticated workspace group, so it runs only for a request that has already passed `auth`,
 * `verified` and the workspace's two-factor gate: a pass is never issued to a session that could not open the page
 * itself. It adds a cookie to the response and nothing else, and adds none when the setting is off or the pass the
 * browser holds still names this user, workspace and session.
 */
final class IssueSignInPass
{
    public function __construct(private readonly SignInPass $pass) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Read before the page runs: the workspace this request was admitted to, not whatever a job left behind.
        $tenantId = TenantContext::currentTenantId();

        $response = $next($request);

        $user = $request->user();

        if ($user === null || $tenantId === null) {
            return $response;
        }

        $cookie = $this->pass->issueFor($request, (string) $user->getAuthIdentifier(), $tenantId);

        if ($cookie !== null) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
