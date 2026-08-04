<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\AnalyticsFilterRequest;
use App\Models\User;
use App\Services\Analytics\AnalyticsExporter;
use App\Services\Analytics\AnalyticsPresenter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The cross-form analytics page (ADR-0011, Increment H24b2) — the session-authed Inertia surface over the
 * H24a substrate and the H24b1 chart primitives.
 *
 * A thin adapter, and deliberately thinner than it looks: all parsing is in
 * {@see AnalyticsFilterRequest}, all mapping is in {@see AnalyticsPresenter}, and every refusal branch is in
 * `bootstrap/app.php`'s render arms. `scripts/controller-gate.php` counts each `match` arm, `&&`, `||` and
 * `??` toward a method's complexity and scans only `app/Http/Controllers`, so that division is mechanical as
 * well as tidy.
 *
 * Authorization is the route's `can:viewAny,SavedReportView` policy gate plus `feature:advanced_analytics`.
 * There is **no `ability:`** — that is Sanctum token-scope middleware and a session request carries no
 * token; the `can:` gate IS the authorization on this surface (the H14/H15b convention in routes/tenant.php).
 *
 * Filters travel in the query string and come back as Inertia partial reloads, never as `/api/v1` calls:
 * a tenant page takes Inertia props, there is no session-authed API client, and a domain exception on a web
 * route returns a 302 with a flash — which an XHR would follow, see `ok`, and then choke parsing as JSON.
 */
final class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsPresenter $presenter) {}

    /** The page: the applied declaration, the report, the question catalogue and the user's saved views. */
    public function index(AnalyticsFilterRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('analytics/Index', $this->presenter->index(
            $user,
            $request->toQuery(),
            $request->questionKey(),
        ));
    }

    /**
     * The streamed CSV/XLSX export (§D13) — the same {@see AnalyticsExporter} the `/api/v1` twin calls.
     *
     * The one guard either analytics controller keeps. It has to be here rather than in the presenter: the
     * exporter has no page to render a refusal into, and streaming a file whose scope-node selection cannot
     * be resolved would 404 mid-download.
     */
    public function export(AnalyticsFilterRequest $request, AnalyticsExporter $exporter): StreamedResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = $request->toQuery();
        $refusal = $this->presenter->selectionRefusal($query);

        if ($refusal !== null) {
            return back()->with('toast', ['type' => 'error', 'message' => $refusal['message']]);
        }

        return $exporter->stream($query, $user, $request->exportFormat());
    }
}
