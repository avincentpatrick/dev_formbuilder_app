<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\SearchRequest;
use App\Models\User;
use App\Services\Search\SearchPresenter;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Global search's full-results page (Increment J1b, PRD §3.7).
 *
 * Thin by design — every decision lives in {@see SearchPresenter} and the arms behind it, which is both the
 * `AuditLogController` shape and what keeps this inside `scripts/controller-gate.php`'s 250-line /
 * complexity-10 budget.
 */
final class SearchController extends Controller
{
    public function __construct(private readonly SearchPresenter $presenter) {}

    public function index(SearchRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render(
            'search/Index',
            $this->presenter->index($user, $request->terms(), $request->entity())
        );
    }

    /**
     * The ⌘K palette's type-ahead (Increment J1d).
     *
     * ⚠️ PLAIN JSON, NEVER AN INERTIA PARTIAL, AND THE DIFFERENCE IS NOT STYLISTIC. An Inertia partial
     * (`router.get(url, {only: [...]})`) re-dispatches the CURRENT page's controller on every request — so
     * on `/forms/{form}/builder` each debounce tick would re-run a full builder render, and on `/analytics`
     * a full metrics aggregation, to fetch five rows this controller already has. The palette opens from
     * every page in the product, so that cost is paid everywhere.
     *
     * `no-store` because the payload is permission-filtered per user: a shared cache holding one member's
     * results and serving them to another is the disclosure the arms exist to prevent, arriving through the
     * transport layer instead.
     */
    public function suggest(SearchRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()
            ->json($this->presenter->suggest($user, $request->terms()))
            ->header('Cache-Control', 'no-store, private');
    }
}
