<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDomainRequest;
use App\Http\Resources\Api\V1\DomainResource;
use App\Models\Tenant;
use App\Services\Tenancy\CustomDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * The tenant's custom-domain surface (H22a / ADR-0012) — claim, list, verify on demand, release.
 *
 * ⚠️ NO ROUTE-MODEL BINDING, AND THAT IS DELIBERATE. `domains` is RLS-EXEMPT (it is the table read to
 * decide which tenant a request IS, so scoping it by tenant would be circular), which means implicit
 * binding would happily resolve ANOTHER tenant's row and hand it to a policy that has no row-level
 * database backstop underneath it. The hostname is resolved through
 * {@see CustomDomainService::findForTenant()}, which carries an explicit `where('tenant_id', …)` against
 * the SUBDOMAIN-RESOLVED current tenant — never a tenant id from the request. That is exactly the
 * TenantSettingsController precedent, and here it is the ONLY isolation these queries have.
 *
 * ⚠️ THERE IS NO ACTIVATE ENDPOINT, and its absence is the feature. Putting a verified domain into
 * service is `php artisan domains:activate`, run by whoever installed the TLS certificate. Per-domain
 * TLS issuance is structurally Track B and Track B is deferred, so a tenant able to activate its own
 * domain could put its respondents on an origin with no certificate — and the resume link they receive
 * would carry that origin. H22b's {@see primary()} is NOT a weakening of that: it refuses
 * any domain that is not already live, so it chooses among hosts an operator activated and cannot
 * produce one.
 *
 * Gating (routes/api.php): `ability:manage:domains` scopes the TOKEN, `can:tenant.settings.manage`
 * re-checks the acting user's real permission, and `feature:custom_domain` gates the WRITES only.
 * Reads and deletes stay ungated on purpose — the ApiTokenController precedent — because a tenant
 * downgraded off Business keeps a LIVE, resolving hostname and must still be able to see and remove it.
 */
final class DomainApiController extends Controller
{
    public function __construct(private readonly CustomDomainService $domains) {}

    /**
     * List the current tenant's custom domains.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => DomainResource::collection($this->domains->forTenant($this->tenant()))->resolve(),
        ]);
    }

    /**
     * Claim a custom domain and mint its DNS verification challenge.
     */
    public function store(StoreDomainRequest $request): JsonResponse
    {
        $domain = $this->domains->claim($this->tenant(), $request->string('domain')->toString());

        return response()->json(['data' => (new DomainResource($domain))->resolve($request)], 201);
    }

    /**
     * Check the DNS challenge for a claimed domain immediately, without waiting for the sweep.
     */
    public function verify(Request $request, string $domain): JsonResponse
    {
        $row = $this->domains->findForTenant($this->tenant(), $domain);

        abort_if($row === null, 404);

        $this->domains->verify($row);

        return response()->json(['data' => (new DomainResource($row->refresh()))->resolve($request)]);
    }

    /**
     * Choose which live custom domain the tenant's respondent-facing links are built on.
     *
     * NOT an activation path, and the distinction is the whole reason this route can exist at all: it
     * refuses any domain that is not already live, so it chooses AMONG hosts an operator put into service
     * and can never put one there. `domains_primary_requires_live_chk` states the same rule in DDL.
     */
    public function primary(Request $request, string $domain): JsonResponse
    {
        $row = $this->domains->findForTenant($this->tenant(), $domain);

        abort_if($row === null, 404);

        // 422 rather than 409: the request named a real row of this tenant's, but one whose state makes the
        // instruction unsatisfiable — the same shape as destroy()'s refusal of a subdomain row.
        abort_unless($this->domains->makePrimary($row), 422);

        return response()->json(['data' => (new DomainResource($row->refresh()))->resolve($request)]);
    }

    /**
     * Release a custom domain, freeing the hostname for any tenant to claim.
     */
    public function destroy(string $domain): JsonResponse
    {
        $row = $this->domains->findForTenant($this->tenant(), $domain);

        abort_if($row === null, 404);

        // Refuses a subdomain row — that is the tenant's app host, and deleting it would take the whole
        // tenant offline with no way back through this surface.
        abort_unless($this->domains->release($row), 422);

        return response()->json(null, 204);
    }

    /**
     * The subdomain-resolved current tenant. Never a tenant id from the request body: `domains` and
     * `tenants` are RLS-exempt, so this controller-side scoping is what prevents a cross-tenant write.
     */
    private function tenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = app(TenantContract::class);

        return $tenant;
    }
}
