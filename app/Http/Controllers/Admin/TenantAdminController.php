<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Admin\SuperAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// The service returns display-ready rows; the controller stays a thin adapter to Inertia.

/**
 * The super-admin platform console (RBAC §9) — served on the central domain, gated by the `superadmin`
 * + `superadmin.mfa` middleware. Stays thin: every cross-tenant operation delegates to
 * {@see SuperAdminService}, so no `is_super_admin` branching or elevated-connection handling lives here.
 * Styled with the design system in Increment C; unstyled placeholders for now.
 */
final class TenantAdminController extends Controller
{
    public function __construct(private readonly SuperAdminService $superAdmin) {}

    public function index(): Response
    {
        return Inertia::render('admin/Tenants', [
            'tenants' => $this->superAdmin->listTenants(),
        ]);
    }

    public function suspend(Request $request, Tenant $tenant): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->superAdmin->suspendTenant($tenant, $actor);

        return back()
            ->with('status', 'tenant-suspended')
            ->with('toast', ['type' => 'success', 'message' => "Suspended {$tenant->name}"]);
    }

    public function reactivate(Request $request, Tenant $tenant): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->superAdmin->reactivateTenant($tenant, $actor);

        return back()
            ->with('status', 'tenant-reactivated')
            ->with('toast', ['type' => 'success', 'message' => "Reactivated {$tenant->name}"]);
    }

    public function users(): Response
    {
        return Inertia::render('admin/Users', [
            'users' => $this->superAdmin->listAllUsers(),
        ]);
    }

    /**
     * The 2FA enrollment landing (reachable WITHOUT confirmed 2FA — it sits outside `superadmin.mfa`).
     * It drives Fortify's own global two-factor endpoints; this passes the current enrolment state so the
     * styled TwoFactorSetup component renders the right step (off / awaiting-confirmation / on).
     */
    public function mfaSetup(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('admin/TwoFactorSetup', [
            'enabled' => $user->two_factor_secret !== null,
            'confirmed' => $user->two_factor_confirmed_at !== null,
        ]);
    }
}
