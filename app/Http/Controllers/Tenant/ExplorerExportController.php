<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ExplorerExportRequest;
use App\Models\ExplorerChart;
use App\Models\User;
use App\Services\Explorer\Agreement\AgreementExporter;
use App\Services\Explorer\ExplorerPresenter;
use App\Support\Explorer\ExplorerQuery;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A saved agreement list as CSV or Excel (M250, `D234`; ADR-0025 §D10).
 *
 * A GET on the saved list, like every export here: it binds the chart, so the bound-instance policy (`can:export`)
 * and §D8's run-as-the-reader rule both apply, and there is no second endpoint a reader can aim a declaration at.
 *
 * The one guard it keeps is the analytics export's: a list that cannot be counted any more is refused BEFORE the
 * stream starts, with a toast, because a file that begins and then breaks mid-download is worse than no file.
 */
final class ExplorerExportController extends Controller
{
    public function __construct(private readonly ExplorerPresenter $presenter) {}

    public function __invoke(ExplorerExportRequest $request, ExplorerChart $explorerChart, AgreementExporter $exporter): StreamedResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $refusal = $this->presenter->exportRefusal($user, $explorerChart);

        if ($refusal !== null) {
            return back()->with('toast', ['type' => 'error', 'message' => $refusal]);
        }

        return $exporter->stream($explorerChart, ExplorerQuery::fromArray($explorerChart->definition), $user, $request->exportFormat());
    }
}
