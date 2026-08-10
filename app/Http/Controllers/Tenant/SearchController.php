<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\SearchRequest;
use App\Models\User;
use App\Services\Search\SearchPresenter;
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
}
