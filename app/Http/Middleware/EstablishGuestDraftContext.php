<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Guest\GuestShareTokenService;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the Postgres RLS tenant context for a guest RESUME request (Increment H9b) — the sibling of
 * {@see EstablishGuestTenantContext}, for the durable resume link rather than the share link. The tenant is
 * resolved from the signed `{resumeToken}` route segment, which additionally carries the draft
 * `submissions.id` the request is allowed to touch.
 *
 * The token is verified (signature + expiry + the resume `typ`/`sid` claims) BEFORE any context is set: a
 * forged, tampered, expired, or non-resume token throws (→ 401, mapped in bootstrap/app.php) and never
 * engages RLS. On success it applies the tenant with a NULL user (guests do no RBAC), so every downstream
 * read/write is RLS-scoped to that tenant — a token minted for tenant A is powerless against tenant B even
 * if app logic were bypassed. The decoded token (with its `submissionId`) is stashed on the request so the
 * controller can load exactly that draft.
 *
 * Registered by class-string in routes/api.php (like {@see EstablishGuestTenantContext}), not as an alias.
 * The only route parameter, `{resumeToken}`, is a plain string (not a route-model binding), so this needs no
 * middleware-priority entry — nothing downstream touches the database before the controller.
 *
 * The session-scoped apply() is paired with a forget() in terminate(), so the tenant context can never
 * survive onto a pooled/persistent connection reused by the next request (ADR-0002 §D2).
 */
final class EstablishGuestDraftContext
{
    public function __construct(private readonly GuestShareTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokens->verifyResume((string) $request->route('resumeToken'));

        // userId stays null: a guest has no account.
        TenantContext::apply(tenantId: $token->tenantId);

        $request->attributes->set('guestResumeToken', $token);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        TenantContext::forget();
    }
}
