<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\DomainVerificationFailure;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreDomainRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\CustomDomainService;
use App\Services\Tenancy\DomainPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * The custom-domain admin UI (Increment H22b / ADR-0012) — the session-authed Inertia surface over the
 * H22a lifecycle. A thin adapter: `index` renders from {@see DomainPresenter}; every
 * mutation delegates to {@see CustomDomainService}, the same service the
 * `/api/v1` controller calls, and returns a redirect + flash so a refusal on a web route stays a redirect
 * and never JSON.
 *
 * ⚠️ NO ROUTE-MODEL BINDING, for exactly the reason the API controller has none: `domains` is RLS-EXEMPT
 * (it is the table read to decide which tenant a request IS, so scoping it by tenant would be circular),
 * which means implicit binding would resolve ANOTHER tenant's row and hand it to a gate with no row-level
 * database backstop underneath. Every hostname goes through
 * {@see CustomDomainService::findForTenant()}, which carries an explicit `where('tenant_id', …)` against
 * the SUBDOMAIN-resolved current tenant. On this surface that filter is the ONLY isolation there is.
 *
 * ⚠️ THERE IS NO ACTIVATE ACTION, and the page says so in words rather than offering a disabled button.
 * Putting a verified hostname into service is `php artisan domains:activate`, run by whoever installed its
 * TLS certificate (ADR-0012 §D6). {@see primary()} is not a way in: it refuses any domain
 * that is not already live.
 *
 * Gating (routes/tenant.php) mirrors the `/api/v1` twins MINUS `ability:` — that is Sanctum token-scope
 * middleware and a session carries no token, so `can:tenant.settings.manage` IS the authorization here.
 * `feature:custom_domain` gates the WRITES only: ADR-0012 §D9 keeps index and destroy open because a
 * tenant downgraded off Business keeps a live, resolving hostname and must be able to take it down.
 */
final class DomainController extends Controller
{
    public function __construct(private readonly CustomDomainService $domains) {}

    /** The tenant's custom domains, each with the DNS record to publish and its verification state. */
    public function index(Request $request, DomainPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('domains/Index', $presenter->index($user, $this->tenant()));
    }

    /** Claim a hostname and mint its DNS challenge. The row is pending: it routes nowhere. */
    public function store(StoreDomainRequest $request): RedirectResponse
    {
        $domain = $this->domains->claim($this->tenant(), $request->string('domain')->toString());

        return to_route('domains.index')->with('toast', [
            'type' => 'success',
            'message' => "{$domain->domain} claimed. Publish the TXT record below, then check DNS.",
        ]);
    }

    /**
     * Check the DNS challenge now rather than waiting for the sweep.
     *
     * The outcome is reported in the tenant's own terms, because "verified: false" is useless to somebody
     * who has just pasted a record into a registrar UI: the three {@see DomainVerificationFailure} cases
     * call for three different actions (wait, fix a typo, do nothing at all).
     */
    public function verify(string $domain): RedirectResponse
    {
        $row = $this->domains->findForTenant($this->tenant(), $domain);

        abort_if($row === null, 404);

        if ($this->domains->verify($row)) {
            return back()->with('toast', [
                'type' => 'success',
                // Says the counter-intuitive half out loud. Proving control is not the last step, and a
                // tenant told only "verified" will reasonably expect its hostname to start working.
                'message' => "{$row->domain} is verified. We will install its certificate and put it into service.",
            ]);
        }

        return back()->with('toast', [
            'type' => 'error',
            'message' => match ($row->refresh()->verification_failure_reason) {
                DomainVerificationFailure::Mismatch => "A TXT record exists for {$row->domain}, but its value does not match.",
                DomainVerificationFailure::LookupFailed => "We could not reach the nameservers for {$row->domain}. Nothing to fix on your side — try again shortly.",
                default => "No TXT record found for {$row->domain} yet. DNS can take up to an hour to propagate.",
            },
        ]);
    }

    /** Choose which live custom domain the tenant's respondent-facing links are built on. */
    public function primary(string $domain): RedirectResponse
    {
        $row = $this->domains->findForTenant($this->tenant(), $domain);

        abort_if($row === null, 404);

        if (! $this->domains->makePrimary($row)) {
            // A toast, not a 422: the only way to reach this is a stale page whose row went out of service
            // between render and click, and an error screen would lose the rest of the list over it.
            return back()->with('toast', [
                'type' => 'error',
                'message' => "{$row->domain} is not in service yet, so it cannot serve respondent links.",
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => "New links now point at {$row->domain}. Links already sent keep working.",
        ]);
    }

    /** Release a hostname, freeing it for any tenant to claim. Refuses the tenant's own subdomain row. */
    public function destroy(string $domain): RedirectResponse
    {
        $row = $this->domains->findForTenant($this->tenant(), $domain);

        abort_if($row === null, 404);

        $host = $row->domain;

        if (! $this->domains->release($row)) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'That is your workspace address and cannot be removed.',
            ]);
        }

        return to_route('domains.index')->with('toast', [
            'type' => 'success',
            'message' => "{$host} removed. Ask us to retire its certificate when you are done with it.",
        ]);
    }

    /**
     * The subdomain-resolved current tenant. Never a tenant id from the request: `domains` and `tenants`
     * are RLS-exempt, so this controller-side scoping is what prevents a cross-tenant write.
     */
    private function tenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = app(TenantContract::class);

        return $tenant;
    }
}
