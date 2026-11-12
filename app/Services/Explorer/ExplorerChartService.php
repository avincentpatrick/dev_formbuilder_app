<?php

declare(strict_types=1);

namespace App\Services\Explorer;

use App\Models\ExplorerChart;
use App\Models\User;
use App\Support\Explorer\ExplorerQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saved explorer charts (M236, ADR-0025 §D8).
 *
 * The stored row is a declaration, so the only thing a write has to guarantee is that the declaration is
 * one {@see ExplorerQuery} wrote: callers pass the query object and this stores its `toArray()`, never a
 * request's own array.
 */
final class ExplorerChartService
{
    /** Enough for a working board; a bound, so the board's fan-out of chart queries has one too. */
    public const int MAX_CHARTS_PER_USER = 50;

    /**
     * A member's board: their own charts first, then the ones shared with the workspace, each by name.
     *
     * @return Collection<int, ExplorerChart>
     */
    public function boardFor(User $user): Collection
    {
        return ExplorerChart::query()
            ->visibleTo($user)
            ->orderByRaw('(user_id = ?) desc', [$user->getKey()])
            ->orderBy('name')
            ->get();
    }

    public function create(User $user, string $name, ExplorerQuery $query, bool $shared): ExplorerChart
    {
        if (ExplorerChart::query()->ownedBy($user)->count() >= self::MAX_CHARTS_PER_USER) {
            throw ValidationException::withMessages([
                'name' => ['You have '.self::MAX_CHARTS_PER_USER.' saved charts, which is the most one member can keep. Delete one first.'],
            ]);
        }

        return $this->guardName(fn (): ExplorerChart => ExplorerChart::create([
            'tenant_id' => TenantContext::currentTenantId(),
            'user_id' => $user->getKey(),
            'name' => $name,
            'definition' => $query->toArray(),
            'is_shared' => $shared,
        ]));
    }

    /** A null leaves that part of the chart as it is: renaming a chart must not rewrite what it charts. */
    public function update(ExplorerChart $chart, ?string $name, ?ExplorerQuery $query, ?bool $shared): ExplorerChart
    {
        if ($name !== null) {
            $chart->name = $name;
        }

        if ($query !== null) {
            $chart->definition = $query->toArray();
        }

        if ($shared !== null) {
            $chart->is_shared = $shared;
        }

        return $this->guardName(function () use ($chart): ExplorerChart {
            $chart->save();

            return $chart;
        });
    }

    public function delete(ExplorerChart $chart): void
    {
        $chart->delete();
    }

    /**
     * Turn the unique-name violation into a field error. Wrapped in a transaction for the reason
     * `SavedReportViewService::guardName()` records: PostgreSQL aborts the enclosing transaction on a
     * constraint violation, and a savepoint is what lets the caller's survive to render the message.
     *
     * @param  callable(): ExplorerChart  $write
     */
    private function guardName(callable $write): ExplorerChart
    {
        try {
            return DB::transaction(static fn (): ExplorerChart => $write());
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages([
                    'name' => ['You already have a chart with that name.'],
                ]);
            }

            throw $e;
        }
    }
}
