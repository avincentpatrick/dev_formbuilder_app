<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ExplorerChart;
use App\Models\User;
use App\Services\Explorer\ExplorerPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The data explorer's two pages (M235, ADR-0025): the board of saved charts, and the builder.
 *
 * Neither page carries a chart's DATA in its props. A tile and the builder's preview each ask
 * {@see ExplorerQueryController} for their own result, so the page paints at once, one slow or refused
 * chart cannot hold up or take down the rest, and changing a group-by is a fetch rather than a page visit.
 */
final class ExplorerController extends Controller
{
    public function __construct(private readonly ExplorerPresenter $presenter) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('explore/Index', $this->presenter->index($user));
    }

    public function create(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('explore/Builder', $this->presenter->builder($user, null));
    }

    public function show(Request $request, ExplorerChart $explorerChart): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('explore/Builder', $this->presenter->builder($user, $explorerChart));
    }
}
