<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Exceptions\Explorer\InvalidExplorerQueryException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ExplorerQueryRequest;
use App\Models\ExplorerChart;
use App\Models\User;
use App\Services\Explorer\ExplorerPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The data explorer's three JSON reads (M235, ADR-0025 §D3).
 *
 * All three answer 200 with `{result, refusal}` or `{fields, notes}`, and a declaration that cannot be run
 * is a `refusal` in that body rather than an error status: the page prints the sentence where the chart
 * would have been, and the fetch client's error path stays for what is actually an error — a lost session,
 * a network failure.
 *
 * `query` is a POST although it changes nothing, because a declaration is a nested document and a query
 * string is the wrong place for one. It is throttled on its route for the same reason it is bounded in
 * {@see ExplorerPresenter}: it is the one endpoint here a reader can aim.
 */
final class ExplorerQueryController extends Controller
{
    public function __construct(private readonly ExplorerPresenter $presenter) {}

    public function query(ExplorerQueryRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            return response()->json($this->presenter->run($user, $request->toQuery()));
        } catch (InvalidExplorerQueryException $e) {
            return response()->json(['result' => null, 'refusal' => ['reason' => $e->reason(), 'message' => $e->getMessage()]]);
        }
    }

    public function result(Request $request, ExplorerChart $explorerChart): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->presenter->result($user, $explorerChart));
    }

    public function fields(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $formId = $request->query('form_id');

        try {
            return response()->json($this->presenter->fields(
                $user,
                (string) $request->query('source', ''),
                is_string($formId) && $formId !== '' ? $formId : null,
            ));
        } catch (InvalidExplorerQueryException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason()], 422);
        }
    }
}
