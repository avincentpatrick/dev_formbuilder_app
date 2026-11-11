<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\SaveExplorerChartRequest;
use App\Models\ExplorerChart;
use App\Models\User;
use App\Services\Explorer\ExplorerChartService;
use App\Support\Explorer\ExplorerQuery;
use Illuminate\Http\RedirectResponse;

/**
 * Saved charts, written from the explorer's builder (M235, ADR-0025 §D8).
 *
 * Inertia visits, like every other saved-thing write in the application. A new chart lands on its own
 * page, so the address bar holds something that can be bookmarked and sent to a colleague.
 */
final class ExplorerChartController extends Controller
{
    public function __construct(private readonly ExplorerChartService $charts) {}

    public function store(SaveExplorerChartRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var ExplorerQuery $query — required on a POST, so the request's own check has built it */
        $query = $request->queryOrNull();

        $chart = $this->charts->create($user, (string) $request->nameOrNull(), $query, (bool) $request->sharedOrNull());

        return to_route('explore.charts.show', $chart)
            ->with('toast', ['type' => 'success', 'message' => 'Chart saved.']);
    }

    public function update(SaveExplorerChartRequest $request, ExplorerChart $explorerChart): RedirectResponse
    {
        $this->charts->update($explorerChart, $request->nameOrNull(), $request->queryOrNull(), $request->sharedOrNull());

        return back()->with('toast', ['type' => 'success', 'message' => 'Chart updated.']);
    }

    public function destroy(ExplorerChart $explorerChart): RedirectResponse
    {
        $this->charts->delete($explorerChart);

        return to_route('explore.index')->with('toast', ['type' => 'success', 'message' => 'Chart deleted.']);
    }
}
