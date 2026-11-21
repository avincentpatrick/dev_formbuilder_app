<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Guest\GuestShareTokenService;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the Postgres RLS tenant context for a respondent's AMEND request (M248, `D232`) — the sibling of
 * {@see EstablishGuestDraftContext}, for the link to a response that was SENT rather than a draft. The tenant is
 * resolved from the signed `{amendToken}` route segment, which also carries the `submissions.id` the request may touch.
 *
 * The token is verified (signature, expiry, the amend `typ`/`sid` claims) BEFORE any context is set: a forged, tampered,
 * expired or other-kind token throws (→ 401, mapped in bootstrap/app.php) and never engages RLS. The tenant is applied
 * with a NULL user — a respondent has no account — and the decoded token is stashed for the controller. The apply() is
 * paired with a forget() in terminate(), so the context never survives onto a pooled connection (ADR-0002 §D2).
 */
final class EstablishGuestAmendContext
{
    public function __construct(private readonly GuestShareTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokens->verifyAmend((string) $request->route('amendToken'));

        TenantContext::apply(tenantId: $token->tenantId);

        $request->attributes->set('guestAmendToken', $token);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        TenantContext::forget();
    }
}
