<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\BillingInterval;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Admin\SuperAdminService;
use App\Services\Admin\TenantDetailPresenter;
use App\Support\Auth\PasswordConfirmation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
    public function __construct(
        private readonly SuperAdminService $superAdmin,
        private readonly TenantDetailPresenter $detail,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/Tenants', [
            'tenants' => $this->superAdmin->listTenants(),
        ]);
    }

    /**
     * One workspace in depth (I7b): identity + plan + usage + domains. `show` belongs on this controller
     * rather than a fourth one because it is the detail view of the resource {@see index()} lists —
     * splitting `index` and `show` of one resource across two classes would be worse than any line count.
     *
     * Route-model binding is safe here ONLY because `tenants` is central and RLS-exempt. A console route
     * over an RLS-protected table cannot use it — binding resolves on the app connection, which carries no
     * tenant context on the central host, so every valid id would 404. {@see FeedbackConsoleController}
     * states the rule in full; this is the one place in the console that is legitimately exempt from it.
     */
    public function show(Request $request, Tenant $tenant): Response
    {
        // The operator's own id reaches the presenter so the I11b picker can exclude them from their own
        // target list — "never yourself" enforced where the operator can SEE it, not only where the POST
        // refuses it.
        return Inertia::render(
            'admin/TenantDetail',
            $this->detail->show($tenant, (string) $request->user()?->getAuthIdentifier()),
        );
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

    /**
     * Assign (or change) a tenant's plan (H5a / ADR-0008 §D1). Thin adapter — validates the selected plan
     * (from the global catalog) and delegates to SuperAdminService, which adopts the affected tenant's
     * context and emits the `subscription.updated` audit atomically.
     */
    public function assignPlan(Request $request, Tenant $tenant): RedirectResponse
    {
        // ⚠️ `bail` + `uuid` BEFORE `exists`, and both are load-bearing. `plans.id` is a uuid column, so
        // `exists:plans,id` on a non-uuid string emits `where id = 'garbage'` and Postgres raises
        // SQLSTATE 22P02 — a 500, not a validation error. And `uuid` alone does not save it: Laravel only
        // short-circuits an attribute after an IMPLICIT rule fails, so without `bail` the `exists` rule
        // still runs after `uuid` has already failed. Latent since H5a purely because no UI ever posted
        // here; I7b gives the route a form.
        $validated = $request->validate([
            'plan_id' => ['required', 'bail', 'uuid', 'exists:plans,id'],
            'billing_interval' => ['nullable', Rule::enum(BillingInterval::class)],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $plan = Plan::query()->where('id', (string) $validated['plan_id'])->firstOrFail();
        $interval = isset($validated['billing_interval'])
            ? BillingInterval::from((string) $validated['billing_interval'])
            : BillingInterval::Monthly;

        $this->superAdmin->assignPlan($tenant, $plan, $actor, $interval);

        return back()
            ->with('status', 'tenant-plan-assigned')
            ->with('toast', ['type' => 'success', 'message' => "Assigned {$plan->name} to {$tenant->name}"]);
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
     *
     * ⚠️ `needsPasswordConfirmation` MATTERS MORE HERE THAN ANYWHERE ELSE IN THE PRODUCT. Fortify's
     * QR/recovery-code endpoints carry `password.confirm` and answer a JSON sidecar with a 423, so without
     * this prop an operator whose confirmation window has lapsed sees a blank QR — on the one page
     * `superadmin.mfa` allows them to reach. That is a lockout, not a cosmetic defect.
     */
    public function mfaSetup(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('admin/TwoFactorSetup', [
            'enabled' => $user->two_factor_secret !== null,
            'confirmed' => $user->two_factor_confirmed_at !== null,
            'needsPasswordConfirmation' => PasswordConfirmation::isStale($request),
        ]);
    }
}
