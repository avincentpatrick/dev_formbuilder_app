<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlatformAuditFilterRequest;
use App\Services\Audit\PlatformAuditPresenter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The PLATFORM audit ledger (Increment I7b, PRD Feature #12 / RBAC §9) — `audits` rows with
 * `tenant_id IS NULL`, which since I5 have been written and never readable.
 *
 * ── ITS OWN CONTROLLER, NOT A METHOD ON TenantAdminController ───────────────────────────────────────────
 * {@see PlatformSettingsController} already drew this seam and wrote the reasoning down: that class is the
 * tenant LIFECYCLE console (list / suspend / reactivate / assign plan) and this is a third job again — the
 * platform LEDGER. {@see FeedbackConsoleController} followed the same rule; three console surfaces, three
 * controllers. The practical argument on top of the tidy one: I7b's other half adds `show()` and a
 * presenter dependency to `TenantAdminController` in this same PR, and `scripts/controller-gate.php` caps a
 * controller at 250 lines / complexity 10.
 *
 * ── NO export METHOD AND NO /{audit} DETAIL ROUTE, BOTH DELIBERATE ──────────────────────────────────────
 * The diff is a modal on the page (the tenant viewer's `AuditChangeModal`), so a detail route would be a
 * second way to render something already rendered — and `routes/tenant.php` records the same call for
 * `/audit-log`. Export is deferred rather than forgotten: `AuditExporter` is built on `TenantContext`, a
 * tenant-scoped `UsageMeter::increment(ExportsCount)` and a self-referential audit row that early-returns
 * when there is no tenant, so a platform export is three rethinks, not a route. `PlatformAuditConsoleTest`
 * asserts the absence so a later export is a decision rather than drift.
 */
final class PlatformAuditController extends Controller
{
    public function __construct(private readonly PlatformAuditPresenter $presenter) {}

    public function index(PlatformAuditFilterRequest $request): Response
    {
        return Inertia::render('admin/AuditLog', $this->presenter->index($request->filters(), $request->page()));
    }
}
