<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\AuditLogFilterRequest;
use App\Models\User;
use App\Services\Audit\AuditExporter;
use App\Services\Audit\AuditLogPresenter;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audit-log viewer and its export (Increment I2, PRD Feature #12) — a thin adapter, with every prop
 * decision in {@see AuditLogPresenter} and every streaming decision in {@see AuditExporter}.
 *
 * ⚠️ **THE URL IS `/audit-log` AND THE PAGE FOLDER IS `audit`. THE MISMATCH IS DELIBERATE.** The URL
 * matches the permission it is gated on (`audit_log.view`) and the name the PRD and the sidebar use; every
 * existing page folder under `resources/js/Pages/` is a single lowercase word, and this one keeps that.
 * It is the repo's first folder/URL divergence, so it is written down: "fixing" either half to match the
 * other silently breaks every `router.get('/audit-log', …)` string on the page.
 *
 * There is no refusal arm on `export`, unlike {@see AnalyticsController::export()} — no selection here can
 * fail to resolve, so there is nothing to redirect back with.
 */
final class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogPresenter $presenter) {}

    public function index(AuditLogFilterRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('audit/Index', $this->presenter->index($user, $request->filters()));
    }

    public function export(AuditLogFilterRequest $request, AuditExporter $exporter): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $exporter->stream($user, $request->filters(), $request->exportFormat());
    }
}
