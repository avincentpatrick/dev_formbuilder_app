<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Admin\ImpersonationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Start an impersonation from the super-admin console — Increment I11b, rbac §9 resolved decision 1.
 *
 * Mounted inside the console's `superadmin` + `superadmin.mfa` + `step-up` stack, which is what makes this
 * a thin controller rather than a gate: by the time a request arrives, the caller is a 2FA-enrolled
 * super-admin who confirmed their password in the last 15 minutes. PRD Feature #14 names the console a
 * high-blast-radius surface, and nothing in it is higher-blast-radius than this route.
 *
 * ⚠️ THE RESPONSE IS `Inertia::location()` AND MUST STAY THAT WAY. The destination is another ORIGIN (the
 * tenant subdomain), and an Inertia XHR cannot follow a plain 302 to one — the browser fetches it, gets
 * HTML back where JSON was expected, and the page silently does nothing. `Inertia::location()` sends a 409
 * with `X-Inertia-Location`, which is the protocol's own "leave the SPA" instruction and the only thing
 * that works here. `MaintenanceResponse` is the existing precedent in this tree, and I10e spent four CI
 * cycles learning the lesson from the other side.
 */
final class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    /**
     * @throws ValidationException when the target is ineligible
     */
    public function store(Request $request, Tenant $tenant): Response
    {
        // ⚠️ A RAW UUID FROM THE BODY, NEVER A BOUND MODEL. Route-model binding resolves on the app
        // connection, and the console runs with NO tenant context — so `usersVisibilitySql()` would match
        // nothing and every VALID id would 404. `{feedback}` at routes/admin.php:58 is the same decision,
        // and the eligibility check below is what a binding would otherwise have been doing badly.
        $validated = $request->validate([
            'user_id' => ['required', 'uuid'],
        ]);

        $operatorId = (string) $request->user()?->getAuthIdentifier();

        try {
            $url = $this->impersonation->mint(
                $tenant,
                $operatorId,
                (string) $validated['user_id'],
                $request->ip(),
            );
        } catch (RuntimeException $e) {
            // Surfaced as a validation error on the field that caused it, so the console renders it beside
            // the picker rather than as a toast with no anchor. Every ineligibility collapses to one
            // message deliberately — "not a member", "is platform staff" and "is you" are the same refusal
            // to an operator, and distinguishing them discloses membership facts the console page has
            // otherwise been careful not to state.
            throw ValidationException::withMessages(['user_id' => $e->getMessage()]);
        }

        return Inertia::location($url);
    }
}
